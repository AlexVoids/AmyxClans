<?php

declare(strict_types=1);

namespace AmyX\BuilderGuard;

use AmyX\BuilderGuard\command\CallbackCommand;
use AmyX\BuilderGuard\form\CustomForm;
use AmyX\BuilderGuard\form\SimpleForm;
use AmyX\BuilderGuard\model\Region;
use AmyX\BuilderGuard\model\Selection;
use AmyX\BuilderGuard\listener\ProtectionListener;
use AmyX\BuilderGuard\service\RegionManager;
use AmyX\BuilderGuard\service\SessionManager;
use AmyX\BuilderGuard\service\SchematicService;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\tile\Container;
use pocketmine\block\tile\Spawnable;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerEntityInteractEvent;
use pocketmine\event\player\PlayerToggleGlideEvent;
use pocketmine\event\player\PlayerToggleSneakEvent;
use pocketmine\event\player\PlayerToggleSprintEvent;
use pocketmine\event\player\PlayerToggleSwimEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\LegacyStringToItemParserException;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use pocketmine\world\World;
use Throwable;

final class Main extends PluginBase implements Listener{
    private const BOOLEAN_FLAGS = [
        'build', 'break', 'place', 'interact', 'container', 'door-use', 'trapdoor-use', 'fence-gate-use',
        'button-use', 'lever-use', 'entity-interact', 'pvp', 'entry', 'exit', 'explosion',
        'fire-spread', 'block-burn', 'liquid-flow', 'leaf-decay', 'growth', 'crop-growth', 'melt',
        'ice-melt', 'snow-melt', 'form', 'item-drop', 'item-pickup', 'item-use', 'item-consume',
        'sleep', 'hunger', 'fly', 'glide', 'sprint', 'sneak', 'swim', 'projectile',
        'enderpearl', 'teleport', 'bucket-fill', 'bucket-empty', 'sign-edit', 'trample',
        'mob-damage', 'entity-damage', 'fall-damage', 'void-damage', 'drowning-damage',
        'lava-damage', 'fire-damage', 'suffocation-damage', 'starvation-damage', 'magic-damage',
        'invincible', 'keep-inventory', 'keep-experience', 'chat', 'commands'
    ];

    private const TEXT_FLAGS = ['greeting', 'farewell', 'deny-message'];

    /** @var array<string, array{title:string, flags:array<string,string>}> */
    private const FLAG_CATEGORIES = [
        'build' => [
            'title' => 'Строительство',
            'flags' => [
                'build' => 'Разрешить строительство целиком',
                'break' => 'Разрешить ломать блоки',
                'place' => 'Разрешить ставить блоки',
                'interact' => 'Разрешить взаимодействие с блоками',
                'container' => 'Разрешить сундуки и контейнеры',
                'door-use' => 'Разрешить двери',
                'trapdoor-use' => 'Разрешить люки',
                'fence-gate-use' => 'Разрешить калитки',
                'button-use' => 'Разрешить кнопки',
                'lever-use' => 'Разрешить рычаги',
                'sign-edit' => 'Разрешить редактировать таблички',
                'bucket-fill' => 'Разрешить набирать жидкости',
                'bucket-empty' => 'Разрешить выливать жидкости'
            ]
        ],
        'players' => [
            'title' => 'Игроки',
            'flags' => [
                'pvp' => 'Разрешить PvP',
                'entity-interact' => 'Разрешить взаимодействие с сущностями',
                'item-drop' => 'Разрешить выбрасывать предметы',
                'item-pickup' => 'Разрешить подбирать предметы',
                'item-use' => 'Разрешить использовать предметы',
                'item-consume' => 'Разрешить еду и зелья',
                'sleep' => 'Разрешить спать',
                'hunger' => 'Разрешить расход голода',
                'fly' => 'Разрешить полёт',
                'glide' => 'Разрешить элитры',
                'sprint' => 'Разрешить бег',
                'sneak' => 'Разрешить приседание',
                'swim' => 'Разрешить плавание',
                'chat' => 'Разрешить чат',
                'commands' => 'Разрешить команды'
            ]
        ],
        'movement' => [
            'title' => 'Вход и перемещение',
            'flags' => [
                'entry' => 'Разрешить вход в регион',
                'exit' => 'Разрешить выход из региона',
                'teleport' => 'Разрешить телепортацию в регион',
                'projectile' => 'Разрешить снаряды',
                'enderpearl' => 'Разрешить эндер-жемчуг'
            ]
        ],
        'world' => [
            'title' => 'Мир и природа',
            'flags' => [
                'explosion' => 'Разрешить взрывы',
                'fire-spread' => 'Разрешить распространение огня',
                'block-burn' => 'Разрешить сгорание блоков',
                'liquid-flow' => 'Разрешить течение воды и лавы',
                'leaf-decay' => 'Разрешить опадание листвы',
                'growth' => 'Разрешить общий рост',
                'crop-growth' => 'Разрешить рост урожая',
                'melt' => 'Разрешить таяние',
                'ice-melt' => 'Разрешить таяние льда',
                'snow-melt' => 'Разрешить таяние снега',
                'form' => 'Разрешить образование блоков',
                'trample' => 'Разрешить вытаптывание грядок'
            ]
        ],
        'damage' => [
            'title' => 'Урон',
            'flags' => [
                'mob-damage' => 'Разрешить игрокам бить мобов',
                'entity-damage' => 'Разрешить урон сущностям',
                'fall-damage' => 'Разрешить урон от падения',
                'void-damage' => 'Разрешить урон от пустоты',
                'drowning-damage' => 'Разрешить урон от утопления',
                'lava-damage' => 'Разрешить урон от лавы',
                'fire-damage' => 'Разрешить урон от огня',
                'suffocation-damage' => 'Разрешить удушение в блоках',
                'starvation-damage' => 'Разрешить урон от голода',
                'magic-damage' => 'Разрешить магический урон',
                'invincible' => 'Включить бессмертие',
                'keep-inventory' => 'Сохранять инвентарь после смерти',
                'keep-experience' => 'Сохранять опыт после смерти'
            ]
        ]
    ];

    /** @var array<string,string> */
    private const DEFAULT_REGION_FLAGS = [
        'build' => 'deny',
        'break' => 'deny',
        'place' => 'deny',
        'interact' => 'deny',
        'container' => 'deny',
        'door-use' => 'deny',
        'trapdoor-use' => 'deny',
        'fence-gate-use' => 'deny',
        'button-use' => 'deny',
        'lever-use' => 'deny',
        'entity-interact' => 'deny',
        'pvp' => 'deny',
        'explosion' => 'deny',
        'fire-spread' => 'deny',
        'block-burn' => 'deny',
        'bucket-fill' => 'deny',
        'bucket-empty' => 'deny'
    ];

    private SessionManager $sessions;
    private RegionManager $regions;
    private int $maxBlocks;
    private int $basicMaxBlocks;
    /** @var array<string,int> Exact selected block states for /set block. */
    private array $selectedInfoBlocks = [];
    /** @var array<string,int>|null Bedrock legacy IDs where a mapping exists. */
    private ?array $legacyBlockIds = null;
    private int $maxClipboard;
    private bool $updateBlocks;
    private string $schematicsFolder;
    private SchematicService $schematics;

    protected function onEnable() : void{
        $this->saveDefaultConfig();
        $this->maxBlocks = max(1, (int) $this->getConfig()->getNested('max-blocks-per-operation', 200000));
        $this->basicMaxBlocks = max(1, (int) $this->getConfig()->getNested('basic-max-blocks-per-operation', 15000));
        $this->maxClipboard = max(1, $this->getConfig()->getNested('max-clipboard-blocks', 200000));
        $this->updateBlocks = (bool) $this->getConfig()->getNested('update-blocks', false);
        $this->sessions = new SessionManager(max(1, (int) $this->getConfig()->getNested('history-size', 10)));
        $this->regions = new RegionManager(new Config($this->getDataFolder() . 'regions.yml', Config::YAML));
        $this->schematicsFolder = $this->getDataFolder() . 'schematics' . DIRECTORY_SEPARATOR;
        $this->schematics = new SchematicService();
        @mkdir($this->schematicsFolder, 0777, true);

        $this->registerCommands();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getPluginManager()->registerEvents(new ProtectionListener($this), $this);
        $this->getLogger()->info('AmyBuilderGuard включён: WorldEdit + WorldGuard.');
    }

    private function registerCommands() : void{
        $map = $this->getServer()->getCommandMap();
        $commands = [
            new CallbackCommand('wand', 'Получить топор выделения', '/wand', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdWand($s)),
            new CallbackCommand('setinfo', 'Выдать палку для выбора блока /set block', '/setinfo', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdSetInfo($s)),
            new CallbackCommand('pos1', 'Установить первую точку', '/pos1 [x y z]', ['1'], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdPos($s, $a, 1)),
            new CallbackCommand('pos2', 'Установить вторую точку', '/pos2 [x y z]', ['2'], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdPos($s, $a, 2)),
            new CallbackCommand('sel', 'Сбросить выделение', '/sel', ['desel', 'deselect'], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdSel($s)),
            new CallbackCommand('size', 'Размер выделения', '/size', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdSize($s)),
            new CallbackCommand('expand', 'Расширить выделение', '/expand <число> <направление>', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdResizeSelection($s, $a, true)),
            new CallbackCommand('contract', 'Сжать выделение', '/contract <число> <направление>', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdResizeSelection($s, $a, false)),
            new CallbackCommand('set', 'Заполнить выделение', '/set <block>', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdSet($s, $a)),
            new CallbackCommand('replace', 'Заменить блоки', '/replace <from> <to>', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdReplace($s, $a)),
            new CallbackCommand('walls', 'Создать стены', '/walls <block>', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdBoundary($s, $a, false)),
            new CallbackCommand('outline', 'Создать оболочку', '/outline <block>', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdBoundary($s, $a, true)),
            new CallbackCommand('copy', 'Копировать выделение', '/copy', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdCopy($s, false)),
            new CallbackCommand('cut', 'Вырезать выделение', '/cut', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdCopy($s, true)),
            new CallbackCommand('paste', 'Вставить буфер', '/paste [-a]', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdPaste($s, $a)),
            new CallbackCommand('rotate', 'Повернуть буфер по Y', '/rotate <90|180|270>', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdRotate($s, $a)),
            new CallbackCommand('undo', 'Отменить изменение', '/undo', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdUndoRedo($s, false)),
            new CallbackCommand('redo', 'Повторить изменение', '/redo', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdUndoRedo($s, true)),
            new CallbackCommand('sphere', 'Создать сферу', '/sphere <block> <radius>', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdSphere($s, $a, false)),
            new CallbackCommand('hsphere', 'Создать полую сферу', '/hsphere <block> <radius>', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdSphere($s, $a, true)),
            new CallbackCommand('cyl', 'Создать цилиндр', '/cyl <block> <radius> <height>', ['cylinder'], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdCylinder($s, $a, false)),
            new CallbackCommand('hcyl', 'Создать полый цилиндр', '/hcyl <block> <radius> <height>', ['hcylinder'], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdCylinder($s, $a, true)),
            new CallbackCommand('line', 'Провести линию между точками', '/line <block>', [], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdLine($s, $a)),
            new CallbackCommand('schem', 'Управление схематиками', '/schem <save|load|list|delete> [name]', ['schematic'], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdSchematic($s, $a)),
            new CallbackCommand('we', 'Справка AmyBuilderGuard', '/we', ['worldedit'], 'amybg.worldedit.basic;amybg.worldedit.advanced;amybg.worldedit', fn($s, $l, $a) => $this->cmdWeHelp($s)),
            new CallbackCommand('rg', 'Управление регионами', '/rg <команда>', ['region'], 'amybg.worldguard', fn($s, $l, $a) => $this->cmdRegion($s, $a))
        ];
        foreach($commands as $command){
            $map->register('amybg', $command);
        }
    }

