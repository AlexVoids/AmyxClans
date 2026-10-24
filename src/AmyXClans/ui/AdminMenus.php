<?php
declare(strict_types=1);
namespace AmyXClans\ui;
use pocketmine\player\Player;
trait AdminMenus{
    public function admin(Player $p) : void{
        if(!$this->p->isOperator($p))throw new \DomainException('Только OP.');$buttons=[];
        foreach($this->p->arenas->all() as $id=>$a)$buttons[]=['Арена §5'.$id,fn()=> $this->arenaEditor($p,$id)];
        $buttons[]=['Создать арену',fn()=> $this->custom($p,'Новая арена',[['input','ID арены','Латиница, цифры, _ или -']],function($d)use($p):void{$id=trim($d[0]);if($this->p->arenas->get($id)!==null)throw new \DomainException('Арена существует.');$this->p->arenas->update($p,$id,static function(array &$a):void{});$this->arenaEditor($p,$id);},fn()=> $this->admin($p),true)];
        foreach($this->p->matches->all() as $id=>$m)if(!in_array($m['phase'],['finished','aborted'],true))$buttons[]=['Прервать битву §5'.substr($id,0,8),fn()=> $this->confirm($p,'Прервать битву','Карта будет восстановлена; новых наград не будет.',function()use($p,$id):void{if(!$this->p->isOperator($p))throw new \DomainException('Только OP.');$this->p->matches->abort($id,'Остановлена оператором '.$p->getName());$this->admin($p);},fn()=> $this->admin($p),true)];
        $this->menu($p,'Настройка арен · OP','Настройки и технические операции доступны только операторам сервера.',$buttons,null,true);
    }
    private function arenaEditor(Player $p,string $id) : void{
        if(!$this->p->isOperator($p))throw new \DomainException('Только OP.');$a=$this->p->arenas->get($id);if($a===null)throw new \DomainException('Арена удалена.');
        $change=function(callable $fn)use($p,$id):void{$this->p->arenas->update($p,$id,$fn);$this->arenaEditor($p,$id);};$buttons=[];
        foreach([1,2] as $corner)$buttons[]=['Поставить угол '.$corner.' здесь',function()use($p,$change,$corner):void{
            $pos=$this->p->arenas->position($p->getPosition());
            $change(function(array &$a)use($pos,$corner):void{
                $a['corners'][$corner]=$pos;
                if(isset($a['corners'][1],$a['corners'][2])){
                    $first=$a['corners'][1];$second=$a['corners'][2];if($first['world']!==$second['world'])throw new \DomainException('Углы должны быть в одном мире.');
                    $b=['world'=>$first['world']];foreach(['X','Y','Z'] as $axis){$k=strtolower($axis);$b['min'.$axis]=(int)floor(min($first[$k],$second[$k]));$b['max'.$axis]=(int)floor(max($first[$k],$second[$k]));}$a['bounds']=$b;
                }
            });
        }];
        foreach(['a','b'] as $side){
            $buttons[]=['Добавить старт '.strtoupper($side).' здесь · §5'.count($a['spawns'][$side]??[]),function()use($p,$change,$side):void{$pos=$this->p->arenas->position($p->getPosition());$change(function(array &$a)use($pos,$side):void{if(count($a['spawns'][$side])>=15)throw new \DomainException('Максимум 15 стартовых точек.');$a['spawns'][$side][]=$pos;});}];
            $buttons[]=['Очистить старты '.strtoupper($side),fn()=> $this->confirm($p,'Очистить старты','Удалить все стартовые точки стороны '.strtoupper($side).'?',fn()=> $change(function(array &$a)use($side):void{$a['spawns'][$side]=[];}),fn()=> $this->arenaEditor($p,$id),true)];
            $buttons[]=['Сундук '.strtoupper($side).' · блок под прицелом',function()use($p,$change,$side):void{
                $block=$p->getTargetBlock(6);if(!$block instanceof \pocketmine\block\Chest)throw new \DomainException('Посмотрите на сундук с расстояния до 6 блоков.');$pos=$this->p->arenas->position($block->getPosition());$change(function(array &$a)use($pos,$side):void{$a['chests'][$side]=$pos;});
            }];
        }
        $buttons[]=['Стена здесь · ось X',function()use($p,$change):void{$pos=$p->getPosition();$change(function(array &$a)use($pos):void{if(!isset($a['bounds']) || $a['bounds']['world']!==$pos->getWorld()->getFolderName())throw new \DomainException('Сначала задайте границы в этом мире.');$b=$a['bounds'];$b['minX']=$b['maxX']=(int)floor($pos->x);$a['axis']='x';$a['wall']=$b;});}];
        $buttons[]=['Стена здесь · ось Z',function()use($p,$change):void{$pos=$p->getPosition();$change(function(array &$a)use($pos):void{if(!isset($a['bounds']) || $a['bounds']['world']!==$pos->getWorld()->getFolderName())throw new \DomainException('Сначала задайте границы в этом мире.');$b=$a['bounds'];$b['minZ']=$b['maxZ']=(int)floor($pos->z);$a['axis']='z';$a['wall']=$b;});}];
        $buttons[]=['Точка выхода здесь',function()use($p,$change):void{$pos=$this->p->arenas->position($p->getPosition());$change(function(array &$a)use($pos):void{$a['exit']=$pos;});}];
        $buttons[]=['Название и режимы',fn()=> $this->custom($p,'Арена',[['input','Название','',$a['name']],['toggle','Обычная битва',in_array('arena',$a['modes'],true)],['toggle','Осада',in_array('siege',$a['modes'],true)],['dropdown','Максимум игроков с каждой стороны',['3','5','10','15'],array_search($a['maximum'],[3,5,10,15],true)?:0]],function($d)use($change):void{
            if(mb_strlen(trim($d[0]))<1 || mb_strlen($d[0])>32)throw new \DomainException('Название: 1–32 символа.');
            $change(function(array &$a)use($d):void{$a['name']=trim($d[0]);$a['modes']=array_values(array_filter([$d[1]?'arena':null,$d[2]?'siege':null]));$a['maximum']=[3,5,10,15][$d[3]];});
        },fn()=> $this->arenaEditor($p,$id),true)];
        $buttons[]=['Привязать регион защитника',function()use($p,$change,$id):void{
            $ids=[];$labels=[];
            foreach($this->p->db->clans as $cid=>$clan)foreach($clan['regions'] as $rid=>$region){$ids[]=['clan'=>$cid,'id'=>$rid];$labels[]=$clan['name'].' / '.$region['name'];}
            if($ids===[])throw new \DomainException('Клановых регионов нет.');
            $this->custom($p,'Осада территории',[['label','Границы станут равны всему региону, включая полную высоту. Затем заново настройте старты, стену и сундуки.'],['dropdown','Регион',$labels]],function($d)use($change,$ids):void{
                $binding=$ids[$d[1]];$region=$this->p->db->clans[$binding['clan']]['regions'][$binding['id']]??null;if($region===null)throw new \DomainException('Регион удалён.');$world=$this->p->arenas->world($region['world']);if($world===null)throw new \DomainException('Мир недоступен.');
                $change(function(array &$a)use($binding,$region,$world):void{$a['region']=$binding;$a['modes']=['siege'];$a['bounds']=array_intersect_key($region,array_flip(['world','minX','maxX','minZ','maxZ']))+['minY'=>$world->getMinY(),'maxY'=>$world->getMaxY()-1];unset($a['wall']);$a['spawns']=['a'=>[],'b'=>[]];});
            },fn()=> $this->arenaEditor($p,$id),true);
        }];
        if(isset($a['region']))$buttons[]=['Отвязать регион',fn()=> $change(function(array &$a):void{unset($a['region']);})];
        $buttons[]=['Проверить готовность',function()use($p,$id):void{
            if(!$this->p->isOperator($p))throw new \DomainException('Только OP.');$a=$this->p->arenas->get($id);$lines=[];
            foreach($a['modes'] as $mode)foreach([3,5,10,15] as $n){try{$this->p->arenas->validate($a,$mode,$n);$lines[]=$mode.' '.$n.'×'.$n.': готова';}catch(\DomainException $e){$lines[]=$mode.' '.$n.'×'.$n.': '.$e->getMessage();}}
            $this->menu($p,'Проверка арены',implode("\n",$lines),[],fn()=> $this->arenaEditor($p,$id),true);
        }];
        $buttons[]=['Удалить арену',fn()=> $this->confirm($p,'Удаление арены','Удалить конфигурацию арены? Карта останется.',function()use($p,$id):void{$this->p->arenas->delete($p,$id);$this->admin($p);},fn()=> $this->arenaEditor($p,$id),true)];
        $this->menu($p,'Арена '.$id,'A — меньшие координаты выбранной оси. B — большие. Стена перекрывает всю зону. По одной стартовой точке на каждого игрока.',$buttons,fn()=> $this->admin($p),true);
    }
}
