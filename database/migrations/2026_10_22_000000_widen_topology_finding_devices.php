<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device lists of the findings history as medium text (16 MB): a TEXT column holds 64 KB, about
 * 10,000 device ids, which an outage finding of a large network can exceed. Only changed while still
 * TEXT, so it can run again.
 */
return new class extends Migration
{
    private const COLUMNS = ['devices' => false, 'subjects' => true]; // column => nullable

    public function up(): void
    {
        foreach (self::COLUMNS as $column => $nullable) {
            if (Schema::hasTable('topology_findings') && Schema::hasColumn('topology_findings', $column)
                && strtolower(Schema::getColumnType('topology_findings', $column)) === 'text') {
                Schema::table('topology_findings', function (Blueprint $table) use ($column, $nullable): void {
                    $table->mediumText($column)->nullable($nullable)->change();
                });
            }
        }
    }

    public function down(): void
    {
        // nothing to undo: narrowing the columns again could not keep the longer lists (the first migration drops the table)
    }
};
