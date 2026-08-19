<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dcs_syllabi_monitoring_status')) {
            return;
        }

        Schema::create('dcs_syllabi_monitoring_status', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('college_id');
            $table->unsignedBigInteger('school_year_id');
            $table->unsignedBigInteger('semester_id');
            $table->unsignedBigInteger('program_id');
            $table->string('section', 20);
            $table->date('deadline')->nullable();
            $table->string('status', 40);
            $table->timestamps();

            $table->unique(
                ['college_id', 'school_year_id', 'semester_id', 'program_id', 'section', 'deadline'],
                'syllabi_mon_status_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_syllabi_monitoring_status');
    }
};
