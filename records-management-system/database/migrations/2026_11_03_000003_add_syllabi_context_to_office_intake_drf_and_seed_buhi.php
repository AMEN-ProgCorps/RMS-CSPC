<?php

/**
 * Office DRF syllabi context + Buhi Campus seed (BECEd / BSOA + sample course).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dcs_office_intake_drf')) {
            Schema::table('dcs_office_intake_drf', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_office_intake_drf', 'college_id')) {
                    $table->unsignedBigInteger('college_id')->nullable()->after('sub_type_id');
                }
                if (! Schema::hasColumn('dcs_office_intake_drf', 'program_id')) {
                    $table->unsignedBigInteger('program_id')->nullable()->after('college_id');
                }
                if (! Schema::hasColumn('dcs_office_intake_drf', 'course_id')) {
                    $table->unsignedBigInteger('course_id')->nullable()->after('program_id');
                }
                if (! Schema::hasColumn('dcs_office_intake_drf', 'semester_id')) {
                    $table->unsignedBigInteger('semester_id')->nullable()->after('course_id');
                }
                if (! Schema::hasColumn('dcs_office_intake_drf', 'school_year_id')) {
                    $table->unsignedBigInteger('school_year_id')->nullable()->after('semester_id');
                }
            });

            $this->addFkIfMissing('dcs_office_intake_drf', 'college_id', 'dcs_colleges', 'dcs_ofi_drf_college_fk');
            $this->addFkIfMissing('dcs_office_intake_drf', 'program_id', 'dcs_programs', 'dcs_ofi_drf_program_fk');
            $this->addFkIfMissing('dcs_office_intake_drf', 'course_id', 'dcs_program_courses', 'dcs_ofi_drf_course_fk');
            $this->addFkIfMissing('dcs_office_intake_drf', 'semester_id', 'dcs_semesters', 'dcs_ofi_drf_semester_fk');
            $this->addFkIfMissing('dcs_office_intake_drf', 'school_year_id', 'dcs_school_years', 'dcs_ofi_drf_school_year_fk');
        }

        $this->seedBuhiCampus();
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_office_intake_drf')) {
            return;
        }

        Schema::table('dcs_office_intake_drf', function (Blueprint $table) {
            foreach ([
                'dcs_ofi_drf_college_fk',
                'dcs_ofi_drf_program_fk',
                'dcs_ofi_drf_course_fk',
                'dcs_ofi_drf_semester_fk',
                'dcs_ofi_drf_school_year_fk',
            ] as $fk) {
                try {
                    $table->dropForeign($fk);
                } catch (\Throwable) {
                    // Constraint may not exist on all drivers / prior partial runs.
                }
            }
            $cols = ['college_id', 'program_id', 'course_id', 'semester_id', 'school_year_id'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('dcs_office_intake_drf', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function addFkIfMissing(string $table, string $column, string $refTable, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable($refTable) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $refTable, $name) {
                $blueprint->foreign($column, $name)
                    ->references('id')
                    ->on($refTable)
                    ->nullOnDelete();
            });
        } catch (\Throwable) {
            // Already present.
        }
    }

    private function seedBuhiCampus(): void
    {
        if (! Schema::hasTable('dcs_colleges') || ! Schema::hasTable('dcs_programs')) {
            return;
        }

        $now = now();
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $buhiOfficeId = DB::table($officeTable)
            ->whereRaw('LOWER(office_code) = ?', ['buhi'])
            ->value('id');

        $collegeQ = DB::table('dcs_colleges')->whereRaw('LOWER(college_code) = ?', ['buhi']);
        if (Schema::hasColumn('dcs_colleges', 'deleted_at')) {
            $collegeQ->whereNull('deleted_at');
        }
        $collegeId = (int) ($collegeQ->value('id') ?? 0);

        if ($collegeId < 1) {
            $collegeId = (int) DB::table('dcs_colleges')->insertGetId([
                'office_id' => $buhiOfficeId ? (int) $buhiOfficeId : null,
                'college_code' => 'BUHI',
                'college_name' => 'Buhi Campus',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } elseif ($buhiOfficeId && Schema::hasColumn('dcs_colleges', 'office_id')) {
            DB::table('dcs_colleges')->where('id', $collegeId)->update([
                'office_id' => (int) $buhiOfficeId,
                'college_name' => 'Buhi Campus',
                'updated_at' => $now,
            ]);
        }

        $programs = [
            ['code' => 'BECEd', 'name' => 'Bachelor of Early Childhood Education'],
            ['code' => 'BSOA', 'name' => 'Bachelor of Science in Office Administration'],
        ];

        $becedId = 0;
        foreach ($programs as $program) {
            $progQ = DB::table('dcs_programs')
                ->where('college_id', $collegeId)
                ->whereRaw('LOWER(program_code) = ?', [mb_strtolower($program['code'])]);
            if (Schema::hasColumn('dcs_programs', 'deleted_at')) {
                $progQ->whereNull('deleted_at');
            }
            $programId = (int) ($progQ->value('id') ?? 0);
            if ($programId < 1) {
                $programId = (int) DB::table('dcs_programs')->insertGetId([
                    'college_id' => $collegeId,
                    'program_code' => $program['code'],
                    'program_name' => $program['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            if ($program['code'] === 'BECEd') {
                $becedId = $programId;
            }
        }

        if ($becedId < 1 || ! Schema::hasTable('dcs_program_courses') || ! Schema::hasTable('dcs_semesters')) {
            return;
        }

        $semesterId = (int) (DB::table('dcs_semesters')->orderBy('id')->value('id') ?? 0);
        if ($semesterId < 1) {
            return;
        }

        $courseQ = DB::table('dcs_program_courses')
            ->where('program_id', $becedId)
            ->where('semester_id', $semesterId)
            ->whereRaw('LOWER(course_name) = ?', ['the teaching profession']);
        if (Schema::hasColumn('dcs_program_courses', 'deleted_at')) {
            $courseQ->whereNull('deleted_at');
        }
        if ($courseQ->exists()) {
            return;
        }

        $row = [
            'program_id' => $becedId,
            'semester_id' => $semesterId,
            'course_name' => 'The Teaching Profession',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('dcs_program_courses', 'course_code')) {
            $row['course_code'] = 'BECE-TP';
        }
        if (Schema::hasColumn('dcs_program_courses', 'year_level')) {
            $row['year_level'] = '1st Year';
        }
        if (Schema::hasColumn('dcs_program_courses', 'course_type')) {
            $row['course_type'] = 'Major';
        }
        DB::table('dcs_program_courses')->insert($row);
    }
};
