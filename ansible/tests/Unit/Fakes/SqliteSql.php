<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit\Fakes;

use Agoodley\LibrenmsAnsible\Data\Sql;

/**
 * The plugin's tables in an SQLite database in memory (the columns of its migrations), for RunRepository's tests:
 * its statements run as they are written for LibreNMS' database. Statements are recorded; $beforeChange runs once
 * before the next statement that changes rows (another request writing in between), $onChange before each one (a
 * failure in the middle of a change). Transactions nest with savepoints, as Laravel's do.
 */
final class SqliteSql implements Sql
{
    public readonly \PDO $pdo;

    /** @var array<int, array{query: string, bindings: array}> */
    public array $statements = [];

    public ?\Closure $beforeChange = null;

    /** @var (\Closure(string, int): void)|null called with the statement and how many changes ran before it */
    public ?\Closure $onChange = null;

    private int $changes = 0;

    private int $depth = 0;

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
            `held_until` TEXT, `change_id` INTEGER, `kind` TEXT, `batch` INTEGER, `context` TEXT, `commit_hash` TEXT, `proof` TEXT)');
        $this->pdo->exec('CREATE TABLE `ansible_run_targets` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `run_id` INTEGER NOT NULL, `device_id` INTEGER NOT NULL,
            `inventory_name` TEXT NOT NULL, `hostname` TEXT NOT NULL DEFAULT \'\', `display` TEXT NOT NULL DEFAULT \'\', `result` TEXT, `counts` TEXT,
            `unexpected` INTEGER NOT NULL DEFAULT 0)');
        // phase 2: changes (2026_11_01_000000_ansible_changes)
        $this->pdo->exec('CREATE TABLE `ansible_changes` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER, `username` TEXT NOT NULL, `template_id` TEXT NOT NULL,
            `template_name` TEXT NOT NULL, `playbook` TEXT NOT NULL DEFAULT \'\', `reference` TEXT NOT NULL DEFAULT \'\', `status` TEXT NOT NULL, `version` INTEGER NOT NULL DEFAULT 0,
            `variables` TEXT, `has_secrets` INTEGER NOT NULL DEFAULT 0, `plan` TEXT NOT NULL, `target_count` INTEGER NOT NULL DEFAULT 0, `batch_count` INTEGER NOT NULL DEFAULT 0,
            `current_batch` INTEGER NOT NULL DEFAULT 0, `dry_run_id` INTEGER, `dry_runs` INTEGER NOT NULL DEFAULT 0, `dry_run_state` TEXT, `dry_run_at` TEXT, `dry_run_ended_at` TEXT,
            `dry_run_commit` TEXT, `dry_run_findings` TEXT, `plan_fingerprint` TEXT, `fingerprint_parts` TEXT, `approval_fingerprint` TEXT, `approved_by` TEXT,
            `approved_user_id` INTEGER, `approved_at` TEXT, `applied_by` TEXT, `applied_user_id` INTEGER, `apply_started_at` TEXT, `waiting` TEXT, `resume_at` TEXT,
            `stop_requested_by` TEXT, `stop_requested_at` TEXT, `error` TEXT, `finished_at` TEXT, `request_token` TEXT UNIQUE, `created_at` TEXT, `updated_at` TEXT)');
        $this->pdo->exec('CREATE TABLE `ansible_change_targets` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `change_id` INTEGER NOT NULL, `device_id` INTEGER NOT NULL,
            `inventory_name` TEXT NOT NULL, `hostname` TEXT NOT NULL DEFAULT \'\', `display` TEXT NOT NULL DEFAULT \'\', `batch` INTEGER NOT NULL, `position` INTEGER NOT NULL DEFAULT 0,
            `dry_result` TEXT, `dry_counts` TEXT, `result` TEXT, `counts` TEXT, `backup_node` TEXT, `backup_check` TEXT,
            `before_status` TEXT, `before_requested_at` TEXT, `before_at` TEXT, `before_version` TEXT, `before_error` TEXT,
            `after_status` TEXT, `after_requested_at` TEXT, `after_at` TEXT, `after_version` TEXT, `after_error` TEXT, `served_hash` TEXT, `served_at` TEXT, `served_note` TEXT,
            UNIQUE (`change_id`, `device_id`))');
        $this->pdo->exec('CREATE TABLE `ansible_change_batches` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `change_id` INTEGER NOT NULL, `number` INTEGER NOT NULL,
            `status` TEXT NOT NULL, `run_id` INTEGER, `failures` INTEGER NOT NULL DEFAULT 0, `user_id` INTEGER, `username` TEXT, `started_at` TEXT, `ended_at` TEXT, `note` TEXT,
            `blast` TEXT, `blast_accepted` TEXT, `blast_override` TEXT, UNIQUE (`change_id`, `number`))');
        $this->pdo->exec('CREATE TABLE `ansible_change_approvals` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `change_id` INTEGER NOT NULL, `user_id` INTEGER NOT NULL,
            `username` TEXT NOT NULL, `decision` TEXT NOT NULL, `comment` TEXT, `fingerprint` TEXT, `dry_run_id` INTEGER, `created_at` TEXT, `voided_at` TEXT, `void_reason` TEXT)');
        $this->pdo->exec('CREATE TABLE `ansible_change_windows` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `change_id` INTEGER NOT NULL, `batch` INTEGER NOT NULL,
            `schedule_id` INTEGER NOT NULL, `status` TEXT NOT NULL, `closing` INTEGER NOT NULL DEFAULT 0, `opened_at` TEXT, `ends_at` TEXT, `closed_at` TEXT, `error` TEXT,
            `cut_off` TEXT)');
        $this->pdo->exec('CREATE TABLE `ansible_change_diffs` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `change_id` INTEGER NOT NULL, `run_id` INTEGER NOT NULL, `device_id` INTEGER,
            `position` INTEGER NOT NULL, `task` TEXT NOT NULL DEFAULT \'\', `item` TEXT, `kind` TEXT NOT NULL, `lines` TEXT NOT NULL, `cut` INTEGER NOT NULL DEFAULT 0, `host` TEXT,
            `reason` TEXT)');
        $this->pdo->exec('CREATE TABLE `ansible_change_events` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `change_id` INTEGER NOT NULL, `at` TEXT NOT NULL, `username` TEXT,
            `kind` TEXT NOT NULL, `message` TEXT NOT NULL)');
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
        if ($this->onChange !== null) {
            ($this->onChange)($query, $this->changes);
        }
        $this->changes++;

        return $this->run($query, $bindings)->rowCount();
    }

    public function insert(string $query, array $bindings = []): int
    {
        $this->run($query, $bindings);

        return (int) $this->pdo->lastInsertId();
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
