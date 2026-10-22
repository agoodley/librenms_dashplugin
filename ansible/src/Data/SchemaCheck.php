<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Illuminate\Support\Facades\Schema;

/**
 * The checks that the plugin's tables and columns exist (./lnms migrate ran), remembered within a process for a minute:
 * each costs a query to the database's catalogue, and one page asks them many times (the device panel spent 25 of its
 * 38 queries on them). A table's columns are read once for all the checks of that table. Only what was found is
 * remembered, so a migration that just ran is seen at once.
 */
final class SchemaCheck
{
    /** Seconds what was found is remembered (a long-running process sees a table dropped after that). */
    private const TTL = 60;

    /** @var array<string, array{at: int, columns: ?string[]}> table => when it was found, and its columns once read */
    private static array $tables = [];

    /** @var (\Closure(string, string): mixed)|null the database's catalogue in the tests: ('table', $name) => bool, ('columns', $name) => string[] */
    private static ?\Closure $catalogue = null;

    /**
     * Where the checks read the catalogue (null: LibreNMS' database, through Laravel's Schema); what was found is forgotten.
     *
     * @param  (\Closure(string, string): mixed)|null  $catalogue
     */
    public static function useCatalogue(?\Closure $catalogue): void
    {
        self::$catalogue = $catalogue;
        self::forget();
    }

    /**
     * Does the table exist?
     */
    public static function hasTable(string $table): bool
    {
        if (self::known($table) !== null) {
            return true;
        }
        if (! (self::$catalogue !== null ? (bool) (self::$catalogue)('table', $table) : Schema::hasTable($table))) {
            return false;
        }
        self::$tables[$table] = ['at' => time(), 'columns' => null];

        return true;
    }

    /**
     * Does the table exist with all these columns?
     *
     * @param  string[]  $columns
     */
    public static function hasColumns(string $table, array $columns): bool
    {
        $known = self::known($table)['columns'] ?? null;
        if ($known === null) {
            $known = array_map('strtolower', self::$catalogue !== null ? (array) (self::$catalogue)('columns', $table) : Schema::getColumnListing($table));
            if (! $known) {
                return false; // no such table
            }
            self::$tables[$table] = ['at' => time(), 'columns' => $known];
        }

        return ! array_diff(array_map('strtolower', $columns), $known);
    }

    /**
     * Forget what was found (after a migration in the same process).
     */
    public static function forget(): void
    {
        self::$tables = [];
    }

    /**
     * What is remembered of a table, null when nothing (or it is too old).
     *
     * @return array{at: int, columns: ?string[]}|null
     */
    private static function known(string $table): ?array
    {
        if (isset(self::$tables[$table]) && time() - self::$tables[$table]['at'] >= self::TTL) {
            unset(self::$tables[$table]);
        }

        return self::$tables[$table] ?? null;
    }
}
