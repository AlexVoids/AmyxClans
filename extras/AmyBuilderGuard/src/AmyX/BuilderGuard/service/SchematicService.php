<?php

declare(strict_types=1);

namespace AmyX\BuilderGuard\service;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\StringToItemParser;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\NBT;
use pocketmine\nbt\TreeRoot;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use RuntimeException;
use Throwable;

final class SchematicService{
    public const EXTENSIONS = ['abg', 'schem', 'schematic'];
    private const CUSTOM_DATA_TAG = 'AmyBuilderGuardData';

    /**
     * @param array{
     *   size: array{0:int,1:int,2:int},
     *   offset: array{0:int,1:int,2:int},
     *   blocks: list<array{0:int,1:int,2:int,3:int}>,
     *   tiles?: list<array{0:int,1:int,2:int,3:string}>
     * } $clipboard
     */
    public function save(string $file, string $extension, array $clipboard) : void{
        $extension = strtolower($extension);
        $clipboard = $this->normalizeClipboard($clipboard);
        $raw = match($extension){
            'abg' => $this->writeAbg($clipboard),
            'schem' => $this->writeSponge($clipboard),
            'schematic' => $this->writeLegacy($clipboard),
            default => throw new RuntimeException("Unsupported schematic extension: {$extension}")
        };
        if(file_put_contents($file, $raw) === false){
            throw new RuntimeException('Could not write schematic file');
        }
    }

    /**
     * @return array{
     *   size: array{0:int,1:int,2:int},
     *   offset: array{0:int,1:int,2:int},
     *   blocks: list<array{0:int,1:int,2:int,3:int}>,
     *   tiles: list<array{0:int,1:int,2:int,3:string}>
     * }
     */
    public function load(string $file, ?string $extension = null) : array{
        if(!is_file($file)){
            throw new RuntimeException('Schematic file not found');
        }
        $raw = file_get_contents($file);
        if($raw === false){
            throw new RuntimeException('Could not read schematic file');
        }
        $extension ??= strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return $this->normalizeClipboard(match($extension){
            'abg' => $this->readAbg($raw),
            'schem' => $this->readSponge($raw),
            'schematic' => $this->readLegacy($raw),
            default => throw new RuntimeException("Unsupported schematic extension: {$extension}")
        });
    }

    /** @return list<string> */
    public function listFiles(string $folder) : array{
        $result = [];
        foreach(self::EXTENSIONS as $extension){
            foreach(glob($folder . '*.'.$extension) ?: [] as $file){
                $result[] = basename($file);
            }
        }
        natcasesort($result);
        return array_values($result);
    }

    public function encodeCompound(CompoundTag $tag) : string{
        return base64_encode((new BigEndianNbtSerializer())->write(new TreeRoot($tag, 'Tile')));
    }

    public function decodeCompound(string $encoded) : CompoundTag{
        $raw = base64_decode($encoded, true);
        if($raw === false){
            throw new RuntimeException('Invalid tile NBT encoding');
        }
        $tag = (new BigEndianNbtSerializer())->read($raw)->getTag();
        if(!$tag instanceof CompoundTag){
            throw new RuntimeException('Tile NBT root is not a compound');
        }
        return $tag;
    }

