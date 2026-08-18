<?php
// 030 — syllabi_drf

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_syllabi_drf', function (Blueprint $table) {
            $table->id();
            $table->foreignId('syllabi_id')
                  ->constrained('dcs_syllabi')
                  ->cascadeOnDelete();
            $table->foreignId('faculty_id')->nullable()
                  ->constrained('dcs_faculties')
                  ->nullOnDelete();
            $table->string('faculty_name');
            $table->boolean('is_drf_available')->default(false);
            $table->string('drf_no', 100)->nullable();
            $table->date('drf_date')->nullable();
            $table->date('drf_received_date')->nullable();
            $table->string('scanned_drf')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_syllabi_drf');
    }
};