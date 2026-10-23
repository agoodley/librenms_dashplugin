<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: the blast radius of each batch, from the topology plugin. A batch keeps the blast radius recomputed before it
 * started and right before its launch (blast: the monitored devices it cuts off then, compared with the one approved
 * with the change), the one an approver accepted when it grew (blast_accepted), and an admin's decision to go on when
 * it could not be computed (blast_override); a maintenance window keeps the devices it covers besides the batch's own:
 * those the batch cuts off (cut_off). The approved blast radius is part of the change's plan (ansible_changes.plan).
 * JSON; only added when missing.
 */
return new class extends Migration
{
    private const BATCH_COLUMNS = ['blast', 'blast_accepted', 'blast_override'];

    public function up(): void
    {
        if (Schema::hasTable('ansible_change_batches')) {
            $missing = array_values(array_filter(self::BATCH_COLUMNS, fn ($column) => ! Schema::hasColumn('ansible_change_batches', $column)));
            if ($missing) {
                Schema::table('ansible_change_batches', function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $column) {
                        $table->text($column)->nullable();
                    }
                });
            }
        }
        if (Schema::hasTable('ansible_change_windows') && ! Schema::hasColumn('ansible_change_windows', 'cut_off')) {
            Schema::table('ansible_change_windows', function (Blueprint $table): void {
                $table->text('cut_off')->nullable(); // JSON: device ids the window covers besides the batch's own (null: none)
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ansible_change_batches')) {
            $present = array_values(array_filter(self::BATCH_COLUMNS, fn ($column) => Schema::hasColumn('ansible_change_batches', $column)));
            if ($present) {
                Schema::table('ansible_change_batches', function (Blueprint $table) use ($present): void {
                    $table->dropColumn($present);
                });
            }
        }
        if (Schema::hasTable('ansible_change_windows') && Schema::hasColumn('ansible_change_windows', 'cut_off')) {
            Schema::table('ansible_change_windows', function (Blueprint $table): void {
                $table->dropColumn('cut_off');
            });
        }
    }
};
