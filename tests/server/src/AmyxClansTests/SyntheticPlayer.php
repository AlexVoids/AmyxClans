<?php
declare(strict_types=1);
namespace AmyxClansTests;

use pocketmine\entity\AttributeMap;
use pocketmine\entity\ExperienceManager;
use pocketmine\entity\Location;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerCraftingInventory;
use pocketmine\inventory\PlayerCursorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\permission\Permission;
use pocketmine\player\Player;
use pocketmine\world\Position;

final class SyntheticPlayer extends Player{
    public bool $creative=false;
    public bool $spectator=false;
    public bool $allowed=true;
    public array $messages=[];
    public array $forms=[];
    public bool $online=true;
    private \pocketmine\player\GameMode $mode;
    public function __construct(Position $position,private string $testName){
         $this->perm=new \pocketmine\permission\PermissibleBase([\pocketmine\permission\DefaultPermissions::ROOT_USER=>true]);
        $this->uuid=\Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_DNS,$testName);
        $this->mode=\pocketmine\player\GameMode::SURVIVAL();
        $this->effectManager=new \pocketmine\entity\effect\EffectManager($this);
        $this->server=\pocketmine\Server::getInstance();$this->logger=$this->server->getLogger();
        $this->id=spl_object_id($this);$this->location=Location::fromObject($position,$position->getWorld());
        $this->regenerateEnchantmentSeed();$this->attributeMap=new AttributeMap();$this->attributeMap->add($this->moveSpeedAttr=\pocketmine\entity\AttributeFactory::getInstance()->mustGet(\pocketmine\entity\Attribute::MOVEMENT_SPEED));$this->xpManager=new ExperienceManager($this);
        $this->inventory=new PlayerInventory($this);$this->armorInventory=new ArmorInventory($this);$this->offHandInventory=new PlayerOffHandInventory($this);
        $this->cursorInventory=new PlayerCursorInventory($this);$this->craftingGrid=new PlayerCraftingInventory($this);
        $this->networkSession=new SyntheticSession($this);$this->networkSession->manager=new InventoryManager($this,$this->networkSession);
        $this->addPermanentInventories($this->inventory,$this->armorInventory,$this->offHandInventory,$this->cursorInventory,$this->craftingGrid);
        $this->networkSession->manager->syncAll();$this->xpManager->setXpAndProgressNoEvent(30,0.25);
    }
    public function getName(): string{ return $this->testName; }
    public function getDisplayName():string{return $this->testName;}
    public function isSurvival(bool $literal=false): bool{ return !$this->creative && !$this->spectator; }
    public function isCreative(bool $literal=false): bool{ return $this->creative; }
    public function isAdventure(bool $literal=false): bool{ return false; }
    public function isSpectator(): bool{ return $this->spectator; }
    public function hasFiniteResources(): bool{ return !$this->creative; }
    public function isConnected(): bool{ return $this->online; }
    public function isAlive(): bool{ return true; }
    public function hasPermission(Permission|string $name): bool{ return $this->allowed; }
    public function setUsingItem(bool $value): void{}
    public function sendMessage(\pocketmine\lang\Translatable|string $message):void{$this->messages[]=(string)$message;}
    public function sendTip(string $message):void{$this->messages[]=$message;}
    public function sendActionBarMessage(string $message):void{}
    public function sendForm(\pocketmine\form\Form $form):void{$this->forms[]=$form;}
    public function save():void{}
    public function getGamemode():\pocketmine\player\GameMode{return $this->mode;}
    public function setGamemode(\pocketmine\player\GameMode $mode):bool{$this->mode=$mode;return true;}
    public function teleport(\pocketmine\math\Vector3 $pos,?float $yaw=null,?float $pitch=null):bool{
        $target=$pos instanceof Position?$pos:Position::fromObject($pos,$this->getWorld());
        $event=new \pocketmine\event\entity\EntityTeleportEvent($this,$this->getPosition(),$target);$event->call();
        if($event->isCancelled())return false;
        $this->location=Location::fromObject($event->getTo(),$event->getTo()->getWorld(),$yaw??0,$pitch??0);return true;
    }
    public function __destruct(){}
}
