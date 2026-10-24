<?php
declare(strict_types=1);
namespace AmyXClans\battle;
use AmyXClans\Main;
use AmyXClans\service\Rules;
use AmyXClans\storage\Database;
use pocketmine\world\{Position,World};
use pocketmine\player\Player;

final class ArenaManager{
    private array $arenas=[];
    public function __construct(private Main $p){$this->reload();}
    public function reload() : void{
        $data=yaml_parse_file($this->p->getDataFolder().'arenas.yml');
        if(!is_array($data) || !is_array($data['arenas']??null)) throw new \DomainException('Некорректный arenas.yml');
        $this->arenas=$data['arenas'];
    }
    public function all() : array{return $this->arenas;}
    public function get(string $id) : ?array{return $this->arenas[$id]??null;}
    public function world(string $name) : ?World{
        $manager=$this->p->getServer()->getWorldManager();
        if(!$manager->isWorldLoaded($name)) $manager->loadWorld($name);
        return $manager->getWorldByName($name);
    }
    public function position(Position $p) : array{return ['world'=>$p->getWorld()->getFolderName(),'x'=>$p->x,'y'=>$p->y,'z'=>$p->z];}
    public function pos(array $p) : Position{
        $w=$this->world($p['world']);if($w===null) throw new \DomainException('Мир '.$p['world'].' недоступен.');
        if($p['y']<$w->getMinY() || $p['y'] >= $w->getMaxY()) throw new \DomainException('Координата Y вне мира.');
        $w->loadChunk(((int)floor($p['x']))>>4,((int)floor($p['z']))>>4);
        return new Position((float)$p['x'],(float)$p['y'],(float)$p['z'],$w);
    }
    public function at(Position $pos) : ?string{
        foreach($this->arenas as $id=>$a) if(isset($a['bounds']) && Rules::contains($a['bounds'],$pos->getWorld()->getFolderName(),$pos->x,$pos->z,$pos->y)) return (string)$id;
        return null;
    }
    public function select(string $mode,int $size,?string $defender=null) : ?array{
        foreach($this->arenas as $id=>$a){
            if($this->p->matches->arenaBusy((string)$id) || (isset($a['region']) && $a['region']['clan']!==$defender))continue;
            try{$this->validate($a,$mode,$size);return $a+['id'=>(string)$id];}catch(\DomainException){}
        }
        return null;
    }
    public function validate(array $a,string $mode,int $size) : void{
        if(!in_array($mode,$a['modes']??[],true) || $size>(int)($a['maximum']??15))throw new \DomainException('Арена не поддерживает этот режим/размер.');
        $bounds=$a['bounds']??null;if(!is_array($bounds))throw new \DomainException('Не заданы границы арены.');
        $w=$this->world($bounds['world']);if($w===null)throw new \DomainException('Мир арены недоступен.');
        $volume=($bounds['maxX']-$bounds['minX']+1)*($bounds['maxY']-$bounds['minY']+1)*($bounds['maxZ']-$bounds['minZ']+1);
        if($bounds['minY']<$w->getMinY() || $bounds['maxY'] >= $w->getMaxY() || $volume<1 || $volume>(int)$this->p->cfg('snapshot.maximum-volume',2000000))throw new \DomainException('Недопустимый объём арены.');
        for($cx=$bounds['minX']>>4;$cx<=($bounds['maxX']>>4);++$cx)for($cz=$bounds['minZ']>>4;$cz<=($bounds['maxZ']>>4);++$cz){
            $chunk=$w->loadChunk($cx,$cz);if($chunk===null || !$chunk->isPopulated())throw new \DomainException('Сначала сгенерируйте все чанки арены.');
        }
        $wall=$a['wall']??null;if(!is_array($wall) || $wall['world']!==$bounds['world'])throw new \DomainException('Не задана разделительная стена.');
        $axis=$a['axis']??'x';$key=$axis==='x'?'X':'Z';
        if($wall['min'.$key]!==$wall['max'.$key])throw new \DomainException('Стена должна иметь толщину один блок по оси '.$axis);
        if($wall['minY']!==$bounds['minY'] || $wall['maxY']!==$bounds['maxY'])throw new \DomainException('Стена должна перекрывать всю высоту боевой зоны.');
        $other=$axis==='x'?'Z':'X';if($wall['min'.$other]!==$bounds['min'.$other] || $wall['max'.$other]!==$bounds['max'.$other])throw new \DomainException('Стена должна перекрывать всю ширину боевой зоны.');
        if($wall['min'.$key]<=$bounds['min'.$key] || $wall['min'.$key]>=$bounds['max'.$key])throw new \DomainException('Стена должна разделять арену.');
        foreach($this->p->db->clans as $cid=>$clan)foreach($clan['regions'] as $rid=>$region)if(Rules::overlaps($bounds,$region)){
            if(($a['region']['clan']??null)!==$cid || ($a['region']['id']??null)!==$rid)throw new \DomainException('Арена пересекается с не привязанным к ней клановым регионом.');
            if($bounds['minX']!==$region['minX'] || $bounds['maxX']!==$region['maxX'] || $bounds['minZ']!==$region['minZ'] || $bounds['maxZ']!==$region['maxZ'] || $bounds['minY']!==$w->getMinY() || $bounds['maxY']!==$w->getMaxY()-1)throw new \DomainException('Осада региона должна сохранять всю его площадь и высоту.');
        }
        if(isset($a['region'])){
            $region=$this->p->db->clans[$a['region']['clan']]['regions'][$a['region']['id']]??null;
            if($region===null || $mode!=='siege')throw new \DomainException('Привязанная территория недоступна для этого режима.');
        }
        $used=[];
        foreach(['a','b'] as $side){
            $spawns=$a['spawns'][$side]??[];if(count($spawns)<$size)throw new \DomainException('Недостаточно отдельных стартовых точек.');
            foreach(array_slice($spawns,0,$size) as $s){
                if(!Rules::contains($bounds,$s['world'],$s['x'],$s['z'],$s['y']) || $s['y']+1>$bounds['maxY'])throw new \DomainException('Стартовая точка вне арены.');
                $coord=$s[$axis];$divider=$wall['min'.$key];if(($side==='a' && $coord >= $divider) || ($side==='b' && $coord < $divider+1))throw new \DomainException('Стартовая точка на чужой стороне стены.');
                $k=floor($s['x']).':'.floor($s['y']).':'.floor($s['z']);if(isset($used[$k]))throw new \DomainException('Стартовые точки повторяются.');$used[$k]=true;
                $p=$this->pos($s);if($w->getBlock($p)->isSolid() || $w->getBlock($p->add(0,1,0))->isSolid() || !$w->getBlock($p->subtract(0,1,0))->isSolid())throw new \DomainException('Стартовая точка небезопасна.');
            }
            if($mode==='siege'){
                $chest=$a['chests'][$side]??null;if(!is_array($chest) || !Rules::contains($bounds,$chest['world'],$chest['x'],$chest['z'],$chest['y']))throw new \DomainException('Не задан сундук осады.');
                $cp=$this->pos($chest);if(!$w->getBlock($cp) instanceof \pocketmine\block\Chest)throw new \DomainException('В позиции сундука должен стоять сундук.');
                if(($side==='a' && $chest[$axis]>=$divider) || ($side==='b' && $chest[$axis]<$divider+1))throw new \DomainException('Сундук находится на чужой стороне.');
                $tile=$w->getTile($cp);if($tile instanceof \pocketmine\block\tile\Chest && count($tile->getInventory()->getContents())>0)throw new \DomainException('Специальные сундуки должны быть пустыми.');
            }
        }
        if($mode==='siege' && $a['chests']['a']===$a['chests']['b'])throw new \DomainException('Сундуки должны быть разными.');
        if(!isset($a['exit']))throw new \DomainException('Не задана точка выхода.');
        $exit=$this->pos($a['exit']);if(Rules::contains($bounds,$a['exit']['world'],$exit->x,$exit->z,$exit->y))throw new \DomainException('Точка выхода должна быть вне арены.');
    }
    public function update(Player $op,string $id,callable $change) : void{
        if(!$this->p->isOperator($op))throw new \DomainException('Только OP.');
        if($this->p->matches->arenaBusy($id))throw new \DomainException('Арена занята.');
        if(!preg_match('/^[a-zA-Z0-9_-]{1,32}$/D',$id))throw new \DomainException('ID арены: латиница/цифры/_/-.');
        $a=$this->arenas[$id]??['id'=>$id,'name'=>$id,'modes'=>['arena','siege'],'maximum'=>15,'axis'=>'x','spawns'=>['a'=>[],'b'=>[]]];
        $change($a);$this->arenas[$id]=$a;
        Database::atomic($this->p->getDataFolder().'arenas.yml',yaml_emit(['arenas'=>$this->arenas],YAML_UTF8_ENCODING));
    }
    public function delete(Player $op,string $id) : void{
        if(!$this->p->isOperator($op) || $this->p->matches->arenaBusy($id))throw new \DomainException('Арена занята или нет OP.');
        unset($this->arenas[$id]);Database::atomic($this->p->getDataFolder().'arenas.yml',yaml_emit(['arenas'=>$this->arenas],YAML_UTF8_ENCODING));
    }
}
