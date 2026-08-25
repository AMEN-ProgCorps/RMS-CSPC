<?php
// 029 — syllabi

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_syllabi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')
                  ->constrained('dcs_document_requests')
                  ->cascadeOnDelete();
            $table->foreignId('college_id')->nullable()
                  ->constrained('dcs_colleges')
                  ->nullOnDelete();
            $table->foreignId('program_id')->nullable()
                  ->constrained('dcs_programs')
                  ->nullOnDelete();
            $table->foreignId('semester_id')->nullable()
                  ->constrained('dcs_semesters')
                  ->nullOnDelete();
            $table->foreignId('school_year_id')->nullable()
                  ->constrained('dcs_school_years')
                  ->nullOnDelete();
            $table->foreignId('course_id')
                  ->constrained('dcs_program_courses')
                  ->cascadeOnDelete();
            $table->boolean('is_available')->default(false);
            $table->integer('no_copies')->default(1);
            $table->integer('no_pages')->nullable();
            $table->date('date_received')->nullable();
            $table->time('time_received')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_syllabi');
    }
};
