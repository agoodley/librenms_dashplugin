<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which version of the plugin's inventory script made each user's latest fetch (its User-Agent), so the Inventory
 * page says when the runner still uses an older copy. Added only when missing, so it can run again safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ansible_inventory_fetches') && ! Schema::hasColumn('ansible_inventory_fetches', 'script_version')) {
            Schema::table('ansible_inventory_fetches', function (Blueprint $table): void {
                $table->string('script_version', 16)->nullable(); // null: the fetch did not come from the plugin's script
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ansible_inventory_fetches') && Schema::hasColumn('ansible_inventory_fetches', 'script_version')) {
            Schema::table('ansible_inventory_fetches', fn (Blueprint $table) => $table->dropColumn('script_version'));
        }
    }
};
