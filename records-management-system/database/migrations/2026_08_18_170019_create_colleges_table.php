<?php
// 022 — colleges

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_colleges')) {
            Schema::create('dcs_colleges', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('office_id')->nullable();
                $table->foreign('office_id')->references('id')->on('office')->cascadeOnDelete();
                $table->string('college_code', 50)->unique();
                $table->string('college_name');
                $table->timestamps();
            });
        }

        if (DB::table('dcs_colleges')->exists()) {
            return;
        }

        $offices = DB::table('office')->get(['id', 'office_code', 'office_name']);
        $resolveOfficeId = function (string $code, string $name) use ($offices): ?int {
            $byCode = $offices->first(fn ($office) => strcasecmp((string) $office->office_code, $code) === 0);
            if ($byCode) {
                return (int) $byCode->id;
            }

            $byName = $offices->first(fn ($office) => strcasecmp((string) $office->office_name, $name) === 0);
            if ($byName) {
                return (int) $byName->id;
            }

            $byPartial = $offices->first(fn ($office) => str_contains(mb_strtolower((string) $office->office_name), mb_strtolower($name))
                || str_contains(mb_strtolower($name), mb_strtolower((string) $office->office_name)));

            return $byPartial ? (int) $byPartial->id : null;
        };

        $now = now();
        $rows = [
            ['id' => 1, 'college_code' => 'CCS',   'college_name' => 'College of Computer Studies'],
            ['id' => 2, 'college_code' => 'CEA',   'college_name' => 'College of Engineering and Architecture'],
            ['id' => 3, 'college_code' => 'CHS',   'college_name' => 'College of Health Sciences'],
            ['id' => 4, 'college_code' => 'CTDE',  'college_name' => 'College of Technological and Developmental Education'],
            ['id' => 5, 'college_code' => 'CTHBM', 'college_name' => 'College of Tourism, Hospitality and Business Management'],
            ['id' => 6, 'college_code' => 'CAS',   'college_name' => 'College of Arts and Sciences'],
        ];

        DB::table('dcs_colleges')->insert(array_map(function (array $row) use ($resolveOfficeId, $now) {
            $row['office_id'] = $resolveOfficeId($row['college_code'], $row['college_name']);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            return $row;
        }, $rows));

        DB::statement("SELECT setval(pg_get_serial_sequence('dcs_colleges', 'id'), (SELECT MAX(id) FROM dcs_colleges))");
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_colleges');
    }
};