<?php
// 028 — faculties

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_faculties', function (Blueprint $table) {
            $table->id();
            $table->string('faculty_name')->unique();
            $table->foreignId('college_id')->nullable()
                  ->constrained('dcs_colleges')
                  ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_faculties');
    }
};