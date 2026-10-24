<?php
declare(strict_types=1);
namespace Amyx\CommandAccess;

final class Policy{
    public const AUTH = ['register', 'login', 'changepassword', 'security', 'emailcode'];
    public const WORLD_EDIT = ['wand','setinfo','pos1','pos2','sel','size','expand','contract','set','replace','walls','outline','copy','cut','paste','rotate','undo','redo','sphere','hsphere','cyl','hcyl','line','schem','we','worldedit'];
    private const MINE_ADMIN = ['create','settings','reset','delete','addblock','delblock','resettime','rename','setspawn','repos'];
    /** @var array<string,true> */
    private array $allowed = [];

    public function __construct(array $allowed){
        foreach($allowed as $label){
            if(is_string($label) && preg_match('/^[a-z0-9_]+$/D', $label) === 1){
                $this->allowed[$label] = true;
            }
        }
    }
    public function allows(string $label) : bool{ return isset($this->allowed[strtolower($label)]); }
    public function normalize(string $label) : string{
        return match(strtolower($label)){ 'tpaccept' => 'tpc', 'tpdeny' => 'tpd', default => strtolower($label) };
    }
    public function labels() : array{ return array_keys($this->allowed); }
    public function isAuth(string $label) : bool{ return in_array($label, self::AUTH, true); }
    public function isWorldEdit(string $label) : bool{ return in_array($label, self::WORLD_EDIT, true); }
    public function safeMineArguments(array $args) : bool{
        return count($args) <= 1 && (!isset($args[0]) || !in_array(strtolower($args[0]), self::MINE_ADMIN, true));
    }
}
