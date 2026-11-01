<?php

namespace Agoodley\LibrenmsInventory\Tests\Unit\Fakes;

use Agoodley\LibrenmsInventory\Data\Sql;

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
        $this->pdo->exec('CREATE TABLE `inventory_config` (`name` TEXT PRIMARY KEY, `value` TEXT NOT NULL, `updated_at` TEXT)');
        $this->pdo->exec('CREATE TABLE `inventory_software` (`device_id` INTEGER PRIMARY KEY, `os` TEXT, `model` TEXT, `version` TEXT, `status` TEXT NOT NULL,
            `policy` TEXT, `message` TEXT, `status_since` TEXT NOT NULL, `version_since` TEXT NOT NULL, `updated_at` TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE `inventory_upgrades` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `device_id` INTEGER NOT NULL, `os` TEXT, `model` TEXT,
            `from_version` TEXT NOT NULL, `to_version` TEXT NOT NULL, `direction` TEXT NOT NULL, `from_since` TEXT, `seen_at` TEXT NOT NULL)');
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
