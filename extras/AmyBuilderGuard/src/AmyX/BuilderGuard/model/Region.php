<?php

declare(strict_types=1);

namespace AmyX\BuilderGuard\model;

final class Region{
    /** @param string[] $owners @param string[] $members @param array<string,string> $flags */
    public function __construct(
        public string $name,
        public string $world,
        public int $minX,
        public int $minY,
        public int $minZ,
        public int $maxX,
        public int $maxY,
        public int $maxZ,
        public array $owners = [],
        public array $members = [],
        public int $priority = 0,
        public array $flags = [],
        public bool $fullHeight = true
    ){
        $this->owners = array_values(array_unique(array_map('strtolower', $owners)));
        $this->members = array_values(array_unique(array_map('strtolower', $members)));
    }

    public function contains(string $world, int $x, int $y, int $z) : bool{
        return $this->world === $world
            && $x >= $this->minX && $x <= $this->maxX
            && ($this->fullHeight || ($y >= $this->minY && $y <= $this->maxY))
            && $z >= $this->minZ && $z <= $this->maxZ;
    }

    public function isOwner(string $player) : bool{
        return in_array(strtolower($player), $this->owners, true);
    }

    public function isMember(string $player) : bool{
        $name = strtolower($player);
        return $this->isOwner($name) || in_array($name, $this->members, true);
    }

    public function getFlag(string $flag) : ?string{
        $value = $this->flags[$flag] ?? null;
        return in_array($value, ['allow', 'deny'], true) ? $value : null;
    }

    public function getRawFlag(string $flag) : ?string{
        $value = $this->flags[$flag] ?? null;
        return is_string($value) ? $value : null;
    }

    /** @return array<string,mixed> */
    public function toArray() : array{
        return [
            'world' => $this->world,
            'min' => [$this->minX, $this->minY, $this->minZ],
            'max' => [$this->maxX, $this->maxY, $this->maxZ],
            'owners' => $this->owners,
            'members' => $this->members,
            'priority' => $this->priority,
            'flags' => $this->flags,
            'full-height' => $this->fullHeight
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(string $name, array $data) : ?self{
        $min = $data['min'] ?? null;
        $max = $data['max'] ?? null;
        $world = $data['world'] ?? null;
        if(!is_string($world) || !is_array($min) || !is_array($max) || count($min) !== 3 || count($max) !== 3){
            return null;
        }
        return new self(
            mb_strtolower($name, 'UTF-8'),
            $world,
            (int) $min[0], (int) $min[1], (int) $min[2],
            (int) $max[0], (int) $max[1], (int) $max[2],
            is_array($data['owners'] ?? null) ? array_map('strval', $data['owners']) : [],
            is_array($data['members'] ?? null) ? array_map('strval', $data['members']) : [],
            (int) ($data['priority'] ?? 0),
            is_array($data['flags'] ?? null) ? array_map('strval', $data['flags']) : [],
            (bool) ($data['full-height'] ?? true)
        );
    }
}
