<?php

declare(strict_types=1);

namespace AmyX\BuilderGuard\service;

use AmyX\BuilderGuard\model\Selection;

final class SessionManager{
    /** @var array<string, Selection> */
    private array $selections = [];

    /**
     * @var array<string, array{
     *   size: array{0:int,1:int,2:int},
     *   offset: array{0:int,1:int,2:int},
     *   blocks: list<array{0:int,1:int,2:int,3:int}>,
     *   tiles?: list<array{0:int,1:int,2:int,3:string}>
     * }>
     */
    private array $clipboards = [];

    /** @var array<string, list<list<array{0:string,1:int,2:int,3:int,4:int,5:int}>>> */
    private array $undo = [];

    /** @var array<string, list<list<array{0:string,1:int,2:int,3:int,4:int,5:int}>>> */
    private array $redo = [];

    public function __construct(private int $historySize){
    }

    private function key(string $player) : string{
        return strtolower($player);
    }

    public function getSelection(string $player) : Selection{
        $key = $this->key($player);
        return $this->selections[$key] ??= new Selection();
    }

    /**
     * @param array{
     *   size: array{0:int,1:int,2:int},
     *   offset: array{0:int,1:int,2:int},
     *   blocks: list<array{0:int,1:int,2:int,3:int}>,
     *   tiles?: list<array{0:int,1:int,2:int,3:string}>
     * } $clipboard
     */
    public function setClipboard(string $player, array $clipboard) : void{
        $this->clipboards[$this->key($player)] = $clipboard;
    }

    /**
     * @return array{
     *   size: array{0:int,1:int,2:int},
     *   offset: array{0:int,1:int,2:int},
     *   blocks: list<array{0:int,1:int,2:int,3:int}>,
     *   tiles?: list<array{0:int,1:int,2:int,3:string}>
     * }|null
     */
    public function getClipboard(string $player) : ?array{
        return $this->clipboards[$this->key($player)] ?? null;
    }

    public function clearClipboard(string $player) : void{
        unset($this->clipboards[$this->key($player)]);
    }

    /** @param list<array{0:string,1:int,2:int,3:int,4:int,5:int}> $changes */
    public function pushHistory(string $player, array $changes) : void{
        if($changes === []){
            return;
        }
        $key = $this->key($player);
        $this->undo[$key][] = $changes;
        while(count($this->undo[$key]) > $this->historySize){
            array_shift($this->undo[$key]);
        }
        $this->redo[$key] = [];
    }

    /** @return list<array{0:string,1:int,2:int,3:int,4:int,5:int}>|null */
    public function peekUndo(string $player) : ?array{
        $entries = $this->undo[$this->key($player)] ?? [];
        return $entries === [] ? null : $entries[array_key_last($entries)];
    }

    /** @return list<array{0:string,1:int,2:int,3:int,4:int,5:int}>|null */
    public function peekRedo(string $player) : ?array{
        $entries = $this->redo[$this->key($player)] ?? [];
        return $entries === [] ? null : $entries[array_key_last($entries)];
    }

    /** @return list<array{0:string,1:int,2:int,3:int,4:int,5:int}>|null */
    public function popUndo(string $player) : ?array{
        $key = $this->key($player);
        if(($this->undo[$key] ?? []) === []){
            return null;
        }
        $operation = array_pop($this->undo[$key]);
        $this->redo[$key][] = $operation;
        return $operation;
    }

    /** @return list<array{0:string,1:int,2:int,3:int,4:int,5:int}>|null */
    public function popRedo(string $player) : ?array{
        $key = $this->key($player);
        if(($this->redo[$key] ?? []) === []){
            return null;
        }
        $operation = array_pop($this->redo[$key]);
        $this->undo[$key][] = $operation;
        return $operation;
    }

    public function remove(string $player) : void{
        $key = $this->key($player);
        unset($this->selections[$key], $this->clipboards[$key], $this->undo[$key], $this->redo[$key]);
    }
}
