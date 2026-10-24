<?php

declare(strict_types=1);

namespace Amyx\Economy;

use Amyx\Economy\api\EconomyAPI;
use Amyx\Economy\storage\EconomyStorage;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

final class Main extends PluginBase implements Listener{
    private EconomyStorage $storage;
    private EconomyAPI $api;

    protected function onEnable() : void{
        $this->saveDefaultConfig();
        @mkdir($this->getDataFolder(), 0777, true);
        $this->storage = new EconomyStorage(
            $this->getDataFolder() . 'economy.sqlite',
            (int) $this->getConfig()->get('starting-balance', 0),
            (int) $this->getConfig()->get('maximum-balance', 9_000_000_000_000)
        );
        $this->api = EconomyAPI::initialize($this, $this->storage);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        foreach($this->getServer()->getOnlinePlayers() as $player){
            $this->api->ensureAccount($player->getName());
        }
        $this->getLogger()->info('AmyxEconomy включена. Валюта: ' . $this->api->getCurrencyName());
    }

    protected function onDisable() : void{
        if(isset($this->storage)){
            $this->storage->close();
        }
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $this->api->ensureAccount($event->getPlayer()->getName());
    }

    public function getAPI() : EconomyAPI{
        return $this->api;
    }

    private function prefix() : string{
        return (string) $this->getConfig()->getNested('messages.prefix', '§l§dAmyX§fEconomy §8» §r');
    }

    private function msg(CommandSender $sender, string $message) : void{
        $sender->sendMessage($this->prefix() . $message);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        return match($command->getName()){
            'balance' => $this->handleBalance($sender, $args),
            'pay' => $this->handlePay($sender, $args),
            'baltop' => $this->handleTop($sender, $args),
            'money' => $this->handleMoney($sender, $args),
            'economy' => $this->handleAdmin($sender, $args),
            default => false
        };
    }

    private function handleBalance(CommandSender $sender, array $args) : bool{
        $target = $args[0] ?? ($sender instanceof Player ? $sender->getName() : null);
        if($target === null){
            $this->msg($sender, '§cУкажите игрока: §f/balance <игрок>');
            return true;
        }
        if(isset($args[0]) && !$sender->hasPermission('amyx.economy.balance.others')){
            $this->msg($sender, '§cУ вас нет права смотреть чужой баланс.');
            return true;
        }
        if(!$this->api->accountExists($target)){
            $this->msg($sender, '§cСчёт игрока §f' . $target . ' §cне найден.');
            return true;
        }
        $canonical = $this->storage->getCanonicalName($target) ?? $target;
        $this->msg($sender, '§fБаланс §d' . $canonical . '§f: §a' . $this->api->format($this->api->getBalance($target)));
        return true;
    }

    private function handlePay(CommandSender $sender, array $args) : bool{
        if(!$sender instanceof Player){
            $this->msg($sender, '§cПеревод может выполнить только игрок.');
            return true;
        }
        if(count($args) < 2){
            $this->msg($sender, '§cИспользование: §f/pay <игрок> <сумма>');
            return true;
        }
        $recipient = (string) $args[0];
        $amount = $this->api->parseAmount((string) $args[1]);
        $min = (int) $this->getConfig()->get('minimum-payment', 1);
        $max = (int) $this->getConfig()->get('maximum-payment', 1_000_000_000_000);
        if($amount === null || $amount < $min || $amount > $max){
            $this->msg($sender, '§cНекорректная сумма. Разрешено от §f' . $min . ' §cдо §f' . $max . '§c.');
            return true;
        }
        if(strtolower($recipient) === strtolower($sender->getName())){
            $this->msg($sender, '§cНельзя переводить монеты самому себе.');
            return true;
        }
        if(!$this->api->accountExists($recipient)){
            $this->msg($sender, '§cИгрок §f' . $recipient . ' §cещё не создавал счёт на сервере.');
            return true;
        }
        if($this->api->getBalance($sender->getName()) < $amount){
            $this->msg($sender, '§cНедостаточно монет. Ваш баланс: §f' . $this->api->format($this->api->getBalance($sender->getName())));
            return true;
        }
        if(!$this->api->transfer($sender->getName(), $recipient, $amount)){
            $this->msg($sender, '§cПеревод не выполнен. Проверьте сумму и баланс получателя.');
            return true;
        }
        $canonical = $this->storage->getCanonicalName($recipient) ?? $recipient;
        $this->msg($sender, '§aВы перевели §f' . $this->api->format($amount) . ' §aигроку §f' . $canonical . '§a.');
        $online = $this->getServer()->getPlayerExact($canonical);
        if($online instanceof Player){
            $this->msg($online, '§aВы получили §f' . $this->api->format($amount) . ' §aот игрока §f' . $sender->getName() . '§a.');
        }
        return true;
    }

