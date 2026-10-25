<?php
declare(strict_types=1);
namespace AmyxClansTests;
use AmyXClans\{Main};
use AmyXClans\storage\{Database,Migration};
use AmyXClans\service\Rules;
use Amyx\Economy\api\EconomyAPI;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\Position;
use pocketmine\block\VanillaBlocks as B;
use pocketmine\item\VanillaItems as I;
use pocketmine\event\player\{PlayerChatEvent,PlayerMoveEvent,PlayerItemHeldEvent};
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\inventory\transaction\action\SlotChangeAction;

final class Probe extends PluginBase{
    private int $passed=0;private array $failures=[];private array $players=[];private Main $clans;private array $ids=[];
    protected function onEnable():void{
        if(getenv('AMYXCLANS_TESTS')!=='1')return;
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn()=> $this->run()),30);
    }
    private static function ok(bool $ok,string $message='Assertion failed'):void{if(!$ok)throw new \RuntimeException($message);}
    private static function denied(callable $fn):void{try{$fn();}catch(\DomainException){return;}throw new \RuntimeException('Action unexpectedly allowed');}
    private function test(string $name,callable $fn):void{try{$fn();++$this->passed;$this->getLogger()->info('PASS '.$name);}catch(\Throwable $e){$this->failures[$name]=$e->getMessage();$this->getLogger()->logException($e);}}
    private function choose(SyntheticPlayer $p,string $label):void{
        $form=end($p->forms);$json=$form->jsonSerialize();
        foreach($json['buttons']??[] as $i=>$button)if(str_contains(\pocketmine\utils\TextFormat::clean($button['text']),$label)){$form->handleResponse($p,$i);return;}
        throw new \RuntimeException('Missing form button: '.$label);
    }
    private function drain():void{for($i=0;$i<500;++$i)$this->clans->snapshots->tick();}
    private function match(string $mode='arena',bool $full=true):string{
        $this->clans->matches->request($this->players['Alice'],$this->ids['b'],$mode,3);
        $requests=$this->clans->db->runtime['requests'];$rid=array_key_last($requests);$this->clans->matches->respond($this->players['Bob'],$rid,true);$this->drain();$mid=array_key_last($this->clans->matches->all());
        if($full)foreach(['Alice','Ally','Ann','Bob','Bill','Ben'] as $name)$this->clans->matches->join($this->players[$name],$mid);
        return $mid;
    }
    private function run():void{
        try{
            $this->clans=Main::getInstance();$c=$this->clans;$w=$this->getServer()->getWorldManager()->getDefaultWorld();$config=$c->getConfig();$config->set('minimum-playtime-seconds',0);$config->set('create-cost',100);$config->setNested('chat.cooldown-seconds',0);$config->setNested('war.rematch-cooldown-seconds',0);$config->setNested('snapshot.blocks-per-tick',10000);
            $this->test('source audit dependencies actually load',function()use($c):void{self::ok($c->isEnabled() && $this->getServer()->getPluginManager()->getPlugin('ChatPerms')->isEnabled());});
            $this->test('lossless migration of legacy roles home storage bank and history',function():void{
                $dir=$this->getDataFolder().'migration/';@mkdir($dir,0777,true);
                $old=['clans'=>['legacy'=>['name'=>'Legacy','leader'=>'old','members'=>['old'=>['name'=>'Old','role'=>'leader'],'friend'=>['name'=>'Friend','role'=>'officer']],'roles'=>['leader'=>['name'=>'Лидер','priority'=>100,'permissions'=>[]],'officer'=>['name'=>'Офицер','priority'=>80,'permissions'=>['invite']]],'bank'=>321,'storage'=>['1'=>'NBT-PRESERVED'],'homes'=>['one'=>['world'=>'world','x'=>1,'y'=>100,'z'=>1],'two'=>['world'=>'world','x'=>2,'y'=>100,'z'=>2]],'history'=>[['time'=>1,'text'=>'old audit']],'kills'=>10,'deaths'=>1]]];
                file_put_contents($dir.'clans.yml',yaml_emit($old));$db=new Database($dir);Migration::run($db,[]);$row=$db->clans['legacy'];
                self::ok($row['bank']===321 && $row['storage'][1]==='NBT-PRESERVED' && count($row['legacyHomes'])===2 && $row['home']['x']===1 && isset($row['roles']['officer']) && $row['roles']['leader']['priority']===10 && $row['history'][0]['text']==='old audit');
                self::ok(count(glob($dir.'backups/*/clans.yml'))===1);$again=new Database($dir);Migration::run($again,[]);self::ok($again->clans['legacy']['roles']['officer']===$row['roles']['officer']);
            });
            $this->test('XP overflow level cap debt and inclusive expansion geometry',function():void{
                $row=['level'=>1,'xp'=>0,'bank'=>3000,'debt'=>0];Rules::xp($row,3100);self::ok($row['level']===4 && $row['xp']===100);Rules::xp($row,2000);self::ok($row['level']===5 && $row['xp']===0);Rules::fine($row,10000);self::ok($row['bank']===0 && $row['debt']===7000);Rules::deposit($row,8000);self::ok($row['bank']===1000 && $row['debt']===0);$b=Rules::bounds(0,0,35);self::ok($b['maxX']-$b['minX']+1===35);
            });
            $worldPlayers=[];
            foreach(['Alice','Ally','Ann','Bob','Bill','Ben','Stranger'] as $i=>$name){$p=new SyntheticPlayer(new Position(100+$i,100,100,$w),$name);$this->players[$name]=$p;$worldPlayers[$p->getUniqueId()->getBytes()]=$p;EconomyAPI::getInstance()->ensureAccount($name);EconomyAPI::getInstance()->setBalance($name,1000000);}
            (new \ReflectionProperty($this->getServer(),'playerList'))->setValue($this->getServer(),$worldPlayers);
            $this->test('minimum playtime denies new clan membership',function()use($config,$c):void{$config->set('minimum-playtime-seconds',1200);self::denied(fn()=> $c->clan->create($this->players['Alice'],'Legends'));$config->set('minimum-playtime-seconds',0);});
            $this->test('creation uses existing economy and creates three required roles',function()use($c):void{
                $c->clan->create($this->players['Alice'],'Legends');$c->clan->create($this->players['Bob'],'Defenders');$this->ids=['a'=>$c->clan->id('Alice'),'b'=>$c->clan->id('Bob')];self::ok(EconomyAPI::getInstance()->getBalance('Alice')===999900);self::ok(count($c->db->clans[$this->ids['a']]['roles'])===3 && $c->db->clans[$this->ids['a']]['roles']['member']['permissions']===[]);
                foreach(['Ally','Ann'] as $name)$c->clan->addMember($this->ids['a'],$this->players[$name]);foreach(['Bill','Ben'] as $name)$c->clan->addMember($this->ids['b'],$this->players[$name]);
            });
            $this->test('role permissions leader immutability and hierarchy enforced in service',function()use($c):void{
                self::denied(fn()=> $c->clan->saveRole($this->players['Ann'],null,'Взлом',9,['withdraw']));self::denied(fn()=> $c->clan->saveRole($this->players['Alice'],'leader','Лидер',9,[]));
                $c->clan->saveRole($this->players['Alice'],null,'Хранитель',5,['deposit','history']);$id=$this->ids['a'];$roles=$c->db->clans[$id]['roles'];$rid=array_key_last($roles);$c->clan->assign($this->players['Alice'],'Ann',$rid);self::ok($c->clan->has($id,'Ann','deposit') && !$c->clan->has($id,'Ann','withdraw'));
                self::denied(fn()=> $c->clan->saveRole($this->players['Alice'],null,'Роль 2',5,[]));self::denied(fn()=> $c->clan->assign($this->players['Ann'],'Alice','member'));
            });
            $this->test('join rating cannot be farmed by rejoining',function()use($c):void{$id=$this->ids['a'];$before=$c->db->clans[$id]['rating'];$c->clan->remove($this->players['Alice'],'Ally');$c->clan->addMember($id,$this->players['Ally']);self::ok($c->db->clans[$id]['rating']===$before);});
            $this->test('economy operation replay is exactly once and argument mismatch rejected',function():void{
                $api=EconomyAPI::getInstance();$before=$api->getBalance('Alice');self::ok($api->applyExternalOperation('test-once','Alice',-100));self::ok($api->applyExternalOperation('test-once','Alice',-100));self::ok($api->getBalance('Alice')===$before-100);
                try{$api->applyExternalOperation('test-once','Alice',-101);throw new \RuntimeException('Changed operation accepted');}catch(\LogicException){}
            });
            $this->test('treasury permissions debt-first deposit and withdrawal',function()use($c):void{
                $id=$this->ids['a'];$c->db->clans[$id]['debt']=700;$c->treasury->transfer($this->players['Alice'],1000,true);self::ok($c->db->clans[$id]['bank']===300 && $c->db->clans[$id]['debt']===0);self::denied(fn()=> $c->treasury->transfer($this->players['Ally'],1,true));$c->treasury->transfer($this->players['Alice'],100,false);self::ok($c->db->clans[$id]['bank']===200);
            });
            $this->test('crash journal resumes provider-applied transfer without double charge',function()use($c):void{
                $id=$this->ids['a'];$op='journal-recovery';$before=EconomyAPI::getInstance()->getBalance('Alice');$bank=$c->db->clans[$id]['bank'];$c->db->runtime['finance'][$op]=['state'=>'pending','clan'=>$id,'player'=>'Alice','amount'=>123,'deposit'=>true];$c->db->save();EconomyAPI::getInstance()->applyExternalOperation($op,'Alice',-123);$c->treasury->recover();$c->treasury->recover();self::ok(EconomyAPI::getInstance()->getBalance('Alice')===$before-123 && $c->db->clans[$id]['bank']===$bank+123);
            });
            $this->test('FormAPI main menu and immutable leader display',function()use($c):void{
                $p=$this->players['Alice'];$c->menus->main($p);$form=end($p->forms);self::ok($form instanceof \jojoe77777\FormAPI\SimpleForm);$json=$form->jsonSerialize();self::ok(str_contains($json['content'],'§5') && !str_contains($json['content'],'\\n'));
                $command=new \pocketmine\event\server\CommandEvent($p,'clan');$command->call();self::ok($command->isCancelled() && end($p->forms) instanceof \jojoe77777\FormAPI\SimpleForm);
                $index=array_search('§fРоли',array_column($json['buttons'],'text'),true);self::ok(is_int($index));$form->handleResponse($p,$index);$form=end($p->forms);$form->handleResponse($p,0);$view=end($p->forms)->jsonSerialize();self::ok($view['type']==='form' && str_contains($view['title'],'чтение'));
            });
            $this->test('stale financial confirmation rechecks rights and cannot replay',function()use($c):void{
                $p=$this->players['Ann'];$id=$this->ids['a'];$c->menus->main($p);end($p->forms)->handleResponse($p,2);end($p->forms)->handleResponse($p,0);$input=end($p->forms);self::ok($input instanceof \jojoe77777\FormAPI\CustomForm);$input->handleResponse($p,[null,'10']);$confirm=end($p->forms);self::ok($confirm instanceof \jojoe77777\FormAPI\ModalForm);$before=$c->db->clans[$id]['bank'];$c->clan->assign($this->players['Alice'],'Ann','member');$confirm->handleResponse($p,true);$confirm->handleResponse($p,true);self::ok($c->db->clans[$id]['bank']===$before);
            });
            $this->test('OP editor rejects ordinary player even with all permission nodes',function()use($c):void{self::denied(fn()=> $c->menus->admin($this->players['Alice']));});
            $this->test('private clan chat routes once excludes other clan and honors cancellation',function()use($c):void{
                foreach($this->players as $p)$p->messages=[];$p=$this->players['Alice'];$fmt=new \pocketmine\player\chat\StandardChatFormatter();$e=new PlayerChatEvent($p,'#c секрет',array_values($this->players),$fmt);$e->call();self::ok($e->isCancelled());self::ok(count($this->players['Ann']->messages)===1 && count($this->players['Bob']->messages)===0 && str_contains($this->players['Ann']->messages[0],'Лидер'));
                $e=new PlayerChatEvent($p,'#c muted',array_values($this->players),$fmt);$e->cancel();$c->routeClanChat($e);self::ok(count($this->players['Ann']->messages)===1);
            });
            $this->test('regions protect outsiders and expansion never overlaps',function()use($c,$w):void{
                $p=$this->players['Alice'];$p->teleport(new Position(500,100,500,$w));$c->regions->create($p,'Первый');$id=$this->ids['a'];$rid=array_key_first($c->db->clans[$id]['regions']);$pos=new Position(500,100,500,$w);self::ok($c->regions->canAccess($p,$pos) && !$c->regions->canAccess($this->players['Stranger'],$pos));$c->db->clans[$id]['bank']=1000000;
                for($i=0;$i<5;++$i)$c->regions->expand($p,$rid);$r=$c->db->clans[$id]['regions'][$rid];self::ok($r['bonus']===10 && $r['maxX']-$r['minX']+1===35);self::denied(fn()=> $c->regions->expand($p,$rid));
                $c->regions->acl($p,$rid,[]);self::ok(!$c->regions->canAccess($this->players['Ally'],$pos) && $c->regions->canAccess($p,$pos));
            });
            $this->test('level growth preserves existing geometry until explicitly confirmed',function()use($c):void{
                $id=$this->ids['a'];$rid=array_key_first($c->db->clans[$id]['regions']);$before=$c->db->clans[$id]['regions'][$rid];$c->db->clans[$id]['level']=3;self::ok($c->db->clans[$id]['regions'][$rid]===$before);$c->regions->applyLevelSize($this->players['Alice'],$rid);$r=$c->db->clans[$id]['regions'][$rid];self::ok($r['baseSize']===35 && $r['bonus']===10 && $r['maxX']-$r['minX']+1===45);
            });
            $this->test('legacy shared storage uses one session and restores its temporary blocks',function()use($c,$w):void{
                $w->setChunk(6,6,new \pocketmine\world\format\Chunk([],true));$p=$this->players['Alice'];$c->matches->teleport($p,['world'=>$w->getFolderName(),'x'=>100,'y'=>100,'z'=>100]);
                $c->openStorage($p);self::denied(fn()=> $c->openStorage($this->players['Ally']));$receipts=$c->db->runtime['storageBlocks'];self::ok(count($receipts)===1);$receipt=reset($receipts);$c->closeStorage($p);foreach($receipt['positions'] as [$x,$y,$z])self::ok($w->getBlockAt((int)$x,(int)$y,(int)$z)->getTypeId()===B::AIR()->getTypeId());self::ok($c->db->runtime['storageBlocks']===[]);
            });
            $this->test('arena validation requires distinct spawns and safe wall',function()use($c,$w):void{
                $this->getServer()->getOps()->set('alice',true);
                for($cx=0;$cx<=1;++$cx)for($cz=0;$cz<=1;++$cz)$w->setChunk($cx,$cz,new \pocketmine\world\format\Chunk([],true));
                for($x=0;$x<=16;++$x)for($z=0;$z<=16;++$z){$w->setBlockAt($x,99,$z,B::STONE());for($y=100;$y<=108;++$y)$w->setBlockAt($x,$y,$z,B::AIR(),false);}
                $pos=fn($x,$y,$z)=>['world'=>$w->getFolderName(),'x'=>$x,'y'=>$y,'z'=>$z];$bounds=['world'=>$w->getFolderName(),'minX'=>0,'maxX'=>16,'minY'=>99,'maxY'=>108,'minZ'=>0,'maxZ'=>16];$wall=array_replace($bounds,['minX'=>8,'maxX'=>8]);
                $a=['id'=>'test','name'=>'Test','bounds'=>$bounds,'wall'=>$wall,'axis'=>'x','modes'=>['arena','siege'],'maximum'=>15,'exit'=>$pos(100,100,100),'spawns'=>['a'=>[$pos(3.5,100,3.5),$pos(4.5,100,3.5),$pos(5.5,100,3.5)],'b'=>[$pos(12.5,100,3.5),$pos(13.5,100,3.5),$pos(14.5,100,3.5)]],'chests'=>['a'=>$pos(3,100,12),'b'=>$pos(13,100,12)]];
                $w->setBlockAt(3,100,12,B::CHEST());$w->setBlockAt(13,100,12,B::CHEST());$w->setBlockAt(6,100,12,B::CHEST());$w->getTileAt(6,100,12)->getInventory()->setItem(0,I::DIAMOND()->setCount(17)->setCustomName('Original NBT'));$c->arenas->update($this->players['Alice'],'test',function(array &$v)use($a):void{$v=$a;});$c->arenas->validate($a,'siege',3);$bad=$a;$bad['spawns']['a'][1]=$bad['spawns']['a'][0];self::denied(fn()=> $c->arenas->validate($bad,'arena',3));
                foreach($this->ids as $id){$c->db->clans[$id]['level']=2;$c->db->clans[$id]['bank']=3000;for($i=count($c->db->clans[$id]['regions']);$i<4;++$i)$c->db->clans[$id]['regions']['fixture'.$i]=['name'=>'Fixture'.$i,'world'=>$w->getFolderName(),'centerX'=>1000+$i*100+($id===$this->ids['a']?0:1000),'centerZ'=>1000,'baseSize'=>30,'bonus'=>0,'roles'=>[]]+Rules::bounds(1000+$i*100+($id===$this->ids['a']?0:1000),1000,30);}
                $c->db->save();
            });
            $this->test('numeric arena 11 opens edits saves reloads and deletes via FormAPI',function()use($c):void{
                $p=$this->players['Alice'];$c->arenas->update($p,'11',static function(array &$a):void{});
                $c->menus->admin($p);$this->choose($p,'Арена 11');self::ok(str_contains(end($p->forms)->jsonSerialize()['title'],'Арена 11'));
                $this->choose($p,'Название и режимы');end($p->forms)->handleResponse($p,['Числовая арена',true,false,0]);
                $c->arenas->reload();self::ok($c->arenas->get('11')['name']==='Числовая арена' && $c->arenas->get('11')['id']==='11');
                $c->menus->admin($p);$this->choose($p,'Арена 11');$this->choose($p,'Удалить арену');end($p->forms)->handleResponse($p,true);self::ok($c->arenas->get('11')===null);
                $cid=$this->ids['a'];$region=reset($c->db->clans[$cid]['regions']);$region['name']='Числовой регион';$region=array_replace($region,['minX'=>700,'maxX'=>710,'minZ'=>700,'maxZ'=>710]);$c->db->clans[$cid]['regions']['11']=$region;
                $c->menus->main($p);$this->choose($p,'Регионы');$this->choose($p,'Числовой регион');self::ok(str_contains(end($p->forms)->jsonSerialize()['title'],'Числовой регион'));
                self::ok($c->regions->at(new Position(705,100,705,$p->getWorld()))['id']==='11');unset($c->db->clans[$cid]['regions']['11']);$c->db->save();
            });
            $this->test('clanhome command and menu share destination and deny missing home',function()use($c,$w):void{
                $p=$this->players['Ally'];$id=$this->ids['a'];self::denied(fn()=> $c->teleportClanHome($p));self::denied(fn()=> $c->teleportClanHome($this->players['Stranger']));
                $c->db->clans[$id]['home']=['world'=>$w->getFolderName(),'x'=>108,'y'=>100,'z'=>108];
                $event=new \pocketmine\event\server\CommandEvent($p,'clanhome');$event->call();self::ok($event->isCancelled() && $p->getPosition()->x===108.0 && $p->getPosition()->z===108.0);
                $c->matches->teleport($p,['world'=>$w->getFolderName(),'x'=>105,'y'=>100,'z'=>105]);$c->menus->main($p);$this->choose($p,'Клановая точка');$this->choose($p,'Телепортироваться');self::ok($p->getPosition()->x===108.0);
                $access=$this->getServer()->getPluginManager()->getPlugin('AmyxCommandAccess');if($access!==null){self::ok($access->resolve('clanhome')?->getName()==='clanhome');self::ok(in_array('clanhome',$access->getConfig()->get('allowed-commands'),true));}
                $c->db->clans[$id]['home']=null;
            });
            $this->test('legacy region booleans keep permissions and new criteria are independent',function()use($c):void{
                $id=$this->ids['a'];$rid=array_key_first($c->db->clans[$id]['regions']);$p=$this->players['Ally'];$pos=new Position(500,100,500,$p->getWorld());
                foreach([true,false] as $value){$c->db->clans[$id]['regions'][$rid]['roles']['member']=$value;foreach(\AmyXClans\region\RegionService::ACTIONS as $key=>$_)self::ok($c->regions->canAccess($p,$pos,$key)===$value);}
                $settings=['break'=>true,'place'=>false,'containers'=>true,'interact'=>false,'entities'=>false];$c->regions->setRoleAccess($this->players['Alice'],$rid,'member',$settings);
                foreach($settings as $key=>$value)self::ok($c->regions->canAccess($p,$pos,$key)===$value);self::ok(!$c->regions->canAccess($this->players['Stranger'],$pos,'containers'));
                self::denied(fn()=> $c->regions->setRoleAccess($this->players['Ally'],$rid,'member',array_fill_keys(array_keys($settings),true)));
                self::denied(fn()=> $c->regions->setRoleAccess($this->players['Alice'],$rid,'leader',$settings));
                self::ok(!$c->regions->canAccess($p,$pos,'unknown'));
            });
            $this->test('native break place interaction and inventory events enforce separate criteria',function()use($c,$w):void{
                $p=$this->players['Ally'];$w->setChunk(31,31,new \pocketmine\world\format\Chunk([],true));$w->setBlockAt(500,100,500,B::STONE());$w->setBlockAt(501,100,500,B::CHEST());
                $block=$w->getBlockAt(500,100,500);$chest=$w->getTileAt(501,100,500)->getInventory();$item=I::AIR();
                $break=new \pocketmine\event\block\BlockBreakEvent($p,$block,$item);$break->call();self::ok(!$break->isCancelled(),'Breaking incorrectly requires place permission');
                $tx=new \pocketmine\world\BlockTransaction($w);$tx->addBlockAt(503,100,500,B::STONE());$place=new \pocketmine\event\block\BlockPlaceEvent($p,$tx,$block,$item);$place->call();self::ok($place->isCancelled());
                $interact=new \pocketmine\event\player\PlayerInteractEvent($p,$item,$block,null,1);$interact->call();self::ok(!$interact->useBlock() || $interact->isCancelled());
                $open=new \pocketmine\event\inventory\InventoryOpenEvent($chest,$p);$open->call();self::ok(!$open->isCancelled(),'Container access incorrectly requires build permission');
                $id=$this->ids['a'];$rid=array_key_first($c->db->clans[$id]['regions']);$c->regions->setRoleAccess($this->players['Alice'],$rid,'member',['break'=>false,'place'=>true,'containers'=>false,'interact'=>true,'entities'=>true]);
                $open=new \pocketmine\event\inventory\InventoryOpenEvent($chest,$p);$open->call();self::ok($open->isCancelled());
                $transfer=new InventoryTransaction($p,[new SlotChangeAction($chest,0,I::AIR(),I::DIAMOND()),new SlotChangeAction($p->getInventory(),0,I::DIAMOND(),I::AIR())]);$inventoryEvent=new InventoryTransactionEvent($transfer);$inventoryEvent->call();self::ok($inventoryEvent->isCancelled());
                $break=new \pocketmine\event\block\BlockBreakEvent($p,$block,$item);$break->call();self::ok($break->isCancelled());$place=new \pocketmine\event\block\BlockPlaceEvent($p,$tx,$block,$item);$place->call();self::ok(!$place->isCancelled());
                $guard=$this->getServer()->getPluginManager()->getPlugin('AmyBuilderGuard');if($guard!==null){self::ok(!$guard->actionAllowed($p,'break',$w->getFolderName(),500,100,500));self::ok($guard->actionAllowed($p,'place',$w->getFolderName(),500,100,500));}
            });
            $this->test('placement is independent of block use and double chest boundary cannot bypass ACL',function()use($c,$w):void{
                $p=$this->players['Ally'];$actor=$this->players['Alice'];$id=$this->ids['a'];$rid=array_key_first($c->db->clans[$id]['regions']);$settings=['break'=>false,'place'=>true,'containers'=>false,'interact'=>false,'entities'=>false];$c->regions->setRoleAccess($actor,$rid,'member',$settings);
                $block=$w->getBlockAt(500,100,500);$event=new \pocketmine\event\player\PlayerInteractEvent($p,B::STONE()->asItem(),$block,null,1);$event->call();self::ok(!$event->isCancelled() && !$event->useBlock(),'Place permission must work without generic interaction permission');
                $minX=$c->db->clans[$id]['regions'][$rid]['minX'];$left=new \pocketmine\block\inventory\ChestInventory(new Position($minX-1,100,500,$w));$right=new \pocketmine\block\inventory\ChestInventory(new Position($minX,100,500,$w));$w->loadChunk($minX>>4,31);
                $w->setChunk($minX>>4,31,new \pocketmine\world\format\Chunk([],true));$w->setBlockAt($minX,100,500,B::CHEST());$double=new \pocketmine\block\inventory\DoubleChestInventory($left,$right);
                $open=new \pocketmine\event\inventory\InventoryOpenEvent($double,$p);$open->call();self::ok($open->isCancelled(),'Unprotected half of double chest bypassed the protected half');
                $fill=new \pocketmine\event\player\PlayerBucketFillEvent($p,$block,1,I::BUCKET(),I::WATER_BUCKET());$fill->call();self::ok($fill->isCancelled());
                $empty=new \pocketmine\event\player\PlayerBucketEmptyEvent($p,$block,1,I::WATER_BUCKET(),I::BUCKET());$empty->call();self::ok(!$empty->isCancelled());
                $settings['place']=false;$c->regions->setRoleAccess($actor,$rid,'member',$settings);
                $destination=$w->getBlockAt($minX,100,500);$edgeEmpty=new \pocketmine\event\player\PlayerBucketEmptyEvent($p,$destination,4,I::WATER_BUCKET(),I::BUCKET());$edgeEmpty->call();self::ok($edgeEmpty->isCancelled(),'Bucket destination was checked one block too far');
                $outside=$w->getBlockAt($minX-1,100,500);$edgeFill=new \pocketmine\event\player\PlayerBucketFillEvent($p,$outside,4,I::BUCKET(),I::WATER_BUCKET());$edgeFill->call();self::ok($edgeFill->isCancelled(),'Bucket extraction source bypassed region boundary');
            });
            $this->test('WorldEdit set cannot destroy occupied blocks with only placement permission',function()use($c,$w):void{
                $guard=$this->getServer()->getPluginManager()->getPlugin('AmyBuilderGuard');if($guard===null)return;
                $p=$this->players['Ally'];$id=$this->ids['a'];$rid=array_key_first($c->db->clans[$id]['regions']);$c->regions->setRoleAccess($this->players['Alice'],$rid,'member',['break'=>false,'place'=>true,'containers'=>false,'interact'=>false,'entities'=>false]);
                $sessions=(new \ReflectionProperty($guard,'sessions'))->getValue($guard);$selection=$sessions->getSelection($p->getName());$pos=new \pocketmine\math\Vector3(500,100,500);$selection->setPos1($pos,$w->getFolderName());$selection->setPos2($pos,$w->getFolderName());
                $command=$this->getServer()->getCommandMap()->getCommand('amybg:set');self::ok($command!==null);$command->execute($p,'set',['diamond_block']);self::ok($w->getBlockAt(500,100,500)->getTypeId()===B::STONE()->getTypeId(),'WorldEdit replaced an existing block without break permission');
                $pos=new \pocketmine\math\Vector3(503,100,500);$selection->setPos1($pos,$w->getFolderName());$selection->setPos2($pos,$w->getFolderName());$command->execute($p,'set',['diamond_block']);self::ok($w->getBlockAt(503,100,500)->getTypeId()===B::DIAMOND()->getTypeId(),'WorldEdit placement into air was denied');
            });
            $this->test('region form edits all five criteria and saved settings survive reload',function()use($c):void{
                $p=$this->players['Alice'];$id=$this->ids['a'];$rid=array_key_first($c->db->clans[$id]['regions']);
                $c->menus->main($p);$this->choose($p,'Регионы');$this->choose($p,'Первый');$this->choose($p,'Права ролей');$this->choose($p,'Участник');$form=end($p->forms);self::ok($form instanceof \jojoe77777\FormAPI\CustomForm && count($form->jsonSerialize()['content'])===5);
                $form->handleResponse($p,[true,false,true,false,true]);$db=new Database($c->getDataFolder());self::ok($db->clans[$id]['regions'][$rid]['roles']['member']===['break'=>true,'place'=>false,'containers'=>true,'interact'=>false,'entities'=>true]);
                $c->regions->acl($p,$rid,[]);
            });
            $this->test('channel limit upgrades existing 3 to 5 and rejects a sixth channel',function()use($c):void{
                self::ok((int)$c->cfg('max-channels')===5,'Existing config was not upgraded');$p=$this->players['Alice'];$id=$this->ids['a'];
                foreach(['первый','второй','третий','четвертый','пятый'] as $name){$c->menus->main($p);$this->choose($p,'Каналы и настройки');$this->choose($p,'Создать канал');$this->choose($p,'Изменить');end($p->forms)->handleResponse($p,[$name,1]);}
                self::ok(count($c->db->clans[$id]['channels'])===5);self::ok(str_contains(end($p->messages),'Достигнут лимит каналов'));
            });
            $this->test('WorldEdit hook and whitelist resolve the real clan provider',function()use($c,$w):void{
                $guard=$this->getServer()->getPluginManager()->getPlugin('AmyBuilderGuard');
                if($guard!==null)self::ok(!$guard->actionAllowed($this->players['Stranger'],'place',$w->getFolderName(),500,100,500),'WorldEdit bypassed a clan claim');
                $access=$this->getServer()->getPluginManager()->getPlugin('AmyxCommandAccess');
                if($access!==null)self::ok($access->resolve('clan')?->getName()==='clan' && $access->resolve('clanadmin')===null);
            });
            $this->test('technical loss drains treasury creates debt and uses only minus30',function()use($c):void{
                $mid=$this->match('arena',false);$a=$this->ids['a'];$b=$this->ids['b'];$ra=$c->db->clans[$a]['rating'];$rb=$c->db->clans[$b]['rating'];$c->matches->tick(time()+601);$this->drain();$m=$c->matches->all()[$mid];self::ok($m['phase']==='finished' && $m['winner']===null);self::ok($c->db->clans[$a]['bank']===0 && $c->db->clans[$a]['debt']===7000);self::ok($c->db->clans[$a]['rating']===max(0,$ra-30) && $c->db->clans[$b]['rating']===max(0,$rb-30));
            });
            $this->test('arena own inventory collection preparation movement boundary and timeout',function()use($c,$w):void{
                $p=$this->players['Alice'];$p->getInventory()->setItem(7,I::DIAMOND_SWORD()->setCustomName('Own gear'));$mid=$this->match();$m=$c->matches->all()[$mid];self::ok($m['phase']==='preparing' && $p->getInventory()->getItem(7)->getCustomName()==='Own gear');
                $from=$p->getLocation();$to=clone $from;$to->x+=1;$event=new PlayerMoveEvent($p,$from,$to);$event->call();self::ok($event->isCancelled());
                self::ok(!$p->teleport(new Position(100,100,100,$w)),'External teleport allowed');self::denied(fn()=> $c->teleportClanHome($p));$c->matches->tick($m['deadline']);self::ok($c->matches->all()[$mid]['phase']==='active');
                $m=$c->matches->all()[$mid];$blast=new \pocketmine\event\entity\EntityExplodeEvent($p,new Position(5,100,5,$w),[$w->getBlockAt(17,99,1)],100,[]);$blast->call();self::ok($blast->getBlockList()===[],'Arena blast reached outside map');$uuid=$this->players['Bob']->getUniqueId()->toString();$c->db->runtime['matches'][$mid]['players'][$uuid]['alive']=false;$a=$this->ids['a'];$ra=$c->db->clans[$a]['rating'];$c->matches->tick($m['deadline']);$this->drain();self::ok($c->db->clans[$a]['rating']===$ra+40 && $c->matches->all()[$mid]['winner']==='a');$c->matches->finish($mid,'a');self::ok($c->db->clans[$a]['rating']===$ra+40,'Duplicate reward');self::ok($p->getInventory()->getItem(7)->getCustomName()==='Own gear');self::ok($w->getBlockAt(8,100,4)->getTypeId()===B::AIR()->getTypeId());
            });
            $this->test('siege carrier cannot stash own totem and capture ends immediately',function()use($c):void{
                $mid=$this->match('siege');$c->matches->tick($c->matches->all()[$mid]['deadline']);$p=$this->players['Alice'];$m=$c->matches->all()[$mid];$c->totems->pickup($p,$mid,'a');self::ok($c->totems->carrier($p)===null,'Own totem picked up');$c->totems->pickup($p,$mid,'b');self::ok($c->totems->carrier($p)!==null,'Enemy totem not acquired');$slot=$p->getInventory()->getHeldItemIndex();$item=$p->getInventory()->getItem($slot);
                $tx=new InventoryTransaction($p,[new SlotChangeAction($p->getInventory(),$slot,$item,I::AIR()),new SlotChangeAction($p->getInventory(),20,I::AIR(),$item)]);$e=new InventoryTransactionEvent($tx);$e->call();self::ok($e->isCancelled(),'Totem moved to inventory');
                $c->totems->dropCarrier($p);self::ok($c->matches->all()[$mid]['totems']['b']['state']==='ground' && $c->totems->marker($p->getInventory()->getItem($slot))===null);$c->totems->pickup($p,$mid,'b');$c->totems->interact($p,$c->arenas->pos($m['arena']['chests']['a']));$this->drain();self::ok($c->matches->all()[$mid]['winner']==='a' && $c->totems->carrier($p)===null);foreach($p->getInventory()->getContents() as $i)self::ok($c->totems->marker($i)===null);
            });
            $this->test('disconnect during collection frees its slot and retains return ticket',function()use($c):void{
                $mid=$this->match('arena',false);$p=$this->players['Alice'];$c->matches->join($p,$mid);$c->matches->quit($p);$uuid=$p->getUniqueId()->toString();self::ok(!isset($c->matches->all()[$mid]['players'][$uuid]) && isset($c->db->runtime['returns'][$uuid]));$c->matches->returnPlayer($p);$c->matches->abort($mid,'test');$this->drain();
            });
            $this->test('death forces gear drops and survival cannot be changed during combat',function()use($c):void{
                $mid=$this->match();$c->matches->tick($c->matches->all()[$mid]['deadline']);$p=$this->players['Ann'];$p->getInventory()->setItem(20,I::DIAMOND()->setCount(4));
                $ev=new \pocketmine\event\player\PlayerGameModeChangeEvent($p,\pocketmine\player\GameMode::CREATIVE());$ev->call();self::ok($ev->isCancelled());
                $cmd=new \pocketmine\event\server\CommandEvent($p,'gm 1');$cmd->call();self::ok($cmd->isCancelled());
                $death=new \pocketmine\event\player\PlayerDeathEvent($p,$p->getDrops(),0,null);$death->setKeepInventory(true);$death->call();self::ok(!$death->getKeepInventory());$drops=0;foreach($death->getDrops() as $i)if($i->getTypeId()===I::DIAMOND()->getTypeId())$drops+=$i->getCount();self::ok($drops===4);self::ok(!$c->matches->all()[$mid]['players'][$p->getUniqueId()->toString()]['alive']);$c->matches->abort($mid,'test');$this->drain();
            });
            $this->test('siege ground hologram refreshes and quit drops objective without duplication',function()use($c):void{
                $mid=$this->match('siege');$c->matches->tick($c->matches->all()[$mid]['deadline']);$p=$this->players['Alice'];$c->totems->pickup($p,$mid,'b');$c->matches->quit($p);self::ok($c->matches->all()[$mid]['totems']['b']['state']==='ground');$c->totems->tick();
                $h=$this->getServer()->getPluginManager()->getPlugin('AmyxHologram');self::ok($h!==null && count($h->getEntityManager()->getEntities())>0);$c->matches->abort($mid,'test');$this->drain();self::ok(count($h->getEntityManager()->getEntities())===0);
            });
            $this->test('overlapping arena IDs cannot reserve the same physical map',function()use($c):void{
                $a=$c->arenas->get('test');$c->arenas->update($this->players['Alice'],'overlap',function(array &$v)use($a):void{$v=$a;$v['id']='overlap';});$mid=$this->match('arena',false);self::ok($c->matches->arenaBusy('overlap'));$c->matches->abort($mid,'test');$this->drain();$c->arenas->delete($this->players['Alice'],'overlap');
            });
            $this->test('restart recovery restores blocks and tile NBT without awarding again',function()use($c,$w):void{
                $mid=$this->match('arena',false);$before=$c->db->clans[$this->ids['a']]['rating'];$w->setBlockAt(4,101,8,B::DIAMOND());$w->getTileAt(6,100,12)->getInventory()->clearAll();$c->matches->recover();$this->drain();self::ok($w->getBlockAt(4,101,8)->getTypeId()===B::AIR()->getTypeId());$stored=$w->getTileAt(6,100,12)->getInventory()->getItem(0);self::ok($stored->getCount()===17 && $stored->getCustomName()==='Original NBT');self::ok($c->db->clans[$this->ids['a']]['rating']===$before && $c->matches->all()[$mid]['phase']==='aborted');$db=new Database($c->getDataFolder());self::ok($db->runtime['matches'][$mid]['phase']==='aborted' && isset($db->clans[$this->ids['a']]['settlements']));
            });
            $this->test('defender region siege snapshots full height and transfers ownership once',function()use($c,$w,$config):void{
                foreach($this->players as $p)$c->matches->teleport($p,['world'=>$w->getFolderName(),'x'=>100,'y'=>100,'z'=>100]);
                $owner=$this->ids['b'];$rid=array_key_first($c->db->clans[$owner]['regions']);$region=['name'=>'Defender land','world'=>$w->getFolderName(),'centerX'=>8,'centerZ'=>8,'baseSize'=>17,'bonus'=>0,'roles'=>array_fill_keys(array_keys($c->db->clans[$owner]['roles']),true)]+Rules::bounds(8,8,17);$c->db->clans[$owner]['regions'][$rid]=$region;$c->db->clans[$this->ids['a']]['level']=3;
                $c->arenas->update($this->players['Alice'],'test',function(array &$a)use($w,$owner,$rid):void{$a['region']=['clan'=>$owner,'id'=>$rid];$a['modes']=['siege'];$a['bounds']['minY']=$a['wall']['minY']=$w->getMinY();$a['bounds']['maxY']=$a['wall']['maxY']=$w->getMaxY()-1;});$config->setNested('war.siege-transfer-region',true);
                $mid=$this->match('siege');$c->matches->tick($c->matches->all()[$mid]['deadline']);$p=$this->players['Alice'];self::ok($c->regions->canAccess($p,new Position(6,100,6,$w)),'Attacker blocked in active defender region');$c->totems->pickup($p,$mid,'b');$m=$c->matches->all()[$mid];$c->totems->interact($p,$c->arenas->pos($m['arena']['chests']['a']));$this->drain();self::ok(!isset($c->db->clans[$owner]['regions'][$rid]) && count($c->db->clans[$this->ids['a']]['regions'])===5 && $c->matches->all()[$mid]['phase']==='finished');
            });
        }catch(\Throwable $e){$this->failures['suite']=$e->getMessage();$this->getLogger()->logException($e);}
        finally{
            (new \ReflectionProperty($this->getServer(),'playerList'))->setValue($this->getServer(),[]);
            file_put_contents($this->getServer()->getDataPath().'results.json',json_encode(['passed'=>$this->passed,'failures'=>$this->failures],JSON_UNESCAPED_UNICODE));$this->getServer()->shutdown();
        }
    }
}
