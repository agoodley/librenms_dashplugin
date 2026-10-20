<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The LibreNMS Custom Maps this plugin created (Sharing\CustomMapLayout): only these may be replaced by a
 * new export, never a map made in LibreNMS' editor. The table is only created when it does not exist, so
 * the migration can run again safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('topology_custom_maps')) {
            Schema::create('topology_custom_maps', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('custom_map_id')->unique(); // custom_maps.custom_map_id
                $table->string('page', 8);
                $table->text('query'); // the map parameters it was made from (MapParams)
                $table->string('created_by', 128)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('topology_custom_maps');
    }
};