    private function handleTop(CommandSender $sender, array $args) : bool{
        $page = max(1, isset($args[0]) ? (int) $args[0] : 1);
        $perPage = max(5, min(20, (int) $this->getConfig()->get('top-per-page', 10)));
        $rows = $this->api->getTop($perPage, ($page - 1) * $perPage);
        if($rows === []){
            $this->msg($sender, '§cНа этой странице нет игроков.');
            return true;
        }
        $sender->sendMessage('§8§m----------------§r §l§dТоп балансов §8§m----------------');
        foreach($rows as $index => $row){
            $place = ($page - 1) * $perPage + $index + 1;
            $sender->sendMessage('§d#' . $place . ' §f' . $row['name'] . ' §8— §a' . $this->api->format($row['balance']));
        }
        $sender->sendMessage('§8Страница: §f' . $page . ' §8| §7/baltop <страница>');
        return true;
    }

    private function handleMoney(CommandSender $sender, array $args) : bool{
        if($args === []){
            return $this->handleBalance($sender, []);
        }
        $sub = strtolower((string) array_shift($args));
        return match($sub){
            'bal', 'balance', 'баланс' => $this->handleBalance($sender, $args),
            'pay', 'перевод' => $this->handlePay($sender, $args),
            'top', 'топ' => $this->handleTop($sender, $args),
            'placeholders', 'placeholder', 'плейсхолдеры' => $this->showPlaceholders($sender),
            default => $this->showMoneyHelp($sender)
        };
    }

    private function showMoneyHelp(CommandSender $sender) : bool{
        $sender->sendMessage('§l§dAmyXEconomy §r§7— команды');
        $sender->sendMessage('§f/balance [игрок] §8— баланс');
        $sender->sendMessage('§f/pay <игрок> <сумма> §8— перевод');
        $sender->sendMessage('§f/baltop [страница] §8— топ');
        $sender->sendMessage('§f/money placeholders §8— список плейсхолдеров');
        return true;
    }

    private function showPlaceholders(CommandSender $sender) : bool{
        $sender->sendMessage('§l§dПлейсхолдеры AmyxEconomy');
        foreach([
            '%amyx_balance%', '%amyx_balance_raw%', '%amyx_balance_formatted%', '%amyx_balance_short%',
            '%amyx_rank%', '%amyx_currency%', '%amyx_currency_symbol%',
            '%amyx_balance_ИГРОК%', '%amyx_rank_ИГРОК%',
            '%amyx_top_1_name%', '%amyx_top_1_balance%', '%amyx_top_1_balance_raw%', '%amyx_top_1_balance_short%',
            '{money}', '{balance}', '{amyx_balance}', '{amyx_rank}'
        ] as $placeholder){
            $sender->sendMessage('§f' . $placeholder);
        }
        return true;
    }

    private function handleAdmin(CommandSender $sender, array $args) : bool{
        if(!$sender->hasPermission('amyx.economy.command.admin')){
            $this->msg($sender, '§cУ вас нет прав.');
            return true;
        }
        if(count($args) < 2){
            $this->msg($sender, '§c/economy <set|add|take|reset|info> <игрок> [сумма]');
            return true;
        }
        $action = strtolower((string) $args[0]);
        $target = (string) $args[1];
        if($action === 'info'){
            return $this->handleBalance($sender, [$target]);
        }
        if($action === 'reset'){
            $amount = (int) $this->getConfig()->get('starting-balance', 0);
        }else{
            if(!isset($args[2]) || ($amount = $this->api->parseAmount((string) $args[2])) === null){
                $this->msg($sender, '§cУкажите корректную сумму.');
                return true;
            }
        }
        $actor = $sender->getName();
        $ok = match($action){
            'set' => $this->api->setBalance($target, $amount, 'admin_set', $actor),
            'add' => $this->api->deposit($target, $amount, 'admin_add', $actor),
            'take', 'remove' => $this->api->withdraw($target, $amount, 'admin_take', $actor),
            'reset' => $this->api->setBalance($target, $amount, 'admin_reset', $actor),
            default => false
        };
        if(!$ok){
            $this->msg($sender, '§cОперация не выполнена. Возможно, недостаточно средств или превышен лимит.');
            return true;
        }
        $this->msg($sender, '§aБаланс игрока §f' . $target . ' §aобновлён: §f' . $this->api->format($this->api->getBalance($target)));
        $online = $this->getServer()->getPlayerExact($target);
        if($online instanceof Player){
            $this->msg($online, '§eВаш баланс изменён администрацией: §f' . $this->api->format($this->api->getBalance($target)));
        }
        return true;
    }
}
