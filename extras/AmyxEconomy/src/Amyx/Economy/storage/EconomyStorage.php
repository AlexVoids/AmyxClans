<?php

declare(strict_types=1);

namespace Amyx\Economy\storage;

use SQLite3;
use SQLite3Result;
use SQLite3Stmt;

final class EconomyStorage{
    private SQLite3 $database;

    public function __construct(
        string $path,
        private int $startingBalance,
        private int $maximumBalance
    ){
        $this->database = new SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $this->database->enableExceptions(true);
        $this->database->busyTimeout(5000);
        $this->database->exec('PRAGMA journal_mode = WAL');
        $this->database->exec('PRAGMA synchronous = FULL');
        $this->database->exec('CREATE TABLE IF NOT EXISTS external_operations (operation_id TEXT PRIMARY KEY, account TEXT NOT NULL, delta INTEGER NOT NULL, success INTEGER NOT NULL)');
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS accounts (' .
            'name_lower TEXT PRIMARY KEY,' .
            'name TEXT NOT NULL,' .
            'balance INTEGER NOT NULL DEFAULT 0,' .
            'created_at INTEGER NOT NULL,' .
            'updated_at INTEGER NOT NULL' .
            ')'
        );
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS transactions (' .
            'id INTEGER PRIMARY KEY AUTOINCREMENT,' .
            'account_lower TEXT NOT NULL,' .
            'amount INTEGER NOT NULL,' .
            'balance_after INTEGER NOT NULL,' .
            'reason TEXT NOT NULL,' .
            'actor TEXT,' .
            'created_at INTEGER NOT NULL' .
            ')'
        );
        $this->database->exec('CREATE INDEX IF NOT EXISTS idx_accounts_balance ON accounts(balance DESC)');
        $this->database->exec('CREATE INDEX IF NOT EXISTS idx_transactions_account ON transactions(account_lower, created_at DESC)');
    }

    public function close() : void{
        $this->database->close();
    }

    public static function normalizeName(string $name) : string{
        return strtolower(trim($name));
    }

