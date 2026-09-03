<?php
// 027 — program_courses

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_program_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')
                  ->constrained('dcs_programs')
                  ->cascadeOnDelete();
            $table->foreignId('semester_id')
                  ->constrained('dcs_semesters')
                  ->cascadeOnDelete();
            $table->string('course_name');
            $table->string('course_code', 50)->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'semester_id', 'course_name'], 'program_courses_unique');
            $table->unique(['program_id', 'semester_id', 'course_code'], 'program_courses_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_program_courses');
    }
};