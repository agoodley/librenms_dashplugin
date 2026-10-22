<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: changes. A change (ansible_changes) is one template on its targets (ansible_change_targets: their batch,
 * dry-run and apply results, the Oxidized backups before and after) with its batches (ansible_change_batches), the
 * approvals and rejections (ansible_change_approvals), the maintenance windows the plugin opened
 * (ansible_change_windows), the diffs of its dry run per device (ansible_change_diffs) and its timeline
 * (ansible_change_events). The dry run and each batch are runs of the audit log (ansible_runs), which now say which
 * change they belong to, as what, and which commit of the repository they ran. Times are UTC (Support\Clock). Plus the
 * approve permission, a direct permission of the users an admin grants it to. Tables and columns are only created
 * when missing, so the migration can run again safely.
 */
return new class extends Migration
{
    /** The permission that lets a LibreNMS admin approve and reject changes (Support\Access::APPROVE). */
    private const PERMISSION = 'ansible.approve';

    /** ansible_runs columns => how to add them. */
    private const RUN_COLUMNS = ['change_id', 'kind', 'batch', 'context', 'commit_hash', 'proof'];

    public function up(): void
    {
        if (! Schema::hasTable('ansible_changes')) {
            Schema::create('ansible_changes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('user_id')->nullable(); // users.user_id of the requester
                $table->string('username', 255);
                $table->string('template_id', 64);
                $table->string('template_name', 255);
                $table->string('playbook', 255)->default('');
                $table->string('reference', 160)->default(''); // free text from the requester (a ticket number), cleaned
                $table->string('status', 24);
                $table->unsignedInteger('version')->default(0); // bumped by every write of the lifecycle (compare-and-set)
                $table->text('variables')->nullable(); // JSON: the answers, secret ones without their value
                $table->boolean('has_secrets')->default(false); // the template has secret survey variables (asked at each launch)
                $table->text('plan'); // JSON: Changes\BatchPlan::toArray()
                $table->unsignedInteger('target_count')->default(0);
                $table->unsignedSmallInteger('batch_count')->default(0);
                $table->unsignedSmallInteger('current_batch')->default(0);
                $table->unsignedBigInteger('dry_run_id')->nullable(); // ansible_runs.id of the latest dry run
                $table->unsignedSmallInteger('dry_runs')->default(0); // dry runs launched (the one-time token of each)
                $table->string('dry_run_state', 16)->nullable(); // launching, running, verified, unverified, failed, not_launched, void
                $table->dateTime('dry_run_at')->nullable();
                $table->dateTime('dry_run_ended_at')->nullable();
                $table->string('dry_run_commit', 64)->nullable();
                $table->text('dry_run_findings')->nullable(); // JSON: why it is not verified or failed
                $table->string('plan_fingerprint', 64)->nullable(); // Changes\Fingerprint of the change at its dry run
                $table->longText('fingerprint_parts')->nullable(); // JSON: what the fingerprint was made of (no secret, variables as hashes)
                $table->string('approval_fingerprint', 64)->nullable();
                $table->string('approved_by', 255)->nullable();
                $table->unsignedInteger('approved_user_id')->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->string('applied_by', 255)->nullable();
                $table->unsignedInteger('applied_user_id')->nullable();
                $table->dateTime('apply_started_at')->nullable();
                $table->string('waiting', 16)->nullable(); // canary, secrets, timer
                $table->dateTime('resume_at')->nullable();
                $table->string('stop_requested_by', 255)->nullable();
                $table->dateTime('stop_requested_at')->nullable();
                $table->text('error')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->string('request_token', 64)->nullable()->unique(); // one change per form (double submits)
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->index('status');
                $table->index('created_at');
                $table->index('template_id');
            });
        }
        if (! Schema::hasTable('ansible_change_targets')) {
            Schema::create('ansible_change_targets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('change_id');
                $table->unsignedInteger('device_id');
                $table->string('inventory_name', 255);
                $table->string('hostname', 255)->default('');
                $table->string('display', 255)->default('');
                $table->unsignedSmallInteger('batch');
                $table->unsignedInteger('position')->default(0);
                $table->string('dry_result', 16)->nullable();
                $table->text('dry_counts')->nullable(); // JSON: the dry run's PLAY RECAP counters
                $table->string('result', 16)->nullable(); // the batch's
                $table->text('counts')->nullable();
                $table->string('backup_node', 255)->nullable(); // Oxidized node (group/name)
                foreach (['before', 'after'] as $phase) {
                    $table->string("{$phase}_status", 16)->nullable(); // Changes\BackupState
                    $table->dateTime("{$phase}_requested_at")->nullable();
                    $table->dateTime("{$phase}_at")->nullable();
                    $table->string("{$phase}_version", 64)->nullable();
                    $table->string("{$phase}_error", 255)->nullable();
                }
                $table->unique(['change_id', 'device_id']);
                $table->index('device_id');
            });
        }
        if (! Schema::hasTable('ansible_change_batches')) {
            Schema::create('ansible_change_batches', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('change_id');
                $table->unsignedSmallInteger('number');
                $table->string('status', 16); // pending, preparing, prepared, launching, running, done, failed, stopped, cancelled
                $table->unsignedBigInteger('run_id')->nullable();
                $table->unsignedInteger('failures')->default(0);
                $table->unsignedInteger('user_id')->nullable(); // who started it
                $table->string('username', 255)->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('ended_at')->nullable();
                $table->string('note', 500)->nullable();
                $table->unique(['change_id', 'number']);
            });
        }
        if (! Schema::hasTable('ansible_change_approvals')) {
            Schema::create('ansible_change_approvals', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('change_id');
                $table->unsignedInteger('user_id');
                $table->string('username', 255);
                $table->string('decision', 16); // approved, rejected
                $table->text('comment')->nullable();
                $table->string('fingerprint', 64)->nullable();
                $table->unsignedBigInteger('dry_run_id')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('voided_at')->nullable();
                $table->string('void_reason', 500)->nullable();
                $table->index('change_id');
            });
        }
        if (! Schema::hasTable('ansible_change_windows')) {
            Schema::create('ansible_change_windows', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('change_id');
                $table->unsignedSmallInteger('batch');
                $table->unsignedInteger('schedule_id'); // LibreNMS alert_schedule.schedule_id
                $table->string('status', 16); // open, closed, gone
                $table->boolean('closing')->default(false); // its final end is set (after its batch)
                $table->dateTime('opened_at')->nullable();
                $table->dateTime('ends_at')->nullable();
                $table->dateTime('closed_at')->nullable();
                $table->string('error', 500)->nullable();
                $table->index('change_id');
            });
        }
        if (! Schema::hasTable('ansible_change_diffs')) {
            Schema::create('ansible_change_diffs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('change_id');
                $table->unsignedBigInteger('run_id');
                $table->unsignedInteger('device_id')->nullable(); // null: not attributed to a device
                $table->unsignedInteger('position');
                $table->string('task', 255)->default('');
                $table->string('item', 255)->nullable();
                $table->string('kind', 16); // diff, commands, text
                $table->longText('lines'); // JSON list of plain lines, cut (Changes\DiffParser)
                $table->boolean('cut')->default(false);
                $table->string('host', 255)->nullable();
                $table->string('reason', 500)->nullable(); // why it is not attributed
                $table->index(['change_id', 'run_id']);
            });
        }
        if (! Schema::hasTable('ansible_change_events')) {
            Schema::create('ansible_change_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('change_id');
                $table->dateTime('at');
                $table->string('username', 255)->nullable();
                $table->string('kind', 24);
                $table->text('message');
                $table->index('change_id');
            });
        }
        if (Schema::hasTable('ansible_runs')) {
            $missing = array_values(array_filter(self::RUN_COLUMNS, fn ($column) => ! Schema::hasColumn('ansible_runs', $column)));
            if ($missing) {
                Schema::table('ansible_runs', function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $column) {
                        match ($column) {
                            'change_id' => $table->unsignedBigInteger('change_id')->nullable()->index(), // the change it belongs to (null: a phase 1 run)
                            'kind' => $table->string('kind', 16)->nullable(), // dry_run, batch
                            'batch' => $table->unsignedSmallInteger('batch')->nullable(),
                            'context' => $table->string('context', 120)->nullable(), // "change #5, batch 1 of 2" in the run's eventlog entries
                            'commit_hash' => $table->string('commit_hash', 64)->nullable(), // the repository commit the runner ran
                            'proof' => $table->string('proof', 16)->nullable(), // a dry run's: verified (proven to be one) or unverified
                        };
                    }
                });
            }
        }
        if (class_exists(\Spatie\Permission\Models\Permission::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            \Spatie\Permission\Models\Permission::findOrCreate(self::PERMISSION, 'web');
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        foreach (['ansible_change_events', 'ansible_change_diffs', 'ansible_change_windows', 'ansible_change_approvals', 'ansible_change_batches',
            'ansible_change_targets', 'ansible_changes'] as $table) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasTable('ansible_runs')) {
            $present = array_values(array_filter(self::RUN_COLUMNS, fn ($column) => Schema::hasColumn('ansible_runs', $column)));
            if ($present) {
                Schema::table('ansible_runs', function (Blueprint $table) use ($present): void {
                    if (in_array('change_id', $present, true)) {
                        $table->dropIndex(['change_id']);
                    }
                    $table->dropColumn($present);
                });
            }
        }
        if (class_exists(\Spatie\Permission\Models\Permission::class)) {
            \Spatie\Permission\Models\Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->get()->each->delete();
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
