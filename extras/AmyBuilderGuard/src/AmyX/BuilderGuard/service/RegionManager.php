<?php

declare(strict_types=1);

namespace AmyX\BuilderGuard\service;

use AmyX\BuilderGuard\model\Region;
use pocketmine\utils\Config;

final class RegionManager{
    /** @var array<string, Region> */
    private array $regions = [];

    public function __construct(private Config $config){
        $this->load();
    }

    public function normalizeName(string $name) : ?string{
        $name = mb_strtolower(trim($name), 'UTF-8');
        return preg_match('/^[\p{L}\p{N}_-]{1,32}$/u', $name) === 1 ? $name : null;
    }

    public function load() : void{
        $this->regions = [];
        foreach($this->config->getAll() as $name => $data){
            if(is_string($name) && is_array($data) && ($region = Region::fromArray($name, $data)) !== null){
                $this->regions[$region->name] = $region;
            }
        }
    }

    public function save() : void{
        $data = [];
        foreach($this->regions as $name => $region){
            $data[$name] = $region->toArray();
        }
        $this->config->setAll($data);
        $this->config->save();
    }

    public function get(string $name) : ?Region{
        return $this->regions[mb_strtolower($name, 'UTF-8')] ?? null;
    }

    public function add(Region $region) : void{
        $this->regions[$region->name] = $region;
        $this->save();
    }

    public function remove(string $name) : bool{
        $name = mb_strtolower($name, 'UTF-8');
        if(!isset($this->regions[$name])){
            return false;
        }
        unset($this->regions[$name]);
        $this->save();
        return true;
    }

    /** @return Region[] */
    public function all() : array{
        $regions = array_values($this->regions);
        usort($regions, static fn(Region $a, Region $b) : int => $b->priority <=> $a->priority ?: strcmp($a->name, $b->name));
        return $regions;
    }

    /** @return Region[] */
    public function at(string $world, int $x, int $y, int $z) : array{
        $result = [];
        foreach($this->regions as $region){
            if($region->contains($world, $x, $y, $z)){
                $result[] = $region;
            }
        }
        usort($result, static fn(Region $a, Region $b) : int => $b->priority <=> $a->priority ?: strcmp($a->name, $b->name));
        return $result;
    }

    public function highestAt(string $world, int $x, int $y, int $z) : ?Region{
        return $this->at($world, $x, $y, $z)[0] ?? null;
    }
    /** @return Region[] */
    public function ownedBy(string $player) : array{
        $player = strtolower($player);
        return array_values(array_filter($this->all(), static fn(Region $region) : bool => $region->isOwner($player)));
    }

    /** @return Region[] */
    public function manageableBy(string $player, bool $admin = false) : array{
        if($admin){
            return $this->all();
        }
        $player = strtolower($player);
        return array_values(array_filter($this->all(), static fn(Region $region) : bool => $region->isOwner($player)));
    }

    /** @return Region[] */
    public function overlapping2D(string $world, int $minX, int $minZ, int $maxX, int $maxZ, ?string $except = null) : array{
        $result = [];
        foreach($this->regions as $region){
            if($except !== null && $region->name === mb_strtolower($except, 'UTF-8')){
                continue;
            }
            if($region->world !== $world){
                continue;
            }
            if($maxX < $region->minX || $minX > $region->maxX || $maxZ < $region->minZ || $minZ > $region->maxZ){
                continue;
            }
            $result[] = $region;
        }
        return $result;
    }

}
