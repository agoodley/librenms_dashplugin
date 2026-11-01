<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inventory plugin's tables (phase 2, software compliance): its settings and its last check (inventory_config), each
 * device's software as the last check saw it, with its status against the policies and since when (inventory_software),
 * and the version changes the checks saw (inventory_upgrades). Everything else the plugin shows it reads from LibreNMS.
 * Times are UTC. Tables are only created when missing, so the migration can run again safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_config')) {
            Schema::create('inventory_config', function (Blueprint $table): void {
                $table->string('name', 64)->primary();
                $table->mediumText('value'); // JSON
                $table->dateTime('updated_at')->nullable();
            });
        }
        if (! Schema::hasTable('inventory_software')) {
            Schema::create('inventory_software', function (Blueprint $table): void {
                $table->unsignedInteger('device_id')->primary();
                $table->string('os', 64)->nullable();
                $table->string('model', 255)->nullable(); // LibreNMS' hardware of the device
                $table->string('version', 255)->nullable();
                $table->string('status', 16); // Software\Status
                $table->string('policy', 100)->nullable(); // the name of the policy that gave the status
                $table->text('message')->nullable();
                $table->dateTime('status_since');
                $table->dateTime('version_since');
                $table->dateTime('updated_at');
                $table->index('status');
            });
        }
        if (! Schema::hasTable('inventory_upgrades')) {
            Schema::create('inventory_upgrades', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('device_id');
                $table->string('os', 64)->nullable();
                $table->string('model', 255)->nullable();
                $table->string('from_version', 255);
                $table->string('to_version', 255);
                $table->string('direction', 16); // upgrade, downgrade
                $table->dateTime('from_since')->nullable(); // since when the device ran from_version (as the checks saw it)
                $table->dateTime('seen_at');
                $table->index(['device_id', 'seen_at']);
                $table->index('seen_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_upgrades');
        Schema::dropIfExists('inventory_software');
        Schema::dropIfExists('inventory_config');
    }
};
