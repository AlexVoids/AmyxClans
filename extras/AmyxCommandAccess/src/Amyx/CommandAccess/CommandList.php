<?php
declare(strict_types=1);
namespace Amyx\CommandAccess;

use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketAssembler;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketDisassembler;

/** Reuse the core's protocol-specific RAWTEXT overload instead of hardcoding arg IDs. */
final class CommandList{
    public static function filter(AvailableCommandsPacket $packet, array $labels) : AvailableCommandsPacket{
        $modern = class_exists(AvailableCommandsPacketDisassembler::class);
        $data = $modern ? AvailableCommandsPacketDisassembler::disassemble($packet)->commandData : $packet->commandData;
        $template = null;
        foreach($data as $command){
            $name = $command->getName();
            if($name === 'register' || $name === 'amyxcommandaccess:register'){
                $template = $command;
                break;
            }
        }
        // An empty list is preferable to leaking suggestions if another plugin
        // strips our registered template from the source packet.
        $result = [];
        if($template !== null){
            foreach($labels as $label => $description){
                $command = clone $template;
                $command->name = $label;
                $command->description = $description;
                $command->aliases = null;
                $result[] = $command;
            }
        }
        if($modern){ return AvailableCommandsPacketAssembler::assemble($result, [], []); }
        $filtered = clone $packet;
        $filtered->commandData = $result;
        $filtered->hardcodedEnums = [];
        $filtered->softEnums = [];
        $filtered->enumConstraints = [];
        return $filtered;
    }
}
