{{--
  DCS PAGE: DCS Inventory / Database list
  FILE: resources/views/pages/dcs/database/index.blade.php
  DEBUG: DcsDatabaseColumns; RegisterQueryHelper inventory; database.css
--}}
<?php

/**
 * DCS file: Document database. Parents are the latest copy. Superseded and obsolete copies are children. The revision filter keeps a row only when its own status matches.
 */

use App\Helpers\DcsDatabaseColumns;
use App\Helpers\DocumentNumberSeriesHelper;
use App\Helpers\RegisterQueryHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    public string $search = '';
    public string $docTypeId = 'all';
    public string $subTypeId = 'all';
    public string $originator = '';
    public string $sourceUnit = '';
    public string $receivedBy = '';
    public string $status = '';
    public string $revisionStatus = 'all';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $revNo = '';
    public int $page = 1;
    public string $notice = '';
    public bool $exportAllColumns = false;
    public string $customReportTitle = 'DOCUMENT MASTERLIST';
    public string $customReportOrientation = 'landscape';
    public string $customReportPaperSize = 'a4';
    public bool $customReportIncludeRevisions = true;
    /** @var list<int|string> Selected column indexes for Custom Report */
    public array $customReportColumns = [0, 2, 3, 4, 5, 6, 8, 10];

    /**
     * Initializes component state, authorization gates, and default configurations.
     * @return void
     */
    public function mount(): void
    {
        RegisterQueryHelper::assertFullDcsUser('database');

        $type = request('type');
        if ($type !== null && $type !== '') {
            $this->docTypeId = (string) $type;
        }
        $revision = request('revision');
        if (in_array($revision, ['all', 'latest', 'superseded', 'obsolete'], true)) {
            $this->revisionStatus = $revision;
        }
        $search = request('search');
        if (is_string($search) && trim($search) !== '') {
            $this->search = trim($search);
        }
    }

    /**
     * Updated action.
     *
     * @param mixed $name
     *
     * @return void
     */
    public function updated($name): void
    {
        if (in_array($name, ['page', 'notice', 'customReportOpen', 'customReportPreview', 'customReportTitle', 'customReportOrientation', 'customReportIncludeRevisions', 'customReportColumns', 'customReportError'], true) || str_starts_with((string) $name, 'customReportColumns.')) {
            return;
        }
        $this->page = 1;
    }

    /**
     * Sets the active type.
     *
     * @param string $id
     *
     * @return void
     */
    public function setType(string $id): void
    {
        $this->docTypeId = $id;
        $this->page = 1;
    }

    /**
     * Resets filters and clears active selections.
     * @return void
     */
    public function resetFilters(): void
    {
        $this->subTypeId = 'all';
        $this->originator = '';
        $this->sourceUnit = '';
        $this->receivedBy = '';
        $this->status = '';
        $this->revisionStatus = 'all';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->revNo = '';
        $this->page = 1;
    }

    /** Apply deferred filter panel values (shows loading overlay). */
    public function applyFilters(): void
    {
        $this->page = 1;
    }

    /**
     * Gos to page.
     *
     * @param int $page
     *
     * @return void
     */
    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    /**
     * Persist Inventory column visibility system-wide (same store as Admin Settings → DCS).
     * Renderless so hiding a column does not remorph the table and collapse category toggles.
     *
     * @param  array<string, mixed>  $visible
     */
    #[Renderless]
    public function saveVisibleGroups(array $visible): void
    {
        RegisterQueryHelper::assertFullDcsUser('database');
        DcsDatabaseColumns::persistSystemVisibleGroups($visible);
    }

    /**
     * All 40 inventory export headers grouped by section for the Custom Report modal.
     *
     * @return array{headers: list<string>, groups: array<string, list<int>>}
     */
    private function customReportCatalog(): array
    {
        return [
            'headers' => [
                'Item No.',
                'Category',
                'Document No.',
                'Rev.',
                'Document Title',
                'Effectivity Date',
                'Originator',
                'Pages',
                'Status',
                'PDF File',
                'Source Unit',
                'Related Docs',
                'Courses',
                'Approval No.',
                'Approval Date',
                'Deadline Date',
                'Day Diff',
                'ML Receipt Date',
                'ML Receipt Time',
                'ML Registered Date',
                'ML Registered Time',
                'Time Spent (mins)',
                'DCN No.',
                'DCN Date',
                'DCN Receipt Date',
                'DCN Receipt Time',
                'Purpose of Revision',
                'Scanned DCN',
                'DRF No.',
                'DRF Date',
                'DRF Receipt Date',
                'DRF Receipt Time',
                'Scanned DRF',
                'Dist. On File Date',
                'Dist. On File Time',
                'Dist. Actual Date',
                'Dist. Actual Time',
                'Receiving Office(s)',
                'Scanned Dist.',
                'Retrieved Office(s)',
            ],
            'groups' => [
                'Document Information' => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
                'Masterlist & Approval' => [13, 14, 15, 16, 17, 18, 19, 20, 21],
                'DCN Details' => [22, 23, 24, 25, 26, 27],
                'DRF Details' => [28, 29, 30, 31, 32],
                'Distribution & Retrieval' => [33, 34, 35, 36, 37, 38, 39],
            ],
        ];
    }

    #[Renderless]
    public function loadCustomReportPreview(array $config = []): array
    {
        RegisterQueryHelper::assertFullDcsUser('database');
        $this->applyCustomReportConfig($config);

        return $this->buildCustomReportPayload(60);
    }

    private function applyCustomReportConfig(array $config): void
    {
        if (isset($config['title'])) {
            $this->customReportTitle = trim((string) $config['title']);
        }
        if (isset($config['orientation']) && in_array($config['orientation'], ['portrait', 'landscape'], true)) {
            $this->customReportOrientation = $config['orientation'];
        }
        if (isset($config['paperSize']) && in_array($config['paperSize'], ['a4', 'short', 'long', 'legal'], true)) {
            $this->customReportPaperSize = $config['paperSize'];
        }
        if (array_key_exists('includeRevisions', $config)) {
            $this->customReportIncludeRevisions = (bool) $config['includeRevisions'];
        }
        if (isset($config['columns']) && is_array($config['columns'])) {
            $this->customReportColumns = $config['columns'];
        }
    }

    /**
     * Builds the exact headers, rows, and metadata shared by Preview, PDF, and Excel exports.
     *
     * @return array{error?: string, title?: string, generatedAt?: string, filterSummary?: string, headers?: list<string>, rows?: list<array{is_child: bool, cells: list<string>}>}
     */
    private function buildCustomReportPayload(?int $limitRows = null): array
    {
        $catalog = $this->customReportCatalog();
        $allHeaders = $catalog['headers'];
        $maxIdx = count($allHeaders) - 1;
        $selected = array_values(array_unique(array_filter(
            array_map('intval', $this->customReportColumns),
            fn (int $idx) => $idx >= 0 && $idx <= $maxIdx
        )));
        sort($selected);

        if ($selected === []) {
            return ['error' => 'Select at least one column to generate the report.'];
        }

        $list = $this->listing(paginate: false);
        if (! empty($list['error'])) {
            return ['error' => (string) $list['error']];
        }

        $headers = array_map(fn (int $idx) => $allHeaders[$idx], $selected);
        $rows = [];
        $totalRows = 0;
        $item = 1;
        foreach ($list['data'] ?? [] as $group) {
            $totalRows++;
            if ($limitRows === null || count($rows) < $limitRows) {
                $parentFull = $this->exportRow($group['parent'], (string) $item);
                $rows[] = [
                    'is_child' => false,
                    'cells' => array_map(fn (int $idx) => (string) ($parentFull[$idx] ?? ''), $selected),
                ];
            }
            if ($this->customReportIncludeRevisions) {
                foreach ($group['children'] ?? [] as $ci => $child) {
                    $totalRows++;
                    if ($limitRows === null || count($rows) < $limitRows) {
                        $childFull = $this->exportRow($child, $item . '.' . ($ci + 1));
                        $rows[] = [
                            'is_child' => true,
                            'cells' => array_map(fn (int $idx) => (string) ($childFull[$idx] ?? ''), $selected),
                        ];
                    }
                }
            }
            $item++;
        }

        $title = trim($this->customReportTitle) !== ''
            ? trim($this->customReportTitle)
            : 'DCS Document Inventory Report';

        $filterParts = [];
        if ($this->search !== '') {
            $filterParts[] = 'Search: "' . $this->search . '"';
        }
        if ($this->revisionStatus !== 'all') {
            $filterParts[] = 'Revision: ' . ucfirst($this->revisionStatus);
        }
        if ($this->status !== '') {
            $filterParts[] = 'Status: ' . ucfirst($this->status);
        }
        if ($this->originator !== '') {
            $filterParts[] = 'Originator: ' . $this->originator;
        }
        if ($this->dateFrom !== '' || $this->dateTo !== '') {
            $filterParts[] = 'Effectivity: ' . ($this->dateFrom ?: '…') . ' to ' . ($this->dateTo ?: '…');
        }

        return [
            'title' => $title,
            'generatedAt' => now()->format('M d, Y g:i A'),
            'filterSummary' => implode(' · ', $filterParts),
            'headers' => $headers,
            'rows' => $rows,
            'totalRows' => $totalRows,
        ];
    }

    public function setCustomReportPreset(string $preset): void
    {
        $this->customReportError = '';
        $catalog = $this->customReportCatalog();
        if ($preset === 'all') {
            $this->customReportColumns = array_keys($catalog['headers']);
        } elseif ($preset === 'none') {
            $this->customReportColumns = [];
        } else {
            $this->customReportColumns = [0, 2, 3, 4, 5, 6, 8, 10];
        }
    }

    public function toggleCustomReportGroup(string $groupName): void
    {
        $this->customReportError = '';
        $catalog = $this->customReportCatalog();
        $groupCols = $catalog['groups'][$groupName] ?? [];
        if ($groupCols === []) {
            return;
        }
        $current = array_values(array_unique(array_map('intval', $this->customReportColumns)));
        $missing = array_diff($groupCols, $current);
        $next = $missing === []
            ? array_values(array_diff($current, $groupCols))
            : array_values(array_unique(array_merge($current, $groupCols)));
        sort($next);
        $this->customReportColumns = $next;
    }

    #[Renderless]
    public function exportCustomReport(string $format, array $config = [])
    {
        RegisterQueryHelper::assertFullDcsUser('database');
        $format = strtolower(trim($format));
        if (! in_array($format, ['pdf', 'excel'], true)) {
            return;
        }

        if ($config !== []) {
            $this->applyCustomReportConfig($config);
        }

        $payload = $this->buildCustomReportPayload(null);
        if (! empty($payload['error'])) {
            return;
        }

        $title = $payload['title'];
        $generatedAt = $payload['generatedAt'];
        $filterSummary = $payload['filterSummary'];
        $headers = $payload['headers'];
        $rows = $payload['rows'];
        $slug = \Illuminate\Support\Str::slug($title) ?: 'dcs-custom-report';

        \App\Helpers\RegisterPersistHelper::logAdminChange(
            'Generated custom database report (' . strtoupper($format) . ') — '
            . count($rows) . ' row(s), ' . count($headers) . ' column(s)'
        );

        if ($format === 'excel') {
            $filename = $slug . '-' . now()->format('Y-m-d') . '.xls';
            return response()->streamDownload(function () use ($title, $generatedAt, $filterSummary, $headers, $rows) {
                echo "\xEF\xBB\xBF";
                echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
                echo '<head><meta charset="UTF-8"><style>';
                echo 'body{font-family:Arial,Calibri,sans-serif;font-size:10pt;}';
                echo 'table{border-collapse:collapse;width:100%;}';
                echo 'th{background:#8db3e2;color:#000000;font-weight:bold;border:1px solid #334155;padding:6px 8px;text-align:left;}';
                echo 'td{border:1px solid #94a3b8;padding:5px 8px;vertical-align:top;mso-number-format:"\@";}';
                echo 'tr.even td{background:#f8fafc;}';
                echo '.org{font-size:10pt;color:#000;border:none;text-align:center;}';
                echo '.org-bold{font-size:10pt;font-weight:bold;color:#000;border:none;text-align:center;}';
                echo '.title{font-size:13pt;font-weight:bold;color:#000;border:none;text-align:center;}';
                echo '.meta{font-size:9pt;color:#475569;border:none;text-align:center;}';
                echo '</style></head><body>';
                $colspan = max(1, count($headers));
                echo '<table>';
                echo '<tr><td class="org" colspan="' . $colspan . '">Republic of the Philippines</td></tr>';
                echo '<tr><td class="org-bold" colspan="' . $colspan . '">CAMARINES SUR POLYTECHNIC COLLEGES</td></tr>';
                echo '<tr><td class="org" colspan="' . $colspan . '">Nabua, Camarines Sur</td></tr>';
                echo '<tr><td style="border:none;border-top:2px solid #0071BC;" colspan="' . $colspan . '">&nbsp;</td></tr>';
                echo '<tr><td class="title" colspan="' . $colspan . '">' . e(mb_strtoupper($title)) . '</td></tr>';
                echo '<tr><td class="meta" colspan="' . $colspan . '">Generated ' . e($generatedAt) . ' · ' . count($rows) . ' record(s)' . ($filterSummary !== '' ? ' · ' . e($filterSummary) : '') . '</td></tr>';
                echo '<tr><td style="border:none;" colspan="' . $colspan . '">&nbsp;</td></tr>';
                echo '<thead><tr>';
                foreach ($headers as $h) {
                    echo '<th>' . e($h) . '</th>';
                }
                echo '</tr></thead><tbody>';
                foreach ($rows as $idx => $r) {
                    echo '<tr' . ($idx % 2 === 1 ? ' class="even"' : '') . '>';
                    foreach ($r['cells'] as $val) {
                        echo '<td>' . e($val !== '' ? $val : '—') . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table></body></html>';
            }, $filename, [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            ]);
        }

        // PDF export via DomPDF
        $logoPath = public_path('images/logo.png');
        $logoSrc = is_file($logoPath)
            ? ('data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath)))
            : '';

        $html = view('pages.dcs.database.custom-report-pdf', [
            'title' => $title,
            'generatedAt' => $generatedAt,
            'filterSummary' => $filterSummary,
            'headers' => $headers,
            'rows' => $rows,
            'logoSrc' => $logoSrc,
        ])->render();

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $orientation = $this->customReportOrientation === 'portrait' ? 'portrait' : 'landscape';
        $paper = match ($this->customReportPaperSize) {
            'short' => 'letter',
            'long' => [0, 0, 612, 936], // 8.5 x 13 in (Folio / Long bond)
            'legal' => 'legal',
            default => 'A4',
        };
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();
        $pdfBytes = $dompdf->output();
        $filename = $slug . '-' . now()->format('Y-m-d') . '.pdf';

        return response()->streamDownload(function () use ($pdfBytes) {
            echo $pdfBytes;
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Exports data.
     */
    public function export()
    {
        $list = $this->listing(paginate: false);
        if (! empty($list['error'])) {
            $this->notice = $list['error'];

            return;
        }

        $allHeaders = [
            'Item No.',
            'Category',
            'Document No.',
            'Rev.',
            'Document Title',
            'Effectivity Date',
            'Originator',
            'Pages',
            'Status',
            'PDF File',
            'Source Unit',
            'Related Docs',
            'Courses',
            'Approval No.',
            'Approval Date',
            'Deadline Date',
            'Day Diff',
            'ML Receipt Date',
            'ML Receipt Time',
            'ML Registered Date',
            'ML Registered Time',
            'Time Spent (mins)',
            'DCN No.',
            'DCN Date',
            'DCN Receipt Date',
            'DCN Receipt Time',
            'Purpose of Revision',
            'Scanned DCN',
            'DRF No.',
            'DRF Date',
            'DRF Receipt Date',
            'DRF Receipt Time',
            'Scanned DRF',
            'Dist. On File Date',
            'Dist. On File Time',
            'Dist. Actual Date',
            'Dist. Actual Time',
            'Receiving Office(s)',
            'Scanned Dist.',
            'Retrieved Office(s)',
        ];

        $mainIndexes = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 21];
        $headers = $this->exportAllColumns
            ? $allHeaders
            : array_values(array_intersect_key($allHeaders, array_flip($mainIndexes)));

        $filename = 'dcs-inventory-' . now()->format('Y-m-d') . '.csv';
        $full = $this->exportAllColumns;

        \App\Helpers\RegisterPersistHelper::logAdminChange(
            'Exported document database — ' . count($list['data'] ?? []) . ' group(s)'
            . ($full ? ' (all columns)' : ' (main columns)')
        );

        return response()->streamDownload(function () use ($list, $headers, $full, $mainIndexes) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            $item = 1;
            foreach ($list['data'] ?? [] as $group) {
                $parent = $this->exportRow($group['parent'], (string) $item);
                fputcsv($handle, $full ? $parent : array_values(array_intersect_key($parent, array_flip($mainIndexes))));
                foreach ($group['children'] ?? [] as $ci => $child) {
                    $row = $this->exportRow($child, $item . '.' . ($ci + 1));
                    fputcsv($handle, $full ? $row : array_values(array_intersect_key($row, array_flip($mainIndexes))));
                }
                $item++;
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Supplies view data, query collections, and metrics to the Blade template.
     * @return array
     */
    public function with(): array
    {
        $catalog = $this->catalog();

        $receivedByOfficeName = '';
        if ($this->receivedBy !== '') {
            $match = collect($catalog['distributionOffices'] ?? [])
                ->first(fn ($o) => (string) ($o->id ?? '') === (string) $this->receivedBy);
            if ($match) {
                $receivedByOfficeName = trim((string) ($match->office_name ?? ''));
            } else {
                $this->receivedBy = '';
            }
        }

        return array_merge($catalog, [
            'list' => $this->listing(),
            'visibleGroups' => DcsDatabaseColumns::systemVisibleGroups(),
            'groupColspans' => DcsDatabaseColumns::BUILTIN_COUNTS,
            'groupLabels' => DcsDatabaseColumns::GROUP_LABELS,
            'receivedByOfficeName' => $receivedByOfficeName,
            'customReportCatalog' => $this->customReportCatalog(),
        ]);
    }

    /**
     * Catalogs action.
     * @return array
     */
    private function catalog(): array
    {
        return Cache::remember('dcs.database.catalog.v1', 90, function () {
            return $this->loadCatalog();
        });
    }

    /**
     * Retrieves and populates catalog data.
     * @return array
     */
    private function loadCatalog(): array
    {
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $allOffices = \App\Helpers\RegisterQueryHelper::applySelectableOfficesFilter(
            DB::table($officeTable)->where('is_active', true)
        )->orderBy('office_name')->get();

        $distributionOffices = collect();
        if (Schema::hasTable('dcs_distribution_offices')) {
            $distOfficeIds = DB::table('dcs_distribution_offices')
                ->whereNotNull('office_id')
                ->distinct()
                ->pluck('office_id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->values()
                ->all();

            if ($distOfficeIds !== []) {
                $distributionOffices = \App\Helpers\RegisterQueryHelper::applySelectableOfficesFilter(
                    DB::table($officeTable)->where('is_active', true)->whereIn('id', $distOfficeIds)
                )->orderBy('office_name')->get();
            }
        }

        return [
            'docTypes' => DB::table('dcs_doc_types')->whereNull('parent_id')->get(),
            'subTypes' => DB::table('dcs_doc_types')->whereNotNull('parent_id')->orderBy('doc_type_name')->get(),
            'offices' => $allOffices,
            'distributionOffices' => $distributionOffices,
            'originators' => Schema::hasTable('dcs_originators')
                ? DB::table('dcs_originators')->orderBy('originator_name')->get()
                : collect(),
        ];
    }

    /**
     * Listings action.
     *
     * @param bool $paginate
     *
     * @return array
     */
    private function listing(bool $paginate = true): array
    {
        try {
            $query = DB::table('dcs_document_requests as dr');
            // Include soft-deleted rows so the inventory can show them with deleted styling.
            RegisterQueryHelper::applyRegisteredDocumentScope($query, 'dr');

            if ($this->docTypeId !== 'all' && $this->docTypeId !== '') {
                $query->where('dr.doc_type_id', $this->docTypeId);
            }

            if ($this->subTypeId !== 'all' && $this->subTypeId !== '') {
                $query->where('dr.sub_type_id', $this->subTypeId);
            }

            if ($this->search !== '') {
                $like = '%' . $this->search . '%';
                $search = $this->search;
                $tokens = RegisterQueryHelper::searchTokens($this->search);
                $query->where(function ($q) use ($like, $tokens, $search) {
                    $q->where(function ($phrase) use ($like, $search) {
                        $phrase->whereExists(function ($q2) use ($like) {
                            $q2->select(DB::raw(1))
                                ->from('dcs_document_request_form as drf')
                                ->whereColumn('drf.request_id', 'dr.id')
                                ->where(function ($q3) use ($like) {
                                    $q3->where('drf.doc_title', 'ilike', $like)
                                        ->orWhere('drf.drf_no', 'ilike', $like);
                                });
                        })
                        ->orWhereExists(function ($q2) use ($like, $search) {
                            $q2->select(DB::raw(1))
                                ->from('dcs_masterlist_registration as ml')
                                ->whereColumn('ml.request_id', 'dr.id')
                                ->where(function ($q3) use ($like, $search) {
                                    $q3->where('ml.doc_no', 'ilike', $like)
                                        ->orWhere('ml.doc_title', 'ilike', $like)
                                        ->orWhere(function ($name) use ($like) {
                                            RegisterQueryHelper::applyMasterlistOriginatorFilter($name, 'ml', $like, false, false);
                                        });
                                    RegisterQueryHelper::applyMasterlistKeywordSearch($q3, $search);
                                });
                        })
                        ->orWhereRaw('dr.id::text ilike ?', [$like]);
                    });

                    if (count($tokens) > 1) {
                        $q->orWhere(function ($all) use ($tokens) {
                            foreach ($tokens as $token) {
                                $tokenLike = '%' . $token . '%';
                                $all->where(function ($one) use ($tokenLike, $token) {
                                    $one->whereExists(function ($q2) use ($tokenLike, $token) {
                                        $q2->select(DB::raw(1))
                                            ->from('dcs_masterlist_registration as ml')
                                            ->whereColumn('ml.request_id', 'dr.id')
                                            ->where(function ($q3) use ($tokenLike, $token) {
                                                $q3->where('ml.doc_no', 'ilike', $tokenLike)
                                                    ->orWhere('ml.doc_title', 'ilike', $tokenLike)
                                                    ->orWhere(function ($name) use ($tokenLike) {
                                                        RegisterQueryHelper::applyMasterlistOriginatorFilter($name, 'ml', $tokenLike, false, false);
                                                    });
                                                RegisterQueryHelper::applyMasterlistKeywordSearch($q3, $token);
                                            });
                                    })->orWhereExists(function ($q2) use ($tokenLike) {
                                        $q2->select(DB::raw(1))
                                            ->from('dcs_document_request_form as drf')
                                            ->whereColumn('drf.request_id', 'dr.id')
                                            ->where(function ($q3) use ($tokenLike) {
                                                $q3->where('drf.doc_title', 'ilike', $tokenLike)
                                                    ->orWhere('drf.drf_no', 'ilike', $tokenLike);
                                            });
                                    });
                                });
                            }
                        });
                    }
                });
            }

            if ($this->originator !== '') {
                $originator = $this->originator;
                $query->whereExists(function ($q) use ($originator) {
                    $q->select(DB::raw(1))
                        ->from('dcs_masterlist_registration as ml')
                        ->whereColumn('ml.request_id', 'dr.id');
                    RegisterQueryHelper::applyMasterlistOriginatorFilter($q, 'ml', $originator, true);
                });
            }

            if ($this->sourceUnit !== '') {
                $officeId = $this->sourceUnit;
                $query->whereExists(function ($q) use ($officeId) {
                    $q->select(DB::raw(1))
                        ->from('dcs_masterlist_registration as ml')
                        ->join('dcs_masterlist_source_offices as so', 'so.masterlist_id', '=', 'ml.id')
                        ->whereColumn('ml.request_id', 'dr.id')
                        ->where('so.office_id', $officeId);
                });
            }

            if ($this->receivedBy !== '') {
                $officeId = (int) $this->receivedBy;
                if ($officeId > 0) {
                    // Match the same (latest) distribution record shown in the inventory row.
                    $query->whereExists(function ($q) use ($officeId) {
                        $q->select(DB::raw(1))
                            ->from('dcs_document_distribution as dist')
                            ->join('dcs_distribution_offices as dof', 'dof.distribution_id', '=', 'dist.id')
                            ->whereColumn('dist.request_id', 'dr.id')
                            ->where('dof.office_id', $officeId)
                            ->whereRaw(
                                'dist.id = (select max(d2.id) from dcs_document_distribution as d2 where d2.request_id = dist.request_id)'
                            );
                    });
                }
            }

            if ($this->status !== '') {
                $query->where('dr.approval_status', $this->status);
            }

            if ($this->dateFrom !== '') {
                $from = $this->dateFrom;
                $query->whereExists(function ($q) use ($from) {
                    $q->select(DB::raw(1))
                        ->from('dcs_masterlist_registration as ml')
                        ->whereColumn('ml.request_id', 'dr.id')
                        ->where('ml.effectivity_date', '>=', $from);
                });
            }

            if ($this->dateTo !== '') {
                $to = $this->dateTo;
                $query->whereExists(function ($q) use ($to) {
                    $q->select(DB::raw(1))
                        ->from('dcs_masterlist_registration as ml')
                        ->whereColumn('ml.request_id', 'dr.id')
                        ->where('ml.effectivity_date', '<=', $to);
                });
            }

            if ($this->revNo !== '') {
                $revNo = $this->revNo;
                $query->whereExists(function ($q) use ($revNo) {
                    $q->select(DB::raw(1))
                        ->from('dcs_masterlist_registration as ml')
                        ->whereColumn('ml.request_id', 'dr.id')
                        ->where('ml.revise_no', $revNo);
                });
            }

            $usedLight = $paginate;
            $allRows = $usedLight
                ? $this->lightInventoryRows($query, (int) $this->receivedBy)
                : RegisterQueryHelper::hydrateRequests($query->orderByDesc('dr.id')->get())
                    ->map(fn ($doc) => $this->mapDocument($doc));

            // Keep only documents whose displayed receiving offices include the selected office.
            if ($this->receivedBy !== '') {
                $officeId = (int) $this->receivedBy;
                $officeName = '';
                if ($officeId > 0) {
                    $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
                    $officeName = trim((string) (
                        DB::table($officeTable)->where('id', $officeId)->value('office_name') ?? ''
                    ));
                }
                $allRows = $allRows->filter(function (array $row) use ($officeId, $officeName) {
                    if ($officeId < 1) {
                        return false;
                    }
                    if (! in_array($officeId, $row['dist_office_ids'] ?? [], true)) {
                        return false;
                    }
                    // Also require the office name to appear in the displayed receiving-office list.
                    // Light rows have no names yet; the SQL filter already matched the latest distribution.
                    if ($officeName === '' || trim((string) ($row['dist_offices'] ?? '')) === '') {
                        return true;
                    }
                    $names = collect(explode(',', (string) ($row['dist_offices'] ?? '')))
                        ->map(fn ($n) => trim((string) $n))
                        ->filter();

                    return $names->contains(fn ($n) => strcasecmp($n, $officeName) === 0);
                })->values();
            }

            $grouped = $allRows->groupBy(fn ($row) => RegisterQueryHelper::inventoryStackKey($row));

            $groups = collect();
            foreach ($grouped as $rows) {
                $allowsRevision = (bool) ($rows->first()['allows_revision'] ?? true);
                $sorted = $allowsRevision
                    ? $rows->sortByDesc(function ($r) {
                        $status = strtolower(trim((string) ($r['status'] ?? $r['revision_status'] ?? 'latest')));
                        $latestRank = ($status === 'latest' || $status === '') ? 1 : 0;

                        return sprintf('%d-%010d-%010d', $latestRank, (int) ($r['rev_no'] ?? 0), (int) ($r['request_id'] ?? 0));
                    })->values()
                    : $rows->sortByDesc(fn ($r) => sprintf('%s|%010d', (string) ($r['effectivity_sort'] ?? ''), (int) ($r['request_id'] ?? 0)))->values();
                $parent = $allowsRevision
                    ? ($sorted->first(fn ($r) => in_array(strtolower(trim((string) ($r['status'] ?? $r['revision_status'] ?? ''))), ['', 'latest'], true)) ?? $sorted->first())
                    : $sorted->first();
                $children = $rows
                    ->filter(fn ($r) => $r['request_id'] !== $parent['request_id'])
                    ->sortByDesc($allowsRevision ? 'rev_no' : 'request_id')
                    ->values();

                $groups->push([
                    'doc_no' => $parent['doc_no'] ?? 'N/A',
                    'doc_type_id' => $parent['doc_type_id'] ?? 0,
                    'sub_type_id' => $parent['sub_type_id'] ?? 0,
                    'parent' => $parent,
                    'children' => $children->all(),
                    'has_revisions' => $children->isNotEmpty(),
                    'revision_count' => $rows->count(),
                    'obsolete_count' => $children->count(),
                    'allows_revision' => $allowsRevision,
                    'stack_label' => trim((string) ($parent['stack_group'] ?? '')) !== '' && ! $allowsRevision
                        ? 'related registrations'
                        : ($allowsRevision ? 'older revisions' : 'more registrations'),
                ]);
            }

            // An older obsolete copy with no stack stays under the family that shares its title.
            $groups = RegisterQueryHelper::foldOrphanStatusCopies($groups);

            // Stack renumbered families: CSPC-F-102 under CSPC-F-1023 when revised_from_doc_no links them.
            $groups = $this->mergeRenumberLineageGroups($groups);
            $groups = RegisterQueryHelper::splitUnrelatedUpdateChildren($groups);

            // After lineage merge, drop any parent/child that is not actually distributed to the office.
            if ($this->receivedBy !== '') {
                $officeId = (int) $this->receivedBy;
                $groups = $groups
                    ->map(function (array $g) use ($officeId) {
                        $parentIds = $g['parent']['dist_office_ids'] ?? [];
                        if ($officeId < 1 || ! in_array($officeId, $parentIds, true)) {
                            // Prefer a matching child as the visible tip when the merged tip does not match.
                            $match = collect($g['children'] ?? [])
                                ->first(fn ($c) => in_array($officeId, $c['dist_office_ids'] ?? [], true));
                            if (! $match) {
                                $g['_drop'] = true;

                                return $g;
                            }
                            $oldParent = $g['parent'];
                            $g['parent'] = $match;
                            $g['doc_no'] = $match['doc_no'] ?? ($g['doc_no'] ?? 'N/A');
                            $rest = collect($g['children'] ?? [])
                                ->filter(fn ($c) => (int) ($c['request_id'] ?? 0) !== (int) ($match['request_id'] ?? 0))
                                ->values();
                            if (in_array($officeId, $oldParent['dist_office_ids'] ?? [], true)) {
                                $rest->push($oldParent);
                            }
                            $g['children'] = $rest
                                ->filter(fn ($c) => in_array($officeId, $c['dist_office_ids'] ?? [], true))
                                ->values()
                                ->all();
                        } else {
                            $g['children'] = collect($g['children'] ?? [])
                                ->filter(fn ($c) => in_array($officeId, $c['dist_office_ids'] ?? [], true))
                                ->values()
                                ->all();
                        }
                        $g['has_revisions'] = ! empty($g['children']);
                        $g['obsolete_count'] = count($g['children']);
                        $g['revision_count'] = 1 + count($g['children']);

                        return $g;
                    })
                    ->reject(fn (array $g) => ! empty($g['_drop']))
                    ->values();
            }

            if ($this->revisionStatus === 'latest') {
                $groups = $groups
                    ->filter(fn ($g) => strtolower((string) ($g['parent']['status'] ?? '')) === 'latest')
                    ->map(function ($g) {
                        $allowsRevision = (bool) ($g['allows_revision'] ?? true);
                        if ($allowsRevision) {
                            // Keep renumbered prior doc nos as obsolete children; hide same-number prior revs.
                            $parentNo = strtolower(trim((string) ($g['doc_no'] ?? '')));
                            $g['children'] = collect($g['children'] ?? [])
                                ->filter(fn ($c) => strtolower(trim((string) ($c['doc_no'] ?? ''))) !== $parentNo)
                                ->values()
                                ->all();
                        }
                        // Non-revisable: keep same-doc_no sibling registrations under the tip.
                        $g['has_revisions'] = !empty($g['children']);
                        $g['obsolete_count'] = count($g['children']);
                        $g['revision_count'] = 1 + count($g['children']);

                        return $g;
                    })
                    ->values();
            } elseif (in_array($this->revisionStatus, ['superseded', 'obsolete'], true)) {
                $wanted = $this->revisionStatus === 'superseded' ? 'superseded' : 'obsolete';
                $groups = $groups
                    ->flatMap(function ($g) use ($wanted) {
                        return collect([$g['parent']])->merge($g['children'] ?? [])
                            ->filter(fn ($row) => strtolower((string) ($row['status'] ?? '')) === $wanted)
                            ->map(function ($row) use ($g) {
                                return [
                                    'doc_no' => $row['doc_no'] ?? ($g['doc_no'] ?? 'N/A'),
                                    'parent' => $row,
                                    'children' => [],
                                    'has_revisions' => false,
                                    'revision_count' => 1,
                                    'allows_revision' => $g['allows_revision'] ?? true,
                                ];
                            });
                    })
                    ->values();
            }

            $categoryOrder = [
                'internal' => 0,
                'internal forms' => 1,
                'external' => 2,
                'forms' => 3,
                'logbooks' => 4,
            ];

            $searchNeedle = trim($this->search);
            $groups = $groups->sort(function ($a, $b) use ($categoryOrder, $searchNeedle) {
                if ($searchNeedle !== '') {
                    $score = function ($group) use ($searchNeedle) {
                        $bits = [
                            $group['parent']['doc_no'] ?? '',
                            $group['parent']['title'] ?? '',
                            $group['parent']['originator'] ?? '',
                        ];
                        foreach ($group['children'] ?? [] as $child) {
                            $bits[] = ($child['doc_no'] ?? '') . ' ' . ($child['title'] ?? '');
                        }

                        return RegisterQueryHelper::looseSearchScore($searchNeedle, implode(' ', $bits));
                    };
                    $byScore = $score($b) <=> $score($a);
                    if ($byScore !== 0) {
                        return $byScore;
                    }
                }

                $catA = $a['parent']['doc_type_name'] ?? 'zzz';
                $catB = $b['parent']['doc_type_name'] ?? 'zzz';

                $rankA = $categoryOrder[strtolower($catA)] ?? PHP_INT_MAX;
                $rankB = $categoryOrder[strtolower($catB)] ?? PHP_INT_MAX;

                if ($rankA !== $rankB) {
                    return $rankA <=> $rankB;
                }

                $catCompare = strcmp($catA, $catB);
                if ($catCompare !== 0) {
                    return $catCompare;
                }

                // Keep related prefixes together (CSPC-LB-CCS-* then CSPC-LB-CTHBM-*),
                // then natural order within the series (incl. 05a / 05b / 05c).
                $docA = (string) ($a['parent']['doc_no'] ?? $a['doc_no'] ?? '');
                $docB = (string) ($b['parent']['doc_no'] ?? $b['doc_no'] ?? '');
                $seriesA = DocumentNumberSeriesHelper::prefixGroupKey($docA);
                $seriesB = DocumentNumberSeriesHelper::prefixGroupKey($docB);
                $bySeries = strnatcasecmp(
                    $seriesA !== '' ? $seriesA : $docA,
                    $seriesB !== '' ? $seriesB : $docB
                );
                if ($bySeries !== 0) {
                    return $bySeries;
                }
                $byDoc = strnatcasecmp($docA, $docB);
                if ($byDoc !== 0) {
                    return $byDoc;
                }

                return ($b['parent']['request_id'] ?? 0) <=> ($a['parent']['request_id'] ?? 0);
            })->values();

            // Sequential # restarts for each document type (Internal 1..n, External 1..n, …).
            // Related prefixes stay contiguous via sort above; no separator bands here.
            // Category badge counts skip deleted tips so recycle-bin rows don't inflate the total.
            $typeCounters = [];
            $typeSeqCounters = [];
            $groups = $groups->map(function (array $group) use (&$typeCounters, &$typeSeqCounters) {
                $catName = (string) ($group['parent']['doc_type_name'] ?? 'Uncategorized');
                $typeSeqCounters[$catName] = ($typeSeqCounters[$catName] ?? 0) + 1;
                $group['type_seq'] = $typeSeqCounters[$catName];
                $group['series_key'] = DocumentNumberSeriesHelper::prefixGroupKey(
                    (string) ($group['parent']['doc_no'] ?? $group['doc_no'] ?? '')
                );
                if (empty($group['parent']['is_deleted'])) {
                    $typeCounters[$catName] = ($typeCounters[$catName] ?? 0) + 1;
                }

                return $group;
            })->values();

            $total = $groups->count();
            $perPage = ($this->docTypeId === 'all' || $this->docTypeId === '') ? 40 : 20;
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min(max(1, $this->page), $lastPage);

            $data = $paginate
                ? $groups->slice(($page - 1) * $perPage, $perPage)->values()->all()
                : $groups->values()->all();

            if ($usedLight) {
                $data = $this->hydrateListingPage($data);
            }

            return [
                'data' => $data,
                'total' => $total,
                'page' => $paginate ? $page : 1,
                'per_page' => $paginate ? $perPage : $total,
                'last_page' => $paginate ? $lastPage : 1,
                'category_totals' => $typeCounters,
            ];
        } catch (\Throwable $e) {
            $refId = uniqid('err_');
            try {
                Log::error("Database listing error [{$refId}]: " . $e->getMessage());
            } catch (\Throwable) {
            }

            return [
                'data' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => 50,
                'category_totals' => [],
                'last_page' => 1,
                'error' => "An error occurred (ref: {$refId})",
            ];
        }
    }

    /**
     * Lights inventory rows.
     *
     * @param mixed $query
     * @param int $receivedOfficeId
     *
     * @return \Illuminate\Support\Collection
     */
    /**
     * Cached schema capability flags so information_schema isn't queried on every render.
     *
     * @return array{effectivity_date: bool, deleted_at: bool, allows_revision: bool, stack_group: bool, revised_from_doc_no: bool}
     */
    private function schemaFlags(): array
    {
        return Cache::remember('dcs.database.schema_flags.v1', 3600, static function (): array {
            return [
                'effectivity_date' => Schema::hasColumn('dcs_masterlist_registration', 'effectivity_date'),
                'deleted_at' => Schema::hasColumn('dcs_document_requests', 'deleted_at'),
                'allows_revision' => Schema::hasColumn('dcs_masterlist_registration', 'allows_revision'),
                'stack_group' => Schema::hasColumn('dcs_masterlist_registration', 'stack_group'),
                'revised_from_doc_no' => Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no'),
            ];
        });
    }

    private function lightInventoryRows($query, int $receivedOfficeId = 0): \Illuminate\Support\Collection
    {
        $flags = $this->schemaFlags();
        $hasEffectivity = $flags['effectivity_date'];
        $hasDeletedAt = $flags['deleted_at'];
        $hasAllows = $flags['allows_revision'];
        $hasStack = $flags['stack_group'];
        $hasRevisedFrom = $flags['revised_from_doc_no'];

        $select = [
            'dr.id as request_id',
            'dr.doc_type_id',
            'dr.sub_type_id',
            'dt.doc_type_name',
            'ml.doc_no',
            'ml.doc_title',
            'ml.revise_no',
            'ml.revision_status',
        ];
        if ($hasEffectivity) {
            $select[] = 'ml.effectivity_date';
        }
        if ($hasDeletedAt) {
            $select[] = 'dr.deleted_at';
        }
        if ($hasAllows) {
            $select[] = 'ml.allows_revision';
        }
        if ($hasStack) {
            $select[] = 'ml.stack_group';
        }
        if ($hasRevisedFrom) {
            $select[] = 'ml.revised_from_doc_no';
        }

        $mlColumns = ['doc_no', 'doc_title', 'revise_no', 'revision_status'];
        if ($hasEffectivity) {
            $mlColumns[] = 'effectivity_date';
        }
        if ($hasAllows) {
            $mlColumns[] = 'allows_revision';
        }
        if ($hasStack) {
            $mlColumns[] = 'stack_group';
        }
        if ($hasRevisedFrom) {
            $mlColumns[] = 'revised_from_doc_no';
        }
        $mlSql = implode(', ', $mlColumns);
        $docsQuery = clone $query;
        $docsQuery->leftJoin(DB::raw(
            "(SELECT DISTINCT ON (request_id) request_id, {$mlSql} FROM dcs_masterlist_registration ORDER BY request_id, id DESC) AS ml"
        ), 'ml.request_id', '=', 'dr.id');
        $docs = $docsQuery
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->orderByDesc('dr.id')
            ->get($select)
            ->unique('request_id')
            ->values();

        $syllabiIds = $docs
            ->filter(function ($doc) {
                $name = mb_strtolower((string) ($doc->doc_type_name ?? ''));

                return str_contains($name, 'syllab') || str_contains($name, 'rubric') || str_contains($name, 'tos');
            })
            ->pluck('request_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $stacks = RegisterQueryHelper::syllabiStackByRequest($syllabiIds);

        return $docs->map(function ($doc) use ($stacks, $hasAllows, $hasStack, $hasRevisedFrom, $receivedOfficeId) {
            $status = strtolower(trim((string) ($doc->revision_status ?? '')));
            if ($status === '') {
                $status = 'latest';
            }

            $effSort = '';
            $rawEff = $doc->effectivity_date ?? null;
            if (! empty($rawEff)) {
                $effStr = (string) $rawEff;
                if (strlen($effStr) >= 10 && $effStr[4] === '-' && $effStr[7] === '-') {
                    $effSort = substr($effStr, 0, 10);
                } else {
                    try {
                        $effSort = \Carbon\Carbon::parse($effStr)->format('Y-m-d');
                    } catch (\Throwable) {
                        $effSort = '';
                    }
                }
            }

            return [
                'request_id' => (int) $doc->request_id,
                'doc_type_id' => (int) ($doc->doc_type_id ?? 0),
                'sub_type_id' => (int) ($doc->sub_type_id ?? 0),
                'doc_type_name' => $doc->doc_type_name ?? 'Uncategorized',
                'doc_no' => trim((string) ($doc->doc_no ?? '')) !== '' ? trim((string) $doc->doc_no) : 'N/A',
                'title' => trim((string) ($doc->doc_title ?? '')),
                'effectivity_sort' => $effSort,
                'rev_no' => (int) ($doc->revise_no ?? 0),
                'status' => $status,
                'stack_group' => $hasStack ? trim((string) ($doc->stack_group ?? '')) : '',
                'revised_from_doc_no' => $hasRevisedFrom ? trim((string) ($doc->revised_from_doc_no ?? '')) : '',
                'allows_revision' => $hasAllows
                    ? (bool) ($doc->allows_revision ?? true)
                    : RegisterQueryHelper::effectiveTypeAllowsRevision($doc->doc_type_id ?? null, $doc->sub_type_id ?? null),
                'syllabi_stack' => $stacks[(int) $doc->request_id] ?? '',
                'is_deleted' => ! empty($doc->deleted_at ?? null),
                // SQL already limited rows to this receiving office, so later filters can keep them.
                'dist_office_ids' => $receivedOfficeId > 0 ? [$receivedOfficeId] : [],
            ];
        })->values();
    }

    /** @param  list<array<string, mixed>>  $groups */
    private function hydrateListingPage(array $groups): array
    {
        $ids = [];
        foreach ($groups as $group) {
            $ids[] = (int) ($group['parent']['request_id'] ?? 0);
            foreach ($group['children'] ?? [] as $child) {
                $ids[] = (int) ($child['request_id'] ?? 0);
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return $groups;
        }

        $full = RegisterQueryHelper::hydrateRequests(
            DB::table('dcs_document_requests')->whereIn('id', $ids)->get()
        )->mapWithKeys(fn ($doc) => [(int) $doc->id => $this->mapDocument($doc)]);

        return array_map(function (array $group) use ($full) {
            $pid = (int) ($group['parent']['request_id'] ?? 0);
            if (isset($full[$pid])) {
                $group['parent'] = $full[$pid];
            }
            $group['children'] = array_map(function ($child) use ($full) {
                $cid = (int) ($child['request_id'] ?? 0);

                return $full[$cid] ?? $child;
            }, $group['children'] ?? []);

            return $group;
        }, $groups);
    }

    /**
     * Maps document.
     *
     * @param object $doc
     *
     * @return array
     */
    private function mapDocument(object $doc): array
    {
        $hasSyllabi = $doc->syllabi->isNotEmpty();
        $firstSyl = $hasSyllabi ? $doc->syllabi->first() : null;
        $syllabiCourseType = trim((string) ($firstSyl?->course?->course_type ?? ''));
        $syllabiStack = '';
        if ($hasSyllabi && $firstSyl) {
            $syllabiStack = (int) ($firstSyl->college_id ?? 0)
                . '|' . (int) ($firstSyl->program_id ?? 0)
                . '|' . mb_strtolower($syllabiCourseType);
        }
        $drf = ($doc->documentRequestForm && ! $hasSyllabi) ? $doc->documentRequestForm : null;
        $syllabiCourses = $doc->syllabi->map(function ($syl) {
            $name = $syl->course->course_name ?? null;
            $code = $syl->course->course_code ?? null;
            if ($name && $code) {
                return $code . ' — ' . $name;
            }

            return $name ?: $code;
        })->filter()->values();

        $syllabiCourseDetails = $doc->syllabi->map(function ($syl) {
            $code = trim((string) ($syl->course->course_code ?? ''));
            $name = trim((string) ($syl->course->course_name ?? ''));
            if ($code === '' && $name === '') {
                return null;
            }
            $drfs = collect($syl->drfs ?? []);
            $originators = $drfs
                ->map(fn ($d) => trim((string) ($d->faculty_name ?? '')))
                ->filter()
                ->unique(fn ($n) => mb_strtolower($n))
                ->values()
                ->all();
            $drfAvailable = $drfs->contains(fn ($d) => ! empty($d->is_drf_available));
            $drfNos = $drfs
                ->map(fn ($d) => trim((string) ($d->drf_no ?? '')))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $drfDates = $drfs
                ->map(fn ($d) => ! empty($d->drf_date) ? RegisterQueryHelper::formatSmartDate($d->drf_date) : null)
                ->filter()
                ->unique()
                ->values()
                ->all();
            $drfReceivedDates = $drfs
                ->map(fn ($d) => ! empty($d->drf_received_date) ? RegisterQueryHelper::formatSmartDate($d->drf_received_date) : null)
                ->filter()
                ->unique()
                ->values()
                ->all();
            $drfScans = [];
            $seenScan = [];
            foreach ($drfs as $d) {
                $path = trim((string) ($d->scanned_drf ?? ''));
                if ($path === '' || isset($seenScan[$path])) {
                    continue;
                }
                $seenScan[$path] = true;
                $url = RegisterQueryHelper::scanUrl($path, false);
                if ($url) {
                    $drfScans[] = [
                        'url' => $url,
                        'name' => basename($path),
                    ];
                }
            }

            $pName = trim((string) ($syl->program_name ?? ''));
            $pCode = trim((string) ($syl->program_code ?? ''));
            $progLabel = $pName !== ''
                ? ($pCode !== '' ? "{$pName} ({$pCode})" : $pName)
                : ($pCode !== '' ? $pCode : 'General / Unassigned Program');

            return [
                'program_label' => $progLabel,
                'code' => $code,
                'name' => $name,
                'originators' => implode(', ', $originators),
                'drf_available' => $drfAvailable,
                'drf_no' => implode(', ', $drfNos),
                'drf_date' => implode(', ', $drfDates),
                'drf_received' => implode(', ', $drfReceivedDates),
                'drf_scan' => $drfScans[0]['url'] ?? null,
                'drf_scans' => $drfScans,
            ];
        })->filter()->values()->all();

        $syllabiCourseGroups = collect($syllabiCourseDetails)
            ->groupBy('program_label')
            ->sortKeys()
            ->map(fn ($items, $label) => [
                'program' => (string) $label,
                'courses' => $items->values()->all(),
            ])
            ->values()
            ->all();
        $ml = $doc->masterlistRegistration;
        $appr = $doc->approvalRecords?->first();
        $dcn = $doc->documentChangeNotice;
        $ret = $doc->documentRetrieval;
        $dist = $doc->documentDistribution;

        // Purpose of Revision = DCN Justification for this registration.
        // Fall back to the first non-empty Documents-for-Revision brief purpose.
        $dcnPurpose = null;
        if ($dcn) {
            $justification = trim((string) ($dcn->brief_purpose ?? ''));
            if ($justification !== '') {
                $dcnPurpose = $justification;
            } elseif ($dcn->revisions->isNotEmpty()) {
                $dcnPurpose = $dcn->revisions
                    ->sortBy('id')
                    ->map(fn ($rev) => trim((string) ($rev->brief_purpose ?? '')))
                    ->first(fn ($purpose) => $purpose !== '') ?: null;
            }
        }

        $retOffices = null;
        if ($ret && $ret->offices) {
            $retOffices = $ret->offices
                ->map(fn ($o) => $o->office?->office_name)
                ->filter()->implode(', ') ?: null;
        }

        $distOffices = null;
        $distOfficeIds = [];
        $distOfficeList = [];
        if ($dist && $dist->offices) {
            $distOfficeIds = $dist->offices
                ->map(fn ($o) => (int) ($o->office_id ?? 0))
                ->filter(fn ($id) => $id > 0)
                ->values()
                ->all();
            $distOfficeList = $dist->offices
                ->map(function ($o) {
                    $name = trim((string) ($o->office?->office_name ?? ''));
                    if ($name === '') {
                        return null;
                    }

                    return [
                        'name' => $name,
                        'copies' => $o->copies !== null && $o->copies !== '' ? (string) (int) $o->copies : '',
                    ];
                })
                ->filter()
                ->values()
                ->all();
            $distOffices = collect($distOfficeList)->pluck('name')->implode(', ') ?: null;
        }

        $sourceUnitName = null;
        if ($ml && $ml->sourceOffices->count() > 0) {
            $sourceUnitName = $ml->sourceOffices
                ->map(fn ($o) => $o->office?->office_name)
                ->filter()->implode(', ') ?: null;
        }

        $deadlineDiff = null;
        if ($ml && $ml->deadline && $ml->effectivity_date) {
            $deadlineDiff = Carbon::parse($ml->effectivity_date)->diffInDays(Carbon::parse($ml->deadline));
        }

        $isDeleted = ! empty($doc->deleted_at);
        $status = $isDeleted
            ? 'Deleted'
            : $this->revisionStatusLabel($ml?->revision_status ?? null);
        $allowsRevision = RegisterQueryHelper::supportsAllowsRevisionColumn()
            ? (bool) ($ml?->allows_revision ?? true)
            : RegisterQueryHelper::effectiveTypeAllowsRevision($doc->doc_type_id, $doc->sub_type_id);
        $docNo = $ml ? trim((string) $ml->doc_no) : '';
        $canEdit = ! $isDeleted;
        $canRevise = $canEdit
            && strtolower((string) $status) === 'latest';
        $reviseParams = [
            'type' => 'revised',
            'from_id' => $doc->id,
            'from_doc_no' => $docNo,
            'doc_type_id' => $doc->doc_type_id,
        ];
        $recurParams = $reviseParams;
        $recurParams['type'] = 'recurring';
        if (! empty($doc->sub_type_id)) {
            $reviseParams['sub_type_id'] = $doc->sub_type_id;
            $recurParams['sub_type_id'] = $doc->sub_type_id;
        }

        return [
            'request_id' => $doc->id,
            'doc_type_id' => $doc->doc_type_id,
            'sub_type_id' => $doc->sub_type_id,
            'doc_type_name' => $doc->docType->doc_type_name ?? 'Uncategorized',
            'doc_no' => $ml ? $ml->doc_no : 'N/A',
            'rev_no' => $ml ? (int) $ml->revise_no : 0,
            'title' => ($ml && $ml->doc_title) ? $ml->doc_title : (($drf && $drf->doc_title) ? $drf->doc_title : 'N/A'),
            'effectivity' => ($ml && $ml->effectivity_date) ? RegisterQueryHelper::formatSmartDate($ml->effectivity_date) : null,
            'originator' => $ml ? ($ml->originator_name ?? null) : null,
            'pages' => $ml?->no_pages,
            'status' => $status,
            'allows_revision' => $allowsRevision,
            'can_edit' => $canEdit,
            'can_revise' => $canRevise,
            'can_recur' => $canRevise,
            'edit_url' => $canEdit ? route('dcs.register.edit', $doc->id, false) : null,
            'revise_url' => $canRevise ? route('dcs.register.create', $reviseParams, false) : null,
            'recur_url' => $canRevise ? route('dcs.register.create', $recurParams, false) : null,
            'is_deleted' => $isDeleted,
            'deleted_at' => $isDeleted ? (RegisterQueryHelper::formatSmartDate($doc->deleted_at) . ' ' . Carbon::parse($doc->deleted_at)->format('h:i A')) : null,
            'revised_from_doc_no' => $ml->revised_from_doc_no ?? null,
            'pdf_path' => ($ml && $ml->scanned_masterlist) ? RegisterQueryHelper::scanUrl($ml->scanned_masterlist, false) : null,
            'source_unit' => $sourceUnitName,
            'related' => $ml ? ($ml->relatedList ?? collect())->map(fn ($r) => [
                'doc_no' => $r->doc_no,
                'title' => $r->doc_title,
            ])->values() : [],
            'syllabi_courses' => $syllabiCourses,
            'syllabi_course_details' => $syllabiCourseDetails,
            'syllabi_course_groups' => $syllabiCourseGroups,
            'syllabi_stack' => $syllabiStack,
            'approval_no' => $appr?->approval_no,
            'approval_date' => ($appr && $appr->approval_date) ? RegisterQueryHelper::formatSmartDate($appr->approval_date) : null,
            'deadline_date' => ($ml && $ml->deadline) ? RegisterQueryHelper::formatSmartDate($ml->deadline) : 'N/A',
            'deadline_diff' => $deadlineDiff !== null ? $deadlineDiff . ' days' : null,
            'ml_receipt_date' => ($ml && $ml->doc_receipt_date) ? RegisterQueryHelper::formatSmartDate($ml->doc_receipt_date) : null,
            'ml_receipt_time' => $ml && $ml->doc_receipt_time ? $this->formatTime($ml->doc_receipt_time) : null,
            'ml_register_date' => ($ml && $ml->doc_registered_date) ? RegisterQueryHelper::formatSmartDate($ml->doc_registered_date) : null,
            'ml_register_time' => $ml && $ml->doc_registered_time ? $this->formatTime($ml->doc_registered_time) : null,
            'ml_time_spent' => ($ml && $ml->time_spent !== null && $ml->time_spent !== '') ? (int) $ml->time_spent : null,
            'dcn_no' => $dcn?->dcn_no,
            'dcn_date' => ($dcn && $dcn->dcn_date) ? RegisterQueryHelper::formatSmartDate($dcn->dcn_date) : null,
            'dcn_receipt_date' => ($dcn && $dcn->dcn_receipt_date) ? RegisterQueryHelper::formatSmartDate($dcn->dcn_receipt_date) : null,
            'dcn_receipt_time' => $dcn && $dcn->dcn_receipt_time ? $this->formatTime($dcn->dcn_receipt_time) : null,
            'dcn_purpose' => $dcnPurpose,
            'dcn_scan' => ($dcn && $dcn->scanned_dcn) ? RegisterQueryHelper::scanUrl($dcn->scanned_dcn, false) : null,
            'drf_no' => $drf?->drf_no,
            'drf_date' => ($drf && $drf->drf_date) ? RegisterQueryHelper::formatSmartDate($drf->drf_date) : null,
            'drf_receipt_date' => ($drf && $drf->drf_receipt_date) ? RegisterQueryHelper::formatSmartDate($drf->drf_receipt_date) : null,
            'drf_receipt_time' => $drf && $drf->drf_receipt_time ? $this->formatTime($drf->drf_receipt_time) : null,
            'drf_scan' => ($drf && $drf->scanned_drf) ? RegisterQueryHelper::scanUrl($drf->scanned_drf, false) : null,
            'dist_onfile_date' => ($dist && $dist->doc_distribution_date_file) ? RegisterQueryHelper::formatSmartDate($dist->doc_distribution_date_file) : null,
            'dist_onfile_time' => $dist && $dist->doc_distribution_time_file ? $this->formatTime($dist->doc_distribution_time_file) : null,
            'dist_actual_date' => ($dist && $dist->doc_distribution_date_actual) ? RegisterQueryHelper::formatSmartDate($dist->doc_distribution_date_actual) : null,
            'dist_actual_time' => $dist && $dist->doc_distribution_time_actual ? $this->formatTime($dist->doc_distribution_time_actual) : null,
            'dist_offices' => $distOffices,
            'dist_office_ids' => $distOfficeIds,
            'dist_office_list' => $distOfficeList,
            'dist_print_date' => ($dist && $dist->doc_distribution_date_actual)
                ? \Carbon\Carbon::parse($dist->doc_distribution_date_actual)->toDateString()
                : (($dist && $dist->doc_distribution_date_file)
                    ? \Carbon\Carbon::parse($dist->doc_distribution_date_file)->toDateString()
                    : null),
            'effectivity_raw' => ($ml && $ml->effectivity_date)
                ? \Carbon\Carbon::parse($ml->effectivity_date)->toDateString()
                : null,
            'dist_scan' => ($dist && $dist->scanned_distribution) ? RegisterQueryHelper::scanUrl($dist->scanned_distribution, false) : null,
            'ret_offices' => $retOffices,
        ];
    }

    /**
     * Revisions status label.
     *
     * @param ?string $status
     *
     * @return string
     */
    private function revisionStatusLabel(?string $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'obsolete' => 'Obsolete',
            'superseded' => 'Superseded',
            'archived' => 'Archived',
            default => 'Latest',
        };
    }

    /**
     * Fold prior doc-no families into the current tip via revised_from_doc_no.
     * Each prior family attaches to at most one successor (highest rev wins).
     */
    private function mergeRenumberLineageGroups(\Illuminate\Support\Collection $groups): \Illuminate\Support\Collection
    {
        if ($groups->isEmpty()) {
            return $groups;
        }

        $standalone = $groups->filter(function ($g) {
            return empty($g['allows_revision'])
                || trim((string) ($g['parent']['syllabi_stack'] ?? '')) !== '';
        })->values();
        $groups = $groups->reject(function ($g) {
            return empty($g['allows_revision'])
                || trim((string) ($g['parent']['syllabi_stack'] ?? '')) !== '';
        })->values();
        if ($groups->isEmpty()) {
            return $standalone;
        }

        $indexed = $groups->values();
        $byKey = [];
        $groupTips = [];
        foreach ($indexed as $i => $g) {
            $key = $this->renumberGroupKey($g['doc_no'] ?? '', $g['doc_type_id'] ?? 0, $g['sub_type_id'] ?? 0);
            $byKey[$key] = $i;
            $parent = $g['parent'] ?? [];
            $rev = (int) ($parent['rev_no'] ?? 0);
            $rid = (int) ($parent['request_id'] ?? 0);
            $groupTips[$key] = [
                'doc_no' => (string) ($g['doc_no'] ?? ''),
                'doc_type_id' => (int) ($g['doc_type_id'] ?? 0),
                'sub_type_id' => (int) ($g['sub_type_id'] ?? 0),
                'rev_no' => $rev,
                'request_id' => $rid,
                'revised_from' => trim((string) ($parent['revised_from_doc_no'] ?? '')),
                'score' => ($rev * 1_000_000_000) + $rid,
                'is_active' => empty($parent['is_deleted']),
            ];
        }

        // revised_from is already on each row. Skip the per-family database walk;
        // that walk runs several queries for every revised document on each page load.
        $edges = RegisterQueryHelper::buildLineageMergeEdges($groupTips, []);
        $groupsByKey = [];
        foreach ($byKey as $key => $index) {
            $groupsByKey[$key] = $indexed[$index];
        }
        $edges = RegisterQueryHelper::dropLineageEdgesBetweenUnrelatedGroups($edges, $groupsByKey);
        if ($edges === []) {
            return $groups->concat($standalone)->values();
        }

        $absorbedAsTopLevel = [];
        foreach ($edges as $absorbedKeys) {
            foreach (array_keys($absorbedKeys) as $absorbedKey) {
                $absorbedAsTopLevel[$absorbedKey] = true;
            }
        }

        $globallyMergedFrom = [];
        $merged = collect();
        foreach ($indexed as $i => $g) {
            $key = $this->renumberGroupKey($g['doc_no'] ?? '', $g['doc_type_id'] ?? 0, $g['sub_type_id'] ?? 0);
            if (isset($absorbedAsTopLevel[$key])) {
                continue;
            }

            $children = collect($g['children'] ?? [])->all();
            $seen = [$key => true];
            $queue = array_keys($edges[$key] ?? []);

            while ($queue !== []) {
                $priorKey = array_shift($queue);
                if (isset($seen[$priorKey]) || ! isset($byKey[$priorKey])) {
                    continue;
                }
                if (isset($globallyMergedFrom[$priorKey])) {
                    continue;
                }
                $seen[$priorKey] = true;
                $globallyMergedFrom[$priorKey] = $key;

                $prior = $indexed[$byKey[$priorKey]];
                $priorParent = $prior['parent'];
                $priorParent['status'] = strtolower((string) ($priorParent['status'] ?? '')) === 'superseded'
                    ? 'Superseded'
                    : 'Obsolete';
                $children[] = $priorParent;

                foreach ($prior['children'] ?? [] as $child) {
                    $child['status'] = strtolower((string) ($child['status'] ?? '')) === 'superseded'
                        ? 'Superseded'
                        : 'Obsolete';
                    $children[] = $child;
                }

                foreach (array_keys($edges[$priorKey] ?? []) as $nextKey) {
                    $queue[] = $nextKey;
                }
            }

            usort($children, fn ($a, $b) => ((int) ($b['rev_no'] ?? 0)) <=> ((int) ($a['rev_no'] ?? 0)));

            $stack = array_merge([$g['parent']], $children);
            usort($stack, fn ($a, $b) => ((int) ($b['rev_no'] ?? 0)) <=> ((int) ($a['rev_no'] ?? 0))
                ?: ((int) ($b['request_id'] ?? 0)) <=> ((int) ($a['request_id'] ?? 0)));
            $tip = $stack[0];
            $stackAllowsRevision = (bool) ($tip['allows_revision'] ?? $g['allows_revision'] ?? true);
            foreach ($stack as &$member) {
                $isTip = (int) ($member['request_id'] ?? 0) === (int) ($tip['request_id'] ?? 0);
                if ($stackAllowsRevision) {
                    $kept = strtolower((string) ($member['status'] ?? ''));
                    if ($isTip && empty($member['is_deleted'])) {
                        $member['status'] = 'Latest';
                    } elseif (!$isTip && empty($member['is_deleted']) && $kept !== 'superseded') {
                        $member['status'] = 'Obsolete';
                    } elseif (!$isTip && $kept === 'superseded') {
                        $member['status'] = 'Superseded';
                    }
                }
                // Non-revisable: preserve DB status labels (typically all Latest).
            }
            unset($member);

            $g['parent'] = $tip;
            $g['doc_no'] = $tip['doc_no'] ?? ($g['doc_no'] ?? 'N/A');
            $g['children'] = array_values(array_filter(
                $stack,
                fn ($r) => (int) ($r['request_id'] ?? 0) !== (int) ($tip['request_id'] ?? 0)
            ));
            $g['has_revisions'] = ! empty($g['children']);
            $g['revision_count'] = 1 + count($g['children']);
            $g['obsolete_count'] = count($g['children']);
            $g['allows_revision'] = $stackAllowsRevision;
            $g['stack_label'] = $stackAllowsRevision ? 'older revisions' : 'more registrations';
            $merged->push($g);
        }

        return $merged->concat($standalone)->values();
    }

    /**
     * A renumber can join a prior number. It cannot join a different document
     * that only happens to use the same number.
     *
     * @param  array<string, array<string, true>>  $edges
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $indexed
     * @param  array<string, int>  $byKey
     * @return array<string, array<string, true>>
     */
    private function dropEdgesBetweenDifferentDocuments(array $edges, $indexed, array $byKey): array
    {
        foreach ($edges as $winner => $absorbed) {
            if (! isset($byKey[$winner])) {
                unset($edges[$winner]);
                continue;
            }
            foreach (array_keys($absorbed) as $absorbedKey) {
                if (! isset($byKey[$absorbedKey])
                    || ! RegisterQueryHelper::familyGroupsMatch($indexed[$byKey[$winner]], $indexed[$byKey[$absorbedKey]])) {
                    unset($edges[$winner][$absorbedKey]);
                }
            }
            if (($edges[$winner] ?? []) === []) {
                unset($edges[$winner]);
            }
        }

        return $edges;
    }

    /**
     * Renumbers group key.
     *
     * @param mixed $docNo
     * @param mixed $docTypeId
     * @param mixed $subTypeId
     *
     * @return string
     */
    private function renumberGroupKey(mixed $docNo, mixed $docTypeId, mixed $subTypeId): string
    {
        return strtolower(trim((string) $docNo)) . '||' . (int) $docTypeId . '||' . (int) $subTypeId;
    }

    /**
     * Exports and downloads row data.
     *
     * @param array $r
     * @param string $itemNo
     *
     * @return array
     */
    private function exportRow(array $r, string $itemNo): array
    {
        $related = collect($r['related'] ?? [])
            ->map(fn ($rel) => $rel['title'] ?? $rel['doc_no'] ?? null)
            ->filter()
            ->implode('; ');
        $courses = collect($r['syllabi_courses'] ?? [])->filter()->implode('; ');

        return [
            $itemNo,
            $r['doc_type_name'] ?? '',
            $r['doc_no'] ?? '',
            $r['rev_no'] ?? '',
            $r['title'] ?? '',
            $r['effectivity'] ?? '',
            $r['originator'] ?? '',
            $r['pages'] ?? '',
            $r['status'] ?? '',
            $this->exportFileUrl($r['pdf_path'] ?? null),
            $r['source_unit'] ?? '',
            $related,
            $courses,
            $r['approval_no'] ?? '',
            $r['approval_date'] ?? '',
            $r['deadline_date'] ?? '',
            $r['deadline_diff'] ?? '',
            $r['ml_receipt_date'] ?? '',
            $r['ml_receipt_time'] ?? '',
            $r['ml_register_date'] ?? '',
            $r['ml_register_time'] ?? '',
            $r['ml_time_spent'] ?? '',
            $r['dcn_no'] ?? '',
            $r['dcn_date'] ?? '',
            $r['dcn_receipt_date'] ?? '',
            $r['dcn_receipt_time'] ?? '',
            $r['dcn_purpose'] ?? '',
            $this->exportFileUrl($r['dcn_scan'] ?? null),
            $r['drf_no'] ?? '',
            $r['drf_date'] ?? '',
            $r['drf_receipt_date'] ?? '',
            $r['drf_receipt_time'] ?? '',
            $this->exportFileUrl($r['drf_scan'] ?? null),
            $r['dist_onfile_date'] ?? '',
            $r['dist_onfile_time'] ?? '',
            $r['dist_actual_date'] ?? '',
            $r['dist_actual_time'] ?? '',
            $r['dist_offices'] ?? '',
            $this->exportFileUrl($r['dist_scan'] ?? null),
            $r['ret_offices'] ?? '',
        ];
    }

    /**
     * Exports and downloads file url data.
     *
     * @param ?string $path
     *
     * @return string
     */
    private function exportFileUrl(?string $path): string
    {
        if (! $path) {
            return '';
        }

        return str_starts_with($path, 'http') ? $path : url($path);
    }

    /**
     * Formats and prepares time data.
     *
     * @param mixed $time
     *
     * @return ?string
     */
    private function formatTime($time): ?string
    {
        if (! $time) {
            return null;
        }

        try {
            return Carbon::parse($time)->format('h:i A');
        } catch (\Throwable $e) {
            return (string) $time;
        }
    }
}; ?>

@php
    $showCategoryRows = ($docTypeId === 'all' || $docTypeId === '');
    // Search/filter results should be visible immediately (don't leave matches collapsed).
    $forceCategoriesOpen = $showCategoryRows && (
        trim((string) ($search ?? '')) !== ''
        || trim((string) ($originator ?? '')) !== ''
        || trim((string) ($sourceUnit ?? '')) !== ''
        || trim((string) ($receivedBy ?? '')) !== ''
        || trim((string) ($status ?? '')) !== ''
        || (isset($revisionStatus) && $revisionStatus !== 'all' && $revisionStatus !== '')
        || trim((string) ($dateFrom ?? '')) !== ''
        || trim((string) ($dateTo ?? '')) !== ''
        || trim((string) ($revNo ?? '')) !== ''
        || (isset($subTypeId) && $subTypeId !== 'all' && $subTypeId !== '')
    );
    $categoryState = [];
    foreach (($list['data'] ?? []) as $group) {
        $name = $group['parent']['doc_type_name'] ?? 'Uncategorized';
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        // Typed tabs / active search-filter: show rows immediately.
        $categoryState[$slug] = ($showCategoryRows && ! $forceCategoriesOpen) ? false : true;
    }
    $visibleGroups = $visibleGroups ?? \App\Helpers\DcsDatabaseColumns::defaultVisibleGroups();
    $groupColspans = $groupColspans ?? \App\Helpers\DcsDatabaseColumns::BUILTIN_COUNTS;
    $tableColspan = 11
        + collect($groupColspans)->sum()
        + 7; // collapsed summary placeholders when counting is rough; use a high colspan for category rows
    $tableColspan = max(47, $tableColspan);
    $forceDistributionOpen = ($receivedByOfficeName ?? '') !== '';
    if ($forceDistributionOpen) {
        $visibleGroups['distribution'] = true;
    }
@endphp

<div
    data-dcs-db-root
    data-show-category-rows="{{ ! empty($showCategoryRows) ? '1' : '0' }}"
    data-force-categories-open="{{ ! empty($forceCategoriesOpen) ? '1' : '0' }}"
    data-category-state="{{ e(json_encode($categoryState)) }}"
    :data-open-approval="open.approval ? '1' : '0'"
    :data-open-deadline="open.deadline ? '1' : '0'"
    :data-open-masterlist="open.masterlist ? '1' : '0'"
    :data-open-dcn="open.dcn ? '1' : '0'"
    :data-open-drf="open.drf ? '1' : '0'"
    :data-open-distribution="open.distribution ? '1' : '0'"
    :data-open-retrieval="open.retrieval ? '1' : '0'"
    :data-vis-approval="visible.approval ? '1' : '0'"
    :data-vis-deadline="visible.deadline ? '1' : '0'"
    :data-vis-masterlist="visible.masterlist ? '1' : '0'"
    :data-vis-dcn="visible.dcn ? '1' : '0'"
    :data-vis-drf="visible.drf ? '1' : '0'"
    :data-vis-distribution="visible.distribution ? '1' : '0'"
    :data-vis-retrieval="visible.retrieval ? '1' : '0'"
    x-data="{
    filterOpen: false,
    notice: @js($notice),
    groupLabels: @js($groupLabels ?? []),
    groupMenu: { open: false, x: 0, y: 0, group: null },
    distTemplateUrl: @js(route('dcs.reports.distributionTemplate')),
    rowMenu: { open: false, x: 0, y: 0, editUrl: '', reviseUrl: '', recurUrl: '', canEdit: false, canRevise: false, canRecur: false, canPrintDist: false, label: '', title: '', effectivity: '', revisionNo: '', distDate: '', offices: [] },
    open: { approval: true, deadline: true, masterlist: true, dcn: true, drf: true, distribution: true, retrieval: true },
    visible: @js($visibleGroups),
    forceDistributionOpen: @js($forceDistributionOpen),
    categories: {{ json_encode($categoryState) }},
    expandedRevs: {},
    openCourses: {},
    collapsedCoursePrograms: {},
    openOffices: {},
    tableBusy: false,
    busyLabel: 'Loading documents…',
    busyHint: 'Fetching records and preparing the preview.',
    _stickySyncTimer: null,
    _busyTimer: null,
    _catServerSig: null,
    _needsLayoutSettle: true,
    init() {
        window.__dcsDbApi = this;
        try {
            const saved = JSON.parse(sessionStorage.getItem('dcs-db-expand') || '{}');
            // Do not restore category expand state — ALL always starts collapsed unless search/filter forces open.
            if (saved.expandedRevs && typeof saved.expandedRevs === 'object') {
                this.expandedRevs = saved.expandedRevs;
            }
            if (saved.openCourses && typeof saved.openCourses === 'object') {
                this.openCourses = saved.openCourses;
            }
            if (saved.openOffices && typeof saved.openOffices === 'object') {
                this.openOffices = saved.openOffices;
            }
            if (saved.open && typeof saved.open === 'object') {
                this.open = Object.assign({}, this.open, saved.open);
            }
        } catch (e) {}
        if (this.forceDistributionOpen) {
            this.visible.distribution = true;
            this.open.distribution = true;
        }
        this.$watch('notice', (v) => { if (v) setTimeout(() => { this.notice = ''; }, 4000); });
        this.$watch('open', () => {
            this.paintOpenDom();
            this.queueStickySync();
        }, { deep: true });
        this.$watch('visible', () => {
            this.paintVisibilityDom();
            this.queueStickySync();
        }, { deep: true });
        // Imperative data-open/data-vis — Alpine :attr on this root can go stale after morph / category expand.
        this.paintOpenDom();
        this.paintVisibilityDom();
        // Play the shared page-enter once, then lock so in-page morphs stay still.
        this.markPageEnterSettled();
        // After Livewire type/search/filter morphs — only reset categories when server attrs change.
        this._onDbResync = () => {
            this.syncCategoriesFromDom();
            this.paintOpenDom();
            this.paintVisibilityDom();
            if (this._needsLayoutSettle) {
                this._needsLayoutSettle = false;
                this.settleTableLayout();
            } else {
                this.applyCategoryVisibility();
                this.queueStickySync();
            }
        };
        this._onDbNavigated = () => {
            this.markPageEnterSettled();
            this._onDbResync();
        };
        this._onDbMorphResync = () => {
            // In-page morph: keep settle lock so fadeInUp does not re-fire.
            document.querySelectorAll('.db-page').forEach((el) => el.classList.add('db-page-settled'));
            this._onDbResync();
        };
        document.addEventListener('livewire:navigated', this._onDbNavigated);
        window.addEventListener('dcs-db-resync-sticky', this._onDbMorphResync);
        this.syncCategoriesFromDom();
        // Capture-phase: always resolve the live Alpine component from the DOM root.
        // Works for ALL / typed tabs / search / filter because rows carry data-row-actions-b64.
        this._onDbContextMenu = (e) => {
            const root = document.querySelector('[data-dcs-db-root]');
            if (!root || !root.contains(e.target)) return;
            // Ignore clicks inside the menus themselves.
            if (e.target?.closest?.('#dcs-db-row-menu, #dcs-db-group-menu')) return;
            let api = null;
            try {
                api = (window.Alpine && typeof Alpine.$data === 'function') ? Alpine.$data(root) : null;
            } catch (err) {}
            if (!api || typeof api.openRowMenuFromEl !== 'function') {
                api = window.__dcsDbApi;
            }
            if (!api || typeof api.openRowMenuFromEl !== 'function') return;
            window.__dcsDbApi = api;
            const row = e.target?.closest?.('tr[data-row-actions-b64], tr[data-row-actions]');
            if (row && root.contains(row)) {
                e.preventDefault();
                e.stopPropagation();
                api.openRowMenuFromEl(row, e.clientX, e.clientY);
                return;
            }
            // Collapsed: .db-group-header[data-group]. Expanded primary: same + data-group.
            // Expanded sub-headers (2nd/3rd row): col-group-* only — resolve group from class or data-group.
            const th = e.target?.closest?.('th[data-group], th.db-group-header, th.col-group-expanded');
            if (th && root.contains(th) && typeof api.openGroupMenu === 'function') {
                let group = th.getAttribute('data-group');
                if (!group) {
                    const m = String(th.className || '').match(/\bcol-group-(approval|deadline|masterlist|dcn|drf|distribution|retrieval)\b/);
                    group = m ? m[1] : null;
                }
                if (!group) return;
                e.preventDefault();
                e.stopPropagation();
                api.openGroupMenu({
                    clientX: e.clientX,
                    clientY: e.clientY,
                    currentTarget: th,
                    target: e.target,
                }, group);
            }
        };
        if (window.__dcsDbContextMenuHandler) {
            document.removeEventListener('contextmenu', window.__dcsDbContextMenuHandler, true);
        }
        window.__dcsDbContextMenuHandler = this._onDbContextMenu;
        document.addEventListener('contextmenu', this._onDbContextMenu, true);
        // Close menus on outside click even if Alpine @mousedown.outside is stale after morph.
        this._onDbMenuOutside = (e) => {
            if (this._suppressOutsideClick) return;
            let api = null;
            try {
                const root = document.querySelector('[data-dcs-db-root]');
                api = (window.Alpine && typeof Alpine.$data === 'function' && root) ? Alpine.$data(root) : null;
            } catch (err) {}
            api = api || window.__dcsDbApi || this;
            const rowMenu = document.getElementById('dcs-db-row-menu');
            const groupMenu = document.getElementById('dcs-db-group-menu');
            if (rowMenu && rowMenu.style.display === 'block' && !rowMenu.contains(e.target)) {
                api.rowMenu && (api.rowMenu.open = false);
                if (typeof api.paintRowMenuDom === 'function') api.paintRowMenuDom();
            }
            if (groupMenu && groupMenu.style.display === 'block' && !groupMenu.contains(e.target)) {
                if (typeof api.closeGroupMenu === 'function') api.closeGroupMenu();
            }
        };
        if (window.__dcsDbMenuOutsideHandler) {
            document.removeEventListener('mousedown', window.__dcsDbMenuOutsideHandler, true);
        }
        window.__dcsDbMenuOutsideHandler = this._onDbMenuOutside;
        document.addEventListener('mousedown', this._onDbMenuOutside, true);
        // Menu buttons cannot rely on Alpine @click after Livewire morph — bind once via DOM.
        this.bindMenuActionClicks();
        this._onResizeSticky = () => this.queueStickySync();
        window.addEventListener('resize', this._onResizeSticky);

        if (!window.__dcsDbStickyHooksBound) {
            window.__dcsDbStickyHooksBound = true;
            const ping = () => window.dispatchEvent(new CustomEvent('dcs-db-resync-sticky'));
            if (window.Livewire && typeof Livewire.hook === 'function') {
                Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => ping());
                });
                Livewire.hook('morph.updated', () => ping());
            }
        }

        this.settleTableLayout();
    },
    destroy() {
        if (this._stickySyncTimer) {
            clearTimeout(this._stickySyncTimer);
            this._stickySyncTimer = null;
        }
        if (this._busyTimer) {
            clearTimeout(this._busyTimer);
            this._busyTimer = null;
        }
        if (this._pageEnterTimer) {
            clearTimeout(this._pageEnterTimer);
            this._pageEnterTimer = null;
        }
        if (this._onResizeSticky) window.removeEventListener('resize', this._onResizeSticky);
        if (this._onDbNavigated) {
            document.removeEventListener('livewire:navigated', this._onDbNavigated);
        }
        if (this._onDbMorphResync) {
            window.removeEventListener('dcs-db-resync-sticky', this._onDbMorphResync);
        }
        if (this._onDbContextMenu) {
            document.removeEventListener('contextmenu', this._onDbContextMenu, true);
            if (window.__dcsDbContextMenuHandler === this._onDbContextMenu) {
                window.__dcsDbContextMenuHandler = null;
            }
        }
        if (this._onDbMenuOutside) {
            document.removeEventListener('mousedown', this._onDbMenuOutside, true);
            if (window.__dcsDbMenuOutsideHandler === this._onDbMenuOutside) {
                window.__dcsDbMenuOutsideHandler = null;
            }
        }
        if (this._onDbMenuClick) {
            document.removeEventListener('click', this._onDbMenuClick, true);
            if (window.__dcsDbMenuClickHandler === this._onDbMenuClick) {
                window.__dcsDbMenuClickHandler = null;
            }
        }
    },
    resolveDbApi() {
        const root = document.querySelector('[data-dcs-db-root]');
        let api = null;
        try {
            api = (window.Alpine && typeof Alpine.$data === 'function' && root) ? Alpine.$data(root) : null;
        } catch (err) {}
        if (!api || typeof api.hideGroupFromMenu !== 'function') {
            api = window.__dcsDbApi;
        }
        if (api) {
            window.__dcsDbApi = api;
        }
        return api;
    },
    paintOpenDom() {
        const root = this.dbRoot();
        if (!root || !this.open) return;
        Object.keys(this.open).forEach((g) => {
            root.setAttribute('data-open-' + g, this.open[g] ? '1' : '0');
        });
    },
    paintVisibilityDom() {
        const root = this.dbRoot();
        if (!root || !this.visible) return;
        Object.keys(this.visible).forEach((g) => {
            root.setAttribute('data-vis-' + g, this.visible[g] ? '1' : '0');
        });
    },
    bindMenuActionClicks() {
        this._onDbMenuClick = (e) => {
            // Prefer live Alpine scope — stale __dcsDbApi after category expand left hide no-ops.
            let api = null;
            try {
                const root = document.querySelector('[data-dcs-db-root]');
                api = (window.Alpine && typeof Alpine.$data === 'function' && root) ? Alpine.$data(root) : null;
            } catch (err) {}
            if (!api || typeof api.hideGroupFromMenu !== 'function') {
                api = window.__dcsDbApi;
            }
            if (!api) return;
            window.__dcsDbApi = api;
            const rowMenu = document.getElementById('dcs-db-row-menu');
            const groupMenu = document.getElementById('dcs-db-group-menu');
            if (rowMenu && rowMenu.style.display !== 'none' && rowMenu.contains(e.target)) {
                const btn = e.target.closest?.('button[data-menu-edit], button[data-menu-revise], button[data-menu-recur], button[data-menu-print]');
                if (!btn || btn.style.display === 'none') return;
                e.preventDefault();
                e.stopPropagation();
                if (btn.hasAttribute('data-menu-edit')) {
                    api.goRowAction(api.rowMenu?.editUrl || '');
                    return;
                }
                if (btn.hasAttribute('data-menu-revise')) {
                    api.goRowAction(api.rowMenu?.reviseUrl || '');
                    return;
                }
                if (btn.hasAttribute('data-menu-recur')) {
                    api.goRowAction(api.rowMenu?.recurUrl || '');
                    return;
                }
                if (btn.hasAttribute('data-menu-print') && typeof api.printDistributionFromRow === 'function') {
                    api.printDistributionFromRow();
                }
                return;
            }
            if (groupMenu && groupMenu.style.display !== 'none' && groupMenu.contains(e.target)) {
                const btn = e.target.closest?.('button[data-menu-hide-group]');
                if (!btn) return;
                e.preventDefault();
                e.stopPropagation();
                if (typeof api.hideGroupFromMenu === 'function') {
                    api.hideGroupFromMenu();
                }
            }
        };
        if (window.__dcsDbMenuClickHandler) {
            document.removeEventListener('click', window.__dcsDbMenuClickHandler, true);
        }
        window.__dcsDbMenuClickHandler = this._onDbMenuClick;
        document.addEventListener('click', this._onDbMenuClick, true);
    },
    dbRoot() {
        return document.querySelector('[data-dcs-db-root]') || this.$el;
    },
    categoryServerSignature() {
        const root = this.dbRoot();
        if (!root) return '';
        return [
            root.getAttribute('data-show-category-rows') || '0',
            root.getAttribute('data-force-categories-open') || '0',
            root.getAttribute('data-category-state') || '{}',
        ].join('||');
    },
    markPageEnterSettled() {
        const pages = () => Array.from(document.querySelectorAll('.db-page'));
        const lock = () => pages().forEach((el) => el.classList.add('db-page-settled'));
        pages().forEach((el) => {
            el.classList.remove('db-page-settled');
            const onEnd = (e) => {
                if (e && e.target !== el) return;
                el.removeEventListener('animationend', onEnd);
                el.classList.add('db-page-settled');
            };
            el.addEventListener('animationend', onEnd);
        });
        // Fallback if animation is disabled (reduced motion) or already finished.
        clearTimeout(this._pageEnterTimer);
        this._pageEnterTimer = setTimeout(lock, 420);
    },
    syncCategoriesFromDom() {
        const root = this.dbRoot();
        if (!root) return;
        const signature = this.categoryServerSignature();
        const serverChanged = signature !== this._catServerSig;
        if (serverChanged) {
            this._catServerSig = signature;
            this._needsLayoutSettle = true;
            let next = {};
            try {
                next = JSON.parse(root.getAttribute('data-category-state') || '{}') || {};
            } catch (e) {
                next = {};
            }
            const forced = root.getAttribute('data-force-categories-open') === '1';
            const showCats = root.getAttribute('data-show-category-rows') === '1';
            if (forced) {
                Object.keys(next).forEach((k) => { next[k] = true; });
            } else if (showCats) {
                // ALL tab without search/filter: start collapsed after type/filter switch.
                Object.keys(next).forEach((k) => { next[k] = false; });
            } else {
                Object.keys(next).forEach((k) => { next[k] = true; });
            }
            this.categories = next;
        }
        // Always re-apply — Livewire morph replaces <tr> nodes and drops inline display state.
        this.applyCategoryVisibility();
    },
    applyCategoryVisibility() {
        const root = this.dbRoot();
        if (!root) return;
        const showCats = root.getAttribute('data-show-category-rows') === '1';
        const forced = root.getAttribute('data-force-categories-open') === '1';
        // Typed tabs: Livewire morph can reuse ALL-tab <tr> nodes that still have
        // display:none / db-row-collapsed from collapsed categories — clear them.
        if (!showCats) {
            root.querySelectorAll('tbody tr').forEach((tr) => {
                const revKey = tr.getAttribute('data-rev-key') || '';
                const courseReq = tr.getAttribute('data-course-req') || '';
                const courseProg = tr.getAttribute('data-course-prog') || '';
                const revOpen = !revKey || !!this.expandedRevs[revKey];
                const courseOpen = !courseReq || !!this.openCourses[courseReq];
                const progOpen = !courseProg || !this.collapsedCoursePrograms[courseProg];
                const open = revOpen && courseOpen && progOpen;
                tr.classList.toggle('db-row-collapsed', !open);
                tr.hidden = !open;
                if (open) {
                    tr.style.removeProperty('display');
                } else {
                    tr.style.display = 'none';
                }
            });
            return;
        }
        root.querySelectorAll('tr[data-cat]').forEach((tr) => {
            const slug = tr.getAttribute('data-cat') || '';
            const revKey = tr.getAttribute('data-rev-key') || '';
            const courseReq = tr.getAttribute('data-course-req') || '';
            const courseProg = tr.getAttribute('data-course-prog') || '';
            const catOpen = forced || !!this.categories[slug];
            const revOpen = !revKey || !!this.expandedRevs[revKey];
            const courseOpen = !courseReq || !!this.openCourses[courseReq];
            const progOpen = !courseProg || !this.collapsedCoursePrograms[courseProg];
            const open = catOpen && revOpen && courseOpen && progOpen;
            tr.classList.toggle('db-row-collapsed', !open);
            tr.classList.toggle('db-cat-open', open);
            tr.hidden = !open;
            if (open) {
                tr.style.removeProperty('display');
            } else {
                tr.style.display = 'none';
            }
        });
    },
    settleTableLayout() {
        // Measure sticky headers without hiding the table (hiding caused a blank gap on type switch).
        this.syncStickyHeaders();
        this.applyCategoryVisibility();
        this.$nextTick(() => {
            requestAnimationFrame(() => {
                this.syncStickyHeaders();
                this.applyCategoryVisibility();
            });
        });
    },
    queueStickySync() {
        if (this._stickySyncTimer) {
            clearTimeout(this._stickySyncTimer);
        }
        this.$nextTick(() => {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    this.syncStickyHeaders();
                    // One more pass after fonts/loading overlay settle.
                    this._stickySyncTimer = setTimeout(() => this.syncStickyHeaders(), 50);
                });
            });
        });
    },
    syncStickyHeaders() {
        const scroll = this.dbRoot()?.querySelector?.('.db-table-scroll');
        if (!scroll) return;
        const table = scroll.querySelector('.db-table');
        const thead = scroll.querySelector('thead');
        if (!table || !thead) return;

        // Livewire morph can leave duplicate JS-injected frames.
        const frames = scroll.querySelectorAll('.db-thead-frame');
        frames.forEach((el, i) => { if (i > 0) el.remove(); });

        const row1 = thead.querySelector('.db-head-primary');
        const row2 = thead.querySelector('.db-head-secondary');
        const row3 = thead.querySelector('.db-head-tertiary');

        // Use offsetTop bands — getBoundingClientRect on rowspan rows reports the full span height.
        const y1 = row1 ? row1.offsetTop : 0;
        const y2 = row2 ? row2.offsetTop : y1;
        const y3 = row3 ? row3.offsetTop : y2;
        const totalH = Math.max(1, Math.ceil(thead.offsetHeight || 0));
        let h1 = Math.ceil(y2 - y1);
        let h2 = Math.ceil(y3 - y2);
        if (h1 < 1) h1 = Math.ceil(this.headerBandHeight(row1) || 36);
        if (h2 < 1) h2 = Math.ceil(this.headerBandHeight(row2) || 32);

        scroll.style.setProperty('--header-row1-h', h1 + 'px');
        scroll.style.setProperty('--header-row2-top', (h1 + h2) + 'px');
        scroll.style.setProperty('--header-total-h', totalH + 'px');
        scroll.style.setProperty('--header-total-w', Math.ceil(table.scrollWidth || scroll.clientWidth) + 'px');

        let frame = scroll.querySelector('.db-thead-frame');
        if (!frame) {
            frame = document.createElement('div');
            frame.className = 'db-thead-frame';
            frame.setAttribute('aria-hidden', 'true');
            scroll.insertBefore(frame, scroll.firstChild);
        }
    },
    headerBandHeight(tr) {
        if (!tr) return 0;
        let max = 0;
        let saw = false;
        tr.querySelectorAll('th').forEach((th) => {
            if (getComputedStyle(th).display === 'none') return;
            const rs = parseInt(th.getAttribute('rowspan') || '1', 10);
            if (rs > 1) return;
            saw = true;
            max = Math.max(max, th.offsetHeight || 0);
        });
        if (saw) return max;
        return tr.offsetHeight || 0;
    },
    persistExpand() {
        try {
            sessionStorage.setItem('dcs-db-expand', JSON.stringify({
                categories: this.categories,
                expandedRevs: this.expandedRevs,
                openCourses: this.openCourses,
                openOffices: this.openOffices,
                open: this.open,
            }));
        } catch (e) {}
    },
    persistVisible() {
        try {
            const wire = this.$wire;
            if (wire && typeof wire.saveVisibleGroups === 'function') {
                wire.saveVisibleGroups(this.visible);
                return;
            }
        } catch (err) {}
        try {
            $wire.saveVisibleGroups(this.visible);
        } catch (err2) {}
    },
    get allCollapsed() {
        return Object.values(this.open).every(v => !v);
    },
    toggle(g) {
        this.open = Object.assign({}, this.open, { [g]: !this.open[g] });
        this.paintOpenDom();
        this.persistExpand();
    },
    openGroupMenu(e, group) {
        if (!this.visible[group]) {
            return;
        }
        // Anchor near the group header label (left edge of the th), not the far click point on wide colspan cells.
        const th = e.currentTarget || e.target;
        const rect = th && th.getBoundingClientRect ? th.getBoundingClientRect() : null;
        let x = rect ? rect.left + 8 : e.clientX;
        let y = rect ? rect.bottom + 4 : e.clientY;
        const menuW = 280;
        const menuH = 96;
        x = Math.max(8, Math.min(x, window.innerWidth - menuW - 8));
        y = Math.max(8, Math.min(y, window.innerHeight - menuH - 8));
        this._suppressOutsideClick = true;
        clearTimeout(this._suppressOutsideTimer);
        this._suppressOutsideTimer = setTimeout(() => { this._suppressOutsideClick = false; }, 400);
        this.groupMenu.open = true;
        this.groupMenu.x = x;
        this.groupMenu.y = y;
        this.groupMenu.group = group;
        this.rowMenu.open = false;
        this.paintRowMenuDom();
        this.paintGroupMenuDom();
    },
    closeGroupMenu() {
        this.groupMenu.open = false;
        this.groupMenu.group = null;
        this.paintGroupMenuDom();
    },
    parseRowActions(el) {
        if (!el) return null;
        const b64 = el.getAttribute('data-row-actions-b64') || '';
        if (b64) {
            try {
                // UTF-8 safe decode (titles / office names can be non-ASCII).
                const bin = atob(b64);
                const bytes = Uint8Array.from(bin, (c) => c.charCodeAt(0));
                return JSON.parse(new TextDecoder().decode(bytes));
            } catch (err) {
                try {
                    return JSON.parse(decodeURIComponent(escape(atob(b64))));
                } catch (err2) {}
            }
        }
        const raw = el.getAttribute('data-row-actions') || '';
        if (!raw) return null;
        try {
            return JSON.parse(raw);
        } catch (err) {
            return null;
        }
    },
    paintRowMenuDom() {
        const menu = document.getElementById('dcs-db-row-menu');
        if (!menu) return;
        menu.removeAttribute('x-cloak');
        menu.style.display = this.rowMenu.open ? 'block' : 'none';
        menu.style.left = (this.rowMenu.x || 0) + 'px';
        menu.style.top = (this.rowMenu.y || 0) + 'px';
        const label = menu.querySelector('[data-menu-label]');
        if (label) {
            label.style.display = this.rowMenu.label ? 'block' : 'none';
            label.textContent = this.rowMenu.label || '';
        }
        const setBtn = (sel, show) => {
            const btn = menu.querySelector(sel);
            if (btn) btn.style.display = show ? 'block' : 'none';
        };
        setBtn('[data-menu-edit]', !!this.rowMenu.canEdit);
        setBtn('[data-menu-revise]', !!this.rowMenu.canRevise);
        setBtn('[data-menu-recur]', !!this.rowMenu.canRecur);
        setBtn('[data-menu-print]', !!this.rowMenu.canPrintDist);
    },
    paintGroupMenuDom() {
        const menu = document.getElementById('dcs-db-group-menu');
        if (!menu) return;
        menu.removeAttribute('x-cloak');
        menu.style.display = this.groupMenu.open ? 'block' : 'none';
        menu.style.left = (this.groupMenu.x || 0) + 'px';
        menu.style.top = (this.groupMenu.y || 0) + 'px';
        const name = menu.querySelector('[data-menu-group-name]');
        if (name) {
            const g = this.groupMenu.group;
            name.textContent = (this.groupLabels && this.groupLabels[g]) ? this.groupLabels[g] : (g || '');
        }
    },
    openRowMenuFromEl(el, clientX, clientY) {
        const row = this.parseRowActions(el);
        if (!row || typeof row !== 'object') {
            return;
        }
        // Show menu whenever row payload exists (typed tabs / search / filter).
        const canEdit = !!row.canEdit;
        const canRevise = !!row.canRevise;
        const canRecur = !!row.canRecur;
        const canPrintDist = row.canPrintDist !== false;
        this.groupMenu.open = false;
        this.groupMenu.group = null;
        this.paintGroupMenuDom();
        const menuW = 260;
        const menuH = 180;
        let x = Number(clientX) || 0;
        let y = Number(clientY) || 0;
        x = Math.max(8, Math.min(x, window.innerWidth - menuW - 8));
        y = Math.max(8, Math.min(y, window.innerHeight - menuH - 8));
        this._suppressOutsideClick = true;
        clearTimeout(this._suppressOutsideTimer);
        this._suppressOutsideTimer = setTimeout(() => { this._suppressOutsideClick = false; }, 400);
        this.rowMenu.open = true;
        this.rowMenu.x = x;
        this.rowMenu.y = y;
        this.rowMenu.editUrl = row.editUrl || '';
        this.rowMenu.reviseUrl = row.reviseUrl || '';
        this.rowMenu.recurUrl = row.recurUrl || '';
        this.rowMenu.canEdit = canEdit;
        this.rowMenu.canRevise = canRevise;
        this.rowMenu.canRecur = canRecur;
        this.rowMenu.canPrintDist = canPrintDist;
        this.rowMenu.label = row.label || '';
        this.rowMenu.title = row.title || '';
        this.rowMenu.effectivity = row.effectivity || '';
        this.rowMenu.revisionNo = row.revisionNo ?? '';
        this.rowMenu.distDate = row.distDate || '';
        this.rowMenu.offices = Array.isArray(row.offices) ? row.offices : [];
        // Imperative paint — survives Livewire morph leaving x-cloak / broken Alpine x-show.
        this.paintRowMenuDom();
    },
    openRowMenu(e) {
        const host = e?.currentTarget || e?.target?.closest?.('tr[data-row-actions-b64], tr[data-row-actions]');
        this.openRowMenuFromEl(host, e?.clientX, e?.clientY);
    },
    closeRowMenu() {
        if (this._suppressOutsideClick) {
            return;
        }
        this.rowMenu.open = false;
        this.paintRowMenuDom();
    },
    closeGroupMenuSafe() {
        if (this._suppressOutsideClick) {
            return;
        }
        this.closeGroupMenu();
    },
    goRowAction(url) {
        if (!url) {
            return;
        }
        this._suppressOutsideClick = false;
        this.rowMenu.open = false;
        this.paintRowMenuDom();
        window.location.href = url;
    },
    buildDistributionUrl(payload = {}) {
        const params = new URLSearchParams();
        const offices = Array.isArray(payload.offices) ? payload.offices : [];
        offices.forEach((office) => {
            const name = typeof office === 'string' ? office : (office?.name || '');
            if (!name) return;
            params.append('offices[]', name);
            const copies = typeof office === 'object' && office?.copies != null ? String(office.copies) : '';
            params.append('copies[]', copies);
        });
        if (payload.date) params.set('date', payload.date);
        if (payload.title) params.set('document_title', payload.title);
        if (payload.effectivity) params.set('effectivity_date', payload.effectivity);
        if (payload.revisionNo !== undefined && payload.revisionNo !== null && payload.revisionNo !== '') {
            params.set('revision_no', String(payload.revisionNo));
        }
        return this.distTemplateUrl + (params.toString() ? ('?' + params.toString()) : '');
    },
    printDistributionFromRow() {
        if (!this.rowMenu.canPrintDist) {
            return;
        }
        if (!this.rowMenu.offices.length) {
            alert('This document has no receiving offices yet. Add distribution offices in Document Registration first.');
            this._suppressOutsideClick = false;
            this.rowMenu.open = false;
            this.paintRowMenuDom();
            return;
        }
        const url = this.buildDistributionUrl({
            offices: this.rowMenu.offices,
            date: this.rowMenu.distDate || '',
            title: this.rowMenu.title || '',
            effectivity: this.rowMenu.effectivity || '',
            revisionNo: this.rowMenu.revisionNo,
        });
        this._suppressOutsideClick = false;
        this.rowMenu.open = false;
        this.paintRowMenuDom();
        window.open(url, '_blank', 'noopener');
    },
    hideGroupFromMenu() {
        const g = this.groupMenu.group;
        this._suppressOutsideClick = false;
        this.closeGroupMenu();
        if (!g) {
            return;
        }
        // Always paint DOM attrs — Alpine :data-vis-* on the root can miss updates after
        // category expand / Livewire morph even when this.visible was already false in JS.
        this.visible = Object.assign({}, this.visible, { [g]: false });
        this.paintVisibilityDom();
        this.queueStickySync();
        this.persistVisible();
    },
    collapseAll() {
        const next = Object.values(this.open).some(Boolean);
        const updated = {};
        Object.keys(this.open).forEach(k => { updated[k] = !next; });
        this.open = updated;
        this.paintOpenDom();
        this.persistExpand();
    },
    withTableBusy(label, hint, fn) {
        if (this._busyTimer) {
            clearTimeout(this._busyTimer);
            this._busyTimer = null;
        }
        this.busyLabel = label || 'Loading documents…';
        this.busyHint = hint || 'Fetching records and preparing the preview.';
        this.tableBusy = true;
        this.$nextTick(() => {
            requestAnimationFrame(() => {
                try {
                    fn();
                } finally {
                    this.$nextTick(() => {
                        requestAnimationFrame(() => {
                            // Keep overlay briefly so dense expands don't flash empty UI.
                            this._busyTimer = setTimeout(() => {
                                this.tableBusy = false;
                                this._busyTimer = null;
                            }, 120);
                        });
                    });
                }
            });
        });
    },
    toggleCategory(slug) {
        const willExpand = !this.categories[slug];
        if (willExpand) {
            this.withTableBusy('Expanding category…', 'Showing documents in this group.', () => {
                // New object so Alpine always notices the change.
                this.categories = Object.assign({}, this.categories, { [slug]: true });
                this.applyCategoryVisibility();
                // Re-assert column hide attrs so newly shown rows honor data-vis-*.
                this.paintVisibilityDom();
                this.paintOpenDom();
                this.persistExpand();
            });
            return;
        }
        this.categories = Object.assign({}, this.categories, { [slug]: false });
        this.applyCategoryVisibility();
        this.persistExpand();
    },
    toggleRev(id) {
        const willExpand = !this.expandedRevs[id];
        if (willExpand) {
            this.withTableBusy('Expanding revisions…', 'Showing older revisions for this document.', () => {
                this.expandedRevs = Object.assign({}, this.expandedRevs, { [id]: true });
                this.applyCategoryVisibility();
                this.persistExpand();
            });
            return;
        }
        this.expandedRevs = Object.assign({}, this.expandedRevs, { [id]: false });
        this.applyCategoryVisibility();
        this.persistExpand();
    },
    toggleCourses(id) {
        this.openCourses[id] = !this.openCourses[id];
        this.persistExpand();
        this.applyCategoryVisibility();
    },
    toggleCourseProgram(progKey) {
        this.collapsedCoursePrograms[progKey] = !this.collapsedCoursePrograms[progKey];
        this.applyCategoryVisibility();
    },
    slug(name) { return String(name || 'uncategorized').toLowerCase().replace(/[^a-z0-9]+/g, '-') }
}" @keydown.escape.window="rowMenu.open = false; paintRowMenuDom(); closeGroupMenu()">

<div class="db-filter-overlay" :class="{ 'db-open': filterOpen }" @click="filterOpen = false"></div>
<aside class="db-filter-panel" :class="{ 'db-open': filterOpen }" id="filterPanel">
    <div class="db-filter-head">
        <h3>Filters</h3>
        <button class="db-close-btn" type="button" @click="filterOpen = false">&times;</button>
    </div>
    <div class="db-filter-body">
        <div class="db-filter-group">
        <label>Sub-type</label>
        <select wire:model="subTypeId">
            <option value="all">All Sub-types</option>
            @foreach($subTypes ?? [] as $sub)
                <option value="{{ $sub->id }}">{{ $sub->doc_type_name }}</option>
            @endforeach
        </select>
    </div>
    <div class="db-filter-group">
        <label>Originator</label>
        <select wire:model="originator">
            <option value="">All Originators</option>
            @foreach($originators as $orig)
                <option value="{{ $orig->originator_name }}">{{ $orig->originator_name }}</option>
            @endforeach
        </select>
    </div>
    <div class="db-filter-group">
        <label>Source Unit</label>
        <select wire:model="sourceUnit">
            <option value="">All Units</option>
            @foreach($offices ?? [] as $office)
                <option value="{{ $office->id }}">{{ $office->office_name }}</option>
            @endforeach
        </select>
    </div>
    <div class="db-filter-group">
        <label>Received By</label>
        <select wire:model="receivedBy">
            <option value="">All distribution offices</option>
            @forelse($distributionOffices ?? [] as $office)
                <option value="{{ $office->id }}">{{ $office->office_name }}</option>
            @empty
                <option value="" disabled>No distribution offices yet</option>
            @endforelse
        </select>
    </div>
    <div class="db-filter-group">
        <label>Approval Status</label>
        <select wire:model="status">
            <option value="">Any</option>
            <option value="applicable">Applicable</option>
            <option value="not_applicable">Not Applicable</option>
        </select>
    </div>
    <div class="db-filter-group">
        <label>Status</label>
        <select wire:model="revisionStatus">
            <option value="all">All</option>
            <option value="latest">Latest Only</option>
            <option value="superseded">Superseded Only</option>
            <option value="obsolete">Obsolete Only</option>
        </select>
    </div>
    <div class="db-filter-group">
        <label>Effectivity Date From</label>
        <input type="date" wire:model="dateFrom">
    </div>
    <div class="db-filter-group">
        <label>Effectivity Date To</label>
        <input type="date" wire:model="dateTo">
    </div>
    <div class="db-filter-group">
        <label>Revision No</label>
        <input type="text" wire:model="revNo" placeholder="e.g. 3">
    </div>
    <div class="db-filter-group">
        <label class="db-check-label">
            <input type="checkbox" wire:model="exportAllColumns">
            Include all columns in CSV export
        </label>
        <p class="db-filter-hint">Export uses the filters above. Leave unchecked for the main inventory columns only.</p>
    </div>
    </div>
    <div class="db-filter-foot">
        <button class="db-btn db-btn-ghost" type="button" wire:click="resetFilters">Reset</button>
        <button class="db-btn db-btn-primary" type="button" wire:click="applyFilters" @click="filterOpen = false">Apply Filters</button>
    </div>
</aside>

<main class="db-page">

    <header class="db-header">
        <div class="db-header-left">
            <div class="db-breadcrumb">Document Control System / <span>Inventory</span></div>
            <h1>Inventory</h1>
        </div>
        <div class="db-header-right">
            <span class="db-count-badge">{{ $list['total'] ?? 0 }} Documents</span>
        </div>
    </header>

    @if($notice)
        <p class="db-notice">{{ $notice }}</p>
    @endif
    @if(!empty($list['error']))
        <p class="db-notice">{{ $list['error'] }}</p>
    @endif

    <section class="db-controls">
        <div class="db-type-grid">
            <button wire:key="db-type-all" class="db-type-btn {{ $docTypeId === 'all' ? 'active' : '' }}" type="button" wire:click="setType('all')">ALL</button>
            @foreach($docTypes ?? [] as $type)
                <button wire:key="db-type-{{ $type->id }}" class="db-type-btn {{ (string) $docTypeId === (string) $type->id ? 'active' : '' }}" type="button" wire:click="setType('{{ $type->id }}')">{{ strtoupper($type->doc_type_name) }}</button>
            @endforeach
        </div>
        <div class="db-controls-right">
            <div
                class="db-search-wrap"
                wire:ignore
                x-data="{
                    q: @js($search),
                    timer: null,
                    push() {
                        clearTimeout(this.timer);
                        this.timer = setTimeout(() => { $wire.set('search', this.q); }, 300);
                    }
                }"
                x-init="$wire.$watch('search', (value) => {
                    if (document.activeElement === $refs.dbSearch) return;
                    q = value ?? '';
                })"
            >
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                <input
                    type="text"
                    x-ref="dbSearch"
                    x-model="q"
                    @input="push()"
                    placeholder="Search by document no., title, keywords..."
                    autocomplete="off"
                >
            </div>
            <button class="db-collapse-btn" type="button" :class="{ 'is-collapsed': allCollapsed }" @click="collapseAll()" title="Collapse all columns" :title="allCollapsed ? 'Expand all columns' : 'Collapse all columns'">
                <i class="fa-solid fa-compress" x-show="!allCollapsed"></i>
                <i class="fa-solid fa-expand" x-show="allCollapsed" x-cloak></i>
                <span class="btn-label" x-text="allCollapsed ? 'Expand' : 'Collapse'">Collapse</span>
            </button>
            <button class="db-filter-btn {{ $receivedBy !== '' || $originator !== '' || $sourceUnit !== '' || $status !== '' || $revisionStatus !== 'all' || $dateFrom !== '' || $dateTo !== '' || $revNo !== '' || $subTypeId !== 'all' ? 'is-active' : '' }}" type="button" @click="filterOpen = true">
                <i class="fa-solid fa-filter"></i>
                <span class="btn-label">Filter</span>
                @if($receivedBy !== '')
                    <span class="db-filter-dot" title="Received By filter active"></span>
                @endif
            </button>
            <button class="db-custom-rpt-btn" type="button" @click="$dispatch('open-db-custom-report')" title="Choose columns and generate a PDF or Excel report">
                <i class="fa-solid fa-file-export"></i>
                <span class="btn-label">Custom Report</span>
            </button>
            <button class="db-export-btn" type="button" wire:click="export" title="Download CSV using the current filters">
                <i class="fa-solid fa-download"></i>
                <span class="btn-label">Export CSV</span>
            </button>
        </div>
    </section>

    <section class="db-table-wrap" wire:loading.class="is-loading" wire:target="setType,applyFilters,resetFilters,goToPage,search" :class="{ 'is-loading': tableBusy }">
        <div class="dcs-loading-overlay" wire:loading.class="is-visible" wire:target="setType,applyFilters,resetFilters,goToPage,search">
            <div class="dcs-loading-spinner" aria-hidden="true"></div>
            <h4>Loading documents…</h4>
            <p>Fetching records and preparing the preview.</p>
        </div>
        <div class="dcs-loading-overlay" x-show="tableBusy" x-cloak :class="{ 'is-visible': tableBusy }">
            <div class="dcs-loading-spinner" aria-hidden="true"></div>
            <h4 x-text="busyLabel">Loading documents…</h4>
            <p x-text="busyHint">Fetching records and preparing the preview.</p>
        </div>
        <div class="db-table-scroll">
            <table class="db-table" id="inventoryTable">
                <thead>
                    <tr class="db-head-primary">
                        <th rowspan="3" class="db-sticky-col db-sticky-1">ITEM NO.</th>
                        <th rowspan="3" class="db-sticky-col db-sticky-2">DOCUMENT NO.</th>
                        <th rowspan="3" class="db-sticky-col db-sticky-3">REV.</th>
                        <th rowspan="3" class="db-sticky-col db-sticky-4">DOCUMENT TITLE</th>
                        <th rowspan="3">EFFECTIVITY DATE</th>
                        <th rowspan="3">ORIGINATOR</th>
                        <th rowspan="3">PAGES</th>
                        <th rowspan="3">STATUS</th>
                        <th rowspan="3">PDF FILE</th>
                        <th rowspan="3" class="db-offices-col db-source-unit-col">SOURCE UNIT</th>
                        <th rowspan="3" class="db-related-col">RELATED DOCS</th>

                        <th rowspan="3" class="col-group-summary db-group-header" data-group="approval" x-on:click="toggle('approval')">APPROVAL <span class="collapse-arrow">&#9654;</span></th>
                        <th colspan="{{ $groupColspans['approval'] ?? 2 }}" class="col-group-approval col-group-expanded db-group-header" data-group="approval" x-on:click="toggle('approval')">APPROVAL <span class="collapse-arrow">&#9664;</span></th>

                        <th rowspan="3" class="col-group-summary db-group-header" data-group="deadline" x-on:click="toggle('deadline')">DEADLINE <span class="collapse-arrow">&#9654;</span></th>
                        <th colspan="{{ $groupColspans['deadline'] ?? 2 }}" class="col-group-deadline col-group-expanded db-group-header" data-group="deadline" x-on:click="toggle('deadline')">DEADLINE <span class="collapse-arrow">&#9664;</span></th>

                        <th rowspan="3" class="col-group-summary db-group-header" data-group="masterlist" x-on:click="toggle('masterlist')">MASTERLIST <span class="collapse-arrow">&#9654;</span></th>
                        <th colspan="{{ $groupColspans['masterlist'] ?? 5 }}" class="col-group-masterlist col-group-expanded db-group-header" data-group="masterlist" x-on:click="toggle('masterlist')">MASTERLIST REGISTRATION <span class="collapse-arrow">&#9664;</span></th>

                        <th rowspan="3" class="col-group-summary db-group-header" data-group="dcn" x-on:click="toggle('dcn')">DCN <span class="collapse-arrow">&#9654;</span></th>
                        <th colspan="{{ $groupColspans['dcn'] ?? 6 }}" class="col-group-dcn col-group-expanded db-group-header" data-group="dcn" x-on:click="toggle('dcn')">DOCUMENT CHANGE NOTICE <span class="collapse-arrow">&#9664;</span></th>

                        <th rowspan="3" class="col-group-summary db-group-header" data-group="drf" x-on:click="toggle('drf')">DRF <span class="collapse-arrow">&#9654;</span></th>
                        <th colspan="{{ $groupColspans['drf'] ?? 5 }}" class="col-group-drf col-group-expanded db-group-header" data-group="drf" x-on:click="toggle('drf')">DOCUMENT REQUEST FORM <span class="collapse-arrow">&#9664;</span></th>

                        <th rowspan="3" class="col-group-summary db-group-header" data-group="distribution" x-on:click="toggle('distribution')">DISTRIBUTION <span class="collapse-arrow">&#9654;</span></th>
                        <th colspan="{{ $groupColspans['distribution'] ?? 6 }}" class="col-group-distribution col-group-expanded db-group-header" data-group="distribution" x-on:click="toggle('distribution')">DISTRIBUTION <span class="collapse-arrow">&#9664;</span></th>

                        <th rowspan="3" class="col-group-summary db-group-header" data-group="retrieval" x-on:click="toggle('retrieval')">RETRIEVAL <span class="collapse-arrow">&#9654;</span></th>
                        <th rowspan="3" class="col-group-retrieval col-group-expanded db-group-header db-offices-col" data-group="retrieval" x-on:click="toggle('retrieval')">RETRIEVED OFFICE(S) <span class="collapse-arrow">&#9664;</span></th>
                    </tr>

                    <tr class="db-head-secondary">
                        <th colspan="2" class="col-group-approval col-group-expanded">BOT/ADCO APPROVAL</th>
                        <th rowspan="2" class="col-group-deadline col-group-expanded">DATE</th>
                        <th rowspan="2" class="col-group-deadline col-group-expanded">DAY DIFF</th>
                        <th colspan="2" class="col-group-masterlist col-group-expanded">DOCUMENT RECEIPT</th>
                        <th colspan="2" class="col-group-masterlist col-group-expanded">DOCUMENT REGISTERED</th>
                        <th rowspan="2" class="col-group-masterlist col-group-expanded">TIME SPENT (MINS)</th>
                        <th rowspan="2" class="col-group-dcn col-group-expanded">DCN NO.</th>
                        <th rowspan="2" class="col-group-dcn col-group-expanded">DCN DATE</th>
                        <th colspan="2" class="col-group-dcn col-group-expanded">DCN RECEIPT (ACTUAL)</th>
                        <th rowspan="2" class="col-group-dcn col-group-expanded db-offices-col">PURPOSE OF REVISION</th>
                        <th rowspan="2" class="col-group-dcn col-group-expanded">SCANNED DCN</th>
                        <th rowspan="2" class="col-group-drf col-group-expanded">DRF NO.</th>
                        <th rowspan="2" class="col-group-drf col-group-expanded">DRF DATE</th>
                        <th colspan="2" class="col-group-drf col-group-expanded">DRF RECEIPT</th>
                        <th rowspan="2" class="col-group-drf col-group-expanded">SCANNED DRF</th>
                        <th colspan="2" class="col-group-distribution col-group-expanded">DISTRIBUTION (ON FILE)</th>
                        <th colspan="2" class="col-group-distribution col-group-expanded">DISTRIBUTION (ACTUAL)</th>
                        <th rowspan="2" class="col-group-distribution col-group-expanded db-offices-col">RECEIVING OFFICE(S)</th>
                        <th rowspan="2" class="col-group-distribution col-group-expanded">SCANNED DIST.</th>
                    </tr>

                    <tr class="db-head-tertiary">
                        <th class="col-group-approval col-group-expanded">NO.</th>
                        <th class="col-group-approval col-group-expanded">APPV. DATE</th>
                        <th class="col-group-masterlist col-group-expanded">DATE</th>
                        <th class="col-group-masterlist col-group-expanded">TIME</th>
                        <th class="col-group-masterlist col-group-expanded">DATE</th>
                        <th class="col-group-masterlist col-group-expanded">TIME</th>
                        <th class="col-group-dcn col-group-expanded">DATE</th>
                        <th class="col-group-dcn col-group-expanded">TIME</th>
                        <th class="col-group-drf col-group-expanded">DATE</th>
                        <th class="col-group-drf col-group-expanded">TIME</th>
                        <th class="col-group-distribution col-group-expanded">DATE</th>
                        <th class="col-group-distribution col-group-expanded">TIME</th>
                        <th class="col-group-distribution col-group-expanded">DATE</th>
                        <th class="col-group-distribution col-group-expanded">TIME</th>
                    </tr>
                </thead>

                <tbody wire:key="db-tbody-{{ $docTypeId }}-{{ ! empty($forceCategoriesOpen) ? 'forced' : 'plain' }}-{{ $list['page'] ?? 1 }}">
                    @php $lastCategory = null; @endphp
                    @forelse(($list['data'] ?? []) as $i => $group)
                        @php
                            $r = $group['parent'];
                            $catName = $r['doc_type_name'] ?? 'Uncategorized';
                            $catSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $catName));
                            $itemNo = (int) ($group['type_seq'] ?? ((($list['page'] ?? 1) - 1) * ($list['per_page'] ?? 50) + $i + 1));
                            $revKey = 'rev-' . $r['request_id'];
                            $rowActions = [
                                'editUrl' => $r['edit_url'] ?? '',
                                'reviseUrl' => $r['revise_url'] ?? '',
                                'recurUrl' => $r['recur_url'] ?? '',
                                'canEdit' => ! empty($r['can_edit']),
                                'canRevise' => ! empty($r['can_revise']),
                                'canRecur' => ! empty($r['can_recur']),
                                'canPrintDist' => empty($r['is_deleted']),
                                'label' => ($r['doc_no'] ?? 'Document') . ' · Rev ' . ($r['rev_no'] ?? 0),
                                'title' => $r['title'] ?? '',
                                'effectivity' => $r['effectivity_raw'] ?? '',
                                'revisionNo' => $r['rev_no'] ?? 0,
                                'distDate' => $r['dist_print_date'] ?? '',
                                'offices' => $r['dist_office_list'] ?? [],
                            ];
                        @endphp

                        @if($showCategoryRows && $catName !== $lastCategory)
                            @php
                                $catTotal = (int) (($list['category_totals'][$catName] ?? 0));
                                if ($catTotal < 1) {
                                    $catTotal = collect($list['data'] ?? [])
                                        ->filter(fn ($g) => ($g['parent']['doc_type_name'] ?? '') === $catName
                                            && empty($g['parent']['is_deleted']))
                                        ->count();
                                }
                            @endphp
                            <tr class="db-category-row">
                                <td colspan="47">
                                    <span class="db-category-toggle" :class="{ expanded: categories['{{ $catSlug }}'] }" x-on:click="toggleCategory('{{ $catSlug }}')">
                                        <span class="db-category-chevron" x-text="categories['{{ $catSlug }}'] ? '▼' : '▶'">{{ ! empty($categoryState[$catSlug]) ? '▼' : '▶' }}</span>
                                        {{ strtoupper($catName) }}
                                        <span class="db-category-count">{{ $catTotal }}</span>
                                    </span>
                                </td>
                            </tr>
                            @php $lastCategory = $catName; @endphp
                        @elseif(! $showCategoryRows)
                            @php $lastCategory = $catName; @endphp
                        @endif

                        @php $catStartsOpen = ! $showCategoryRows || ! empty($categoryState[$catSlug]); @endphp
                        <tr class="db-parent-row @if(!empty($r['is_deleted'])) db-deleted-row @endif @if($showCategoryRows && ! $catStartsOpen) db-row-collapsed @endif @if($showCategoryRows && $catStartsOpen) db-cat-open @endif" @if($showCategoryRows) data-cat="{{ $catSlug }}" :class="{ 'db-row-collapsed': !categories['{{ $catSlug }}'], 'db-cat-open': !!categories['{{ $catSlug }}'] }" @endif data-row-actions-b64="{{ base64_encode(json_encode($rowActions, JSON_UNESCAPED_UNICODE)) }}" @contextmenu.prevent="openRowMenu($event)" @if(!empty($r['is_deleted'])) title="Moved to Recycle Bin{{ !empty($r['deleted_at']) ? ' on ' . $r['deleted_at'] : '' }}" @endif>
                            <td>
                                @if(!empty($group['children']))
                                    <span class="db-expand-btn" :class="{ expanded: expandedRevs['{{ $revKey }}'] }" x-on:click.stop="toggleRev('{{ $revKey }}')" title="Show {{ $group['stack_label'] ?? 'older revisions' }}" x-text="expandedRevs['{{ $revKey }}'] ? '▼' : '▶'">▶</span>
                                @endif
                                {{ $itemNo }}
                            </td>
                            @include('pages.dcs.database._row', ['r' => $r])
                        </tr>

                        @if(!empty($r['syllabi_course_groups']))
                            @php $totalProgGroups = count($r['syllabi_course_groups']); @endphp
                            @foreach($r['syllabi_course_groups'] as $pIdx => $pGrp)
                                @php
                                    $isLastProgGroup = ($pIdx === $totalProgGroups - 1);
                                    $progCourseCount = count($pGrp['courses']);
                                    $progToggleKey = $r['request_id'] . ':' . $pIdx;
                                @endphp
                                <tr
                                    wire:key="course-prog-{{ $r['request_id'] }}-{{ $pIdx }}"
                                    class="db-course-prog-row {{ $pIdx === 0 ? 'is-course-block-start' : '' }} db-row-collapsed"
                                    @if($showCategoryRows) data-cat="{{ $catSlug }}" @endif
                                    data-course-req="{{ $r['request_id'] }}"
                                    style="display:none;"
                                    @click="toggleCourseProgram('{{ $progToggleKey }}')"
                                    title="Click to collapse or expand courses for {{ $pGrp['program'] }}"
                                >
                                    <td colspan="47">
                                        <span class="db-course-prog-badge" :class="{ 'is-collapsed': !!collapsedCoursePrograms['{{ $progToggleKey }}'] }">
                                            <i class="fa-solid fa-chevron-down db-course-prog-caret" aria-hidden="true"></i>
                                            <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
                                            <span>{{ $pGrp['program'] }}</span>
                                            <span class="db-course-prog-count">{{ $progCourseCount }}</span>
                                        </span>
                                    </td>
                                </tr>
                                @foreach($pGrp['courses'] as $cIdx => $cd)
                                    <tr
                                        wire:key="course-sub-{{ $r['request_id'] }}-{{ $pIdx }}-{{ $cIdx }}"
                                        class="db-course-sub-row {{ $cIdx % 2 === 1 ? 'is-course-alt' : '' }} {{ ($pIdx === 0 && $cIdx === 0) ? 'is-course-first' : '' }} {{ ($isLastProgGroup && $cIdx === $progCourseCount - 1) ? 'is-course-last' : '' }} db-row-collapsed"
                                        @if($showCategoryRows) data-cat="{{ $catSlug }}" @endif
                                        data-course-req="{{ $r['request_id'] }}"
                                        data-course-prog="{{ $progToggleKey }}"
                                        style="display:none;"
                                    >
                                        @include('pages.dcs.database._course-row', ['cd' => $cd, 'cIdx' => $cIdx])
                                    </tr>
                                @endforeach
                            @endforeach
                        @endif

                        @foreach($group['children'] ?? [] as $ci => $child)
                            @php
                                $childActions = [
                                    'editUrl' => $child['edit_url'] ?? '',
                                    'reviseUrl' => $child['revise_url'] ?? '',
                                    'recurUrl' => $child['recur_url'] ?? '',
                                    'canEdit' => ! empty($child['can_edit']),
                                    'canRevise' => ! empty($child['can_revise']),
                                    'canRecur' => ! empty($child['can_recur']),
                                    'canPrintDist' => empty($child['is_deleted']),
                                    'label' => ($child['doc_no'] ?? 'Document') . ' · Rev ' . ($child['rev_no'] ?? 0),
                                    'title' => $child['title'] ?? '',
                                    'effectivity' => $child['effectivity_raw'] ?? '',
                                    'revisionNo' => $child['rev_no'] ?? 0,
                                    'distDate' => $child['dist_print_date'] ?? '',
                                    'offices' => $child['dist_office_list'] ?? [],
                                ];
                            @endphp
                            <tr class="db-child-row @if(!empty($child['is_deleted'])) db-deleted-row @endif @if($showCategoryRows && ! $catStartsOpen) db-row-collapsed @endif @if($showCategoryRows && $catStartsOpen) db-cat-open @endif" @if($showCategoryRows) data-cat="{{ $catSlug }}" @endif data-rev-key="{{ $revKey }}" :class="{ 'db-row-collapsed': {{ $showCategoryRows ? "!categories['{$catSlug}'] || " : '' }}!expandedRevs['{{ $revKey }}'], 'db-cat-open': {{ $showCategoryRows ? "!!categories['{$catSlug}']" : 'true' }} }" data-row-actions-b64="{{ base64_encode(json_encode($childActions, JSON_UNESCAPED_UNICODE)) }}" @contextmenu.prevent="openRowMenu($event)" @if(!empty($child['is_deleted'])) title="Moved to Recycle Bin{{ !empty($child['deleted_at']) ? ' on ' . $child['deleted_at'] : '' }}" @endif>
                                <td class="db-child-ind"></td>
                                @include('pages.dcs.database._row', ['r' => $child])
                            </tr>
                            @if(!empty($child['syllabi_course_groups']))
                                @php $totalChildProgGroups = count($child['syllabi_course_groups']); @endphp
                                @foreach($child['syllabi_course_groups'] as $pIdx => $pGrp)
                                    @php
                                        $isLastChildProgGroup = ($pIdx === $totalChildProgGroups - 1);
                                        $childProgCourseCount = count($pGrp['courses']);
                                        $childProgToggleKey = $child['request_id'] . ':' . $pIdx;
                                    @endphp
                                    <tr
                                        wire:key="child-course-prog-{{ $child['request_id'] }}-{{ $pIdx }}"
                                        class="db-course-prog-row db-child-course-sub-row {{ $pIdx === 0 ? 'is-course-block-start' : '' }} db-row-collapsed"
                                        @if($showCategoryRows) data-cat="{{ $catSlug }}" @endif
                                        data-rev-key="{{ $revKey }}"
                                        data-course-req="{{ $child['request_id'] }}"
                                        style="display:none;"
                                        @click="toggleCourseProgram('{{ $childProgToggleKey }}')"
                                        title="Click to collapse or expand courses for {{ $pGrp['program'] }}"
                                    >
                                        <td colspan="47">
                                            <span class="db-course-prog-badge" :class="{ 'is-collapsed': !!collapsedCoursePrograms['{{ $childProgToggleKey }}'] }">
                                                <i class="fa-solid fa-chevron-down db-course-prog-caret" aria-hidden="true"></i>
                                                <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
                                                <span>{{ $pGrp['program'] }}</span>
                                                <span class="db-course-prog-count">{{ $childProgCourseCount }}</span>
                                            </span>
                                        </td>
                                    </tr>
                                    @foreach($pGrp['courses'] as $cIdx => $cd)
                                        <tr
                                            wire:key="child-course-sub-{{ $child['request_id'] }}-{{ $pIdx }}-{{ $cIdx }}"
                                            class="db-course-sub-row db-child-course-sub-row {{ $cIdx % 2 === 1 ? 'is-course-alt' : '' }} {{ ($pIdx === 0 && $cIdx === 0) ? 'is-course-first' : '' }} {{ ($isLastChildProgGroup && $cIdx === $childProgCourseCount - 1) ? 'is-course-last' : '' }} db-row-collapsed"
                                            @if($showCategoryRows) data-cat="{{ $catSlug }}" @endif
                                            data-rev-key="{{ $revKey }}"
                                            data-course-req="{{ $child['request_id'] }}"
                                            data-course-prog="{{ $childProgToggleKey }}"
                                            style="display:none;"
                                        >
                                            @include('pages.dcs.database._course-row', ['cd' => $cd, 'cIdx' => $cIdx])
                                        </tr>
                                    @endforeach
                                @endforeach
                            @endif
                        @endforeach
                    @empty
                        <tr><td colspan="47" class="db-empty-cell">No documents found</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @php
            $pg = (int) ($list['page'] ?? 1);
            $pgLast = (int) ($list['last_page'] ?? 1);
            $pgTotal = (int) ($list['total'] ?? 0);
            $pgSize = (int) ($list['per_page'] ?? 20);
            $pgFrom = $pgTotal === 0 ? 0 : (($pg - 1) * $pgSize) + 1;
            $pgTo = min($pgTotal, $pg * $pgSize);
        @endphp
        @include('pages.dcs.partials.list-pagination', [
            'from' => $pgFrom,
            'to' => $pgTo,
            'total' => $pgTotal,
            'page' => $pg,
            'lastPage' => $pgLast,
            'loadingTarget' => 'goToPage',
        ])

    </section>
