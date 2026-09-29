<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One faculty person can teach under several colleges (e.g. part-time GE + major).
 * Replaces the single dcs_faculties.college_id with a membership pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_faculties') || ! Schema::hasTable('dcs_colleges')) {
            return;
        }

        if (! Schema::hasTable('dcs_faculty_colleges')) {
            Schema::create('dcs_faculty_colleges', function (Blueprint $table) {
                $table->id();
                $table->foreignId('faculty_id')->constrained('dcs_faculties')->cascadeOnDelete();
                $table->foreignId('college_id')->constrained('dcs_colleges')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['faculty_id', 'college_id']);
            });
        }

        if (Schema::hasColumn('dcs_faculties', 'college_id')) {
            $rows = DB::table('dcs_faculties')
                ->whereNotNull('college_id')
                ->get(['id', 'college_id']);

            $now = now();
            foreach ($rows as $row) {
                $facultyId = (int) $row->id;
                $collegeId = (int) $row->college_id;
                if ($facultyId < 1 || $collegeId < 1) {
                    continue;
                }
                $exists = DB::table('dcs_faculty_colleges')
                    ->where('faculty_id', $facultyId)
                    ->where('college_id', $collegeId)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('dcs_faculty_colleges')->insert([
                    'faculty_id' => $facultyId,
                    'college_id' => $collegeId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Schema::table('dcs_faculties', function (Blueprint $table) {
                try {
                    $table->dropConstrainedForeignId('college_id');
                } catch (\Throwable) {
                    if (Schema::hasColumn('dcs_faculties', 'college_id')) {
                        $table->dropColumn('college_id');
                    }
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_faculties')) {
            return;
        }

        if (! Schema::hasColumn('dcs_faculties', 'college_id')) {
            Schema::table('dcs_faculties', function (Blueprint $table) {
                $table->foreignId('college_id')->nullable()
                    ->constrained('dcs_colleges')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('dcs_faculty_colleges')) {
            $firstByFaculty = DB::table('dcs_faculty_colleges')
                ->orderBy('id')
                ->get(['faculty_id', 'college_id'])
                ->groupBy('faculty_id');

            foreach ($firstByFaculty as $facultyId => $links) {
                $collegeId = (int) ($links->first()->college_id ?? 0);
                if ($collegeId < 1) {
                    continue;
                }
                DB::table('dcs_faculties')
                    ->where('id', (int) $facultyId)
                    ->whereNull('college_id')
                    ->update(['college_id' => $collegeId]);
            }

            Schema::dropIfExists('dcs_faculty_colleges');
        }
    }
};
