<?php
// 004 — approval_body

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_approval_body', function (Blueprint $table) {
            $table->id();
            $table->string('approval_name');
        });

        DB::table('dcs_approval_body')->insert([
            ['approval_name' => 'Board of Trustees'],
            ['approval_name' => 'Admin Council'],
            ['approval_name' => 'Acad Council'],
            ['approval_name' => 'RIC Council'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_approval_body');
    }
};