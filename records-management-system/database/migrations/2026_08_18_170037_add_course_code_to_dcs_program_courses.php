<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_program_courses') || Schema::hasColumn('dcs_program_courses', 'course_code')) {
            return;
        }

        Schema::table('dcs_program_courses', function (Blueprint $table) {
            $table->string('course_code', 50)->nullable()->after('course_name');
        });

        Schema::table('dcs_program_courses', function (Blueprint $table) {
            $table->unique(['program_id', 'semester_id', 'course_code'], 'program_courses_code_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_program_courses') || ! Schema::hasColumn('dcs_program_courses', 'course_code')) {
            return;
        }

        Schema::table('dcs_program_courses', function (Blueprint $table) {
            $table->dropUnique('program_courses_code_unique');
            $table->dropColumn('course_code');
        });
    }
};
