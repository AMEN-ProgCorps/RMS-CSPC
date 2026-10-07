<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Third normal form for the five leftover repeating or copied values:
 * department + date, DRF distribution offices, faculty and originator names,
 * random-check document copies, and JSON blobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->splitDepartmentDate();
        $this->splitDistributeTo();
        $this->dropCopiedNames();
        $this->dropRandomCheckCopies();
        $this->splitJsonColumns();
    }

    public function down(): void
    {
        // Data copied into child tables is not folded back into the old columns.
    }

    private function splitDepartmentDate(): void
    {
        if (! Schema::hasTable('dcs_office_intake_dcn')) {
            return;
        }

        if (! Schema::hasColumn('dcs_office_intake_dcn', 'department_on')) {
            Schema::table('dcs_office_intake_dcn', function (Blueprint $table) {
                $table->date('department_on')->nullable();
            });
        }

        if (Schema::hasColumn('dcs_office_intake_dcn', 'department_date')) {
            DB::table('dcs_office_intake_dcn')
                ->whereNotNull('department_date')
                ->orderBy('id')
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $row) {
                        $stored = trim((string) $row->department_date);
                        $dateLabel = str_contains($stored, ' / ')
                            ? trim(explode(' / ', $stored, 2)[1])
                            : '';
                        if ($dateLabel === '') {
                            continue;
                        }
                        try {
                            $iso = \Carbon\Carbon::parse($dateLabel)->format('Y-m-d');
                        } catch (\Throwable) {
                            continue;
                        }
                        DB::table('dcs_office_intake_dcn')->where('id', $row->id)->update([
                            'department_on' => $iso,
                        ]);
                    }
                });

            Schema::table('dcs_office_intake_dcn', function (Blueprint $table) {
                $table->dropColumn('department_date');
            });
        }
    }

    private function splitDistributeTo(): void
    {
        if (! Schema::hasTable('dcs_office_intake_drf')) {
            return;
        }

        if (! Schema::hasTable('dcs_office_drf_distribute_offices')) {
            Schema::create('dcs_office_drf_distribute_offices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('office_intake_drf_id');
                $table->foreign('office_intake_drf_id', 'dcs_drf_distribute_drf_fk')
                    ->references('id')
                    ->on('dcs_office_intake_drf')
                    ->cascadeOnDelete();
                $table->unsignedInteger('office_id');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['office_intake_drf_id', 'office_id'], 'dcs_office_drf_distribute_unique');
            });
        }

        if (! Schema::hasColumn('dcs_office_intake_drf', 'distribute_to')) {
            return;
        }

        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        DB::table('dcs_office_intake_drf')
            ->whereNotNull('distribute_to')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($officeTable) {
                foreach ($rows as $row) {
                    $labels = $this->decodeJson($row->distribute_to);
                    if ($labels === null) {
                        continue;
                    }
                    $now = now();
                    $sort = 0;
                    foreach ($labels as $label) {
                        $label = trim((string) $label);
                        if ($label === '') {
                            continue;
                        }
                        $office = DB::table($officeTable)
                            ->where(function ($q) use ($label) {
                                $q->where('office_code', $label)->orWhere('office_name', $label);
                            })
                            ->first(['id']);
                        if (! $office) {
                            continue;
                        }
                        $exists = DB::table('dcs_office_drf_distribute_offices')
                            ->where('office_intake_drf_id', $row->id)
                            ->where('office_id', $office->id)
                            ->exists();
                        if ($exists) {
                            continue;
                        }
                        DB::table('dcs_office_drf_distribute_offices')->insert([
                            'office_intake_drf_id' => $row->id,
                            'office_id' => $office->id,
                            'sort_order' => $sort++,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });

        Schema::table('dcs_office_intake_drf', function (Blueprint $table) {
            $table->dropColumn('distribute_to');
        });
    }

    private function dropCopiedNames(): void
    {
        if (Schema::hasTable('dcs_syllabi_drf') && Schema::hasColumn('dcs_syllabi_drf', 'faculty_name')) {
            if (Schema::hasTable('dcs_faculties')) {
                DB::table('dcs_syllabi_drf')
                    ->whereNull('faculty_id')
                    ->whereNotNull('faculty_name')
                    ->orderBy('id')
                    ->chunkById(100, function ($rows) {
                        foreach ($rows as $row) {
                            $name = trim((string) $row->faculty_name);
                            if ($name === '') {
                                continue;
                            }
                            $faculty = DB::table('dcs_faculties')
                                ->whereRaw('LOWER(faculty_name) = ?', [mb_strtolower($name)])
                                ->first(['id']);
                            $facultyId = $faculty
                                ? (int) $faculty->id
                                : (int) DB::table('dcs_faculties')->insertGetId([
                                    'faculty_name' => $name,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            DB::table('dcs_syllabi_drf')->where('id', $row->id)->update([
                                'faculty_id' => $facultyId,
                            ]);
                        }
                    });
            }

            Schema::table('dcs_syllabi_drf', function (Blueprint $table) {
                $table->dropColumn('faculty_name');
            });
        }

        if (
            Schema::hasTable('dcs_masterlist_registration')
            && Schema::hasColumn('dcs_masterlist_registration', 'originator_name')
            && Schema::hasColumn('dcs_masterlist_registration', 'originator_id')
            && Schema::hasTable('dcs_originators')
        ) {
            DB::table('dcs_masterlist_registration')
                ->whereNull('originator_id')
                ->whereNotNull('originator_name')
                ->orderBy('id')
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $row) {
                        $name = trim((string) $row->originator_name);
                        if ($name === '') {
                            continue;
                        }
                        $existing = DB::table('dcs_originators')
                            ->whereRaw('LOWER(originator_name) = ?', [mb_strtolower($name)])
                            ->first(['id']);
                        $originatorId = $existing
                            ? (int) $existing->id
                            : (int) DB::table('dcs_originators')->insertGetId([
                                'originator_name' => $name,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        DB::table('dcs_masterlist_registration')->where('id', $row->id)->update([
                            'originator_id' => $originatorId,
                        ]);
                    }
                });

            Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
                $table->dropColumn('originator_name');
            });
        }
    }

    private function dropRandomCheckCopies(): void
    {
        if (! Schema::hasTable('dcs_random_check_items')) {
            return;
        }

        $columns = array_values(array_filter(
            ['doc_no', 'rev_no', 'doc_title', 'effectivity_date', 'doc_type_key', 'doc_type_label'],
            fn (string $column) => Schema::hasColumn('dcs_random_check_items', $column)
        ));
        if ($columns === []) {
            return;
        }

        Schema::table('dcs_random_check_items', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    private function splitJsonColumns(): void
    {
        $this->splitReportFilters();
        $this->splitShiftMappings();
        $this->splitActivityMeta();
    }

    private function splitReportFilters(): void
    {
        if (! Schema::hasTable('dcs_generated_reports')) {
            return;
        }

        if (! Schema::hasTable('dcs_generated_report_filters')) {
            Schema::create('dcs_generated_report_filters', function (Blueprint $table) {
                $table->id();
                $table->foreignId('report_id')
                    ->constrained('dcs_generated_reports')
                    ->cascadeOnDelete();
                $table->string('filter_key', 120);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->text('filter_value');
                $table->index(['report_id', 'filter_key']);
            });
        }

        if (! Schema::hasColumn('dcs_generated_reports', 'filters')) {
            return;
        }

        DB::table('dcs_generated_reports')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $decoded = $this->decodeJson($row->filters ?? null);
                if ($decoded === null) {
                    continue;
                }
                $this->insertFlatRows('dcs_generated_report_filters', 'report_id', (int) $row->id, 'filter_key', 'filter_value', $decoded);
            }
        });

        Schema::table('dcs_generated_reports', function (Blueprint $table) {
            $table->dropColumn('filters');
        });
    }

    private function splitShiftMappings(): void
    {
        if (! Schema::hasTable('dcs_doc_no_shifts')) {
            return;
        }

        if (! Schema::hasTable('dcs_doc_no_shift_lines')) {
            Schema::create('dcs_doc_no_shift_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shift_id')
                    ->constrained('dcs_doc_no_shifts')
                    ->cascadeOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('from_doc_no', 100);
                $table->string('to_doc_no', 100);
            });
        }

        if (! Schema::hasColumn('dcs_doc_no_shifts', 'mappings')) {
            return;
        }

        DB::table('dcs_doc_no_shifts')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $decoded = $this->decodeJson($row->mappings ?? null);
                if ($decoded === null) {
                    continue;
                }
                $nowSort = 0;
                foreach ($decoded as $map) {
                    if (! is_array($map)) {
                        continue;
                    }
                    $from = trim((string) ($map['from'] ?? ''));
                    $to = trim((string) ($map['to'] ?? ''));
                    if ($from === '' || $to === '') {
                        continue;
                    }
                    DB::table('dcs_doc_no_shift_lines')->insert([
                        'shift_id' => $row->id,
                        'sort_order' => $nowSort++,
                        'from_doc_no' => $from,
                        'to_doc_no' => $to,
                    ]);
                }
            }
        });

        Schema::table('dcs_doc_no_shifts', function (Blueprint $table) {
            $table->dropColumn('mappings');
        });
    }

    private function splitActivityMeta(): void
    {
        if (! Schema::hasTable('dcs_activity_events')) {
            return;
        }

        if (! Schema::hasTable('dcs_activity_event_meta')) {
            Schema::create('dcs_activity_event_meta', function (Blueprint $table) {
                $table->id();
                $table->foreignId('event_id')
                    ->constrained('dcs_activity_events')
                    ->cascadeOnDelete();
                $table->string('meta_key', 150);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->text('meta_value');
                $table->index(['event_id', 'meta_key']);
            });
        }

        if (! Schema::hasColumn('dcs_activity_events', 'meta')) {
            return;
        }

        DB::table('dcs_activity_events')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $decoded = $this->decodeJson($row->meta ?? null);
                if ($decoded === null) {
                    continue;
                }
                $this->insertFlatRows('dcs_activity_event_meta', 'event_id', (int) $row->id, 'meta_key', 'meta_value', $decoded);
            }
        });

        Schema::table('dcs_activity_events', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }

    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function insertFlatRows(
        string $table,
        string $ownerColumn,
        int $ownerId,
        string $keyColumn,
        string $valueColumn,
        array $payload
    ): void {
        $rows = [];
        $this->flatten($payload, '', $rows);
        if ($rows === []) {
            return;
        }

        foreach ($rows as $row) {
            DB::table($table)->insert([
                $ownerColumn => $ownerId,
                $keyColumn => $row['key'],
                'sort_order' => $row['sort'],
                $valueColumn => $row['value'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{key: string, sort: int, value: string}>  $rows
     */
    private function flatten(array $payload, string $prefix, array &$rows): void
    {
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $isList = array_keys($value) === range(0, count($value) - 1);
                if ($isList) {
                    foreach (array_values($value) as $index => $item) {
                        if (is_array($item) || $item === null || $item === '') {
                            continue;
                        }
                        $rows[] = [
                            'key' => mb_substr($name, 0, 150),
                            'sort' => $index,
                            'value' => is_bool($item) ? ($item ? '1' : '0') : (string) $item,
                        ];
                    }
                    continue;
                }
                $this->flatten($value, $name, $rows);
                continue;
            }
            $rows[] = [
                'key' => mb_substr($name, 0, 150),
                'sort' => 0,
                'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            ];
        }
    }
};
