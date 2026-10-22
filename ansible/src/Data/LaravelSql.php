<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Illuminate\Support\Facades\DB;

/**
 * SQL on LibreNMS' database connection.
 */
final class LaravelSql implements Sql
{
    public function select(string $query, array $bindings = []): array
    {
        return array_map(fn ($row) => (array) $row, DB::select($query, $bindings));
    }

    public function change(string $query, array $bindings = []): int
    {
        return DB::affectingStatement($query, $bindings);
    }

    public function insert(string $query, array $bindings = []): int
    {
        DB::insert($query, $bindings);

        return (int) DB::getPdo()->lastInsertId();
    }

    public function transaction(\Closure $callback): mixed
    {
        return DB::transaction($callback);
    }

    public function table(string $name): string
    {
        return '`' . DB::getTablePrefix() . $name . '`';
    }
}
