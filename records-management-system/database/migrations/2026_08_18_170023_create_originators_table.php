<?php
// 026 — originators

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_originators', function (Blueprint $table) {
            $table->id();
            $table->string('originator_name')->unique();
            $table->timestamps();
        });

        DB::table('dcs_originators')->insert([
            ['originator_name' => 'Juan Dela Cruz',    'created_at' => now(), 'updated_at' => now()],
            ['originator_name' => 'Maria De Jesus',    'created_at' => now(), 'updated_at' => now()],
            ['originator_name' => 'John Doe',          'created_at' => now(), 'updated_at' => now()],
            ['originator_name' => 'Mark Zuckerberg',   'created_at' => now(), 'updated_at' => now()],
            ['originator_name' => 'Stepanie Maslow',   'created_at' => now(), 'updated_at' => now()],
            ['originator_name' => 'George Field',      'created_at' => now(), 'updated_at' => now()],
            ['originator_name' => 'Mccoy Roi',         'created_at' => now(), 'updated_at' => now()],
            ['originator_name' => 'Troy George',       'created_at' => now(), 'updated_at' => now()],
            ['originator_name' => 'Rain Gerarnd',      'created_at' => now(), 'updated_at' => now()],
            ['originator_name' => 'Troy Husley',       'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_originators');
    }
};