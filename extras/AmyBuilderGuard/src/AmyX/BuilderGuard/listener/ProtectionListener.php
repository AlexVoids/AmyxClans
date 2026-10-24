<?php

declare(strict_types=1);

namespace AmyX\BuilderGuard\listener;

use AmyX\BuilderGuard\Main;
use pocketmine\block\tile\Container;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockBurnEvent;
use pocketmine\event\block\BlockFormEvent;
use pocketmine\event\block\BlockGrowEvent;
use pocketmine\event\block\BlockMeltEvent;
use pocketmine\event\block\BlockSpreadEvent;
use pocketmine\event\block\LeavesDecayEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\entity\EntityTrampleFarmlandEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\event\player\PlayerBedEnterEvent;
use pocketmine\event\player\PlayerBucketEmptyEvent;
use pocketmine\event\player\PlayerBucketFillEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerItemConsumeEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerToggleFlightEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\player\Player;

final class ProtectionListener implements Listener{
    public function __construct(private Main $plugin){
    }

    private function playerAction(Player $player, string $flag, int $x, int $y, int $z) : bool{
        $world = $player->getWorld()->getFolderName();
        if($this->plugin->actionAllowed($player, $flag, $world, $x, $y, $z)){
            return true;
        }
        $this->plugin->deny($player, $world, $x, $y, $z);
        return false;
    }

    public function onContainerInteract(\pocketmine\event\player\PlayerInteractEvent $event) : void{
        if($event->isCancelled() || $event->getAction() !== \pocketmine\event\player\PlayerInteractEvent::RIGHT_CLICK_BLOCK){
            return;
        }
        $block = $event->getBlock();
        $pos = $block->getPosition();
        if($pos->getWorld()->getTileAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) instanceof Container){
            if(!$this->playerAction($event->getPlayer(), 'container', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
                $event->cancel();
            }
        }
    }