    /** @param array<string,mixed> $clipboard */
    private function normalizeClipboard(array $clipboard) : array{
        $size = $clipboard['size'] ?? null;
        $offset = $clipboard['offset'] ?? null;
        $blocks = $clipboard['blocks'] ?? null;
        $tiles = $clipboard['tiles'] ?? [];
        if(!is_array($size) || count($size) !== 3 || !is_array($offset) || count($offset) !== 3 || !is_array($blocks) || !is_array($tiles)){
            throw new RuntimeException('Invalid clipboard structure');
        }
        $normalizedBlocks = [];
        foreach($blocks as $entry){
            if(!is_array($entry) || count($entry) < 4){
                continue;
            }
            $normalizedBlocks[] = [(int) $entry[0], (int) $entry[1], (int) $entry[2], (int) $entry[3]];
        }
        $normalizedTiles = [];
        foreach($tiles as $entry){
            if(!is_array($entry) || count($entry) < 4 || !is_string($entry[3])){
                continue;
            }
            $normalizedTiles[] = [(int) $entry[0], (int) $entry[1], (int) $entry[2], $entry[3]];
        }
        return [
            'size' => [max(1, (int) $size[0]), max(1, (int) $size[1]), max(1, (int) $size[2])],
            'offset' => [(int) $offset[0], (int) $offset[1], (int) $offset[2]],
            'blocks' => $normalizedBlocks,
            'tiles' => $normalizedTiles
        ];
    }

    private function customPayload(array $clipboard) : string{
        $json = json_encode(['format' => 2, 'clipboard' => $clipboard], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $compressed = gzencode($json, 6);
        if($compressed === false){
            throw new RuntimeException('Could not compress native schematic payload');
        }
        return $compressed;
    }

    private function readCustomPayload(CompoundTag $root) : ?array{
        if($root->getTag(self::CUSTOM_DATA_TAG) === null){
            return null;
        }
        try{
            $compressed = $root->getByteArray(self::CUSTOM_DATA_TAG);
            $json = gzdecode($compressed);
            if($json === false){
                return null;
            }
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data['clipboard'] ?? null) ? $data['clipboard'] : null;
        }catch(Throwable){
            return null;
        }
    }

    private function writeAbg(array $clipboard) : string{
        return $this->customPayload($clipboard);
    }

