<?php

namespace App\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyllabiMonitoringHelper
{
    public const REMARKS = [
        'controlled' => 'Controlled',
        'released' => 'Released',
        'incomplete' => 'Incomplete',
        'late_submission' => 'Late submission',
    ];

    public static function subtypeId(string $needle): ?int
    {
        $needle = strtolower($needle);

        return DB::table('dcs_doc_types')
            ->whereNotNull('parent_id')
            ->get(['id', 'doc_type_name'])
            ->first(function ($row) use ($needle) {
                $name = strtolower((string) $row->doc_type_name);
                if ($needle === 'syllabi') {
                    return str_contains($name, 'syllab');
                }
                if ($needle === 'tos') {
                    return str_contains($name, 'tos') || str_contains($name, 'rubric');
                }

                return false;
            })?->id;
    }

    public static function build(?int $collegeId, ?int $schoolYearId, ?int $semesterId, ?string $deadline): array
    {
        $empty = [
            'ready' => false,
            'rows' => [],
            'totals' => self::emptyTotals(),
            'meta' => [],
            'deadlines' => [],
            'remarks_enabled' => false,
        ];

        if (! $collegeId || ! $schoolYearId || ! $semesterId) {
            return $empty;
        }

        $college = DB::table('dcs_colleges')->where('id', $collegeId)->first();
        $schoolYear = DB::table('dcs_school_years')->where('id', $schoolYearId)->first();
        $semester = DB::table('dcs_semesters')->where('id', $semesterId)->first();
        if (! $college || ! $schoolYear || ! $semester) {
            return $empty;
        }

        $deadlines = self::availableDeadlines($collegeId, $schoolYearId, $semesterId);
        // Only accept a deadline that exists on syllabi masterlist rows for this filter set.
        if ($deadline && ! in_array($deadline, $deadlines, true)) {
            $deadline = null;
        }

        $programs = DB::table('dcs_programs')
            ->where('college_id', $collegeId)
            ->orderBy('program_code')
            ->get(['id', 'program_code', 'program_name']);

        $courseColumns = ['id', 'program_id', 'course_name'];
        if (Schema::hasColumn('dcs_program_courses', 'course_code')) {
            $courseColumns[] = 'course_code';
        }
        $courses = DB::table('dcs_program_courses')
            ->where('semester_id', $semesterId)
            ->whereIn('program_id', $programs->pluck('id')->all() ?: [0])
            ->orderBy('course_name')
            ->get($courseColumns);

        $coursesByProgram = $courses->groupBy('program_id');

        $syllabiTypeId = self::subtypeId('syllabi');
        $tosTypeId = self::subtypeId('tos');

        $submissions = self::loadSubmissions($collegeId, $schoolYearId, $semesterId, $deadline);

        $rows = [];
        $totals = self::emptyTotals();
        $remarksEnabled = $deadline !== null && $deadline !== '';

        foreach ($programs as $program) {
            $catalog = $coursesByProgram->get($program->id, collect());
            $catalogIds = $catalog->pluck('id')->all();

            $syllabiSubs = $submissions
                ->where('program_id', $program->id)
                ->where('sub_type_id', $syllabiTypeId);
            $tosSubs = $submissions
                ->where('program_id', $program->id)
                ->where('sub_type_id', $tosTypeId);

            $syllabiActualIds = $syllabiSubs->pluck('course_id')->unique()->filter()->values();
            $tosActualIds = $tosSubs->pluck('course_id')->unique()->filter()->values();

            $target = count($catalogIds);
            $syllabiActual = $syllabiActualIds->intersect($catalogIds)->count();
            if ($target === 0) {
                $syllabiActual = $syllabiActualIds->count();
            }
            $syllabiLacking = max(0, $target - $syllabiActual);
            $lackingNames = $catalog
                ->reject(fn ($c) => $syllabiActualIds->contains($c->id))
                ->map(fn ($c) => self::courseLabel($c))
                ->values()
                ->all();

            $tosTarget = $target;
            $tosActual = $tosActualIds->intersect($catalogIds)->count();
            if ($tosTarget === 0) {
                $tosActual = $tosActualIds->count();
            }

            $drfCourseIds = $syllabiSubs
                ->filter(fn ($row) => (int) $row->drf_actual > 0)
                ->pluck('course_id')
                ->unique()
                ->filter()
                ->values();
            $drfActual = $drfCourseIds->intersect($catalogIds)->count();
            if ($target === 0) {
                $drfActual = $drfCourseIds->count();
            }
            $drfLackingNames = $catalog
                ->reject(fn ($c) => $drfCourseIds->contains($c->id))
                ->map(fn ($c) => self::courseLabel($c))
                ->values()
                ->all();
            $tosDrfCourseIds = $tosSubs
                ->filter(fn ($row) => (int) $row->drf_actual > 0)
                ->pluck('course_id')
                ->unique()
                ->filter()
                ->values();
            $tosDrfActual = $tosDrfCourseIds->intersect($catalogIds)->count();
            if ($tosTarget === 0) {
                $tosDrfActual = $tosDrfCourseIds->count();
            }
            $tosLackingNames = $catalog
                ->reject(fn ($c) => $tosActualIds->contains($c->id))
                ->map(fn ($c) => self::courseLabel($c))
                ->values()
                ->all();

            $saved = $remarksEnabled
                ? self::savedStatuses($collegeId, $schoolYearId, $semesterId, (int) $program->id, $deadline)
                : [];
            $syllabiStatus = $saved['syllabi'] ?? self::suggestStatus($syllabiActual, $target, $syllabiSubs, $deadline);
            $tosStatus = $saved['tos'] ?? self::suggestStatus($tosActual, $tosTarget, $tosSubs, $deadline);

            $row = [
                'program_id' => (int) $program->id,
                'program_code' => $program->program_code,
                'program_name' => $program->program_name,
                'syllabi_label' => $syllabiActual . ' / ' . $target,
                'syllabi_target' => $target,
                'syllabi_actual' => $syllabiActual,
                'syllabi_pct' => self::pct($syllabiActual, $target),
                'syllabi_lacking' => $syllabiLacking,
                'syllabi_lacking_names' => $lackingNames,
                'submission_dates' => self::uniqueDates($syllabiSubs, 'date_received'),
                'effectivity_date' => self::uniqueDates($syllabiSubs, 'effectivity_date'),
                'released_date' => self::uniqueDates($syllabiSubs, 'released_date'),
                'drf_label' => $drfActual . ' / ' . $target,
                'drf_target' => $target,
                'drf_actual' => $drfActual,
                'drf_pct' => self::pct($drfActual, $target),
                'drf_lacking' => count($drfLackingNames),
                'drf_lacking_names' => $drfLackingNames,
                'drf_received' => self::uniqueDates($syllabiSubs, 'drf_received_date'),
                'syllabi_status' => $syllabiStatus,
                'tos_courses' => $catalog
                    ->filter(fn ($c) => $tosActualIds->contains($c->id))
                    ->map(fn ($c) => self::courseLabel($c))
                    ->values()
                    ->all(),
                'tos_target' => $tosTarget,
                'tos_actual' => $tosActual,
                'tos_pct' => self::pct($tosActual, $tosTarget),
                'tos_label' => $tosActual . ' / ' . $tosTarget,
                'tos_lacking_names' => $tosLackingNames,
                'tos_drf_label' => $tosDrfActual . ' / ' . $tosTarget,
                'tos_status' => $tosStatus,
            ];

            $rows[] = $row;
            $totals['syllabi_target'] += $target;
            $totals['syllabi_actual'] += $syllabiActual;
            $totals['syllabi_lacking'] += $syllabiLacking;
            $totals['drf_target'] += $target;
            $totals['drf_actual'] += $drfActual;
            $totals['drf_lacking'] += count($drfLackingNames);
            $totals['tos_target'] += $tosTarget;
            $totals['tos_actual'] += $tosActual;
        }

        $totals['syllabi_pct'] = self::pct($totals['syllabi_actual'], $totals['syllabi_target']);
        $totals['drf_pct'] = self::pct($totals['drf_actual'], $totals['drf_target']);
        $totals['tos_pct'] = self::pct($totals['tos_actual'], $totals['tos_target']);

        return [
            'ready' => true,
            'rows' => $rows,
            'totals' => $totals,
            'deadlines' => $deadlines,
            'remarks_enabled' => $remarksEnabled,
            'meta' => [
                'college' => $college->college_name,
                'college_code' => $college->college_code,
                'school_year' => $schoolYear->school_year,
                'semester' => $semester->semester_name,
                'deadline' => $deadline ? self::formatDate($deadline) : null,
            ],
        ];
    }

