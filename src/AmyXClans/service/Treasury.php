<?php
declare(strict_types=1);
namespace AmyXClans\service;
use AmyXClans\Main;
use Amyx\Economy\api\EconomyAPI;
use pocketmine\player\Player;

final class Treasury{
    public function __construct(private Main $p){
        if(!method_exists(EconomyAPI::class,'applyExternalOperation')) throw new \RuntimeException('Установите AmyxEconomy 1.0.2 из комплекта: нужен идемпотентный API переводов.');
        $this->recover();
    }
    public function locked(string $clan) : bool{
        foreach($this->p->db->runtime['finance']??[] as $op) if($op['clan']===$clan && $op['state']==='pending') return true;
        return false;
    }
    public function transfer(Player $player, int $amount, bool $deposit) : void{
        $id=$this->p->clan->require($player, $deposit?'deposit':'withdraw');
        if($amount<1 || $amount>2_000_000_000) throw new \DomainException('Введите целое число монет от 1 до 2000000000.');
        if($this->locked($id)) throw new \DomainException('Предыдущий перевод ещё восстанавливается.');
        $this->p->clan->idle($id);
        $c=$this->p->db->clans[$id];
        if(!$deposit && ($c['debt']>0 || $c['bank']<$amount)) throw new \DomainException('Недостаточно свободных монет в казне.');
        $opId='amyxclans:'.bin2hex(random_bytes(16));
        $this->p->db->runtime['finance'][$opId]=['state'=>'pending','clan'=>$id,'player'=>$player->getName(),'amount'=>$amount,'deposit'=>$deposit];
        $this->p->db->save();
        $this->apply($opId);
    }
    private function apply(string $id) : void{
        $op=$this->p->db->runtime['finance'][$id];
        if($op['state']!=='pending') return;
        $ok=EconomyAPI::getInstance()->applyExternalOperation($id,$op['player'],$op['deposit']?-$op['amount']:$op['amount']);
        $this->p->db->transact(function()use($id,$op,$ok):void{
            if($ok){
                if(!isset($this->p->db->clans[$op['clan']])) throw new \RuntimeException('Клан с незавершённым переводом исчез. Требуется восстановить резервную копию.');
                $c=&$this->p->db->clans[$op['clan']];
                if($op['deposit']) Rules::deposit($c,$op['amount']); else $c['bank']-=$op['amount'];
                $this->p->clan->log($op['clan'],$op['player'],($op['deposit']?'внёс в казну ':'снял из казны ').$op['amount'].' монет');
            }
            $this->p->db->runtime['finance'][$id]['state']=$ok?'done':'rejected';
        });
        if(!$ok) throw new \DomainException('Недостаточно монет или достигнут лимит личного баланса.');
    }
    public function recover() : void{
        foreach($this->p->db->runtime['finance']??[] as $id=>$op) if($op['state']==='pending'){
            try{$this->apply($id);}catch(\DomainException){} // rejected operations are durably marked
        }
    }
    public function spend(string $id, string $actor, int $amount, string $reason, callable $change) : void{
        if($amount<0 || $this->locked($id)) throw new \DomainException('Казна временно занята.');
        $this->p->db->transact(function()use($id,$actor,$amount,$reason,$change):void{
            $c=&$this->p->db->clans[$id];
            if($c['debt']>0 || $c['bank']<$amount) throw new \DomainException('Недостаточно монет в казне или есть долг.');
            $change(); $c['bank']-=$amount;
            $this->p->clan->log($id,$actor,$reason.' за '.$amount.' монет');
        });
    }
}
