<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the run lifecycle (Runs\RunLifecycle) keeps besides the first tables: a version per run for compare-and-set
 * writes, the nonce of the launch's task message, how long the runner has not found the task, the stop the plugin
 * or a user asked for, who released a run's devices, how long a lost or released run is still watched for a late
 * end, the tail of a cut log, and the hosts the runner ran on that were not targets. Columns are only added when
 * missing, so the migration can run again safely.
 */
return new class extends Migration
{
    /** ansible_runs columns => how to add them. */
    private const RUN_COLUMNS = ['version', 'launch_nonce', 'missing_since', 'missing_checks', 'stop_requested_at', 'stop_reason', 'released_by',
        'runner_seen_at', 'watch_until', 'output_tail', 'output_omitted'];

    public function up(): void
    {
        if (Schema::hasTable('ansible_runs')) {
            $missing = array_values(array_filter(self::RUN_COLUMNS, fn ($column) => ! Schema::hasColumn('ansible_runs', $column)));
            if ($missing) {
                Schema::table('ansible_runs', function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $column) {
                        match ($column) {
                            'version' => $table->unsignedInteger('version')->default(0), // bumped by every write of the lifecycle
                            'launch_nonce' => $table->string('launch_nonce', 16)->nullable(), // in the task's message, to find it again
                            'missing_since' => $table->timestamp('missing_since')->nullable(), // the runner first did not find the task
                            'missing_checks' => $table->unsignedSmallInteger('missing_checks')->default(0), // ... and how many times since
                            'stop_requested_at' => $table->timestamp('stop_requested_at')->nullable(),
                            'stop_reason' => $table->string('stop_reason', 255)->nullable(), // set when the plugin stopped it (Stop after)
                            'released_by' => $table->string('released_by', 255)->nullable(),
                            'runner_seen_at' => $table->timestamp('runner_seen_at')->nullable(), // the runner last answered about the task
                            'watch_until' => $table->timestamp('watch_until')->nullable(), // a lost or released run is checked for a late end
                            'output_tail' => $table->longText('output_tail')->nullable(), // the end of a log cut at the output size
                            'output_omitted' => $table->unsignedInteger('output_omitted')->default(0), // lines between the head and the tail
                        };
                    }
                });
            }
        }
        if (Schema::hasTable('ansible_run_targets') && ! Schema::hasColumn('ansible_run_targets', 'unexpected')) {
            Schema::table('ansible_run_targets', function (Blueprint $table): void {
                // a device the runner ran on although it was not a target
                $table->boolean('unexpected')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ansible_runs')) {
            $present = array_values(array_filter(self::RUN_COLUMNS, fn ($column) => Schema::hasColumn('ansible_runs', $column)));
            if ($present) {
                Schema::table('ansible_runs', fn (Blueprint $table) => $table->dropColumn($present));
            }
        }
        if (Schema::hasTable('ansible_run_targets') && Schema::hasColumn('ansible_run_targets', 'unexpected')) {
            Schema::table('ansible_run_targets', fn (Blueprint $table) => $table->dropColumn('unexpected'));
        }
    }
};
