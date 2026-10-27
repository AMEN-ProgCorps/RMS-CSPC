<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Version Type dropdown labels: Recur → Recurring, Revise → Revision.
 * Matching still uses LIKE '%recur%' / '%revis%' so existing logic keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_version_type')) {
            return;
        }

        DB::table('dcs_version_type')
            ->whereRaw("LOWER(TRIM(version_name)) IN ('recur', 'recurring')")
            ->update(['version_name' => 'Recurring']);

        DB::table('dcs_version_type')
            ->whereRaw("LOWER(TRIM(version_name)) IN ('revise', 'revised', 'revision')")
            ->update(['version_name' => 'Revision']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_version_type')) {
            return;
        }

        DB::table('dcs_version_type')
            ->whereRaw("LOWER(TRIM(version_name)) = 'recurring'")
            ->update(['version_name' => 'Recur']);

        DB::table('dcs_version_type')
            ->whereRaw("LOWER(TRIM(version_name)) = 'revision'")
            ->update(['version_name' => 'Revise']);
    }
};
