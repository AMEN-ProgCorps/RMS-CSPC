<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete support for DCS Settings reference data so deletes go to DCS Recycle Bin.
 */
return new class extends Migration
{
    /** @return list<string> */
    private function tables(): array
    {
        return [
            'dcs_doc_types',
            'dcs_originators',
            'dcs_faculties',
            'dcs_colleges',
            'dcs_programs',
            'dcs_semesters',
            'dcs_school_years',
            'dcs_program_courses',
        ];
    }

    public function up(): void
    {
        foreach ($this->tables() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'deleted_at')) {
                    $blueprint->timestamp('deleted_at')->nullable()->index();
                }
                if (! Schema::hasColumn($table, 'deleted_by')) {
                    $blueprint->unsignedBigInteger('deleted_by')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables()) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'deleted_by')) {
                    $blueprint->dropColumn('deleted_by');
                }
                if (Schema::hasColumn($table, 'deleted_at')) {
                    $blueprint->dropColumn('deleted_at');
                }
            });
        }
    }
};
