<?php
// 002 — doc_types

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_doc_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()
                  ->constrained('dcs_doc_types')
                  ->cascadeOnDelete();
            $table->string('doc_type_name');
        });

        DB::table('dcs_doc_types')->insert([
            ['id' => 1,  'parent_id' => null, 'doc_type_name' => 'Internal'],
            ['id' => 2,  'parent_id' => null, 'doc_type_name' => 'Internal Forms'],
            ['id' => 3,  'parent_id' => null, 'doc_type_name' => 'External'],
            ['id' => 4,  'parent_id' => null, 'doc_type_name' => 'Forms'],
            ['id' => 5,  'parent_id' => null, 'doc_type_name' => 'Logbooks'],
            ['id' => 6,  'parent_id' => 1,    'doc_type_name' => 'Manuals/Policy'],
            ['id' => 7,  'parent_id' => 1,    'doc_type_name' => 'Quality Objectives'],
            ['id' => 8,  'parent_id' => 1,    'doc_type_name' => 'FMEA'],
            ['id' => 9,  'parent_id' => 1,    'doc_type_name' => 'Work Instructions'],
            ['id' => 10, 'parent_id' => 1,    'doc_type_name' => 'Curriculum'],
            ['id' => 11, 'parent_id' => 2,    'doc_type_name' => 'Syllabi'],
            ['id' => 12, 'parent_id' => 2,    'doc_type_name' => 'TOS/Rubrics'],
            ['id' => 13, 'parent_id' => 2,    'doc_type_name' => 'Preventive Maintenance Plan'],
            ['id' => 14, 'parent_id' => 2,    'doc_type_name' => 'Faculty Profile'],
        ]);

        DB::statement("SELECT setval(pg_get_serial_sequence('dcs_doc_types', 'id'), (SELECT MAX(id) FROM dcs_doc_types))");
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_doc_types');
    }
};