    private function player(CommandSender $sender) : ?Player{
        if(!$sender instanceof Player){
            $sender->sendMessage('§cКоманда доступна только в игре.');
            return null;
        }
        return $sender;
    }

    private function cmdWeHelp(CommandSender $sender) : void{
        $sender->sendMessage("§dAmyBuilderGuard §f— основные команды:");
        $sender->sendMessage('§f/wand, /setinfo, /pos1, /pos2, /set block, /set stone');
        $sender->sendMessage('§f/copy, /cut, /paste, /rotate, /undo, /redo');
        $sender->sendMessage('§f/sphere, /hsphere, /cyl, /hcyl, /line, /schem');
        $sender->sendMessage('§7Лимит операции: §e' . ($sender instanceof Player ? $this->operationLimit($sender) : $this->maxBlocks));
        $sender->sendMessage('§f/rg help — команды регионов.');
    }

    private function canEdit(Player $player) : bool{
        return $player->hasPermission('amybg.worldedit.basic') ||
            $player->hasPermission('amybg.worldedit.advanced') ||
            $player->hasPermission('amybg.worldedit');
    }

    /** An explicit high-tier permission also preserves access for legacy amybg.worldedit OP users. */
    private function isAdvanced(Player $player) : bool{
        return $player->hasPermission('amybg.worldedit.advanced') || $player->hasPermission('amybg.worldedit');
    }

    private function operationLimit(Player $player) : int{
        return $this->isAdvanced($player) ? $this->maxBlocks : min($this->maxBlocks, $this->basicMaxBlocks);
    }

    private function requireShapePermission(Player $player) : bool{
        if($this->isAdvanced($player) || $player->hasPermission('amybg.worldedit.shapes')){
            return true;
        }
        $player->sendMessage('§cДля /set, /sphere, /cyl и /line нужно право §eamybg.worldedit.shapes§c.');
        return false;
    }

    private function cmdSetInfo(CommandSender $sender) : void{
        if(($player = $this->player($sender)) === null){ return; }
        $item = VanillaItems::STICK();
        $item->setCustomName('§r§bПалка выбора блока §7(/setinfo)');
        $item->getNamedTag()->setByte('amybg_setinfo', 1);
        $player->getInventory()->addItem($item);
        $player->sendMessage('§aПалка выдана. Нажми ею на блок, затем используй §e/set block§a.');
    }

    private function isInfoStick(Item $item) : bool{
        return $item->getNamedTag()->getByte('amybg_setinfo', 0) === 1;
    }

    private function selectInfoBlock(Player $player, Block $block) : void{
        if(!$this->canEdit($player)){ return; }
        $this->selectedInfoBlocks[strtolower($player->getName())] = $block->getStateId();
        $name = $block->getName();
        $message = '§bВыбран: §f' . $name . ' §7| Числовой ID: §e' . $this->legacyBlockId($block);
        $player->sendActionBarMessage($message);
    }

    /** Legacy numeric Bedrock IDs are not stable PM5 state/type IDs. Display only mapped old IDs. */
    private function legacyBlockId(Block $block) : string{
        try{
            if($this->legacyBlockIds === null){
                $this->legacyBlockIds = [];
                foreach(\pocketmine\data\bedrock\block\upgrade\LegacyBlockIdToStringIdMap::getInstance()->getLegacyToStringMap() as $id => $stringId){
                    if(!isset($this->legacyBlockIds[$stringId])){
                        $this->legacyBlockIds[$stringId] = (int) $id;
                    }
                }
            }
            $stringId = \pocketmine\network\mcpe\convert\TypeConverter::getInstance()
                ->getBlockTranslator()->internalIdToNetworkStateData($block->getStateId())->getName();
            return isset($this->legacyBlockIds[$stringId]) ? (string) $this->legacyBlockIds[$stringId] : '-';
        }catch(\Throwable){
            return '-';
        }
    }

    private function cmdWand(CommandSender $sender) : void{
        if(($player = $this->player($sender)) === null){
            return;
        }
        $item = VanillaItems::WOODEN_AXE();
        $item->setCustomName('§r§dТопор выделения');
        $item->getNamedTag()->setByte('amybg_wand', 1);
        $player->getInventory()->addItem($item);
        $player->sendMessage('§aТопор выдан. ЛКМ — точка 1, ПКМ — точка 2.');
    }

    /** @param string[] $args */
    private function cmdPos(CommandSender $sender, array $args, int $number) : void{
        if(($player = $this->player($sender)) === null){
            return;
        }
        $pos = $player->getPosition()->floor();
        if(count($args) >= 3 && is_numeric($args[0]) && is_numeric($args[1]) && is_numeric($args[2])){
            $pos = new Vector3((int) $args[0], (int) $args[1], (int) $args[2]);
        }
        $selection = $this->sessions->getSelection($player->getName());
        if($number === 1){
            $selection->setPos1($pos, $player->getWorld()->getFolderName());
        }else{
            $selection->setPos2($pos, $player->getWorld()->getFolderName());
        }
        $player->sendMessage("§aТочка {$number}: §f{$pos->getFloorX()} {$pos->getFloorY()} {$pos->getFloorZ()}");
    }

    private function cmdSel(CommandSender $sender) : void{
        if(($player = $this->player($sender)) === null){
            return;
        }
        $this->sessions->getSelection($player->getName())->clear();
        $player->sendMessage('§aВыделение сброшено.');
    }

