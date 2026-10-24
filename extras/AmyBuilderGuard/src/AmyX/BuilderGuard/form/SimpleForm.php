<?php

declare(strict_types=1);

namespace AmyX\BuilderGuard\form;

use Closure;
use pocketmine\form\Form;
use pocketmine\player\Player;

final class SimpleForm implements Form{
    /** @var array<int, array{text:string,image?:array{type:string,data:string}}> */
    private array $buttons = [];

    /** @param Closure(Player, ?int):void $callback */
    public function __construct(
        private string $title,
        private string $content,
        private Closure $callback
    ){}

    public function addButton(string $text, ?string $image = null, string $imageType = 'path') : self{
        $button = ['text' => $text];
        if($image !== null && $image !== ''){
            $button['image'] = ['type' => $imageType, 'data' => $image];
        }
        $this->buttons[] = $button;
        return $this;
    }

    public function jsonSerialize() : array{
        return [
            'type' => 'form',
            'title' => $this->title,
            'content' => $this->content,
            'buttons' => $this->buttons
        ];
    }

    public function handleResponse(Player $player, $data) : void{
        if($data === null){
            ($this->callback)($player, null);
            return;
        }
        if(!is_int($data) || !isset($this->buttons[$data])){
            return;
        }
        ($this->callback)($player, $data);
    }
}
