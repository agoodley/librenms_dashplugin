<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: what the inventory endpoint served the runner for each target while its dry run or batch was in progress
 * (Changes\InventoryGuard): the hash of the connection it served, "left_out" when the plugin left the host out (how the
 * runner reaches it was no longer the one recorded at the dry run), "mixed" when fetches served different ones; when,
 * and why it was left out. The end of the dry run or batch checks what the runner got. Only added when missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ansible_change_targets') && ! Schema::hasColumn('ansible_change_targets', 'served_hash')) {
            Schema::table('ansible_change_targets', function (Blueprint $table): void {
                $table->string('served_hash', 64)->nullable(); // a SHA-256 of the connection served, left_out or mixed (null: no fetch while it ran)
                $table->dateTime('served_at')->nullable(); // UTC: the latest fetch that served it, or the first that left it out
                $table->string('served_note', 500)->nullable(); // why it was left out
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ansible_change_targets') && Schema::hasColumn('ansible_change_targets', 'served_hash')) {
            Schema::table('ansible_change_targets', function (Blueprint $table): void {
                $table->dropColumn(['served_hash', 'served_at', 'served_note']);
            });
        }
    }
};
