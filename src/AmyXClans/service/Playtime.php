<?php
declare(strict_types=1);
namespace AmyXClans\service;
use AmyXClans\Main;
use pocketmine\player\Player;
final class Playtime{
    private array $last=[];
    public function __construct(private Main $p){}
    public function tick() : void{
        $now=microtime(true);
        foreach($this->p->getServer()->getOnlinePlayers() as $p){
            $uuid=$p->getUniqueId()->toString();
            if(!$this->p->authenticated($p)){unset($this->last[$uuid]);continue;}
            $elapsed=min(10,max(0,$now-($this->last[$uuid]??$now))); $this->last[$uuid]=$now;
            $row=&$this->p->db->runtime['playtime'][$uuid];
            $row??=['seconds'=>0,'name'=>$p->getName()];
            $row['seconds']+=$elapsed; $row['name']=$p->getName();
        }
    }
    public function seconds(Player $p) : int{
        $local=(int)($this->p->db->runtime['playtime'][$p->getUniqueId()->toString()]['seconds']??0);
        $stats=$this->p->getServer()->getPluginManager()->getPlugin('AmyxStats');
        if($stats!==null && $stats->isEnabled() && method_exists($stats,'getStatistic')) $local=max($local,(int)$stats->getStatistic($p,'playtime_seconds'));
        return $local;
    }
    public function eligible(Player $p) : void{
        if($this->seconds($p)<(int)$this->p->cfg('minimum-playtime-seconds',1200)) throw new \DomainException('Для вступления в клан нужно провести на сервере '.ceil((int)$this->p->cfg('minimum-playtime-seconds',1200)/60).' минут.');
    }
}