    private function cmdSize(CommandSender $sender) : void{
        if(($player = $this->player($sender)) === null){
            return;
        }
        $selection = $this->sessions->getSelection($player->getName());
        if(!$selection->isComplete()){
            $player->sendMessage('§cСначала выдели две точки.');
            return;
        }
        [$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = $selection->bounds();
        $player->sendMessage('§aРазмер: §f' . ($maxX - $minX + 1) . 'x' . ($maxY - $minY + 1) . 'x' . ($maxZ - $minZ + 1) . ' §7(' . $selection->volume() . ' блоков)');
    }

    /** @param string[] $args */
    private function cmdResizeSelection(CommandSender $sender, array $args, bool $expand) : void{
        if(($player = $this->player($sender)) === null){
            return;
        }
        if(count($args) < 2 || !is_numeric($args[0])){
            $player->sendMessage('§cИспользование: //' . ($expand ? 'expand' : 'contract') . ' <число> <up|down|north|south|east|west|vert>');
            return;
        }
        $amount = max(0, (int) $args[0]);
        $direction = strtolower($args[1]);
        $selection = $this->sessions->getSelection($player->getName());
        if(!$selection->isComplete()){
            $player->sendMessage('§cСначала выдели две точки.');
            return;
        }
        [$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = $selection->bounds();
        $delta = $expand ? $amount : -$amount;
        switch($direction){
            case 'up': $maxY += $delta; break;
            case 'down': $minY -= $delta; break;
            case 'north': $minZ -= $delta; break;
            case 'south': $maxZ += $delta; break;
            case 'west': $minX -= $delta; break;
            case 'east': $maxX += $delta; break;
            case 'vert': $minY -= $delta; $maxY += $delta; break;
            default:
                $player->sendMessage('§cНаправление: up, down, north, south, east, west или vert.');
                return;
        }
        if($minX > $maxX || $minY > $maxY || $minZ > $maxZ){
            $player->sendMessage('§cНельзя сжать выделение сильнее его размера.');
            return;
        }
        $world = $selection->world ?? $player->getWorld()->getFolderName();
        $selection->setPos1(new Vector3($minX, $minY, $minZ), $world);
        $selection->setPos2(new Vector3($maxX, $maxY, $maxZ), $world);
        $player->sendMessage('§aВыделение изменено. Новый объём: §f' . $selection->volume());
    }

    /** @return array{0:Selection,1:World,2:array{0:int,1:int,2:int,3:int,4:int,5:int}}|null */
    private function selectionContext(Player $player, bool $checkLimit = true) : ?array{
        $selection = $this->sessions->getSelection($player->getName());
        if(!$selection->isComplete()){
            $player->sendMessage('§cСначала выдели две точки.');
            return null;
        }
        $world = $this->getServer()->getWorldManager()->getWorldByName((string) $selection->world);
        if($world === null){
            $player->sendMessage('§cМир выделения не загружен.');
            return null;
        }
        if($checkLimit && $selection->volume() > $this->operationLimit($player)){
            $player->sendMessage("§cСлишком большая операция: {$selection->volume()} блоков. Лимит: {$this->operationLimit($player)}.");
            return null;
        }
        return [$selection, $world, $selection->bounds()];
    }

    private function parseBlock(string $input) : ?Block{
        $input = trim($input);
        if($input === ''){
            return null;
        }

        $item = StringToItemParser::getInstance()->parse($input);
        if($item === null){
            try{
                // Legacy IDs are intentionally supported: /set 2, /set 1, /set 35:14, etc.
                $item = LegacyStringToItemParser::getInstance()->parse($input);
            }catch(LegacyStringToItemParserException){
                return null;
            }
        }

        $block = $item->getBlock();
        $normalized = strtolower(str_replace(['minecraft:', ' '], ['', '_'], $input));
        if($block->getTypeId() === BlockTypeIds::AIR && !in_array($normalized, ['0', 'air'], true)){
            return null;
        }
        return $block;
    }

    private function replacementAllowed(Player $player, World $world, int $x, int $y, int $z, Block $old, Block $new) : bool{
        if($old->getStateId()===$new->getStateId())return true;
        if($old->getTypeId()!==BlockTypeIds::AIR && !$this->actionAllowed($player,'break',$world->getFolderName(),$x,$y,$z))return false;
        return $new->getTypeId()===BlockTypeIds::AIR || $this->actionAllowed($player,'place',$world->getFolderName(),$x,$y,$z);
    }
    /**
     * @param iterable<array{0:int,1:int,2:int}> $positions
     * @param callable(Block,int,int,int):?Block $resolver
     */
    private function applyEdit(Player $player, World $world, iterable $positions, callable $resolver, int $estimated, string $label) : void{
        if($estimated > $this->operationLimit($player)){
            $player->sendMessage("§cОперация затронет до {$estimated} блоков. Лимит: {$this->operationLimit($player)}.");
            return;
        }
        $changes = [];
        $changed = 0;
        try{
            foreach($positions as [$x, $y, $z]){
                if($y < $world->getMinY() || $y >= $world->getMaxY()){
                    continue;
                }
                $old = $world->getBlockAt($x, $y, $z);
                $new = $resolver($old, $x, $y, $z);
                if($new === null || $old->getStateId() === $new->getStateId()){
                    continue;
                }
                if(!$this->replacementAllowed($player,$world,$x,$y,$z,$old,$new)){
                    continue;
                }
                if($changed >= $this->operationLimit($player)){
                    $player->sendMessage('§cЛимит операции достигнут; дальнейшие блоки не изменены.');
                    break;
                }
                $changes[] = [$world->getFolderName(), $x, $y, $z, $old->getStateId(), $new->getStateId()];
                $world->setBlockAt($x, $y, $z, $new, $this->updateBlocks);
                ++$changed;
            }
        }catch(Throwable $e){
            $this->getLogger()->error($e->getMessage());
            $player->sendMessage('§cОшибка операции. Уже изменённые блоки доступны через /undo.');
        }
        $this->sessions->pushHistory($player->getName(), $changes);
        $player->sendMessage("§a{$label}: §f{$changed} блоков.");
    }

    /** @return \Generator<int,array{0:int,1:int,2:int}> */
    private function cuboid(array $bounds) : \Generator{
        [$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = $bounds;
        for($x = $minX; $x <= $maxX; ++$x){
            for($y = $minY; $y <= $maxY; ++$y){
                for($z = $minZ; $z <= $maxZ; ++$z){
                    yield [$x, $y, $z];
                }
            }
        }
    }

    /** @return \Generator<int,array{0:int,1:int,2:int}> */
    private function boundary(array $bounds, bool $outline) : \Generator{
        [$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = $bounds;
        for($x = $minX; $x <= $maxX; ++$x){
            for($y = $minY; $y <= $maxY; ++$y){
                for($z = $minZ; $z <= $maxZ; ++$z){
                    $edgeXZ = $x === $minX || $x === $maxX || $z === $minZ || $z === $maxZ;
                    $edgeY = $y === $minY || $y === $maxY;
                    if($edgeXZ || ($outline && $edgeY)){
                        yield [$x, $y, $z];
                    }
                }
            }
        }
    }

    /** @param string[] $args */
    private function cmdSet(CommandSender $sender, array $args) : void{
        if(($player = $this->player($sender)) === null){ return; }
        if(!$this->requireShapePermission($player)){ return; }
        if(strtolower($args[0] ?? '') === 'block'){
            $state = $this->selectedInfoBlocks[strtolower($player->getName())] ?? null;
            if($state === null){
                $player->sendMessage('§cСначала возьми палку /setinfo и выбери ею нужный блок.');
                return;
            }
            try{
                $block = RuntimeBlockStateRegistry::getInstance()->fromStateId($state);
            }catch(Throwable){
                $player->sendMessage('§cВыбранное состояние блока недоступно. Выбери блок заново.');
                return;
            }
        }else{
            $block = $this->parseBlock($args[0] ?? '');
            if($block === null){
                $player->sendMessage('§cНеизвестный блок. Укажи stone, air, ID или используй /setinfo → /set block.');
                return;
            }
        }
        if(($ctx = $this->selectionContext($player)) === null){ return; }
        [$selection, $world, $bounds] = $ctx;
        $this->applyEdit($player, $world, $this->cuboid($bounds), static fn() => clone $block, $selection->volume(), '/set');
    }

    /** @param string[] $args */
    private function cmdReplace(CommandSender $sender, array $args) : void{
        if(($player = $this->player($sender)) === null){ return; }
        $from = $this->parseBlock($args[0] ?? '');
        $to = $this->parseBlock($args[1] ?? '');
        if($from === null || $to === null){
            $player->sendMessage('§cИспользование: /replace <from> <to>');
            return;
        }
        if(($ctx = $this->selectionContext($player)) === null){ return; }
        [$selection, $world, $bounds] = $ctx;
        $fromType = $from->getTypeId();
        $this->applyEdit($player, $world, $this->cuboid($bounds), static fn(Block $old) => $old->getTypeId() === $fromType ? clone $to : null, $selection->volume(), '/replace');
    }

    /** @param string[] $args */
    private function cmdBoundary(CommandSender $sender, array $args, bool $outline) : void{
        if(($player = $this->player($sender)) === null){ return; }
        if(($block = $this->parseBlock($args[0] ?? '')) === null){
            $player->sendMessage('§cУкажи блок.');
            return;
        }
        if(($ctx = $this->selectionContext($player)) === null){ return; }
        [$selection, $world, $bounds] = $ctx;
        $this->applyEdit($player, $world, $this->boundary($bounds, $outline), static fn() => clone $block, $selection->volume(), $outline ? '/outline' : '/walls');
    }

    private function cmdCopy(CommandSender $sender, bool $cut) : void{
        if(($player = $this->player($sender)) === null){ return; }
        if(($ctx = $this->selectionContext($player, false)) === null){ return; }
        [$selection, $world, $bounds] = $ctx;
        $volume = $selection->volume();
        if($volume > $this->maxClipboard || $volume > $this->operationLimit($player)){
            $player->sendMessage("§cОбъём {$volume}, лимит операции {$this->operationLimit($player)} и буфера {$this->maxClipboard}.");
            return;
        }
        [$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = $bounds;
        $blocks = [];
        $tiles = [];
        foreach($this->cuboid($bounds) as [$x, $y, $z]){
            $rx = $x - $minX;
            $ry = $y - $minY;
            $rz = $z - $minZ;
            $blocks[] = [$rx, $ry, $rz, $world->getBlockAt($x, $y, $z)->getStateId()];
            $tile = $world->getTileAt($x, $y, $z);
            if($tile !== null){
                try{
                    $nbt = $tile->saveNBT();
                    $nbt->setInt(Tile::TAG_X, $rx)->setInt(Tile::TAG_Y, $ry)->setInt(Tile::TAG_Z, $rz);
                    if($nbt->getTag('pairx') !== null){ $nbt->setInt('pairx', $nbt->getInt('pairx') - $minX); }
                    if($nbt->getTag('pairz') !== null){ $nbt->setInt('pairz', $nbt->getInt('pairz') - $minZ); }
                    $tiles[] = [$rx, $ry, $rz, $this->schematics->encodeCompound($nbt)];
                }catch(Throwable $e){
                    $this->getLogger()->warning("Не удалось скопировать tile {$x}:{$y}:{$z}: {$e->getMessage()}");
                }
            }
        }
        $p = $player->getPosition()->floor();
        $clipboard = [
            'size' => [$maxX - $minX + 1, $maxY - $minY + 1, $maxZ - $minZ + 1],
            'offset' => [$p->getFloorX() - $minX, $p->getFloorY() - $minY, $p->getFloorZ() - $minZ],
            'blocks' => $blocks,
            'tiles' => $tiles
        ];
        $this->sessions->setClipboard($player->getName(), $clipboard);
        $player->sendMessage("§aСкопировано: §f{$volume} блоков, " . count($tiles) . ' блоков с данными.');
        if($cut){
            $air = VanillaBlocks::AIR();
            $this->applyEdit($player, $world, $this->cuboid($bounds), static fn() => clone $air, $volume, '/cut');
        }
    }

    /** @param string[] $args */
    private function cmdPaste(CommandSender $sender, array $args) : void{
        if(($player = $this->player($sender)) === null){ return; }
        $clipboard = $this->sessions->getClipboard($player->getName());
        if($clipboard === null){
            $player->sendMessage('§cБуфер пуст. Используй /copy или /schem load.');
            return;
        }
        $skipAir = in_array('-a', $args, true);
        $origin = $player->getPosition()->floor();
        [$offX, $offY, $offZ] = $clipboard['offset'];
        $baseX = $origin->getFloorX() - $offX;
        $baseY = $origin->getFloorY() - $offY;
        $baseZ = $origin->getFloorZ() - $offZ;
        $registry = RuntimeBlockStateRegistry::getInstance();
        $blocks = $clipboard['blocks'];
        if(count($blocks) > $this->operationLimit($player)){
            $player->sendMessage("§cСхематика содержит " . count($blocks) . " блоков. Лимит операции: {$this->operationLimit($player)}.");
            return;
        }
        $world = $player->getWorld();
        $changes = [];
        $changed = 0;
        foreach($blocks as [$rx, $ry, $rz, $state]){
            $x = $baseX + $rx; $y = $baseY + $ry; $z = $baseZ + $rz;
            if($y < $world->getMinY() || $y >= $world->getMaxY()){
                continue;
            }
            $new = $registry->fromStateId($state);
            if($skipAir && $new->getTypeId() === BlockTypeIds::AIR){
                continue;
            }
            $old = $world->getBlockAt($x, $y, $z);
            if(!$this->replacementAllowed($player,$world,$x,$y,$z,$old,$new)){
                continue;
            }
            if($old->getStateId() !== $state){
                $changes[] = [$world->getFolderName(), $x, $y, $z, $old->getStateId(), $state];
                $world->setBlockAt($x, $y, $z, $new, $this->updateBlocks);
                ++$changed;
            }
        }

        $restoredTiles = 0;
        foreach($clipboard['tiles'] ?? [] as [$rx, $ry, $rz, $encoded]){
            $x = $baseX + $rx; $y = $baseY + $ry; $z = $baseZ + $rz;
            if($y < $world->getMinY() || $y >= $world->getMaxY() || !$this->actionAllowed($player, 'place', $world->getFolderName(), $x, $y, $z)){
                continue;
            }
            try{
                $nbt = $this->schematics->decodeCompound($encoded);
                $nbt->setInt(Tile::TAG_X, $x)->setInt(Tile::TAG_Y, $y)->setInt(Tile::TAG_Z, $z);
                if($nbt->getTag('pairx') !== null){ $nbt->setInt('pairx', $baseX + $nbt->getInt('pairx')); }
                if($nbt->getTag('pairz') !== null){ $nbt->setInt('pairz', $baseZ + $nbt->getInt('pairz')); }
                $tile = $world->getTileAt($x, $y, $z);
                if($tile !== null){
                    $tile->readSaveData($nbt);
                }else{
                    $tile = TileFactory::getInstance()->createFromData($world, $nbt);
                    if($tile !== null){
                        $world->addTile($tile);
                    }
                }
                if($tile instanceof Spawnable){
                    $tile->clearSpawnCompoundCache();
                }
                ++$restoredTiles;
            }catch(Throwable $e){
                $this->getLogger()->warning("Не удалось восстановить tile {$x}:{$y}:{$z}: {$e->getMessage()}");
            }
        }
        $this->sessions->pushHistory($player->getName(), $changes);
        $player->sendMessage("§aВставлено: §f{$changed} блоков, восстановлено {$restoredTiles} контейнеров/табличек.");
    }

    /** @param string[] $args */
    private function cmdRotate(CommandSender $sender, array $args) : void{
        if(($player = $this->player($sender)) === null){ return; }
        $degrees = ((int) ($args[0] ?? 0) % 360 + 360) % 360;
        if(!in_array($degrees, [90, 180, 270], true)){
            $player->sendMessage('§cИспользование: /rotate <90|180|270>');
            return;
        }
        $clipboard = $this->sessions->getClipboard($player->getName());
        if($clipboard === null){
            $player->sendMessage('§cБуфер пуст.');
            return;
        }
        for($turn = 0; $turn < intdiv($degrees, 90); ++$turn){
            [$sx, $sy, $sz] = $clipboard['size'];
            [$ox, $oy, $oz] = $clipboard['offset'];
            $rotated = [];
            foreach($clipboard['blocks'] as [$x, $y, $z, $state]){
                $rotated[] = [$z, $y, $sx - 1 - $x, $state];
            }
            $rotatedTiles = [];
            foreach($clipboard['tiles'] ?? [] as [$x, $y, $z, $encoded]){
                try{
                    $nbt = $this->schematics->decodeCompound($encoded);
                    if($nbt->getTag('pairx') !== null && $nbt->getTag('pairz') !== null){
                        $pairX = $nbt->getInt('pairx');
                        $pairZ = $nbt->getInt('pairz');
                        $nbt->setInt('pairx', $pairZ)->setInt('pairz', $sx - 1 - $pairX);
                    }
                    $nbt->setInt(Tile::TAG_X, $z)->setInt(Tile::TAG_Y, $y)->setInt(Tile::TAG_Z, $sx - 1 - $x);
                    $encoded = $this->schematics->encodeCompound($nbt);
                }catch(Throwable){
                }
                $rotatedTiles[] = [$z, $y, $sx - 1 - $x, $encoded];
            }
            $clipboard = [
                'size' => [$sz, $sy, $sx],
                'offset' => [$oz, $oy, $sx - 1 - $ox],
                'blocks' => $rotated,
                'tiles' => $rotatedTiles
            ];
        }
        $this->sessions->setClipboard($player->getName(), $clipboard);
        $player->sendMessage("§aБуфер повёрнут на {$degrees}°. Блоки с NBT также перемещены.");
    }

    private function cmdUndoRedo(CommandSender $sender, bool $redo) : void{
        if(($player = $this->player($sender)) === null){ return; }
        $preview = $redo ? $this->sessions->peekRedo($player->getName()) : $this->sessions->peekUndo($player->getName());
        if($preview !== null && count($preview) > $this->operationLimit($player)){
            $player->sendMessage('§cИстория содержит ' . count($preview) . ' блоков; лимит: ' . $this->operationLimit($player));
            return;
        }
        $operation = $redo ? $this->sessions->popRedo($player->getName()) : $this->sessions->popUndo($player->getName());
        if($operation === null){
            $player->sendMessage($redo ? '§cНечего повторять.' : '§cНечего отменять.');
            return;
        }
        $registry = RuntimeBlockStateRegistry::getInstance();
        $entries = $redo ? $operation : array_reverse($operation);
        $changed = 0;
        foreach($entries as [$worldName, $x, $y, $z, $oldState, $newState]){
            $world = $this->getServer()->getWorldManager()->getWorldByName($worldName);
            if($world === null){ continue; }
            $targetBlock=$registry->fromStateId($redo ? $newState : $oldState);
            if(!$this->replacementAllowed($player,$world,$x,$y,$z,$world->getBlockAt($x,$y,$z),$targetBlock))continue;
            $world->setBlockAt($x, $y, $z, $targetBlock, $this->updateBlocks);
            ++$changed;
        }
        $player->sendMessage(($redo ? '§aПовторено: §f' : '§aОтменено: §f') . $changed . ' блоков.');
    }

    /** Exact count of positions generated by the sphere tool, including hollow mode. */
    private function sphereBlockCount(int $radius, bool $hollow) : int{
        $outer = $radius * $radius;
        $inner = max(0, ($radius - 1) * ($radius - 1));
        $count = 0;
        for($x = -$radius; $x <= $radius; ++$x){
            for($y = -$radius; $y <= $radius; ++$y){
                $d = $x * $x + $y * $y;
                if($d > $outer){ continue; }
                $maxZ = (int) floor(sqrt($outer - $d));
                $count += 2 * $maxZ + 1;
                if($hollow && $d < $inner){
                    $insideMaxZ = (int) floor(sqrt($inner - $d - 1));
                    $count -= 2 * $insideMaxZ + 1;
                }
            }
        }
        return $count;
    }

    private function cylinderBlockCount(int $radius, int $height, bool $hollow) : int{
        $outer = $radius * $radius;
        $inner = max(0, ($radius - 1) * ($radius - 1));
        $count = 0;
        for($x = -$radius; $x <= $radius; ++$x){
            $d = $x * $x;
            $maxZ = (int) floor(sqrt($outer - $d));
            $count += 2 * $maxZ + 1;
            if($hollow && $d < $inner){
                $insideMaxZ = (int) floor(sqrt($inner - $d - 1));
                $count -= 2 * $insideMaxZ + 1;
            }
        }
        return $count * $height;
    }

    /** @param string[] $args */
    private function cmdSphere(CommandSender $sender, array $args, bool $hollow) : void{
        if(($player = $this->player($sender)) === null){ return; }
        if(!$this->requireShapePermission($player)){ return; }
        $block = $this->parseBlock($args[0] ?? '');
        $radius = (int) ($args[1] ?? 0);
        if($block === null || $radius < 1 || $radius > 100){
            $player->sendMessage('§cИспользование: //' . ($hollow ? 'hsphere' : 'sphere') . ' <block> <radius 1..100>');
            return;
        }
        $center = $player->getPosition()->floor();
        $estimated = $this->sphereBlockCount($radius, $hollow);
        $positions = (function() use ($center, $radius, $hollow) : \Generator{
            $outer = $radius * $radius;
            $inner = max(0, ($radius - 1) * ($radius - 1));
            for($x = -$radius; $x <= $radius; ++$x){
                for($y = -$radius; $y <= $radius; ++$y){
                    for($z = -$radius; $z <= $radius; ++$z){
                        $d = $x * $x + $y * $y + $z * $z;
                        if($d <= $outer && (!$hollow || $d >= $inner)){
                            yield [$center->getFloorX() + $x, $center->getFloorY() + $y, $center->getFloorZ() + $z];
                        }
                    }
                }
            }
        })();
        $this->applyEdit($player, $player->getWorld(), $positions, static fn() => clone $block, $estimated, $hollow ? '/hsphere' : '/sphere');
    }

    /** @param string[] $args */
    private function cmdCylinder(CommandSender $sender, array $args, bool $hollow) : void{
        if(($player = $this->player($sender)) === null){ return; }
        if(!$this->requireShapePermission($player)){ return; }
        $block = $this->parseBlock($args[0] ?? '');
        $radius = (int) ($args[1] ?? 0);
        $height = (int) ($args[2] ?? 0);
        if($block === null || $radius < 1 || $radius > 100 || $height < 1 || $height > 384){
            $player->sendMessage('§cИспользование: //' . ($hollow ? 'hcyl' : 'cyl') . ' <block> <radius 1..100> <height 1..384>');
            return;
        }
        $center = $player->getPosition()->floor();
        $estimated = $this->cylinderBlockCount($radius, $height, $hollow);
        $positions = (function() use ($center, $radius, $height, $hollow) : \Generator{
            $outer = $radius * $radius;
            $inner = max(0, ($radius - 1) * ($radius - 1));
            for($y = 0; $y < $height; ++$y){
                for($x = -$radius; $x <= $radius; ++$x){
                    for($z = -$radius; $z <= $radius; ++$z){
                        $d = $x * $x + $z * $z;
                        if($d <= $outer && (!$hollow || $d >= $inner)){
                            yield [$center->getFloorX() + $x, $center->getFloorY() + $y, $center->getFloorZ() + $z];
                        }
                    }
                }
            }
        })();
        $this->applyEdit($player, $player->getWorld(), $positions, static fn() => clone $block, $estimated, $hollow ? '/hcyl' : '/cyl');
    }

    /** @param string[] $args */
    private function cmdLine(CommandSender $sender, array $args) : void{
        if(($player = $this->player($sender)) === null){ return; }
        if(!$this->requireShapePermission($player)){ return; }
        $block = $this->parseBlock($args[0] ?? '');
        if($block === null){
            $player->sendMessage('§cИспользование: /line <block>');
            return;
        }
        if(($ctx = $this->selectionContext($player, false)) === null){ return; }
        [$selection, $world] = $ctx;
        $a = $selection->pos1;
        $b = $selection->pos2;
        if($a === null || $b === null){ return; }
        $steps = max(abs($b->getFloorX() - $a->getFloorX()), abs($b->getFloorY() - $a->getFloorY()), abs($b->getFloorZ() - $a->getFloorZ()));
        $positions = (function() use ($a, $b, $steps) : \Generator{
            $seen = [];
            for($i = 0; $i <= max(1, $steps); ++$i){
                $t = $steps === 0 ? 0.0 : $i / $steps;
                $x = (int) round($a->getFloorX() + ($b->getFloorX() - $a->getFloorX()) * $t);
                $y = (int) round($a->getFloorY() + ($b->getFloorY() - $a->getFloorY()) * $t);
                $z = (int) round($a->getFloorZ() + ($b->getFloorZ() - $a->getFloorZ()) * $t);
                $key = "$x:$y:$z";
                if(!isset($seen[$key])){
                    $seen[$key] = true;
                    yield [$x, $y, $z];
                }
            }
        })();
        $this->applyEdit($player, $world, $positions, static fn() => clone $block, $steps + 1, '/line');
    }

    private function schematicName(string $name) : ?string{
        $name = strtolower(trim($name));
        return preg_match('/^[a-z0-9_-]{1,48}$/', $name) === 1 ? $name : null;
    }

    /** @param string[] $args */
    private function cmdSchematic(CommandSender $sender, array $args) : void{
        if(($player = $this->player($sender)) === null){ return; }
        $action = strtolower($args[0] ?? '');
        if($action === 'list'){
            $files = $this->schematics->listFiles($this->schematicsFolder);
            $player->sendMessage('§aСхематики: §f' . ($files === [] ? 'нет' : implode(', ', $files)));
            return;
        }

        $input = strtolower(trim($args[1] ?? ''));
        $extension = strtolower(trim($args[2] ?? ''));
        if(str_contains($input, '.')){
            $extension = strtolower(pathinfo($input, PATHINFO_EXTENSION));
            $input = pathinfo($input, PATHINFO_FILENAME);
        }
        $name = $this->schematicName($input);
        if($name === null){
            $player->sendMessage('§c/schem save <name> [abg|schem|schematic]');
            $player->sendMessage('§c/schem load|delete <name[.ext]> [ext], /schem list');
            return;
        }

        if($action === 'save'){
            $extension = $extension === '' ? 'abg' : $extension;
            if(!in_array($extension, SchematicService::EXTENSIONS, true)){
                $player->sendMessage('§cФормат: abg, schem или schematic.');
                return;
            }
            $clipboard = $this->sessions->getClipboard($player->getName());
            if($clipboard === null){
                $player->sendMessage('§cСначала используй /copy.');
                return;
            }
            try{
                $file = $this->schematicsFolder . $name . '.' . $extension;
                $this->schematics->save($file, $extension, $clipboard);
                $player->sendMessage("§aСхематика сохранена: §f{$name}.{$extension} §7(блоки и содержимое контейнеров)");
            }catch(Throwable $e){
                $player->sendMessage('§cНе удалось сохранить: ' . $e->getMessage());
            }
            return;
        }

        $candidates = [];
        if($extension !== ''){
            if(!in_array($extension, SchematicService::EXTENSIONS, true)){
                $player->sendMessage('§cФормат: abg, schem или schematic.');
                return;
            }
            $candidates[] = [$this->schematicsFolder . $name . '.' . $extension, $extension];
        }else{
            foreach(SchematicService::EXTENSIONS as $ext){
                $candidates[] = [$this->schematicsFolder . $name . '.' . $ext, $ext];
            }
        }
        $found = null;
        foreach($candidates as $candidate){
            if(is_file($candidate[0])){ $found = $candidate; break; }
        }
        if($found === null){
            $player->sendMessage('§cСхематика не найдена. Укажи расширение, если есть файлы с одинаковым именем.');
            return;
        }
        [$file, $extension] = $found;

        if($action === 'load'){
            try{
                $clipboard = $this->schematics->load($file, $extension);
                if(count($clipboard['blocks']) > min($this->maxClipboard, $this->operationLimit($player))){
                    throw new \RuntimeException('Схематика слишком большая: ' . count($clipboard['blocks']));
                }
                $this->sessions->setClipboard($player->getName(), $clipboard);
                $player->sendMessage("§aЗагружено: §f" . basename($file) . '§a. Используй /paste. Tile/NBT: ' . count($clipboard['tiles'] ?? []));
            }catch(Throwable $e){
                $player->sendMessage('§cНе удалось загрузить схематику: ' . $e->getMessage());
            }
            return;
        }

        if($action === 'delete'){
            if(@unlink($file)){
                $player->sendMessage('§aСхематика удалена: §f' . basename($file));
            }else{
                $player->sendMessage('§cНе удалось удалить файл.');
            }
            return;
        }
        $player->sendMessage('§c/schem <save|load|list|delete> ...');
    }

    private function isRegionAdmin(Player $player) : bool{
        return $player->hasPermission('amybg.region.admin');
    }

    private function canManageRegion(Player $player, Region $region) : bool{
        return $this->isRegionAdmin($player) || $region->isOwner($player->getName());
    }

    private function selectionArea(Selection $selection) : int{
        [$minX, , $minZ, $maxX, , $maxZ] = $selection->bounds();
        return ($maxX - $minX + 1) * ($maxZ - $minZ + 1);
    }

    /** @return array<string,string> */
    private function defaultRegionFlags() : array{
        $flags = self::DEFAULT_REGION_FLAGS;
        $configured = $this->getConfig()->getNested('worldguard.default-region-flags', []);
        if(is_array($configured)){
            foreach($configured as $flag => $value){
                if(is_string($flag) && is_string($value) && in_array($flag, self::BOOLEAN_FLAGS, true) && in_array($value, ['allow', 'deny'], true)){
                    $flags[$flag] = $value;
                }
            }
        }
        return $flags;
    }

    private function createOrRedefineRegion(Player $player, string $rawName, bool $redefine = false) : ?Region{
        $name = $this->regions->normalizeName($rawName);
        if($name === null){
            $player->sendMessage('§cИмя: 1–32 символа, русские/латинские буквы, цифры, _ и -.');
            return null;
        }
        if(($ctx = $this->selectionContext($player, false)) === null){
            return null;
        }
        [$selection, $world, $bounds] = $ctx;
        $existing = $this->regions->get($name);
        if(!$redefine && $existing !== null){
            $player->sendMessage('§cРегион с таким именем уже существует.');
            return null;
        }
        if($redefine && $existing === null){
            $player->sendMessage('§cРегион не найден.');
            return null;
        }
        if($existing !== null && !$this->canManageRegion($player, $existing)){
            $player->sendMessage('§cТы не владелец этого региона.');
            return null;
        }

        $area = $this->selectionArea($selection);
        $maxArea = max(1, (int) $this->getConfig()->getNested('worldguard.max-region-area', 50000));
        if(!$this->isRegionAdmin($player) && $area > $maxArea){
            $player->sendMessage("§cПлощадь региона {$area}. Максимум: {$maxArea} блоков.");
            return null;
        }
        $maxRegions = max(1, (int) $this->getConfig()->getNested('worldguard.max-regions-per-player', 10));
        if(!$redefine && !$this->isRegionAdmin($player) && count($this->regions->ownedBy($player->getName())) >= $maxRegions){
            $player->sendMessage("§cДостигнут лимит регионов: {$maxRegions}.");
            return null;
        }

        [$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = $bounds;
        $clans=$this->getServer()->getPluginManager()->getPlugin('AmyXClans');
        if($clans!==null && $clans->isEnabled() && method_exists($clans,'overlapsClanRegion') && $clans->overlapsClanRegion($world->getFolderName(),$minX,$minZ,$maxX,$maxZ)){
            $player->sendMessage('§5Выделение пересекается с клановым регионом.');return null;
        }
        $overlaps = $this->regions->overlapping2D($world->getFolderName(), $minX, $minZ, $maxX, $maxZ, $redefine ? $name : null);
        $allowOverlap = (bool) $this->getConfig()->getNested('worldguard.allow-overlap', false);
        if(!$allowOverlap && !$this->isRegionAdmin($player) && $overlaps !== []){
            $player->sendMessage('§cВыделение пересекается с регионом: §f' . $overlaps[0]->name);
            return null;
        }

        $fullHeight = (bool) $this->getConfig()->getNested('worldguard.full-height-regions', true);
        if($fullHeight){
            $minY = $world->getMinY();
            $maxY = $world->getMaxY() - 1;
        }
        $region = new Region(
            $name,
            $world->getFolderName(),
            $minX, $minY, $minZ,
            $maxX, $maxY, $maxZ,
            $existing?->owners ?? [strtolower($player->getName())],
            $existing?->members ?? [],
            $existing?->priority ?? 0,
            $existing?->flags ?? $this->defaultRegionFlags(),
            $fullHeight
        );
        $this->regions->add($region);
        $player->sendMessage('§aРегион ' . ($redefine ? 'переопределён' : 'создан') . ": §f{$name}§a. Площадь: §f{$area}§a. Высота: §f" . ($fullHeight ? 'весь мир' : 'по выделению'));
        return $region;
    }

    private function openRegionMainMenu(Player $player) : void{
        $here = $this->regions->highestAt(
            $player->getWorld()->getFolderName(),
            $player->getPosition()->getFloorX(),
            $player->getPosition()->getFloorY(),
            $player->getPosition()->getFloorZ()
        );
        $owned = count($this->regions->ownedBy($player->getName()));
        $max = (int) $this->getConfig()->getNested('worldguard.max-regions-per-player', 10);
        $form = new SimpleForm('Управление регионами', "Твои регионы: §f{$owned} / {$max}\nРегион здесь: §f" . ($here?->name ?? 'нет'), function(Player $p, ?int $choice) use($here) : void{
            if($choice === null){ return; }
            switch($choice){
                case 0: $this->openCreateRegionForm($p); break;
                case 1: $this->openRegionListForm($p, false); break;
                case 2:
                    $current = $this->regions->highestAt($p->getWorld()->getFolderName(), $p->getPosition()->getFloorX(), $p->getPosition()->getFloorY(), $p->getPosition()->getFloorZ());
                    if($current === null){ $p->sendMessage('§cВ этой точке нет региона.'); }
                    elseif(!$this->canManageRegion($p, $current)){ $p->sendMessage('§cТы не владелец этого региона.'); }
                    else{ $this->openRegionMenu($p, $current); }
                    break;
                case 3:
                    if($this->isRegionAdmin($p)){ $this->openRegionListForm($p, true); }
                    else{ $this->openRegionHelp($p); }
                    break;
                case 4: $this->openRegionHelp($p); break;
            }
        });
        $form->addButton('Создать регион');
        $form->addButton('Мои регионы');
        $form->addButton('Регион в этой точке');
        if($this->isRegionAdmin($player)){
            $form->addButton('Все регионы');
        }
        $form->addButton('Помощь');
        $player->sendForm($form);
    }

    private function openCreateRegionForm(Player $player) : void{
        $selection = $this->sessions->getSelection($player->getName());
        if(!$selection->isComplete()){
            $player->sendMessage('§cСначала выдели две точки топором: /wand.');
            return;
        }
        $area = $this->selectionArea($selection);
        $form = new CustomForm('Создание региона', function(Player $p, ?array $data) : void{
            if($data === null){ return; }
            $name = trim((string) ($data[1] ?? ''));
            $region = $this->createOrRedefineRegion($p, $name, false);
            if($region !== null){ $this->openRegionMenu($p, $region); }
        });
        $form->addLabel("Площадь: §c{$area} блоков§r\nРегион защищает всю высоту мира.\nПосле создания ломать и ставить смогут только владелец и участники.");
        $form->addInput('Название региона', 'Например: home');
        $player->sendForm($form);
    }

    private function openRegionListForm(Player $player, bool $all) : void{
        $regions = $this->regions->manageableBy($player->getName(), $all && $this->isRegionAdmin($player));
        $form = new SimpleForm($all ? 'Все регионы' : 'Мои регионы', 'Выбери регион:', function(Player $p, ?int $choice) use($regions) : void{
            if($choice === null){ return; }
            if(isset($regions[$choice])){
                $current = $this->regions->get($regions[$choice]->name);
                if($current !== null && $this->canManageRegion($p, $current)){
                    $this->openRegionMenu($p, $current);
                }
                return;
            }
            $this->openRegionMainMenu($p);
        });
        foreach($regions as $region){
            $area = ($region->maxX - $region->minX + 1) * ($region->maxZ - $region->minZ + 1);
            $form->addButton("§l{$region->name}§r\n§7{$region->world} • {$area} блоков");
        }
        $form->addButton('Назад');
        $player->sendForm($form);
    }

    private function openRegionMenu(Player $player, Region $region) : void{
        if(!$this->canManageRegion($player, $region)){
            $player->sendMessage('§cНет доступа к управлению этим регионом.');
            return;
        }
        $area = ($region->maxX - $region->minX + 1) * ($region->maxZ - $region->minZ + 1);
        $content = "Владелец: §f" . ($region->owners[0] ?? '-') . "\nПлощадь: §f{$area} блоков\nУчастников: §f" . count($region->members) . "\nМир: §f{$region->world}";
        $form = new SimpleForm("Регион: {$region->name}", $content, function(Player $p, ?int $choice) use($region) : void{
            if($choice === null){ return; }
            $current = $this->regions->get($region->name);
            if($current === null){ $p->sendMessage('§cРегион уже удалён.'); return; }
            switch($choice){
                case 0: $this->openRegionInfo($p, $current); break;
                case 1: $this->openMembersMenu($p, $current); break;
                case 2: $this->openFlagCategoryMenu($p, $current); break;
                case 3: $this->openTextFlagsForm($p, $current); break;
                case 4:
                    $selection = $this->sessions->getSelection($p->getName());
                    $selection->setPos1(new Vector3($current->minX, $current->minY, $current->minZ), $current->world);
                    $selection->setPos2(new Vector3($current->maxX, $current->maxY, $current->maxZ), $current->world);
                    $p->sendMessage('§aГраницы региона загружены в выделение.');
                    break;
                case 5: $this->teleportToRegion($p, $current); break;
                case 6: $this->openDeleteRegionForm($p, $current); break;
                case 7: $this->openRegionMainMenu($p); break;
            }
        });
        $form->addButton('Информация');
        $form->addButton('Участники');
        $form->addButton('Флаги защиты');
        $form->addButton('Сообщения региона');
        $form->addButton('Выделить регион');
        $form->addButton('Телепортироваться');
        $form->addButton('§cУдалить регион');
        $form->addButton('Назад');
        $player->sendForm($form);
    }

    private function openRegionInfo(Player $player, Region $region) : void{
        $area = ($region->maxX - $region->minX + 1) * ($region->maxZ - $region->minZ + 1);
        $content = "Название: §f{$region->name}\nМир: §f{$region->world}\nПлощадь: §f{$area}\nВысота: §f" . ($region->fullHeight ? 'весь мир' : "{$region->minY}..{$region->maxY}") . "\nГраницы: §f{$region->minX}, {$region->minZ} → {$region->maxX}, {$region->maxZ}\nВладельцы: §f" . implode(', ', $region->owners) . "\nУчастники: §f" . ($region->members === [] ? 'нет' : implode(', ', $region->members));
        $form = new SimpleForm('Информация: ' . $region->name, $content, function(Player $p, ?int $choice) use($region) : void{
            if($choice !== null){
                $current = $this->regions->get($region->name);
                if($current !== null){ $this->openRegionMenu($p, $current); }
            }
        });
        $form->addButton('Назад');
        $player->sendForm($form);
    }

    private function openMembersMenu(Player $player, Region $region) : void{
        $max = max(1, (int) $this->getConfig()->getNested('worldguard.max-members-per-region', 30));
        $content = 'Всего участников: §f' . count($region->members) . " / {$max}\n" . ($region->members === [] ? 'Участников пока нет.' : implode(', ', $region->members));
        $form = new SimpleForm('Участники: ' . $region->name, $content, function(Player $p, ?int $choice) use($region) : void{
            if($choice === null){ return; }
            $current = $this->regions->get($region->name);
            if($current === null){ return; }
            if($choice === 0){ $this->openAddMemberForm($p, $current); }
            elseif($choice === 1){ $this->openRemoveMemberForm($p, $current); }
            else{ $this->openRegionMenu($p, $current); }
        });
        $form->addButton('Пригласить игрока');
        $form->addButton('Удалить участника');
        $form->addButton('Назад');
        $player->sendForm($form);
    }

    private function openAddMemberForm(Player $player, Region $region) : void{
        $form = new CustomForm('Добавить участника', function(Player $p, ?array $data) use($region) : void{
            if($data === null){ return; }
            $current = $this->regions->get($region->name);
            if($current === null || !$this->canManageRegion($p, $current)){ return; }
            $name = strtolower(trim((string) ($data[1] ?? '')));
            if($name === ''){ $p->sendMessage('§cУкажи ник.'); return; }
            $max = max(1, (int) $this->getConfig()->getNested('worldguard.max-members-per-region', 30));
            if(count($current->members) >= $max){ $p->sendMessage("§cЛимит участников: {$max}."); return; }
            if(!$current->isMember($name)){ $current->members[] = $name; }
            $current->members = array_values(array_unique(array_map('strtolower', $current->members)));
            $this->regions->save();
            $p->sendMessage("§aИгрок §f{$name} §aдобавлен в регион §f{$current->name}§a.");
            $this->openMembersMenu($p, $current);
        });
        $form->addLabel('Участник сможет ломать, ставить и использовать блоки внутри региона.');
        $form->addInput('Ник игрока', 'PlayerName');
        $player->sendForm($form);
    }

    private function openRemoveMemberForm(Player $player, Region $region) : void{
        $members = array_values($region->members);
        $form = new SimpleForm('Удалить участника', $members === [] ? 'Список пуст.' : 'Выбери игрока:', function(Player $p, ?int $choice) use($region, $members) : void{
            if($choice === null){ return; }
            $current = $this->regions->get($region->name);
            if($current === null || !$this->canManageRegion($p, $current)){ return; }
            if(isset($members[$choice])){
                $target = $members[$choice];
                $current->members = array_values(array_filter($current->members, static fn(string $v) : bool => strtolower($v) !== strtolower($target)));
                $this->regions->save();
                $p->sendMessage("§aИгрок §f{$target} §aудалён из региона.");
            }
            $this->openMembersMenu($p, $current);
        });
        foreach($members as $member){ $form->addButton($member); }
        $form->addButton('Назад');
        $player->sendForm($form);
    }

    private function openFlagCategoryMenu(Player $player, Region $region) : void{
        $keys = array_keys(self::FLAG_CATEGORIES);
        $form = new SimpleForm('Флаги: ' . $region->name, 'Зелёный ползунок = разрешено. Серый = запрещено.\nИзменения применяются сразу после отправки формы.', function(Player $p, ?int $choice) use($region, $keys) : void{
            if($choice === null){ return; }
            $current = $this->regions->get($region->name);
            if($current === null){ return; }
            if(isset($keys[$choice])){ $this->openFlagForm($p, $current, $keys[$choice]); }
            else{ $this->openRegionMenu($p, $current); }
        });
        foreach($keys as $key){ $form->addButton(self::FLAG_CATEGORIES[$key]['title']); }
        $form->addButton('Назад');
        $player->sendForm($form);
    }

    private function effectiveFlagAllowed(Region $region, string $flag) : bool{
        $value = $region->getFlag($flag);
        if($value !== null){ return $value === 'allow'; }
        if(in_array($flag, ['build', 'break', 'place', 'interact', 'container', 'door-use', 'trapdoor-use', 'fence-gate-use', 'button-use', 'lever-use', 'entity-interact'], true)){
            return false;
        }
        if(in_array($flag, ['invincible', 'keep-inventory', 'keep-experience'], true)){
            return false;
        }
        return true;
    }

    private function openFlagForm(Player $player, Region $region, string $category) : void{
        $definition = self::FLAG_CATEGORIES[$category] ?? null;
        if($definition === null){ return; }
        $flags = array_keys($definition['flags']);
        $form = new CustomForm($definition['title'] . ': ' . $region->name, function(Player $p, ?array $data) use($region, $category, $flags) : void{
            if($data === null){ return; }
            $current = $this->regions->get($region->name);
            if($current === null || !$this->canManageRegion($p, $current)){ return; }
            foreach($flags as $i => $flag){
                $current->flags[$flag] = (bool) ($data[$i + 1] ?? false) ? 'allow' : 'deny';
            }
            $this->regions->save();
            $p->sendMessage('§aФлаги региона сохранены.');
            $this->openFlagCategoryMenu($p, $current);
        });
        $form->addLabel('Зелёный = разрешить, серый = запретить. Владельцы и участники обходят строительные запреты.');
        foreach($definition['flags'] as $flag => $label){
            $form->addToggle($label, $this->effectiveFlagAllowed($region, $flag));
        }
        $player->sendForm($form);
    }

    private function openTextFlagsForm(Player $player, Region $region) : void{
        $form = new CustomForm('Сообщения: ' . $region->name, function(Player $p, ?array $data) use($region) : void{
            if($data === null){ return; }
            $current = $this->regions->get($region->name);
            if($current === null || !$this->canManageRegion($p, $current)){ return; }
            foreach(['greeting' => 1, 'farewell' => 2, 'deny-message' => 3] as $flag => $index){
                $value = trim((string) ($data[$index] ?? ''));
                if($value === ''){ unset($current->flags[$flag]); }
                else{ $current->flags[$flag] = $value; }
            }
            $this->regions->save();
            $p->sendMessage('§aСообщения региона сохранены.');
            $this->openRegionMenu($p, $current);
        });
        $form->addLabel('Поддерживаются цветовые коды § и переменная {region}. Пустое поле отключает сообщение.');
        $form->addInput('Приветствие при входе', '§aДобро пожаловать в {region}', $region->getRawFlag('greeting') ?? '');
        $form->addInput('Сообщение при выходе', '§eТы покинул {region}', $region->getRawFlag('farewell') ?? '');
        $form->addInput('Сообщение запрета', '§cЗдесь это запрещено', $region->getRawFlag('deny-message') ?? '');
        $player->sendForm($form);
    }

    private function openDeleteRegionForm(Player $player, Region $region) : void{
        $form = new SimpleForm('Удаление региона', "Точно удалить регион §c{$region->name}§r? Отменить это действие нельзя.", function(Player $p, ?int $choice) use($region) : void{
            if($choice === 0){
                $current = $this->regions->get($region->name);
                if($current !== null && $this->canManageRegion($p, $current)){
                    $this->regions->remove($current->name);
                    $p->sendMessage("§aРегион §f{$current->name} §aудалён.");
                }
                $this->openRegionMainMenu($p);
            }elseif($choice !== null){
                $current = $this->regions->get($region->name);
                if($current !== null){ $this->openRegionMenu($p, $current); }
            }
        });
        $form->addButton('§cУдалить');
        $form->addButton('Отмена');
        $player->sendForm($form);
    }

    private function teleportToRegion(Player $player, Region $region) : void{
        $world = $this->getServer()->getWorldManager()->getWorldByName($region->world);
        if($world === null){ $player->sendMessage('§cМир региона не загружен.'); return; }
        $x = ($region->minX + $region->maxX) / 2 + 0.5;
        $z = ($region->minZ + $region->maxZ) / 2 + 0.5;
        $y = $world->getHighestBlockAt((int) floor($x), (int) floor($z));
        $y = $y === null ? $world->getSpawnLocation()->getY() : $y + 1;
        $player->teleport(new Position($x, $y, $z, $world));
    }

    private function openRegionHelp(Player $player) : void{
        $form = new SimpleForm('Помощь по регионам', "1. Получи топор: /wand\n2. ЛКМ — первая точка, ПКМ — вторая.\n3. Напиши /rg create и введи название.\n4. /rg открывает меню.\n\nРегионы по умолчанию защищают всю высоту мира. Обычный игрок не может ломать, ставить, открывать сундуки и использовать двери.", function(Player $p, ?int $choice) : void{
            if($choice !== null){ $this->openRegionMainMenu($p); }
        });
        $form->addButton('Назад');
        $player->sendForm($form);
    }

    /** @param string[] $args */
    private function cmdRegion(CommandSender $sender, array $args) : void{
        $sub = strtolower($args[0] ?? 'menu');
        if(!$sender instanceof Player){
            if($sub === 'save'){ $this->regions->save(); $sender->sendMessage('Регионы сохранены.'); return; }
            if($sub === 'reload'){ $this->regions->load(); $sender->sendMessage('Регионы перезагружены.'); return; }
            $sender->sendMessage('/rg save|reload|list');
            return;
        }
        $player = $sender;

        if(in_array($sub, ['menu', 'gui', 'open'], true)){
            $this->openRegionMainMenu($player);
            return;
        }
        if($sub === 'help'){
            $this->openRegionHelp($player);
            return;
        }
        if($sub === 'create' && !isset($args[1])){
            $this->openCreateRegionForm($player);
            return;
        }
        if($sub === 'list'){
            $this->openRegionListForm($player, $this->isRegionAdmin($player) && strtolower($args[1] ?? '') === 'all');
            return;
        }
        if($sub === 'here'){
            $region = $this->regions->highestAt($player->getWorld()->getFolderName(), $player->getPosition()->getFloorX(), $player->getPosition()->getFloorY(), $player->getPosition()->getFloorZ());
            if($region === null){ $player->sendMessage('§cВ этой точке нет региона.'); }
            elseif($this->canManageRegion($player, $region)){ $this->openRegionMenu($player, $region); }
            else{ $player->sendMessage("§eРегион: §f{$region->name}§e. Владелец: §f" . ($region->owners[0] ?? '-')); }
            return;
        }
        if($sub === 'flaglist'){
            $player->sendMessage('§dЛогические флаги: §f' . implode(', ', self::BOOLEAN_FLAGS));
            $player->sendMessage('§dТекстовые флаги: §f' . implode(', ', self::TEXT_FLAGS));
            return;
        }
        if(in_array($sub, ['save', 'reload'], true)){
            if(!$this->isRegionAdmin($player)){ $player->sendMessage('§cТолько для администратора.'); return; }
            if($sub === 'save'){ $this->regions->save(); }else{ $this->regions->load(); }
            $player->sendMessage('§aГотово.');
            return;
        }

        $name = $this->regions->normalizeName($args[1] ?? '');
        if($name === null){
            $player->sendMessage('§cУкажи имя региона или открой меню командой /rg.');
            return;
        }

        if(in_array($sub, ['create', 'redefine'], true)){
            $region = $this->createOrRedefineRegion($player, $name, $sub === 'redefine');
            if($region !== null){ $this->openRegionMenu($player, $region); }
            return;
        }

        $region = $this->regions->get($name);
        if($region === null){ $player->sendMessage('§cРегион не найден.'); return; }
        if(!$this->canManageRegion($player, $region)){
            $player->sendMessage('§cТы не владелец этого региона.');
            return;
        }

        switch($sub){
            case 'menu':
            case 'info':
                $this->openRegionMenu($player, $region);
                break;
            case 'flags':
                $this->openFlagCategoryMenu($player, $region);
                break;
            case 'members':
                $this->openMembersMenu($player, $region);
                break;
            case 'delete':
                $this->openDeleteRegionForm($player, $region);
                break;
            case 'select':
                $selection = $this->sessions->getSelection($player->getName());
                $selection->setPos1(new Vector3($region->minX, $region->minY, $region->minZ), $region->world);
                $selection->setPos2(new Vector3($region->maxX, $region->maxY, $region->maxZ), $region->world);
                $player->sendMessage('§aРегион загружен в выделение.');
                break;
            case 'tp':
                $this->teleportToRegion($player, $region);
                break;
            case 'priority':
                if(!$this->isRegionAdmin($player) || !isset($args[2]) || !is_numeric($args[2])){
                    $player->sendMessage('§c/rg priority <region> <number> — только администратор.');
                    break;
                }
                $region->priority = (int) $args[2];
                $this->regions->save();
                $player->sendMessage("§aПриоритет: §f{$region->priority}");
                break;
            case 'flag':
                $flag = strtolower($args[2] ?? '');
                if(in_array($flag, self::TEXT_FLAGS, true)){
                    $value = trim(implode(' ', array_slice($args, 3)));
                    if($value === '' || strtolower($value) === 'none'){ unset($region->flags[$flag]); }
                    else{ $region->flags[$flag] = $value; }
                    $this->regions->save();
                    $player->sendMessage("§aФлаг {$flag} сохранён.");
                    break;
                }
                $value = strtolower($args[3] ?? '');
                if(!in_array($flag, self::BOOLEAN_FLAGS, true) || !in_array($value, ['allow', 'deny', 'none'], true)){
                    $player->sendMessage('§c/rg flag <region> <flag> <allow|deny|none>; /rg flaglist');
                    break;
                }
                if($value === 'none'){ unset($region->flags[$flag]); }else{ $region->flags[$flag] = $value; }
                $this->regions->save();
                $player->sendMessage("§aФлаг {$flag}: §f{$value}");
                break;
            case 'addowner':
            case 'removeowner':
            case 'addmember':
            case 'removemember':
                $target = strtolower(trim($args[2] ?? ''));
                if($target === ''){ $player->sendMessage("§c/rg {$sub} <region> <player>"); break; }
                if(in_array($sub, ['addowner', 'removeowner'], true) && !$this->isRegionAdmin($player)){
                    $player->sendMessage('§cВладельцев меняет только администратор.');
                    break;
                }
                if($sub === 'addowner' && !in_array($target, $region->owners, true)){ $region->owners[] = $target; }
                elseif($sub === 'removeowner'){ $region->owners = array_values(array_filter($region->owners, static fn(string $v) : bool => $v !== $target)); }
                elseif($sub === 'addmember' && !in_array($target, $region->members, true)){ $region->members[] = $target; }
                elseif($sub === 'removemember'){ $region->members = array_values(array_filter($region->members, static fn(string $v) : bool => $v !== $target)); }
                $this->regions->save();
                $player->sendMessage('§aСписок региона обновлён.');
                break;
            default:
                $player->sendMessage('§cНеизвестная команда. Используй /rg.');
        }
    }

    private function isWand(Item $item) : bool{
        return $item->getNamedTag()->getByte('amybg_wand', 0) === 1;
    }

    public function hasBypass(Player $player) : bool{
        if((bool) $this->getConfig()->getNested('worldguard.operators-bypass', false) && $this->getServer()->isOp($player->getName())){
            return true;
        }
        return $player->hasPermission('amybg.bypass');
    }

    public function actionAllowed(Player $player, string $action, string $world, int $x, int $y, int $z, bool $checkClan = true) : bool{
        // WorldEdit performs direct world writes, so ordinary block events are not sufficient.
        $clans=$this->getServer()->getPluginManager()->getPlugin('AmyXClans');
        $target=$this->getServer()->getWorldManager()->getWorldByName($world);
        if($checkClan && $clans!==null && $clans->isEnabled() && $target!==null && method_exists($clans,'canBuildAt') && in_array($action,['break','place','build','container','interact','entity-interact'],true)){
            if(!$clans->canBuildAt($player,new \pocketmine\world\Position($x,$y,$z,$target),match($action){'container'=>'containers','entity-interact'=>'entities',default=>$action})) return false;
        }
        if($this->hasBypass($player)){
            return true;
        }
        $regions = $this->regions->at($world, $x, $y, $z);
        if($regions === []){
            return true;
        }
        $topPriority = $regions[0]->priority;
        $top = array_values(array_filter($regions, static fn(Region $r) : bool => $r->priority === $topPriority));
        $decision = null;
        foreach($top as $region){
            $flag = $region->getFlag($action);
            if($flag === null && in_array($action, ['break', 'place'], true)){
                $flag = $region->getFlag('build');
            }
            if($flag === null && in_array($action, ['door-use', 'trapdoor-use', 'fence-gate-use', 'button-use', 'lever-use'], true)){
                $flag = $region->getFlag('interact');
            }
            if($flag === null && $action === 'container'){
                $flag = $region->getFlag('interact');
            }

            // PvP is a global region rule. Owners don't bypass it automatically.
            if($action === 'pvp'){
                if($flag === 'deny'){ return false; }
                if($flag === 'allow'){ $decision = true; }
                continue;
            }

            // Owners and members bypass only construction and block-use restrictions.
            // Gameplay flags such as PvP, flight, entry, damage and chat still apply to everyone.
            $memberBypassActions = [
                'build', 'break', 'place', 'interact', 'container', 'door-use', 'trapdoor-use',
                'fence-gate-use', 'button-use', 'lever-use', 'entity-interact', 'sign-edit',
                'bucket-fill', 'bucket-empty'
            ];
            if($region->isMember($player->getName()) && in_array($action, $memberBypassActions, true)){
                continue;
            }
            if($flag === 'deny'){
                return false;
            }
            if($flag === 'allow'){
                $decision = true;
                continue;
            }

            if(in_array($action, ['build', 'break', 'place'], true) && (bool) $this->getConfig()->getNested('worldguard.default-build-deny', true)){
                return false;
            }
            if(in_array($action, ['interact', 'container', 'door-use', 'trapdoor-use', 'fence-gate-use', 'button-use', 'lever-use', 'entity-interact'], true)
                && (bool) $this->getConfig()->getNested('worldguard.default-interact-deny', true)){
                return false;
            }
        }
        return $decision ?? true;
    }

    public function getRegionManager() : RegionManager{
        return $this->regions;
    }

    public function flagStateAt(string $world, int $x, int $y, int $z, string $flag) : ?string{
        $regions = $this->regions->at($world, $x, $y, $z);
        if($regions === []){
            return null;
        }
        $priority = $regions[0]->priority;
        $decision = null;
        foreach($regions as $region){
            if($region->priority !== $priority){
                break;
            }
            $value = $region->getFlag($flag);
            if($value === 'deny'){
                return 'deny';
            }
            if($value === 'allow'){
                $decision = 'allow';
            }
        }
        return $decision;
    }

    public function deny(Player $player, string $world, int $x, int $y, int $z) : void{
        $region = $this->regions->highestAt($world, $x, $y, $z);
        $message = $region?->getRawFlag('deny-message') ?? (string) $this->getConfig()->getNested('worldguard.denial-message', '§cДействие запрещено в регионе §f{name}§c.');
        $player->sendMessage(str_replace(['{name}', '{region}'], [$region?->name ?? '?', $region?->name ?? '?'], $message));
    }

    private function interactionFlag(Block $block) : string{
        $name = strtolower($block->getName());
        if(str_contains($name, 'trapdoor')){ return 'trapdoor-use'; }
        if(str_contains($name, 'fence gate')){ return 'fence-gate-use'; }
        if(str_contains($name, 'door')){ return 'door-use'; }
        if(str_contains($name, 'button')){ return 'button-use'; }
        if(str_contains($name, 'lever')){ return 'lever-use'; }
        return 'interact';
    }

    /** @priority HIGHEST */
    public function onInteract(PlayerInteractEvent $event) : void{
        $player = $event->getPlayer();
        $block = $event->getBlock();
        if($this->isInfoStick($event->getItem())){
            if($event->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK || $event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK){
                $this->selectInfoBlock($player, $block);
                $event->cancel();
            }
            return;
        }
        if($this->isWand($event->getItem())){
            $selection = $this->sessions->getSelection($player->getName());
            if($event->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK){
                $selection->setPos1($block->getPosition(), $player->getWorld()->getFolderName());
                $player->sendMessage('§aТочка 1: §f' . $block->getPosition()->getFloorX() . ' ' . $block->getPosition()->getFloorY() . ' ' . $block->getPosition()->getFloorZ());
                $event->cancel();
            }elseif($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK){
                $selection->setPos2($block->getPosition(), $player->getWorld()->getFolderName());
                $player->sendMessage('§aТочка 2: §f' . $block->getPosition()->getFloorX() . ' ' . $block->getPosition()->getFloorY() . ' ' . $block->getPosition()->getFloorZ());
                $event->cancel();
            }
            return;
        }
        if($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK){
            $pos = $block->getPosition();
            $worldObject = $pos->getWorld();
            $world = $worldObject->getFolderName();
            $tile = $worldObject->getTileAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
            $flag = $tile instanceof Container ? 'container' : $this->interactionFlag($block);
            if(!$this->actionAllowed($player, $flag, $world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), false)){
                $event->cancel();
                $this->deny($player, $world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
            }
        }
    }

    /** @priority HIGHEST */
    public function onEntityInteract(PlayerEntityInteractEvent $event) : void{
        $entity = $event->getEntity();
        $pos = $entity->getPosition();
        $player = $event->getPlayer();
        if(!$this->actionAllowed($player, 'entity-interact', $entity->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
            $event->cancel();
            $this->deny($player, $entity->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
        }
    }

    private function toggleAllowed(Player $player, string $flag) : bool{
        $pos = $player->getPosition();
        if($this->actionAllowed($player, $flag, $player->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
            return true;
        }
        $this->deny($player, $player->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
        return false;
    }

    public function onToggleGlide(PlayerToggleGlideEvent $event) : void{
        if($event->isGliding() && !$this->toggleAllowed($event->getPlayer(), 'glide')){ $event->cancel(); }
    }

    public function onToggleSprint(PlayerToggleSprintEvent $event) : void{
        if($event->isSprinting() && !$this->toggleAllowed($event->getPlayer(), 'sprint')){ $event->cancel(); }
    }

    public function onToggleSneak(PlayerToggleSneakEvent $event) : void{
        if($event->isSneaking() && !$this->toggleAllowed($event->getPlayer(), 'sneak')){ $event->cancel(); }
    }

    public function onToggleSwim(PlayerToggleSwimEvent $event) : void{
        if($event->isSwimming() && !$this->toggleAllowed($event->getPlayer(), 'swim')){ $event->cancel(); }
    }

    /** @priority HIGHEST */
    public function onBreak(BlockBreakEvent $event) : void{
        $player = $event->getPlayer();
        if($this->isInfoStick($event->getItem())){
            $this->selectInfoBlock($player, $event->getBlock());
            $event->cancel();
            return;
        }
        if($this->isWand($event->getItem())){
            $pos = $event->getBlock()->getPosition();
            $this->sessions->getSelection($player->getName())->setPos1($pos, $pos->getWorld()->getFolderName());
            $event->cancel();
            return;
        }
        $pos = $event->getBlock()->getPosition();
        $world = $pos->getWorld()->getFolderName();
        if(!$this->actionAllowed($player, 'break', $world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
            $event->cancel();
            $this->deny($player, $world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
        }
    }

    /** @priority HIGHEST */
    public function onPlace(BlockPlaceEvent $event) : void{
        $player = $event->getPlayer();
        foreach($event->getTransaction()->getBlocks() as [$x, $y, $z, $block]){
            $world = $block->getPosition()->getWorld()->getFolderName();
            if(!$this->actionAllowed($player, 'place', $world, $x, $y, $z)){
                $event->cancel();
                $this->deny($player, $world, $x, $y, $z);
                return;
            }
        }
    }

    public function onDamage(EntityDamageByEntityEvent $event) : void{
        $damager = $event->getDamager();
        $victim = $event->getEntity();
        if(!$damager instanceof Player || !$victim instanceof Player){
            return;
        }
        $pos = $victim->getPosition();
        $world = $victim->getWorld()->getFolderName();
        if(!$this->actionAllowed($damager, 'pvp', $world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
            $event->cancel();
            $this->deny($damager, $world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
        }
    }

    public function onMove(PlayerMoveEvent $event) : void{
        $player = $event->getPlayer();
        if($this->hasBypass($player)){
            return;
        }
        $from = $event->getFrom();
        $to = $event->getTo();
        if($from->getFloorX() === $to->getFloorX() && $from->getFloorY() === $to->getFloorY() && $from->getFloorZ() === $to->getFloorZ() && $from->getWorld() === $to->getWorld()){
            return;
        }
        $fromWorld = $from->getWorld()->getFolderName();
        $toWorld = $to->getWorld()->getFolderName();
        $fromRegion = $this->regions->highestAt($fromWorld, $from->getFloorX(), $from->getFloorY(), $from->getFloorZ());
        $toRegion = $this->regions->highestAt($toWorld, $to->getFloorX(), $to->getFloorY(), $to->getFloorZ());
        if($toRegion !== null && $toRegion->name !== $fromRegion?->name && !$this->actionAllowed($player, 'entry', $toWorld, $to->getFloorX(), $to->getFloorY(), $to->getFloorZ())){
            $event->cancel();
            $this->deny($player, $toWorld, $to->getFloorX(), $to->getFloorY(), $to->getFloorZ());
            return;
        }
        if($fromRegion !== null && $fromRegion->name !== $toRegion?->name && !$this->actionAllowed($player, 'exit', $fromWorld, $from->getFloorX(), $from->getFloorY(), $from->getFloorZ())){
            $event->cancel();
            $this->deny($player, $fromWorld, $from->getFloorX(), $from->getFloorY(), $from->getFloorZ());
        }
    }

    public function onExplode(EntityExplodeEvent $event) : void{
        $filtered = [];
        foreach($event->getBlockList() as $block){
            $pos = $block->getPosition();
            $region = $this->regions->highestAt($pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
            if($region === null || $region->getFlag('explosion') !== 'deny'){
                $filtered[] = $block;
            }
        }
        $event->setBlockList($filtered);

        // PocketMine-MP 5 EntityExplodeEvent has no getIgnitions()/setIgnitions().
        // Filtering the block list is sufficient to protect regions from explosion damage.
    }

    public function onQuit(PlayerQuitEvent $event) : void{
        $this->sessions->remove($event->getPlayer()->getName());
        unset($this->selectedInfoBlocks[strtolower($event->getPlayer()->getName())]);
    }
}
