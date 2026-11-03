<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A change keeps the approval mode it follows (approval_mode: off, required, second; Changes\ApprovalRules): the
 * settings' when it was created, raised whenever the settings asked for more while it was open, never lowered. A
 * setting raised later applies to it, a setting lowered later does not. The changes there are when the column is added
 * get the setting of now, which they followed until then. Only added when missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ansible_changes') && ! Schema::hasColumn('ansible_changes', 'approval_mode')) {
            Schema::table('ansible_changes', function (Blueprint $table): void {
                $table->string('approval_mode', 16)->nullable();
            });
            DB::table('ansible_changes')->whereNull('approval_mode')->update(['approval_mode' => $this->setting()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ansible_changes') && Schema::hasColumn('ansible_changes', 'approval_mode')) {
            Schema::table('ansible_changes', function (Blueprint $table): void {
                $table->dropColumn('approval_mode');
            });
        }
    }

    /**
     * The approval setting of now, as the plugin reads it (an approval, its default, when it cannot).
     */
    private function setting(): string
    {
        try {
            return (new \Agoodley\LibrenmsAnsible\Data\SettingsRepository())->get()->changeSettings()->approval;
        } catch (\Throwable) {
            return 'required';
        }
    }
};
