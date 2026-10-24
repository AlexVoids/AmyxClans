<?php
declare(strict_types=1);
namespace AmyXClans\storage;
use AmyXClans\service\Rules;

final class Migration{
    public static function run(Database $db, array $config) : void{
        if(($db->runtime['schema'] ?? 0) >= 4) return;
        $db->backup();
        $seen = [];
        foreach($db->clans as $id=>&$c){
            $c['id'] = (string)$id;
            $c['roles'] = (array)($c['roles'] ?? []);
            $legacy = (int)($c['schemaVersion'] ?? 0) < 4;
            foreach($c['roles'] as $rid=>&$role){
                $p = (int)($role['priority'] ?? 0);
                $role['priority'] = $rid === 'leader' ? 10 : ($legacy ? max(1,min(9,(int)ceil($p*9/99))) : max(1,min(9,$p)));
                $role['permissions'] = array_values(array_intersect((array)($role['permissions'] ?? []), array_keys(Rules::RIGHTS)));
                if(in_array('manage_roles',$role['permissions'],true) && !in_array('change_roles',$role['permissions'],true)) $role['permissions'][] = 'change_roles';
            }unset($role);
            foreach(Rules::roles() as $rid=>$default){
                if(!isset($c['roles'][$rid]) || $rid === 'leader') $c['roles'][$rid] = $default;
            }
            $c['members'] = (array)($c['members'] ?? []);
            foreach($c['members'] as $key=>&$m){
                if(isset($seen[$key])) throw new \RuntimeException('Игрок в двух кланах: ' . $key . '. Исправьте копию данных, оригинал сохранён.');
                $seen[$key] = $id;
                $m += ['name'=>(string)$key,'role'=>'member','kills'=>0,'deaths'=>0,'joinedAt'=>time()];
                if(!isset($c['roles'][$m['role']])) $m['role']='member';
                if($key === ($c['leader'] ?? '')) $m['role']='leader';elseif($m['role']==='leader')$m['role']='member';
            }unset($m);
            if(!isset($c['members'][$c['leader'] ?? ''])) throw new \RuntimeException('У клана ' . $id . ' отсутствует лидер. Данные не изменены.');
            $oldRating=max(0,(int)($c['kills']??0)*(int)($config['rating']['kill-weight']??10)-(int)($c['deaths']??0)*(int)($config['rating']['death-weight']??3)+count($c['members'])*(int)($config['rating']['member-weight']??5));
            $c += ['name'=>(string)$id,'createdAt'=>time(),'bank'=>0,'memberLimit'=>20,'storage'=>[],'storageMinRole'=>'member','history'=>[],'purchasedIcons'=>[],'icon'=>'','kills'=>0,'deaths'=>0,'pvp'=>false,'visible'=>true,'invites'=>[],'requests'=>[],'channels'=>['general'=>['name'=>'общий','priority'=>1]],'level'=>1,'xp'=>0,'rating'=>$oldRating,'debt'=>0,'regions'=>[],'joinSeen'=>[],'settlements'=>[]];
            $c['bank']=max(0,(int)$c['bank']); $c['debt']=max(0,(int)$c['debt']);
            foreach($c['members'] as $key=>$m) $c['joinSeen']['name:'.$key]=true;
            if($legacy){
                foreach($c['channels'] as &$channel) $channel['priority']=max(1,min(10,(int)ceil((int)($channel['priority']??0)/10))); unset($channel);
                $c['legacyStorage']=(array)$c['storage'];
                $homes = (array)($c['homes'] ?? []);
                $c['home'] = $homes === [] ? null : reset($homes);
                $c['legacyHomes'] = $homes;
                $c['homes'] = []; // lossless archive, exactly one usable home
            }
            $c['schemaVersion']=4;
        }unset($c);
        $db->runtime['schema']=4;
        $db->save();
    }
}
