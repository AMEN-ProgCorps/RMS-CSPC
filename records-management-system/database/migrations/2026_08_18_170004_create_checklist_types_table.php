<?php
// 006 — checklist_types

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_checklist_types', function (Blueprint $table) {
            $table->id();
            $table->string('checklist_name')->unique();
        });

        DB::table('dcs_checklist_types')->insert([
            ['checklist_name' => 'Document Request Form'],
            ['checklist_name' => 'Document Change Notice'],
            ['checklist_name' => 'Masterlist Registration'],
            ['checklist_name' => 'Document Retrieval'],
            ['checklist_name' => 'Document Distribution'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_checklist_types');
    }
};