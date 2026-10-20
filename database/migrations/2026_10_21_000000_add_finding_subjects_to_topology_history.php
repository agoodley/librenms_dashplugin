<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a finding of the background checks is about, kept with its history (History\FindingHistory):
 * its VLAN (spanning tree findings) and the devices its title named when it was recorded, so a
 * device renamed later is still matched by its id. Only added when missing, so it can run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('topology_findings')) {
            return;
        }
        if (! Schema::hasColumn('topology_findings', 'vlan')) {
            Schema::table('topology_findings', function (Blueprint $table): void {
                $table->unsignedSmallInteger('vlan')->nullable()->after('devices'); // VLAN key (0: VLAN 1 / CST)
            });
        }
        if (! Schema::hasColumn('topology_findings', 'subjects')) {
            Schema::table('topology_findings', function (Blueprint $table): void {
                $table->text('subjects')->nullable()->after('vlan'); // device ids its title named, [] for none
            });
        }
    }

    public function down(): void
    {
        foreach (['subjects', 'vlan'] as $column) {
            if (Schema::hasTable('topology_findings') && Schema::hasColumn('topology_findings', $column)) {
                Schema::table('topology_findings', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
