<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dcs_program_courses', function (Blueprint $table) {
            $table->string('year_level', 50)->nullable()->after('semester_id');
            $table->dropUnique('program_courses_unique');
            $table->dropUnique('program_courses_code_unique');
            $table->unique(['program_id', 'semester_id', 'course_name', 'year_level'], 'program_courses_year_level_unique');
            $table->unique(['program_id', 'semester_id', 'course_code', 'year_level'], 'program_courses_code_year_level_unique');
        });
    }

    public function down(): void
    {
        Schema::table('dcs_program_courses', function (Blueprint $table) {
            $table->dropUnique('program_courses_year_level_unique');
            $table->dropUnique('program_courses_code_year_level_unique');
            $table->dropColumn('year_level');
            $table->unique(['program_id', 'semester_id', 'course_name'], 'program_courses_unique');
            $table->unique(['program_id', 'semester_id', 'course_code'], 'program_courses_code_unique');
        });
    }
};
