<?php
// 023 — programs

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')
                  ->constrained('dcs_colleges')
                  ->cascadeOnDelete();
            $table->string('program_code', 50);
            $table->string('program_name');
            $table->timestamps();
        });

        DB::table('dcs_programs')->insert([
            // CCS
            ['college_id' => 1, 'program_code' => 'BSIT',      'program_name' => 'Bachelor of Science in Information Technology', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 1, 'program_code' => 'BSCS',      'program_name' => 'Bachelor of Science in Computer Science', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 1, 'program_code' => 'BLIS',      'program_name' => 'Bachelor of Library and Information Science', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 1, 'program_code' => 'BSIS',      'program_name' => 'Bachelor of Science in Information Systems', 'created_at' => now(), 'updated_at' => now()],
            // CEA
            ['college_id' => 2, 'program_code' => 'BSCE',      'program_name' => 'Bachelor of Science in Civil Engineering', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 2, 'program_code' => 'BSEE',      'program_name' => 'Bachelor of Science in Electrical Engineering', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 2, 'program_code' => 'ECE',       'program_name' => 'Bachelor of Science in Electronics Engineering', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 2, 'program_code' => 'BSME',      'program_name' => 'Bachelor of Science in Mechanical Engineering', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 2, 'program_code' => 'BSARCH',    'program_name' => 'Bachelor of Science in Architecture', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 2, 'program_code' => 'BSCpE',     'program_name' => 'Bachelor of Science in Computer Engineering', 'created_at' => now(), 'updated_at' => now()],
            // CHS
            ['college_id' => 3, 'program_code' => 'BSN',       'program_name' => 'Bachelor of Science in Nursing', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 3, 'program_code' => 'BSM',       'program_name' => 'Bachelor of Science in Midwifery', 'created_at' => now(), 'updated_at' => now()],
            // CTDE
            ['college_id' => 4, 'program_code' => 'BTVTED-FSM', 'program_name' => 'Bachelor of Technical-Vocational Teacher Education major in Food Service Management', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 4, 'program_code' => 'BTVTED-ET',  'program_name' => 'Bachelor of Technical-Vocational Teacher Education major in Electronics Technology', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 4, 'program_code' => 'BTVTED-FP',  'program_name' => 'Bachelor of Technical-Vocational Teacher Education major in Fish Processing', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 4, 'program_code' => 'BSNED',      'program_name' => 'Bachelor of Special Needs Education', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 4, 'program_code' => 'BPE',        'program_name' => 'Bachelor of Physical Education', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 4, 'program_code' => 'BCAED',      'program_name' => 'Bachelor of Culture and Arts Education', 'created_at' => now(), 'updated_at' => now()],
            // CTHBM
            ['college_id' => 5, 'program_code' => 'BSOA',       'program_name' => 'Bachelor of Science in Office Administration', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 5, 'program_code' => 'BSHM',       'program_name' => 'Bachelor of Science in Hospitality Management', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 5, 'program_code' => 'BSENTREP',   'program_name' => 'Bachelor of Science in Entrepreneurship', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 5, 'program_code' => 'BSTM',       'program_name' => 'Bachelor of Science in Tourism Management', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 5, 'program_code' => 'BSBA-FM',    'program_name' => 'Bachelor of Science in Business Administration major in Financial Management', 'created_at' => now(), 'updated_at' => now()],
            // CAS
            ['college_id' => 6, 'program_code' => 'BHS',        'program_name' => 'Bachelor in Human Services', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 6, 'program_code' => 'AB ELS',     'program_name' => 'Bachelor of Arts in English Language Studies', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 6, 'program_code' => 'BS DevCom',  'program_name' => 'Bachelor of Science in Development Communication', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 6, 'program_code' => 'BPA',        'program_name' => 'Bachelor of Public Administration', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 6, 'program_code' => 'BS Math',    'program_name' => 'Bachelor of Science in Mathematics', 'created_at' => now(), 'updated_at' => now()],
            ['college_id' => 6, 'program_code' => 'BS AppMath', 'program_name' => 'Bachelor of Science in Applied Mathematics', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_programs');
    }
};