<div
    x-data="{
        open: false,
        previewMode: false,
        loadingPreview: false,
        exporting: '',
        title: 'DOCUMENT MASTERLIST',
        paperSize: 'a4',
        orientation: 'landscape',
        includeRevisions: true,
        columns: [0, 2, 3, 4, 5, 6, 8, 10],
        error: '',
        previewData: null,
        catalog: @js($customReportCatalog),
        openModal() {
            this.error = '';
            this.previewMode = false;
            if (this.columns.length === 0) {
                this.columns = [0, 2, 3, 4, 5, 6, 8, 10];
            }
            this.open = true;
        },
        closeModal() {
            this.open = false;
            this.previewMode = false;
            this.error = '';
        },
        toggleCol(idx) {
            idx = Number(idx);
            this.error = '';
            if (this.columns.includes(idx)) {
                this.columns = this.columns.filter(c => c !== idx);
            } else {
                this.columns = [...this.columns, idx].sort((a, b) => a - b);
            }
        },
        setPreset(preset) {
            this.error = '';
            if (preset === 'all') {
                this.columns = this.catalog.headers.map((_, i) => i);
            } else if (preset === 'none') {
                this.columns = [];
            } else {
                this.columns = [0, 2, 3, 4, 5, 6, 8, 10];
            }
        },
        groupAllChecked(grpName) {
            const cols = this.catalog.groups[grpName] || [];
            return cols.length > 0 && cols.every(c => this.columns.includes(c));
        },
        toggleGroup(grpName) {
            this.error = '';
            const cols = this.catalog.groups[grpName] || [];
            if (this.groupAllChecked(grpName)) {
                this.columns = this.columns.filter(c => !cols.includes(c));
            } else {
                const merged = new Set([...this.columns, ...cols]);
                this.columns = Array.from(merged).sort((a, b) => a - b);
            }
        },
        configPayload() {
            return {
                title: this.title,
                paperSize: this.paperSize,
                orientation: this.orientation,
                includeRevisions: this.includeRevisions,
                columns: this.columns,
            };
        },
        async showPreview() {
            if (this.columns.length === 0) {
                this.error = 'Select at least one column to preview the report.';
                return;
            }
            this.error = '';
            this.previewMode = true;
            this.loadingPreview = true;
            try {
                const res = await $wire.loadCustomReportPreview(this.configPayload());
                if (res && res.error) {
                    this.error = res.error;
                    this.previewMode = false;
                } else {
                    this.previewData = res;
                }
            } catch (e) {
                this.error = 'Could not load preview. Please try again.';
                this.previewMode = false;
            } finally {
                this.loadingPreview = false;
            }
        },
        async runExport(format) {
            if (this.columns.length === 0) {
                this.error = 'Select at least one column to generate the report.';
                return;
            }
            this.error = '';
            this.exporting = format;
            try {
                await $wire.exportCustomReport(format, this.configPayload());
            } finally {
                this.exporting = '';
            }
        },
        paperLabel() {
            const map = { a4: 'A4 (8.27 × 11.69 in)', short: 'Short / Letter (8.5 × 11 in)', long: 'Long / Folio (8.5 × 13 in)', legal: 'Legal (8.5 × 14 in)' };
            return map[this.paperSize] || 'A4';
        }
    }"
    @open-db-custom-report.window="openModal()"
    @keydown.escape.window="if (open) closeModal()"
