<?php
declare(strict_types=1);
namespace AmyXClans;
use AmyXClans\storage\{Database,Migration};
use AmyXClans\service\{ClanService,Treasury,Playtime};
use AmyXClans\region\RegionService;
use AmyXClans\battle\{ArenaManager,MatchManager,TotemManager,SnapshotStore};
use AmyXClans\ui\Menus;
use AmyXClans\listener\Protection;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\command\{Command,CommandSender};
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\Position;
use pocketmine\utils\Config;

final class Main extends PluginBase implements Listener{
    use \AmyXClans\legacy\Storage;
    private static self $instance;
    public Database $db;
    public ClanService $clan;
    public Treasury $treasury;
    public Playtime $playtime;
    public RegionService $regions;
    public ArenaManager $arenas;
    public SnapshotStore $snapshots;
    public MatchManager $matches;
    public TotemManager $totems;
    public Menus $menus;
    private array $clans=[];
    private array $storageSessions=[];
    private array $chatCooldown=[];
    private array $replacements=[];
    private int $second=0;
    private bool $ready=false;
    public static function getInstance() : self{return self::$instance;}
    protected function onEnable() : void{
        self::$instance=$this;
        $this->saveDefaultConfig();
        $stream=$this->getResource('defaults.yml');
        $defaults=$stream===null?[]:yaml_parse(stream_get_contents($stream));
        if(is_resource($stream))fclose($stream);
        foreach((array)$defaults as $key=>$value) if(!$this->getConfig()->exists($key)) $this->getConfig()->set($key,$value);
        if(!(bool)$this->getConfig()->get('channels-limit-v201-migrated',false)){
            $this->getConfig()->set('max-channels',5);
            $this->getConfig()->set('channels-limit-v201-migrated',true);
        }
        $this->getConfig()->save();
        foreach(['texts.yml','arenas.yml','messages.yml','interface.yml'] as $file) if(!is_file($this->getDataFolder().$file)) $this->saveResource($file);
        $texts=new Config($this->getDataFolder().'texts.yml',Config::YAML);
        $interface=new Config($this->getDataFolder().'interface.yml',Config::YAML);
        $this->replacements=array_replace((array)$interface->get('replacements',[]),(array)$texts->get('replacements',[]));
        $this->db=new Database($this->getDataFolder());
        Migration::run($this->db,$this->getConfig()->getAll());
        $this->clans=&$this->db->clans;
        $this->clan=new ClanService($this);
        $this->playtime=new Playtime($this);
        $this->regions=new RegionService($this);
        $this->arenas=new ArenaManager($this);
        $this->snapshots=new SnapshotStore($this);
        $this->matches=new MatchManager($this);
        $this->totems=new TotemManager($this);
        $this->menus=new Menus($this);
        $this->treasury=new Treasury($this);
        $this->clan->recoverCreations();
        $this->recoverStorageBlocks();
        $this->matches->recover();
        $this->getServer()->getPluginManager()->registerEvents($this,$this);
        $this->getServer()->getPluginManager()->registerEvents(new Protection($this),$this);
        if(class_exists('pocketmine\\event\\block\\BlockExplodeEvent')) $this->getServer()->getPluginManager()->registerEvents(new \AmyXClans\listener\ModernProtection($this),$this);
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function():void{
            try{
                $this->snapshots->tick();
                if((++$this->second%20)===0){
                    $this->playtime->tick();$this->matches->tick();$this->totems->tick();
                    if($this->second%1200===0) $this->db->save();
                }
            }catch(\Throwable $e){$this->getLogger()->logException($e);$this->matches->emergencyStop();}
        }),1);
        $this->ready=true;
        $this->getLogger()->info('AmyxCraft: кланов '.count($this->db->clans).', схема 4.');
    }
    protected function onDisable() : void{
        if(!$this->ready) return;
        if(isset($this->matches)) $this->matches->shutdown();
        foreach(array_keys($this->storageSessions) as $key) $this->closeStorageSession($key,true);
        $this->db->save();
    }
    private function reloadInterface() : void{
        $texts=new Config($this->getDataFolder().'texts.yml',Config::YAML);$interface=new Config($this->getDataFolder().'interface.yml',Config::YAML);
        $this->replacements=array_replace((array)$interface->get('replacements',[]),(array)$texts->get('replacements',[]));
    }
    public function cfg(string $key,mixed $default=null) : mixed{
        $value=$this->getConfig()->getAll();foreach(explode('.',$key) as $part){if(!is_array($value) || !array_key_exists($part,$value))return $default;$value=$value[$part];}return $value;
    }
    public function authenticated(Player $player) : bool{
        $auth=$this->getServer()->getPluginManager()->getPlugin('SimpleAuth');
        return $player->isConnected() && ($auth===null || !$auth->isEnabled() || !method_exists($auth,'isAuthenticated') || $auth->isAuthenticated($player->getName()));
    }
    public function isOperator(CommandSender $sender) : bool{return !($sender instanceof Player) || $this->getServer()->isOp($sender->getName());}
    public function translateText(string $text) : string{
        $text=strtr($text,$this->replacements);
        $accent=(string)$this->cfg('style.accent','§5');$plain=(string)$this->cfg('style.text','§f');
        return strtr($text,['&5'=>$accent,'&f'=>$plain,'§c'=>$accent,'§a'=>$accent,'§e'=>$accent,'§7'=>$plain,'§8'=>$plain,'§5'=>$accent,'§f'=>$plain]);
    }
    public function message(Player $player,string $text) : void{$player->sendMessage($this->translateText((string)$this->cfg('style.prefix','§5[Кланы] §f').$text));}
    /** Canonical /clan must not be intercepted by old command bundles with their own clan stub.
     * @priority LOWEST
     */
    public function routeClanCommand(\pocketmine\event\server\CommandEvent $event) : void{
        $sender=$event->getSender();if(!$sender instanceof Player)return;
        $label=strtolower(explode(' ',trim(ltrim($event->getCommand(),'/')))[0]);
        $name=match($label){'clan','amyxclans:clan'=>'clan','clanhome','amyxclans:clanhome'=>'clanhome',default=>null};
        if($name===null)return;
        $event->cancel();$command=$this->getCommand($name);
        if($command!==null && $command->testPermission($sender))$this->onCommand($sender,$command,$name,[]);
    }
    public function onCommand(CommandSender $sender,Command $command,string $label,array $args) : bool{
        try{
            if($command->getName()==='clanadmin'){
                if($sender instanceof Player && !$this->authenticated($sender))throw new \DomainException('Сначала войдите в аккаунт.');
                if(!$this->isOperator($sender)) throw new \DomainException('Настройка доступна только OP.');
                if(($args[0]??'')==='reload'){$this->reloadConfig();$this->arenas->reload();$this->reloadInterface();$sender->sendMessage('Настройки перезагружены.');return true;}
                if(($args[0]??'')==='xp' && isset($args[1],$args[2])){
                    $id=$this->findClanId($args[1]); if($id===null) throw new \DomainException('Клан не найден.');
                    $this->clan->eventXp($id,'op:'.bin2hex(random_bytes(8)),max(0,min(10000,(int)$args[2])));return true;
                }
                if(($args[0]??'')==='setbank' && isset($args[1],$args[2])){
                    $id=$this->findClanId($args[1]);if($id===null) throw new \DomainException('Клан не найден.');$this->clan->idle($id);
                    $this->db->clans[$id]['bank']=max(0,min(2_000_000_000,(int)$args[2]));$this->clan->log($id,$sender->getName(),'установил баланс казны §5'.$args[2]);$this->db->save();return true;
                }
                if(($args[0]??'')==='money' && isset($args[1],$args[2])){
                    \Amyx\Economy\api\EconomyAPI::getInstance()->setBalance($args[1],max(0,(int)$args[2]),'amyxclans_admin_set',$sender->getName());return true;
                }
                if(($args[0]??'')==='delete' && isset($args[1])){
                    $id=$this->findClanId($args[1]);if($id===null)throw new \DomainException('Клан не найден.');$this->clan->idle($id);
                    $this->db->runtime['deletedClans'][$id]=$this->db->clans[$id];unset($this->db->clans[$id]);$this->matches->cancelRequests($id);$this->db->save();return true;
                }
                if(!$sender instanceof Player){$sender->sendMessage('/clanadmin reload | xp <id> <XP> | setbank <id> <монеты>. Арены настраиваются в игре.');return true;}
                $this->menus->admin($sender);return true;
            }
            if(!$sender instanceof Player){$sender->sendMessage('Команда доступна в игре.');return true;}
            if(!$this->authenticated($sender)) throw new \DomainException('Сначала войдите в аккаунт.');
            if($command->getName()==='clanhome'){$this->teleportClanHome($sender);return true;}
            $this->menus->main($sender);
        }catch(\DomainException $e){$sender->sendMessage($this->translateText('§5'.$e->getMessage()));}catch(\Throwable $e){$this->getLogger()->logException($e);$sender->sendMessage('§5Операция приостановлена из-за ошибки. Проверьте консоль.');}
        return true;
    }
    public function teleportClanHome(Player $player) : void{
        $id=$this->clan->require($player);
        if($this->matches->playerMatch($player)!==null)throw new \DomainException('Нельзя покинуть битву телепортом.');
        $home=$this->db->clans[$id]['home']??null;
        if($home===null)throw new \DomainException('В вашем клане отсутствует клановая точка.');
        $destination=$this->arenas->pos($home);
        if($this->matches->at($destination)!==null)throw new \DomainException('Клановая точка временно недоступна: здесь проходит битва.');
        $this->matches->teleport($player,$home);
        $this->message($player,'Вы телепортированы на клановую точку.');
    }
    public function getClanIdByPlayer(string $name) : ?string{return $this->clan->id($name);}
    public function getRawClanNameByPlayer(string $name) : ?string{$id=$this->clan->id($name);return $id===null?null:$this->clans[$id]['name'].'§r';}
    public function getClanNameByPlayer(string $name) : ?string{
        $id=$this->clan->id($name);if($id===null) return null;
        $icon=$this->cfg('show-icon-in-chat-tag',true)?$this->getClanIconSymbol($id):'';
        return ($icon===''?'':$icon.(string)$this->cfg('clan-icon-name-separator',' ')).$this->clans[$id]['name'].'§r';
    }
    public function getClanRoleIdByPlayer(string $name) : ?string{$id=$this->clan->id($name);return $id===null?null:$this->clans[$id]['members'][$this->key($name)]['role'];}
    public function getClanRoleNameByPlayer(string $name) : ?string{$id=$this->clan->id($name);return $id===null?null:$this->clan->role($id,$name)['name'];}
    public function getClanRoleByPlayer(string $name) : ?string{return $this->getClanRoleNameByPlayer($name);}
    public function awardEventXp(string $clanId,string $eventId,int $xp) : bool{return $this->clan->eventXp($clanId,$eventId,$xp);}
    public function isInClanBattle(Player $player) : bool{
        $id=$this->matches->playerMatch($player,true);return $id!==null && in_array($this->matches->all()[$id]['phase'],['collecting','preparing','active'],true);
    }
    public function overlapsClanRegion(string $world,int $minX,int $minZ,int $maxX,int $maxZ) : bool{
        $b=['world'=>$world,'minX'=>$minX,'minZ'=>$minZ,'maxX'=>$maxX,'maxZ'=>$maxZ];
        foreach($this->db->clans as $c)foreach($c['regions'] as $r)if(\AmyXClans\service\Rules::overlaps($b,$r))return true;return false;
    }
    public function canBuildAt(Player $player,Position $position,string $action='build') : bool{return $this->regions->canAccess($player,$position,$action) && $this->matches->canBuild($player,$position);}
    public function findClanId(string $name) : ?string{
        if(isset($this->clans[$name])) return $name;
        foreach($this->clans as $id=>$c) if(mb_strtolower(preg_replace('/§./u','',$c['name']))===mb_strtolower(preg_replace('/§./u','',$name))) return (string)$id;
        return null;
    }
    public function routeClanChat(\pocketmine\event\player\PlayerChatEvent $event) : void{
        if($event->isCancelled() || !str_starts_with($event->getMessage(),'#')) return;
        $event->cancel(); $p=$event->getPlayer();
        try{
            $id=$this->clan->require($p);
            if(!$this->cfg('features.chat',true))throw new \DomainException('Клановый чат отключён.');
            $raw=$event->getMessage();$minimum=1;$channel='Клан';
            if(preg_match('/^#c(?:\s|$)/iu',$raw)){$text=trim(mb_substr($raw,2));}
            else{
                $parts=preg_split('/\s+/u',mb_substr($raw,1),2);$target=mb_strtolower($parts[0]??'');$found=null;
                foreach($this->clans[$id]['channels'] as $c)if(mb_strtolower($c['name'])===$target){$found=$c;break;}
                if($found!==null){$minimum=$found['priority'];$channel=$found['name'];$text=trim($parts[1]??'');}
                elseif(count($this->clans[$id]['channels'])===1){$text=trim(mb_substr($raw,1));}
                else throw new \DomainException('Канал не найден. Используйте #c сообщение или #название сообщение.');
            }
            if($this->clan->role($id,$p->getName())['priority']<$minimum)throw new \DomainException('Недостаточный приоритет для канала.');
            if($text==='' || mb_strlen($text)>256) throw new \DomainException('Формат: #c сообщение (до 256 символов).');
            $uuid=$p->getUniqueId()->toString();
            if(microtime(true)-($this->chatCooldown[$uuid]??0)<(float)$this->cfg('chat.cooldown-seconds',2)) throw new \DomainException('Пишите немного медленнее.');
            $this->chatCooldown[$uuid]=microtime(true);
            $format=strtr((string)$this->cfg('chat.format','&5[Клан] &5({role}) &f{player}: &f{message}'),['{channel}'=>$channel,'{role}'=>$this->getClanRoleNameByPlayer($p->getName()),'{player}'=>$p->getName(),'{clan}'=>$this->clans[$id]['name'],'{icon}'=>$this->getClanIconSymbol($id),'{message}'=>\pocketmine\utils\TextFormat::clean($text)]);
            foreach($this->getServer()->getOnlinePlayers() as $online) if($this->clan->id($online->getName())===$id && $this->authenticated($online) && $this->clan->role($id,$online->getName())['priority'] >= $minimum) $online->sendMessage($this->translateText($format));
        }catch(\DomainException $e){$this->message($p,$e->getMessage());}
    }
    /** @priority HIGHEST */
    public function clanChatFallback(\pocketmine\event\player\PlayerChatEvent $event) : void{
        $chat=$this->getServer()->getPluginManager()->getPlugin('ChatPerms');
        if($chat!==null && $chat->isEnabled() && method_exists($chat,'supportsClanChannel')) return;
        $this->routeClanChat($event);
    }
    private function key(string $value) : string{return mb_strtolower($value);}
    private function saveData() : void{$this->db->save();}
    private function canTakeFromStorage(string $id,string $name) : bool{
        $c=$this->clans[$id]??null; $r=$c===null?null:$this->clan->role($id,$name);
        return $r!==null && $r['priority'] >= (int)($c['roles'][$c['storageMinRole']]['priority']??1);
    }
    public function openStorage(Player $p) : void{$id=$this->clan->require($p);$this->openStorageInventory($p,$id);}
    public function closeStorage(Player $p) : void{ $key=$this->key($p->getName());if(isset($this->storageSessions[$key])){$p->removeCurrentWindow();if(isset($this->storageSessions[$key]))$this->closeStorageSession($key,true);}}
    private function getClanIconSymbol(string $id) : string{
        $code=(string)($this->cfg('clan-icons',[])[$this->clans[$id]['icon']]['symbol']??'');
        if(preg_match('/^U\+([0-9A-F]+)$/i',$code,$m)) return mb_chr(hexdec($m[1])); return $code;
    }
    private function recoverStorageBlocks() : void{
        foreach($this->db->runtime['storageBlocks']??[] as $receipt){
            $world=$this->arenas->world($receipt['world']);
            if($world===null) throw new \RuntimeException('Недоступен мир незакрытого хранилища '.$receipt['world']);
            foreach($receipt['positions'] as [$x,$y,$z]){
                $world->loadChunk(((int)$x)>>4,((int)$z)>>4);
                $tile=$world->getTileAt((int)$x,(int)$y,(int)$z);
                if($tile instanceof \pocketmine\block\tile\Chest) $tile->getInventory()->clearAll();
                $world->setBlockAt((int)$x,(int)$y,(int)$z,\pocketmine\block\VanillaBlocks::AIR(),false);
            }
        }
        $this->db->runtime['storageBlocks']=[]; $this->db->save();
    }
}
