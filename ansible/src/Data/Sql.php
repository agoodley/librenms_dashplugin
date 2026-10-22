<?php

namespace Agoodley\LibrenmsAnsible\Data;

/**
 * Runs SQL for RunRepository: LibreNMS' database connection (LaravelSql), or an SQLite database in the tests, so the
 * exact statements of the run lifecycle (the compare-and-set, the log in pieces, the busy devices) are tested. The
 * statements are written for MySQL/MariaDB and SQLite alike: backquoted names, bound values, times as
 * 'Y-m-d H:i:s' strings, booleans as 0 and 1.
 */
interface Sql
{
    /**
     * The rows of a query, as arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function select(string $query, array $bindings = []): array;

    /**
     * Run a statement that changes rows (UPDATE, DELETE, INSERT): how many it changed.
     */
    public function change(string $query, array $bindings = []): int;

    /**
     * Insert one row: its id.
     */
    public function insert(string $query, array $bindings = []): int;

    /**
     * Run $callback in one transaction: committed when it returns, rolled back when it throws.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function transaction(\Closure $callback): mixed;

    /**
     * A table's name as a statement writes it: with the connection's prefix, backquoted.
     */
    public function table(string $name): string;
}
