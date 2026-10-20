<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables of the topology history (History\HistoryService): facts as periods of presence, the
 * saved baseline, the runs of the scheduled job and the findings history of the background checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        // one row per period a fact (link, port-channel member, STP root or blocked port, adjacency) is seen
        Schema::create('topology_facts', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->char('hash', 40);
            $table->string('fact_key', 512);
            $table->unsignedInteger('device_a')->nullable();
            $table->unsignedInteger('device_b')->nullable();
            $table->text('data');
            $table->char('data_hash', 32);
            $table->boolean('initial')->default(false);
            $table->dateTime('first_seen');
            $table->dateTime('removed_at')->nullable();
            $table->index(['hash', 'removed_at']);
            $table->index('first_seen');
            $table->index('removed_at');
            $table->index('device_a');
            $table->index('device_b');
        });

        Schema::create('topology_baseline', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->char('hash', 40)->unique();
            $table->string('fact_key', 512);
            $table->unsignedInteger('device_a')->nullable();
            $table->unsignedInteger('device_b')->nullable();
            $table->text('data');
            $table->dateTime('saved_at');
            $table->string('saved_by', 128)->nullable();
            $table->index('device_a');
            $table->index('device_b');
        });

        Schema::create('topology_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 16);
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->text('stats')->nullable();
            $table->index(['type', 'started_at']);
        });

        // one row per period a finding of the background checks is reported
        Schema::create('topology_findings', function (Blueprint $table): void {
            $table->id();
            $table->string('analysis', 16);
            $table->string('code', 64);
            $table->char('hash', 40);
            $table->string('severity', 16);
            $table->string('title', 512);
            $table->text('devices');
            $table->boolean('initial')->default(false);
            $table->dateTime('first_seen');
            $table->dateTime('resolved_at')->nullable();
            $table->index(['hash', 'resolved_at']);
            $table->index(['analysis', 'resolved_at']);
            $table->index('first_seen');
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topology_findings');
        Schema::dropIfExists('topology_runs');
        Schema::dropIfExists('topology_baseline');
        Schema::dropIfExists('topology_facts');
    }
};
