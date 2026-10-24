<?php

declare(strict_types=1);

namespace AmyX\BuilderGuard\form;

use Closure;
use pocketmine\form\Form;
use pocketmine\player\Player;

final class CustomForm implements Form{
    /** @var array<int, array<string,mixed>> */
    private array $content = [];

    /** @param Closure(Player, ?array):void $callback */
    public function __construct(
        private string $title,
        private Closure $callback
    ){}

    public function addLabel(string $text) : self{
        $this->content[] = ['type' => 'label', 'text' => $text];
        return $this;
    }

    public function addInput(string $text, string $placeholder = '', string $default = '') : self{
        $this->content[] = ['type' => 'input', 'text' => $text, 'placeholder' => $placeholder, 'default' => $default];
        return $this;
    }

    public function addToggle(string $text, bool $default = false) : self{
        $this->content[] = ['type' => 'toggle', 'text' => $text, 'default' => $default];
        return $this;
    }

    public function addSlider(string $text, float $min, float $max, float $step = 1.0, ?float $default = null) : self{
        $entry = ['type' => 'slider', 'text' => $text, 'min' => $min, 'max' => $max, 'step' => $step];
        if($default !== null){
            $entry['default'] = $default;
        }
        $this->content[] = $entry;
        return $this;
    }

    /** @param string[] $options */
    public function addDropdown(string $text, array $options, int $default = 0) : self{
        $this->content[] = ['type' => 'dropdown', 'text' => $text, 'options' => array_values($options), 'default' => $default];
        return $this;
    }

    public function jsonSerialize() : array{
        return [
            'type' => 'custom_form',
            'title' => $this->title,
            'content' => $this->content
        ];
    }

    public function handleResponse(Player $player, $data) : void{
        if($data === null){
            ($this->callback)($player, null);
            return;
        }
        if(!is_array($data) || count($data) !== count($this->content)){
            return;
        }
        ($this->callback)($player, array_values($data));
    }
}
