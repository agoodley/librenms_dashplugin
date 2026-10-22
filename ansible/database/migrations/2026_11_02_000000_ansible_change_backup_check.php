<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: what Oxidized said about each target of a change when its dry run was launched (Changes\BackupCheck): it
 * has a node for it, it has none, LibreNMS leaves the device out of Oxidized, or Oxidized could not be asked. The
 * change page warns before the apply about the batches a missing backup would refuse. Only added when missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ansible_change_targets') && ! Schema::hasColumn('ansible_change_targets', 'backup_check')) {
            Schema::table('ansible_change_targets', function (Blueprint $table): void {
                $table->string('backup_check', 16)->nullable(); // node, no_node, excluded, unknown (null: not asked)
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ansible_change_targets') && Schema::hasColumn('ansible_change_targets', 'backup_check')) {
            Schema::table('ansible_change_targets', function (Blueprint $table): void {
                $table->dropColumn('backup_check');
            });
        }
    }
};
