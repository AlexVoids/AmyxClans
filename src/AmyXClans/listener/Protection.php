<?php
declare(strict_types=1);
namespace AmyXClans\listener;
use AmyXClans\Main;
use AmyXClans\service\Rules;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\event\block\{BlockBreakEvent,BlockPlaceEvent,BlockBurnEvent,BlockSpreadEvent,BlockFormEvent,BlockItemPickupEvent,BlockExplodeEvent};
use pocketmine\event\entity\{EntityDamageEvent,EntityDamageByEntityEvent,EntityTeleportEvent,EntityItemPickupEvent,ItemDespawnEvent,EntityExplodeEvent};
use pocketmine\event\inventory\{InventoryOpenEvent,InventoryTransactionEvent};
use pocketmine\event\player\{PlayerMoveEvent,PlayerInteractEvent,PlayerDeathEvent,PlayerRespawnEvent,PlayerQuitEvent,PlayerItemHeldEvent,PlayerDropItemEvent,PlayerItemConsumeEvent};
use pocketmine\event\server\CommandEvent;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\entity\object\ItemEntity;
use pocketmine\world\Position;
use pocketmine\scheduler\ClosureTask;

final class Protection implements Listener{
    private array $activity=[];
    public function __construct(private Main $p){}
    private function protected(Position $p) : bool{return $this->p->regions->at($p)!==null || $this->p->matches->at($p)!==null;}
    /** @priority HIGHEST */
    public function break(BlockBreakEvent $e) : void{if(!$this->p->canBuildAt($e->getPlayer(),$e->getBlock()->getPosition()))$e->cancel();
        elseif($this->p->matches->at($e->getBlock()->getPosition())!==null){
            $pos=$e->getBlock()->getPosition();$tile=$pos->getWorld()->getTile($pos);
            if($tile instanceof \pocketmine\block\tile\Container)$e->cancel();
            else{$e->setDrops([]);$e->setXpDropAmount(0);}
        }}
    /** @priority HIGHEST */
    public function place(BlockPlaceEvent $e) : void{
        foreach($e->getTransaction()->getBlocks() as [$x,$y,$z,$block])if(!$this->p->canBuildAt($e->getPlayer(),new Position($x,$y,$z,$e->getBlockAgainst()->getPosition()->getWorld()))){$e->cancel();return;}
    }
    /** @priority HIGHEST */
    public function interact(PlayerInteractEvent $e) : void{
        $p=$e->getPlayer();$pos=$e->getBlock()->getPosition();
        if(!$this->p->regions->canAccess($p,$pos)){$e->cancel();return;}
        $mid=$this->p->matches->at($pos);
        if($mid!==null){
            $m=$this->p->matches->all()[$mid];$own=$this->p->matches->playerMatch($p,true);
            if($own!==$mid || $m['phase']!=='active'){$e->cancel();return;}
            if($this->p->matches->special($m,$pos)){
                $e->cancel();
                if($e->getAction()===PlayerInteractEvent::RIGHT_CLICK_BLOCK)$this->p->totems->interact($p,$pos);
            }
        }
        if($this->p->totems->marker($e->getItem())!==null)$e->cancel();
    }
    /** @priority HIGHEST */
    public function open(InventoryOpenEvent $e) : void{
        $inventory=$e->getInventory();
        if(method_exists($inventory,'getHolder')){
            $holder=$inventory->getHolder();if($holder instanceof Position){
                if(!$this->p->regions->canAccess($e->getPlayer(),$holder)){$e->cancel();return;}
                $mid=$this->p->matches->at($holder);
                if($mid!==null)$e->cancel(); // map containers are frozen, preventing snapshot/loot duplication
            }
        }
    }
    /** @priority HIGHEST */
    public function transaction(InventoryTransactionEvent $e) : void{
        foreach($e->getTransaction()->getActions() as $action){
            if($this->p->totems->marker($action->getSourceItem())!==null || $this->p->totems->marker($action->getTargetItem())!==null){$e->cancel();return;}
            if($action instanceof SlotChangeAction && method_exists($action->getInventory(),'getHolder')){
                $pos=$action->getInventory()->getHolder();if($pos instanceof Position && !$this->p->regions->canAccess($e->getTransaction()->getSource(),$pos)){$e->cancel();return;}
                if($pos instanceof Position && ($mid=$this->p->matches->at($pos))!==null){$m=$this->p->matches->all()[$mid];if($mid!==null){$e->cancel();return;}}
            }
        }
    }
    /** @priority HIGHEST */
    public function move(PlayerMoveEvent $e) : void{
        $p=$e->getPlayer();$to=$e->getTo();$mid=$this->p->matches->playerMatch($p,true);
        if($mid!==null){
            $m=$this->p->matches->all()[$mid];$r=$m['players'][$p->getUniqueId()->toString()];
            if(in_array($m['phase'],['collecting','preparing'],true)){
                $spawn=$m['arena']['spawns'][$r['side']][$r['spawn']];
                if(abs($to->x-$spawn['x'])>0.05 || abs($to->y-$spawn['y'])>0.05 || abs($to->z-$spawn['z'])>0.05)$e->cancel();return;
            }
            if(!Rules::contains($m['arena']['bounds'],$to->getWorld()->getFolderName(),$to->x,$to->z,$to->y))$e->cancel();
        }elseif($this->p->matches->at($to)!==null)$e->cancel();
        if(!$e->isCancelled() && $e->getFrom()->distanceSquared($to)>0.04)$this->activity($p);
    }
    private function activity(Player $p) : void{
        if(!$this->p->authenticated($p))return;$id=$this->p->clan->id($p->getName());if($id===null)return;
        $uuid=$p->getUniqueId()->toString();$now=time();$last=$this->activity[$uuid]??$now;$this->activity[$uuid]=$now;
        $elapsed=min(10,max(0,$now-$last));if($elapsed===0)return;
        $key=date('Y-m-d').':'.$id.':'.$uuid;
        $row=&$this->p->db->runtime['activity'][$key];$row??=['seconds'=>0,'awards'=>0];$row['seconds']+=$elapsed;
        $period=max(60,(int)$this->p->cfg('levels.activity-seconds',3600));
        $eligible=min(4,intdiv($row['seconds'],$period));
        if($eligible>$row['awards']){$row['awards']=$eligible;$this->p->clan->eventXp($id,'activity:'.$key.':'.$eligible,max(0,(int)$this->p->cfg('levels.activity-xp',50)));}
    }
    /** @priority HIGHEST */
    public function teleport(EntityTeleportEvent $e) : void{
        $p=$e->getEntity();if(!$p instanceof Player || $this->p->matches->internalTeleport)return;
        if($this->p->matches->playerMatch($p,true)!==null || $this->p->matches->at($e->getTo())!==null)$e->cancel();
    }
    /** @priority LOWEST */
    public function command(CommandEvent $e) : void{
        $p=$e->getSender();if(!$p instanceof Player || $this->p->matches->playerMatch($p,true)===null)return;
        $cmd=strtolower(explode(' ',ltrim($e->getCommand(),'/'))[0]);if(!in_array($cmd,['clan','c','clans','amyxclans:clan'],true)){$e->cancel();$this->p->message($p,'Во время битвы доступно только меню /clan.');}
    }
    /** @priority HIGHEST */
    public function gameMode(\pocketmine\event\player\PlayerGameModeChangeEvent $e) : void{
        $mid=$this->p->matches->playerMatch($e->getPlayer(),true);
        if($mid!==null && in_array($this->p->matches->all()[$mid]['phase'],['collecting','preparing','active'],true) && $e->getNewGamemode()!==\pocketmine\player\GameMode::SURVIVAL())$e->cancel();
    }
    /** @priority HIGHEST */
    public function damage(EntityDamageEvent $e) : void{
        $v=$e->getEntity();
        if($v instanceof ItemEntity && $this->p->totems->marker($v->getItem())!==null){$e->cancel();return;}
        if(!$v instanceof Player){if($this->p->matches->at($v->getPosition())!==null)$e->cancel();return;}
        $mid=$this->p->matches->playerMatch($v,true);$at=$this->p->matches->at($v->getPosition());
        $attacker=$e instanceof EntityDamageByEntityEvent?$e->getDamager():null;
        if($mid!==null){
            $m=$this->p->matches->all()[$mid];if($m['phase']!=='active'){$e->cancel();return;}
            if($attacker!==null){
                if(!$attacker instanceof Player || $this->p->matches->playerMatch($attacker,true)!==$mid || $m['players'][$attacker->getUniqueId()->toString()]['side']===$m['players'][$v->getUniqueId()->toString()]['side'])$e->cancel();
            }
        }elseif($at!==null || ($attacker instanceof Player && $this->p->matches->playerMatch($attacker,true)!==null)){$e->cancel();return;}
        if($attacker instanceof Player){$id=$this->p->clan->id($v->getName());if($id!==null && $id===$this->p->clan->id($attacker->getName()) && !$this->p->db->clans[$id]['pvp'])$e->cancel();}
    }
    /** @priority HIGHEST */
    public function death(PlayerDeathEvent $e) : void{
        $p=$e->getPlayer();$mid=$this->p->matches->playerMatch($p,true);$cause=$p->getLastDamageCause();$killer=$cause instanceof EntityDamageByEntityEvent?$cause->getDamager():null;if(!$killer instanceof Player)$killer=null;
        if($mid!==null){$e->setKeepInventory(false);$e->setKeepXp(false);$e->setDrops(array_values(array_filter($p->getDrops(),fn($i)=>$this->p->totems->marker($i)===null)));$this->p->matches->death($p,$killer);}
        $id=$this->p->clan->id($p->getName());$name=mb_strtolower($p->getName());if($id!==null){++$this->p->db->clans[$id]['deaths'];++$this->p->db->clans[$id]['members'][$name]['deaths'];}
        if($killer!==null && $killer!==$p){$kid=$this->p->clan->id($killer->getName());if($kid!==null && $kid!==$id){++$this->p->db->clans[$kid]['kills'];++$this->p->db->clans[$kid]['members'][mb_strtolower($killer->getName())]['kills'];}}
        $this->p->db->save();
    }
    /** @priority HIGHEST */
    public function respawn(PlayerRespawnEvent $e) : void{
        $p=$e->getPlayer();$ticket=$this->p->db->runtime['returns'][$p->getUniqueId()->toString()]??null;if($ticket===null)return;
        $e->setRespawnPosition($this->p->matches->returnPosition($p));
        $hasHome=isset($this->p->db->clans[$ticket['clan']]['home']);$messages=new \pocketmine\utils\Config($this->p->getDataFolder().'messages.yml',\pocketmine\utils\Config::YAML);
        $this->p->message($p,(string)$messages->get($hasHome?'defeat-home':'defeat-spawn','Вы выбыли из битвы.'));
        $this->p->getScheduler()->scheduleDelayedTask(new ClosureTask(function()use($p):void{if($p->isConnected() && $p->isAlive())$this->p->matches->returnPlayer($p);}),1);
    }
    public function quit(PlayerQuitEvent $e) : void{$this->p->matches->quit($e->getPlayer());$this->p->playtime->forget($e->getPlayer());unset($this->activity[$e->getPlayer()->getUniqueId()->toString()]);}
    /** @priority HIGHEST */
    public function held(PlayerItemHeldEvent $e) : void{
        if($this->p->totems->carrier($e->getPlayer())!==null && $e->getSlot()!==$e->getPlayer()->getInventory()->getHeldItemIndex())$this->p->totems->dropCarrier($e->getPlayer());
    }
    /** @priority HIGHEST */
    public function drop(PlayerDropItemEvent $e) : void{if($this->p->totems->marker($e->getItem())!==null){$e->cancel();$this->p->totems->dropCarrier($e->getPlayer());}}
    /** @priority HIGHEST */
    public function consume(PlayerItemConsumeEvent $e) : void{if($this->p->totems->marker($e->getItem())!==null)$e->cancel();}
    /** @priority HIGHEST */
    public function pickup(EntityItemPickupEvent $e) : void{
        $item=$e->getOrigin();$p=$e->getEntity();if(!$item instanceof ItemEntity)return;
        if($this->p->totems->marker($e->getItem())!==null){$e->cancel();if($p instanceof Player)$this->p->totems->pickupEntity($p,$item);return;}
        $mid=$this->p->matches->at($item->getPosition());if($mid!==null && (!$p instanceof Player || $this->p->matches->playerMatch($p,true)!==$mid || $this->p->matches->all()[$mid]['phase']!=='active'))$e->cancel();
    }
    /** @priority HIGHEST */
    public function hopper(BlockItemPickupEvent $e) : void{if($this->p->totems->marker($e->getItem())!==null || $this->protected($e->getBlock()->getPosition()) || $this->protected($e->getOrigin()->getPosition()))$e->cancel();}
    /** @priority HIGHEST */
    public function despawn(ItemDespawnEvent $e) : void{if($this->p->totems->marker($e->getEntity()->getItem())!==null)$e->cancel();}
    /** @priority HIGHEST */
    public function burn(BlockBurnEvent $e) : void{if($this->protected($e->getBlock()->getPosition()))$e->cancel();}
    /** @priority HIGHEST */
    public function spread(BlockSpreadEvent $e) : void{if($this->protected($e->getBlock()->getPosition()) || $this->protected($e->getSource()->getPosition()))$e->cancel();}
    /** @priority HIGHEST */
    public function form(BlockFormEvent $e) : void{if($this->protected($e->getBlock()->getPosition()))$e->cancel();}
    /** @priority HIGHEST */
    public function explode(EntityExplodeEvent $e) : void{if($this->p->matches->at($e->getPosition())!==null){$e->setBlockList([]);if(method_exists($e,'setIgnitions'))$e->setIgnitions([]);return;}$e->setBlockList(array_values(array_filter($e->getBlockList(),fn($b)=>!$this->protected($b->getPosition()))));if(method_exists($e,'getIgnitions') && method_exists($e,'setIgnitions'))$e->setIgnitions(array_values(array_filter($e->getIgnitions(),fn($b)=>!$this->protected($b->getPosition()))));}
}
