<?php

namespace App\View\Components\Dcs;

use App\Helpers\RegisterQueryHelper;
use App\Helpers\ReportHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared report browser for Generate Report and Monitoring.
 * Loaded with the other DCS component files under resources/views/components/dcs.
 */
trait InteractsWithDcsReport
{
    public string $category = 'masterlist';
    public string $sub = '';
    public string $period = 'annually';
    public string $asOf = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $originator = '';
    public string $sourceUnit = '';
    public string $revisionStatus = 'all';
    public string $revNo = '';
    public array $subTypeIds = [];
    public string $monitoringDocType = '';
    public array $monitoringSubTypeIds = [];
    public string $formYear = '';
    public string $sortBy = 'effectivity_date';
    public string $sortDir = 'asc';
    public bool $exportOpen = false;
    public bool $filterOpen = false;
    public bool $columnsOpen = false;
    public array $exportColumns = [];
    public array $selectedMlIds = [];
    public string $error = '';
    public array $result = [];
    public int $previewPage = 1;
    /** Server cache key for the full row set. The browser only receives the current page. */
    public string $reportCacheKey = '';
    /** Masterlist ids for select-all. Kept separate so the row payload stays off the page. */
    public array $allMlIds = [];

    public function mount(): void
    {
        $this->asOf = now('Asia/Manila')->toDateString();
        $this->category = match (request()->route()?->getName()) {
            'dcs.reports.opcr' => 'opcr',
            'dcs.reports.others' => 'others',
            default => 'masterlist',
        };
        // Masterlist / Monitoring / OPCR default to all-time so older registration
        // dates (common for External docs) are not hidden by the annual window.
        if (in_array($this->category, ['masterlist', 'monitoring', 'opcr'], true)) {
            $this->period = 'all';
        }
        if ($this->category === 'others') {
            $this->loadReport();
        }
    }

    public function goPreviewPage(int $page): void
    {
        $this->previewPage = max(1, $page);
    }