    public function ensureAccount(string $name) : void{
        $name = trim($name);
        $normalized = self::normalizeName($name);
        if($normalized === ''){
            throw new \InvalidArgumentException('Player name cannot be empty');
        }
        $now = time();
        $stmt = $this->prepare(
            'INSERT INTO accounts(name_lower, name, balance, created_at, updated_at) VALUES(:name_lower, :name, :balance, :created_at, :updated_at) ' .
            'ON CONFLICT(name_lower) DO UPDATE SET name = excluded.name, updated_at = excluded.updated_at'
        );
        $stmt->bindValue(':name_lower', $normalized, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':balance', $this->clamp($this->startingBalance), SQLITE3_INTEGER);
        $stmt->bindValue(':created_at', $now, SQLITE3_INTEGER);
        $stmt->bindValue(':updated_at', $now, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function accountExists(string $name) : bool{
        $stmt = $this->prepare('SELECT 1 FROM accounts WHERE name_lower = :name_lower LIMIT 1');
        $stmt->bindValue(':name_lower', self::normalizeName($name), SQLITE3_TEXT);
        $result = $stmt->execute();
        return $result->fetchArray(SQLITE3_NUM) !== false;
    }

    public function getCanonicalName(string $name) : ?string{
        $stmt = $this->prepare('SELECT name FROM accounts WHERE name_lower = :name_lower LIMIT 1');
        $stmt->bindValue(':name_lower', self::normalizeName($name), SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row === false ? null : (string) $row['name'];
    }

    public function getBalance(string $name) : int{
        $stmt = $this->prepare('SELECT balance FROM accounts WHERE name_lower = :name_lower LIMIT 1');
        $stmt->bindValue(':name_lower', self::normalizeName($name), SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row === false ? 0 : (int) $row['balance'];
    }

    public function setBalance(string $name, int $amount, string $reason = 'set', ?string $actor = null) : bool{
        $this->ensureAccount($name);
        $amount = $this->clamp($amount);
        $normalized = self::normalizeName($name);
        $this->database->exec('BEGIN IMMEDIATE');
        try{
            $before = $this->getBalance($normalized);
            $stmt = $this->prepare('UPDATE accounts SET balance = :balance, updated_at = :updated_at WHERE name_lower = :name_lower');
            $stmt->bindValue(':balance', $amount, SQLITE3_INTEGER);
            $stmt->bindValue(':updated_at', time(), SQLITE3_INTEGER);
            $stmt->bindValue(':name_lower', $normalized, SQLITE3_TEXT);
            $stmt->execute();
            $this->logTransaction($normalized, $amount - $before, $amount, $reason, $actor);
            $this->database->exec('COMMIT');
            return true;
        }catch(\Throwable $e){
            $this->database->exec('ROLLBACK');
            throw $e;
        }
    }

    public function deposit(string $name, int $amount, string $reason = 'deposit', ?string $actor = null) : bool{
        if($amount < 0){
            return false;
        }
        $this->ensureAccount($name);
        $normalized = self::normalizeName($name);
        $this->database->exec('BEGIN IMMEDIATE');
        try{
            $before = $this->getBalance($normalized);
            if($before > $this->maximumBalance - $amount){
                $this->database->exec('ROLLBACK');
                return false;
            }
            $after = $before + $amount;
            $this->updateBalance($normalized, $after);
            $this->logTransaction($normalized, $amount, $after, $reason, $actor);
            $this->database->exec('COMMIT');
            return true;
        }catch(\Throwable $e){
            $this->database->exec('ROLLBACK');
            throw $e;
        }
    }

    public function withdraw(string $name, int $amount, string $reason = 'withdraw', ?string $actor = null) : bool{
        if($amount < 0){
            return false;
        }
        $this->ensureAccount($name);
        $normalized = self::normalizeName($name);
        $this->database->exec('BEGIN IMMEDIATE');
        try{
            $before = $this->getBalance($normalized);
            if($before < $amount){
                $this->database->exec('ROLLBACK');
                return false;
            }
            $after = $before - $amount;
            $this->updateBalance($normalized, $after);
            $this->logTransaction($normalized, -$amount, $after, $reason, $actor);
            $this->database->exec('COMMIT');
            return true;
        }catch(\Throwable $e){
            $this->database->exec('ROLLBACK');
            throw $e;
        }
    }


    /** Idempotent extension: balance, audit and operation key commit in the same SQLite transaction. */
    public function applyExternalOperation(string $id, string $name, int $delta) : bool{
        if($id === '' || strlen($id)>200 || $delta === 0) throw new \InvalidArgumentException('Invalid external operation');
        $this->ensureAccount($name);
        $name = self::normalizeName($name);
        $this->database->exec('BEGIN IMMEDIATE');
        try{
            $q=$this->prepare('SELECT account,delta,success FROM external_operations WHERE operation_id=:id');
            $q->bindValue(':id',$id,SQLITE3_TEXT);
            $row=$q->execute()->fetchArray(SQLITE3_ASSOC);
            if($row !== false){
                if($row['account'] !== $name || (int)$row['delta'] !== $delta) throw new \LogicException('Operation key reused with different arguments');
                $this->database->exec('COMMIT');
                return (bool)$row['success'];
            }
            $before=$this->getBalance($name);
            $ok=$delta>0 ? $before <= $this->maximumBalance-$delta : $before >= -$delta;
            if($ok){
                $this->updateBalance($name,$before+$delta);
                $this->logTransaction($name,$delta,$before+$delta,'amyxclans_external',$id);
            }
            $q=$this->prepare('INSERT INTO external_operations(operation_id,account,delta,success) VALUES(:id,:name,:delta,:success)');
            $q->bindValue(':id',$id,SQLITE3_TEXT);$q->bindValue(':name',$name,SQLITE3_TEXT);
            $q->bindValue(':delta',$delta,SQLITE3_INTEGER);$q->bindValue(':success',$ok?1:0,SQLITE3_INTEGER);$q->execute();
            $this->database->exec('COMMIT'); return $ok;
        }catch(\Throwable $e){$this->database->exec('ROLLBACK');throw $e;}
    }

    public function transfer(string $sender, string $recipient, int $amount) : bool{
        if($amount <= 0){
            return false;
        }
        $senderLower = self::normalizeName($sender);
        $recipientLower = self::normalizeName($recipient);
        if($senderLower === $recipientLower || !$this->accountExists($sender) || !$this->accountExists($recipient)){
            return false;
        }

        $this->database->exec('BEGIN IMMEDIATE');
        try{
            $senderBefore = $this->getBalance($senderLower);
            $recipientBefore = $this->getBalance($recipientLower);
            if($senderBefore < $amount || $recipientBefore > $this->maximumBalance - $amount){
                $this->database->exec('ROLLBACK');
                return false;
            }
            $senderAfter = $senderBefore - $amount;
            $recipientAfter = $recipientBefore + $amount;
            $this->updateBalance($senderLower, $senderAfter);
            $this->updateBalance($recipientLower, $recipientAfter);
            $this->logTransaction($senderLower, -$amount, $senderAfter, 'pay', $recipient);
            $this->logTransaction($recipientLower, $amount, $recipientAfter, 'pay_received', $sender);
            $this->database->exec('COMMIT');
            return true;
        }catch(\Throwable $e){
            $this->database->exec('ROLLBACK');
            throw $e;
        }
    }

    /** @return array<int, array{name: string, balance: int}> */
    public function getTop(int $limit, int $offset = 0) : array{
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $stmt = $this->prepare('SELECT name, balance FROM accounts ORDER BY balance DESC, name_lower ASC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $rows = [];
        while(($row = $result->fetchArray(SQLITE3_ASSOC)) !== false){
            $rows[] = ['name' => (string) $row['name'], 'balance' => (int) $row['balance']];
        }
        return $rows;
    }

    public function getAccountCount() : int{
        $row = $this->database->querySingle('SELECT COUNT(*) FROM accounts');
        return (int) $row;
    }

    public function getRank(string $name) : ?int{
        if(!$this->accountExists($name)){
            return null;
        }
        $balance = $this->getBalance($name);
        $normalized = self::normalizeName($name);
        $stmt = $this->prepare(
            'SELECT COUNT(*) + 1 AS rank FROM accounts WHERE balance > :balance OR (balance = :balance AND name_lower < :name_lower)'
        );
        $stmt->bindValue(':balance', $balance, SQLITE3_INTEGER);
        $stmt->bindValue(':name_lower', $normalized, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row === false ? null : (int) $row['rank'];
    }

    private function updateBalance(string $normalized, int $balance) : void{
        $stmt = $this->prepare('UPDATE accounts SET balance = :balance, updated_at = :updated_at WHERE name_lower = :name_lower');
        $stmt->bindValue(':balance', $this->clamp($balance), SQLITE3_INTEGER);
        $stmt->bindValue(':updated_at', time(), SQLITE3_INTEGER);
        $stmt->bindValue(':name_lower', $normalized, SQLITE3_TEXT);
        $stmt->execute();
    }

    private function logTransaction(string $accountLower, int $amount, int $balanceAfter, string $reason, ?string $actor) : void{
        $stmt = $this->prepare(
            'INSERT INTO transactions(account_lower, amount, balance_after, reason, actor, created_at) ' .
            'VALUES(:account_lower, :amount, :balance_after, :reason, :actor, :created_at)'
        );
        $stmt->bindValue(':account_lower', $accountLower, SQLITE3_TEXT);
        $stmt->bindValue(':amount', $amount, SQLITE3_INTEGER);
        $stmt->bindValue(':balance_after', $balanceAfter, SQLITE3_INTEGER);
        $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
        $stmt->bindValue(':actor', $actor, $actor === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':created_at', time(), SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function prepare(string $sql) : SQLite3Stmt{
        $stmt = $this->database->prepare($sql);
        if(!$stmt instanceof SQLite3Stmt){
            throw new \RuntimeException('Unable to prepare SQLite query');
        }
        return $stmt;
    }

    private function clamp(int $amount) : int{
        return max(0, min($this->maximumBalance, $amount));
    }
}