>
<template x-teleport="body">
<div class="db-crpt-backdrop" x-show="open" x-cloak @click.self="closeModal()" role="dialog" aria-modal="true" aria-labelledby="dbCrptTitle">
    <div class="db-crpt-modal" :class="{ 'db-crpt-modal--preview': previewMode }">
        <div class="db-crpt-head">
            <div class="db-crpt-title-wrap">
                <span class="db-crpt-icon"><i class="fa-solid" :class="previewMode ? 'fa-eye' : 'fa-file-export'" aria-hidden="true"></i></span>
                <div>
                    <h3 id="dbCrptTitle" x-text="previewMode ? 'Report Preview' : 'Custom Generate Report'"></h3>
                    <p x-text="previewMode ? 'Review the letterhead, bond paper size, and orientation before exporting.' : 'Select the exact database columns to include. Uses your current active filters.'"></p>
                </div>
            </div>
            <div class="db-crpt-head-right">
                <div class="db-crpt-step-tabs" role="tablist">
                    <button type="button" :class="{ 'is-active': !previewMode }" @click="previewMode = false">
                        <i class="fa-solid fa-sliders" aria-hidden="true"></i> Columns
                    </button>
                    <button type="button" :class="{ 'is-active': previewMode }" @click="showPreview()">
                        <i class="fa-solid fa-eye" aria-hidden="true"></i> Preview
                    </button>
                </div>
                <button type="button" class="db-crpt-close" @click="closeModal()" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>

        <div class="db-crpt-body">
            <div class="db-crpt-err" x-show="error !== ''" x-cloak role="alert">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <span x-text="error"></span>
            </div>

            <div x-show="previewMode" x-cloak>
                <div class="db-crpt-preview-bar">
                    <div class="db-crpt-preview-controls">
                        <select class="db-crpt-paper-select" x-model="paperSize" aria-label="Bond paper size">
                            <option value="a4">A4 (8.27 × 11.69 in)</option>
                            <option value="short">Short / Letter (8.5 × 11 in)</option>
                            <option value="long">Long / Folio (8.5 × 13 in)</option>
                            <option value="legal">Legal (8.5 × 14 in)</option>
                        </select>
                        <div class="db-crpt-orient-switch" role="group" aria-label="Page orientation">
                            <button type="button" :class="{ 'is-active': orientation === 'portrait' }" @click="orientation = 'portrait'">
                                <i class="fa-regular fa-file" aria-hidden="true"></i> Portrait
                            </button>
                            <button type="button" :class="{ 'is-active': orientation === 'landscape' }" @click="orientation = 'landscape'">
                                <i class="fa-regular fa-file-lines" aria-hidden="true"></i> Landscape
                            </button>
                        </div>
                    </div>
                    <span class="db-crpt-preview-meta" x-show="previewData">
                        <span x-text="paperLabel()"></span> ·
                        <span x-text="orientation === 'portrait' ? 'Portrait' : 'Landscape'"></span> ·
                        Showing <span x-text="previewData ? previewData.rows.length : 0"></span> of <span x-text="previewData ? previewData.totalRows : 0"></span> records
                    </span>
                </div>

                <div class="db-crpt-paper-stage">
                    <div class="db-crpt-loading" x-show="loadingPreview">
                        <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                        <span>Preparing preview…</span>
                    </div>

                    <div
                        class="db-crpt-paper"
                        x-show="!loadingPreview && previewData"
                        :class="[orientation === 'portrait' ? 'is-portrait' : 'is-landscape', 'is-paper-' + paperSize]"
                    >
                        <div class="db-crpt-cspc-hdr">
                            <div class="db-crpt-cspc-brand">
                                <img src="{{ asset('images/logo.png') }}" alt="CSPC" class="db-crpt-cspc-logo">
                                <div class="db-crpt-cspc-text">
                                    <div>Republic of the Philippines</div>
                                    <div class="db-crpt-cspc-name">CAMARINES SUR POLYTECHNIC COLLEGES</div>
                                    <div>Nabua, Camarines Sur</div>
                                </div>
                            </div>
                            <div class="db-crpt-cspc-rule"></div>
                        </div>

                        <div class="db-crpt-paper-title" x-text="(title || 'DOCUMENT MASTERLIST').toUpperCase()"></div>
                        <div class="db-crpt-paper-sub" x-show="previewData">
                            Generated <span x-text="previewData?.generatedAt"></span> · <span x-text="previewData?.totalRows"></span> record(s)
                        </div>

                        <div class="db-crpt-paper-filters" x-show="previewData && previewData.filterSummary">
                            <strong>Active Filters:</strong> <span x-text="previewData?.filterSummary"></span>
                        </div>

                        <div class="db-crpt-paper-table-wrap">
                            <table class="db-crpt-paper-table">
                                <thead>
                                    <tr>
                                        <template x-for="(h, hi) in (previewData?.headers || [])" :key="hi">
                                            <th x-text="h"></th>
                                        </template>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-if="(previewData?.rows || []).length === 0">
                                        <tr>
                                            <td :colspan="Math.max(1, (previewData?.headers || []).length)" style="text-align:center; padding:18px; color:#64748b;">
                                                No matching documents found for the current filters.
                                            </td>
                                        </tr>
                                    </template>
                                    <template x-for="(r, ri) in (previewData?.rows || [])" :key="ri">
                                        <tr :class="{ 'is-child': r.is_child }">
                                            <template x-for="(val, ci) in r.cells" :key="ci">
                                                <td x-text="val !== '' ? val : '—'"></td>
                                            </template>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <div class="db-crpt-paper-more" x-show="previewData && previewData.totalRows > previewData.rows.length">
                            Preview shows the first <span x-text="previewData?.rows.length"></span> rows. Exporting will include all <span x-text="previewData?.totalRows"></span> records.
                        </div>
                    </div>
                </div>
            </div>

            <div x-show="!previewMode" class="db-crpt-config-wrap">
                <div class="db-crpt-options">
                    <div class="db-crpt-field db-crpt-field--grow">
                        <label for="dbCrptTitleInput">Report Title</label>
                        <input id="dbCrptTitleInput" type="text" x-model="title" placeholder="DOCUMENT MASTERLIST">
                    </div>
                    <div class="db-crpt-field">
                        <label for="dbCrptPaper">Bond Paper Size</label>
                        <select id="dbCrptPaper" x-model="paperSize">
                            <option value="a4">A4 (8.27 × 11.69 in)</option>
                            <option value="short">Short / Letter (8.5 × 11 in)</option>
                            <option value="long">Long / Folio (8.5 × 13 in)</option>
                            <option value="legal">Legal (8.5 × 14 in)</option>
                        </select>
                    </div>
                    <div class="db-crpt-field">
                        <label for="dbCrptOrient">Orientation</label>
                        <select id="dbCrptOrient" x-model="orientation">
                            <option value="landscape">Landscape</option>
                            <option value="portrait">Portrait</option>
                        </select>
                    </div>
                    <label class="db-crpt-toggle">
                        <input type="checkbox" x-model="includeRevisions">
                        <span>Include superseded/obsolete rows</span>
                    </label>
                </div>

                <div class="db-crpt-presets">
                    <span class="db-crpt-count"><strong x-text="columns.length"></strong> of <span x-text="catalog.headers.length"></span> columns selected</span>
                    <div class="db-crpt-preset-btns">
                        <button type="button" @click="setPreset('default')">Default</button>
                        <button type="button" @click="setPreset('all')">Select all</button>
                        <button type="button" @click="setPreset('none')">Clear all</button>
                    </div>
                </div>

                <div class="db-crpt-groups">
                    @foreach($customReportCatalog['groups'] as $grpName => $colIndexes)
                        <div class="db-crpt-group">
                            <div class="db-crpt-group-head">
                                <span>{{ $grpName }}</span>
                                <button type="button" @click="toggleGroup(@js($grpName))" x-text="groupAllChecked(@js($grpName)) ? 'Unselect group' : 'Select group'"></button>
                            </div>
                            <div class="db-crpt-cols">
                                @foreach($colIndexes as $cIdx)
                                    <label class="db-crpt-col-chip">
                                        <input type="checkbox" :checked="columns.includes({{ $cIdx }})" @change="toggleCol({{ $cIdx }})">
                                        <span>{{ $customReportCatalog['headers'][$cIdx] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="db-crpt-foot">
            <button type="button" class="db-crpt-btn-cancel" @click="closeModal()">Cancel</button>
            <div class="db-crpt-export-btns">
                <button
                    type="button"
                    class="db-crpt-btn-preview"
                    x-show="!previewMode"
                    @click="showPreview()"
                >
                    <i class="fa-solid fa-eye" aria-hidden="true"></i> Preview
                </button>
                <button
                    type="button"
                    class="db-crpt-btn-cancel"
                    x-show="previewMode"
                    x-cloak
                    @click="previewMode = false"
                >
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Edit Columns
                </button>
                <button
                    type="button"
                    class="db-crpt-btn-excel"
                    :disabled="exporting !== ''"
                    @click="runExport('excel')"
                >
                    <i class="fa-solid fa-file-excel" aria-hidden="true"></i>
                    <span x-text="exporting === 'excel' ? 'Generating…' : 'Export Excel'"></span>
                </button>
                <button
                    type="button"
                    class="db-crpt-btn-pdf"
                    :disabled="exporting !== ''"
                    @click="runExport('pdf')"
                >
                    <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
                    <span x-text="exporting === 'pdf' ? 'Generating…' : 'Export PDF'"></span>
                </button>
            </div>
        </div>
    </div>
</div>
</template>
</div>

</main>

{{-- Fixed menus outside .db-page overflow; actions bound via document click (survives Livewire morph) --}}
<div id="dcs-db-group-menu"
     class="db-col-context-menu db-group-hide-menu"
     wire:ignore
     style="display:none">
    <button type="button" data-menu-hide-group class="db-group-hide-btn">
        <span class="db-group-hide-icon" aria-hidden="true"><i class="fa-solid fa-eye-slash"></i></span>
        <span class="db-group-hide-copy">
            <span class="db-group-hide-title">Hide <span data-menu-group-name></span></span>
            <span class="db-group-hide-sub">Saved for everyone</span>
        </span>
    </button>
</div>

<div id="dcs-db-row-menu"
     class="db-col-context-menu db-row-context-menu"
     wire:ignore
     style="display:none">
    <div class="db-row-context-label" data-menu-label style="display:none"></div>
    <button type="button" data-menu-edit style="display:none">
        <i class="fa-solid fa-pen"></i> Edit
    </button>
    <button type="button" data-menu-revise style="display:none">
        <i class="fa-solid fa-file-pen"></i> Register revised
    </button>
    <button type="button" data-menu-recur style="display:none">
        <i class="fa-solid fa-rotate"></i> Register recur
    </button>
    <button type="button" data-menu-print style="display:none">
        <i class="fa-solid fa-print"></i> Print for distribution
    </button>
</div>
</div>

<script>
/**
 * Dcs offices clamp.
 *
 * @param {*} offices
 * @param {*} highlightOffice
 */
window.dcsOfficesClamp = function (offices, highlightOffice) {
    return {
        offices: Array.isArray(offices) ? offices.filter(Boolean) : [],
        highlightOffice: String(highlightOffice || '').trim(),
        expanded: false,
        visibleCount: 0,
        visibleHtml: '',
        showToggle: false,
        _ro: null,
        escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },
        isHit(name) {
            if (!this.highlightOffice) return false;
            return String(name).localeCompare(this.highlightOffice, undefined, { sensitivity: 'accent' }) === 0;
        },
        hasHighlight() {
            return this.offices.some((name) => this.isHit(name));
        },
        renderHtml(list) {
            return (Array.isArray(list) ? list : []).map((name) => {
                const safe = this.escapeHtml(name);
                return this.isHit(name)
                    ? '<mark class="db-office-hit">' + safe + '</mark>'
                    : safe;
            }).join(', ');
        },
        init() {
            this.visibleCount = this.offices.length;
            this.visibleHtml = this.renderHtml(this.offices);
            this.showToggle = false;
            // Received By filter: start expanded so match is visible; user can still collapse.
            if (this.hasHighlight()) {
                this.expanded = true;
            }
            this.$nextTick(() => {
                this.sync();
                if (typeof ResizeObserver !== 'undefined') {
                    this._ro = new ResizeObserver(() => {
                        if (!this.expanded) this.sync();
                    });
                    if (this.$refs.view) this._ro.observe(this.$refs.view);
                }
            });
        },
        lineHeightPx() {
            const view = this.$refs.view;
            if (!view) return 18;
            const lh = parseFloat(getComputedStyle(view).lineHeight);
            return Number.isFinite(lh) && lh > 0 ? lh : 18;
        },
        fitsTwoLines(text) {
            const measure = this.$refs.measure;
            const view = this.$refs.view;
            if (!measure || !view) return true;
            const width = view.clientWidth || view.offsetWidth;
            if (width < 8) return true;
            measure.style.width = width + 'px';
            measure.textContent = text;
            const maxH = (this.lineHeightPx() * 2) + 2;
            return measure.scrollHeight <= maxH;
        },
        trimAtWord(value, maxLen) {
            if (maxLen >= value.length) return value;
            let cut = value.slice(0, Math.max(1, maxLen));
            const space = cut.lastIndexOf(' ');
            if (space >= Math.floor(maxLen * 0.5)) {
                cut = cut.slice(0, space);
            }
            return cut.replace(/[,\s.;:!-]+$/g, '');
        },
        sync() {
            const total = this.offices.length;
            if (total === 0) {
                this.visibleHtml = '';
                this.visibleCount = 0;
                this.showToggle = false;
                return;
            }

            const full = this.offices.join(', ');

            if (this.expanded) {
                this.visibleCount = total;
                this.visibleHtml = this.renderHtml(this.offices);
                // Only keep See less when something was actually truncated.
                this.showToggle = !this.fitsTwoLines(full);
                return;
            }

            if (this.fitsTwoLines(full)) {
                this.visibleCount = total;
                this.visibleHtml = this.renderHtml(this.offices);
                this.showToggle = false;
                return;
            }

            // One long office name: truncate at a word boundary (not a fake "See more").
            if (total === 1) {
                let best = 1;
                let lo = 1;
                let hi = full.length;
                while (lo <= hi) {
                    const mid = (lo + hi) >> 1;
                    const candidate = this.trimAtWord(full, mid) + ' See more';
                    if (this.fitsTwoLines(candidate)) {
                        best = mid;
                        lo = mid + 1;
                    } else {
                        hi = mid - 1;
                    }
                }
                const clipped = this.trimAtWord(full, Math.max(1, best)) || full.slice(0, Math.max(1, best));
                if (clipped === full) {
                    this.visibleCount = 1;
                    this.visibleHtml = this.renderHtml(this.offices);
                    this.showToggle = false;
                    return;
                }
                this.visibleCount = 1;
                this.visibleHtml = this.escapeHtml(clipped);
                this.showToggle = true;
                return;
            }

            // Largest prefix of complete office names that still fits in 2 lines with " See more".
            let best = 1;
            let lo = 1;
            let hi = total;
            while (lo <= hi) {
                const mid = (lo + hi) >> 1;
                const candidate = this.offices.slice(0, mid).join(', ') + ' See more';
                if (this.fitsTwoLines(candidate)) {
                    best = mid;
                    lo = mid + 1;
                } else {
                    hi = mid - 1;
                }
            }
            if (best < 1) best = 1;
            if (best >= total) {
                // Prefix would show every office — nothing left to reveal.
                this.visibleCount = total;
                this.visibleHtml = this.renderHtml(this.offices);
                this.showToggle = false;
                return;
            }

            this.visibleCount = best;
            this.visibleHtml = this.renderHtml(this.offices.slice(0, best));
            this.showToggle = true;
        },
        toggle() {
            this.expanded = !this.expanded;
            this.$nextTick(() => this.sync());
        },
    };
};

/**
 * Dcs text clamp.
 *
 * @param {*} text
 */
window.dcsTextClamp = function (text) {
    return {
        fullText: String(text ?? '').trim(),
        expanded: false,
        visibleText: '',
        showToggle: false,
        _ro: null,
        init() {
            this.visibleText = this.fullText;
            this.showToggle = false;
            this.$nextTick(() => {
                this.sync();
                if (typeof ResizeObserver !== 'undefined') {
                    this._ro = new ResizeObserver(() => {
                        if (!this.expanded) this.sync();
                    });
                    if (this.$refs.view) this._ro.observe(this.$refs.view);
                }
            });
        },
        lineHeightPx() {
            const view = this.$refs.view;
            if (!view) return 18;
            const lh = parseFloat(getComputedStyle(view).lineHeight);
            return Number.isFinite(lh) && lh > 0 ? lh : 18;
        },
        fitsTwoLines(value) {
            const measure = this.$refs.measure;
            const view = this.$refs.view;
            if (!measure || !view) return true;
            const width = view.clientWidth || view.offsetWidth;
            if (width < 8) return true;
            measure.style.width = width + 'px';
            measure.textContent = value;
            const maxH = (this.lineHeightPx() * 2) + 2;
            return measure.scrollHeight <= maxH;
        },
        trimAtWord(value, maxLen) {
            if (maxLen >= value.length) return value;
            let cut = value.slice(0, Math.max(1, maxLen));
            const space = cut.lastIndexOf(' ');
            if (space >= Math.floor(maxLen * 0.5)) {
                cut = cut.slice(0, space);
            }
            return cut.replace(/[,\s.;:!-]+$/g, '');
        },
        sync() {
            const full = this.fullText;
            if (!full) {
                this.visibleText = '';
                this.showToggle = false;
                return;
            }

            if (this.expanded) {
                this.visibleText = full;
                this.showToggle = !this.fitsTwoLines(full);
                return;
            }

            if (this.fitsTwoLines(full)) {
                this.visibleText = full;
                this.showToggle = false;
                return;
            }

            // Largest prefix that still fits in 2 lines with " See more".
            let best = 1;
            let lo = 1;
            let hi = full.length;
            while (lo <= hi) {
                const mid = (lo + hi) >> 1;
                const candidate = this.trimAtWord(full, mid) + ' See more';
                if (this.fitsTwoLines(candidate)) {
                    best = mid;
                    lo = mid + 1;
                } else {
                    hi = mid - 1;
                }
            }
            if (best < 1) best = 1;
            this.visibleText = this.trimAtWord(full, best);
            if (!this.visibleText) {
                this.visibleText = full.slice(0, Math.max(1, best));
            }
            this.showToggle = true;
        },
        toggle() {
            this.expanded = !this.expanded;
            this.$nextTick(() => this.sync());
        },
    };
};
</script>
