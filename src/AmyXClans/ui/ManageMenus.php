<?php
declare(strict_types=1);
namespace AmyXClans\ui;
use pocketmine\player\Player;
use AmyXClans\service\Rules;
trait ManageMenus{
    private function roles(Player $p) : void{
        $id=$this->p->clan->require($p);$buttons=[];
        foreach($this->p->db->clans[$id]['roles'] as $rid=>$role) $buttons[]=['§5'.$role['name'].'§f | приоритет §5'.$role['priority'],fn()=> $this->role($p,$rid)];
        if($this->p->clan->has($id,$p->getName(),'manage_roles'))$buttons[]=['Создать роль',fn()=> $this->role($p,null)];
        $this->menu($p,'Роли','Приоритет 10 принадлежит только лидеру.',$buttons,fn()=> $this->main($p));
    }
    private function role(Player $p,?string $rid) : void{
        $id=$this->p->clan->require($p);$role=$rid===null?['name'=>'','priority'=>1,'permissions'=>[]]:($this->p->db->clans[$id]['roles'][$rid]??null);
        if($role===null)throw new \DomainException('Роль удалена.');
        $actor=$this->p->clan->role($id,$p->getName());
        if($rid==='leader' || !$this->p->clan->has($id,$p->getName(),'manage_roles') || $role['priority'] >= $actor['priority']){
            $lines=['Название: §5'.$role['name'],'Приоритет: §5'.$role['priority']];foreach(Rules::RIGHTS as $right=>$label)$lines[]='§f'.$label.': §5'.(in_array($right,$role['permissions'],true)?'Включено':'Выключено');
            $this->menu($p,'Роль · только чтение',implode("\n",$lines),[],fn()=> $this->roles($p));return;
        }
        $items=[['input','Название роли','Только буквы и пробелы',$role['name']],(in_array($rid,['deputy','member'],true)?['label','Приоритет: §5'.$role['priority']]:['slider','Приоритет',1,9,$role['priority']])];
        foreach(Rules::RIGHTS as $right=>$label)$items[]=['toggle',$label,in_array($right,$role['permissions'],true)];
        $this->menu($p,'Управление ролью','Роль: §5'.($role['name']===''?'Новая':$role['name']),[
            ['Изменить название, приоритет и права',fn()=> $this->custom($p,'Настройка роли',$items,function($d)use($p,$rid):void{
                $rights=[];foreach(array_keys(Rules::RIGHTS) as $i=>$key)if($d[$i+2])$rights[]=$key;
                $this->p->clan->saveRole($p,$rid,trim($d[0]),$rid==='deputy'?9:($rid==='member'?1:(int)$d[1]),$rights);$this->roles($p);
            },fn()=> $this->roles($p))],
            ...($rid!==null && !in_array($rid,['member','deputy'],true)?[['Удалить роль',fn()=> $this->confirm($p,'Удаление роли','Участники этой роли станут обычными участниками.',function()use($p,$rid):void{$this->p->clan->deleteRole($p,$rid);$this->roles($p);},fn()=> $this->roles($p))]]:[])
        ],fn()=> $this->roles($p));
    }
    private function treasury(Player $p) : void{
        $id=$this->p->clan->require($p);$c=$this->p->db->clans[$id];
        $this->menu($p,'Казна','Баланс: §5'.$c['bank'].' §fмонет\nДолг: §5'.$c['debt'].' §fмонет\nПополнения сначала погашают долг.',[
            ['Внести монеты',fn()=> $this->money($p,true)],['Снять монеты',fn()=> $this->money($p,false)],['История операций',fn()=> $this->history($p)],['Улучшения',fn()=> $this->shop($p)]
        ],fn()=> $this->main($p));
    }
    private function money(Player $p,bool $deposit) : void{
        $id=$this->p->clan->require($p,$deposit?'deposit':'withdraw');
        $this->custom($p,$deposit?'Пополнение':'Снятие',[['label','Казна: §5'.$this->p->db->clans[$id]['bank'].' §fмонет'],['input',$deposit?'Введите сумму пополнения':'Введите сумму снятия','Целое число монет']],function($d)use($p,$deposit):void{
            $s=trim($d[1]);if(!preg_match('/^[0-9]{1,10}$/D',$s))throw new \DomainException('Введите целое положительное число монет.');
            $amount=(int)$s;$this->confirm($p,'Перевод',($deposit?'Внести ':'Снять ').'§5'.$amount.' §fмонет?',function()use($p,$deposit,$amount):void{$this->p->treasury->transfer($p,$amount,$deposit);$this->treasury($p);},fn()=> $this->treasury($p));
        },fn()=> $this->treasury($p));
    }
    private function shop(Player $p) : void{
        $id=$this->p->clan->require($p,'shop');$c=$this->p->db->clans[$id];$buttons=[];
        $buttons[]=['Переименовать клан · §5'.$this->p->cfg('clan-rename-cost',5000),fn()=> $this->custom($p,'Переименование',[['input','Новое название']],function($d)use($p):void{
            $id=$this->p->clan->require($p,'shop');$this->p->clan->idle($id);$name=$this->p->clan->name($d[0]);$cost=max(0,(int)$this->p->cfg('clan-rename-cost',5000));
            $this->confirm($p,'Переименование','Новое название §5'.$name.'§r§f, стоимость §5'.$cost,function()use($p,$id,$name,$cost):void{
                $this->p->clan->require($p,'shop',$id);$this->p->clan->idle($id);$this->p->clan->name($name);
                $this->p->treasury->spend($id,$p->getName(),$cost,'переименовал клан в §5'.$name,function()use($id,$name):void{$this->p->db->clans[$id]['name']=$name;});$this->shop($p);
            },fn()=> $this->shop($p));
        },fn()=> $this->shop($p))];
        foreach((array)$this->p->cfg('clan-icons',[]) as $iconId=>$icon){
            $cost=in_array($iconId,$c['purchasedIcons'],true)?0:max(0,(int)($icon['cost']??$this->p->cfg('clan-icon-cost',10000)));
            $buttons[]=['Значок '.$icon['name'].' · §5'.$cost,fn()=> $this->confirm($p,'Значок','Купить/выбрать значок за §5'.$cost.' §fмонет?',function()use($p,$id,$iconId,$cost):void{
                $this->p->clan->require($p,'shop',$id);$this->p->clan->idle($id);
                $this->p->treasury->spend($id,$p->getName(),in_array($iconId,$this->p->db->clans[$id]['purchasedIcons'],true)?0:$cost,'выбрал значок §5'.$iconId,function()use($id,$iconId):void{$this->p->db->clans[$id]['icon']=$iconId;if(!in_array($iconId,$this->p->db->clans[$id]['purchasedIcons'],true))$this->p->db->clans[$id]['purchasedIcons'][]=$iconId;});$this->shop($p);
            },fn()=> $this->shop($p))];
        }
        foreach((array)$this->p->cfg('member-limits',[20,30,40,50]) as $limit) if((int)$limit>$c['memberLimit']){
            $cost=max(0,(int)$this->p->cfg('member-limit-upgrade-cost',10000));
            $buttons[]=['Лимит участников §5'.$limit.' §f· §5'.$cost,fn()=> $this->confirm($p,'Улучшение','Увеличить лимит до §5'.$limit.' §fза §5'.$cost.' §fмонет?',function()use($p,$id,$limit,$cost):void{
                $this->p->clan->require($p,'shop',$id);$this->p->clan->idle($id);if($this->p->db->clans[$id]['memberLimit']>=$limit)throw new \DomainException('Лимит уже куплен.');
                $this->p->treasury->spend($id,$p->getName(),$cost,'увеличил лимит участников до §5'.$limit,function()use($id,$limit):void{$this->p->db->clans[$id]['memberLimit']=(int)$limit;});$this->shop($p);
            },fn()=> $this->shop($p))];break;
        }
        $buttons[]=['Расширение региона',fn()=> $this->regionList($p)];
        $this->menu($p,'Улучшения','Все покупки оплачиваются из казны.',$buttons,fn()=> $this->treasury($p));
    }
    private function home(Player $p) : void{
        $id=$this->p->clan->require($p);$home=$this->p->db->clans[$id]['home']??null;$buttons=[];
        if($home!==null)$buttons[]=['Телепортироваться',function()use($p,$id,$home):void{$this->p->clan->require($p,null,$id);if($this->p->matches->playerMatch($p)!==null)throw new \DomainException('Нельзя покинуть битву телепортом.');$this->p->matches->teleport($p,$this->p->db->clans[$id]['home']);}];
        $r=$this->p->clan->role($id,$p->getName());
        if($r['priority']>=9 && $this->p->clan->has($id,$p->getName(),'homes')){
            $buttons[]=['Установить точку здесь',fn()=> $this->confirm($p,'Клановая точка','Заменить текущую клановую точку вашим местоположением?',function()use($p,$id):void{
                $this->p->clan->require($p,'homes',$id);$this->p->clan->idle($id);if($this->p->clan->role($id,$p->getName())['priority']<9)throw new \DomainException('Требуется приоритет 9 или 10.');
                $pos=$p->getPosition();if(!$this->p->canBuildAt($p,$pos) || $this->p->arenas->at($pos)!==null)throw new \DomainException('Нельзя установить точку на чужой или боевой территории.');
                $this->p->db->clans[$id]['home']=$this->p->arenas->position($pos);$this->p->clan->log($id,$p->getName(),'изменил клановую точку');$this->p->db->save();$this->home($p);
            },fn()=> $this->home($p))];
        }
        $this->menu($p,'Клановая точка',$home===null?'Клановая точка отсутствует.':'Мир: §5'.$home['world'].'§f\nКоординаты: §5'.$home['x'].' '.$home['y'].' '.$home['z'],$buttons,fn()=> $this->main($p));
    }
    private function storage(Player $p) : void{
        $id=$this->p->clan->require($p);$buttons=[['Открыть хранилище',fn()=> $this->p->openStorage($p)]];
        if($this->p->clan->has($id,$p->getName(),'settings'))$buttons[]=['Минимальная роль доступа',function()use($p,$id):void{
            $roles=$this->p->db->clans[$id]['roles'];$ids=array_keys($roles);
            $this->custom($p,'Доступ к хранилищу',[['dropdown','Минимальная роль',array_column($roles,'name')]],function($d)use($p,$id,$ids):void{
                $this->p->clan->require($p,'settings',$id);$this->p->clan->idle($id);$rid=$ids[$d[0]]??null;if($rid===null || !isset($this->p->db->clans[$id]['roles'][$rid]))throw new \DomainException('Роль удалена.');
                $this->p->db->clans[$id]['storageMinRole']=$rid;$this->p->clan->log($id,$p->getName(),'изменил доступ к хранилищу');$this->p->db->save();$this->storage($p);
            },fn()=> $this->storage($p));
        }];
        $this->menu($p,'Хранилище','Одновременно доступно одно окно на клан. Доступ определяется приоритетом роли.',$buttons,fn()=> $this->main($p));
    }
    private function settings(Player $p) : void{
        $id=$this->p->clan->require($p);$c=$this->p->db->clans[$id];$buttons=[];
        if($this->p->clan->has($id,$p->getName(),'settings'))$buttons[]=['Основные настройки',fn()=> $this->custom($p,'Настройки',[['toggle','Внутриклановый PvP',$c['pvp']],['toggle','Показывать клан в поиске',$c['visible']]],function($d)use($p,$id):void{
            $this->p->clan->require($p,'settings',$id);$this->p->db->clans[$id]['pvp']=$d[0];$this->p->db->clans[$id]['visible']=$d[1];$this->p->clan->log($id,$p->getName(),'изменил настройки клана');$this->p->db->save();$this->settings($p);
        },fn()=> $this->settings($p))];
        if($this->p->clan->has($id,$p->getName(),'channels')){
            foreach($c['channels'] as $channelId=>$channel)$buttons[]=['Канал §5'.$channel['name'],fn()=> $this->channel($p,$channelId)];
            $buttons[]=['Создать канал',fn()=> $this->channel($p,null)];
        }
        $this->menu($p,'Каналы и настройки','Клановый чат: §5#c сообщение§f\nДополнительный канал: §5#название сообщение',$buttons,fn()=> $this->main($p));
    }
    private function channel(Player $p,?string $channelId) : void{
        $id=$this->p->clan->require($p,'channels');$c=$this->p->db->clans[$id];$channel=$channelId===null?['name'=>'','priority'=>1]:($c['channels'][$channelId]??null);if($channel===null)throw new \DomainException('Канал удалён.');
        $this->menu($p,'Канал','Название: §5'.$channel['name'],[
            ['Изменить',fn()=> $this->custom($p,'Настройка канала',[['input','Название','Буквы',$channel['name']],['slider','Минимальный приоритет',1,10,$channel['priority']]],function($d)use($p,$id,$channelId):void{
                $this->p->clan->require($p,'channels',$id);$c=&$this->p->db->clans[$id];$name=trim($d[0]);
                if(!preg_match('/^[A-Za-zА-Яа-яЁё]{2,16}$/u',$name) || mb_strtolower($name)==='c')throw new \DomainException('Название канала: 2–16 букв.');
                if($d[1]>$this->p->clan->role($id,$p->getName())['priority'])throw new \DomainException('Приоритет выше вашего.');
                if($channelId===null && count($c['channels'])>=(int)$this->p->cfg('max-channels',3))throw new \DomainException('Достигнут лимит каналов.');
                foreach($c['channels'] as $otherId=>$other)if($otherId!==$channelId && mb_strtolower($other['name'])===mb_strtolower($name))throw new \DomainException('Название занято.');
                $cid=$channelId??'channel_'.bin2hex(random_bytes(4));if($channelId!==null && !isset($c['channels'][$channelId]))throw new \DomainException('Канал удалён.');
                $c['channels'][$cid]=['name'=>$name,'priority'=>(int)$d[1]];$this->p->clan->log($id,$p->getName(),'изменил канал §5'.$name);$this->p->db->save();$this->settings($p);
            },fn()=> $this->settings($p))],
            ...($channelId!==null && $channelId!=='general'?[['Удалить',fn()=> $this->confirm($p,'Удалить канал','Удалить канал?',function()use($p,$id,$channelId):void{$this->p->clan->require($p,'channels',$id);unset($this->p->db->clans[$id]['channels'][$channelId]);$this->p->clan->log($id,$p->getName(),'удалил канал');$this->p->db->save();$this->settings($p);},fn()=> $this->settings($p))]]:[])
        ],fn()=> $this->settings($p));
    }
    private function regionList(Player $p) : void{
        $id=$this->p->clan->require($p);$buttons=[];foreach($this->p->db->clans[$id]['regions'] as $rid=>$r)$buttons[]=['§5'.$r['name'].'§f · '.$r['world'],fn()=> $this->region($p,$rid)];
        if($this->p->clan->has($id,$p->getName(),'regions'))$buttons[]=['Создать здесь',fn()=> $this->custom($p,'Новый регион',[['input','Название региона']],function($d)use($p):void{$this->p->regions->create($p,trim($d[0]));$this->regionList($p);},fn()=> $this->regionList($p))];
        [$count,$size]=$this->p->regions->limits($id);$this->menu($p,'Регионы','Лимит: §5'.$count.'§f\nРазмер новых регионов: §5'.$size.'×'.$size.'§f\nВысота: весь мир.',$buttons,fn()=> $this->main($p));
    }
    private function region(Player $p,string $rid) : void{
        $id=$this->p->clan->require($p);$r=$this->p->db->clans[$id]['regions'][$rid]??null;if($r===null)throw new \DomainException('Регион удалён.');$buttons=[];
        if($this->p->clan->has($id,$p->getName(),'regions')){
            [, $base]=$this->p->regions->limits($id);
            if($base>$r['baseSize'])$buttons[]=['Применить размер уровня · §5'.$base.'×'.$base,fn()=> $this->confirm($p,'Размер региона','Применить размер текущего уровня? Бонус сохранится. Соседние территории будут проверены.',function()use($p,$rid):void{$this->p->regions->applyLevelSize($p,$rid);$this->region($p,$rid);},fn()=> $this->region($p,$rid))];
            if($r['bonus']<10){$next=$r['bonus']+2;$cost=(int)$this->p->cfg('region-expansion-prices.'.$next,10000*$next);$buttons[]=['Расширить до +'.$next.' · §5'.$cost,fn()=> $this->confirm($p,'Расширение','Купить суммарное расширение +'.$next.' за §5'.$cost.' §fмонет?',function()use($p,$rid,$next,$cost):void{$this->p->regions->expand($p,$rid,$next,$cost);$this->region($p,$rid);},fn()=> $this->region($p,$rid))];}
            $buttons[]=['Права ролей',function()use($p,$id,$rid,$r):void{
                $roles=$this->p->db->clans[$id]['roles'];$elements=[];$ids=[];foreach($roles as $roleId=>$role)if($roleId!=='leader'){$ids[]=$roleId;$elements[]=['toggle',$role['name'],(bool)($r['roles'][$roleId]??false)];}
                $this->custom($p,'Доступ к региону',$elements,function($d)use($p,$rid,$ids):void{$allowed=[];foreach($ids as $i=>$roleId)if($d[$i])$allowed[]=$roleId;$this->p->regions->acl($p,$rid,$allowed);$this->region($p,$rid);},fn()=> $this->region($p,$rid));
            }];
            $buttons[]=['Удалить регион',fn()=> $this->confirm($p,'Удаление региона','Снять защиту региона §5'.$r['name'].'§f?',function()use($p,$rid):void{$this->p->regions->delete($p,$rid);$this->regionList($p);},fn()=> $this->region($p,$rid))];
        }
        $size=$r['baseSize']+$r['bonus'];$this->menu($p,'Регион '.$r['name'],'Размер: §5'.$size.'×'.$size.'§f\nX: §5'.$r['minX'].'…'.$r['maxX'].'§f\nZ: §5'.$r['minZ'].'…'.$r['maxZ'].'§f\nСуммарный бонус: §5+'.$r['bonus'],$buttons,fn()=> $this->regionList($p));
    }
}
