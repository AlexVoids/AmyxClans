<?php

declare(strict_types=1);

namespace AmyX\BuilderGuard\command;

use Closure;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;

final class CallbackCommand extends Command{
    /** @var Closure(CommandSender,string,array<int,string>):void */
    private Closure $callback;

    /**
     * @param string[] $aliases
     * @param Closure(CommandSender,string,array<int,string>):void $callback
     */
    public function __construct(
        string $name,
        string $description,
        string $usage,
        array $aliases,
        string $permission,
        Closure $callback
    ){
        parent::__construct($name, $description, $usage, $aliases);
        $this->setPermission($permission);
        $this->callback = $callback;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args) : void{
        if(!$this->testPermission($sender)){
            return;
        }
        ($this->callback)($sender, $commandLabel, $args);
    }
}