    /**
     * On-screen tables show one page so a large report does not freeze the browser.
     * Export and print still use the full result.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, page: int, last: int, total: int, from: int}
     */
    public function slicePreviewRows(array $rows): array
    {
        $rows = array_values($rows);
        $perPage = 40;
        $total = count($rows);
        $last = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $this->previewPage), $last);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'rows' => $slice,
            'page' => $page,
            'last' => $last,
            'total' => $total,
            'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
        ];
    }

    public function with(): array
    {
        $filters = Cache::remember('dcs.report.filter-options.v1', 90, function () {
            return [
                'originators' => Schema::hasTable('dcs_originators')
                    ? DB::table('dcs_originators')->orderBy('originator_name')->get()
                    : collect(),
                'offices' => \App\Helpers\RegisterQueryHelper::applySelectableOfficesFilter(
                    DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('is_active', true)
                )->orderBy('office_name')->get(),
                'revisionNos' => DB::table('dcs_masterlist_registration')
                    ->whereNotNull('revise_no')
                    ->distinct()
                    ->orderBy('revise_no')
                    ->pluck('revise_no'),
                'allDocTypes' => DB::table('dcs_doc_types')->orderBy('id')->get(['id', 'doc_type_name', 'parent_id']),
            ];
        });
        $allDocTypes = $filters['allDocTypes'];
        $parentId = RegisterQueryHelper::parentTypeIdMap()[$this->sub] ?? null;
        $childTypes = $parentId
            ? $allDocTypes->filter(fn ($d) => (string) $d->parent_id === (string) $parentId)->values()
            : collect();

        return [
            'originators' => $filters['originators'],
            'offices' => $filters['offices'],
            'revisionNos' => $filters['revisionNos'],
            'allDocTypes' => $allDocTypes,
            'childTypes' => $childTypes,
            'isOpcr' => $this->category === 'opcr',
            'isMonitoring' => $this->category === 'monitoring',
            'isOthers' => $this->category === 'others',
            'formYears' => $this->category === 'monitoring' && $this->sub !== ''
                ? RegisterQueryHelper::monitoringReportYears($this->sub)
                : [],
            'pageTitle' => match ($this->category) {
                'monitoring' => 'Monitoring Reports',
                'opcr' => 'OPCR Targets',
                'others' => 'General Report',
                default => 'Document Masterlist',
            },
            'periodWindow' => $this->periodWindow(),
            'awaitingSubType' => $this->category === 'monitoring'
                && $this->sub !== ''
                && $childTypes->isNotEmpty()
                && $this->subTypeIds === [],
        ];
    }

    /** Inclusive dates the report will include, based on Period + ending date. */
    public function periodWindow(): array
    {
        $asOf = $this->asOf !== '' ? $this->asOf : now('Asia/Manila')->toDateString();
        $end = \Carbon\Carbon::parse($asOf)->startOfDay();

        if ($this->period === 'custom') {
            $from = $this->dateFrom !== '' ? $this->dateFrom : null;
            $to = $this->dateTo !== '' ? $this->dateTo : null;
            $fromLabel = $from ? \Carbon\Carbon::parse($from)->format('M j, Y') : '…';
            $toLabel = $to ? \Carbon\Carbon::parse($to)->format('M j, Y') : '…';

            return [
                'from' => $from,
                'to' => $to,
                'label' => $fromLabel.' – '.$toLabel,
                'dateLabel' => 'Dates',
            ];
        }

        if ($this->period === 'all') {
            return [
                'from' => null,
                'to' => $end->toDateString(),
                'label' => 'Everything dated on or before '.$end->format('M j, Y'),
                'dateLabel' => 'Up to',
            ];
        }

        $start = match ($this->period) {
            'weekly' => $end->copy()->startOfWeek(\Carbon\Carbon::MONDAY),
            'monthly' => $end->copy()->startOfMonth(),
            'quarterly' => $end->copy()->firstOfQuarter(),
            default => $end->copy()->startOfYear(),
        };

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'label' => $start->format('M j, Y').' – '.$end->format('M j, Y'),
            'dateLabel' => match ($this->period) {
                'weekly' => 'Week ending',
                'monthly' => 'Month ending',
                'quarterly' => 'Quarter ending',
                default => 'Year ending',
            },
        ];
    }

    public function selectSub(string $sub): void
    {
        $this->exportColumns = [];
        $this->selectedMlIds = [];
        $this->sub = $sub;
        $parentId = RegisterQueryHelper::parentTypeIdMap()[$sub] ?? null;
        $childIds = $parentId
            ? DB::table('dcs_doc_types')
                ->where('parent_id', $parentId)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all()
            : [];
        if ($this->category === 'monitoring') {
            $this->monitoringDocType = match ($sub) {
                'internal_docs' => 'Internal',
                'external_docs' => 'External',
                'internal_forms' => 'Internal Forms',
                'forms' => 'Forms',
                'logbooks' => 'Logbooks',
                default => '',
            };
            $this->monitoringSubTypeIds = [];
            $years = RegisterQueryHelper::monitoringReportYears($sub);
            if ($this->formYear !== '' && ! in_array($this->formYear, $years, true)) {
                $this->formYear = '';
            }
            if ($childIds !== []) {
                $this->subTypeIds = [];
                $this->result = [];
                $this->error = '';
                $this->reportCacheKey = '';
                $this->allMlIds = [];

                return;
            }
        }
        $this->subTypeIds = $childIds;
        $this->loadReport();
    }

    public function selectSubType(string $id): void
    {
        $parentId = RegisterQueryHelper::parentTypeIdMap()[$this->sub] ?? null;
        $allIds = $parentId
            ? DB::table('dcs_doc_types')
                ->where('parent_id', $parentId)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all()
            : [];
        $this->subTypeIds = $id === 'all' ? $allIds : [$id];
        $this->loadReport();
    }

    public function openFilters(): void
    {
        $this->columnsOpen = false;
        $this->filterOpen = true;
    }

    public function closeFilters(): void
    {
        $this->filterOpen = false;
    }

    public function applyFilters(): void
    {
        $this->filterOpen = false;
        $this->loadReport();
    }

    public function updated($name): void
    {
        if ($this->category === 'others' && in_array($name, ['originator', 'sourceUnit', 'revisionStatus', 'revNo', 'subTypeIds'], true)) {
            $this->loadReport();
        }
    }

    public function toggleSortDir(): void
    {
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        $this->loadReport();
    }

    public function selectAllSubTypes(): void
    {
        if ($this->category === 'monitoring' && $this->sub !== '') {
            $this->selectSubType('all');

            return;
        }
        $this->selectSub($this->sub);
    }

    public function clearSubTypes(): void
    {
        $this->selectAllSubTypes();
    }

    public function resetFilters(): void
    {
        $this->period = in_array($this->category, ['masterlist', 'monitoring', 'opcr'], true) ? 'all' : 'annually';
        $this->asOf = now('Asia/Manila')->toDateString();
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->originator = '';
        $this->sourceUnit = '';
        $this->revisionStatus = 'all';
        $this->revNo = '';
        $this->monitoringSubTypeIds = [];
        $this->formYear = '';
        $this->sortBy = 'effectivity_date';
        $this->sortDir = 'asc';
        if ($this->sub !== '') {
            $this->selectAllSubTypes();
        } else {
            $this->subTypeIds = [];
            $this->loadReport();
        }
        $this->filterOpen = false;
    }

    public function loadReport(): void
    {
        $this->previewPage = 1;
        $this->error = '';
        $input = $this->queryInput();
        if (($this->category !== 'others') && $this->sub === '') {
            return;
        }
        $parentId = RegisterQueryHelper::parentTypeIdMap()[$this->sub] ?? null;
        if ($this->category === 'monitoring' && $parentId && $this->subTypeIds === []) {
            $hasChildren = DB::table('dcs_doc_types')->where('parent_id', $parentId)->exists();
            if ($hasChildren) {
                return;
            }
        }
        $payload = ReportHelper::payload($input);
        $rows = $payload['rows'] ?? [];
        if ($rows instanceof \Illuminate\Support\Collection) {
            $rows = $rows->values()->all();
        }
        $rows = array_values(is_array($rows) ? $rows : []);
        unset($payload['rows']);
        $payload['total_rows'] = count($rows);
        $this->storeReportRows($rows);
        $this->allMlIds = [];
        if ($this->category === 'masterlist') {
            foreach ($rows as $row) {
                $id = (int) ($row['ml_id'] ?? 0);
                if ($id > 0) {
                    $this->allMlIds[] = (string) $id;
                }
            }
        }
        $this->result = $payload;
        if (! empty($this->result['error'])) {
            $this->error = $this->result['error'];
            $this->result['columns'] = $this->result['columns'] ?? [];
        }
        $this->syncExportColumns();
    }

    /**
     * Full report rows live in cache. The Livewire snapshot keeps columns and the visible page only.
     *
     * @return list<array<string, mixed>>
     */
    public function reportRows(): array
    {
        if ($this->reportCacheKey !== '') {
            $cached = Cache::get($this->reportCacheKey);
            if (is_array($cached)) {
                return $cached;
            }
            if (($this->result['columns'] ?? []) !== []) {
                try {
                    $payload = ReportHelper::payload($this->queryInput());
                    $rows = $payload['rows'] ?? [];
                    if ($rows instanceof \Illuminate\Support\Collection) {
                        $rows = $rows->values()->all();
                    }
                    $rows = array_values(is_array($rows) ? $rows : []);
                    $this->storeReportRows($rows);

                    return $rows;
                } catch (\Throwable) {
                    return [];
                }
            }
        }

        return [];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function storeReportRows(array $rows): void
    {
        if ($this->reportCacheKey === '') {
            $this->reportCacheKey = 'dcs.report.rows.'.sha1((string) (auth()->id() ?? 'guest').'|'.uniqid('', true));
        }
        Cache::put($this->reportCacheKey, array_values($rows), now()->addMinutes(20));
    }

    private function updateCachedReportRow(int $requestId, callable $mutate): void
    {
        $rows = $this->reportRows();
        foreach ($rows as $i => $row) {
            if ((int) ($row['request_id'] ?? 0) !== $requestId) {
                continue;
            }
            $rows[$i] = $mutate($row);
            break;
        }
        $this->storeReportRows($rows);
    }

    public function openColumns(): void
    {
        $this->columnsOpen = true;
    }

    public function openExcelExport(): void
    {
        $this->filterOpen = false;
        $this->syncExportColumns();
        $this->columnsOpen = true;
    }

    public function closeColumns(): void
    {
        $this->columnsOpen = false;
    }

    public function selectAllExportColumns(): void
    {
        $this->exportColumns = array_keys($this->result['columns'] ?? []);
    }

    public function syncExportColumns(): void
    {
        $keys = array_keys($this->result['columns'] ?? []);
        if ($keys === []) {
            $this->exportColumns = [];

            return;
        }
        if ($this->exportColumns === []) {
            $this->exportColumns = $keys;

            return;
        }
        $kept = array_values(array_intersect($this->exportColumns, $keys));
        $this->exportColumns = $kept !== [] ? $kept : $keys;
    }

    public function previewUrl(): string
    {
        return route('dcs.reports.export', array_merge($this->queryInput(), [
            'format' => 'html',
            'embed' => 1,
        ]));
    }

    public function saveRatingField(int $requestId, string $field, $value = null): void
    {
        $allowed = ['rating_q', 'rating_e', 'rating_t', 'rating_a', 'remarks'];
        if (! in_array($field, $allowed, true) || $this->sub === '') {
            return;
        }

        // Normalize Livewire/JS payloads (string "5", int 5, or empty).
        if (is_array($value) && array_key_exists('value', $value)) {
            $value = $value['value'];
        }
        if (is_string($value)) {
            $value = trim($value);
        }

        $saved = app(ReportHelper::class)->saveOpcrRatingField(
            $requestId,
            $this->sub,
            $field === 'remarks' ? 'remarks_override' : $field,
            $value === '' ? null : $value
        );

        $this->updateCachedReportRow($requestId, function (array $row) use ($field, $saved) {
            if ($field === 'remarks') {
                $row['remarks'] = $saved;
                $row['remarks_override'] = $saved;
            } else {
                $row[$field] = $saved;
            }

            return $row;
        });
    }

    public function saveMonitoringRemark(int $requestId, $value = null): void
    {
        if ($requestId < 1) {
            return;
        }
        if (is_array($value) && array_key_exists('value', $value)) {
            $value = $value['value'];
        }
        if (is_string($value)) {
            $value = trim($value);
        }
        $saved = app(ReportHelper::class)->saveMonitoringRemark($requestId, $value === '' ? null : $value);
        $this->updateCachedReportRow($requestId, function (array $row) use ($saved) {
            $row['remarks'] = $saved;

            return $row;
        });
    }

    public function saveMonitoringForwardedDrr(int $requestId, $value = null): void
    {
        if ($requestId < 1) {
            return;
        }
        if (is_array($value) && array_key_exists('value', $value)) {
            $value = $value['value'];
        }
        $checked = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        $saved = app(ReportHelper::class)->saveMonitoringForwardedDrr($requestId, $checked);
        $this->updateCachedReportRow($requestId, function (array $row) use ($saved) {
            $row['forwarded_drr'] = $saved;

            return $row;
        });
    }

    public function toggleMasterlistSelection(array $ids): void
    {
        $ids = array_values(array_filter(array_map('strval', $ids), fn ($id) => $id !== '' && $id !== '0'));
        $current = array_map('strval', $this->selectedMlIds);
        $allOn = $ids !== [] && array_diff($ids, $current) === [];
        $this->selectedMlIds = $allOn
            ? array_values(array_diff($current, $ids))
            : array_values(array_unique(array_merge($current, $ids)));
    }

    public function exportUrl(string $format): string
    {
        $query = $this->queryInput(forExport: true);
        if ($format !== 'xlsx') {
            unset($query['columns']);
        }
        // Print opens the same HTML letterhead layout as the on-screen preview
        // (what you see is what prints). PDF download stays Dompdf.
        if ($format === 'print') {
            $query['format'] = 'html';
            $query['autoPrint'] = 1;
        } else {
            $query['format'] = $format;
        }

        return route('dcs.reports.export', $query);
    }

    private function queryInput(bool $forExport = false): array
    {
        $input = [
            'category' => $this->category,
            'sub' => $this->sub,
            'period' => $this->period,
            'as_of' => $this->asOf,
            'originator' => $this->originator,
            'source_unit' => $this->sourceUnit,
            'revision_status' => $this->revisionStatus,
            'rev_no' => $this->revNo,
            'form_year' => $this->formYear,
            'sort' => $this->sortBy,
            'sort_dir' => $this->sortDir,
        ];
        if ($this->period === 'custom') {
            $input['date_from'] = $this->dateFrom;
            $input['date_to'] = $this->dateTo;
        }
        if ($this->category === 'masterlist' && $this->selectedMlIds !== []) {
            $input['ml_ids'] = implode(',', array_map('intval', $this->selectedMlIds));
        }
        if ($forExport && $this->category === 'monitoring' && $this->exportColumns !== []) {
            $input['columns'] = implode(',', $this->exportColumns);
        }
        $parentId = RegisterQueryHelper::parentTypeIdMap()[$this->sub] ?? null;
        $allIds = DB::table('dcs_doc_types')
            ->when($parentId, fn ($q) => $q->where('parent_id', $parentId))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
        if ($parentId && $allIds !== []) {
            $selected = $this->subTypeIds !== [] ? $this->subTypeIds : $allIds;
            if (count($selected) < count($allIds)) {
                $input['sub_type_ids'] = implode(',', $selected);
            }
        }

        return array_filter($input, fn ($v) => $v !== '' && $v !== null);
    }
}
