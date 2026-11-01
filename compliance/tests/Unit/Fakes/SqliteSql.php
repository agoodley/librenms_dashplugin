<?php

namespace Agoodley\LibrenmsCompliance\Tests\Unit\Fakes;

use Agoodley\LibrenmsCompliance\Data\Sql;

/**
 * The plugin's tables in an SQLite database in memory (the columns of its migration): its statements run as they are
 * written for LibreNMS' database. Transactions nest with savepoints, as Laravel's do; $failOn makes the statements
 * that contain it fail (a database that breaks in the middle of a change).
 */
final class SqliteSql implements Sql
{
    public readonly \PDO $pdo;

    public ?string $failOn = null;

    private int $depth = 0;

    public function __construct(bool $tables = true)
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        if (! $tables) {
            return;
        }
        $this->pdo->exec('CREATE TABLE `compliance_config` (`name` TEXT PRIMARY KEY, `value` TEXT NOT NULL, `updated_at` TEXT)');
        $this->pdo->exec('CREATE TABLE `compliance_devices` (`device_id` INTEGER PRIMARY KEY, `status` TEXT NOT NULL, `provider` TEXT, `backup_at` TEXT,
            `checked_at` TEXT NOT NULL, `config_hash` TEXT, `os` TEXT, `error` TEXT, `outcomes` TEXT, `ports` TEXT)');
        $this->pdo->exec('CREATE TABLE `compliance_findings` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `device_id` INTEGER NOT NULL, `rule` TEXT NOT NULL,
            `severity` TEXT NOT NULL, `message` TEXT NOT NULL, `evidence` TEXT, `ports` TEXT, `first_seen` TEXT NOT NULL, `last_seen` TEXT NOT NULL,
            UNIQUE (`device_id`, `rule`))');
    }

    public function select(string $query, array $bindings = []): array
    {
        return $this->run($query, $bindings)->fetchAll();
    }

    public function change(string $query, array $bindings = []): int
    {
        if ($this->failOn !== null && str_contains($query, $this->failOn)) {
            throw new \RuntimeException('the database failed');
        }

        return $this->run($query, $bindings)->rowCount();
    }

    public function transaction(\Closure $callback): mixed
    {
        $savepoint = 'level' . $this->depth;
        $this->depth === 0 ? $this->pdo->beginTransaction() : $this->pdo->exec("SAVEPOINT $savepoint");
        $this->depth++;
        try {
            $result = $callback();
            $this->depth--;
            $this->depth === 0 ? $this->pdo->commit() : $this->pdo->exec("RELEASE SAVEPOINT $savepoint");

            return $result;
        } catch (\Throwable $e) {
            $this->depth--;
            $this->depth === 0 ? $this->pdo->rollBack() : $this->pdo->exec("ROLLBACK TO SAVEPOINT $savepoint");

            throw $e;
        }
    }

    public function table(string $name): string
    {
        return "`$name`";
    }

    public function hasTable(string $name): bool
    {
        return (bool) $this->run("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$name])->fetch();
    }

    private function run(string $query, array $bindings): \PDOStatement
    {
        $statement = $this->pdo->prepare($query);
        foreach (array_values($bindings) as $index => $value) {
            // as Laravel binds them
            $value = is_bool($value) ? (int) $value : $value;
            $statement->bindValue($index + 1, $value, match (true) {
                is_int($value) => \PDO::PARAM_INT,
                $value === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            });
        }
        $statement->execute();

        return $statement;
    }
}
