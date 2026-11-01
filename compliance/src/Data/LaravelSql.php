<?php

namespace Agoodley\LibrenmsCompliance\Data;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    public function transaction(\Closure $callback): mixed
    {
        return DB::transaction($callback);
    }

    public function table(string $name): string
    {
        return '`' . DB::getTablePrefix() . $name . '`';
    }

    public function hasTable(string $name): bool
    {
        return Schema::hasTable($name);
    }
}
