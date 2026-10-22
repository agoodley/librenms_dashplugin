<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit\Fakes;

use Agoodley\LibrenmsAnsible\Data\Sql;

/**
 * The plugin's tables in an SQLite database in memory (the columns of its migrations), for RunRepository's tests:
 * its statements run as they are written for LibreNMS' database. Statements are recorded; $beforeChange runs once
 * before the next statement that changes rows (another request writing in between, or a failure).
 */
final class SqliteSql implements Sql
{
    public readonly \PDO $pdo;

    /** @var array<int, array{query: string, bindings: array}> */
    public array $statements = [];

    public ?\Closure $beforeChange = null;

    public function __construct()
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE `ansible_runs` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER, `username` TEXT NOT NULL, `runner` TEXT NOT NULL,
            `runner_project` INTEGER NOT NULL, `template_id` TEXT NOT NULL, `template_name` TEXT NOT NULL, `playbook` TEXT NOT NULL DEFAULT \'\', `limit` TEXT NOT NULL,
            `variables` TEXT, `request_token` TEXT UNIQUE, `runner_task_id` TEXT, `status` TEXT NOT NULL, `runner_status` TEXT, `output` TEXT,
            `output_lines` INTEGER NOT NULL DEFAULT 0, `output_truncated` INTEGER NOT NULL DEFAULT 0, `summary` TEXT, `unexpected_hosts` TEXT, `error` TEXT,
            `poll_error` TEXT, `stopped_by` TEXT, `started_at` TEXT, `finished_at` TEXT, `polled_at` TEXT, `runner_final_at` TEXT, `created_at` TEXT, `updated_at` TEXT,
            `version` INTEGER NOT NULL DEFAULT 0, `launch_nonce` TEXT, `missing_since` TEXT, `missing_checks` INTEGER NOT NULL DEFAULT 0, `stop_requested_at` TEXT,
            `stop_reason` TEXT, `released_by` TEXT, `runner_seen_at` TEXT, `watch_until` TEXT, `output_tail` TEXT, `output_omitted` INTEGER NOT NULL DEFAULT 0,
            `held_until` TEXT)');
        $this->pdo->exec('CREATE TABLE `ansible_run_targets` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `run_id` INTEGER NOT NULL, `device_id` INTEGER NOT NULL,
            `inventory_name` TEXT NOT NULL, `hostname` TEXT NOT NULL DEFAULT \'\', `display` TEXT NOT NULL DEFAULT \'\', `result` TEXT, `counts` TEXT,
            `unexpected` INTEGER NOT NULL DEFAULT 0)');
    }

    public function select(string $query, array $bindings = []): array
    {
        return $this->run($query, $bindings)->fetchAll();
    }

    public function change(string $query, array $bindings = []): int
    {
        if ($this->beforeChange !== null) {
            $hook = $this->beforeChange;
            $this->beforeChange = null;
            $hook($query);
        }

        return $this->run($query, $bindings)->rowCount();
    }

    public function insert(string $query, array $bindings = []): int
    {
        $this->run($query, $bindings);

        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(\Closure $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function table(string $name): string
    {
        return "`$name`";
    }

    private function run(string $query, array $bindings): \PDOStatement
    {
        $this->statements[] = ['query' => $query, 'bindings' => $bindings];
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
