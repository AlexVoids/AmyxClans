<?php
declare(strict_types=1);
namespace AmyXClans\ui;
use pocketmine\player\Player;
trait BattleMenus{
    private function wars(Player $p) : void{
        $id=$this->p->clan->require($p);$buttons=[];
        if($this->p->clan->has($id,$p->getName(),'wars')){
            $buttons[]=['Отправить заявку',fn()=> $this->warRequest($p)];
            foreach($this->p->db->runtime['requests']??[] as $rid=>$r)if($r['b']===$id && $r['expires']>=time()){
                $other=$this->p->db->clans[$r['a']]['name']??$r['a'];$buttons[]=['Заявка от §5'.$other.'§r§f · '.$r['size'].'×'.$r['size'],fn()=> $this->menu($p,'Заявка на битву','Противник: §5'.$other.'§r§f\nРежим: §5'.($r['mode']==='siege'?'Осада':'Арена').'§f\nИгроков с каждой стороны: §5'.$r['size'],[
                    ['Принять',fn()=> $this->confirm($p,'Принять битву','После принятия начнётся сбор. Несобранная команда получит штраф §510000 §fмонет и §530 §fрейтинга.',function()use($p,$rid):void{$this->p->matches->respond($p,$rid,true);$this->wars($p);},fn()=> $this->wars($p))],['Отклонить',function()use($p,$rid):void{$this->p->matches->respond($p,$rid,false);$this->wars($p);}]
                ],fn()=> $this->wars($p))];
            }
        }
        $lines=[];
        foreach($this->p->matches->all() as $mid=>$m)if(in_array($id,$m['teams'],true) && !in_array($m['phase'],['finished','aborted'],true)){
            $side=array_search($id,$m['teams'],true);$count=count(array_filter($m['players'],fn($d)=>$d['side']===$side && $d['alive']));
            $lines[]='Стадия: §5'.match($m['phase']){'initializing'=>'Подготовка карты','collecting'=>'Сбор','preparing'=>'Подготовка', 'active'=>'Бой',default=>'Восстановление'}.'§f\nКоманда: §5'.$count.'/'.$m['size'];
            if($m['phase']==='collecting')$buttons[]=['Присоединиться к битве',function()use($p,$mid):void{$this->p->matches->join($p,$mid);$this->wars($p);}];
        }
        $this->menu($p,'Клановые битвы',($lines===[]?'Активных битв нет.':implode("\n",$lines)).'\nВ бой берите собственные вещи. Смерть и выход означают выбытие.\nУсловия: уровень §5'.$this->p->cfg('war.minimum-level',2).'§f, регионов §5'.$this->p->cfg('war.minimum-regions',4),$buttons,fn()=> $this->main($p));
    }
    private function warRequest(Player $p) : void{
        $id=$this->p->clan->require($p,'wars');$ids=[];$names=[];
        foreach($this->p->db->clans as $cid=>$c)if($cid!==$id){$ids[]=$cid;$names[]=$c['name'];}
        if($ids===[])throw new \DomainException('Нет других кланов.');
        $sizes=array_values(array_intersect([3,5,10,15],(array)$this->p->cfg('war.team-sizes',[3,5,10,15])));if($sizes===[])throw new \DomainException('Размеры команд отключены.');
        $this->custom($p,'Заявка на битву',[['dropdown','Клан противника',$names],['dropdown','Режим',['Арена','Осада']],['dropdown','Игроков с каждой стороны',array_map('strval',$sizes)]],function($d)use($p,$ids,$sizes):void{
            $enemy=$ids[$d[0]];$mode=$d[1]===0?'arena':'siege';$size=$sizes[$d[2]];
            $this->confirm($p,'Отправить заявку','Режим §5'.($mode==='arena'?'Арена':'Осада').'§f, состав §5'.$size.'×'.$size.'§f. Подтвердить?',function()use($p,$enemy,$mode,$size):void{$this->p->matches->request($p,$enemy,$mode,$size);$this->wars($p);},fn()=> $this->wars($p));
        },fn()=> $this->wars($p));
    }
}