    private function readAbg(string $raw) : array{
        $json = gzdecode($raw);
        if($json === false){
            throw new RuntimeException('Invalid ABG compression');
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if(!is_array($data['clipboard'] ?? null)){
            throw new RuntimeException('Invalid ABG schematic');
        }
        return $data['clipboard'];
    }

    private function writeSponge(array $clipboard) : string{
        [$width, $height, $length] = $clipboard['size'];
        $lookup = $this->blockLookup($clipboard['blocks']);
        $serializer = GlobalBlockStateHandlers::getSerializer();
        $paletteIndexes = [];
        $paletteByName = ['minecraft:air' => 0];
        $paletteTag = CompoundTag::create()->setInt('minecraft:air', 0);
        $nextIndex = 1;

        foreach($clipboard['blocks'] as $entry){
            $stateId = $entry[3];
            if(isset($paletteIndexes[$stateId])){
                continue;
            }
            try{
                $name = $serializer->serialize($stateId)->getName();
            }catch(Throwable){
                $name = 'minecraft:air';
            }
            if(!isset($paletteByName[$name])){
                $paletteByName[$name] = $nextIndex;
                $paletteTag->setInt($name, $nextIndex++);
            }
            $paletteIndexes[$stateId] = $paletteByName[$name];
        }
        $air = VanillaBlocks::AIR()->getStateId();
        $blockData = '';
        for($y = 0; $y < $height; ++$y){
            for($z = 0; $z < $length; ++$z){
                for($x = 0; $x < $width; ++$x){
                    $stateId = $lookup["{$x}:{$y}:{$z}"] ?? $air;
                    if(!isset($paletteIndexes[$stateId])){
                        try{
                            $name = $serializer->serialize($stateId)->getName();
                        }catch(Throwable){
                            $name = 'minecraft:air';
                        }
                        $paletteIndexes[$stateId] = $paletteByName[$name] ?? $paletteByName['minecraft:air'] ?? 0;
                    }
                    $blockData .= $this->writeUnsignedVarInt($paletteIndexes[$stateId]);
                }
            }
        }

        $blockEntities = [];
        foreach($clipboard['tiles'] as [$x, $y, $z, $encoded]){
            try{
                $tile = $this->decodeCompound($encoded);
                $id = $tile->getString('id', 'minecraft:chest');
                $tile->removeTag('x', 'y', 'z');
                $tile->setString('Id', $this->toJavaTileId($id));
                $tile->setIntArray('Pos', [$x, $y, $z]);
                $blockEntities[] = $tile;
            }catch(Throwable){
                // Ignore malformed tile data in the compatibility layer.
            }
        }

        $metadata = CompoundTag::create()
            ->setInt('WEOffsetX', -$clipboard['offset'][0])
            ->setInt('WEOffsetY', -$clipboard['offset'][1])
            ->setInt('WEOffsetZ', -$clipboard['offset'][2])
            ->setString('Author', 'AmyBuilderGuard');

        $root = CompoundTag::create()
            ->setInt('Version', 2)
            ->setInt('DataVersion', 3465)
            ->setShort('Width', $width)
            ->setShort('Height', $height)
            ->setShort('Length', $length)
            ->setInt('PaletteMax', $nextIndex)
            ->setTag('Palette', $paletteTag)
            ->setByteArray('BlockData', $blockData)
            ->setTag('BlockEntities', new ListTag($blockEntities, NBT::TAG_Compound))
            ->setTag('Entities', new ListTag([], NBT::TAG_Compound))
            ->setTag('Metadata', $metadata)
            ->setByteArray(self::CUSTOM_DATA_TAG, $this->customPayload($clipboard));

        return $this->writeCompressedNbt($root, 'Schematic');
    }

    private function readSponge(string $raw) : array{
        $root = $this->readCompressedNbt($raw);
        if(($native = $this->readCustomPayload($root)) !== null){
            return $native;
        }
        $version = $root->getInt('Version', 1);
        $width = $this->numericTag($root, 'Width');
        $height = $this->numericTag($root, 'Height');
        $length = $this->numericTag($root, 'Length');
        if($width < 1 || $height < 1 || $length < 1){
            throw new RuntimeException('Invalid Sponge schematic dimensions');
        }

        if($version >= 3){
            $blocksRoot = $root->getCompoundTag('Blocks') ?? throw new RuntimeException('Sponge v3 Blocks tag missing');
            $paletteTag = $blocksRoot->getCompoundTag('Palette');
            $blockData = $blocksRoot->getByteArray('Data');
            $tileTag = $blocksRoot->getTag('BlockEntities');
        }else{
            $paletteTag = $root->getCompoundTag('Palette');
            $blockData = $root->getByteArray('BlockData');
            $tileTag = $root->getTag('BlockEntities');
        }
        if($paletteTag === null){
            throw new RuntimeException('Sponge palette missing');
        }

        $palette = [];
        foreach($paletteTag->getValue() as $stateString => $idTag){
            $index = (int) $idTag->getValue();
            $palette[$index] = $this->parseSpongeBlock((string) $stateString);
        }

        $blocks = [];
        $offset = 0;
        $total = $width * $height * $length;
        for($i = 0; $i < $total; ++$i){
            $paletteIndex = $this->readUnsignedVarInt($blockData, $offset);
            $x = $i % $width;
            $z = intdiv($i, $width) % $length;
            $y = intdiv($i, $width * $length);
            $blocks[] = [$x, $y, $z, $palette[$paletteIndex] ?? VanillaBlocks::AIR()->getStateId()];
        }

        $tiles = $this->readStandardTiles($tileTag);
        $metadata = $root->getCompoundTag('Metadata');
        $clipboardOffset = [0, 0, 0];
        if($metadata !== null){
            $clipboardOffset = [
                -$metadata->getInt('WEOffsetX', 0),
                -$metadata->getInt('WEOffsetY', 0),
                -$metadata->getInt('WEOffsetZ', 0)
            ];
        }
        return ['size' => [$width, $height, $length], 'offset' => $clipboardOffset, 'blocks' => $blocks, 'tiles' => $tiles];
    }

    private function writeLegacy(array $clipboard) : string{
        [$width, $height, $length] = $clipboard['size'];
        $lookup = $this->blockLookup($clipboard['blocks']);
        $air = VanillaBlocks::AIR()->getStateId();
        $total = $width * $height * $length;
        $blocks = str_repeat("\x00", $total);
        $data = str_repeat("\x00", $total);
        $serializer = GlobalBlockStateHandlers::getSerializer();

        for($y = 0; $y < $height; ++$y){
            for($z = 0; $z < $length; ++$z){
                for($x = 0; $x < $width; ++$x){
                    $index = ($y * $length + $z) * $width + $x;
                    $stateId = $lookup["{$x}:{$y}:{$z}"] ?? $air;
                    try{
                        $name = $serializer->serialize($stateId)->getName();
                    }catch(Throwable){
                        $name = 'minecraft:air';
                    }
                    [$legacyId, $legacyMeta] = $this->legacyForName($name);
                    $blocks[$index] = chr($legacyId & 0xff);
                    $data[$index] = chr($legacyMeta & 0x0f);
                }
            }
        }

        $tileEntities = [];
        foreach($clipboard['tiles'] as [$x, $y, $z, $encoded]){
            try{
                $tile = $this->decodeCompound($encoded);
                $tile->setInt('x', $x)->setInt('y', $y)->setInt('z', $z);
                $tileEntities[] = $tile;
            }catch(Throwable){
            }
        }

        $root = CompoundTag::create()
            ->setShort('Width', $width)
            ->setShort('Height', $height)
            ->setShort('Length', $length)
            ->setString('Materials', 'Alpha')
            ->setByteArray('Blocks', $blocks)
            ->setByteArray('Data', $data)
            ->setTag('Entities', new ListTag([], NBT::TAG_Compound))
            ->setTag('TileEntities', new ListTag($tileEntities, NBT::TAG_Compound))
            ->setByteArray(self::CUSTOM_DATA_TAG, $this->customPayload($clipboard));

        return $this->writeCompressedNbt($root, 'Schematic');
    }

    private function readLegacy(string $raw) : array{
        $root = $this->readCompressedNbt($raw);
        if(($native = $this->readCustomPayload($root)) !== null){
            return $native;
        }
        $width = $this->numericTag($root, 'Width');
        $height = $this->numericTag($root, 'Height');
        $length = $this->numericTag($root, 'Length');
        $blockBytes = $root->getByteArray('Blocks');
        $dataBytes = $root->getByteArray('Data');
        $addBytes = $root->getTag('AddBlocks') !== null ? $root->getByteArray('AddBlocks') : '';
        $total = $width * $height * $length;
        if($width < 1 || $height < 1 || $length < 1 || strlen($blockBytes) < $total || strlen($dataBytes) < $total){
            throw new RuntimeException('Invalid legacy schematic');
        }

        $blocks = [];
        for($i = 0; $i < $total; ++$i){
            $id = ord($blockBytes[$i]);
            if($addBytes !== ''){
                $nibble = ord($addBytes[intdiv($i, 2)] ?? "\x00");
                $id |= (($i & 1) === 0 ? ($nibble & 0x0f) : (($nibble >> 4) & 0x0f)) << 8;
            }
            $meta = ord($dataBytes[$i]) & 0x0f;
            $item = LegacyStringToItemParser::getInstance()->parse("{$id}:{$meta}");
            $stateId = $item?->getBlock()->getStateId() ?? VanillaBlocks::AIR()->getStateId();
            $x = $i % $width;
            $z = intdiv($i, $width) % $length;
            $y = intdiv($i, $width * $length);
            $blocks[] = [$x, $y, $z, $stateId];
        }
        return [
            'size' => [$width, $height, $length],
            'offset' => [0, 0, 0],
            'blocks' => $blocks,
            'tiles' => $this->readStandardTiles($root->getTag('TileEntities'))
        ];
    }

    private function parseSpongeBlock(string $stateString) : int{
        $name = strtolower(trim((string) preg_replace('/\[.*$/', '', $stateString)));
        $item = StringToItemParser::getInstance()->parse($name);
        if($item === null && str_starts_with($name, 'minecraft:')){
            $item = StringToItemParser::getInstance()->parse(substr($name, 10));
        }
        return $item?->getBlock()->getStateId() ?? VanillaBlocks::AIR()->getStateId();
    }

    /** @return list<array{0:int,1:int,2:int,3:string}> */
    private function readStandardTiles(mixed $tag) : array{
        if(!$tag instanceof ListTag){
            return [];
        }
        $result = [];
        foreach($tag->getValue() as $tile){
            if(!$tile instanceof CompoundTag){
                continue;
            }
            try{
                if($tile->getTag('Pos') !== null){
                    $pos = $tile->getIntArray('Pos');
                }else{
                    $pos = [$tile->getInt('x', 0), $tile->getInt('y', 0), $tile->getInt('z', 0)];
                }
                if(count($pos) < 3){
                    continue;
                }
                $id = $tile->getString('Id', $tile->getString('id', ''));
                $tile->removeTag('Id', 'Pos');
                $tile->setString('id', $this->toBedrockTileId($id));
                $tile->setInt('x', (int) $pos[0])->setInt('y', (int) $pos[1])->setInt('z', (int) $pos[2]);
                $result[] = [(int) $pos[0], (int) $pos[1], (int) $pos[2], $this->encodeCompound($tile)];
            }catch(Throwable){
            }
        }
        return $result;
    }

    private function toJavaTileId(string $id) : string{
        return match(strtolower($id)){
            'chest', 'minecraft:chest' => 'minecraft:chest',
            'barrel', 'minecraft:barrel' => 'minecraft:barrel',
            'furnace', 'minecraft:furnace' => 'minecraft:furnace',
            'blastfurnace', 'minecraft:blast_furnace' => 'minecraft:blast_furnace',
            'smoker', 'minecraft:smoker' => 'minecraft:smoker',
            'hopper', 'minecraft:hopper' => 'minecraft:hopper',
            'shulkerbox', 'minecraft:shulker_box' => 'minecraft:shulker_box',
            'sign', 'minecraft:sign' => 'minecraft:sign',
            default => str_starts_with(strtolower($id), 'minecraft:') ? strtolower($id) : 'minecraft:' . strtolower($id)
        };
    }

    private function toBedrockTileId(string $id) : string{
        return match(strtolower($id)){
            'minecraft:chest', 'chest' => 'Chest',
            'minecraft:barrel', 'barrel' => 'Barrel',
            'minecraft:furnace', 'furnace' => 'Furnace',
            'minecraft:blast_furnace', 'blast_furnace' => 'BlastFurnace',
            'minecraft:smoker', 'smoker' => 'Smoker',
            'minecraft:hopper', 'hopper' => 'Hopper',
            'minecraft:shulker_box', 'shulker_box' => 'ShulkerBox',
            'minecraft:sign', 'sign' => 'Sign',
            default => $id
        };
    }

    /** @param list<array{0:int,1:int,2:int,3:int}> $blocks @return array<string,int> */
    private function blockLookup(array $blocks) : array{
        $result = [];
        foreach($blocks as [$x, $y, $z, $stateId]){
            $result["{$x}:{$y}:{$z}"] = $stateId;
        }
        return $result;
    }

    private function numericTag(CompoundTag $root, string $name) : int{
        $tag = $root->getTag($name);
        return $tag === null ? 0 : (int) $tag->getValue();
    }

    private function writeCompressedNbt(CompoundTag $root, string $rootName) : string{
        $raw = (new BigEndianNbtSerializer())->write(new TreeRoot($root, $rootName));
        $compressed = gzencode($raw, 6);
        if($compressed === false){
            throw new RuntimeException('Could not compress NBT');
        }
        return $compressed;
    }

    private function readCompressedNbt(string $raw) : CompoundTag{
        $decoded = gzdecode($raw);
        if($decoded === false){
            throw new RuntimeException('Invalid gzip-compressed NBT');
        }
        $root = (new BigEndianNbtSerializer())->read($decoded)->getTag();
        if(!$root instanceof CompoundTag){
            throw new RuntimeException('NBT root is not a compound');
        }
        return $root;
    }

    private function writeUnsignedVarInt(int $value) : string{
        $result = '';
        do{
            $byte = $value & 0x7f;
            $value >>= 7;
            if($value !== 0){
                $byte |= 0x80;
            }
            $result .= chr($byte);
        }while($value !== 0);
        return $result;
    }

    private function readUnsignedVarInt(string $buffer, int &$offset) : int{
        $value = 0;
        $shift = 0;
        while(true){
            if($offset >= strlen($buffer) || $shift > 35){
                throw new RuntimeException('Malformed VarInt in schematic');
            }
            $byte = ord($buffer[$offset++]);
            $value |= ($byte & 0x7f) << $shift;
            if(($byte & 0x80) === 0){
                return $value;
            }
            $shift += 7;
        }
    }

    /** @return array{0:int,1:int} */
    private function legacyForName(string $name) : array{
        $name = strtolower(str_replace('minecraft:', '', $name));
        return match($name){
            'air' => [0, 0], 'stone' => [1, 0], 'grass', 'grass_block' => [2, 0], 'dirt' => [3, 0],
            'cobblestone' => [4, 0], 'oak_planks', 'planks' => [5, 0], 'oak_sapling' => [6, 0], 'bedrock' => [7, 0],
            'water', 'flowing_water' => [9, 0], 'lava', 'flowing_lava' => [11, 0], 'sand' => [12, 0], 'gravel' => [13, 0],
            'gold_ore' => [14, 0], 'iron_ore' => [15, 0], 'coal_ore' => [16, 0], 'oak_log', 'log' => [17, 0],
            'oak_leaves', 'leaves' => [18, 0], 'sponge' => [19, 0], 'glass' => [20, 0], 'lapis_ore' => [21, 0],
            'lapis_block' => [22, 0], 'sandstone' => [24, 0], 'white_wool', 'wool' => [35, 0], 'gold_block' => [41, 0],
            'iron_block' => [42, 0], 'bricks', 'brick_block' => [45, 0], 'tnt' => [46, 0], 'bookshelf' => [47, 0],
            'mossy_cobblestone' => [48, 0], 'obsidian' => [49, 0], 'torch' => [50, 0], 'chest' => [54, 0],
            'diamond_ore' => [56, 0], 'diamond_block' => [57, 0], 'crafting_table' => [58, 0], 'furnace' => [61, 0],
            'ladder' => [65, 0], 'rail' => [66, 0], 'lever' => [69, 0], 'stone_pressure_plate' => [70, 0],
            'redstone_ore' => [73, 0], 'redstone_torch' => [76, 0], 'snow' => [78, 0], 'ice' => [79, 0],
            'snow_block' => [80, 0], 'cactus' => [81, 0], 'clay' => [82, 0], 'netherrack' => [87, 0],
            'soul_sand' => [88, 0], 'glowstone' => [89, 0], 'stone_bricks' => [98, 0], 'iron_bars' => [101, 0],
            'glass_pane' => [102, 0], 'melon' => [103, 0], 'mycelium' => [110, 0], 'nether_bricks' => [112, 0],
            'end_stone' => [121, 0], 'emerald_ore' => [129, 0], 'emerald_block' => [133, 0], 'redstone_block' => [152, 0],
            'quartz_block' => [155, 0], 'terracotta' => [172, 0], 'coal_block' => [173, 0], 'packed_ice' => [174, 0],
            default => [0, 0]
        };
    }
}
