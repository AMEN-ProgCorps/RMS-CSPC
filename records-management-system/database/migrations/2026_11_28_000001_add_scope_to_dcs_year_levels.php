<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_year_levels')) {
            return;
        }

        Schema::table('dcs_year_levels', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_year_levels', 'college_id')) {
                $table->unsignedBigInteger('college_id')->nullable()->after('year_level_name');
                $table->index('college_id', 'dcs_year_levels_college_idx');
            }
            if (! Schema::hasColumn('dcs_year_levels', 'program_id')) {
                $table->unsignedBigInteger('program_id')->nullable()->after('college_id');
                $table->index('program_id', 'dcs_year_levels_program_idx');
            }
        });

        // Allow the same year level label to be assigned to different colleges/programs.
        try {
            DB::statement('ALTER TABLE dcs_year_levels DROP CONSTRAINT IF EXISTS dcs_year_levels_year_level_name_unique');
        } catch (\Throwable) {
            try {
                Schema::table('dcs_year_levels', function (Blueprint $table) {
                    $table->dropUnique(['year_level_name']);
                });
            } catch (\Throwable) {
            }
        }

        // Remove default 5th/6th Year if unused so colleges default to 1st–4th Year with no college/program assigned yet.
        foreach (['5th Year', '6th Year'] as $extraLevel) {
            $inUse = Schema::hasTable('dcs_program_courses')
                && Schema::hasColumn('dcs_program_courses', 'year_level')
                && DB::table('dcs_program_courses')->where('year_level', $extraLevel)->exists();
            if (! $inUse) {
                DB::table('dcs_year_levels')
                    ->where('year_level_name', $extraLevel)
                    ->whereNull('college_id')
                    ->whereNull('program_id')
                    ->delete();
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_year_levels')) {
            return;
        }

        Schema::table('dcs_year_levels', function (Blueprint $table) {
            if (Schema::hasColumn('dcs_year_levels', 'program_id')) {
                $table->dropIndex('dcs_year_levels_program_idx');
                $table->dropColumn('program_id');
            }
            if (Schema::hasColumn('dcs_year_levels', 'college_id')) {
                $table->dropIndex('dcs_year_levels_college_idx');
                $table->dropColumn('college_id');
            }
        });
    }
};
