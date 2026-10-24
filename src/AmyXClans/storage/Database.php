<?php
declare(strict_types=1);
namespace AmyXClans\storage;

/** clans.yml remains the source of truth; runtime and settlements share its commit. */
final class Database{
    public array $clans = [];
    public array $runtime = [];
    private bool $transaction = false;
    public function __construct(private string $folder){
        @mkdir($folder, 0777, true);
        $path = $folder . 'clans.yml';
        if(is_file($path)){
            $data = yaml_parse_file($path);
            if(!is_array($data) || !isset($data['clans']) || !is_array($data['clans'])) throw new \RuntimeException('Некорректный clans.yml: загрузка остановлена, файл сохранён.');
            $this->clans = $data['clans'];
            $this->runtime = (array) ($data['runtime'] ?? []);
        }
    }
    public static function atomic(string $path, string $data) : void{
        $tmp = $path . '.tmp';
        $fp = fopen($tmp, 'wb');
        if($fp === false) throw new \RuntimeException('Не удалось открыть ' . $tmp);
        try{
            $written = 0;
            while($written < strlen($data)){
                $n = fwrite($fp, substr($data, $written));
                if($n === false || $n === 0) throw new \RuntimeException('Ошибка записи ' . $path);
                $written += $n;
            }
            fflush($fp);
            if(function_exists('fsync')) fsync($fp);
        }finally{fclose($fp);}
        if(!rename($tmp, $path)) throw new \RuntimeException('Не удалось заменить ' . $path);
    }
    public function save() : void{
        if($this->transaction) return;
        self::atomic($this->folder . 'clans.yml', yaml_emit(['clans' => $this->clans, 'runtime' => $this->runtime], YAML_UTF8_ENCODING));
        // The legacy index is derived; failure here cannot undo the authoritative commit.
        $index = [];
        foreach($this->clans as $id => $clan) foreach($clan['members'] as $name => $_) $index[$name] = $id;
        try{self::atomic($this->folder . 'players.yml', yaml_emit(['players' => $index], YAML_UTF8_ENCODING));}catch(\Throwable $e){error_log('AmyXClans: индекс players.yml будет пересоздан: '.$e->getMessage());}
    }
    public function transact(callable $operation) : mixed{
        if($this->transaction) return $operation();
        $before = serialize([$this->clans, $this->runtime]);
        $this->transaction = true;
        try{
            $result = $operation();
            $this->transaction = false;
            $this->save();
            return $result;
        }catch(\Throwable $e){
            $this->transaction = false;
            [$this->clans, $this->runtime] = unserialize($before, ['allowed_classes' => false]);
            throw $e;
        }
    }
    public function backup() : void{
        $dest = $this->folder . 'backups/pre-v2-' . date('Ymd-His') . '/';
        @mkdir($dest, 0777, true);
        foreach(['clans.yml','players.yml','config.yml','texts.yml'] as $file){
            if(is_file($this->folder . $file) && !copy($this->folder . $file, $dest . $file)) throw new \RuntimeException('Ошибка резервного копирования ' . $file);
        }
    }
}
