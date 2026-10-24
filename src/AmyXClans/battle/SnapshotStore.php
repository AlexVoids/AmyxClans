<?php
declare(strict_types=1);
namespace AmyXClans\battle;
use AmyXClans\Main;
use AmyXClans\storage\Database;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\nbt\{LittleEndianNbtSerializer,TreeRoot};
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\block\tile\TileFactory;

/** Incremental capture/restore. Persistent Bedrock block state NBT survives runtime ID changes. */
final class SnapshotStore{
    private array $jobs=[];
    public function __construct(private Main $p){@mkdir($p->getDataFolder().'snapshots',0777,true);}
    private function file(string $id) : string{return $this->p->getDataFolder().'snapshots/'.$id.'.dat';}
    public function exists(string $id) : bool{return is_file($this->file($id));}
    public function pending(string $id) : bool{return isset($this->jobs[$id]);}
    public function capture(string $id,array $bounds,callable $done) : void{$this->jobs[$id]=[$this->captureIterator($id,$bounds),$done];}
    public function restore(string $id,callable $done) : void{
        if(!$this->exists($id))throw new \RuntimeException('Отсутствует снимок арены '.$id);
        $this->jobs[$id]=[$this->restoreIterator($id),$done];
    }
    public function tick() : void{
        $budget=max(1,(int)$this->p->cfg('snapshot.blocks-per-tick',2048));
        foreach(array_keys($this->jobs) as $id){
            [$gen,$done]=$this->jobs[$id];
            try{for($i=0;$i<$budget && $gen->valid();++$i) $gen->next();}catch(\Throwable $e){unset($this->jobs[$id]);throw $e;}
            if(!$gen->valid()){unset($this->jobs[$id]);$done();}
            break;
        }
    }
    private function captureIterator(string $id,array $b) : \Generator{
        $world=$this->p->arenas->world($b['world']);if($world===null)throw new \RuntimeException('Мир снимка недоступен.');
        $nbt=new LittleEndianNbtSerializer();$palette=[];$paletteIndex=[];$blocks=[];$tiles=[];
        for($x=$b['minX'];$x<=$b['maxX'];++$x)for($z=$b['minZ'];$z<=$b['maxZ'];++$z){
            $world->loadChunk($x>>4,$z>>4);
            for($y=$b['minY'];$y<=$b['maxY'];++$y){
                $block=$world->getBlockAt($x,$y,$z);$state=$block->getStateId();
                if(!isset($paletteIndex[$state])){$paletteIndex[$state]=count($palette);$palette[]=$nbt->write(new TreeRoot(GlobalBlockStateHandlers::getSerializer()->serializeBlock($block)->toNbt()));}
                $i=count($blocks);$blocks[]=$paletteIndex[$state];$tile=$world->getTileAt($x,$y,$z);if($tile!==null)$tiles[$i]=$nbt->write(new TreeRoot($tile->saveNBT()));
                yield;
            }
        }
        Database::atomic($this->file($id),gzcompress(serialize(['bounds'=>$b,'palette'=>$palette,'blocks'=>$blocks,'tiles'=>$tiles]),6));
    }
    private function restoreIterator(string $id) : \Generator{
        $raw=gzuncompress(file_get_contents($this->file($id)));if($raw===false)throw new \RuntimeException('Повреждён снимок '.$id);
        $data=unserialize($raw,['allowed_classes'=>false]);$b=$data['bounds'];$w=$this->p->arenas->world($b['world']);if($w===null)throw new \RuntimeException('Мир восстановления недоступен.');
        $nbt=new LittleEndianNbtSerializer();$palette=[];
        foreach($data['palette'] as $entry)$palette[]=GlobalBlockStateHandlers::getDeserializer()->deserializeBlock(BlockStateData::fromNbt($nbt->read($entry)->mustGetCompoundTag()));
        $i=0;
        for($x=$b['minX'];$x<=$b['maxX'];++$x)for($z=$b['minZ'];$z<=$b['maxZ'];++$z){
            $w->loadChunk($x>>4,$z>>4);
            for($y=$b['minY'];$y<=$b['maxY'];++$y){
                $old=$w->getTileAt($x,$y,$z);if($old!==null)$old->close();
                $w->setBlockAt($x,$y,$z,clone $palette[$data['blocks'][$i]],false);
                if(isset($data['tiles'][$i])){
                    $created=$w->getTileAt($x,$y,$z);if($created!==null)$created->close();
                    $tile=TileFactory::getInstance()->createFromData($w,$nbt->read($data['tiles'][$i])->mustGetCompoundTag());if($tile!==null)$w->addTile($tile);
                }
                ++$i;yield;
            }
        }
        if(!$w->save(true))throw new \RuntimeException('Мир арены не удалось сохранить после восстановления.');
    }
    public function wall(array $a,bool $closed) : void{
        $b=$a['wall'];$w=$this->p->arenas->world($b['world']);if($w===null)throw new \DomainException('Мир стены недоступен.');
        for($x=$b['minX'];$x<=$b['maxX'];++$x)for($z=$b['minZ'];$z<=$b['maxZ'];++$z)for($y=$b['minY'];$y<=$b['maxY'];++$y)$w->setBlockAt($x,$y,$z,$closed?\pocketmine\block\VanillaBlocks::GLASS():\pocketmine\block\VanillaBlocks::AIR(),false);
    }
    public function forget(string $id) : void{if($this->exists($id))unlink($this->file($id));}
}
