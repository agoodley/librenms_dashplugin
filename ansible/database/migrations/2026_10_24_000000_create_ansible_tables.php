<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Ansible plugin's tables: the audit log of runs (ansible_runs) and their targets with the per-device
 * result (ansible_run_targets), the runner's inventory fetches (ansible_inventory_fetches), and the runner
 * connection, stored encrypted (ansible_config). Plus the run permission, a permission of the plugin's own that
 * admins grant to users on the settings page. Tables are only created when missing, so the migration can run
 * again safely.
 */
return new class extends Migration
{
    /** The permission that lets a LibreNMS admin launch and stop runs (Support\Access::PERMISSION). */
    private const PERMISSION = 'ansible.run';

    public function up(): void
    {
        if (! Schema::hasTable('ansible_runs')) {
            Schema::create('ansible_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('user_id')->nullable(); // users.user_id of who launched it
                $table->string('username', 255);
                $table->string('runner', 32); // semaphore
                $table->unsignedInteger('runner_project');
                $table->string('template_id', 64);
                $table->string('template_name', 255);
                $table->string('playbook', 255)->default('');
                $table->text('limit'); // the inventory names sent as the limit, comma separated
                $table->text('variables')->nullable(); // JSON: the answers, secret ones without their value
                $table->string('request_token', 64)->nullable()->unique(); // one run per launch form (double submits)
                $table->string('runner_task_id', 64)->nullable();
                $table->string('status', 16);
                $table->string('runner_status', 32)->nullable();
                $table->longText('output')->nullable(); // capped (output size setting)
                $table->unsignedInteger('output_lines')->default(0); // lines of the runner's log read so far
                $table->boolean('output_truncated')->default(false);
                $table->text('summary')->nullable(); // JSON: targets per result
                $table->text('unexpected_hosts')->nullable(); // JSON: hosts in the PLAY RECAP that were not targets
                $table->text('error')->nullable();
                $table->string('poll_error', 500)->nullable();
                $table->string('stopped_by', 255)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('polled_at')->nullable();
                $table->timestamp('runner_final_at')->nullable(); // when the runner first reported the task finished
                $table->timestamps();
                $table->index('status');
                $table->index('created_at');
                $table->index('user_id');
                $table->index('template_id');
            });
        }
        if (! Schema::hasTable('ansible_run_targets')) {
            Schema::create('ansible_run_targets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id');
                $table->unsignedInteger('device_id');
                $table->string('inventory_name', 255);
                $table->string('hostname', 255)->default('');
                $table->string('display', 255)->default('');
                $table->string('result', 16)->nullable(); // ok, changed, failed, unreachable, skipped, not_run
                $table->text('counts')->nullable(); // JSON: the PLAY RECAP counters
                $table->index('run_id');
                $table->index(['device_id', 'run_id']);
            });
        }
        if (! Schema::hasTable('ansible_inventory_fetches')) {
            Schema::create('ansible_inventory_fetches', function (Blueprint $table): void {
                $table->unsignedInteger('user_id')->primary(); // the API token's user
                $table->string('username', 255);
                $table->unsignedInteger('hosts');
                $table->string('address', 45)->nullable();
                $table->timestamp('fetched_at')->nullable();
            });
        }
        if (! Schema::hasTable('ansible_config')) {
            Schema::create('ansible_config', function (Blueprint $table): void {
                $table->string('name', 64)->primary();
                $table->longText('value'); // encrypted with Laravel's Crypt
                $table->timestamp('updated_at')->nullable();
            });
        }
        if (class_exists(\Spatie\Permission\Models\Permission::class)) {
            \Spatie\Permission\Models\Permission::findOrCreate(self::PERMISSION, 'web');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ansible_run_targets');
        Schema::dropIfExists('ansible_runs');
        Schema::dropIfExists('ansible_inventory_fetches');
        Schema::dropIfExists('ansible_config');
        if (class_exists(\Spatie\Permission\Models\Permission::class)) {
            \Spatie\Permission\Models\Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->delete();
        }
    }
};
