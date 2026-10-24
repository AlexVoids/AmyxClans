<?php
declare(strict_types=1);
namespace AmyXClans\service;

final class Rules{
    public const RIGHTS = [
        'invite'=>'Приглашать игроков', 'kick'=>'Исключать игроков',
        'manage_roles'=>'Управлять ролями', 'channels'=>'Управлять клановыми каналами',
        'homes'=>'Управлять клановой точкой', 'deposit'=>'Вносить деньги в казну',
        'withdraw'=>'Снимать деньги из казны', 'history'=>'Просматривать историю',
        'wars'=>'Объявлять войны', 'regions'=>'Управлять регионами',
        'settings'=>'Менять настройки клана', 'shop'=>'Покупать улучшения',
        'change_roles'=>'Назначать роли', 'requests'=>'Рассматривать заявки'
    ];
    public static function roles() : array{
        $all = array_keys(self::RIGHTS);
        return [
            'leader'=>['name'=>'Лидер','priority'=>10,'permissions'=>$all,'color'=>'5','builtin'=>true],
            'deputy'=>['name'=>'Заместитель','priority'=>9,'permissions'=>$all,'color'=>'5','builtin'=>true],
            'member'=>['name'=>'Участник','priority'=>1,'permissions'=>[],'color'=>'5','builtin'=>true]
        ];
    }
    public static function roleName(string $name, array $roles, ?string $except = null, int $max = 24) : void{
        if(mb_strlen($name) < 2 || mb_strlen($name) > $max || !preg_match('/^[A-Za-zА-Яа-яЁё]+(?: [A-Za-zА-Яа-яЁё]+)*$/u', $name)) throw new \DomainException('Название роли: 2–' . $max . ' русских/английских букв и пробелов.');
        foreach($roles as $id=>$role) if($id !== $except && mb_strtolower($role['name']) === mb_strtolower($name)) throw new \DomainException('Название роли уже занято.');
    }
    public static function xp(array &$clan, int $amount, int $max = 5, int $threshold = 1000) : void{
        if($amount <= 0 || $clan['level'] >= $max) return;
        $clan['xp'] += $amount;
        while($clan['level'] < $max && $clan['xp'] >= $threshold){$clan['xp'] -= $threshold; ++$clan['level'];}
        if($clan['level'] >= $max) $clan['xp'] = 0;
    }
    public static function deposit(array &$clan, int $amount) : void{
        $paid = min($clan['debt'], $amount);
        $clan['debt'] -= $paid;
        $clan['bank'] += $amount - $paid;
    }
    public static function fine(array &$clan, int $amount) : void{
        $paid = min($clan['bank'], max(0,$amount));
        $clan['bank'] -= $paid;
        $clan['debt'] += max(0,$amount) - $paid;
    }
    public static function bounds(int $x, int $z, int $size) : array{
        $minX = $x - intdiv($size - 1, 2); $minZ = $z - intdiv($size - 1, 2);
        return ['minX'=>$minX,'maxX'=>$minX+$size-1,'minZ'=>$minZ,'maxZ'=>$minZ+$size-1];
    }
    public static function overlaps(array $a, array $b) : bool{
        return $a['world'] === $b['world'] && $a['minX'] <= $b['maxX'] && $a['maxX'] >= $b['minX'] && $a['minZ'] <= $b['maxZ'] && $a['maxZ'] >= $b['minZ'];
    }
    public static function contains(array $box, string $world, float $x, float $z, ?float $y = null) : bool{
        return $box['world'] === $world && floor($x) >= $box['minX'] && floor($x) <= $box['maxX'] && floor($z) >= $box['minZ'] && floor($z) <= $box['maxZ'] && ($y === null || !isset($box['minY']) || ($y >= $box['minY'] && $y < $box['maxY']+1));
    }
}
