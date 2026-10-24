<?php
declare(strict_types=1);
namespace AmyXClans\ui;
/** FormAPI 2.1.1 rejects null for modals although closing a Bedrock form sends null. */
final class SafeModal extends \jojoe77777\FormAPI\ModalForm{
    public function processData(&$data) : void{if($data!==null) parent::processData($data);}
}
