<?php

declare(strict_types=1);

namespace AmyXClans\legacy;
use AmyXClans\Main;

use AmyXClans\economy\EconomyBridge;
use AmyXClans\form\CustomForm;
use AmyXClans\form\ModalForm;
use AmyXClans\form\SimpleForm;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\inventory\ChestInventory;
use pocketmine\block\inventory\DoubleChestInventory;
use pocketmine\block\tile\Chest as ChestTile;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\nbt\TreeRoot;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use Throwable;
use function base64_decode;
use function base64_encode;
use function floor;
use function is_int;
use function is_numeric;
use function min;
use function spl_object_id;

trait Storage{
    private function hideStoragePositionsFrom(Player $viewer, array $positions) : void{
        if(!$viewer->isConnected()) return;
        $translator = $viewer->getNetworkSession()->getTypeConverter()->getBlockTranslator();
        $airRuntimeId = $translator->internalIdToNetworkId(VanillaBlocks::AIR()->getStateId());
        foreach($positions as $position){
            if($viewer->getWorld() !== $position->getWorld()) continue;
            $viewer->getNetworkSession()->sendDataPacket(UpdateBlockPacket::create(
                BlockPosition::fromVector3($position),
                $airRuntimeId,
                UpdateBlockPacket::FLAG_NETWORK,
                UpdateBlockPacket::DATA_LAYER_NORMAL
            ));
        }
    }

