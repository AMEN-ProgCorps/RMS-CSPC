<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('dcs_program_course_faculties');
    }
};
