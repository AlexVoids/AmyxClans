<?php
declare(strict_types=1);
namespace AmyXClans\battle;
use AmyXClans\Main;
use AmyXClans\service\Rules;
use pocketmine\player\Player;
use pocketmine\item\{Item,VanillaItems};
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\effect\{EffectInstance,VanillaEffects};
use pocketmine\world\particle\DustParticle;
use pocketmine\color\Color;
use AmyxHologram\hologram\Hologram;

/** Objective ownership is canonical server state. Marked items cannot enter normal containers. */
final class TotemManager{
    private array $entities=[];
    private array $holograms=[];
    private array $previousSlow=[];
    public function __construct(private Main $p){}
    public function marker(Item $item) : ?array{
        $tag=$item->getNamedTag();$match=$tag->getString('AmyxClanMatch','');$owner=$tag->getString('AmyxClanOwner','');$nonce=$tag->getString('AmyxClanNonce','');
        return $match===''?null:['match'=>$match,'owner'=>$owner,'nonce'=>$nonce];
    }
    private function item(string $mid,string $side,array $state) : Item{
        $i=VanillaItems::NETHER_STAR()->setCustomName('§5§lТотем клана §r§5'.$this->p->db->clans[$this->p->matches->all()[$mid]['teams'][$side]]['name']);
        $i->setLore(['§fДоставьте в собственный специальный сундук.','§fДержите в активной руке. Смена слота — сброс.']);
        $i->getNamedTag()->setString('AmyxClanMatch',$mid)->setString('AmyxClanOwner',$side)->setString('AmyxClanNonce',$state['nonce']);return $i;
    }
    public function initialize(string $mid) : void{
        $m=&$this->p->db->runtime['matches'][$mid];foreach(['a','b'] as $side)$m['totems'][$side]=['state'=>'home','nonce'=>bin2hex(random_bytes(16)),'position'=>$m['arena']['chests'][$side],'carrier'=>null];
        $this->p->db->save();
    }
    public function interact(Player $p,\pocketmine\world\Position $position) : bool{
        $mid=$this->p->matches->at($position);if($mid===null)return false;$m=$this->p->matches->all()[$mid];
        if(!$this->p->matches->special($m,$position))return false;
        if($m['phase']!=='active' || $m['mode']!=='siege')return true;
        $uuid=$p->getUniqueId()->toString();$roster=$m['players'][$uuid]??null;if($roster===null || !$roster['alive'])return true;
        foreach($m['arena']['chests'] as $side=>$chest){
            if((int)floor($position->x)!==(int)floor($chest['x']) || (int)floor($position->y)!==(int)floor($chest['y']) || (int)floor($position->z)!==(int)floor($chest['z']))continue;
            if($side!==$roster['side']){
                if(($m['totems'][$side]['state']??'')==='home')$this->pickup($p,$mid,$side);
            }else{
                $enemy=$side==='a'?'b':'a';$state=$m['totems'][$enemy];
                if($state['state']==='carried' && $state['carrier']===$uuid && $this->validHand($p,$mid,$enemy,$state)){
                    $this->p->db->runtime['matches'][$mid]['totems'][$enemy]['state']='delivered';$this->p->db->save();
                    $this->purgePlayer($p);$this->p->matches->finish($mid,$side);
                }else $this->p->message($p,'Принесите вражеский тотем в активной руке.');
            }
            return true;
        }
        return true;
    }
    public function pickup(Player $p,string $mid,string $owner) : void{
        $m=&$this->p->db->runtime['matches'][$mid];$uuid=$p->getUniqueId()->toString();$r=$m['players'][$uuid]??null;$state=&$m['totems'][$owner];
        if($m['phase']!=='active' || $r===null || !$r['alive'] || $r['side']===$owner || !in_array($state['state'],['home','ground'],true) || $this->carrier($p)!==null)return;
        $slot=null;for($i=0;$i<9;++$i)if($p->getInventory()->getItem($i)->isNull()){$slot=$i;break;}if($slot===null){$p->sendTip('§fОсвободите один слот панели быстрого доступа.');return;}
        $state['state']='carried';$state['carrier']=$uuid;$this->p->db->save();
        $key=$mid.':'.$owner;if(isset($this->entities[$key])){$this->entities[$key]->flagForDespawn();unset($this->entities[$key]);}$this->removeHologram($key);
        $p->getInventory()->setItem($slot,$this->item($mid,$owner,$state));$p->getInventory()->setHeldItemIndex($slot);
        $this->effect($p);$this->p->message($p,'Вражеский тотем взят. Несите его к своему сундуку.');
    }
    public function carrier(Player $p) : ?array{
        $uuid=$p->getUniqueId()->toString();foreach($this->p->matches->all() as $mid=>$m)if($m['phase']==='active')foreach($m['totems'] as $side=>$s)if($s['state']==='carried' && $s['carrier']===$uuid)return ['match'=>$mid,'side'=>$side,'state'=>$s];return null;
    }
    private function validHand(Player $p,string $mid,string $side,array $s) : bool{
        $mark=$this->marker($p->getInventory()->getItemInHand());return $mark!==null && $mark['match']===$mid && $mark['owner']===$side && hash_equals($s['nonce'],$mark['nonce']);
    }
    public function dropCarrier(Player $p) : void{
        $carrier=$this->carrier($p);if($carrier===null){$this->purgePlayer($p);return;}
        $mid=$carrier['match'];$side=$carrier['side'];$m=&$this->p->db->runtime['matches'][$mid];$state=&$m['totems'][$side];
        $pos=$p->getPosition()->addVector($p->getDirectionVector()->multiply(1.2))->add(0,0.5,0);
        $world=$p->getWorld();
        if(!Rules::contains($m['arena']['bounds'],$world->getFolderName(),$pos->x,$pos->z,$pos->y)){$pos=$this->p->arenas->pos($m['arena']['chests'][$side]);$world=$pos->getWorld();}
        $state['state']='ground';$state['carrier']=null;$state['position']=['world'=>$world->getFolderName(),'x'=>$pos->x,'y'=>$pos->y,'z'=>$pos->z];
        $this->p->db->save();$this->purgePlayer($p);$this->spawnGround($mid,$side);
    }
    private function spawnGround(string $mid,string $side) : void{
        $s=$this->p->matches->all()[$mid]['totems'][$side];$pos=$this->p->arenas->pos($s['position']);
        $entity=$pos->getWorld()->dropItem($pos,$this->item($mid,$side,$s),new \pocketmine\math\Vector3(0,0,0),0);
        if($entity!==null){$entity->setDespawnDelay(-1);$this->entities[$mid.':'.$side]=$entity;}
    }
    public function pickupEntity(Player $p,ItemEntity $e) : void{
        $mark=$this->marker($e->getItem());if($mark===null)return;
        $key=$mark['match'].':'.$mark['owner'];
        if(($this->entities[$key]??null)!==$e){$e->flagForDespawn();return;}
        $m=$this->p->matches->all()[$mark['match']]??null;$s=$m['totems'][$mark['owner']]??null;
        if($s!==null && hash_equals($s['nonce'],$mark['nonce']) && $s['state']==='ground')$this->pickup($p,$mark['match'],$mark['owner']);
    }
    private function effect(Player $p) : void{
        $uuid=$p->getUniqueId()->toString();$effect=VanillaEffects::SLOWNESS();
        if(!array_key_exists($uuid,$this->previousSlow)){$old=$p->getEffects()->get($effect);$this->previousSlow[$uuid]=['effect'=>$old===null?null:clone $old,'time'=>time()];}
        $p->getEffects()->add(new EffectInstance($effect,60,max(0,(int)$this->p->cfg('war.totem-slowness',1)),false));
        // Bedrock/PM5 has no portable glowing effect: visible purple particles mark the carrier.
        $p->getWorld()->addParticle($p->getPosition()->add(0,2.1,0),new DustParticle(new Color(170,0,170)));
    }
    private function clearEffect(Player $p) : void{
        $uuid=$p->getUniqueId()->toString();$record=$this->previousSlow[$uuid]??null;if($record===null)return;unset($this->previousSlow[$uuid]);
        $current=$p->getEffects()->get(VanillaEffects::SLOWNESS());
        if($current!==null && $current->getDuration()<=60 && $current->getAmplifier()===(int)$this->p->cfg('war.totem-slowness',1))$p->getEffects()->remove(VanillaEffects::SLOWNESS());
        $old=$record['effect'];if($old!==null){$remaining=$old->getDuration()-(time()-$record['time'])*20;if($remaining>0){$old->setDuration($remaining);$p->getEffects()->add($old);}}
    }
    public function purgePlayer(Player $p) : void{
        foreach([$p->getInventory(),$p->getArmorInventory(),$p->getOffHandInventory(),$p->getCursorInventory(),$p->getCraftingGrid()] as $inventory)foreach($inventory->getContents() as $slot=>$item)if($this->marker($item)!==null)$inventory->clear($slot);
        $this->clearEffect($p);
    }
    public function tick() : void{
        foreach($this->p->matches->all() as $mid=>$m){
            if($m['phase']!=='active' || $m['mode']!=='siege')continue;
            foreach($m['totems'] as $side=>$state){
                $key=$mid.':'.$side;
                if($state['state']==='carried'){
                    $p=null;foreach($m['players'] as $uuid=>$data)if($uuid===$state['carrier']){$p=$this->p->getServer()->getPlayerExact($data['name']);break;}
                    if($p===null){$this->p->db->runtime['matches'][$mid]['totems'][$side]=array_replace($state,['state'=>'ground','carrier'=>null]);$this->p->db->save();}
                    elseif(!$this->validHand($p,$mid,$side,$state))$this->dropCarrier($p);else{$this->effect($p);$this->p->db->runtime['matches'][$mid]['totems'][$side]['position']=$this->p->arenas->position($p->getPosition());}
                }elseif($state['state']==='ground'){
                    $entity=$this->entities[$key]??null;
                    if($entity===null || $entity->isClosed() || $entity->isFlaggedForDespawn()){$this->spawnGround($mid,$side);$entity=$this->entities[$key]??null;}
                    if($entity!==null){
                        $pos=$entity->getPosition();if(!Rules::contains($m['arena']['bounds'],$pos->getWorld()->getFolderName(),$pos->x,$pos->z,$pos->y)){$entity->flagForDespawn();unset($this->entities[$key]);$this->p->db->runtime['matches'][$mid]['totems'][$side]['position']=$m['arena']['chests'][$side];}
                        else{$this->p->db->runtime['matches'][$mid]['totems'][$side]['position']=$this->p->arenas->position($pos);$this->hologram($key,$pos,'§5§lВражеский тотем\n§r§fПоднять может только противник');}
                    }
                }
            }
        }
        foreach($this->p->getServer()->getWorldManager()->getWorlds() as $w)foreach($w->getEntities() as $e)if($e instanceof ItemEntity && ($mark=$this->marker($e->getItem()))!==null && ($this->entities[$mark['match'].':'.$mark['owner']]??null)!==$e)$e->flagForDespawn();
        foreach($this->p->getServer()->getOnlinePlayers() as $p)if($this->p->authenticated($p) && $this->carrier($p)===null)$this->purgePlayer($p);
    }
    private function hologram(string $key,\pocketmine\world\Position $pos,string $text) : void{
        $h=$this->p->getServer()->getPluginManager()->getPlugin('AmyxHologram');if($h===null || !$h->isEnabled() || !method_exists($h,'getEntityManager'))return;
        $name='clan-totem-'.str_replace(':','-',$key);$manager=$h->getEntityManager();$entity=$manager->getEntities()[$name]??null;
        if($entity!==null && !$entity->isClosed() && !$entity->isFlaggedForDespawn())$entity->teleport($pos->add(0,0.8,0));
        else $manager->spawn(new Hologram($name,['world'=>$pos->getWorld()->getFolderName(),'position'=>['x'=>$pos->x,'y'=>$pos->y+0.8,'z'=>$pos->z],'lines'=>explode("\n",str_replace('\\n',"\n",$text))]));
        $this->holograms[$key]=$name;
    }
    private function removeHologram(string $key) : void{
        $h=$this->p->getServer()->getPluginManager()->getPlugin('AmyxHologram');if(isset($this->holograms[$key]) && $h!==null && $h->isEnabled())$h->getEntityManager()->despawn($this->holograms[$key]);unset($this->holograms[$key]);
    }
    public function cleanup(string $mid) : void{
        foreach(['a','b'] as $side){$key=$mid.':'.$side;if(isset($this->entities[$key])){$this->entities[$key]->flagForDespawn();unset($this->entities[$key]);}$this->removeHologram($key);}
        foreach($this->p->getServer()->getOnlinePlayers() as $p){$c=$this->carrier($p);if(($c['match']??null)===$mid || $this->p->matches->playerMatch($p)===$mid)$this->purgePlayer($p);}
    }
}
