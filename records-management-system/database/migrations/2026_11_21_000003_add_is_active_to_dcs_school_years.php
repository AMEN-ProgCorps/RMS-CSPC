<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exactly one school year should be "active" for office intake / requestors,
 * so they cannot pick a wrong future or past S/Y by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_school_years')) {
            return;
        }

        if (! Schema::hasColumn('dcs_school_years', 'is_active')) {
            Schema::table('dcs_school_years', function (Blueprint $table) {
                $table->boolean('is_active')->default(false)->after('school_year');
            });
        }

        // Prefer the academic year that covers "today" (PH: June → May).
        $now = now();
        $startYear = (int) $now->month >= 6 ? (int) $now->year : ((int) $now->year - 1);
        $preferred = $startYear . '-' . ($startYear + 1);

        $query = DB::table('dcs_school_years');
        if (Schema::hasColumn('dcs_school_years', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $activeId = (clone $query)->where('school_year', $preferred)->value('id');
        if (! $activeId) {
            $activeId = (clone $query)->orderByDesc('school_year')->value('id');
        }

        DB::table('dcs_school_years')->update(['is_active' => false]);
        if ($activeId) {
            DB::table('dcs_school_years')->where('id', $activeId)->update(['is_active' => true]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_school_years') || ! Schema::hasColumn('dcs_school_years', 'is_active')) {
            return;
        }

        Schema::table('dcs_school_years', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
