<?php

namespace Agoodley\LibrenmsInventory\Data;

/**
 * Runs SQL on the plugin's tables: LibreNMS' database connection (LaravelSql), or an SQLite database in the tests, so
 * that the statements are tested as they are written. They are written for MySQL/MariaDB and SQLite alike: backquoted
 * names, bound values, times as 'Y-m-d H:i:s' strings (UTC).
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

    /**
     * Does the table exist?
     */
    public function hasTable(string $name): bool;
}
