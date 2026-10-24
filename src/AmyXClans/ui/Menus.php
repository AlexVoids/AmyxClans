<?php
declare(strict_types=1);
namespace AmyXClans\ui;
use AmyXClans\Main;
use pocketmine\player\Player;
use jojoe77777\FormAPI\{SimpleForm,CustomForm};

final class Menus{
    use ClanMenus,ManageMenus,BattleMenus,AdminMenus;
    public function __construct(public Main $p){}
    private function text(string $text) : string{return $this->p->translateText(str_replace('\\n', "\n", $text));}
    private function title(string $text) : string{return $this->text((string)$this->p->cfg('forms.title-prefix','§5AmyxCraft §f| ').$text);}
    private function handler(Player $owner,callable $fn,bool $op=false) : \Closure{
        $uuid=$owner->getUniqueId()->toString();$clan=$this->p->clan->id($owner->getName());$used=false;
        return function(Player $player,mixed $data)use($uuid,$clan,$fn,$op,&$used):void{
            if($used) return;$used=true;
            try{
                if($player->getUniqueId()->toString()!==$uuid || !$this->p->authenticated($player)) throw new \DomainException('Сначала войдите в аккаунт.');
                if($op && !$this->p->isOperator($player)) throw new \DomainException('Настройка доступна только OP.');
                if($this->p->clan->id($player->getName())!==$clan) throw new \DomainException('Состав клана изменился. Откройте /clan снова.');
                $fn($data);
            }catch(\DomainException $e){$this->p->message($player,$e->getMessage());}catch(\Throwable $e){$this->p->getLogger()->logException($e);$this->p->message($player,'Операция приостановлена из-за ошибки. Обратитесь к администрации.');}
        };
    }
    /** Each button is [label, callback]; callbacks also revalidate current permissions in services. */
    private function menu(Player $player,string $title,string $body,array $buttons,?callable $back=null,bool $op=false) : void{
        if($back!==null) $buttons[]=['Назад',$back];
        $form=new SimpleForm($this->handler($player,function(?int $data)use($buttons):void{if($data!==null && isset($buttons[$data])) $buttons[$data][1]();},$op));
        $form->setTitle($this->title($title));$form->setContent($this->text('§f'.$body));
        foreach($buttons as [$label]) $form->addButton($this->text('§f'.$label));
        $player->sendForm($form);
    }
    /** Elements: label/input/toggle/dropdown/slider, with positional response indexes. */
    private function custom(Player $player,string $title,array $elements,callable $submit,?callable $back=null,bool $op=false) : void{
        $form=new CustomForm($this->handler($player,function(?array $data)use($submit,$back):void{if($data===null){if($back!==null)$back();return;}$submit($data);},$op));
        $form->setTitle($this->title($title));
        foreach($elements as $e){
            [$type,$label]=$e;$label=$this->text('§f'.$label);
            match($type){
                'label'=>$form->addLabel($label),
                'input'=>$form->addInput($label,(string)($e[2]??''),(string)($e[3]??'')),
                'toggle'=>$form->addToggle($label,(bool)($e[2]??false)),
                'dropdown'=>$form->addDropdown($label,array_map(fn($s)=>$this->text('§5'.$s),$e[2]),(int)($e[3]??0)),
                'slider'=>$form->addSlider($label,(int)$e[2],(int)$e[3],1,(int)($e[4]??$e[2])),
                default=>throw new \LogicException('Unknown form element')
            };
        }
        $player->sendForm($form);
    }
    private function confirm(Player $player,string $title,string $body,callable $yes,callable $back,bool $op=false) : void{
        $f=new SafeModal($this->handler($player,function(?bool $ok)use($yes,$back):void{if($ok===true)$yes();else $back();},$op));
        $f->setTitle($this->title($title));$f->setContent($this->text('§f'.$body));$f->setButton1($this->text('§fПодтвердить'));$f->setButton2($this->text('§fНазад'));$player->sendForm($f);
    }
    private function success(Player $player,callable $next) : void{$this->p->message($player,'Изменения сохранены.');$next();}
}
