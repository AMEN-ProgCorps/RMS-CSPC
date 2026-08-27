<?php
// 003 — version_type

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_version_type', function (Blueprint $table) {
            $table->id();
            $table->string('version_name');
        });

        DB::table('dcs_version_type')->insert([
            ['version_name' => 'New'],
            ['version_name' => 'Revised'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_version_type');
    }
};
