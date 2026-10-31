<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Infrastructure;

/** Domain services share this connection, including the Flarum transaction coordinator. */
final class Db
{
    public bool $trace = false;
    public array $queries = [];
    private int $sequence = 0;
    public function __construct(public readonly \PDO $pdo, public readonly string $prefix = '', private ?\Illuminate\Database\ConnectionInterface $coordinator = null)
    {
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) throw new \InvalidArgumentException('Invalid table prefix');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    public function native(string $table): string
    {
        if (!preg_match('/^[a-z_]+$/D', $table)) throw new \InvalidArgumentException('Invalid table');
        return '`'.$this->prefix.$table.'`';
    }
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $sql = preg_replace('/\blgc_([a-z_]+)\b/', $this->prefix.'lgc_$1', $sql);
        if ($this->trace) $this->queries[] = $sql; // SQL templates only, never bindings or credentials.
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
    public function one(string $sql, array $params = []): ?array { return $this->run($sql, $params)->fetch(\PDO::FETCH_ASSOC) ?: null; }
    public function all(string $sql, array $params = []): array { return $this->run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC); }
    public function scalar(string $sql, array $params = []): mixed { return $this->run($sql, $params)->fetchColumn(); }
    public function transaction(callable $work): mixed
    {
        if ($this->coordinator) return $this->coordinator->transaction($work, $this->coordinator->transactionLevel() ? 1 : 4);
        $outer = !$this->pdo->inTransaction();
        for ($attempt = 0; ; $attempt++) {
            $savepoint = 'lgc_sp_'.++$this->sequence;
            if ($outer) $this->pdo->beginTransaction(); else $this->pdo->exec('SAVEPOINT '.$savepoint);
            try {
                $result = $work();
                if ($outer) $this->pdo->commit(); else $this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
                return $result;
            } catch (\Throwable $error) {
                if ($this->pdo->inTransaction()) { if ($outer) $this->pdo->rollBack(); else $this->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint); }
                if (!$outer || !$error instanceof \PDOException || !in_array((int) ($error->errorInfo[1] ?? 0), [1205,1213], true) || $attempt >= 3) throw $error;
                usleep(random_int(2000, 12000));
            }
        }
    }
    public function source(string $key, int $user, bool $write = false): int
    {
        $row=$this->one('SELECT version FROM lgc_source_states WHERE source_key=? AND user_id=?',[$key,$user]);
        if (!$row) { $this->run("INSERT IGNORE INTO lgc_source_states(source_key,user_id,version,updated_at) VALUES(?,?,0,?)", [$key,$user,Data::now()]); $row=$this->one('SELECT version FROM lgc_source_states WHERE source_key=? AND user_id=? LOCK IN SHARE MODE',[$key,$user]); }
        if (!$write) return (int)$row['version'];
        if ($write) $this->run('UPDATE lgc_source_states SET version=version+1,updated_at=? WHERE source_key=? AND user_id=?', [Data::now(),$key,$user]);
        return (int) $this->scalar('SELECT version FROM lgc_source_states WHERE source_key=? AND user_id=?', [$key,$user]);
    }
}
