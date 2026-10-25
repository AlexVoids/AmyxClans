<?php
declare(strict_types=1);
namespace Amyx\CommandAccess;

use pocketmine\command\{Command, CommandSender};
use pocketmine\command\utils\{CommandStringHelper, InvalidCommandSyntaxException};
use pocketmine\event\Listener;
use pocketmine\event\player\{PlayerJoinEvent, PlayerQuitEvent};
use pocketmine\event\server\{CommandEvent, DataPacketSendEvent};
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\permission\PermissionAttachment;
use pocketmine\player\Player;
use pocketmine\plugin\{Plugin, PluginBase};
use pocketmine\scheduler\ClosureTask;

final class Main extends PluginBase implements Listener{
    private Policy $policy;
    /** @var \WeakMap<Player,array{attachment:?PermissionAttachment,permissions:array<string,bool>,state:string}> */
    private \WeakMap $players;
    private bool $resending = false;
    private int $worldEditLimit = 15000;
    private bool $worldEditReady = false;

    protected function onEnable() : void{
        $this->saveDefaultConfig();
        if(!(bool)$this->getConfig()->get('clans-v2-migrated',false)){
            $allowed=(array)$this->getConfig()->get('allowed-commands',[]);
            if(!in_array('clan',$allowed,true))$allowed[]='clan';
            $grants=(array)$this->getConfig()->get('grant-permissions',[]);
            if(!in_array('amyxclans.command',$grants,true))$grants[]='amyxclans.command';
            $this->getConfig()->set('allowed-commands',$allowed);$this->getConfig()->set('grant-permissions',$grants);
            $this->getConfig()->set('clans-v2-migrated',true);$this->getConfig()->save();
        }
        if(!(bool)$this->getConfig()->get('clanhome-v201-migrated',false)){
            $allowed=(array)$this->getConfig()->get('allowed-commands',[]);$grants=(array)$this->getConfig()->get('grant-permissions',[]);
            if(!in_array('clanhome',$allowed,true))$allowed[]='clanhome';
            if(!in_array('amyxclans.command.home',$grants,true))$grants[]='amyxclans.command.home';
            $this->getConfig()->set('allowed-commands',$allowed);$this->getConfig()->set('grant-permissions',$grants);
            $this->getConfig()->set('clanhome-v201-migrated',true);$this->getConfig()->save();
        }
        $this->policy = new Policy((array)$this->getConfig()->get('allowed-commands', []));
        $this->players = new \WeakMap();
        $this->worldEditLimit = max(1, (int)$this->getConfig()->get('worldedit-max-blocks', 15000));
        $builder = $this->enabledPlugin('AmyBuilderGuard');
        if($builder instanceof PluginBase){
            $basic = max(1, (int)$builder->getConfig()->get('basic-max-blocks-per-operation', 15000));
            $this->worldEditReady = $basic <= $this->worldEditLimit;
            if(!$this->worldEditReady){
                $this->getLogger()->error('WorldEdit для обычных игроков закрыт: установите basic-max-blocks-per-operation: ' . $this->worldEditLimit . ' в plugins/AmyBuilderGuard/config.yml и перезапустите сервер.');
            }
        }
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
            foreach($this->getServer()->getOnlinePlayers() as $player){ $this->applyPolicy($player); }
        }), 20);
        foreach($this->getServer()->getOnlinePlayers() as $player){ $this->applyPolicy($player); }
    }

    private function enabledPlugin(string $name) : ?Plugin{
        $plugin = $this->getServer()->getPluginManager()->getPlugin($name);
        return $plugin !== null && $plugin->isEnabled() ? $plugin : null;
    }
    public function isOperator(Player $player) : bool{ return $this->getServer()->isOp($player->getName()); }
    private function authenticated(Player $player) : bool{
        if(!(bool)$this->getConfig()->get('require-authentication', true)){ return true; }
        $auth = $this->enabledPlugin('SimpleAuth');
        return $auth !== null && method_exists($auth, 'isAuthenticated') && $auth->isAuthenticated($player->getName());
    }
    private function message(Player $player, string $key) : void{
        $player->sendMessage((string)$this->getConfig()->getNested('messages.' . $key, '§cЭта команда недоступна.'));
    }

    /** Resolve a specific provider; never fall back to a similarly named command. */
    public function resolve(string $label) : ?Command{
        $map = $this->getServer()->getCommandMap();
        if($this->policy->isWorldEdit($label)){
            return $this->worldEditReady && $this->enabledPlugin('AmyBuilderGuard') !== null ? $map->getCommand('amybg:' . ($label === 'worldedit' ? 'we' : $label)) : null;
        }
        if($label === 'mine'){
            return $this->enabledPlugin('AmyxMine') !== null ? $map->getCommand('mine:mine') : null;
        }
        $provider = match($label){
            'balance', 'pay' => 'AmyxEconomy',
            'clan', 'clanhome' => 'AmyXClans',
            'spawn' => 'AmyxSpawn',
            'bank' => 'AmyxMarkets',
            'changepassword', 'security' => 'SimpleAuth',
            'register', 'login', 'emailcode' => 'AmyxCommandAccess',
            default => 'AmyxCommand'
        };
        $name = match($label){ 'tpc' => 'tpaccept', 'tpd' => 'tpdeny', default => $label };
        $plugin = $this->enabledPlugin($provider);
        return $plugin instanceof PluginBase ? $plugin->getCommand($name) : null;
    }

    public function applyPolicy(Player $player) : void{
        $previous = $this->players[$player] ?? null;
        if($this->isOperator($player)){
            if($previous !== null && $previous['attachment'] !== null){
                $player->removeAttachment($previous['attachment']);
                unset($this->players[$player]);
            }
            return;
        }
        $permissions = [];
        foreach($this->getServer()->getCommandMap()->getCommands() as $command){
            foreach($command->getPermissions() as $permission){ $permissions[$permission] = false; }
        }
        foreach((array)$this->getConfig()->get('grant-permissions', []) as $permission){ $permissions[(string)$permission] = true; }
        foreach((array)$this->getConfig()->get('deny-permissions', []) as $permission){ $permissions[(string)$permission] = false; }
        $state = $this->authenticated($player) ? 'logged-in' : 'guest';
        $needsUpdate = $previous === null || $previous['permissions'] !== $permissions;
        if(!$needsUpdate){
            foreach($permissions as $name => $value){
                if($player->hasPermission($name) !== $value){ $needsUpdate = true; break; }
            }
        }
        if($needsUpdate){
            if($previous !== null && $previous['attachment'] !== null){ $player->removeAttachment($previous['attachment']); }
            $attachment = $player->addAttachment($this);
            $this->players[$player] = ['attachment' => $attachment, 'permissions' => $permissions, 'state' => $state];
            $attachment->setPermissions($permissions);
        }else{
            $this->players[$player] = ['attachment' => $previous['attachment'], 'permissions' => $permissions, 'state' => $state];
        }
        if($previous === null || $previous['state'] !== $state || $needsUpdate){
            $player->getNetworkSession()->syncAvailableCommands();
        }
    }

    /** @priority MONITOR */
    public function onJoin(PlayerJoinEvent $event) : void{ $this->applyPolicy($event->getPlayer()); }
    public function onQuit(PlayerQuitEvent $event) : void{
        $player = $event->getPlayer();
        if(isset($this->players[$player])){
            $entry = $this->players[$player];
            unset($this->players[$player]);
            if($entry['attachment'] !== null){ $player->removeAttachment($entry['attachment']); }
        }
    }

    /** @priority LOWEST */
    public function onCommandEvent(CommandEvent $event) : void{
        $player = $event->getSender();
        if(!$player instanceof Player){ return; }
        $this->applyPolicy($player);
        if($this->isOperator($player)){ return; }
        // Cancel before AmyxCommand's interceptor can execute an unrelated provider.
        $event->cancel();
        try{ $args = CommandStringHelper::parseQuoteAware($event->getCommand()); }
        catch(InvalidCommandSyntaxException){ $this->message($player, 'denied'); return; }
        $label = strtolower((string)array_shift($args));
        if(str_starts_with($label, '/') && $this->policy->isWorldEdit(substr($label, 1))){ $label = substr($label, 1); }
        $label = $this->policy->normalize($label);
        $clans=$this->enabledPlugin('AmyXClans');
        if($clans!==null && method_exists($clans,'isInClanBattle') && $clans->isInClanBattle($player) && $label!=='clan'){
            $player->sendMessage('§5Во время битвы доступно только меню /clan.');return;
        }
        if(!$this->policy->allows($label)){ $this->message($player, 'denied'); return; }
        if(!$this->policy->isAuth($label) && !$this->authenticated($player)){ $this->message($player, 'login-first'); return; }
        // Preserve the existing additional restrictions of temporary builder sessions.
        $session = $this->enabledPlugin('AmyxBuildSession');
        if($session !== null && method_exists($session, 'onRestrictedCommand')){
            $check = new CommandEvent($player, $label . (count($args) ? ' ' . implode(' ', $args) : ''));
            $session->onRestrictedCommand($check);
            if($check->isCancelled()){ return; }
        }
        if($label === 'mine' && !$this->policy->safeMineArguments($args)){ $this->message($player, 'denied'); return; }
        $command = $this->resolve($label);
        if($command === null){ $this->message($player, 'unavailable'); return; }
        if($label === 'mine' && count($args) === 0){ $this->message($player, 'mine-help'); return; }
        if(in_array($label, ['tpa','tpc','tpd','tell'], true)){
            // These commands are implemented by AmyxCommand's public interceptor,
            // rather than its PluginCommand executor. No event is re-dispatched.
            $plugin = $this->enabledPlugin('AmyxCommand');
            if($plugin !== null && method_exists($plugin, 'onCommandEvent')){
                $plugin->onCommandEvent(new CommandEvent($player, $label . (count($args) ? ' ' . implode(' ', $args) : '')));
            }
            return;
        }
        if(!$command->testPermission($player)){ return; }
        try{ $command->execute($player, $label, $args); }
        catch(InvalidCommandSyntaxException){ $player->sendMessage('§f' . $command->getUsage()); }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if(!$sender instanceof Player){ $sender->sendMessage('Команда доступна в игре.'); return true; }
        $auth = $this->enabledPlugin('SimpleAuth');
        if($auth === null || !method_exists($auth, 'openMainForm')){ $this->message($sender, 'unavailable'); return true; }
        $loggedIn = $auth->isAuthenticated($sender->getName());
        if($command->getName() === 'emailcode'){
            if($loggedIn){
                $security = $this->resolve('security');
                if($security !== null && $security->testPermission($sender)){ $security->execute($sender, 'security', []); }
            }else{
                $this->message($sender, 'email-help');
                $auth->openMainForm($sender);
            }
        }elseif($loggedIn){ $this->message($sender, 'already-logged-in'); }
        else{ $auth->openMainForm($sender); }
        return true;
    }

    public function visibleLabels(Player $player) : array{
        $result = [];
        foreach($this->policy->labels() as $label){
            if(!$this->policy->isAuth($label) && !$this->authenticated($player)){ continue; }
            $command = $this->resolve($label);
            if($command !== null){ $result[$label] = $command->getDescription(); }
        }
        return $result;
    }

    /** @priority HIGHEST */
    public function onPacketSend(DataPacketSendEvent $event) : void{
        if($this->resending){ return; }
        $hasCommands = false;
        foreach($event->getPackets() as $packet){ if($packet instanceof AvailableCommandsPacket){ $hasCommands = true; break; } }
        if(!$hasCommands){ return; }
        $restricted = false;
        foreach($event->getTargets() as $target){
            $player = $target->getPlayer();
            if($player !== null && !$this->isOperator($player)){ $restricted = true; break; }
        }
        if(!$restricted){ return; }
        $event->cancel();
        $this->resending = true;
        try{
            foreach($event->getTargets() as $target){
                $player = $target->getPlayer();
                foreach($event->getPackets() as $packet){
                    $out = $packet;
                    if($packet instanceof AvailableCommandsPacket && $player !== null && !$this->isOperator($player)){
                        $out = CommandList::filter($packet, $this->visibleLabels($player));
                    }
                    $target->sendDataPacket($out);
                }
            }
        }finally{ $this->resending = false; }
    }

    protected function onDisable() : void{
        if(!isset($this->players)){ return; }
        foreach($this->players as $player => $entry){
            if($entry['attachment'] !== null){ $player->removeAttachment($entry['attachment']); }
        }
        $this->players = new \WeakMap();
    }
}