    private function openStorageInventory(Player $player, string $clanId) : void{
        if(!isset($this->clans[$clanId]) || !$this->canTakeFromStorage($clanId,$player->getName())) return;
        if($this->matches->playerMatch($player)!==null) throw new \DomainException('В битве хранилище недоступно.');
        foreach($this->storageSessions as $session) if($session['clanId']===$clanId) throw new \DomainException('Хранилище уже открыто участником клана.');
        $playerKey = $this->key($player->getName());
        if(isset($this->storageSessions[$playerKey])){
            $player->removeCurrentWindow();
            if(isset($this->storageSessions[$playerKey])){
                $this->closeStorageSession($playerKey, true);
            }
        }

        $world = $player->getWorld();
        $baseX = (int) floor($player->getPosition()->x);
        $baseY = (int) floor($player->getPosition()->y);
        $baseZ = (int) floor($player->getPosition()->z);
        $leftPosition = null;
        $rightPosition = null;
        $airTypeId = VanillaBlocks::AIR()->getTypeId();
        for($dy = 3; $dy <= 10 && $leftPosition === null; ++$dy){
            $y = (int) min($world->getMaxY() - 2, $baseY + $dy);
            for($dx = -2; $dx <= 2 && $leftPosition === null; ++$dx){
                for($dz = -2; $dz <= 2; ++$dz){
                    $candidateLeft = new Position($baseX + $dx, $y, $baseZ + $dz, $world);
                    $candidateRight = new Position($baseX + $dx + 1, $y, $baseZ + $dz, $world);
                    if($world->getBlock($candidateLeft)->getTypeId() === $airTypeId && $world->getBlock($candidateRight)->getTypeId() === $airTypeId && $this->canBuildAt($player,$candidateLeft) && $this->canBuildAt($player,$candidateRight)){
                        $leftPosition = $candidateLeft;
                        $rightPosition = $candidateRight;
                        break;
                    }
                }
            }
        }
        if(!$leftPosition instanceof Position || !$rightPosition instanceof Position){
            $player->sendMessage("§5Рядом не найдено свободное место для открытия хранилища.");
            return;
        }
        $receipt=$this->key($player->getName());
        $this->db->runtime['storageBlocks'][$receipt]=['world'=>$world->getFolderName(),'positions'=>[[$leftPosition->x,$leftPosition->y,$leftPosition->z],[$rightPosition->x,$rightPosition->y,$rightPosition->z]]];
        $this->db->save();
        $originalLeft = clone $world->getBlock($leftPosition);
        $originalRight = clone $world->getBlock($rightPosition);
        // Real, client-visible paired chests are required for Bedrock to accept
        // the following ContainerOpen packet. Use the same facing and notify the
        // player immediately; a detached inventory or an unpaired visual block
        // is rendered but its menu is silently rejected by the client.
        $world->setBlock($leftPosition, VanillaBlocks::CHEST()->setFacing(Facing::NORTH), true);
        $world->setBlock($rightPosition, VanillaBlocks::CHEST()->setFacing(Facing::NORTH), true);

        // Use the actual chest tile inventories created by World::setBlock().
        // Constructing detached ChestInventory objects made the client open a
        // container which did not match the block entity and could freeze.
        $leftTile = $world->getTile($leftPosition);
        $rightTile = $world->getTile($rightPosition);
        if(!$leftTile instanceof ChestTile || !$rightTile instanceof ChestTile || !$leftTile->pairWith($rightTile)){
            $world->setBlock($leftPosition, $originalLeft, false);
            $world->setBlock($rightPosition, $originalRight, false);
            $player->sendMessage("§5Не удалось создать окно хранилища. Попробуйте ещё раз.");
            return;
        }
        $leftTile->setName("Хранилище клана");
        $rightTile->setName("Хранилище клана");
        $leftTile->clearSpawnCompoundCache();
        $rightTile->clearSpawnCompoundCache();

        // pairWith() changes the block-entity NBT after the blocks were already
        // sent. Resend both block entities so Bedrock knows that this is one
        // valid double chest before we ask it to open the window.
        $session = $player->getNetworkSession();
        $typeConverter = $session->getTypeConverter();
        $session->sendDataPacket(BlockActorDataPacket::create(
            BlockPosition::fromVector3($leftPosition),
            $leftTile->getSerializedSpawnCompound($typeConverter)
        ));
        $session->sendDataPacket(BlockActorDataPacket::create(
            BlockPosition::fromVector3($rightPosition),
            $rightTile->getSerializedSpawnCompound($typeConverter)
        ));

        $inventory = $leftTile->getInventory();
        if(!$inventory instanceof DoubleChestInventory){
            $world->setBlock($leftPosition, $originalLeft, false);
            $world->setBlock($rightPosition, $originalRight, false);
            $player->sendMessage("§5Не удалось связать сундуки хранилища.");
            return;
        }
        $left = $inventory->getLeftSide();
        $right = $inventory->getRightSide();
        $inventory->setContents($this->decodeStorageContents((array) ($this->clans[$clanId]["storage"] ?? [])));

        $this->storageSessions[$playerKey] = [
            "clanId" => $clanId,
            "inventory" => $inventory,
            "left" => $left,
            "right" => $right,
            "positions" => [$leftPosition, $rightPosition],
            "originals" => [$originalLeft, $originalRight]
        ];

        // The real blocks are needed by the owner's Bedrock client, but other
        // players receive AIR after the world's block update has been flushed.
        // Server-side interaction/break protection below remains the final guard.
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $leftPosition, $rightPosition) : void{
            foreach($player->getWorld()->getPlayers() as $viewer){
                if($viewer !== $player){
                    $this->hideStoragePositionsFrom($viewer, [$leftPosition, $rightPosition]);
                }
            }
        }), 2);

        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $playerKey, $inventory, $leftPosition) : void{
            if(!$player->isConnected() || !$this->canTakeFromStorage($clanId = ($this->storageSessions[$playerKey]['clanId'] ?? ''),$player->getName()) || !isset($this->storageSessions[$playerKey]) || $this->storageSessions[$playerKey]["inventory"] !== $inventory){
                return;
            }

            // Open through the real chest block first. This makes PocketMine send
            // the same holder coordinates, window type and inventory that the
            // client just received in BlockActorData. Directly opening the
            // inventory was rejected on newer Bedrock clients.
            $block = $leftPosition->getWorld()->getBlock($leftPosition);
            $returnedItems = [];
            $block->onInteract(
                $player->getInventory()->getItemInHand(),
                Facing::UP,
                new Vector3(0.5, 0.5, 0.5),
                $player,
                $returnedItems
            );

            // Fallback for custom cores which don't route Chest::onInteract().
            if($player->getCurrentWindow() !== $inventory && !$player->setCurrentWindow($inventory)){
                $player->sendMessage("§5Не удалось открыть клановое хранилище. Повторите команду после закрытия других окон.");
                $this->closeStorageSession($playerKey, true);
            }
        }), 12);
    }

    /** @return array<int, Item> */
    private function decodeStorageContents(array $saved) : array{
        $result = [];
        $serializer = new LittleEndianNbtSerializer();
        foreach($saved as $slot => $encoded){
            if(!is_numeric($slot) || !is_string($encoded)) continue;
            $index = (int) $slot;
            if($index < 0 || $index >= 54) continue;
            try{
                $binary = base64_decode($encoded, true);
                if($binary === false) continue;
                $item = Item::nbtDeserialize($serializer->read($binary)->mustGetCompoundTag());
                if(!$item->isNull()) $result[$index] = $item;
            }catch(Throwable $e){
                $this->getLogger()->warning("Не удалось загрузить предмет из хранилища, слот " . $index . ": " . $e->getMessage());
            }
        }
        return $result;
    }

    /** @return array<int, string> */
    private function encodeStorageContents(DoubleChestInventory $inventory) : array{
        $result = [];
        $serializer = new LittleEndianNbtSerializer();
        foreach($inventory->getContents() as $slot => $item){
            if($item->isNull()) continue;
            $result[(int) $slot] = base64_encode($serializer->write(new TreeRoot($item->nbtSerialize((int) $slot))));
        }
        return $result;
    }

    private function saveStorageSession(string $playerKey) : void{
        $session = $this->storageSessions[$playerKey] ?? null;
        if($session === null || !isset($this->clans[$session["clanId"]])) return;
        $clanId = $session["clanId"];
        $this->clans[$clanId]["storage"] = $this->encodeStorageContents($session["inventory"]);
        $this->saveData();

        // Все одновременно открытые окна одного клана получают одинаковое
        // содержимое, чтобы последнее закрытое окно не затёрло чужие изменения.
        $contents = $this->decodeStorageContents((array) $this->clans[$clanId]["storage"]);
        foreach($this->storageSessions as $otherKey => $otherSession){
            if($otherKey !== $playerKey && $otherSession["clanId"] === $clanId){
                $otherSession["inventory"]->setContents($contents);
            }
        }
    }

    private function closeStorageSession(string $playerKey, bool $save) : void{
        $session = $this->storageSessions[$playerKey] ?? null;
        if($session === null) return;
        if($save) $this->saveStorageSession($playerKey);
        $session['inventory']->clearAll();
        unset($this->storageSessions[$playerKey]);
        unset($this->db->runtime['storageBlocks'][$playerKey]);
        $this->db->save();
        foreach($session["positions"] as $index => $position){
            $position->getWorld()->setBlock($position, $session["originals"][$index], false);
        }
    }

    /** Returns the owner key of a temporary clan chest at this position. */
    private function getStorageOwnerAt(Position $position) : ?string{
        foreach($this->storageSessions as $ownerKey => $session){
            foreach($session["positions"] as $storagePosition){
                if(
                    $storagePosition->getWorld() === $position->getWorld() &&
                    (int) floor($storagePosition->x) === (int) floor($position->x) &&
                    (int) floor($storagePosition->y) === (int) floor($position->y) &&
                    (int) floor($storagePosition->z) === (int) floor($position->z)
                ){
                    return $ownerKey;
                }
            }
        }
        return null;
    }

    /**
     * Nobody can physically break the temporary chest, including its owner.
     * @priority HIGHEST
     */
    public function onStorageBlockBreak(BlockBreakEvent $event) : void{
        if($this->getStorageOwnerAt($event->getBlock()->getPosition()) !== null){
            $event->cancel();
        }
    }

    /**
     * Only the player whose storage session created the chest may interact with it.
     * @priority HIGHEST
     */
    public function onStorageBlockInteract(PlayerInteractEvent $event) : void{
        $ownerKey = $this->getStorageOwnerAt($event->getBlock()->getPosition());
        if($ownerKey !== null && $ownerKey !== $this->key($event->getPlayer()->getName())){
            $event->cancel();
        }
    }

    /**
     * Final guard: even if another plugin attempts to open the temporary chest
     * directly, only its owning storage session may receive the window.
     * @priority HIGHEST
     */
    public function onStorageInventoryOpen(InventoryOpenEvent $event) : void{
        $playerKey = $this->key($event->getPlayer()->getName());
        $inventory = $event->getInventory();
        foreach($this->storageSessions as $ownerKey => $session){
            if(($inventory === $session["inventory"] || $inventory === $session["left"] || $inventory === $session["right"])
                && $ownerKey !== $playerKey){
                $event->cancel();
                return;
            }
        }
    }

    /**
     * Chunk data may reveal a real temporary chest again when another player
     * walks into the area. Re-apply the client-only AIR blocks on chunk change.
     * Server-side cancellation above remains the security boundary.
     */
    public function onStorageViewerMove(PlayerMoveEvent $event) : void{
        $from = $event->getFrom();
        $to = $event->getTo();
        if($to === null || $from->getWorld() !== $to->getWorld()){
            return;
        }
        if((((int) floor($from->x)) >> 4) === (((int) floor($to->x)) >> 4)
            && (((int) floor($from->z)) >> 4) === (((int) floor($to->z)) >> 4)){
            return;
        }
        $viewer = $event->getPlayer();
        $viewerKey = $this->key($viewer->getName());
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($viewer, $viewerKey) : void{
            if(!$viewer->isConnected()) return;
            foreach($this->storageSessions as $ownerKey => $session){
                if($ownerKey !== $viewerKey){
                    $this->hideStoragePositionsFrom($viewer, $session["positions"]);
                }
            }
        }), 1);
    }

    private function actionUsesStorageInventory(SlotChangeAction $action, array $session) : bool{
        $inventory = $action->getInventory();
        return $inventory === $session["inventory"] || $inventory === $session["left"] || $inventory === $session["right"];
    }

    private function actionRemovesStoredItem(SlotChangeAction $action) : bool{
        $source = $action->getSourceItem();
        $target = $action->getTargetItem();
        if($source->isNull()) return false;
        if($target->isNull()) return true;
        if(!$source->canStackWith($target)) return true;
        return $target->getCount() < $source->getCount();
    }

    public function onStorageTransaction(InventoryTransactionEvent $event) : void{
        $player = $event->getTransaction()->getSource();
        $playerKey = $this->key($player->getName());
        $session = $this->storageSessions[$playerKey] ?? null;
        if($session === null) return;

        if(!$this->canTakeFromStorage($session["clanId"], $player->getName())){
            foreach($event->getTransaction()->getActions() as $action){
                // A role without storage access cannot either take items or
                // deposit them. Cancel every change touching the clan storage.
                if($action instanceof SlotChangeAction && $this->actionUsesStorageInventory($action, $session)){
                    $event->cancel();
                    $player->sendActionBarMessage(" У вашей роли недостаточно прав для доступа к хранилищу!");
                    return;
                }
            }
        }

        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($playerKey) : void{
            if(isset($this->storageSessions[$playerKey])) $this->saveStorageSession($playerKey);
        }), 1);
    }

    public function onStorageClose(InventoryCloseEvent $event) : void{
        $playerKey = $this->key($event->getPlayer()->getName());
        $session = $this->storageSessions[$playerKey] ?? null;
        if($session === null || $event->getInventory() !== $session["inventory"]) return;
        $this->closeStorageSession($playerKey, true);
    }

    public function onStorageQuit(PlayerQuitEvent $event) : void{
        $playerKey = $this->key($event->getPlayer()->getName());
        if(isset($this->storageSessions[$playerKey])){
            $this->closeStorageSession($playerKey, true);
        }
    }

}
