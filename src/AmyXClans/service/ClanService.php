<?php
declare(strict_types=1);
namespace AmyXClans\service;
use AmyXClans\Main;
use Amyx\Economy\api\EconomyAPI;
use pocketmine\player\Player;

final class ClanService{
    public function __construct(private Main $p){}
    public function id(string $name) : ?string{
        $key=mb_strtolower($name);
        foreach($this->p->db->clans as $id=>$c) if(isset($c['members'][$key])) return (string)$id;
        return null;
    }
    public function role(string $id, string $name) : ?array{
        $c=$this->p->db->clans[$id]??null; $m=$c['members'][mb_strtolower($name)]??null;
        return $m===null?null:($c['roles'][$m['role']]??null);
    }
    public function has(string $id, string $name, string $right) : bool{
        $role=$this->role($id,$name);
        return $role!==null && ($role['priority']===10 || in_array($right,$role['permissions'],true));
    }
    public function require(Player $player, ?string $right=null, ?string $expected=null) : string{
        if(!$this->p->authenticated($player)) throw new \DomainException('Сначала войдите в аккаунт.');
        $id=$this->id($player->getName());
        if($id===null || ($expected!==null && $expected!==$id)) throw new \DomainException('Вы больше не состоите в этом клане.');
        if($right!==null && !$this->has($id,$player->getName(),$right)) throw new \DomainException('У вашей роли нет права: '.(Rules::RIGHTS[$right]??$right));
        return $id;
    }
    public function idle(string $id) : void{
        if($this->p->matches->clanBusy($id)) throw new \DomainException('Во время битвы это действие недоступно.');
        if($this->p->treasury->locked($id)) throw new \DomainException('Дождитесь завершения перевода казны.');
    }
    public function log(string $id, string $actor, string $text) : void{
        if(!isset($this->p->db->clans[$id])) return;
        array_unshift($this->p->db->clans[$id]['history'],['time'=>time(),'text'=>'§5'.$actor.' §f'.$text]);
        $limit=(int)$this->p->cfg('history-limit',10000);
        if($limit>0) $this->p->db->clans[$id]['history']=array_slice($this->p->db->clans[$id]['history'],0,$limit);
    }
    public function name(string $input) : string{
        $input=trim($input);
        $plain=preg_replace('/(?:§|&)[0-9a-fklmnor]/iu','',$input);
        if(!preg_match('/^[\p{L}\p{N}_-]{'.(int)$this->p->cfg('clan-name-min-length',3).','.(int)$this->p->cfg('clan-name-max-length',16).'}$/u',$plain)) throw new \DomainException('Название клана: 3–16 букв, цифр, _ или -.');
        foreach($this->p->db->clans as $c){
            $other=preg_replace('/(?:§|&)[0-9a-fklmnor]/iu','',$c['name']);
            if(mb_strtolower($other)===mb_strtolower($plain)) throw new \DomainException('Название клана уже занято.');
        }
        return $this->p->cfg('allow-colored-clan-names',true)?str_replace('&','§',$input):$plain;
    }
    public function create(Player $player, string $name) : void{
        if(!$this->p->authenticated($player)) throw new \DomainException('Сначала войдите в аккаунт.');
        $this->p->playtime->eligible($player);
        if($this->id($player->getName())!==null) throw new \DomainException('Вы уже состоите в клане.');
        $name=$this->name($name);
        $uuid=$player->getUniqueId()->toString();
        foreach($this->p->db->runtime['creations']??[] as $o) if($o['uuid']===$uuid && $o['state']==='pending') throw new \DomainException('Создание клана уже обрабатывается.');
        $id=bin2hex(random_bytes(8)); $op='clan-create:'.$id;
        $cost=max(0,(int)$this->p->cfg('create-cost',10000));
        $key=mb_strtolower($player->getName());
        $draft=['id'=>$id,'schemaVersion'=>4,'name'=>$name,'leader'=>$key,'createdAt'=>time(),'bank'=>0,'debt'=>0,'level'=>1,'xp'=>0,'rating'=>(int)$this->p->cfg('ranking.initial',0),'memberLimit'=>20,'roles'=>Rules::roles(),'members'=>[$key=>['name'=>$player->getName(),'uuid'=>$uuid,'role'=>'leader','joinedAt'=>time(),'kills'=>0,'deaths'=>0]],'joinSeen'=>['name:'.$key=>true,'uuid:'.$uuid=>true],'invites'=>[],'requests'=>[],'channels'=>['general'=>['name'=>'общий','priority'=>1]],'home'=>null,'legacyHomes'=>[],'homes'=>[],'regions'=>[],'storage'=>[],'storageMinRole'=>'member','history'=>[],'purchasedIcons'=>[],'icon'=>'','kills'=>0,'deaths'=>0,'pvp'=>false,'visible'=>true,'settlements'=>[]];
        $this->p->db->runtime['creations'][$op]=['state'=>'pending','uuid'=>$uuid,'player'=>$player->getName(),'cost'=>$cost,'draft'=>$draft];
        $this->p->db->save(); $this->applyCreation($op);
    }
    public function recoverCreations() : void{
        foreach($this->p->db->runtime['creations']??[] as $id=>$o) if($o['state']==='pending'){
            try{$this->applyCreation($id);}catch(\DomainException){}
        }
    }
    private function applyCreation(string $op) : void{
        $o=$this->p->db->runtime['creations'][$op];
        $ok=$o['cost']===0 || EconomyAPI::getInstance()->applyExternalOperation($op,$o['player'],-$o['cost']);
        $this->p->db->transact(function()use($op,$o,$ok):void{
            if($ok){
                $id=$o['draft']['id']; $this->p->db->clans[$id]=$o['draft'];
                $this->log($id,$o['player'],'создал клан');
            }
            $this->p->db->runtime['creations'][$op]['state']=$ok?'done':'rejected';
        });
        if(!$ok) throw new \DomainException('Недостаточно монет для создания клана.');
    }
    public function addMember(string $id, Player $player, string $role='member') : void{
        $this->p->playtime->eligible($player);
        if($this->id($player->getName())!==null) throw new \DomainException('Игрок уже состоит в клане.');
        $c=&$this->p->db->clans[$id];
        if(count($c['members'])>=$c['memberLimit']) throw new \DomainException('В клане нет свободных мест.');
        if(!isset($c['roles'][$role]) || $role==='leader') $role='member';
        $key=mb_strtolower($player->getName()); $uuid=$player->getUniqueId()->toString();
        $c['members'][$key]=['name'=>$player->getName(),'uuid'=>$uuid,'role'=>$role,'joinedAt'=>time(),'kills'=>0,'deaths'=>0];
        if(!isset($c['joinSeen']['name:'.$key]) && !isset($c['joinSeen']['uuid:'.$uuid])) $this->rating($id,(int)$this->p->cfg('ranking.join',2));
        $c['joinSeen']['name:'.$key]=true; $c['joinSeen']['uuid:'.$uuid]=true;
        foreach($this->p->db->clans as &$other) unset($other['invites'][$key],$other['requests'][$key]); unset($other);
        $this->log($id,$player->getName(),'вступил в клан'); $this->p->db->save();
    }
    public function invite(Player $actor, string $targetName, string $role='member') : void{
        $id=$this->require($actor,'invite'); $c=&$this->p->db->clans[$id];
        $target=$this->p->getServer()->getPlayerExact($targetName);
        if($target===null || !$this->p->authenticated($target)) throw new \DomainException('Игрок должен быть онлайн и авторизован.');
        $this->p->playtime->eligible($target);
        if($this->id($targetName)!==null) throw new \DomainException('Игрок уже состоит в клане.');
        if(!isset($c['roles'][$role]) || $role==='leader' || $c['roles'][$role]['priority']>=$this->role($id,$actor->getName())['priority']) throw new \DomainException('Нельзя приглашать с этой ролью.');
        $c['invites'][mb_strtolower($targetName)]=['name'=>$targetName,'role'=>$role,'from'=>$actor->getName(),'expires'=>time()+(int)$this->p->cfg('invite-lifetime',86400)];
        $this->log($id,$actor->getName(),'пригласил игрока §5'.$targetName); $this->p->db->save();
        $this->p->message($target,'Вас пригласили в клан §5'.$c['name'].'§f. Откройте /clan.');
    }
    public function manageTarget(Player $actor, string $target, string $right) : string{
        $id=$this->require($actor,$right); $this->idle($id);
        $a=$this->role($id,$actor->getName()); $t=$this->role($id,$target);
        if($t===null || $a['priority'] <= $t['priority'] || mb_strtolower($actor->getName())===mb_strtolower($target)) throw new \DomainException('Нельзя управлять участником с равным или большим приоритетом.');
        return $id;
    }
    public function assign(Player $actor, string $target, string $roleId) : void{
        $id=$this->manageTarget($actor,$target,'change_roles');
        $r=$this->p->db->clans[$id]['roles'][$roleId]??null; $a=$this->role($id,$actor->getName());
        if($r===null || $roleId==='leader' || $r['priority'] >= $a['priority'] || ($a['priority']<10 && array_diff($r['permissions'],$a['permissions'])!==[])) throw new \DomainException('Эта роль выдаёт недоступные вам полномочия.');
        $this->p->db->clans[$id]['members'][mb_strtolower($target)]['role']=$roleId;
        $this->log($id,$actor->getName(),'изменил роль игрока §5'.$target.' §fна §5'.$r['name']); $this->p->db->save();
    }
    public function saveRole(Player $actor, ?string $roleId, string $name, int $priority, array $rights) : void{
        $id=$this->require($actor,'manage_roles'); $this->idle($id); $c=&$this->p->db->clans[$id];
        $a=$this->role($id,$actor->getName()); $old=$roleId===null?null:($c['roles'][$roleId]??null);
        if($roleId==='leader' || ($roleId!==null && $old===null) || ($old!==null && $old['priority'] >= $a['priority']) || ($roleId==='deputy' && $a['priority']!==10)) throw new \DomainException('Нельзя изменять эту роль.');
        if($priority<1 || $priority>9 || $priority >= $a['priority']) throw new \DomainException('Приоритет роли должен быть ниже вашего, от 1 до 9.');
        Rules::roleName($name,$c['roles'],$roleId,(int)$this->p->cfg('role-name-max-length',24));
        if($roleId===null && count($c['roles']) >= (int)$this->p->cfg('max-custom-roles',10)+3) throw new \DomainException('Достигнут лимит ролей.');
        $rights=array_values(array_intersect($rights,array_keys(Rules::RIGHTS)));
        if($a['priority']<10 && array_diff($rights,$a['permissions'])!==[]) throw new \DomainException('Нельзя выдавать отсутствующие у вас права.');
        if($roleId==='member') $priority=1;
        $roleId??='role_'.bin2hex(random_bytes(6));
        $c['roles'][$roleId]=['name'=>$name,'priority'=>$priority,'permissions'=>$rights,'color'=>'5','builtin'=>in_array($roleId,['member','deputy'],true)];
        $this->log($id,$actor->getName(),'изменил роль §5'.$name); $this->p->db->save();
    }
    public function deleteRole(Player $actor, string $roleId) : void{
        $id=$this->require($actor,'manage_roles'); $this->idle($id); $c=&$this->p->db->clans[$id];
        if(in_array($roleId,['leader','member','deputy'],true) || !isset($c['roles'][$roleId]) || $c['roles'][$roleId]['priority'] >= $this->role($id,$actor->getName())['priority']) throw new \DomainException('Нельзя удалить эту роль.');
        foreach($c['members'] as &$m) if($m['role']===$roleId) $m['role']='member'; unset($m);
        foreach($c['regions'] as &$r) unset($r['roles'][$roleId]); unset($r);
        if($c['storageMinRole']===$roleId) $c['storageMinRole']='member';
        $this->log($id,$actor->getName(),'удалил роль §5'.$c['roles'][$roleId]['name']); unset($c['roles'][$roleId]); $this->p->db->save();
    }
    public function transferLeader(Player $actor, string $target) : void{
        $id=$this->require($actor); $this->idle($id); $c=&$this->p->db->clans[$id]; $key=mb_strtolower($target);
        if($c['leader']!==mb_strtolower($actor->getName()) || !isset($c['members'][$key]) || $key===$c['leader']) throw new \DomainException('Передача лидерства недоступна.');
        $c['members'][$c['leader']]['role']='member'; $c['members'][$key]['role']='leader'; $c['leader']=$key;
        $this->log($id,$actor->getName(),'передал лидерство игроку §5'.$target); $this->p->db->save();
    }
    public function remove(Player $actor, string $target) : void{
        $id=$this->manageTarget($actor,$target,'kick');
        $online=$this->p->getServer()->getPlayerExact($target);if($online!==null)$this->p->closeStorage($online);
        $this->log($id,$actor->getName(),'исключил игрока §5'.$target); unset($this->p->db->clans[$id]['members'][mb_strtolower($target)]); $this->p->db->save();
    }
    public function leave(Player $actor) : void{
        $id=$this->require($actor); $this->idle($id); $this->p->closeStorage($actor);$c=&$this->p->db->clans[$id]; $key=mb_strtolower($actor->getName());
        if($c['leader']===$key){
            $this->p->db->runtime['deletedClans'][$id]=$c; // audit/recovery archive, including storage
            unset($this->p->db->clans[$id]);
            $this->p->matches->cancelRequests($id);
        }else{$this->log($id,$actor->getName(),'покинул клан');unset($c['members'][$key]);}
        $this->p->db->save();
    }
    public function rating(string $id, int $delta) : void{
        $c=&$this->p->db->clans[$id]; $c['rating']+=$delta;
        if(!$this->p->cfg('ranking.allow-negative',false)) $c['rating']=max(0,$c['rating']);
    }
    public function xp(string $id, int $amount) : void{
        $before=$this->p->db->clans[$id]['level'];
        Rules::xp($this->p->db->clans[$id],$amount,min(5,max(1,(int)$this->p->cfg('levels.maximum',5))),max(1,(int)$this->p->cfg('levels.xp-per-level',1000)));
        if($before!==$this->p->db->clans[$id]['level']) $this->log($id,'Система','повысила уровень клана до §5'.$this->p->db->clans[$id]['level']);
    }
    public function eventXp(string $id, string $eventId, int $amount) : bool{
        if(!isset($this->p->db->clans[$id]) || isset($this->p->db->runtime['xpEvents'][$id][$eventId]) || $amount<1 || $amount>10000) return false;
        $this->p->db->transact(function()use($id,$eventId,$amount):void{$this->p->db->runtime['xpEvents'][$id][$eventId]=time();$this->xp($id,$amount);$this->log($id,'Система','начислила §5'.$amount.' XP §fза клановое событие');}); return true;
    }
}