    public function onDrop(PlayerDropItemEvent $event) : void{
        $p = $event->getPlayer(); $pos = $p->getPosition();
        if(!$this->playerAction($p, 'item-drop', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
    }

    public function onPickup(EntityItemPickupEvent $event) : void{
        $collector = $event->getEntity();
        if($collector instanceof Player){
            $pos = $collector->getPosition();
            if(!$this->playerAction($collector, 'item-pickup', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
        }
    }

    public function onItemUse(PlayerItemUseEvent $event) : void{
        $p = $event->getPlayer(); $pos = $p->getPosition();
        if(!$this->playerAction($p, 'item-use', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
    }

    public function onItemConsume(PlayerItemConsumeEvent $event) : void{
        $p = $event->getPlayer(); $pos = $p->getPosition();
        if(!$this->playerAction($p, 'item-consume', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
    }

    public function onBed(PlayerBedEnterEvent $event) : void{
        $pos = $event->getBed()->getPosition();
        if(!$this->playerAction($event->getPlayer(), 'sleep', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
    }

    public function onExhaust(PlayerExhaustEvent $event) : void{
        $p = $event->getPlayer(); $pos = $p->getPosition();
        if(!$this->playerAction($p, 'hunger', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
    }

    public function onFlight(PlayerToggleFlightEvent $event) : void{
        if(!$event->isFlying()){ return; }
        $p = $event->getPlayer(); $pos = $p->getPosition();
        if(!$this->playerAction($p, 'fly', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
    }

    public function onBucketEmpty(PlayerBucketEmptyEvent $event) : void{
        $p = $event->getPlayer(); $pos = $event->getBlockClicked()->getPosition();
        if(!$this->playerAction($p, 'bucket-empty', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
    }

    public function onBucketFill(PlayerBucketFillEvent $event) : void{
        $p = $event->getPlayer(); $pos = $event->getBlockClicked()->getPosition();
        if(!$this->playerAction($p, 'bucket-fill', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
    }

    public function onChat(PlayerChatEvent $event) : void{
        $p = $event->getPlayer(); $pos = $p->getPosition();
        if(!$this->playerAction($p, 'chat', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
    }

    public function onCommand(CommandEvent $event) : void{
        $sender = $event->getSender();
        if(!$sender instanceof Player){ return; }
        $rawLabel = strtolower(strtok($event->getCommand(), ' ') ?: '');
        $label = ltrim($rawLabel, '/');
        if(in_array($label, ['rg', 'region', 'we', 'worldedit'], true) || str_starts_with($rawLabel, '/')){
            return;
        }
        $pos = $sender->getPosition();
        if(!$this->playerAction($sender, 'commands', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
    }

    public function onTeleport(EntityTeleportEvent $event) : void{
        $entity = $event->getEntity();
        if(!$entity instanceof Player || $this->plugin->hasBypass($entity)){ return; }
        $to = $event->getTo();
        $world = $to->getWorld()->getFolderName();
        if(!$this->plugin->actionAllowed($entity, 'teleport', $world, $to->getFloorX(), $to->getFloorY(), $to->getFloorZ())){
            $event->cancel();
            $this->plugin->deny($entity, $world, $to->getFloorX(), $to->getFloorY(), $to->getFloorZ());
        }
    }

    public function onProjectile(ProjectileLaunchEvent $event) : void{
        $projectile = $event->getEntity();
        if(!$projectile instanceof Projectile){ return; }
        $owner = $projectile->getOwningEntity();
        if(!$owner instanceof Player){ return; }
        $pos = $owner->getPosition();
        $flag = str_contains(strtolower($projectile::class), 'enderpearl') ? 'enderpearl' : 'projectile';
        if(!$this->playerAction($owner, $flag, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
    }

    public function onDamage(EntityDamageEvent $event) : void{
        $victim = $event->getEntity();
        $pos = $victim->getPosition();
        $world = $victim->getWorld()->getFolderName();
        $x = $pos->getFloorX(); $y = $pos->getFloorY(); $z = $pos->getFloorZ();

        if($victim instanceof Player){
            if($this->plugin->flagStateAt($world, $x, $y, $z, 'invincible') === 'allow'){
                $event->cancel();
                return;
            }
            $damageFlag = match($event->getCause()){
                EntityDamageEvent::CAUSE_FALL => 'fall-damage',
                EntityDamageEvent::CAUSE_VOID => 'void-damage',
                EntityDamageEvent::CAUSE_DROWNING => 'drowning-damage',
                EntityDamageEvent::CAUSE_LAVA => 'lava-damage',
                EntityDamageEvent::CAUSE_FIRE, EntityDamageEvent::CAUSE_FIRE_TICK => 'fire-damage',
                EntityDamageEvent::CAUSE_SUFFOCATION => 'suffocation-damage',
                EntityDamageEvent::CAUSE_STARVATION => 'starvation-damage',
                EntityDamageEvent::CAUSE_MAGIC => 'magic-damage',
                default => null
            };
            if($damageFlag !== null && $this->plugin->flagStateAt($world, $x, $y, $z, $damageFlag) === 'deny'){
                $event->cancel();
                return;
            }
        }elseif($this->plugin->flagStateAt($world, $x, $y, $z, 'entity-damage') === 'deny'){
            $event->cancel();
            return;
        }

        if($event instanceof EntityDamageByEntityEvent){
            $damager = $event->getDamager();
            if($damager instanceof Player && !$victim instanceof Player && !$this->plugin->actionAllowed($damager, 'mob-damage', $world, $x, $y, $z)){
                $event->cancel();
                $this->plugin->deny($damager, $world, $x, $y, $z);
            }
        }
    }

    public function onTrample(EntityTrampleFarmlandEvent $event) : void{
        $block = $event->getBlock(); $pos = $block->getPosition();
        $entity = $event->getEntity();
        if($entity instanceof Player){
            if(!$this->playerAction($entity, 'trample', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
        }elseif($this->plugin->flagStateAt($pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), 'trample') === 'deny'){
            $event->cancel();
        }
    }

    public function onBurn(BlockBurnEvent $event) : void{
        $pos = $event->getBlock()->getPosition();
        if($this->plugin->flagStateAt($pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), 'block-burn') === 'deny'){ $event->cancel(); }
    }

    public function onSpread(BlockSpreadEvent $event) : void{
        $pos = $event->getBlock()->getPosition();
        $sourceName = strtolower($event->getSource()->getName());
        $flag = str_contains($sourceName, 'fire') ? 'fire-spread' : ((str_contains($sourceName, 'water') || str_contains($sourceName, 'lava')) ? 'liquid-flow' : 'growth');
        if($this->plugin->flagStateAt($pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $flag) === 'deny'){ $event->cancel(); }
    }

    public function onLeavesDecay(LeavesDecayEvent $event) : void{
        $pos = $event->getBlock()->getPosition();
        if($this->plugin->flagStateAt($pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), 'leaf-decay') === 'deny'){ $event->cancel(); }
    }

    public function onGrow(BlockGrowEvent $event) : void{
        $block = $event->getBlock();
        $pos = $block->getPosition();
        $name = strtolower($block->getName());
        $cropWords = ['wheat', 'carrot', 'potato', 'beetroot', 'melon', 'pumpkin', 'cocoa', 'nether wart', 'sweet berry'];
        $flag = 'growth';
        foreach($cropWords as $word){
            if(str_contains($name, $word)){ $flag = 'crop-growth'; break; }
        }
        $state = $this->plugin->flagStateAt($pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $flag);
        if($state === null && $flag !== 'growth'){
            $state = $this->plugin->flagStateAt($pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), 'growth');
        }
        if($state === 'deny'){ $event->cancel(); }
    }

    public function onMelt(BlockMeltEvent $event) : void{
        $block = $event->getBlock();
        $pos = $block->getPosition();
        $name = strtolower($block->getName());
        $flag = str_contains($name, 'ice') ? 'ice-melt' : (str_contains($name, 'snow') ? 'snow-melt' : 'melt');
        $state = $this->plugin->flagStateAt($pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $flag);
        if($state === null && $flag !== 'melt'){
            $state = $this->plugin->flagStateAt($pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), 'melt');
        }
        if($state === 'deny'){ $event->cancel(); }
    }

    public function onForm(BlockFormEvent $event) : void{
        $pos = $event->getBlock()->getPosition();
        if($this->plugin->flagStateAt($pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), 'form') === 'deny'){ $event->cancel(); }
    }

    public function onSign(SignChangeEvent $event) : void{
        $pos = $event->getBlock()->getPosition();
        if(!$this->playerAction($event->getPlayer(), 'sign-edit', $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){ $event->cancel(); }
    }

    public function onDeath(PlayerDeathEvent $event) : void{
        $p = $event->getPlayer(); $pos = $p->getPosition(); $world = $p->getWorld()->getFolderName();
        if($this->plugin->flagStateAt($world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), 'keep-inventory') === 'allow'){
            $event->setKeepInventory(true);
        }
        if($this->plugin->flagStateAt($world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), 'keep-experience') === 'allow'){
            $event->setKeepXp(true);
        }
    }

    public function onRegionMessages(PlayerMoveEvent $event) : void{
        if($event->isCancelled()){ return; }
        $from = $event->getFrom(); $to = $event->getTo();
        if($from->getFloorX() === $to->getFloorX() && $from->getFloorY() === $to->getFloorY() && $from->getFloorZ() === $to->getFloorZ() && $from->getWorld() === $to->getWorld()){
            return;
        }
        $fromRegion = $this->plugin->getRegionManager()->highestAt($from->getWorld()->getFolderName(), $from->getFloorX(), $from->getFloorY(), $from->getFloorZ());
        $toRegion = $this->plugin->getRegionManager()->highestAt($to->getWorld()->getFolderName(), $to->getFloorX(), $to->getFloorY(), $to->getFloorZ());
        if($fromRegion?->name === $toRegion?->name){ return; }
        if($fromRegion !== null && ($message = $fromRegion->getRawFlag('farewell')) !== null && $message !== ''){
            $event->getPlayer()->sendMessage(str_replace('{region}', $fromRegion->name, $message));
        }
        if($toRegion !== null && ($message = $toRegion->getRawFlag('greeting')) !== null && $message !== ''){
            $event->getPlayer()->sendMessage(str_replace('{region}', $toRegion->name, $message));
        }
    }
}
