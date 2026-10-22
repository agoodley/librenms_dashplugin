<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long a stopped run keeps its devices: Semaphore UI's stop ends only Ansible's main process, and the step each
 * device was in runs on in a process of its own, so the plugin holds the devices of a stopped run for a while (the
 * Hold after a stop setting) unless an admin releases them. Added only when missing, so it can run again safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ansible_runs') && ! Schema::hasColumn('ansible_runs', 'held_until')) {
            Schema::table('ansible_runs', function (Blueprint $table): void {
                // a stopped run's devices stay busy until then (set to the release time when an admin releases them)
                $table->timestamp('held_until')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ansible_runs') && Schema::hasColumn('ansible_runs', 'held_until')) {
            Schema::table('ansible_runs', fn (Blueprint $table) => $table->dropColumn('held_until'));
        }
    }
};
