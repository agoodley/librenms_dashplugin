<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index for the maps at a point in time (HistoryRepository::factsAt()): the facts present at a snapshot are those
 * of a kind whose removed_at is empty or after it and whose first_seen is at or before it, and their devices decide
 * which belong to the map. With these columns in one index they are found among the entries of a long history
 * without reading its rows. It starts with (kind, removed_at), so it takes the place of that index. Only added
 * once, so it can run again.
 */
return new class extends Migration
{
    private const INDEX = 'topology_facts_kind_time_devices_index';

    private const REPLACED = 'topology_facts_kind_removed_at_index';

    public function up(): void
    {
        if (! Schema::hasTable('topology_facts') || Schema::hasIndex('topology_facts', self::INDEX)) {
            return;
        }
        Schema::table('topology_facts', function (Blueprint $table): void {
            $table->index(['kind', 'removed_at', 'first_seen', 'device_a', 'device_b'], self::INDEX);
        });
        if (Schema::hasIndex('topology_facts', self::REPLACED)) {
            Schema::table('topology_facts', function (Blueprint $table): void {
                $table->dropIndex(self::REPLACED);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('topology_facts')) {
            return;
        }
        if (! Schema::hasIndex('topology_facts', self::REPLACED)) {
            Schema::table('topology_facts', function (Blueprint $table): void {
                $table->index(['kind', 'removed_at'], self::REPLACED);
            });
        }
        if (Schema::hasIndex('topology_facts', self::INDEX)) {
            Schema::table('topology_facts', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
