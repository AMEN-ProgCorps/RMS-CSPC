<?php
// 025 — semesters

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_semesters', function (Blueprint $table) {
            $table->id();
            $table->string('semester_name', 50);
            $table->timestamps();
        });

        DB::table('dcs_semesters')->insert([
            ['semester_name' => '1st Semester', 'created_at' => now(), 'updated_at' => now()],
            ['semester_name' => '2nd Semester', 'created_at' => now(), 'updated_at' => now()],
            ['semester_name' => 'Mid Year', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_semesters');
    }
};
