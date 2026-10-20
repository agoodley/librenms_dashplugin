<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved views of the L2 and L3 maps (Sharing\SavedView): a name, the map's parameters, the positions of
 * its nodes and its display options, personal or shared with everyone who can open the maps. The table is
 * only created when it does not exist, so the migration can run again safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('topology_views')) {
            Schema::create('topology_views', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('user_id'); // owner: users.user_id
                $table->string('name', 100);
                $table->string('page', 8); // l2, l3
                $table->text('query'); // the map parameters (MapParams)
                $table->longText('layout')->nullable(); // node id => [x, y]
                $table->text('options')->nullable(); // layout, labels, colors of the map's view bar
                $table->boolean('shared')->default(false);
                $table->timestamps();
                $table->index(['user_id', 'page']);
                $table->index(['shared', 'page']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('topology_views');
    }
};
