<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: the verification of each batch and the changes made from fixes. A batch keeps how its verification goes
 * (verify: the state before it, the wait for fresh data after it, the result, an acceptance; JSON). Its states are kept
 * apart (ansible_change_states, one row per batch and phase, before or after): the devices a state covers, and the state
 * itself, compact (identities, texts and severities of the facts and findings, and how fresh the data was), compressed,
 * with the comparison and what it means. A change keeps the fix it was made from (fix: JSON) and the outcome of its
 * verification (verification: verified, accepted, not_verified; verification_note). Only added when missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ansible_changes')) {
            $missing = array_values(array_filter(['fix', 'verification', 'verification_note'], fn ($column) => ! Schema::hasColumn('ansible_changes', $column)));
            if ($missing) {
                Schema::table('ansible_changes', function (Blueprint $table) use ($missing): void {
                    if (in_array('fix', $missing, true)) {
                        $table->mediumText('fix')->nullable(); // JSON: the fix the change was made from (Changes\FixAnswers::record())
                    }
                    if (in_array('verification', $missing, true)) {
                        $table->string('verification', 16)->nullable(); // verified, accepted (verified with accepted changes), not_verified
                    }
                    if (in_array('verification_note', $missing, true)) {
                        $table->text('verification_note')->nullable();
                    }
                });
            }
        }
        if (Schema::hasTable('ansible_change_batches') && ! Schema::hasColumn('ansible_change_batches', 'verify')) {
            Schema::table('ansible_change_batches', function (Blueprint $table): void {
                $table->text('verify')->nullable(); // JSON: how the batch's verification goes (Changes\ChangeLifecycle)
            });
        }
        if (! Schema::hasTable('ansible_change_states')) {
            Schema::create('ansible_change_states', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('change_id');
                $table->unsignedSmallInteger('batch');
                $table->string('phase', 8); // before, after
                $table->longText('covered')->nullable(); // JSON: the devices whose facts it records (and those it must cover again after)
                $table->longText('domain')->nullable(); // JSON: the other spanning tree switches of their domains
                $table->longText('names')->nullable(); // JSON: the names of the covered devices (device id => name), for the timeline
                $table->timestamp('taken_at')->nullable();
                $table->unsignedInteger('bytes')->nullable(); // the state's JSON, before compression
                $table->longText('data')->nullable(); // the state, compressed
                $table->longText('result')->nullable(); // JSON (after): the comparison and what it means
                $table->unique(['change_id', 'batch', 'phase']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ansible_change_states');
        if (Schema::hasTable('ansible_change_batches') && Schema::hasColumn('ansible_change_batches', 'verify')) {
            Schema::table('ansible_change_batches', function (Blueprint $table): void {
                $table->dropColumn('verify');
            });
        }
        if (Schema::hasTable('ansible_changes')) {
            $present = array_values(array_filter(['fix', 'verification', 'verification_note'], fn ($column) => Schema::hasColumn('ansible_changes', $column)));
            if ($present) {
                Schema::table('ansible_changes', function (Blueprint $table) use ($present): void {
                    $table->dropColumn($present);
                });
            }
        }
    }
};
