<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plugin's record of each switch port (Capacity\PortUsage), kept with every topology snapshot: when it
 * was last known up and its latest err-disable message, which outlast a restart of the switch and the
 * purge of LibreNMS' eventlog and syslog. The table is only created when it does not exist, so the
 * migration can run again safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('topology_port_usage')) {
            Schema::create('topology_port_usage', function (Blueprint $table): void {
                $table->unsignedInteger('port_id')->primary(); // ports.port_id
                $table->unsignedInteger('device_id')->index();
                $table->dateTime('first_seen'); // the record of the port began
                $table->dateTime('last_up')->nullable(); // the latest time it is known to have been up
                $table->dateTime('err_disabled_at')->nullable(); // its latest err-disable message
                $table->string('err_disabled_message', 255)->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('topology_port_usage');
    }
};
