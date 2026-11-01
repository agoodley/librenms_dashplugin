<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The compliance plugin's tables: its settings and the time of its last check (compliance_config), each device's last
 * check (compliance_devices: when, of which backup, each rule's outcome, its switch ports) and the findings of the
 * rules that failed (compliance_findings, one per device and rule, with since when). A configuration itself is never
 * stored. Times are UTC. Tables are only created when missing, so the migration can run again safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_config')) {
            Schema::create('compliance_config', function (Blueprint $table): void {
                $table->string('name', 64)->primary();
                $table->mediumText('value'); // JSON
                $table->dateTime('updated_at')->nullable();
            });
        }
        if (! Schema::hasTable('compliance_devices')) {
            Schema::create('compliance_devices', function (Blueprint $table): void {
                $table->unsignedInteger('device_id')->primary();
                $table->string('status', 16); // checked, no_backup, error
                $table->string('provider', 32)->nullable(); // the backup's: Oxidized, RANCID, Unimus
                $table->dateTime('backup_at')->nullable();
                $table->dateTime('checked_at');
                $table->string('config_hash', 64)->nullable(); // sha256 of the backup checked
                $table->string('os', 32)->nullable();
                $table->text('error')->nullable();
                $table->mediumText('outcomes')->nullable(); // JSON: rule => [status, why it did not apply or failed to run]
                $table->mediumText('ports')->nullable(); // JSON: the switch ports (Rules\Checker::ports())
                $table->index('status');
            });
        }
        if (! Schema::hasTable('compliance_findings')) {
            Schema::create('compliance_findings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('device_id');
                $table->string('rule', 64);
                $table->string('severity', 16);
                $table->text('message');
                $table->mediumText('evidence')->nullable(); // JSON: [line number, line with its secrets hidden]
                $table->text('ports')->nullable(); // JSON: the ports it is about, as the configuration names them
                $table->dateTime('first_seen');
                $table->dateTime('last_seen');
                $table->unique(['device_id', 'rule']);
                $table->index(['rule', 'severity']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_findings');
        Schema::dropIfExists('compliance_devices');
        Schema::dropIfExists('compliance_config');
    }
};
