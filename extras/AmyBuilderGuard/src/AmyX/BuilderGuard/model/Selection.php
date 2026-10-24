<?php

declare(strict_types=1);

namespace AmyX\BuilderGuard\model;

use pocketmine\math\Vector3;

final class Selection{
    public ?Vector3 $pos1 = null;
    public ?Vector3 $pos2 = null;
    public ?string $world = null;

    public function setPos1(Vector3 $pos, string $world) : void{
        if($this->world !== null && $this->world !== $world){
            $this->pos2 = null;
        }
        $this->pos1 = $pos->floor();
        $this->world = $world;
    }

    public function setPos2(Vector3 $pos, string $world) : void{
        if($this->world !== null && $this->world !== $world){
            $this->pos1 = null;
        }
        $this->pos2 = $pos->floor();
        $this->world = $world;
    }

    public function isComplete() : bool{
        return $this->pos1 !== null && $this->pos2 !== null && $this->world !== null;
    }

    /** @return array{0:int,1:int,2:int,3:int,4:int,5:int} */
    public function bounds() : array{
        if(!$this->isComplete()){
            throw new \LogicException("Selection is incomplete");
        }
        return [
            min($this->pos1->getFloorX(), $this->pos2->getFloorX()),
            min($this->pos1->getFloorY(), $this->pos2->getFloorY()),
            min($this->pos1->getFloorZ(), $this->pos2->getFloorZ()),
            max($this->pos1->getFloorX(), $this->pos2->getFloorX()),
            max($this->pos1->getFloorY(), $this->pos2->getFloorY()),
            max($this->pos1->getFloorZ(), $this->pos2->getFloorZ())
        ];
    }

    public function volume() : int{
        [$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = $this->bounds();
        return ($maxX - $minX + 1) * ($maxY - $minY + 1) * ($maxZ - $minZ + 1);
    }

    public function clear() : void{
        $this->pos1 = null;
        $this->pos2 = null;
        $this->world = null;
    }
}
