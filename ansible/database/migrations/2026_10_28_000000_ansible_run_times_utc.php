<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The times of the runs in UTC, as DATETIME: the plugin writes and compares them in UTC whatever the PHP time zone
 * of the web server or the sync command (Support\Clock), so its deadlines do not depend on those agreeing. The
 * TIMESTAMP columns kept instants, shown in the connection's time zone: read once in UTC, they become those UTC
 * times. A DATETIME column is left as it is, so the migration can run again safely.
 */
return new class extends Migration
{
    /** The time columns of ansible_runs. */
    private const COLUMNS = ['started_at', 'finished_at', 'polled_at', 'runner_final_at', 'created_at', 'updated_at', 'missing_since',
        'stop_requested_at', 'runner_seen_at', 'watch_until', 'held_until'];

    public function up(): void
    {
        $this->convert('timestamp', 'DATETIME');
    }

    public function down(): void
    {
        $this->convert('datetime', 'TIMESTAMP');
    }

    /**
     * The columns of one type as another, their values read and written in UTC.
     */
    private function convert(string $from, string $to): void
    {
        if (! Schema::hasTable('ansible_runs') || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $columns = [];
        foreach (DB::select('SELECT COLUMN_NAME AS name, DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [DB::getTablePrefix() . 'ansible_runs']) as $column) {
            if (in_array($column->name, self::COLUMNS, true) && strtolower($column->type) === $from) {
                $columns[] = "MODIFY `{$column->name}` $to NULL DEFAULT NULL";
            }
        }
        if (! $columns) {
            return;
        }
        DB::statement("SET time_zone = '+00:00'");
        try {
            DB::statement('ALTER TABLE `' . DB::getTablePrefix() . 'ansible_runs` ' . implode(', ', $columns));
        } finally {
            DB::statement('SET time_zone = @@global.time_zone');
        }
    }
};
