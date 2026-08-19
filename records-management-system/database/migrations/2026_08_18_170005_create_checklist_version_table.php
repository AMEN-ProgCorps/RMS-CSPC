<?php
// 007 — checklist_version

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_checklist_version', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')
                  ->constrained('dcs_checklist_types')
                  ->cascadeOnDelete();
            $table->foreignId('version_id')
                  ->constrained('dcs_version_type')
                  ->cascadeOnDelete();
        });

        DB::table('dcs_checklist_version')->insert([
            ['checklist_id' => 1, 'version_id' => 1],
            ['checklist_id' => 3, 'version_id' => 1],
            ['checklist_id' => 5, 'version_id' => 1],
            ['checklist_id' => 1, 'version_id' => 2],
            ['checklist_id' => 2, 'version_id' => 2],
            ['checklist_id' => 3, 'version_id' => 2],
            ['checklist_id' => 4, 'version_id' => 2],
            ['checklist_id' => 5, 'version_id' => 2],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_checklist_version');
    }
};