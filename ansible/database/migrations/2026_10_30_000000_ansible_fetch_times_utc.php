<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The time of each inventory fetch in UTC, as DATETIME, like the times of the runs (2026_10_28): the TIMESTAMP
 * column kept instants, read once in UTC they become those UTC times. A DATETIME column is left as it is, so the
 * migration can run again safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->convert('timestamp', 'DATETIME');
    }

    public function down(): void
    {
        $this->convert('datetime', 'TIMESTAMP');
    }

    private function convert(string $from, string $to): void
    {
        $table = DB::getTablePrefix() . 'ansible_inventory_fetches';
        if (! Schema::hasTable('ansible_inventory_fetches') || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $type = DB::selectOne('SELECT DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'fetched_at']);
        if ($type === null || strtolower((string) $type->type) !== $from) {
            return;
        }
        DB::statement("SET time_zone = '+00:00'");
        try {
            DB::statement("ALTER TABLE `$table` MODIFY `fetched_at` $to NULL DEFAULT NULL");
        } finally {
            DB::statement('SET time_zone = @@global.time_zone');
        }
    }
};
