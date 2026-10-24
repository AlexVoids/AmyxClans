<?php
declare(strict_types=1);
namespace AmyXClans\battle;
use AmyXClans\Main;
use AmyXClans\service\Rules;
use pocketmine\player\{Player,GameMode};
use pocketmine\world\Position;
use pocketmine\scheduler\ClosureTask;

final class MatchManager{
    public bool $internalTeleport=false;
    public function __construct(private Main $p){}
    public function all() : array{return $this->p->db->runtime['matches']??[];}
    private function ongoing(array $m) : bool{return !in_array($m['phase'],['finished','aborted'],true);}
    public function clanBusy(string $id) : bool{foreach($this->all() as $m)if($this->ongoing($m) && in_array($id,$m['teams'],true))return true;return false;}
    public function arenaBusy(string $id) : bool{
        $target=$this->p->arenas->get($id);
        foreach($this->all() as $m)if($this->ongoing($m)){
            if($m['arena']['id']===$id)return true;
            if(isset($target['bounds']) && Rules::overlaps($target['bounds'],$m['arena']['bounds']) && $target['bounds']['minY']<=$m['arena']['bounds']['maxY'] && $target['bounds']['maxY']>=$m['arena']['bounds']['minY'])return true;
        }return false;
    }
    public function playerMatch(Player $p,bool $aliveOnly=false) : ?string{
        $uuid=$p->getUniqueId()->toString();foreach($this->all() as $id=>$m)if($this->ongoing($m) && isset($m['players'][$uuid]) && (!$aliveOnly || $m['players'][$uuid]['alive']))return (string)$id;return null;
    }
    public function at(Position $pos) : ?string{
        foreach($this->all() as $id=>$m)if($this->ongoing($m) && Rules::contains($m['arena']['bounds'],$pos->getWorld()->getFolderName(),$pos->x,$pos->z,$pos->y))return (string)$id;return null;
    }
    public function canBuild(Player $p,Position $pos) : bool{
        $at=$this->at($pos);$own=$this->playerMatch($p,true);
        if($own!==null){$m=$this->all()[$own];return $at===$own && $m['phase']==='active' && !$this->special($m,$pos) && !$this->boundary($m,$pos);}
        return $at===null;
    }
    public function special(array $m,Position $pos) : bool{
        foreach($m['arena']['chests']??[] as $c)if($c['world']===$pos->getWorld()->getFolderName() && (int)floor($c['x'])===(int)floor($pos->x) && (int)floor($c['y'])===(int)floor($pos->y) && (int)floor($c['z'])===(int)floor($pos->z))return true;
        return false;
    }
    public function boundary(array $m,Position $p) : bool{
        $b=$m['arena']['bounds'];return (int)floor($p->x)===$b['minX'] || (int)floor($p->x)===$b['maxX'] || (int)floor($p->z)===$b['minZ'] || (int)floor($p->z)===$b['maxZ'] || (int)floor($p->y)===$b['minY'];
    }
    private function eligible(string $id) : void{
        $c=$this->p->db->clans[$id]??null;
        if($c===null || $c['level']<(int)$this->p->cfg('war.minimum-level',2) || count($c['regions'])<(int)$this->p->cfg('war.minimum-regions',4))throw new \DomainException('Для битвы нужны уровень '.(int)$this->p->cfg('war.minimum-level',2).' и минимум '.(int)$this->p->cfg('war.minimum-regions',4).' региона.');
        $this->p->clan->idle($id);
    }
    public function request(Player $actor,string $enemy,string $mode,int $size) : void{
        if(!$this->p->cfg('features.wars',true) || ($mode==='siege' && !$this->p->cfg('features.siege',true)))throw new \DomainException('Режим отключён.');
        $id=$this->p->clan->require($actor,'wars');if($id===$enemy)throw new \DomainException('Выберите другой клан.');
        if(!in_array($size,(array)$this->p->cfg('war.team-sizes',[3,5,10,15]),true) || !in_array($size,[3,5,10,15],true) || !in_array($mode,['arena','siege'],true))throw new \DomainException('Недопустимый режим/размер команды.');
        $this->eligible($id);$this->eligible($enemy);
        $pair=[$id,$enemy];sort($pair);$pair=implode(':',$pair);
        if(time()-(int)($this->p->db->runtime['lastOpponents'][$pair]??0)<max(0,(int)$this->p->cfg('war.rematch-cooldown-seconds',1800)))throw new \DomainException('Повторная битва с этим кланом пока недоступна: защита от накрутки.');
        foreach($this->p->db->runtime['requests']??[] as $r)if($r['expires']>=time() && in_array($id,[$r['a'],$r['b']],true) && in_array($enemy,[$r['a'],$r['b']],true))throw new \DomainException('Заявка между этими кланами уже существует.');
        $rid=bin2hex(random_bytes(8));$this->p->db->runtime['requests'][$rid]=['a'=>$id,'b'=>$enemy,'mode'=>$mode,'size'=>$size,'expires'=>time()+(int)$this->p->cfg('war.request-seconds',86400)];
        foreach([$id,$enemy] as $cid)$this->p->clan->log($cid,$actor->getName(),'отправил заявку на битву §5'.$rid.' §f('.$mode.', '.$size.'×'.$size.')');
        $this->p->db->save();$this->notifyClan($enemy,'Поступила заявка на клановую битву. Откройте /clan → Клановые битвы.');
    }
    public function respond(Player $actor,string $rid,bool $accept) : void{
        $id=$this->p->clan->require($actor,'wars');$r=$this->p->db->runtime['requests'][$rid]??null;
        if($r===null || $r['b']!==$id || $r['expires']<time())throw new \DomainException('Заявка больше недоступна.');
        if(!$accept){unset($this->p->db->runtime['requests'][$rid]);$this->p->clan->log($id,$actor->getName(),'отклонил заявку на битву');$this->p->db->save();return;}
        $this->eligible($r['a']);$this->eligible($r['b']);
        $arena=$this->p->arenas->select($r['mode'],$r['size'],$r['b']);if($arena===null)throw new \DomainException('Нет свободной настроенной арены с достаточным числом стартовых точек.');
        $world=$this->p->arenas->world($arena['bounds']['world']);
        foreach($world->getPlayers() as $player)if(Rules::contains($arena['bounds'],$arena['bounds']['world'],$player->getPosition()->x,$player->getPosition()->z,$player->getPosition()->y))throw new \DomainException('На арене находятся посторонние игроки.');
        foreach($world->getEntities() as $entity)if($entity instanceof \pocketmine\entity\object\ItemEntity && !$entity->isFlaggedForDespawn() && Rules::contains($arena['bounds'],$arena['bounds']['world'],$entity->getPosition()->x,$entity->getPosition()->z,$entity->getPosition()->y))throw new \DomainException('Уберите посторонние выпавшие вещи с арены.');
        $mid=bin2hex(random_bytes(12));
        $this->p->db->transact(function()use($mid,$r,$arena,$rid,$actor):void{
            $this->p->db->runtime['matches'][$mid]=['id'=>$mid,'phase'=>'initializing','teams'=>['a'=>$r['a'],'b'=>$r['b']],'mode'=>$r['mode'],'size'=>$r['size'],'arena'=>$arena,'players'=>[],'deadline'=>time()+(int)$this->p->cfg('war.collect-seconds',600),'created'=>time(),'totems'=>[],'rewarded'=>false];
            unset($this->p->db->runtime['requests'][$rid]);
            foreach([$r['a'],$r['b']] as $cid)$this->p->clan->log($cid,$actor->getName(),'принял битву §5'.$mid.'§f. Сбор команды начат');
        });
        $this->p->arenas->pin($mid,$arena['bounds']);
        $this->p->snapshots->capture($mid,$arena['bounds'],function()use($mid):void{
            $m=&$this->p->db->runtime['matches'][$mid];if($m['phase']!=='initializing')return;
            $this->p->snapshots->wall($m['arena'],true);$m['phase']='collecting';$this->p->db->save();$this->broadcast($m,'Сбор участников: 10 минут. Войти можно через /clan.');
        });
    }
    public function cancelRequests(string $id) : void{foreach($this->p->db->runtime['requests']??[] as $rid=>$r)if($r['a']===$id || $r['b']===$id)unset($this->p->db->runtime['requests'][$rid]);}
    public function join(Player $player,string $mid) : void{
        $clan=$this->p->clan->require($player);$uuid=$player->getUniqueId()->toString();$m=&$this->p->db->runtime['matches'][$mid];
        if(!is_array($m) || $m['phase']!=='collecting' || !in_array($clan,$m['teams'],true) || isset($m['players'][$uuid]) || $this->playerMatch($player)!==null)throw new \DomainException('Войти в эту битву сейчас нельзя.');
        if(!$player->isAlive())throw new \DomainException('Сначала возродитесь.');
        $side=array_search($clan,$m['teams'],true);$occupied=[];foreach($m['players'] as $data)if($data['side']===$side)$occupied[]=$data['spawn'];
        $slot=null;for($i=0;$i<$m['size'];++$i)if(!in_array($i,$occupied,true)){$slot=$i;break;}if($slot===null)throw new \DomainException('Команда уже собрана.');
        $spawn=$m['arena']['spawns'][$side][$slot];
        $this->p->closeStorage($player);$player->removeCurrentWindow();
        $ticket=['clan'=>$clan,'exit'=>$m['arena']['exit'],'gamemode'=>$player->getGamemode()->getEnglishName(),'match'=>$mid];
        $this->p->db->runtime['returns'][$uuid]=$ticket;
        $m['players'][$uuid]=['name'=>$player->getName(),'side'=>$side,'spawn'=>$slot,'alive'=>true,'joined'=>time()];$this->p->db->save();
        try{
            $player->setGamemode(GameMode::SURVIVAL());$this->teleport($player,$spawn);$player->save();
        }catch(\Throwable $e){unset($m['players'][$uuid]);$this->returnPlayer($player);$this->p->db->save();throw new \DomainException('Не удалось войти в битву: '.$e->getMessage());}
        if($this->count($m,'a')===$m['size'] && $this->count($m,'b')===$m['size']){$m['phase']='preparing';$m['deadline']=time()+(int)$this->p->cfg('war.prepare-seconds',300);$this->broadcast($m,'Команды собраны. Началась подготовка.');}
        $this->p->db->save();
    }
    public function teleport(Player $player,array $pos) : void{
        $destination=$this->p->arenas->pos($pos);$this->internalTeleport=true;
        try{if(!$player->teleport($destination))throw new \DomainException('Телепортация отклонена сервером.');}finally{$this->internalTeleport=false;}
    }
    private function count(array $m,string $side) : int{return count(array_filter($m['players'],fn($p)=>$p['side']===$side && $p['alive']));}
    public function tick(?int $now=null) : void{
        $now??=time();
        foreach($this->all() as $mid=>$copy){
            if(!$this->ongoing($copy))continue;
            if(in_array($copy['phase'],['restoring','settled'],true)){if(!$this->p->snapshots->pending($mid))$this->restore($mid);continue;}
            if($copy['phase']==='initializing')continue;
            $m=&$this->p->db->runtime['matches'][$mid];
            if($this->p->arenas->world($m['arena']['bounds']['world'])===null){$this->abort($mid,'Мир арены недоступен');continue;}
            foreach($m['players'] as $uuid=>$data){
                $player=$this->p->getServer()->getPlayerExact($data['name']);
                if($data['alive'] && ($player===null || !$this->p->authenticated($player))){$this->disconnectUuid($mid,$uuid);}
            }
            $left=max(0,$m['deadline']-$now);
            foreach($m['players'] as $data)if($data['alive'])$this->p->getServer()->getPlayerExact($data['name'])?->sendActionBarMessage('§5'.match($m['phase']){'collecting'=>'Сбор','preparing'=>'Подготовка',default=>'Битва'}.' §f'.intdiv($left,60).':'.str_pad((string)($left%60),2,'0',STR_PAD_LEFT));
            if($m['phase']==='active' && $m['mode']==='arena' && ($this->count($m,'a')===0 || $this->count($m,'b')===0)){
                $this->finish($mid,$this->count($m,'a')===0?($this->count($m,'b')===0?null:'b'):'a');continue;
            }
            if($now<$m['deadline'])continue;
            if($m['phase']==='collecting'){
                $missing=[];foreach(['a','b'] as $side)if($this->count($m,$side)<$m['size'])$missing[]=$side;
                if(count($missing)===2 && $this->p->cfg('war.both-incomplete','technical-both')==='cancel'){$this->abort($mid,'Обе команды не собраны');continue;}
                $winner=count($missing)===1?($missing[0]==='a'?'b':'a'):null;
                $this->finish($mid,$winner,$missing);continue;
            }
            if($m['phase']==='preparing'){
                $this->p->snapshots->wall($m['arena'],false);$m['phase']='active';$m['deadline']=$now+(int)$this->p->cfg($m['mode']==='siege'?'war.siege-seconds':'war.fight-seconds',1800);
                if($m['mode']==='siege')$this->p->totems->initialize($mid);
                $this->p->db->save();$this->broadcast($m,'Битва началась!');continue;
            }
            if($m['phase']==='active'){
                if($m['mode']==='arena'){$a=$this->count($m,'a');$b=$this->count($m,'b');$winner=$a===$b?null:($a>$b?'a':'b');}
                else $winner=match($this->p->cfg('war.siege-timeout','defenders')){'attackers'=>'a','draw'=>null,default=>'b'};
                $this->finish($mid,$winner);
            }
        }
        // Deferred login/respawn/crash return tickets, without restoring any inventory snapshot.
        foreach($this->p->getServer()->getOnlinePlayers() as $p){
            $uuid=$p->getUniqueId()->toString();$ticket=$this->p->db->runtime['returns'][$uuid]??null;
            if($ticket===null || !$this->p->authenticated($p) || !$p->isAlive())continue;
            $m=$this->all()[$ticket['match']]??null;
            if($m===null || !in_array($m['phase'],['collecting','preparing','active'],true) || !($m['players'][$uuid]['alive']??false))$this->returnPlayer($p);
        }
        foreach($this->p->db->runtime['requests']??[] as $rid=>$r)if($r['expires']<$now || !isset($this->p->db->clans[$r['a']],$this->p->db->clans[$r['b']]))unset($this->p->db->runtime['requests'][$rid]);
    }
    public function death(Player $victim,?Player $killer) : void{
        $mid=$this->playerMatch($victim,true);if($mid===null)return;$m=&$this->p->db->runtime['matches'][$mid];$uuid=$victim->getUniqueId()->toString();
        $this->p->totems->dropCarrier($victim);$m['players'][$uuid]['alive']=false;
        if($m['phase']==='active' && $killer!==null){
            $kuuid=$killer->getUniqueId()->toString();$k=$m['players'][$kuuid]??null;
            if($k!==null && $k['alive'] && $k['side']!==$m['players'][$uuid]['side']){
                $pair=$kuuid.':'.$uuid;$last=(int)($this->p->db->runtime['killPairs'][$pair]??0);
                if(time()-$last>=(int)$this->p->cfg('levels.kill-pair-cooldown',300) && ($this->p->cfg('war.kill-same-ip-xp',false) || $killer->getNetworkSession()->getIp()!==$victim->getNetworkSession()->getIp())){
                    $this->p->db->runtime['killPairs'][$pair]=time();$this->p->clan->xp($m['teams'][$k['side']],max(0,(int)$this->p->cfg('levels.kill-xp',25)));
                }
            }
        }
        $this->p->db->save();
        // Finish after the server has applied death drops and cleared the player's inventory.
        $this->p->getScheduler()->scheduleDelayedTask(new ClosureTask(function()use($mid):void{
            $m=$this->all()[$mid]??null;if($m!==null && $m['phase']==='active' && $m['mode']==='arena' && ($this->count($m,'a')===0 || $this->count($m,'b')===0))$this->finish($mid,$this->count($m,'a')===0?($this->count($m,'b')===0?null:'b'):'a');
        }),1);
    }
    public function quit(Player $player) : void{
        $mid=$this->playerMatch($player,true);if($mid===null)return;
        $this->p->totems->dropCarrier($player);$this->disconnectUuid($mid,$player->getUniqueId()->toString());$this->p->db->save();
    }
    private function disconnectUuid(string $mid,string $uuid) : void{
        $m=&$this->p->db->runtime['matches'][$mid];
        if($m['phase']==='collecting')unset($m['players'][$uuid]);else $m['players'][$uuid]['alive']=false;
    }
    public function returnPosition(Player $p) : Position{
        $ticket=$this->p->db->runtime['returns'][$p->getUniqueId()->toString()]??null;
        $home=$ticket===null?null:($this->p->db->clans[$ticket['clan']]['home']??null);
        if($home!==null){try{$pos=$this->p->arenas->pos($home);if($this->at($pos)===null)return $pos;}catch(\DomainException){}}
        // User specification chooses server spawn when no home is available.
        return $this->p->getServer()->getWorldManager()->getDefaultWorld()->getSafeSpawn();
    }
    public function returnPlayer(Player $p) : void{
        $uuid=$p->getUniqueId()->toString();$ticket=$this->p->db->runtime['returns'][$uuid]??null;if($ticket===null || !$p->isAlive())return;
        $this->p->totems->purgePlayer($p);$pos=$this->returnPosition($p);
        $this->teleport($p,$this->p->arenas->position($pos));
        $mode=GameMode::fromString($ticket['gamemode']);if($mode!==null)$p->setGamemode($mode);
        $p->save();unset($this->p->db->runtime['returns'][$uuid]);$this->p->db->save();
    }
    public function finish(string $mid,?string $winner,array $technical=[]) : void{
        $copy=$this->all()[$mid]??null;if($copy===null || !in_array($copy['phase'],['collecting','preparing','active'],true))return;
        $this->p->db->transact(function()use($mid,$winner,$technical):void{
            $m=&$this->p->db->runtime['matches'][$mid];$active=$m['phase']==='active';$m['phase']='settled';$m['winner']=$winner;$m['technical']=$technical;$m['ended']=time();
            foreach($m['teams'] as $side=>$id){
                if(!isset($this->p->db->clans[$id]) || isset($this->p->db->clans[$id]['settlements'][$mid]))continue;
                $c=&$this->p->db->clans[$id];$c['settlements'][$mid]=true;
                if(in_array($side,$technical,true)){
                    Rules::fine($c,max(0,(int)$this->p->cfg('war.technical-fine',10000)));$this->p->clan->rating($id,(int)$this->p->cfg('ranking.technical-loss',-30));$result='техническое поражение';
                }elseif($winner===null){$this->p->clan->rating($id,(int)$this->p->cfg('ranking.draw',0));$result='ничья';}
                elseif($winner===$side){$this->p->clan->rating($id,(int)$this->p->cfg('ranking.win',40));if($active)$this->p->clan->xp($id,max(0,(int)$this->p->cfg('levels.win-xp',300)));$result='победа';}
                else{$this->p->clan->rating($id,(int)$this->p->cfg('ranking.loss',-40));$result='поражение';}
                if($active){$participants=count(array_filter($m['players'],fn($data)=>$data['side']===$side));$this->p->clan->xp($id,$participants*max(0,(int)$this->p->cfg('levels.participation-xp',20)));}
                $this->p->clan->log($id,'Система','завершила битву §5'.$mid.'§f: §5'.$result);
            }
            if($m['mode']==='siege' && $winner==='a' && isset($m['arena']['region']) && $this->p->cfg('war.siege-transfer-region',false)){
                $binding=$m['arena']['region'];$from=$binding['clan'];$rid=$binding['id'];$to=$m['teams']['a'];$region=$this->p->db->clans[$from]['regions'][$rid]??null;
                if($region!==null){
                    [$limit,$size]=$this->p->regions->limits($to);
                    if(!($region['insured']??false) && count($this->p->db->clans[$to]['regions'])<$limit && $region['baseSize']<=$size){
                        $region['roles']=array_fill_keys(array_keys($this->p->db->clans[$to]['roles']),true);
                        $newId='captured_'.substr($mid,0,12);$this->p->db->clans[$to]['regions'][$newId]=$region;unset($this->p->db->clans[$from]['regions'][$rid]);
                        $this->p->clan->log($from,'Система','передала регион победителю осады');$this->p->clan->log($to,'Система','получен регион §5'.$region['name']);
                        // The OP must rebind the arena to its new defender before the next territory siege.
                    }else $this->p->clan->log($to,'Система','регион не передан: страхование или лимит территории победителя');
                }
            }
            $pair=array_values($m['teams']);sort($pair);$this->p->db->runtime['lastOpponents'][implode(':',$pair)]=time();
            $m['rewarded']=true;
        });
        $this->broadcast($this->all()[$mid],'Битва завершена. '.($winner===null?'Победитель не определён.':'Победил клан §5'.$this->p->db->clans[$copy['teams'][$winner]]['name']));
        $this->restore($mid);
    }
    public function abort(string $mid,string $reason) : void{
        $m=$this->all()[$mid]??null;if($m===null || !$this->ongoing($m))return;
        $this->p->db->runtime['matches'][$mid]['abortReason']=$reason;
        foreach($m['teams'] as $id)$this->p->clan->log($id,'Система','прервала битву: '.$reason);
        $this->p->db->save();$this->restore($mid);
    }
    private function restore(string $mid) : void{
        $m=&$this->p->db->runtime['matches'][$mid];$this->p->arenas->pin($mid,$m['arena']['bounds']);$m['phase']='restoring';$this->p->db->save();
        $this->p->totems->cleanup($mid);
        $world=$this->p->arenas->world($m['arena']['bounds']['world']);
        if($world!==null){
            $exit=$this->p->arenas->pos($m['arena']['exit']);
            foreach($world->getEntities() as $entity)if($entity instanceof \pocketmine\entity\object\ItemEntity && !$entity->isFlaggedForDespawn() && Rules::contains($m['arena']['bounds'],$world->getFolderName(),$entity->getPosition()->x,$entity->getPosition()->z,$entity->getPosition()->y)){
                if($this->p->totems->marker($entity->getItem())!==null)$entity->flagForDespawn();else $entity->teleport($exit);
            }
        }
        foreach($m['players'] as $data){$player=$this->p->getServer()->getPlayerExact($data['name']);if($player!==null && $player->isAlive())$this->returnPlayer($player);}
        if($this->p->snapshots->pending($mid))return; // capture callback/next recovery will retry; never discard a partial map
        if(!$this->p->snapshots->exists($mid)){
            // Initializing matches have not changed the world until a complete snapshot exists.
            if(isset($m['abortReason'])){$m['phase']='aborted';$this->p->db->save();$this->p->arenas->release($mid);return;}
            throw new \RuntimeException('Нельзя восстановить матч без снимка '.$mid);
        }
        if($world===null)return; // durable reservation remains; retry when the world is available
        $this->p->snapshots->restore($mid,function()use($mid):void{
            $m=&$this->p->db->runtime['matches'][$mid];$m['phase']=isset($m['abortReason'])?'aborted':'finished';$this->p->db->save();$this->p->snapshots->forget($mid);$this->p->arenas->release($mid);
        });
    }
    public function recover() : void{
        foreach($this->all() as $id=>$m)if($this->ongoing($m)){
            if(in_array($m['phase'],['settled','restoring'],true))$this->restore((string)$id);else $this->abort((string)$id,'Перезапуск сервера: бой закрыт без повторных наград');
        }
    }
    public function shutdown() : void{
        foreach($this->all() as $id=>$m)if($this->ongoing($m)){
            $this->p->db->runtime['matches'][$id]['phase']='restoring';
            if(!($m['rewarded']??false))$this->p->db->runtime['matches'][$id]['abortReason']='Перезапуск сервера';
            $this->p->db->save();
            $this->p->totems->cleanup((string)$id);
            foreach($m['players'] as $data){$p=$this->p->getServer()->getPlayerExact($data['name']);if($p!==null && $p->isAlive())$this->returnPlayer($p);}
            $this->p->arenas->release((string)$id);
            // Recovery on next boot restores the durable snapshot; do not discard the match.
        }
    }
    public function emergencyStop() : void{
        foreach($this->all() as $id=>$m)if($this->ongoing($m) && !in_array($m['phase'],['restoring','settled'],true)){
            $this->p->db->runtime['matches'][$id]['phase']='restoring';$this->p->db->runtime['matches'][$id]['abortReason']='Ошибка сервера: требуется восстановление';
        }
        $this->p->db->save();
    }
    private function notifyClan(string $id,string $text) : void{foreach($this->p->getServer()->getOnlinePlayers() as $p)if($this->p->clan->id($p->getName())===$id)$this->p->message($p,$text);}
    private function broadcast(array $m,string $text) : void{foreach($m['teams'] as $id)$this->notifyClan($id,$text);}
}
