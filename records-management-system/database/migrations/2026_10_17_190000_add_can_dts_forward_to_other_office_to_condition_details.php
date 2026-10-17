<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['sys_condition_details', 'condition_details'];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'can_dts_forward_to_other_office')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->boolean('can_dts_forward_to_other_office')->default(false);
                });
            }

            try {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN can_dts_forward_to_other_office SET DEFAULT false");
            } catch (\Throwable) {
                // SQLite or unsupported drivers
            }
        }

        // Also ensure "To be Forwarded to" exists in dts_action_options if table exists
        if (Schema::hasTable('dts_action_options')) {
            $exists = DB::table('dts_action_options')->where('option_name', 'To be Forwarded to')->exists();
            if (! $exists) {
                DB::table('dts_action_options')->insert([
                    'option_name' => 'To be Forwarded to',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $tables = ['sys_condition_details', 'condition_details'];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasColumn($table, 'can_dts_forward_to_other_office')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('can_dts_forward_to_other_office');
                });
            }
        }

        if (Schema::hasTable('dts_action_options')) {
            DB::table('dts_action_options')->where('option_name', 'To be Forwarded to')->delete();
        }
    }
};