    /**
     * Distinct masterlist deadlines from syllabi registrations for this college/year/semester.
     *
     * @return list<string> Y-m-d dates
     */
    public static function availableDeadlines(int $collegeId, int $schoolYearId, int $semesterId): array
    {
        $query = DB::table('dcs_syllabi as s')
            ->join('dcs_masterlist_registration as ml', 'ml.request_id', '=', 's.request_id')
            ->where('s.college_id', $collegeId)
            ->where('s.school_year_id', $schoolYearId)
            ->where('s.semester_id', $semesterId)
            ->whereNotNull('ml.deadline');

        if (Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            $query->join('dcs_document_requests as dr', 'dr.id', '=', 's.request_id')
                ->whereNull('dr.deleted_at');
        }

        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            $query->where('ml.revision_status', '!=', 'obsolete');
        }

        return $query
            ->orderBy('ml.deadline')
            ->distinct()
            ->pluck('ml.deadline')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->unique()
            ->values()
            ->all();
    }

    private static function loadSubmissions(int $collegeId, int $schoolYearId, int $semesterId, ?string $deadline)
    {
        $query = DB::table('dcs_syllabi as s')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 's.request_id')
            ->leftJoin('dcs_masterlist_registration as ml', 'ml.request_id', '=', 's.request_id')
            ->leftJoin('dcs_document_distribution as dist', 'dist.request_id', '=', 's.request_id')
            ->leftJoin('dcs_program_courses as pc', 'pc.id', '=', 's.course_id')
            ->where('s.college_id', $collegeId)
            ->where('s.school_year_id', $schoolYearId)
            ->where('s.semester_id', $semesterId)
            ->where('s.is_available', true);

        if ($deadline) {
            $query->whereDate('ml.deadline', $deadline);
        }

        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            $query->where(function ($q) {
                $q->whereNull('ml.revision_status')
                    ->orWhere('ml.revision_status', '!=', 'obsolete');
            });
        }

        $rows = $query->get([
            's.id',
            's.program_id',
            's.course_id',
            's.no_copies',
            's.date_received',
            'pc.course_name',
            'dr.sub_type_id',
            'ml.effectivity_date',
            'ml.deadline',
            'dist.doc_distribution_date_actual as released_date',
            'dist.remarks',
        ]);

        $drfBySyllabi = collect();
        if ($rows->isNotEmpty() && Schema::hasTable('dcs_syllabi_drf')) {
            $drfBySyllabi = DB::table('dcs_syllabi_drf')
                ->whereIn('syllabi_id', $rows->pluck('id')->all())
                ->get(['syllabi_id', 'is_drf_available', 'drf_received_date'])
                ->groupBy('syllabi_id');
        }

        return $rows->map(function ($row) use ($drfBySyllabi) {
            $drfs = $drfBySyllabi->get($row->id, collect());
            $row->drf_rows = $drfs->count();
            $row->drf_actual = $drfs->where('is_drf_available', true)->count();
            $row->drf_received_date = $drfs->pluck('drf_received_date')->filter()->unique()->implode(',');

            return $row;
        });
    }

    public static function saveStatus(
        int $collegeId,
        int $schoolYearId,
        int $semesterId,
        int $programId,
        string $section,
        ?string $deadline,
        string $status
    ): void {
        if (! Schema::hasTable('dcs_syllabi_monitoring_status')) {
            return;
        }
        if (! isset(self::REMARKS[$status]) && $status !== '') {
            return;
        }

        // Remarks are always scoped to a real syllabi masterlist deadline.
        $deadline = $deadline ? Carbon::parse($deadline)->format('Y-m-d') : null;
        if (! $deadline) {
            return;
        }
        $allowed = self::availableDeadlines($collegeId, $schoolYearId, $semesterId);
        if (! in_array($deadline, $allowed, true)) {
            return;
        }

        $keys = [
            'college_id' => $collegeId,
            'school_year_id' => $schoolYearId,
            'semester_id' => $semesterId,
            'program_id' => $programId,
            'section' => $section === 'tos' ? 'tos' : 'syllabi',
            'deadline' => $deadline,
        ];

        $existing = DB::table('dcs_syllabi_monitoring_status')->where($keys)->first();
        $now = now();
        if ($status === '') {
            if ($existing) {
                DB::table('dcs_syllabi_monitoring_status')->where('id', $existing->id)->delete();
            }
            return;
        }

        if ($existing) {
            DB::table('dcs_syllabi_monitoring_status')->where('id', $existing->id)->update([
                'status' => $status,
                'updated_at' => $now,
            ]);
            return;
        }

        DB::table('dcs_syllabi_monitoring_status')->insert($keys + [
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function savedStatuses(int $collegeId, int $schoolYearId, int $semesterId, int $programId, string $deadline): array
    {
        if (! Schema::hasTable('dcs_syllabi_monitoring_status')) {
            return [];
        }

        return DB::table('dcs_syllabi_monitoring_status')
            ->where('college_id', $collegeId)
            ->where('school_year_id', $schoolYearId)
            ->where('semester_id', $semesterId)
            ->where('program_id', $programId)
            ->whereDate('deadline', $deadline)
            ->pluck('status', 'section')
            ->all();
    }

    private static function suggestStatus(int $actual, int $target, $subs, ?string $deadline): string
    {
        if ($target > 0 && $actual < $target) {
            return 'incomplete';
        }
        if ($deadline) {
            foreach ($subs as $row) {
                if (! empty($row->date_received) && Carbon::parse($row->date_received)->gt(Carbon::parse($deadline)->endOfDay())) {
                    return 'late_submission';
                }
            }
        }
        $released = 0;
        foreach ($subs as $row) {
            if (! empty($row->released_date)) {
                $released++;
            }
        }
        if ($actual > 0 && $released >= $actual) {
            return 'released';
        }
        if ($target > 0 && $actual >= $target) {
            return 'controlled';
        }

        return '';
    }

    private static function courseLabel(object $course): string
    {
        $code = trim((string) ($course->course_code ?? ''));
        $name = trim((string) ($course->course_name ?? ''));

        return $code !== '' ? $code : $name;
    }

    private static function pct(int $actual, int $target): ?float
    {
        if ($target <= 0) {
            return null;
        }

        return round(($actual / $target) * 100, 1);
    }

    private static function uniqueDates($subs, string $field): string
    {
        $dates = [];
        foreach ($subs as $row) {
            $raw = $row->{$field} ?? null;
            if ($field === 'drf_received_date' && is_string($raw) && str_contains($raw, ',')) {
                foreach (explode(',', $raw) as $part) {
                    $formatted = self::formatDate(trim($part));
                    if ($formatted) {
                        $dates[$formatted] = true;
                    }
                }
                continue;
            }
            $formatted = self::formatDate($raw);
            if ($formatted) {
                $dates[$formatted] = true;
            }
        }

        return implode(', ', array_keys($dates));
    }

    private static function uniqueText($subs, string $field): string
    {
        $values = [];
        foreach ($subs as $row) {
            $text = trim((string) ($row->{$field} ?? ''));
            if ($text !== '') {
                $values[$text] = true;
            }
        }

        return implode('; ', array_keys($values));
    }

    private static function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->format('m/d/Y');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function emptyTotals(): array
    {
        return [
            'syllabi_target' => 0,
            'syllabi_actual' => 0,
            'syllabi_pct' => null,
            'syllabi_lacking' => 0,
            'drf_target' => 0,
            'drf_actual' => 0,
            'drf_pct' => null,
            'drf_lacking' => 0,
            'tos_target' => 0,
            'tos_actual' => 0,
            'tos_pct' => null,
        ];
    }
}
