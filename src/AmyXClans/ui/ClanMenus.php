<?php
declare(strict_types=1);
namespace AmyXClans\ui;
use pocketmine\player\Player;
trait ClanMenus{
    public function main(Player $player) : void{
        $id=$this->p->clan->id($player->getName());
        if($id===null){
            $this->menu($player,'Кланы','Вы не состоите в клане.\nМинимальное время игры: §5'.ceil((int)$this->p->cfg('minimum-playtime-seconds',1200)/60).' §fминут',[['Создать клан',fn()=> $this->custom($player,'Создание клана',[['label','Стоимость: §5'.$this->p->cfg('create-cost',10000).' §fмонет'],['input','Название клана']],function($d)use($player):void{$this->p->clan->create($player,trim($d[1]));$this->main($player);},fn()=> $this->main($player))],['Приглашения',fn()=> $this->invitations($player)],['Поиск клана',fn()=> $this->publicClans($player)],['Рейтинг кланов',fn()=> $this->top($player)]]);return;
        }
        $c=$this->p->db->clans[$id];$r=$this->p->clan->role($id,$player->getName());
        $body='Название клана: §5'.$c['name'].'§r§f\nУровень: §5'.$c['level'].' §f| XP: §5'.$c['xp'].'/1000§f\nРейтинг: §5'.$c['rating'].'§f\nУчастники: §5'.count($c['members']).'/'.$c['memberLimit'].'§f\nРоль: §5'.$r['name'].'§f\nКазна: §5'.$c['bank'].' §fмонет\nДолг: §5'.$c['debt'].' §fмонет';
        $buttons=[['Участники',fn()=> $this->members($player)],['Приглашения и заявки',fn()=> $this->recruitment($player)],['Казна и улучшения',fn()=> $this->treasury($player)],['Хранилище клана',fn()=> $this->storage($player)],['Регионы',fn()=> $this->regionList($player)],['Клановая точка',fn()=> $this->home($player)],['Роли',fn()=> $this->roles($player)],['Клановые битвы',fn()=> $this->wars($player)],['Каналы и настройки',fn()=> $this->settings($player)],['История действий',fn()=> $this->history($player)],['Рейтинг кланов',fn()=> $this->top($player)],[$r['priority']===10?'Распустить клан':'Покинуть клан',fn()=> $this->confirm($player,'Выход из клана','Подтвердите выход или роспуск. Данные распущенного клана сохранятся в архиве.',function()use($player):void{$this->p->clan->leave($player);$this->main($player);},fn()=> $this->main($player))]];
        $this->menu($player,'Клан',$body,$buttons);
    }
    private function top(Player $p) : void{
        $clans=$this->p->db->clans;uasort($clans,fn($a,$b)=>$b['rating']<=>$a['rating']);$lines=[];$i=1;
        foreach(array_slice($clans,0,50,true) as $c) $lines[]=$i++.'. §5'.$c['name'].'§r§f — §5'.$c['rating'].' §f| уровень §5'.$c['level'];
        $this->menu($p,'Рейтинг кланов',implode("\n§f",$lines),[],fn()=> $this->main($p));
    }
    private function invitations(Player $p) : void{
        $key=mb_strtolower($p->getName());$buttons=[];
        foreach($this->p->db->clans as $id=>$c){
            $invite=$c['invites'][$key]??null;if($invite===null || $invite['expires']<time()) continue;
            $buttons[]=['Клан §5'.$c['name'],fn()=> $this->confirm($p,'Приглашение','Вступить в клан §5'.$c['name'].'§f?',function()use($p,$id,$key):void{
                $invite=$this->p->db->clans[$id]['invites'][$key]??null;
                if($invite===null || $invite['expires']<time()) throw new \DomainException('Приглашение истекло.');
                $this->p->clan->addMember($id,$p,$invite['role']);$this->main($p);
            },fn()=> $this->main($p))];
        }
        $this->menu($p,'Приглашения',$buttons===[]?'Приглашений нет.':'Выберите приглашение.',$buttons,fn()=> $this->main($p));
    }
    private function publicClans(Player $p) : void{
        $buttons=[];
        foreach($this->p->db->clans as $id=>$c) if($c['visible']) $buttons[]=['§5'.$c['name'].'§r§f | рейтинг §5'.$c['rating'],fn()=> $this->confirm($p,'Заявка','Подать заявку в клан §5'.$c['name'].'§f?',function()use($p,$id):void{
            if($this->p->clan->id($p->getName())!==null || !isset($this->p->db->clans[$id])) throw new \DomainException('Клан недоступен.');
            $this->p->playtime->eligible($p);$this->p->db->clans[$id]['requests'][mb_strtolower($p->getName())]=['name'=>$p->getName(),'time'=>time()];$this->p->clan->log($id,$p->getName(),'подал заявку на вступление');$this->p->db->save();$this->main($p);
        },fn()=> $this->publicClans($p))];
        $this->menu($p,'Поиск клана','Открытые кланы',$buttons,fn()=> $this->main($p));
    }
    private function recruitment(Player $p) : void{
        $id=$this->p->clan->require($p);$c=$this->p->db->clans[$id];$buttons=[];
        if($this->p->clan->has($id,$p->getName(),'invite')) $buttons[]=['Пригласить игрока',fn()=> $this->custom($p,'Приглашение',[['input','Ник игрока онлайн']],function($d)use($p):void{$this->p->clan->invite($p,trim($d[0]));$this->recruitment($p);},fn()=> $this->recruitment($p))];
        if($this->p->clan->has($id,$p->getName(),'requests')) foreach($c['requests'] as $key=>$request){
            $buttons[]=['Заявка §5'.$request['name'],fn()=> $this->menu($p,'Заявка','Игрок: §5'.$request['name'],[
                ['Принять',function()use($p,$id,$key):void{
                    $this->p->clan->require($p,'requests',$id);$req=$this->p->db->clans[$id]['requests'][$key]??null;if($req===null)throw new \DomainException('Заявка уже обработана.');
                    $target=$this->p->getServer()->getPlayerExact($req['name']);if($target===null || !$this->p->authenticated($target))throw new \DomainException('Игрок должен быть онлайн и авторизован.');
                    $this->p->clan->addMember($id,$target);$this->recruitment($p);
                }],['Отклонить',function()use($p,$id,$key):void{$this->p->clan->require($p,'requests',$id);unset($this->p->db->clans[$id]['requests'][$key]);$this->p->clan->log($id,$p->getName(),'отклонил заявку §5'.$key);$this->p->db->save();$this->recruitment($p);}]
            ],fn()=> $this->recruitment($p))];
        }
        $this->menu($p,'Приглашения и заявки','Приглашать можно с ролью участника. Другую роль назначьте после вступления.',$buttons,fn()=> $this->main($p));
    }
    private function members(Player $p) : void{
        $id=$this->p->clan->require($p);$buttons=[];
        foreach($this->p->db->clans[$id]['members'] as $m) $buttons[]=['§5'.$m['name'].'§f | '.$this->p->db->clans[$id]['roles'][$m['role']]['name'],fn()=> $this->member($p,$m['name'])];
        $this->menu($p,'Участники','Выберите участника.',$buttons,fn()=> $this->main($p));
    }
    private function member(Player $p,string $target) : void{
        $id=$this->p->clan->require($p);$m=$this->p->db->clans[$id]['members'][mb_strtolower($target)]??null;if($m===null)throw new \DomainException('Участник вышел из клана.');
        $buttons=[];
        if($this->p->clan->has($id,$p->getName(),'change_roles')) $buttons[]=['Изменить роль',function()use($p,$id,$target):void{
            $items=[];foreach($this->p->db->clans[$id]['roles'] as $rid=>$role) if($rid!=='leader') $items[]=['§5'.$role['name'],function()use($p,$target,$rid):void{$this->p->clan->assign($p,$target,$rid);$this->members($p);}];
            $this->menu($p,'Назначение роли','Нельзя выдавать недоступные вам права.',$items,fn()=> $this->members($p));
        }];
        if($this->p->clan->has($id,$p->getName(),'kick')) $buttons[]=['Исключить',fn()=> $this->confirm($p,'Исключение','Исключить §5'.$target.'§f?',function()use($p,$target):void{$this->p->clan->remove($p,$target);$this->members($p);},fn()=> $this->members($p))];
        if($this->p->clan->role($id,$p->getName())['priority']===10) $buttons[]=['Передать лидерство',fn()=> $this->confirm($p,'Передача лидерства','Передать клан игроку §5'.$target.'§f? Вы станете участником.',function()use($p,$target):void{$this->p->clan->transferLeader($p,$target);$this->main($p);},fn()=> $this->members($p))];
        $this->menu($p,$target,'Убийства: §5'.$m['kills'].'§f\nСмерти: §5'.$m['deaths'],$buttons,fn()=> $this->members($p));
    }
    private function history(Player $p,int $page=0) : void{
        $id=$this->p->clan->require($p,'history');$all=$this->p->db->clans[$id]['history'];$size=max(1,min(30,(int)$this->p->cfg('forms.page-size',10)));$page=max(0,min($page,max(0,(int)ceil(count($all)/$size)-1)));$lines=[];
        $tz=new \DateTimeZone((string)$this->p->cfg('history-timezone','Asia/Yekaterinburg'));
        foreach(array_slice($all,$page*$size,$size) as $row){$date=(new \DateTimeImmutable('@'.$row['time']))->setTimezone($tz)->format('d.m H:i');$lines[]='§f['.$date.'] '.$row['text'];}
        $buttons=[];if($page>0)$buttons[]=['Предыдущая страница',fn()=> $this->history($p,$page-1)];if(($page+1)*$size<count($all))$buttons[]=['Следующая страница',fn()=> $this->history($p,$page+1)];
        $this->menu($p,'История · '.($page+1),implode("\n",$lines),$buttons,fn()=> $this->main($p));
    }
}
