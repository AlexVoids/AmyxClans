<?php
declare(strict_types=1);
namespace AmyXClans\listener;
use AmyXClans\Main;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockExplodeEvent;
final class ModernProtection implements Listener{
    public function __construct(private Main $p){}
    /** @priority HIGHEST */
    public function explode(BlockExplodeEvent $e) : void{
        $safe=fn($b)=>$this->p->regions->at($b->getPosition())===null && $this->p->matches->at($b->getPosition())===null;
        $e->setAffectedBlocks(array_values(array_filter($e->getAffectedBlocks(),$safe)));
        $e->setIgnitions(array_values(array_filter($e->getIgnitions(),$safe)));
    }
}
