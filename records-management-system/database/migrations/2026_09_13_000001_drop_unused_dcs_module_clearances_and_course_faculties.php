<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove unused DCS leftovers:
 * - dcs_program_course_faculties (never written; faculty is chosen at registration)
 * - dcs_can_* module clearances (UI removed; access is View All / RFIO via isFullDcsUser)
 */
return new class extends Migration
{
    /** @return list<string> */
    private function moduleColumns(): array
    {
        return [
            'dcs_can_register',
            'dcs_can_settings',
            'dcs_can_recycle_bin',
            'dcs_can_review_intake',
        ];
    }

    public function up(): void
    {
        Schema::dropIfExists('dcs_program_course_faculties');

        $details = Schema::hasTable('sys_condition_details')
            ? 'sys_condition_details'
            : (Schema::hasTable('condition_details') ? 'condition_details' : null);

        if ($details === null) {
            return;
        }

        foreach ($this->moduleColumns() as $column) {
            if (Schema::hasColumn($details, $column)) {
                Schema::table($details, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_program_course_faculties')
            && Schema::hasTable('dcs_program_courses')
            && Schema::hasTable('dcs_faculties')
        ) {
            Schema::create('dcs_program_course_faculties', function (Blueprint $table) {
                $table->id();
                $table->foreignId('program_course_id')
                    ->constrained('dcs_program_courses')
                    ->cascadeOnDelete();
                $table->foreignId('faculty_id')
                    ->constrained('dcs_faculties')
                    ->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['program_course_id', 'faculty_id'], 'pc_faculty_unique');
            });
        }

        $details = Schema::hasTable('sys_condition_details')
            ? 'sys_condition_details'
            : (Schema::hasTable('condition_details') ? 'condition_details' : null);

        if ($details === null) {
            return;
        }

        $after = Schema::hasColumn($details, 'dcs_view_all_documents')
            ? 'dcs_view_all_documents'
            : (Schema::hasColumn($details, 'can_access_dcs') ? 'can_access_dcs' : null);

        foreach ($this->moduleColumns() as $column) {
            if (Schema::hasColumn($details, $column)) {
                $after = $column;
                continue;
            }
            Schema::table($details, function (Blueprint $table) use ($column, $after) {
                $col = $table->boolean($column)->default(false);
                if ($after) {
                    $col->after($after);
                }
            });
            $after = $column;
        }
    }
};
