<?php

declare(strict_types=1);

namespace Amyx\Economy\api;

use Amyx\Economy\Main;
use Amyx\Economy\storage\EconomyStorage;
use pocketmine\player\Player;

final class EconomyAPI{
    private static ?self $instance = null;

    private function __construct(
        private Main $plugin,
        private EconomyStorage $storage
    ){}

    public static function initialize(Main $plugin, EconomyStorage $storage) : self{
        return self::$instance = new self($plugin, $storage);
    }

    public static function getInstance() : self{
        return self::$instance ?? throw new \LogicException('AmyxEconomy API is not initialized');
    }

    public function ensureAccount(string $playerName) : void{
        $this->storage->ensureAccount($playerName);
    }

    public function accountExists(string $playerName) : bool{
        return $this->storage->accountExists($playerName);
    }

    public function getBalance(string $playerName) : int{
        return $this->storage->getBalance($playerName);
    }

    public function setBalance(string $playerName, int $amount, string $reason = 'api_set', ?string $actor = null) : bool{
        return $this->storage->setBalance($playerName, $amount, $reason, $actor);
    }

    public function deposit(string $playerName, int $amount, string $reason = 'api_deposit', ?string $actor = null) : bool{
        return $this->storage->deposit($playerName, $amount, $reason, $actor);
    }

    public function withdraw(string $playerName, int $amount, string $reason = 'api_withdraw', ?string $actor = null) : bool{
        return $this->storage->withdraw($playerName, $amount, $reason, $actor);
    }

    public function applyExternalOperation(string $operationId, string $playerName, int $delta) : bool{
        return $this->storage->applyExternalOperation($operationId, $playerName, $delta);
    }

    public function transfer(string $sender, string $recipient, int $amount) : bool{
        return $this->storage->transfer($sender, $recipient, $amount);
    }

    /** @return array<int, array{name: string, balance: int}> */
    public function getTop(int $limit = 10, int $offset = 0) : array{
        return $this->storage->getTop($limit, $offset);
    }

    public function getRank(string $playerName) : ?int{
        return $this->storage->getRank($playerName);
    }

    public function getCurrencyName() : string{
        return (string) $this->plugin->getConfig()->get('currency-name', 'монет');
    }

    public function getCurrencySymbol() : string{
        return (string) $this->plugin->getConfig()->get('currency-symbol', '⛃');
    }

    public function format(int $amount, bool $withCurrency = true) : string{
        $separator = (string) $this->plugin->getConfig()->get('thousands-separator', '.');
        $formatted = number_format($amount, 0, '.', $separator);
        return $withCurrency ? $formatted . ' ' . $this->getCurrencyName() : $formatted;
    }

    public function formatShort(int $amount) : string{
        $abs = abs($amount);
        if($abs >= 1_000_000_000){
            return $this->trimNumber($amount / 1_000_000_000) . 'B';
        }
        if($abs >= 1_000_000){
            return $this->trimNumber($amount / 1_000_000) . 'M';
        }
        if($abs >= 1_000){
            return $this->trimNumber($amount / 1_000) . 'K';
        }
        return (string) $amount;
    }

    private function trimNumber(float $number) : string{
        return rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.');
    }

    public function parseAmount(string $input) : ?int{
        $input = strtolower(str_replace([' ', ','], ['', '.'], trim($input)));
        if(!preg_match('/^(\d+(?:\.\d{1,2})?)([kmb])?$/', $input, $matches)){
            return null;
        }
        $multiplier = match($matches[2] ?? ''){
            'k' => 1_000,
            'm' => 1_000_000,
            'b' => 1_000_000_000,
            default => 1
        };
        $value = (int) round((float) $matches[1] * $multiplier);
        return $value >= 0 ? $value : null;
    }

    public function replacePlaceholders(string $text, ?Player $player = null) : string{
        $playerName = $player?->getName();
        $balance = $playerName !== null ? $this->getBalance($playerName) : 0;
        $rank = $playerName !== null ? ($this->getRank($playerName) ?? 0) : 0;

        $simple = [
            '%amyx_balance%' => $this->format($balance, false),
            '%amyx_balance_raw%' => (string) $balance,
            '%amyx_balance_formatted%' => $this->format($balance, true),
            '%amyx_balance_short%' => $this->formatShort($balance),
            '%amyx_money%' => $this->format($balance, false),
            '%amyx_rank%' => (string) $rank,
            '%amyx_currency%' => $this->getCurrencyName(),
            '%amyx_currency_symbol%' => $this->getCurrencySymbol(),
            '%money%' => $this->format($balance, false),
            '%balance%' => $this->format($balance, false),
            '{amyx_balance}' => $this->format($balance, false),
            '{amyx_balance_raw}' => (string) $balance,
            '{amyx_balance_formatted}' => $this->format($balance, true),
            '{amyx_balance_short}' => $this->formatShort($balance),
            '{amyx_money}' => $this->format($balance, false),
            '{amyx_rank}' => (string) $rank,
            '{money}' => $this->format($balance, false),
            '{balance}' => $this->format($balance, false),
        ];
        $text = strtr($text, $simple);

        $text = preg_replace_callback('/%amyx_(balance|balance_raw|balance_short|rank)_([A-Za-z0-9_]{1,16})%/i', function(array $m) : string{
            $name = $m[2];
            return match(strtolower($m[1])){
                'balance' => $this->format($this->getBalance($name), false),
                'balance_raw' => (string) $this->getBalance($name),
                'balance_short' => $this->formatShort($this->getBalance($name)),
                'rank' => (string) ($this->getRank($name) ?? 0),
                default => $m[0]
            };
        }, $text) ?? $text;

        $text = preg_replace_callback('/%amyx_top_(\d{1,3})_(name|balance|balance_raw|balance_short)%/i', function(array $m) : string{
            $position = max(1, min(100, (int) $m[1]));
            $row = $this->getTop(1, $position - 1)[0] ?? null;
            if($row === null){
                return '-';
            }
            return match(strtolower($m[2])){
                'name' => $row['name'],
                'balance' => $this->format($row['balance'], false),
                'balance_raw' => (string) $row['balance'],
                'balance_short' => $this->formatShort($row['balance']),
                default => '-'
            };
        }, $text) ?? $text;

        return $text;
    }
}
