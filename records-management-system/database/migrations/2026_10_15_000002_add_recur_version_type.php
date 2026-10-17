<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Version labels use the base form: New, Revise, Recur.
 * Recur registrations store revision_status = superseded on the previous latest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dcs_version_type')) {
            DB::table('dcs_version_type')
                ->whereRaw("LOWER(version_name) = 'revised'")
                ->update(['version_name' => 'Revise']);

            $hasRecur = DB::table('dcs_version_type')
                ->whereRaw("LOWER(version_name) LIKE '%recur%'")
                ->exists();
            if (! $hasRecur) {
                DB::table('dcs_version_type')->insert([
                    'version_name' => 'Recur',
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_version_type')) {
            return;
        }

        DB::table('dcs_version_type')
            ->whereRaw("LOWER(version_name) = 'revise'")
            ->update(['version_name' => 'Revised']);

        DB::table('dcs_version_type')
            ->whereRaw("LOWER(version_name) = 'recur'")
            ->delete();
    }
};
