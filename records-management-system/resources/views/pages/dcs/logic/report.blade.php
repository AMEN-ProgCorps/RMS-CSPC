<?php

namespace App\Helpers;

use App\Services\DocumentStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportHelper
{
    // ════════════════════════════════════════════
    // REPORT DEFINITIONS
    // ════════════════════════════════════════════

    private function getReportCategories(): array
    {
        return [
            'masterlist' => [
            'label' => 'Document Masterlist',
            'icon'  => 'fa-solid fa-clipboard-list',
            'subs'  => [
                'internal_docs'  => 'Internal',
                'external_docs'  => 'External',
                'internal_forms' => 'Internal Forms',
                'forms'          => 'Forms',
                'logbooks'       => 'Logbooks',
            ],
        ],
            'monitoring' => [
                'label' => 'Monitoring Reports',
                'icon'  => 'fa-solid fa-chart-line',
                'subs'  => [
                    'internal_docs'  => 'Internal',
                    'external_docs'  => 'External',
                    'internal_forms' => 'Internal Forms',
                    'forms'          => 'Forms',
                    'logbooks'       => 'Logbooks',
                    'drf'            => 'DRF',
                    'dcn'            => 'DCN',
                ],
            ],
            'opcr' => [
                'label' => 'OPCR Targets (PMT Report Accomplishment Evidence)',
                'icon'  => 'fa-solid fa-bullseye',
                'subs'  => [
                    'update_masterlist'     => 'Updating of Masterlist',
                    'issuance_internal'     => 'Issuance of Controlled Internal Documents',
                    'issuance_external'     => 'Issuance of Controlled External Documents',
                    'control_forms'         => 'Controlling of Forms',
                    'control_logbooks'      => 'Controlling of Logbooks',
                    'control_internal_forms'=> 'Controlling of Internal Forms',
                ],
            ],
            'others' => [
                'label' => 'Others',
                'icon'  => 'fa-solid fa-folder-open',
                'subs'  => [
                    'general' => 'General Report',
                ],
            ],
        ];
    }

    /** Report sub-tab → parent doc type ID (dcs_doc_types). */
    private function getDocTypeParentMap(): array
    {
        return RegisterQueryHelper::parentTypeIdMap();
    }

    /** Parent + child doc type IDs for a report sub-tab. */
    private function getTypeIdsForSubTab(?string $sub): ?array
    {
        $parentId = RegisterQueryHelper::parentTypeIdMap()[$sub] ?? null;
        if (!$parentId) {
            return null;
        }

        return DB::table('dcs_doc_types')
            ->where(function ($q) use ($parentId) {
                $q->where('id', $parentId)->orWhere('parent_id', $parentId);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function resolveDateRange(Request $request): array
    {
        $period   = $request->input('period', 'annually');
        $asOf     = $request->input('as_of');
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');

        if ($period === 'custom') {
            return [$dateFrom ?: null, $dateTo ?: null, $asOf ?: null, 'custom'];
        }

        $end = $asOf
            ? \Carbon\Carbon::parse($asOf)->startOfDay()
            : \Carbon\Carbon::now('Asia/Manila')->startOfDay();

        if ($period === 'all') {
            return [null, $end->toDateString(), $end->toDateString(), 'all'];
        }

        switch ($period) {
            case 'weekly':
                $start = $end->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
                break;
            case 'monthly':
                $start = $end->copy()->startOfMonth();
                break;
            case 'quarterly':
                $start = $end->copy()->firstOfQuarter();
                break;
            case 'annually':
            default:
                $start = $end->copy()->startOfYear();
                $period = 'annually';
                break;
        }

        return [$start->toDateString(), $end->toDateString(), $end->toDateString(), $period];
    }

    private function periodLabel(string $period): string
    {
        return match ($period) {
            'weekly'    => 'This week',
            'monthly'   => 'This month',
            'quarterly' => 'This quarter',
            'annually'  => 'This year',
            'all'       => 'Up to date',
            default     => 'Custom dates',
        };
    }

    private function normalizedFormYear(mixed $year): ?int
    {
        $year = trim((string) $year);
        if ($year === '' || ! preg_match('/^\d{4}$/', $year)) {
            return null;
        }

        return (int) $year;
    }

    /** Print the same HTML the preview uses, so the downloaded PDF matches print. */
    private function renderPreviewPdf(string $html): ?string
    {
        $binary = null;
        foreach (['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable'] as $path) {
            if (is_executable($path)) {
                $binary = $path;
                break;
            }
        }
        if ($binary === null) {
            return null;
        }

        $dir = sys_get_temp_dir();
        $id = bin2hex(random_bytes(8));
        $htmlFile = $dir.'/ml-'.$id.'.html';
        $pdfFile = $dir.'/ml-'.$id.'.pdf';
        file_put_contents($htmlFile, $html);

        $cmd = 'HOME='.escapeshellarg($dir).' '.implode(' ', [
            escapeshellarg($binary),
            '--headless=new',
            '--disable-gpu',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--no-pdf-header-footer',
            '--print-to-pdf-no-header',
            '--print-to-pdf='.escapeshellarg($pdfFile),
            escapeshellarg($htmlFile),
        ]);

        exec($cmd.' 2>/dev/null', $unused, $code);
        $pdf = ($code === 0 && is_file($pdfFile)) ? file_get_contents($pdfFile) : false;
        @unlink($htmlFile);
        @unlink($pdfFile);

        return is_string($pdf) && $pdf !== '' ? $pdf : null;
    }

    /** CSPC form code shown on the report letterhead (right of blue rule). */
    private function letterNumberForReport(?string $category, ?string $sub): string
    {
        return match ($sub) {
            'drf' => 'CSPC-F-DCC-06',
            'dcn' => 'CSPC-F-DCC-01',
            'internal_docs' => 'CSPC-F-DCC-03',
            default => 'CSPC-F-DCC-03',
        };
    }

    private function parseReportFilters(Request $request): array
    {
        $raw = $request->input('sub_type_ids', '');
        $subTypeEmpty = $request->boolean('sub_type_empty');
        if (is_array($raw)) {
            $subTypeIds = array_filter(array_map('intval', $raw));
        } elseif (is_string($raw) && $raw !== '') {
            $subTypeIds = array_filter(array_map('intval', explode(',', $raw)));
        } else {
            $subTypeIds = [];
        }

        $uiSubRaw = $request->input('ui_sub_type_ids', '');
        if (is_array($uiSubRaw)) {
            $uiSubTypeIds = array_filter(array_map('intval', $uiSubRaw));
        } elseif (is_string($uiSubRaw) && $uiSubRaw !== '') {
            $uiSubTypeIds = array_filter(array_map('intval', explode(',', $uiSubRaw)));
        } else {
            $uiSubTypeIds = [];
        }

        return [
            'originator'       => $request->input('originator'),
            'source_unit'      => $request->input('source_unit'),
            'status'           => $request->input('status'),
            'rev_no'           => $request->input('rev_no'),
            'revision_status'  => $request->input('revision_status'),
            'sub_type_ids'     => $subTypeIds,
            'sub_type_empty'   => $subTypeEmpty,
            'ui_doc_type'      => $request->input('ui_doc_type'),
            'ui_sub_type_ids'  => $uiSubTypeIds,
            'form_year'        => $this->normalizedFormYear($request->input('form_year')),
            'sort'             => $this->normalizedSort($request->input('sort')),
            'sort_dir'         => $this->normalizedSortDir($request->input('sort_dir')),
        ];
    }

    private function normalizedSort(mixed $sort): string
    {
        $sort = trim((string) $sort);
        $allowed = ['effectivity_date', 'doc_no', 'doc_title', 'originator', 'rev_no', 'registered'];

        return in_array($sort, $allowed, true) ? $sort : 'effectivity_date';
    }

    private function normalizedSortDir(mixed $dir): string
    {
        return strtolower(trim((string) $dir)) === 'desc' ? 'desc' : 'asc';
    }

    private function sqlSortDir(array $filters): string
    {
        return ($filters['sort_dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    }

    private function orderByNullableDate($query, string $column, array $filters, ?string $timeColumn = null)
    {
        $dir = $this->sqlSortDir($filters);
        $driver = \Illuminate\Support\Facades\Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            $query->orderByRaw($column.' '.$dir.' NULLS LAST');
            if ($timeColumn) {
                $query->orderByRaw($timeColumn.' '.$dir.' NULLS LAST');
            }
        } else {
            $query->orderByRaw('CASE WHEN '.$column.' IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw($column.' '.$dir);
            if ($timeColumn) {
                $query->orderByRaw('CASE WHEN '.$timeColumn.' IS NULL THEN 1 ELSE 0 END')
                    ->orderByRaw($timeColumn.' '.$dir);
            }
        }

        return $query;
    }

    private function applySubTypeFilter($query, array $filters)
    {
        if (!empty($filters['sub_type_empty'])) {
            return $query->whereRaw('0 = 1');
        }

        if (empty($filters['sub_type_ids'])) {
            return $query;
        }

        return $query->whereIn('dr.sub_type_id', $filters['sub_type_ids']);
    }

    private function applyUiMonitoringFilters($query, array $filters)
    {
        if (!empty($filters['ui_doc_type'])) {
            $docType = $filters['ui_doc_type'];
            $query->whereExists(function ($q) use ($docType) {
                $q->select(DB::raw(1))->from('dcs_doc_types as dt')
                    ->whereColumn('dt.id', 'dr.doc_type_id')
                    ->where('dt.doc_type_name', $docType);
            });
        }

        if (!empty($filters['ui_sub_type_ids'])) {
            $ids = $filters['ui_sub_type_ids'];
            $query->whereIn('dr.sub_type_id', $ids);
        }

        return $query;
    }

    /** Apply report period on registration date, falling back to effectivity. */
    private function applyMasterlistPeriodFilter($query, ?string $dateFrom, ?string $dateTo)
    {
        if (! $dateFrom && ! $dateTo) {
            return $query;
        }

        $expr = 'COALESCE(ml.doc_registered_date, ml.effectivity_date)';

        $query->where(function ($q) use ($expr, $dateFrom, $dateTo) {
            $q->where(function ($inner) use ($expr, $dateFrom, $dateTo) {
                $inner->whereRaw($expr . ' IS NOT NULL');
                if ($dateFrom) {
                    $inner->whereRaw($expr . ' >= ?', [$dateFrom]);
                }
                if ($dateTo) {
                    $inner->whereRaw($expr . ' <= ?', [$dateTo]);
                }
            });
            if ($dateFrom === null && $dateTo !== null) {
                $q->orWhere(function ($inner) {
                    $inner->whereNull('ml.doc_registered_date')
                        ->whereNull('ml.effectivity_date');
                });
            }
        });

        return $query;
    }

    private function applyMasterlistCategoryFilter($query, ?string $sub)
    {
        $typeIds = $this->getTypeIdsForSubTab($sub);
        if (!$typeIds) {
            return $query;
        }

        $parentId = $this->getDocTypeParentMap()[$sub];
        $childIds = array_values(array_filter($typeIds, fn ($id) => $id !== $parentId));

        $query->where(function ($q) use ($typeIds, $childIds, $parentId) {
            $q->whereIn('ml.doc_type_id', $typeIds)
                ->orWhereExists(function ($r) use ($typeIds, $childIds, $parentId) {
                    $r->select(DB::raw(1))
                        ->from('dcs_document_requests as dr')
                        ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
                        ->whereColumn('dr.id', 'ml.request_id')
                        ->where(function ($r2) use ($typeIds, $childIds, $parentId) {
                            $r2->whereIn('dr.doc_type_id', $typeIds);
                            if ($childIds) {
                                $r2->orWhereIn('dr.sub_type_id', $childIds);
                            }
                            $r2->orWhere('st.parent_id', $parentId);
                        });
                });
        });

        return $query;
    }

    private function applyMasterlistSubTypeFilter($query, array $filters)
    {
        if (!empty($filters['sub_type_empty'])) {
            return $query->whereRaw('0 = 1');
        }

        if (empty($filters['sub_type_ids'])) {
            return $query;
        }

        $ids = $filters['sub_type_ids'];
        $query->whereExists(function ($q) use ($ids) {
            $q->select(DB::raw(1))
                ->from('dcs_document_requests as dr')
                ->whereColumn('dr.id', 'ml.request_id')
                ->whereIn('dr.sub_type_id', $ids);
        });

        return $query;
    }

    private function applyMasterlistCommonFilters($query, array $filters)
    {
        if (!empty($filters['originator'])) {
            $originator = $filters['originator'];
            $query->whereExists(function ($q) use ($originator) {
                $q->select(DB::raw(1))
                    ->from('dcs_originators as og')
                    ->whereColumn('og.id', 'ml.originator_id')
                    ->where('og.originator_name', $originator);
            });
        }

        if (!empty($filters['source_unit'])) {
            $officeId = $filters['source_unit'];
            $query->whereExists(function ($q) use ($officeId) {
                $q->select(DB::raw(1))
                    ->from('dcs_masterlist_source_offices as so')
                    ->whereColumn('so.masterlist_id', 'ml.id')
                    ->where('so.office_id', $officeId);
            });
        }

        if (!empty($filters['rev_no'])) {
            $query->where('ml.revise_no', $filters['rev_no']);
        }

        if (!empty($filters['revision_status']) && $filters['revision_status'] !== 'all') {
            if (! RegisterQueryHelper::supportsRevisionStatus()) {
                // Column missing: treat everything as latest; ignore obsolete filter.
                if ($filters['revision_status'] !== 'latest') {
                    $query->whereRaw('1 = 0');
                }
            } elseif ($filters['revision_status'] === 'latest') {
                RegisterQueryHelper::applyLatestRevisionStatus($query, 'ml');
            } else {
                $query->where('ml.revision_status', $filters['revision_status']);
            }
        }

        if (!empty($filters['status'])) {
            $query->whereExists(function ($q) use ($filters) {
                $q->select(DB::raw(1))
                    ->from('dcs_document_requests as dr')
                    ->whereColumn('dr.id', 'ml.request_id')
                    ->where('dr.approval_status', $filters['status']);
            });
        }

        return $query;
    }

    public static function payload(array $input): array
    {
        $response = (new self())->data(Request::create('/dcs/reports/data', 'GET', $input));

        return json_decode($response->getContent(), true) ?? [];
    }

    // ════════════════════════════════════════════
    // DATA — JSON for report table
    // ════════════════════════════════════════════

    public function data(Request $request)
    {
        try {
            $category = $request->input('category');
            $sub      = $request->input('sub');
            [$dateFrom, $dateTo] = array_slice($this->resolveDateRange($request), 0, 2);
            $filters = $this->parseReportFilters($request);

            if (!$category) {
                return response()->json(['error' => 'Category is required.'], 400);
            }

            switch ($category) {
                case 'masterlist':
                    return $this->masterlistData($sub, $dateFrom, $dateTo, $filters);
                case 'monitoring':
                    return $this->monitoringData($sub, $dateFrom, $dateTo, $filters);
                case 'opcr':
                    return $this->opcrData($sub, $dateFrom, $dateTo, $filters);
                case 'others':
                    return $this->othersData($dateFrom, $dateTo, $filters);
                default:
                    return response()->json(['error' => 'Invalid category.'], 400);
            }
        } catch (\Throwable $e) {
            $refId = uniqid('err_');
            \Log::error("Report data error [{$refId}]: " . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return response()->json([
                'error'   => "An error occurred (ref: {$refId})",
                'rows'    => [],
                'summary' => [],
            ], 500);
        }
    }

    private function applyCommonFilters($query, array $filters)
    {
        if (!empty($filters['originator'])) {
            $originator = $filters['originator'];
            $query->whereExists(function ($q) use ($originator) {
                $q->select(DB::raw(1))
                    ->from('dcs_masterlist_registration as ml')
                    ->join('dcs_originators as og', 'og.id', '=', 'ml.originator_id')
                    ->whereColumn('ml.request_id', 'dr.id')
                    ->where('og.originator_name', $originator);
            });
        }

        if (!empty($filters['source_unit'])) {
            $officeId = $filters['source_unit'];
            $query->whereExists(function ($q) use ($officeId) {
                $q->select(DB::raw(1))
                    ->from('dcs_masterlist_registration as ml')
                    ->join('dcs_masterlist_source_offices as so', 'so.masterlist_id', '=', 'ml.id')
                    ->whereColumn('ml.request_id', 'dr.id')
                    ->where('so.office_id', $officeId);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('dr.approval_status', $filters['status']);
        }

        if (!empty($filters['rev_no'])) {
            $revNo = $filters['rev_no'];
            $query->whereExists(function ($q) use ($revNo) {
                $q->select(DB::raw(1))
                    ->from('dcs_masterlist_registration as ml')
                    ->whereColumn('ml.request_id', 'dr.id')
                    ->where('ml.revise_no', $revNo);
            });
        }

        return $query;
    }

    /**
     * Keep latest or obsolete DocumentRequest rows, partitioned like visibility:
     * doc_no + doc_type_id + sub_type_id.
     */
    private function filterRequestsByRevisionStatus($docs, array $filters)
    {
        $status = $filters['revision_status'] ?? null;
        if (!$status || $status === 'all') {
            return $docs;
        }

        return $docs->filter(function ($doc) use ($status) {
            $ml = $doc->masterlistRegistration;
            if (!$ml || !$ml->doc_no) {
                return $status === 'latest';
            }

            $stored = strtolower(trim((string) ($ml->revision_status ?? '')));
            $isLatest = $stored !== 'obsolete';

            return $status === 'latest' ? $isLatest : $stored === $status;
        })->values();
    }

    private function sortMonitoringDocsByFormDate($docs)
    {
        return $this->sortMonitoringDocs($docs, []);
    }

    private function sortMonitoringDocs($docs, array $filters)
    {
        return $this->sortRevisionFamilies($docs, $filters, fn ($doc) => $doc->masterlistRegistration ?? null);
    }

    private function sortMasterlistFamilies($records, array $filters)
    {
        return $this->sortRevisionFamilies($records, $filters, fn ($ml) => $ml);
    }

    /**
     * Keep each document's revisions together. Date sorts use only the latest
     * document in the family, so an older revision does not move the group.
     */
    private function sortRevisionFamilies($rows, array $filters, callable $masterlistOf)
    {
        $sort = $filters['sort'] ?? 'effectivity_date';
        $descending = ($filters['sort_dir'] ?? 'asc') === 'desc';
        $families = $this->groupByRevisionFamily($rows, $masterlistOf);

        $sorted = $families->sort(function ($a, $b) use ($sort, $descending, $masterlistOf) {
            if ($this->isDateSort($sort)) {
                $cmp = $this->compareSortValues(
                    $this->familyDateSortValue($a, $sort, $masterlistOf, $descending),
                    $this->familyDateSortValue($b, $sort, $masterlistOf, $descending),
                    $descending
                );
                if ($cmp !== 0) {
                    return $cmp;
                }
                $cmp = strnatcasecmp(
                    (string) ($masterlistOf($this->latestInFamily($a, $masterlistOf))?->doc_no ?? ''),
                    (string) ($masterlistOf($this->latestInFamily($b, $masterlistOf))?->doc_no ?? '')
                );
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            $latestA = $this->latestInFamily($a, $masterlistOf);
            $latestB = $this->latestInFamily($b, $masterlistOf);

            return $this->compareSortValues(
                $this->familySortValue($latestA, $sort, $masterlistOf),
                $this->familySortValue($latestB, $sort, $masterlistOf),
                $descending
            );
        });

        $flat = collect();
        foreach ($sorted as $family) {
            $members = $this->orderFamilyMembers($family, $masterlistOf, $sort, $descending);
            $lead = true;
            foreach ($members as $row) {
                $ml = $masterlistOf($row);
                if ($lead && is_object($ml)) {
                    $ml->_family_lead = true;
                }
                $lead = false;
                $flat->push($row);
            }
        }

        return $flat->values();
    }

    private function groupByRevisionFamily($rows, callable $masterlistOf)
    {
        $items = collect($rows)->values();
        $parent = [];
        $find = function (string $key) use (&$parent, &$find): string {
            if (! isset($parent[$key])) {
                $parent[$key] = $key;
            }
            if ($parent[$key] !== $key) {
                $parent[$key] = $find($parent[$key]);
            }

            return $parent[$key];
        };
        $union = function (string $a, string $b) use ($find, &$parent): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        foreach ($items as $i => $row) {
            $ml = $masterlistOf($row);
            $docNo = strtolower(trim((string) ($ml?->doc_no ?? '')));
            $key = $docNo !== '' ? $docNo : 'row:'.$i;
            $find($key);
            $from = strtolower(trim((string) ($ml?->revised_from_doc_no ?? '')));
            if ($from !== '') {
                $union($key, $from);
            }
        }

        $families = [];
        foreach ($items as $i => $row) {
            $ml = $masterlistOf($row);
            $docNo = strtolower(trim((string) ($ml?->doc_no ?? '')));
            $key = $docNo !== '' ? $docNo : 'row:'.$i;
            $families[$find($key)][] = $row;
        }

        return collect(array_values($families));
    }

    private function latestInFamily(array $family, callable $masterlistOf)
    {
        $live = collect($family)->filter(fn ($row) => ! $this->isObsoleteMasterlist($masterlistOf($row)));
        $pool = $live->isNotEmpty() ? $live : collect($family);

        return $pool->sortByDesc(function ($row) use ($masterlistOf) {
            $ml = $masterlistOf($row);
            $stamp = $this->sortDateStamp($ml?->effectivity_date ?? null);

            return sprintf(
                '%010d-%s-%010d',
                (int) ($ml?->revise_no ?? 0),
                $stamp !== '' ? $stamp : '0000-00-00',
                (int) ($ml?->id ?? 0)
            );
        })->first();
    }

    private function isDateSort(string $sort): bool
    {
        return ! in_array($sort, ['doc_no', 'doc_title', 'originator', 'rev_no'], true);
    }

    private function familyDateSortValue(array $family, string $sort, callable $masterlistOf, bool $descending): string
    {
        $latest = $this->latestInFamily($family, $masterlistOf);

        return (string) $this->familySortValue($latest, $sort, $masterlistOf);
    }

    private function orderFamilyMembers(array $family, callable $masterlistOf, string $sort = 'effectivity_date', bool $descending = false)
    {
        if ($this->isDateSort($sort)) {
            $reference = $this->latestInFamily($family, $masterlistOf);
            $rest = collect($family)->reject(fn ($row) => $row === $reference)->sort(function ($a, $b) use ($masterlistOf, $sort, $descending) {
                $cmp = $this->compareSortValues(
                    $this->familySortValue($a, $sort, $masterlistOf),
                    $this->familySortValue($b, $sort, $masterlistOf),
                    $descending
                );
                if ($cmp !== 0) {
                    return $cmp;
                }

                $rev = ((int) ($masterlistOf($a)?->revise_no ?? 0)) <=> ((int) ($masterlistOf($b)?->revise_no ?? 0));
                if ($rev !== 0) {
                    return $descending ? -$rev : $rev;
                }

                $id = ((int) ($masterlistOf($a)?->id ?? 0)) <=> ((int) ($masterlistOf($b)?->id ?? 0));

                return $descending ? -$id : $id;
            })->values();

            return $reference === null ? $rest : collect([$reference])->merge($rest)->values();
        }

        return collect($family)->sort(function ($a, $b) use ($masterlistOf) {
            $aObs = $this->isObsoleteMasterlist($masterlistOf($a));
            $bObs = $this->isObsoleteMasterlist($masterlistOf($b));
            if ($aObs !== $bObs) {
                return $aObs ? 1 : -1;
            }
            $rev = ((int) ($masterlistOf($b)?->revise_no ?? 0)) <=> ((int) ($masterlistOf($a)?->revise_no ?? 0));
            if ($rev !== 0) {
                return $rev;
            }

            return ((int) ($masterlistOf($b)?->id ?? 0)) <=> ((int) ($masterlistOf($a)?->id ?? 0));
        })->values();
    }

    private function familySortValue($row, string $sort, callable $masterlistOf): string|int
    {
        if ($row === null) {
            return '';
        }

        $ml = $masterlistOf($row);
        $drf = is_object($row) ? ($row->documentRequestForm ?? null) : null;

        return match ($sort) {
            'doc_no' => trim((string) ($ml?->doc_no ?? '')),
            'doc_title' => trim((string) ($ml?->doc_title ?? $drf?->doc_title ?? '')),
            'originator' => trim((string) ($ml?->originator_name ?? '')),
            'rev_no' => (int) ($ml?->revise_no ?? 0),
            'registered' => $this->sortDateStamp($ml?->doc_registered_date ?? null),
            default => $this->sortDateStamp($ml?->effectivity_date ?? $drf?->drf_date ?? null),
        };
    }

    private function sortDateStamp(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function compareSortValues(string|int $va, string|int $vb, bool $descending): int
    {
        $aEmpty = $va === '';
        $bEmpty = $vb === '';
        if ($aEmpty && $bEmpty) {
            return 0;
        }
        if ($aEmpty) {
            return 1;
        }
        if ($bEmpty) {
            return -1;
        }
        $cmp = is_int($va) || is_int($vb)
            ? ((int) $va <=> (int) $vb)
            : strnatcasecmp((string) $va, (string) $vb);

        return $descending ? -$cmp : $cmp;
    }

    private function isObsoleteMasterlist(?object $ml): bool
    {
        if ($ml === null) {
            return false;
        }

        return strtolower(trim((string) ($ml->revision_status ?? ''))) === 'obsolete';
    }

    /**
     * Sequential row/item number.
     * Obsolete rows are unnumbered in mixed/all reports; obsolete-only reports number every row.
     */
    private function reportRowNumber(?object $ml, int &$counter, array $filters = []): int|string
    {
        $obsoleteOnly = ($filters['revision_status'] ?? 'all') === 'obsolete';

        if ($this->isObsoleteMasterlist($ml) && ! $obsoleteOnly) {
            return '';
        }

        $counter++;

        return $counter;
    }

    /**
     * One item number per revision family, on the first printed row of that group.
     */
    private function masterlistItemNumber(?object $ml, int &$counter, array $filters = []): int|string
    {
        $obsoleteOnly = ($filters['revision_status'] ?? 'all') === 'obsolete';
        if ($obsoleteOnly) {
            $counter++;

            return $counter;
        }

        if (! is_object($ml) || empty($ml->_family_lead)) {
            return '';
        }

        $counter++;

        return $counter;
    }

    /** Narrow request rows for report builders. Relations are loaded separately. */
    private function reportRequestRows($query)
    {
        return $query->orderByDesc('dr.id')->get([
            'dr.id',
            'dr.doc_type_id',
            'dr.sub_type_id',
            'dr.created_at',
        ]);
    }

    // ════════════════════════════════════════════
    // MASTERLIST REPORT
    // ════════════════════════════════════════════

    private function masterlistData(?string $sub, ?string $dateFrom, ?string $dateTo, array $filters = [])
    {
        // Source of truth: dcs_masterlist_registration (not document_requests alone).
        $query = DB::table('dcs_masterlist_registration as ml')
            ->whereNotNull('ml.doc_no')->where('ml.doc_no', '!=', '');
        $query->whereExists(function ($q) {
            $q->select(DB::raw(1))->from('dcs_document_requests as dr')
                ->whereColumn('dr.id', 'ml.request_id');
            RegisterQueryHelper::applyNotDeleted($q, 'dr');
            RegisterQueryHelper::applyRegisteredDocumentScope($q, 'dr');
        });

        $query = $this->applyMasterlistCategoryFilter($query, $sub);
        $query = $this->applyMasterlistSubTypeFilter($query, $filters);
        $query = $this->applyMasterlistPeriodFilter($query, $dateFrom, $dateTo);
        $query = $this->applyMasterlistCommonFilters($query, $filters);

        $records = RegisterQueryHelper::hydrateMasterlists($query->get(), ['types']);
        $records = $this->sortMasterlistFamilies($records, $filters);

        $counter = 0;
        $rows = $records->map(function ($ml) use (&$counter, $filters) {
            $doc = $ml->request;

            return [
                'ml_id'            => (int) ($ml->id ?? 0),
                'item_no'          => $this->masterlistItemNumber($ml, $counter, $filters),
                'doc_no'           => $ml->doc_no,
                'rev_no'           => (int) ($ml->revise_no ?? 0),
                'doc_title'        => $ml->doc_title,
                'effectivity_date' => $ml->effectivity_date
                    ? RegisterQueryHelper::formatSmartDate($ml->effectivity_date) : null,
                'originator'       => $ml->originator_name,
                'no_pages'         => $ml->no_pages,
                'doc_type'         => $doc?->docType?->doc_type_name ?? $ml->docType?->doc_type_name ?? 'N/A',
                'sub_type'         => $doc?->subType?->doc_type_name ?? null,
                'type_key'         => (int) ($doc?->doc_type_id ?? $ml->doc_type_id ?? 0)
                    . '|' . (int) ($doc?->sub_type_id ?? 0),
                'pdf_path'         => $ml->scanned_masterlist
                    ? RegisterQueryHelper::scanUrl($ml->scanned_masterlist, false) : null,
                'revision_status'  => strtolower(trim((string) ($ml->revision_status ?? ''))),
            ];
        })->values();

        $columns = [
            'item_no'          => 'Item<br>No.',
            'doc_no'           => 'Doc. No.',
            'rev_no'           => 'Rev<br>No.',
            'doc_title'        => 'Document Title',
            'effectivity_date' => 'Effectivity<br>Date',
            'originator'       => 'Originator',
            'no_pages'         => 'No.<br>of pages',
            'pdf_path'         => 'PDF FILE',
        ];

        return response()->json([
            'rows'       => $rows,
            'columns'    => $columns,
            'title'      => 'Document Masterlist',
            'total_rows' => $rows->count(),
        ]);
    }

    // ════════════════════════════════════════════
    // MONITORING REPORT
    // ════════════════════════════════════════════
    private function monitoringData(?string $sub, ?string $dateFrom, ?string $dateTo, array $filters = [])
    {
        if ($sub === 'drf') return $this->drfReport($dateFrom, $dateTo, $filters);
        if ($sub === 'dcn') return $this->dcnReport($dateFrom, $dateTo, $filters);
        if ($sub === 'internal_docs') return $this->documentMonitoringLog('Internal', $dateFrom, $dateTo, $filters);
        if ($sub === 'external_docs') return $this->documentMonitoringLog('External', $dateFrom, $dateTo, $filters);
        if (in_array($sub, ['internal_forms', 'forms', 'logbooks'])) {
            return $this->formsLogbooksMonitoringLog($sub, $dateFrom, $dateTo, $filters);
        }

        return response()->json(['error' => 'Unknown monitoring report type.'], 400);
    }

    /**
     * Forms & Logbooks monitoring — matches the 3-row grouped header layout
     */
    private function formsLogbooksMonitoringLog(string $sub, ?string $dateFrom, ?string $dateTo, array $filters = [])
    {
        $docTypeMap = [
            'internal_forms' => 'Internal Forms',
            'forms'          => 'Forms',
            'logbooks'       => 'Logbooks',
        ];
        $docTypeName = $docTypeMap[$sub] ?? 'Forms';

        $query = DB::table('dcs_document_requests as dr');
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyRegisteredDocumentScope($query, 'dr');
        $query->whereExists(function ($q) use ($docTypeName) {
            $q->select(DB::raw(1))->from('dcs_doc_types as dt')
                ->whereColumn('dt.id', 'dr.doc_type_id')
                ->where('dt.doc_type_name', $docTypeName);
        });

        $formYear = $filters['form_year'] ?? null;
        if ($formYear) {
            $query->whereExists(function ($q) use ($formYear) {
                $q->select(DB::raw(1))->from('dcs_document_request_form as drf')
                    ->whereColumn('drf.request_id', 'dr.id')
                    ->whereYear('drf.drf_date', $formYear);
            });
        } elseif ($dateFrom || $dateTo) {
            $query->whereExists(function ($q) use ($dateFrom, $dateTo) {
                $q->select(DB::raw(1))->from('dcs_masterlist_registration as ml')
                    ->whereColumn('ml.request_id', 'dr.id');
                if ($dateFrom) {
                    $q->whereDate('ml.doc_registered_date', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->whereDate('ml.doc_registered_date', '<=', $dateTo);
                }
            });
        }

        $query = $this->applyCommonFilters($query, $filters);
        $query = $this->applySubTypeFilter($query, $filters);
        $query = $this->applyUiMonitoringFilters($query, $filters);

        $docs = RegisterQueryHelper::hydrateRequests($this->reportRequestRows($query), [
            'types', 'masterlist', 'sourceOffices', 'drf', 'dcn', 'distribution',
        ]);
        $docs = $this->filterRequestsByRevisionStatus($docs, $filters);
        $docs = $this->sortMonitoringDocs($docs, $filters);
        $remarksByRequest = $this->monitoringRemarksByRequestId($docs);
        $forwardedByRequest = $this->monitoringForwardedDrrByRequestId($docs);

        $counter = 0;
        $rows = $docs->map(function ($doc) use (&$counter, $filters, $remarksByRequest, $forwardedByRequest) {
            $ml  = $doc->masterlistRegistration;
            $drf = $doc->documentRequestForm;
            $dcn = $doc->documentChangeNotice;
            $dist = $doc->documentDistribution;

            // Date received
            $dateReceived = $drf && $drf->drf_date
                ? $this->formatMonitoringDate($drf->drf_date) : null;

            // Time received
            $timeReceived = $drf && $drf->drf_receipt_time
                ? $this->formatTime($drf->drf_receipt_time) : null;

            $source = $this->monitoringSourceAcronyms($ml);

            // Document number
            $docNumber = $ml ? $ml->doc_no : null;

            // Description
            $description = $ml ? $ml->doc_title : ($drf ? $drf->doc_title : null);

            $category = $this->monitoringNewOrRevised($ml, $dcn);

            // Masterlist registration date
            $mlRegDate = $ml && $ml->doc_registered_date
                ? $this->formatMonitoringDate($ml->doc_registered_date) : null;

            // Masterlist registration time
            $mlRegTime = $ml && $ml->doc_registered_time
                ? $this->formatTime($ml->doc_registered_time) : null;

            // Time spent 1 (mins) — stored masterlist time, else receipt to registration
            $timeSpent1 = ($ml && $ml->time_spent !== null && $ml->time_spent !== '')
                ? (int) $ml->time_spent
                : null;
            if ($timeSpent1 === null && $drf && $drf->drf_date && $drf->drf_receipt_time && $ml && $ml->doc_registered_date) {
                $start = $this->combineDateTime($drf->drf_date, $drf->drf_receipt_time);
                $end = $this->combineDateTime($ml->doc_registered_date, $ml->doc_registered_time);
                $timeSpent1 = ($start && $end) ? CalendarHelper::workingMinutesBetween($start, $end) : null;
            }

            // Time released date — from distribution when available
            $dist = $doc->documentDistribution;
            $dateReleased = $dist && $dist->doc_distribution_date_actual
                ? $this->formatMonitoringDate($dist->doc_distribution_date_actual) : null;

            // Time released time
            $timeReleased = $dist && $dist->doc_distribution_time_actual
                ? $this->formatTime($dist->doc_distribution_time_actual) : null;

            // Time spent 2 (mins) — stored distribution time, else masterlist receipt → distribution
            $timeSpent2 = ($dist && $dist->time_spent !== null && $dist->time_spent !== '')
                ? (int) $dist->time_spent
                : null;
            if ($timeSpent2 === null && $ml && $ml->doc_receipt_date && $dist && $dist->doc_distribution_date_actual) {
                $start = $this->combineDateTime($ml->doc_receipt_date, $ml->doc_receipt_time);
                $end = $this->combineDateTime($dist->doc_distribution_date_actual, $dist->doc_distribution_time_actual);
                $timeSpent2 = ($start && $end) ? CalendarHelper::workingMinutesBetween($start, $end) : null;
            }

            $forwardedDRR = (bool) ($forwardedByRequest[(int) $doc->id] ?? false);

            return [
                'request_id'    => (int) $doc->id,
                'no'            => $this->reportRowNumber($ml, $counter, $filters),
                'date_received' => $dateReceived,
                'time_received' => $timeReceived,
                'source'        => $source,
                'doc_number'    => $docNumber,
                'description'   => $description,
                'category'      => $category,
                'ml_reg_date'   => $mlRegDate,
                'ml_reg_time'   => $mlRegTime,
                'time_spent1'   => $timeSpent1,
                'date_released' => $dateReleased,
                'time_released' => $timeReleased,
                'time_spent2'   => $timeSpent2,
                'forwarded_drr' => $forwardedDRR,
                'remarks'       => $remarksByRequest[(int) $doc->id] ?? null,
                'pdf_path'      => $ml && $ml->scanned_masterlist
                    ? RegisterQueryHelper::scanUrl($ml->scanned_masterlist, false) : null,
            ];
        })->values();

        $subLabels = [
            'internal_forms' => 'Internal Forms',
            'forms'          => 'Forms',
            'logbooks'       => 'Logbooks',
        ];

        $title = 'Monitoring Reports (' . ($subLabels[$sub] ?? 'Forms') . ')';

        // Row 2 — sub-labels (for grouped columns only)
        $columns = [
            'no'            => 'No',
            'date_received' => 'Date',
            'time_received' => 'Time',
            'source'        => 'Source',
            'doc_number'    => 'Document Number',
            'description'   => 'Description',
            'category'      => 'Category',
            'ml_reg_date'   => 'Date',
            'ml_reg_time'   => 'Time',
            'time_spent1'   => 'Time Spent (Mins)',
            'date_released' => 'Date',
            'time_released' => 'Time',
            'time_spent2'   => 'Time Spent (Mins)',
            'forwarded_drr' => 'Forwarded for DRR?',
            'remarks'       => 'Remarks',
        ];

        // Row 1 — group headers (null = standalone, rowspan=2)
        $groupHeaders = [
            'no'            => null,
            'date_received' => 'Date Received',
            'time_received' => 'Date Received',
            'source'        => null,
            'doc_number'    => null,
            'description'   => null,
            'category'      => null,
            'ml_reg_date'   => 'Masterlist Registration',
            'ml_reg_time'   => 'Masterlist Registration',
            'time_spent1'   => null,
            'date_released' => 'Time Released',
            'time_released' => 'Time Released',
            'time_spent2'   => null,
            'forwarded_drr' => null,
            'remarks'       => null,
        ];

        return response()->json([
            'rows'          => $rows,
            'columns'       => $columns,
            'group_headers' => $groupHeaders,
            'title'         => $title,
            'total_rows'    => $rows->count(),
        ]);
    }

    /**
     * Shared monitoring log for Internal & External documents.
     * Matches the layout: No, Date Received, Document Time,
     * Registered Masterlist Time, Source, In-Charge, Control Number,
     * Subject Matter, Effectivity Date, DEADLINE, Date Released,
     * Days Spent, Remarks
     */
    private function documentMonitoringLog(string $docTypeName, ?string $dateFrom, ?string $dateTo, array $filters = [])
    {
        $query = DB::table('dcs_document_requests as dr');
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyRegisteredDocumentScope($query, 'dr');
        $query->whereExists(function ($q) use ($docTypeName) {
            $q->select(DB::raw(1))->from('dcs_doc_types as dt')
                ->whereColumn('dt.id', 'dr.doc_type_id')
                ->where('dt.doc_type_name', $docTypeName);
        });

        $formYear = $filters['form_year'] ?? null;
        if ($formYear) {
            $query->whereExists(function ($q) use ($formYear) {
                $q->select(DB::raw(1))->from('dcs_document_request_form as drf')
                    ->whereColumn('drf.request_id', 'dr.id')
                    ->whereYear('drf.drf_date', $formYear);
            });
        } elseif ($dateFrom || $dateTo) {
            $query->whereExists(function ($q) use ($dateFrom, $dateTo) {
                $q->select(DB::raw(1))->from('dcs_masterlist_registration as ml')
                    ->whereColumn('ml.request_id', 'dr.id');
                if ($dateFrom) {
                    $q->whereDate('ml.doc_registered_date', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->whereDate('ml.doc_registered_date', '<=', $dateTo);
                }
            });
        }

        $query = $this->applyCommonFilters($query, $filters);
        $query = $this->applySubTypeFilter($query, $filters);
        $query = $this->applyUiMonitoringFilters($query, $filters);

        $docs = RegisterQueryHelper::hydrateRequests($this->reportRequestRows($query), [
            'types', 'masterlist', 'sourceOffices', 'drf', 'dcn', 'distribution',
        ]);
        $docs = $this->filterRequestsByRevisionStatus($docs, $filters);
        $docs = $this->sortMonitoringDocs($docs, $filters);
        $remarksByRequest = $this->monitoringRemarksByRequestId($docs);

        $counter = 0;
        $rows = $docs->map(function ($doc) use (&$counter, $filters, $remarksByRequest) {
            $ml  = $doc->masterlistRegistration;
            $drf = $doc->documentRequestForm;
            $dcn = $doc->documentChangeNotice;
            $dist = $doc->documentDistribution;

            // DRF reference number
            $drfRef = $drf && $drf->drf_no ? $drf->drf_no : null;

            // Date received (document date)
            $dateReceived = $drf && $drf->drf_date
                ? $this->formatMonitoringDate($drf->drf_date) : null;

            // Time received
            $timeReceived = $drf && $drf->drf_receipt_time
                ? $this->formatTime($drf->drf_receipt_time) : null;

            // Registered to masterlist - date
            $dateRegistered = $ml && $ml->doc_registered_date
                ? $this->formatMonitoringDate($ml->doc_registered_date) : null;

            // Registered to masterlist - time
            $timeRegistered = $ml && $ml->doc_registered_time
                ? $this->formatTime($ml->doc_registered_time) : null;

            // Minutes spent — stored masterlist time, else receipt to registration
            $minsSpent = ($ml && $ml->time_spent !== null && $ml->time_spent !== '')
                ? (int) $ml->time_spent
                : null;
            if ($minsSpent === null && $drf && $drf->drf_date && $drf->drf_receipt_time && $ml && $ml->doc_registered_date) {
                $start = $this->combineDateTime($drf->drf_date, $drf->drf_receipt_time);
                $end = $this->combineDateTime($ml->doc_registered_date, $ml->doc_registered_time);
                $minsSpent = ($start && $end) ? CalendarHelper::workingMinutesBetween($start, $end) : null;
            }

            $source = $this->monitoringSourceAcronyms($ml);

            // Control number
            $controlNumber = $ml ? $ml->doc_no : null;

            // Subject matter
            $subjectMatter = $ml ? $ml->doc_title : ($drf ? $drf->doc_title : null);

            // Effectivity date
            $effectivityDate = $ml && $ml->effectivity_date
                ? $this->formatMonitoringDate($ml->effectivity_date) : null;

            // Days spent
            $daysSpent = null;
            if ($drf && $drf->drf_date && $ml && $ml->effectivity_date) {
                $start = $this->combineDateTime($drf->drf_date);
                $end = $this->combineDateTime($ml->effectivity_date);
                $daysSpent = ($start && $end) ? (int) $start->diffInDays($end) : null;
            }

            return [
                'request_id'       => (int) $doc->id,
                'no'               => $this->reportRowNumber($ml, $counter, $filters),
                'drf_no'           => $drfRef,
                'date_received'    => $dateReceived,
                'time_received'    => $timeReceived,
                'date_registered'  => $dateRegistered,
                'time_registered'  => $timeRegistered,
                'mins_spent'       => $minsSpent,
                'source'           => $source,
                'in_charge'        => $ml ? ($ml->originator_name ?: null) : null,
                'control_number'   => $controlNumber,
                'subject_matter'   => $subjectMatter,
                'effectivity_date' => $effectivityDate,
                'deadline'         => $ml && $ml->deadline
                    ? $this->formatMonitoringDate($ml->deadline) : 'N/A',
                'date_released'    => $dist && $dist->doc_distribution_date_actual
                    ? $this->formatMonitoringDate($dist->doc_distribution_date_actual) : null,
                'days_spent'       => $daysSpent,
                'remarks'          => $remarksByRequest[(int) $doc->id] ?? null,
                'pdf_path'         => $ml && $ml->scanned_masterlist
                    ? RegisterQueryHelper::scanUrl($ml->scanned_masterlist, false) : null,
            ];
        })->values();

        $title = $docTypeName === 'Internal'
            ? 'Monitoring Reports (Internal Documents)'
            : 'Monitoring Reports (External Documents)';

        // Row 2 — main column names
        $columns = [
            'no'               => 'No.',
            'drf_no'           => 'DRF',
            'date_received'    => 'Document',
            'time_received'    => 'Time',
            'date_registered'  => 'Date',
            'time_registered'  => 'Time',
            'mins_spent'       => 'Mins Spent',
            'source'           => 'Source',
            'in_charge'        => 'In charge',
            'control_number'   => 'Control Number',
            'subject_matter'   => 'Subject Matter',
            'effectivity_date' => 'Effectivity Date',
            'deadline'         => 'DEADLINE',
            'date_released'    => 'Date Released',
            'days_spent'       => 'Days Spent',
            'remarks'          => 'Remarks',
        ];

        // Row 1 — group headers (null = standalone, spans 2 rows)
        $groupHeaders = [
            'no'               => null,
            'drf_no'           => 'Date Received',
            'date_received'    => 'Date Received',
            'time_received'    => 'Date Received',
            'date_registered'  => 'Registered to Masterlist',
            'time_registered'  => 'Registered to Masterlist',
            'mins_spent'       => 'Registered to Masterlist',
            'source'           => null,
            'in_charge'        => null,
            'control_number'   => null,
            'subject_matter'   => null,
            'effectivity_date' => null,
            'deadline'         => null,
            'date_released'    => null,
            'days_spent'       => null,
            'remarks'          => null,
        ];

        return response()->json([
            'rows'          => $rows,
            'columns'       => $columns,
            'group_headers' => $groupHeaders,
            'title'         => $title,
            'total_rows'    => $rows->count(),
        ]);
    }

    private function drfReport(?string $dateFrom, ?string $dateTo, array $filters = [])
    {
        $query = DB::table('dcs_document_request_form as drf')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'drf.request_id')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->select('drf.*', 'dt.doc_type_name');
        RegisterQueryHelper::applyExcludeOfficeIntakeDrf($query, 'drf');
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyRegisteredDocumentScope($query, 'dr');

        $formYear = $filters['form_year'] ?? null;
        if ($formYear) {
            $query->whereYear('drf.drf_date', $formYear);
        } else {
            if ($dateFrom) {
                $query->where('drf.drf_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->where('drf.drf_date', '<=', $dateTo);
            }
        }

        $this->orderByNullableDate($query, 'drf.drf_date', $filters, 'drf.drf_receipt_time')
            ->orderBy('drf.id');
        $drfs = $query->get();

        $rows = $drfs->map(function ($drf, $index) {
            return [
                'item_no'          => $index + 1,
                'drf_no'           => $drf->drf_no,
                'drf_date'         => $drf->drf_date
                    ? RegisterQueryHelper::formatSmartDate($drf->drf_date) : null,
                'doc_title'        => $drf->doc_title,
                'receipt_date'     => $drf->drf_receipt_date
                    ? RegisterQueryHelper::formatSmartDate($drf->drf_receipt_date) : null,
                'receipt_time'     => $drf->drf_receipt_time
                    ? $this->formatTime($drf->drf_receipt_time) : null,
                'doc_type'         => $drf->doc_type_name ?: 'N/A',
                'pdf_path'         => $drf->scanned_drf
                    ? RegisterQueryHelper::scanUrl($drf->scanned_drf, false) : null,
            ];
        })->values();

        $columns = [
            'item_no'      => 'ITEM NO.',
            'drf_no'       => 'DRF NO.',
            'drf_date'     => 'DRF DATE',
            'doc_title'    => 'DOCUMENT TITLE',
            'receipt_date' => 'RECEIPT DATE',
            'receipt_time' => 'RECEIPT TIME',
            'doc_type'     => 'DOC TYPE',
            'pdf_path'     => 'SCANNED DRF',
        ];

        return response()->json([
            'rows'       => $rows,
            'columns'    => $columns,
            'title'      => 'DRF Monitoring Report',
            'total_rows' => $rows->count(),
        ]);
    }

    private function dcnReport(?string $dateFrom, ?string $dateTo, array $filters = [])
    {
        $query = DB::table('dcs_document_change_notice as dcn')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'dcn.request_id')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->select('dcn.*', 'dt.doc_type_name');
        RegisterQueryHelper::applyExcludeOfficeIntakeDcn($query, 'dcn');
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyRegisteredDocumentScope($query, 'dr');

        $formYear = $filters['form_year'] ?? null;
        if ($formYear) {
            $query->whereYear('dcn.dcn_date', $formYear);
        } else {
            if ($dateFrom) {
                $query->where('dcn.dcn_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->where('dcn.dcn_date', '<=', $dateTo);
            }
        }

        $this->orderByNullableDate($query, 'dcn.dcn_date', $filters, 'dcn.dcn_receipt_time')
            ->orderBy('dcn.id');
        $dcns = $query->get();
        $revsByDcn = DB::table('dcs_doc_revision')->whereIn('dcn_id', $dcns->pluck('id'))->orderBy('id')->get()->groupBy('dcn_id');

        $rows = $dcns->map(function ($dcn, $index) use ($revsByDcn) {
            $revisions = $revsByDcn->get($dcn->id, collect());
            $purpose = trim((string) ($dcn->brief_purpose ?? '')) ?: null;

            return [
                'item_no'          => $index + 1,
                'dcn_no'           => $dcn->dcn_no,
                'dcn_date'         => $dcn->dcn_date
                    ? RegisterQueryHelper::formatSmartDate($dcn->dcn_date) : null,
                'receipt_date'     => $dcn->dcn_receipt_date
                    ? RegisterQueryHelper::formatSmartDate($dcn->dcn_receipt_date) : null,
                'receipt_time'     => $dcn->dcn_receipt_time
                    ? $this->formatTime($dcn->dcn_receipt_time) : null,
                'purpose'          => $purpose,
                'doc_type'         => $dcn->doc_type_name ?: 'N/A',
                'revision_count'   => $revisions->count(),
                'pdf_path'         => $dcn->scanned_dcn
                    ? RegisterQueryHelper::scanUrl($dcn->scanned_dcn, false) : null,
            ];
        })->values();

        $columns = [
            'item_no'        => 'ITEM NO.',
            'dcn_no'         => 'DCN NO.',
            'dcn_date'       => 'DCN DATE',
            'receipt_date'   => 'RECEIPT DATE',
            'receipt_time'   => 'RECEIPT TIME',
            'purpose'        => 'PURPOSE',
            'doc_type'       => 'DOC TYPE',
            'revision_count' => 'REVISIONS',
            'pdf_path'       => 'SCANNED DCN',
        ];

        return response()->json([
            'rows'       => $rows,
            'columns'    => $columns,
            'title'      => 'DCN Monitoring Report',
            'total_rows' => $rows->count(),
        ]);
    }

    // ════════════════════════════════════════════
    // OPCR TARGETS
    // ════════════════════════════════════════════
    private function opcrData(?string $sub, ?string $dateFrom, ?string $dateTo, array $filters = [])
    {
        // Null dates = all-time (do not fall back to current year — that hides External docs).
        $startDate = $dateFrom ? \Carbon\Carbon::parse($dateFrom)->startOfDay() : null;
        $endDate   = $dateTo   ? \Carbon\Carbon::parse($dateTo)->endOfDay()   : null;

        $subLabel = $this->getReportCategories()['opcr']['subs'][$sub] ?? 'OPCR Targets';

        switch ($sub) {
            case 'update_masterlist':
                $docs = $this->getOpcrDocs($startDate, $endDate, null, $filters);
                break;
            case 'issuance_internal':
                $docs = $this->getOpcrDocs($startDate, $endDate, ['Internal'], $filters);
                break;
            case 'issuance_external':
                $docs = $this->getOpcrDocs($startDate, $endDate, ['External'], $filters);
                break;
            case 'control_forms':
                $docs = $this->getOpcrDocs($startDate, $endDate, ['Forms'], $filters);
                break;
            case 'control_logbooks':
                $docs = $this->getOpcrDocs($startDate, $endDate, ['Logbooks'], $filters);
                break;
            case 'control_internal_forms':
                $docs = $this->getOpcrDocs($startDate, $endDate, ['Internal Forms'], $filters);
                break;
            default:
                $docs = $this->getOpcrDocs($startDate, $endDate, null, $filters);
                break;
        }

        $docs = $this->filterRequestsByRevisionStatus($docs, $filters);
        $ratingsByRequest = DB::table('dcs_opcr_ratings')->whereIn('request_id', $docs->pluck('id'))
            ->where('sub_type', $sub)
            ->get()
            ->keyBy('request_id');

        // Layout variants from OPCR templates:
        // - date_only: Issuance Internal/External + Controlling of Internal Forms
        // - with_times: Controlling of Forms / Logbooks (Request Received + Released Date/Time)
        // - masterlist: Updating of Masterlist
        $layout = match ($sub) {
            'update_masterlist' => 'masterlist',
            'control_forms', 'control_logbooks' => 'with_times',
            default => 'date_only', // issuance_internal, issuance_external, control_internal_forms
        };

        $counter = 0;
        $rows = $docs->map(function ($doc) use ($ratingsByRequest, $layout, &$counter, $filters) {
            $ml = $doc->masterlistRegistration;
            $dist = $doc->documentDistribution;
            $drf = $doc->documentRequestForm;

            // Request / document received — prefer DRF receipt, else masterlist receipt
            $recvDateRaw = null;
            $recvTimeRaw = null;
            if ($drf && $drf->drf_receipt_date) {
                $recvDateRaw = $drf->drf_receipt_date;
                $recvTimeRaw = $drf->drf_receipt_time;
            } elseif ($ml && $ml->doc_receipt_date) {
                $recvDateRaw = $ml->doc_receipt_date;
                $recvTimeRaw = $ml->doc_receipt_time;
            } elseif ($ml && $ml->doc_registered_date) {
                $recvDateRaw = $ml->doc_registered_date;
                $recvTimeRaw = $ml->doc_registered_time;
            }

            $receivedAt = $recvDateRaw
                ? \Carbon\Carbon::parse($recvDateRaw)->startOfDay()
                : null;

            $dateReceived = $receivedAt ? $this->formatMonitoringDate($receivedAt) : null;
            $timeReceived = $recvTimeRaw ? $this->formatTime($recvTimeRaw) : null;

            $dateRegistered = ($ml && $ml->doc_registered_date)
                ? $this->formatMonitoringDate($ml->doc_registered_date)
                : null;
            $timeRegistered = ($ml && $ml->doc_registered_time)
                ? $this->formatTime($ml->doc_registered_time)
                : null;

            $releasedAt = null;
            $dateReleased = null;
            $timeReleased = null;
            if ($dist && $dist->doc_distribution_date_actual) {
                $releasedAt = \Carbon\Carbon::parse($dist->doc_distribution_date_actual)->startOfDay();
                $dateReleased = $this->formatMonitoringDate($releasedAt);
                $timeReleased = $dist->doc_distribution_time_actual
                    ? $this->formatTime($dist->doc_distribution_time_actual)
                    : null;
            }

            $compareEnd = $layout === 'masterlist'
                ? (($ml && $ml->doc_registered_date)
                    ? \Carbon\Carbon::parse($ml->doc_registered_date)->startOfDay()
                    : null)
                : $releasedAt;

            // Days Advance (+) / Delay (−) uses the two dates shown on the row:
            // masterlist = registered − received; all others = released − received.
            $daysDiff = null;
            $daysType = null;
            if ($receivedAt && $compareEnd) {
                $daysDiff = (int) $receivedAt->diffInDays($compareEnd, false);
            }
            if ($daysDiff !== null) {
                if ($daysDiff > 0) {
                    $daysType = 'advanced';
                } elseif ($daysDiff < 0) {
                    $daysType = 'delayed';
                } else {
                    $daysType = 'on_time';
                }
            }

            $opcrRating = $ratingsByRequest->get($doc->id);
            $docNo = $ml ? $ml->doc_no : null;
            // Editable remarks start empty — user enters them like Q/E/T/A (do not auto-fill DCN).
            $remarksOverride = ($opcrRating && isset($opcrRating->remarks_override) && $opcrRating->remarks_override !== null && $opcrRating->remarks_override !== '')
                ? $opcrRating->remarks_override
                : null;

            $row = [
                'no'             => $this->reportRowNumber($ml, $counter, $filters),
                'request_id'     => $doc->id,
                'control_number' => $docNo,
                'doc_number'     => $docNo,
                'date_received'  => $dateReceived,
                'days_diff'      => $daysDiff,
                'days_type'      => $daysType,
                'rating_q'       => $opcrRating?->rating_q !== null ? (int) $opcrRating->rating_q : null,
                'rating_e'       => $opcrRating?->rating_e !== null ? (int) $opcrRating->rating_e : null,
                'rating_t'       => $opcrRating?->rating_t !== null ? (int) $opcrRating->rating_t : null,
                'rating_a'       => $opcrRating?->rating_a !== null ? (int) $opcrRating->rating_a : null,
                'remarks'        => $remarksOverride,
                'remarks_override' => $remarksOverride,
                'pdf_path'       => $ml && $ml->scanned_masterlist
                    ? RegisterQueryHelper::scanUrl($ml->scanned_masterlist, false) : null,
            ];

            if ($layout === 'masterlist') {
                $row['time_received'] = $timeReceived;
                $row['date_registered'] = $dateRegistered;
                $row['time_registered'] = $timeRegistered;
            } else {
                $row['time_received'] = $timeReceived;
                $row['date_released'] = $dateReleased;
                $row['time_released'] = $timeReleased;
            }

            return $row;
        })->values();

        if ($layout === 'masterlist') {
            $columns = [
                'no'               => 'No.',
                'control_number'   => 'Control Number',
                'date_received'    => 'Date',
                'time_received'    => 'Time',
                'date_registered'  => 'Date',
                'time_registered'  => 'Time',
                'days_diff'        => 'Days',
                'rating_q'         => 'Q',
                'rating_e'         => 'E',
                'rating_t'         => 'T',
                'rating_a'         => 'A',
                'remarks'          => 'Remarks',
            ];
            $groupHeaders = [
                'no'               => null,
                'control_number'   => null,
                'date_received'    => 'Received',
                'time_received'    => 'Received',
                'date_registered'  => 'Registered to Masterlist',
                'time_registered'  => 'Registered to Masterlist',
                'days_diff'        => null,
                'rating_q'         => 'Ratings',
                'rating_e'         => 'Ratings',
                'rating_t'         => 'Ratings',
                'rating_a'         => 'Ratings',
                'remarks'          => null,
            ];
        } elseif ($layout === 'with_times') {
            // Controlling of Forms / Logbooks
            $columns = [
                'no'               => 'No.',
                'doc_number'       => 'Document Number',
                'date_received'    => 'Date',
                'time_received'    => 'Time',
                'date_released'    => 'Date',
                'time_released'    => 'Time',
                'days_diff'        => 'Days',
                'rating_q'         => 'Q',
                'rating_e'         => 'E',
                'rating_t'         => 'T',
                'rating_a'         => 'A',
                'remarks'          => 'Remarks',
            ];
            $groupHeaders = [
                'no'               => null,
                'doc_number'       => null,
                'date_received'    => 'Request Received',
                'time_received'    => 'Request Received',
                'date_released'    => 'Released',
                'time_released'    => 'Released',
                'days_diff'        => null,
                'rating_q'         => 'Ratings',
                'rating_e'         => 'Ratings',
                'rating_t'         => 'Ratings',
                'rating_a'         => 'Ratings',
                'remarks'          => null,
            ];
        } else {
            // Issuance Internal/External + Controlling of Internal Forms
            $columns = [
                'no'               => 'No.',
                'control_number'   => 'Control Number',
                'date_received'    => 'Date',
                'time_received'    => 'Time',
                'date_released'    => 'Date',
                'time_released'    => 'Time',
                'days_diff'        => 'Days',
                'rating_q'         => 'Q',
                'rating_e'         => 'E',
                'rating_t'         => 'T',
                'rating_a'         => 'A',
                'remarks'          => 'Remarks',
            ];
            $groupHeaders = [
                'no'               => null,
                'control_number'   => null,
                'date_received'    => 'Received',
                'time_received'    => 'Received',
                'date_released'    => 'Released',
                'time_released'    => 'Released',
                'days_diff'        => null,
                'rating_q'         => 'Ratings',
                'rating_e'         => 'Ratings',
                'rating_t'         => 'Ratings',
                'rating_a'         => 'Ratings',
                'remarks'          => null,
            ];
        }

        return response()->json([
            'rows'          => $rows,
            'columns'       => $columns,
            'group_headers' => $groupHeaders,
            'title'         => $subLabel,
            'total_rows'    => $rows->count(),
        ]);
    }

    private function getOpcrDocs($startDate, $endDate, ?array $docTypeNames, array $filters = [])
    {
        $query = DB::table('dcs_document_requests as dr')
            ->whereExists(function ($q) use ($startDate, $endDate) {
                $q->select(DB::raw(1))->from('dcs_masterlist_registration as ml')
                    ->whereColumn('ml.request_id', 'dr.id')
                    ->whereNotNull('ml.doc_no')
                    ->where('ml.doc_no', '!=', '');
                if ($startDate) {
                    $q->whereDate('ml.doc_registered_date', '>=', $startDate);
                }
                if ($endDate) {
                    $q->whereDate('ml.doc_registered_date', '<=', $endDate);
                }
            });
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyRegisteredDocumentScope($query, 'dr');

        if ($docTypeNames) {
            $query->whereExists(function ($q) use ($docTypeNames) {
                $q->select(DB::raw(1))->from('dcs_doc_types as dt')
                    ->whereColumn('dt.id', 'dr.doc_type_id')
                    ->whereIn('dt.doc_type_name', $docTypeNames);
            });
        }

        $query = $this->applyCommonFilters($query, $filters);
        $query = $this->applySubTypeFilter($query, $filters);

        return RegisterQueryHelper::hydrateRequests($this->reportRequestRows($query), [
            'types', 'masterlist', 'drf', 'distribution',
        ]);
    }

    private function ensureMonitoringRemarksTable(): void
    {
        if (! Schema::hasTable('dcs_monitoring_remarks')) {
            Schema::create('dcs_monitoring_remarks', function ($table) {
                $table->id();
                $table->unsignedBigInteger('request_id')->unique();
                $table->text('remarks')->nullable();
                $table->boolean('forwarded_drr')->default(false);
                $table->timestamps();
            });

            return;
        }

        if (! Schema::hasColumn('dcs_monitoring_remarks', 'forwarded_drr')) {
            Schema::table('dcs_monitoring_remarks', function ($table) {
                $table->boolean('forwarded_drr')->default(false);
            });
        }
    }

    private function monitoringNewOrRevised($ml, $dcn = null): string
    {
        $rev = (int) ($ml?->revise_no ?? 0);
        $from = trim((string) ($ml?->revised_from_doc_no ?? ''));
        if ($rev > 0 || $from !== '' || ($dcn && ($dcn->dcn_no ?? $dcn->id))) {
            return 'Revised';
        }

        return 'New';
    }

    private function monitoringForwardedDrrByRequestId($docs): array
    {
        $this->ensureMonitoringRemarksTable();
        $ids = collect($docs)->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
        if ($ids === []) {
            return [];
        }

        return DB::table('dcs_monitoring_remarks')
            ->whereIn('request_id', $ids)
            ->pluck('forwarded_drr', 'request_id')
            ->mapWithKeys(fn ($flag, $id) => [(int) $id => (bool) $flag])
            ->all();
    }

    private function monitoringRemarksByRequestId($docs): array
    {
        $this->ensureMonitoringRemarksTable();
        $ids = collect($docs)->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
        if ($ids === []) {
            return [];
        }

        return DB::table('dcs_monitoring_remarks')
            ->whereIn('request_id', $ids)
            ->pluck('remarks', 'request_id')
            ->mapWithKeys(fn ($remark, $id) => [(int) $id => $remark])
            ->all();
    }

    public function saveMonitoringRemark(int $requestId, $value): ?string
    {
        $this->ensureMonitoringRemarksTable();
        $remarks = $value === null || $value === '' ? null : (string) $value;
        $now = now();
        DB::table('dcs_monitoring_remarks')->updateOrInsert(
            ['request_id' => $requestId],
            ['remarks' => $remarks, 'updated_at' => $now, 'created_at' => $now]
        );

        return $remarks;
    }

    public function saveMonitoringForwardedDrr(int $requestId, bool $value): bool
    {
        $this->ensureMonitoringRemarksTable();
        $now = now();
        $existing = DB::table('dcs_monitoring_remarks')->where('request_id', $requestId)->first();
        if ($existing) {
            DB::table('dcs_monitoring_remarks')->where('request_id', $requestId)->update([
                'forwarded_drr' => $value,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('dcs_monitoring_remarks')->insert([
                'request_id' => $requestId,
                'remarks' => null,
                'forwarded_drr' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $value;
    }

    /** Persist a single OPCR rating/remarks field without clobbering the others. */
    public function saveOpcrRatingField(int $requestId, string $sub, string $field, $value)
    {
        $ratingFields = ['rating_q', 'rating_e', 'rating_t', 'rating_a'];
        $isRating = in_array($field, $ratingFields, true);
        $isRemarks = $field === 'remarks_override';

        if (! $isRating && ! $isRemarks) {
            return null;
        }

        if ($isRating) {
            if ($value === '' || $value === null) {
                $normalized = null;
            } else {
                $normalized = max(1, min(5, (int) $value));
            }
        } else {
            $normalized = ($value === '' || $value === null) ? null : (string) $value;
            if ($isRemarks && ! Schema::hasColumn('dcs_opcr_ratings', 'remarks_override')) {
                return $normalized;
            }
        }

        $attrs = [
            'request_id' => $requestId,
            'sub_type'   => $sub,
        ];
        $values = [
            $field => $normalized,
            'updated_at' => now(),
        ];

        if (DB::table('dcs_opcr_ratings')->where($attrs)->exists()) {
            DB::table('dcs_opcr_ratings')->where($attrs)->update($values);
        } else {
            $insert = $attrs + [
                'rating_q' => null,
                'rating_e' => null,
                'rating_t' => null,
                'rating_a' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('dcs_opcr_ratings', 'remarks_override')) {
                $insert['remarks_override'] = null;
            }
            $insert[$field] = $normalized;
            DB::table('dcs_opcr_ratings')->insert($insert);
        }

        return $normalized;
    }

    public function saveOpcrRatings(Request $request)
    {
        $request->validate([
            'request_id' => 'required|integer',
            'sub'        => 'required|string',
            'rating_q'   => 'nullable|integer|min:1|max:5',
            'rating_e'   => 'nullable|integer|min:1|max:5',
            'rating_t'   => 'nullable|integer|min:1|max:5',
            'rating_a'   => 'nullable|integer|min:1|max:5',
            'remarks_override' => 'nullable|string|max:2000',
        ]);

        $normalize = fn ($value) => ($value === '' || $value === null)
            ? null
            : max(1, min(5, (int) $value));

        $attrs = [
            'request_id' => $request->request_id,
            'sub_type'   => $request->sub,
        ];
        $values = [
            'rating_q' => $normalize($request->rating_q),
            'rating_e' => $normalize($request->rating_e),
            'rating_t' => $normalize($request->rating_t),
            'rating_a' => $normalize($request->rating_a),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('dcs_opcr_ratings', 'remarks_override')) {
            $remarks = $request->input('remarks_override');
            $values['remarks_override'] = ($remarks === '' || $remarks === null) ? null : $remarks;
        }
        if (DB::table('dcs_opcr_ratings')->where($attrs)->exists()) {
            DB::table('dcs_opcr_ratings')->where($attrs)->update($values);
        } else {
            DB::table('dcs_opcr_ratings')->insert($attrs + $values + ['created_at' => now()]);
        }

        RegisterPersistHelper::logAdminChange(
            'Saved OPCR ratings — request #' . $request->request_id . ' (' . $request->sub . ')'
        );

        return response()->json(['success' => true]);
    }

    // ════════════════════════════════════════════
    // OTHERS
    // ════════════════════════════════════════════

    private function othersData(?string $dateFrom, ?string $dateTo, array $filters = [])
    {
        $query = DB::table('dcs_document_requests as dr');
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyRegisteredDocumentScope($query, 'dr');

        if ($dateFrom || $dateTo) {
            $query->whereExists(function ($q) use ($dateFrom, $dateTo) {
                $q->select(DB::raw(1))->from('dcs_masterlist_registration as ml')
                    ->whereColumn('ml.request_id', 'dr.id');
                if ($dateFrom) {
                    $q->whereDate('ml.doc_registered_date', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->whereDate('ml.doc_registered_date', '<=', $dateTo);
                }
            });
        }

        $query = $this->applyCommonFilters($query, $filters);
        $query = $this->applySubTypeFilter($query, $filters);

        $docs = RegisterQueryHelper::hydrateRequests($this->reportRequestRows($query), [
            'types', 'masterlist', 'drf', 'dcn',
        ]);
        $docs = $this->filterRequestsByRevisionStatus($docs, $filters);
        $docs = $this->sortMonitoringDocs($docs, $filters);

        $counter = 0;
        $rows = $docs->map(function ($doc) use (&$counter, $filters) {
            $ml  = $doc->masterlistRegistration;
            $drf = $doc->documentRequestForm;
            $dcn = $doc->documentChangeNotice;

            $originator = $ml ? ($ml->originator_name ?: null) : null;

            $checklists = collect();
            if ($drf) $checklists->push('DRF');
            if ($dcn) $checklists->push('DCN');
            if ($ml)  $checklists->push('Masterlist');

            return [
                'item_no'         => $this->reportRowNumber($ml, $counter, $filters),
                'request_id'      => $doc->id,
                'doc_no'          => $ml ? $ml->doc_no : 'N/A',
                'doc_title'       => $ml ? $ml->doc_title : ($drf ? $drf->doc_title : 'N/A'),
                'rev_no'          => $ml ? (int) $ml->revise_no : 0,
                'doc_type'        => $doc->docType?->doc_type_name ?? 'N/A',
                'originator'      => $originator,
                'checklists'      => $checklists->implode(', '),
                'date_created'    => $doc->created_at
                    ? \Carbon\Carbon::parse($doc->created_at)->format('M d, Y h:i A') : null,
            ];
        })->values();

        $columns = [
            'item_no'      => 'ITEM NO.',
            'request_id'   => 'REQUEST ID',
            'doc_no'       => 'DOCUMENT NO.',
            'doc_title'    => 'DOCUMENT TITLE',
            'rev_no'       => 'REV.',
            'doc_type'     => 'DOC TYPE',
            'originator'   => 'ORIGINATOR',
            'checklists'   => 'CHECKLISTS',
            'date_created' => 'DATE CREATED',
        ];

        return response()->json([
            'rows'       => $rows,
            'columns'    => $columns,
            'title'      => 'General Report',
            'total_rows' => $rows->count(),
        ]);
    }

    // ════════════════════════════════════════════
    // EXPORT — Print-friendly HTML
    // ════════════════════════════════════════════

    public function export(Request $request)
    {
        RegisterQueryHelper::assertFullDcsUser('reports');
        $category = $request->get('category');
        $sub      = $request->get('sub');
        [$dateFrom, $dateTo, $asOf, $period] = $this->resolveDateRange($request);
        $format   = $request->get('format', 'html');
        $embed    = $request->boolean('embed');

        $filters = $this->parseReportFilters($request);
        
        if (!$category) {
            abort(400, 'Category is required.');
        }

        RegisterPersistHelper::logAdminChange(
            'Exported DCS report — ' . $category
            . ($sub ? '/' . $sub : '')
            . ' (' . $format . ')'
        );

        \App\Services\DcsAuditService::log(
            'report.export',
            'reports',
            null,
            null,
            ['category' => $category, 'sub' => $sub, 'format' => $format]
        );

        $data = $this->applySelectedColumns(
            $this->fetchReportData($category, $sub, $dateFrom, $dateTo, $filters),
            $request
        );
        $isPlainTable = in_array($category, ['monitoring', 'opcr', 'others'], true);
        $isMlPrint = $category === 'masterlist'
            && in_array($sub, ['internal_docs', 'external_docs', 'internal_forms', 'forms', 'logbooks'], true);
        $checkedType = match ($sub) {
            'external_docs' => 'external',
            'forms' => 'forms',
            'logbooks' => 'logbooks',
            default => 'internal',
        };

        $allRows = collect($data['rows'] ?? [])->values();
        $totalCount = $allRows->count();
        $rows = $allRows;
        $isFiltered = false;

        $selectedMlIds = collect(explode(',', (string) $request->get('ml_ids', '')))
            ->map(fn ($v) => (int) trim($v))
            ->filter(fn ($v) => $v > 0)
            ->values();
        if ($selectedMlIds->isNotEmpty()) {
            $rows = $allRows->filter(function ($row) use ($selectedMlIds) {
                $id = (int) (is_array($row) ? ($row['ml_id'] ?? 0) : ($row->ml_id ?? 0));

                return $selectedMlIds->contains($id);
            })->values();
            $isFiltered = true;
        }

        if ($request->has('rows') && $request->get('rows') !== 'none' && $request->get('rows') !== '') {
            $selectedIndices = collect(explode(',', $request->get('rows')))
                ->map(fn($v) => trim($v))
                ->filter(fn($v) => $v !== '' && is_numeric($v))
                ->map(fn($v) => (int) $v)
                ->values();

            $rows = $allRows->filter(function ($row, $idx) use ($selectedIndices) {
                return $selectedIndices->contains($idx);
            })->values();
            $isFiltered = true;
        }

        $categories = $this->getReportCategories();
        $catLabel   = $categories[$category]['label'] ?? '';
        $subLabel   = ($sub && isset($categories[$category]['subs'][$sub]))
                        ? $categories[$category]['subs'][$sub] : '';

        $filename = 'report-' . $category . '-' . now()->format('Y-m-d');

        $selectedSubTypeNames = [];
        if (!empty($filters['sub_type_ids'])) {
            $selectedSubTypeNames = DB::table('dcs_doc_types')->whereIn('id', $filters['sub_type_ids'])
                ->orderBy('id')
                ->pluck('doc_type_name')
                ->all();
        }

        $viewData = [
            'title'              => $data['title'],
            'columns'            => $data['columns'],
            'groupHeaders'       => $data['group_headers'] ?? [],
            'rows'               => $rows,
            'isFiltered'         => $isFiltered,
            'selectedCount'      => $rows->count(),
            'totalCount'         => $totalCount,
            'dateFrom'           => $dateFrom,
            'dateTo'             => $dateTo,
            'asOf'               => $asOf,
            'period'             => $period,
            'periodLabel'        => $this->periodLabel($period),
            'embed'              => $embed,
            'selectedSubTypeNames' => $selectedSubTypeNames,
            'activeSub'          => $sub,
            'activeCategory'     => $category,
            'autoPrint'          => $request->boolean('autoPrint'),
            'plainTable'         => $isPlainTable,
            'isMlInternal'       => $isMlPrint,
            'checkedType'        => $checkedType,
            'republic'           => 'Republic of the Philippines',
            'institutionName'    => 'Camarines Sur Polytechnic Colleges',
            'institutionAddress' => 'Nabua, Camarines Sur',
            'letterNumber'       => $this->letterNumberForReport($category, $sub),
            'footerLeft'         => 'Effectivity Date:',
            'footerCenter'       => 'Rev.',
            'footerRight'        => '',
            'footerEffectivity'  => in_array($sub, ['internal_forms', 'external_docs', 'forms', 'logbooks'], true) ? 'August 2021' : 'January 2025',
            'footerRev'          => in_array($sub, ['internal_forms', 'external_docs', 'forms', 'logbooks'], true) ? '3' : '4',
        ];

        // ── CSV ──
        if ($format === 'xlsx' || $format === 'csv' || $format === 'excel') {
            $csvContent = $this->buildCsvContent($data['columns'], $rows, $data['group_headers'] ?? []);
            $asExcel = in_array($format, ['xlsx', 'excel'], true);
            $this->archiveGeneratedReport(
                $csvContent,
                'csv',
                $category,
                $sub,
                $data,
                $rows,
                $dateFrom,
                $dateTo,
                $period,
                $this->archiveFiltersForFingerprint($request, $filters),
                $this->shouldArchiveGeneratedReport($request)
            );

            return response($csvContent, 200, [
                'Content-Type'        => $asExcel
                    ? 'application/vnd.ms-excel; charset=UTF-8'
                    : 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . ($asExcel ? '.xls' : '.csv') . '"',
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            ]);
        }

 
        // ── PDF ──
        if ($format === 'pdf') {
            $output = null;
            if ($isMlPrint) {
                $previewData = $viewData;
                $previewData['isPdf'] = false;
                $previewData['embed'] = true;
                $output = $this->renderPreviewPdf(
                    view('pages.dcs.reports.export-masterlist-internal', $previewData)->render()
                );
            }

            if ($output === null) {
            $viewData['isPdf'] = true;

            $html = view($isMlPrint ? 'pages.dcs.reports.export-masterlist-internal' : 'pages.dcs.reports.export', $viewData)->render();

            $options = new \Dompdf\Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'Helvetica');
            $options->set('dpi', 96);
            $options->set('isPhpEnabled', false);

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper(
                ($isMlPrint || $isPlainTable) ? 'letter' : 'a4',
                $isPlainTable ? 'landscape' : 'portrait'
            );
            $dompdf->render();

            if (! $isPlainTable && ! $isMlPrint) {
                $canvas  = $dompdf->getCanvas();
                $fm      = $dompdf->getFontMetrics();
                $font    = $fm->getFont('Helvetica');
                $w       = $canvas->get_width();
                $h       = $canvas->get_height();
                $footerY = $h - 18;

                $canvas->line(40, $footerY - 14, $w - 40, $footerY - 14, [13/255, 42/255, 122/255], 1.5);
                $canvas->page_text(40, $footerY, 'Effectivity Date:', $font, 9, [0, 0, 0], 0, 1, '');
                $centerText = 'Rev.';
                $centerW    = $fm->getTextWidth($centerText, $font, 9);
                $canvas->page_text(($w - $centerW) / 2, $footerY, $centerText, $font, 9, [0, 0, 0], 0, 1, '');
                $canvas->page_text($w - 130, $footerY, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 9, [0, 0, 0], 0, 1, '');
            }
            $output = $dompdf->output();
            }
            $this->archiveGeneratedReport(
                $output,
                'pdf',
                $category,
                $sub,
                $data,
                $rows,
                $dateFrom,
                $dateTo,
                $period,
                $this->archiveFiltersForFingerprint($request, $filters),
                $this->shouldArchiveGeneratedReport($request)
            );

            // OPCR / print: open blank window with embedded PDF so Chrome headers don't show the export URL
            if ($request->boolean('autoPrint')) {
                $b64 = base64_encode($output);
                $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title></title>
<style>html,body{margin:0;height:100%;overflow:hidden}embed{border:0;width:100%;height:100%}</style>
</head>
<body>
<script>
(function () {
  var b64 = "{$b64}";
  var html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title></title>'
    + '<style>html,body{margin:0;height:100%;overflow:hidden}embed{border:0;width:100%;height:100%}</style></head><body>'
    + '<embed type="application/pdf" src="data:application/pdf;base64,' + b64 + '">'
    + '</body></html>';
  var w = window.open('', '_blank');
  if (!w) {
    document.write(html);
    setTimeout(function () { window.print(); }, 700);
    return;
  }
  w.document.open();
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(function () {
    w.print();
    window.close();
  }, 800);
})();
</script>
</body>
</html>
HTML;

                return response($html, 200, [
                    'Content-Type'  => 'text/html; charset=UTF-8',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                ]);
            }

            $inline = $request->boolean('inline');

            return response($output, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '.pdf"',
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            ]);
        }

        // ── HTML (browser view or auto-print) ──
        return view($isMlPrint ? 'pages.dcs.reports.export-masterlist-internal' : 'pages.dcs.reports.export', $viewData);
    }

        // ════════════════════════════════════════════
    // SHARED DATA FETCHER — used by both data() and export()
    // Calls the existing private methods and extracts the array
    // ════════════════════════════════════════════

    private function fetchReportData(string $category, ?string $sub, ?string $dateFrom, ?string $dateTo, array $filters = []): array
    {
        switch ($category) {
            case 'masterlist':
                $response = $this->masterlistData($sub, $dateFrom, $dateTo, $filters);
                break;
            case 'monitoring':
                $response = $this->monitoringData($sub, $dateFrom, $dateTo, $filters);
                break;
            case 'opcr':
                $response = $this->opcrData($sub, $dateFrom, $dateTo, $filters);
                break;
            case 'others':
                $response = $this->othersData($dateFrom, $dateTo, $filters);
                break;
            default:
                return [
                    'title'      => 'Report',
                    'columns'    => [],
                    'rows'       => collect(),
                    'total_rows' => 0,
            ];
        }

        // Extract the JSON data from the response
        $data = $response->getData(true);

        return [
            'title'         => $data['title'] ?? 'Report',
            'columns'       => $data['columns'] ?? [],
            'group_headers' => $data['group_headers'] ?? [],
            'rows'          => collect($data['rows'] ?? []),
            'total_rows'    => $data['total_rows'] ?? 0,
        ];
    }

    private function shouldArchiveGeneratedReport(Request $request): bool
    {
        // Print-only should not create Manage Files copies; explicit downloads still archive.
        if ($request->boolean('autoPrint')) {
            return false;
        }

        if ($request->has('archive') && ! $request->boolean('archive')) {
            return false;
        }

        return true;
    }

    /**
     * Include the row and column selection in the fingerprint so partial exports stay distinct.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function archiveFiltersForFingerprint(Request $request, array $filters): array
    {
        $rowsParam = trim((string) $request->get('rows', ''));
        if ($rowsParam !== '') {
            $filters['_export_rows'] = $rowsParam;
        }

        $columnsParam = trim((string) $request->get('columns', ''));
        if ($columnsParam !== '') {
            $filters['_export_columns'] = $columnsParam;
        }

        return $filters;
    }

    /**
     * Keep export columns in the requested order when the UI sends a subset.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applySelectedColumns(array $data, Request $request): array
    {
        $raw = trim((string) $request->get('columns', ''));
        if ($raw === '') {
            return $data;
        }

        $wanted = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $columns = $data['columns'] ?? [];
        $filtered = [];
        foreach ($wanted as $key) {
            if (array_key_exists($key, $columns)) {
                $filtered[$key] = $columns[$key];
            }
        }
        if ($filtered === []) {
            return $data;
        }

        $data['columns'] = $filtered;
        $groups = $data['group_headers'] ?? [];
        if (is_array($groups) && $groups !== []) {
            $data['group_headers'] = array_intersect_key($groups, $filtered);
        }

        return $data;
    }

    private function archiveGeneratedReport(
        string $content,
        string $format,
        string $category,
        ?string $sub,
        array $data,
        $rows,
        $dateFrom,
        $dateTo,
        ?string $period,
        array $filters,
        bool $shouldArchive = true
    ): void {
        if (! $shouldArchive || trim($content) === '') {
            return;
        }

        $categories = $this->getReportCategories();
        $catLabel = $categories[$category]['label'] ?? ucfirst($category);
        $subLabel = ($sub && isset($categories[$category]['subs'][$sub]))
            ? $categories[$category]['subs'][$sub]
            : '';

        try {
            $stored = DocumentStorageService::storeGeneratedReport($content, $format, [
                'category'     => $category,
                'sub_category' => $sub,
                'title'        => trim(($data['title'] ?? $catLabel) . ($subLabel ? ' — ' . $subLabel : '')),
                'row_count'    => collect($rows)->count(),
                'filters'      => $filters,
                'date_from'    => $dateFrom,
                'date_to'      => $dateTo,
                'period'       => $period,
            ]);

            if (! empty($stored['reused'])) {
                RegisterPersistHelper::logAdminChange(
                    'Reused existing Manage Files report — ' . $category
                    . ($sub ? '/' . $sub : '')
                    . ' (' . $format . ', ' . ($stored['report_token'] ?? '') . ')'
                );
            }
        } catch (\Throwable $e) {
            // Never block report download if archiving fails (e.g. Drive or cache unavailable).
            try {
                logger()->warning('Failed to archive generated DCS report: ' . $e->getMessage());
            } catch (\Throwable) {
            }
        }
    }

    private function buildCsvContent(array $columns, $rows, array $groupHeaders = []): string
    {
        unset($columns['pdf_path']);
        $colKeys = array_keys($columns);

        if ($rows instanceof \Illuminate\Support\Collection) {
            $rows = $rows->toArray();
        }

        $handle = fopen('php://temp', 'r+');
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $hasGroups = collect($groupHeaders)->contains(fn ($g) => $g !== null && $g !== '');
        if ($hasGroups) {
            $top = [];
            $i = 0;
            $n = count($colKeys);
            while ($i < $n) {
                $key = $colKeys[$i];
                $group = $groupHeaders[$key] ?? null;
                if ($group === null || $group === '') {
                    $top[] = $columns[$key];
                    $i++;
                    continue;
                }
                $span = 1;
                while ($i + $span < $n && ($groupHeaders[$colKeys[$i + $span]] ?? null) === $group) {
                    $span++;
                }
                for ($s = 0; $s < $span; $s++) {
                    $top[] = $s === 0 ? $group : '';
                }
                $i += $span;
            }
            fputcsv($handle, $top);

            $sub = [];
            foreach ($colKeys as $key) {
                $group = $groupHeaders[$key] ?? null;
                $sub[] = ($group !== null && $group !== '') ? $columns[$key] : '';
            }
            fputcsv($handle, $sub);
        } else {
            fputcsv($handle, array_map(
                fn ($h) => trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $h)))),
                array_values($columns)
            ));
        }

        foreach ($rows as $row) {
            $line = [];
            foreach ($colKeys as $key) {
                $val = is_array($row) ? ($row[$key] ?? '') : ($row->$key ?? '');
                if ($key === 'forwarded_drr') {
                    $val = !empty($val) ? 'Yes' : 'No';
                }
                if ($key === 'days_diff' && $val !== null && $val !== '') {
                    $val = abs((int) $val);
                }
                $line[] = $val;
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
    }

    private function generateCsv(array $columns, $rows, string $filename, array $groupHeaders = []): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        unset($columns['pdf_path']);
        $colKeys = array_keys($columns);

        if ($rows instanceof \Illuminate\Support\Collection) {
            $rows = $rows->toArray();
        }

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($columns, $rows, $colKeys, $groupHeaders) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            $hasGroups = collect($groupHeaders)->contains(fn ($g) => $g !== null && $g !== '');
            if ($hasGroups) {
                $top = [];
                $i = 0;
                $n = count($colKeys);
                while ($i < $n) {
                    $key = $colKeys[$i];
                    $group = $groupHeaders[$key] ?? null;
                    if ($group === null || $group === '') {
                        $top[] = $columns[$key];
                        $i++;
                        continue;
                    }
                    $span = 1;
                    while ($i + $span < $n && ($groupHeaders[$colKeys[$i + $span]] ?? null) === $group) {
                        $span++;
                    }
                    for ($s = 0; $s < $span; $s++) {
                        $top[] = $s === 0 ? $group : '';
                    }
                    $i += $span;
                }
                fputcsv($handle, $top);

                $sub = [];
                foreach ($colKeys as $key) {
                    $group = $groupHeaders[$key] ?? null;
                    $sub[] = ($group !== null && $group !== '') ? $columns[$key] : '';
                }
                fputcsv($handle, $sub);
            } else {
                fputcsv($handle, array_map(
                    fn ($h) => trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $h)))),
                    array_values($columns)
                ));
            }

            foreach ($rows as $row) {
                $line = [];
                foreach ($colKeys as $key) {
                    $val = is_array($row) ? ($row[$key] ?? '') : ($row->$key ?? '');
                    if ($key === 'forwarded_drr') {
                        $val = !empty($val) ? 'Yes' : 'No';
                    }
                    if ($key === 'days_diff' && $val !== null && $val !== '') {
                        $val = abs((int) $val);
                    }
                    $line[] = $val;
                }
                fputcsv($handle, $line);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }

    private function formatTime($time): string
    {
        if (!$time) return '';

        try {
            return \Carbon\Carbon::parse($time)->format('g:i A');
        } catch (\Throwable $e) {
            return (string) $time;
        }
    }

    private function monitoringSourceAcronyms($ml): ?string
    {
        $labels = collect($ml?->sourceOffices ?? [])
            ->map(function ($row) {
                $code = strtoupper(trim((string) ($row->office?->office_code ?? '')));
                if ($code !== '') {
                    return $code;
                }

                return trim((string) ($row->office?->office_name ?? ''));
            })
            ->filter()
            ->unique()
            ->values();

        return $labels->isEmpty() ? null : $labels->implode(', ');
    }

    private function formatMonitoringDate($val): ?string
    {
        if (! $val) {
            return null;
        }

        try {
            $date = $val instanceof \Carbon\Carbon ? $val : \Carbon\Carbon::parse($val);

            return $date->format('M j, Y');
        } catch (\Throwable $e) {
            $raw = trim((string) $val);

            return $raw !== '' ? $raw : null;
        }
    }

    private function combineDateTime($date, $time = null): ?\Carbon\Carbon
    {
        if (!$date) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse(trim($date . ' ' . ($time ?: '')));
        } catch (\Throwable $e) {
            return null;
        }
    }

}