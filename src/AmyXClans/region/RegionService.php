<?php
declare(strict_types=1);
namespace AmyXClans\region;
use AmyXClans\Main;
use AmyXClans\service\Rules;
use pocketmine\player\Player;
use pocketmine\world\Position;

final class RegionService{
    public const ACTIONS=[
        'break'=>'Разрушать блоки',
        'place'=>'Устанавливать блоки',
        'containers'=>'Открывать сундуки и другие контейнеры',
        'interact'=>'Использовать двери, кнопки и другие блоки',
        'entities'=>'Взаимодействовать с сущностями'
    ];
    /** v2.0 boolean entries retain exactly the same access after upgrade. Missing criteria deny access. */
    public static function accessSettings(mixed $value) : array{
        $result=[];foreach(self::ACTIONS as $action=>$_)$result[$action]=is_array($value)?(($value[$action]??false)===true):($value===true);
        return $result;
    }
    public function __construct(private Main $p){}
    public function at(Position $pos) : ?array{
        if(!$this->p->cfg('features.regions',true)) return null;
        foreach($this->p->db->clans as $id=>$c) foreach($c['regions'] as $rid=>$r){
            if(Rules::contains($r,$pos->getWorld()->getFolderName(),$pos->x,$pos->z)) return ['clan'=>(string)$id,'id'=>(string)$rid,'region'=>$r];
        }
        return null;
    }
    public function canAccess(Player $player,Position $pos,string $action='build') : bool{
        if($action!=='build' && !isset(self::ACTIONS[$action]))return false;
        $at=$this->at($pos);if($at===null) return true;
        $mid=$this->p->matches->playerMatch($player,true);
        if($mid!==null){$m=$this->p->matches->all()[$mid];if($m['phase']==='active' && ($m['arena']['region']['clan']??null)===$at['clan'] && ($m['arena']['region']['id']??null)===$at['id'] && $this->p->matches->at($pos)===$mid)return true;}
        if($this->p->cfg('regions.op-bypass',true) && $this->p->isOperator($player)) return true;
        $role=$this->p->clan->role($at['clan'],$player->getName()); if($role===null) return false;
        $id=$this->p->getClanRoleIdByPlayer($player->getName());
        if($role['priority']===10)return true;
        $settings=self::accessSettings($at['region']['roles'][$id]??false);
        return $action==='build'?($settings['break'] && $settings['place']):$settings[$action];
    }
    public function limits(string $id) : array{
        $level=$this->p->db->clans[$id]['level'];
        $defaults=[1=>[3,25],2=>[4,30],3=>[6,35],4=>[8,40],5=>[10,50]];
        return [(int)$this->p->cfg('region-levels.'.$level.'.count',$defaults[$level][0]),(int)$this->p->cfg('region-levels.'.$level.'.size',$defaults[$level][1])];
    }
    public function validate(array $r, ?string $clan=null, ?string $rid=null) : void{
        $world=$this->p->arenas->world($r['world']);if($world===null) throw new \DomainException('Мир недоступен.');
        $allowed=(array)$this->p->cfg('regions.allowed-worlds',[]);
        if(($allowed!==[] && !in_array($r['world'],$allowed,true)) || in_array($r['world'],(array)$this->p->cfg('regions.denied-worlds',[]),true)) throw new \DomainException('В этом мире нельзя создавать клановые регионы.');
        $border=$this->p->cfg('regions.world-borders.'.$r['world'],['minX'=>-29999984,'maxX'=>29999984,'minZ'=>-29999984,'maxZ'=>29999984]);
        foreach(['minX','minZ'] as $k) if($r[$k]<$border[$k]) throw new \DomainException('Регион выходит за границы мира.');
        foreach(['maxX','maxZ'] as $k) if($r[$k]>$border[$k]) throw new \DomainException('Регион выходит за границы мира.');
        foreach($this->p->db->clans as $id=>$c) foreach($c['regions'] as $otherId=>$other){
            if($clan===(string)$id && $rid===(string)$otherId) continue;
            if(Rules::overlaps($r,$other)) throw new \DomainException('Территория пересекается с регионом клана §5'.$c['name']);
        }
        foreach($this->p->arenas->all() as $a) if(isset($a['bounds']) && Rules::overlaps($r,$a['bounds'])) throw new \DomainException('Здесь находится арена.');
        $guard=$this->p->getServer()->getPluginManager()->getPlugin('AmyBuilderGuard');
        if($guard!==null && $guard->isEnabled() && method_exists($guard,'getRegionManager')){
            if($guard->getRegionManager()->overlapping2D($r['world'],$r['minX'],$r['minZ'],$r['maxX'],$r['maxZ'])!==[]) throw new \DomainException('Территория занята регионом AmyBuilderGuard.');
        }
    }
    public function create(Player $actor,string $name) : void{
        if(!$this->p->cfg('features.regions',true)) throw new \DomainException('Регионы отключены.');
        $id=$this->p->clan->require($actor,'regions');$this->p->clan->idle($id);$c=&$this->p->db->clans[$id];
        if(!preg_match('/^[\p{L}\p{N}_ -]{2,24}$/u',$name)) throw new \DomainException('Название региона: 2–24 буквы/цифры/пробелы.');
        [$max,$size]=$this->limits($id);if(count($c['regions']) >= $max) throw new \DomainException('Достигнут лимит регионов уровня '.$c['level']);
        foreach($c['regions'] as $r) if(mb_strtolower($r['name'])===mb_strtolower($name)) throw new \DomainException('Название региона занято.');
        $pos=$actor->getPosition(); $x=(int)floor($pos->x);$z=(int)floor($pos->z);
        $r=['name'=>$name,'world'=>$pos->getWorld()->getFolderName(),'centerX'=>$x,'centerZ'=>$z,'baseSize'=>$size,'bonus'=>0,'roles'=>array_fill_keys(array_keys($c['roles']),self::accessSettings(true))]+Rules::bounds($x,$z,$size);
        $this->validate($r);
        $c['regions'][bin2hex(random_bytes(6))]=$r;
        $this->p->clan->log($id,$actor->getName(),'создал регион §5'.$name.' §f'.$size.'×'.$size);$this->p->db->save();
    }
    public function delete(Player $actor,string $rid) : void{
        $id=$this->p->clan->require($actor,'regions');$this->p->clan->idle($id);$r=$this->p->db->clans[$id]['regions'][$rid]??null;
        if($r===null) throw new \DomainException('Регион удалён.');
        $this->p->clan->log($id,$actor->getName(),'удалил регион §5'.$r['name']);unset($this->p->db->clans[$id]['regions'][$rid]);$this->p->db->save();
    }
    public function expand(Player $actor,string $rid,?int $expectedBonus=null,?int $expectedCost=null) : void{
        $id=$this->p->clan->require($actor,'regions');$this->p->clan->require($actor,'shop');$this->p->clan->idle($id);
        $r=$this->p->db->clans[$id]['regions'][$rid]??null;if($r===null) throw new \DomainException('Регион удалён.');
        $next=$r['bonus']+2;if($next>10) throw new \DomainException('Максимальное расширение уже куплено.');
        $new=array_replace($r,['bonus'=>$next],Rules::bounds($r['centerX'],$r['centerZ'],$r['baseSize']+$next));$this->validate($new,$id,$rid);
        $cost=max(0,(int)$this->p->cfg('region-expansion-prices.'.$next,10000*$next));
        if(($expectedBonus!==null && $expectedBonus!==$next) || ($expectedCost!==null && $expectedCost!==$cost))throw new \DomainException('Стоимость или ступень расширения изменились. Откройте регион заново.');
        $this->p->treasury->spend($id,$actor->getName(),$cost,'расширил регион §5'.$r['name'].' §fдо +'.$next,function()use($id,$rid,$new):void{$this->p->db->clans[$id]['regions'][$rid]=$new;});
    }
    public function applyLevelSize(Player $actor,string $rid) : void{
        $id=$this->p->clan->require($actor,'regions');$this->p->clan->idle($id);
        $r=$this->p->db->clans[$id]['regions'][$rid]??null;if($r===null)throw new \DomainException('Регион удалён.');
        [, $size]=$this->limits($id);if($size<=$r['baseSize'])throw new \DomainException('Размер текущего уровня уже применён.');
        $new=array_replace($r,['baseSize'=>$size],Rules::bounds($r['centerX'],$r['centerZ'],$size+$r['bonus']));$this->validate($new,$id,$rid);
        $this->p->db->clans[$id]['regions'][$rid]=$new;$this->p->clan->log($id,$actor->getName(),'применил размер уровня к региону §5'.$r['name']);$this->p->db->save();
    }
    public function setRoleAccess(Player $actor,string $rid,string $roleId,array $access) : void{
        $id=$this->p->clan->require($actor,'regions');$this->p->clan->idle($id);$c=&$this->p->db->clans[$id];
        if(!isset($c['regions'][$rid],$c['roles'][$roleId]))throw new \DomainException('Регион или роль удалены.');
        if($roleId==='leader')throw new \DomainException('Права лидера в регионе изменить нельзя.');
        foreach(self::ACTIONS as $action=>$_)if(!isset($access[$action]) || !is_bool($access[$action]))throw new \DomainException('Некорректные настройки доступа.');
        $c['regions'][$rid]['roles'][$roleId]=self::accessSettings($access);
        $this->p->clan->log($id,$actor->getName(),'изменил права роли §5'.$c['roles'][$roleId]['name'].' §fв регионе §5'.$c['regions'][$rid]['name']);$this->p->db->save();
    }
    public function acl(Player $actor,string $rid,array $roles) : void{
        $id=$this->p->clan->require($actor,'regions');$this->p->clan->idle($id);$c=&$this->p->db->clans[$id];
        if(!isset($c['regions'][$rid])) throw new \DomainException('Регион удалён.');
        $acl=[];foreach($c['roles'] as $roleId=>$r) $acl[$roleId]=self::accessSettings($roleId==='leader' || in_array($roleId,$roles,true));
        $c['regions'][$rid]['roles']=$acl;$this->p->clan->log($id,$actor->getName(),'изменил доступ к региону §5'.$c['regions'][$rid]['name']);$this->p->db->save();
    }
}
