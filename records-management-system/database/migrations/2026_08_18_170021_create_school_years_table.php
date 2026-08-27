<?php
// 024 — school_years

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_school_years', function (Blueprint $table) {
            $table->id();
            $table->string('school_year', 50)->unique();
            $table->timestamps();
        });

        DB::table('dcs_school_years')->insert([
            ['school_year' => '2025-2026', 'created_at' => now(), 'updated_at' => now()],
            ['school_year' => '2026-2027', 'created_at' => now(), 'updated_at' => now()],
            ['school_year' => '2027-2028', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_school_years');
    }
};