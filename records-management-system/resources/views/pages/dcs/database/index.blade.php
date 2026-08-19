<?php

use App\Helpers\RegisterQueryHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    public string $search = '';
    public string $docTypeId = 'all';
    public string $subTypeId = 'all';
    public string $originator = '';
    public string $sourceUnit = '';
    public string $status = '';
    public string $revisionStatus = 'all';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $revNo = '';
    public int $page = 1;
    public string $notice = '';
    public bool $exportAllColumns = false;

    public function mount(): void
    {
        $type = request('type');
        if ($type !== null && $type !== '') {
            $this->docTypeId = (string) $type;
        }
        $revision = request('revision');
        if (in_array($revision, ['all', 'latest', 'obsolete'], true)) {
            $this->revisionStatus = $revision;
        }
        $search = request('search');
        if (is_string($search) && trim($search) !== '') {
            $this->search = trim($search);
        }
    }

    public function updated($name): void
    {
        if ($name !== 'page') {
            $this->page = 1;
        }
    }

    public function setType(string $id): void
    {
        $this->docTypeId = $id;
        $this->page = 1;
    }

    public function resetFilters(): void
    {
        $this->subTypeId = 'all';
        $this->originator = '';
        $this->sourceUnit = '';
        $this->status = '';
        $this->revisionStatus = 'all';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->revNo = '';
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

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
            'Retrieval On File',
            'Retrieval Actual',
            'Retrieved Office(s)',
            'Scanned Ret.',
        ];
        $mainIndexes = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 21];
        $headers = $this->exportAllColumns
            ? $allHeaders
            : array_values(array_intersect_key($allHeaders, array_flip($mainIndexes)));

        $filename = 'dcs-inventory-' . now()->format('Y-m-d') . '.csv';
        $full = $this->exportAllColumns;

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

    public function with(): array
    {
        return array_merge($this->catalog(), ['list' => $this->listing()]);
    }

    private function catalog(): array
    {
        return [
            'docTypes' => DB::table('dcs_doc_types')->whereNull('parent_id')->get(),
            'subTypes' => DB::table('dcs_doc_types')->whereNotNull('parent_id')->orderBy('doc_type_name')->get(),
            'offices' => DB::table('office')->where('is_active', true)->orderBy('office_name')->get(),
            'originators' => DB::table('dcs_originators')->orderBy('originator_name')->get(),
        ];
    }

    private function listing(bool $paginate = true): array
    {
        try {
            $query = DB::table('dcs_document_requests as dr');

            if ($this->docTypeId !== 'all' && $this->docTypeId !== '') {
                $query->where('dr.doc_type_id', $this->docTypeId);
            }

            if ($this->subTypeId !== 'all' && $this->subTypeId !== '') {
                $query->where('dr.sub_type_id', $this->subTypeId);
            }

            if ($this->search !== '') {
                $like = '%' . $this->search . '%';
                $query->where(function ($q) use ($like) {
                    $q->whereExists(function ($q2) use ($like) {
                        $q2->select(DB::raw(1))
                            ->from('dcs_document_request_form as drf')
                            ->whereColumn('drf.request_id', 'dr.id')
                            ->where(function ($q3) use ($like) {
                                $q3->where('drf.doc_title', 'ilike', $like)
                                    ->orWhere('drf.drf_no', 'ilike', $like);
                            });
                    })
                    ->orWhereExists(function ($q2) use ($like) {
                        $q2->select(DB::raw(1))
                            ->from('dcs_masterlist_registration as ml')
                            ->whereColumn('ml.request_id', 'dr.id')
                            ->where(function ($q3) use ($like) {
                                $q3->where('ml.doc_no', 'ilike', $like)
                                    ->orWhere('ml.doc_title', 'ilike', $like);
                            });
                    })
                    ->orWhereRaw('dr.id::text ilike ?', [$like]);
                });
            }

            if ($this->originator !== '') {
                $originator = $this->originator;
                $query->whereExists(function ($q) use ($originator) {
                    $q->select(DB::raw(1))
                        ->from('dcs_masterlist_registration as ml')
                        ->whereColumn('ml.request_id', 'dr.id')
                        ->where('ml.originator_name', $originator);
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

            $allRows = RegisterQueryHelper::hydrateRequests($query->orderByDesc('dr.id')->get())
                ->map(fn ($doc) => $this->mapDocument($doc));

            $grouped = $allRows->groupBy(function ($row) {
                if (! $row['doc_no'] || $row['doc_no'] === 'N/A') {
                    return 'no_ml_' . $row['request_id'];
                }

                return $row['doc_no'] . '||' . ($row['doc_type_id'] ?? 0) . '||' . ($row['sub_type_id'] ?? 0);
            });

            $groups = collect();
            foreach ($grouped as $rows) {
                $sorted = $rows->sortByDesc('rev_no')->values();
                $parent = $rows->first(fn ($r) => strtolower((string) $r['status']) === 'latest')
                    ?? $sorted->first();
                $children = $rows
                    ->filter(fn ($r) => $r['request_id'] !== $parent['request_id'])
                    ->sortByDesc('rev_no')
                    ->values();

                $groups->push([
                    'doc_no' => $parent['doc_no'] ?? 'N/A',
                    'parent' => $parent,
                    'children' => $children->all(),
                    'has_revisions' => $children->isNotEmpty(),
                    'revision_count' => $rows->count(),
                    'obsolete_count' => $children->count(),
                ]);
            }

            if ($this->revisionStatus === 'latest') {
                $groups = $groups
                    ->filter(fn ($g) => strtolower((string) ($g['parent']['status'] ?? '')) === 'latest')
                    ->map(function ($g) {
                        $g['children'] = [];

                        return $g;
                    })
                    ->values();
            } elseif ($this->revisionStatus === 'obsolete') {
                $groups = $groups
                    ->filter(fn ($g) => $g['has_revisions'])
                    ->flatMap(function ($g) {
                        return collect($g['children'])->map(function ($child) use ($g) {
                            $child['status'] = 'Obsolete';

                            return [
                                'doc_no' => $g['doc_no'],
                                'parent' => $child,
                                'children' => [],
                                'has_revisions' => false,
                                'revision_count' => $g['revision_count'],
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

            $groups = $groups->sort(function ($a, $b) use ($categoryOrder) {
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

                return ($b['parent']['request_id'] ?? 0) <=> ($a['parent']['request_id'] ?? 0);
            })->values();

            $total = $groups->count();
            $perPage = 50;
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min(max(1, $this->page), $lastPage);

            $data = $paginate
                ? $groups->slice(($page - 1) * $perPage, $perPage)->values()->all()
                : $groups->values()->all();

            return [
                'data' => $data,
                'total' => $total,
                'page' => $paginate ? $page : 1,
                'per_page' => $paginate ? $perPage : $total,
                'last_page' => $paginate ? $lastPage : 1,
            ];
        } catch (\Throwable $e) {
            $refId = uniqid('err_');
            Log::error("Database listing error [{$refId}]: " . $e->getMessage());

            return [
                'data' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => 50,
                'last_page' => 1,
                'error' => "An error occurred (ref: {$refId})",
            ];
        }
    }

    private function mapDocument(object $doc): array
    {
        $hasSyllabi = $doc->syllabi->isNotEmpty();
        $drf = ($doc->documentRequestForm && ! $hasSyllabi) ? $doc->documentRequestForm : null;
        $syllabiCourses = $doc->syllabi->map(function ($syl) {
            $name = $syl->course->course_name ?? null;
            $code = $syl->course->course_code ?? null;
            if ($name && $code) {
                return $code . ' — ' . $name;
            }

            return $name ?: $code;
        })->filter()->values();
        $ml = $doc->masterlistRegistration;
        $appr = $doc->approvalRecords?->first();
        $dcn = $doc->documentChangeNotice;
        $ret = $doc->documentRetrieval;
        $dist = $doc->documentDistribution;

        $dcnPurpose = null;
        if ($dcn && $dcn->revisions->isNotEmpty()) {
            $dcnPurpose = $dcn->revisions->sortBy('id')->first()->brief_purpose;
        }

        $retOffices = null;
        if ($ret && $ret->offices) {
            $retOffices = $ret->offices
                ->map(fn ($o) => $o->office?->office_name)
                ->filter()->implode(', ') ?: null;
        }

        $distOffices = null;
        if ($dist && $dist->offices) {
            $distOffices = $dist->offices
                ->map(fn ($o) => $o->office?->office_name)
                ->filter()->implode(', ') ?: null;
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

        return [
            'request_id' => $doc->id,
            'doc_type_id' => $doc->doc_type_id,
            'sub_type_id' => $doc->sub_type_id,
            'doc_type_name' => $doc->docType->doc_type_name ?? 'Uncategorized',
            'doc_no' => $ml ? $ml->doc_no : 'N/A',
            'rev_no' => $ml ? (int) $ml->revise_no : 0,
            'title' => ($ml && $ml->doc_title) ? $ml->doc_title : (($drf && $drf->doc_title) ? $drf->doc_title : 'N/A'),
            'effectivity' => ($ml && $ml->effectivity_date) ? Carbon::parse($ml->effectivity_date)->format('M d, Y') : null,
            'originator' => $ml?->originator_name,
            'pages' => $ml?->no_pages,
            'status' => $this->revisionStatusLabel($ml?->revision_status ?? null),
            'pdf_path' => ($ml && $ml->scanned_masterlist) ? '/storage/' . $ml->scanned_masterlist : null,
            'source_unit' => $sourceUnitName,
            'related' => $ml ? ($ml->relatedList ?? collect())->map(fn ($r) => [
                'doc_no' => $r->doc_no,
                'title' => $r->doc_title,
            ])->values() : [],
            'syllabi_courses' => $syllabiCourses,
            'approval_no' => $appr?->approval_no,
            'approval_date' => ($appr && $appr->approval_date) ? Carbon::parse($appr->approval_date)->format('M d, Y') : null,
            'deadline_date' => ($ml && $ml->deadline) ? Carbon::parse($ml->deadline)->format('M d, Y') : null,
            'deadline_diff' => $deadlineDiff !== null ? $deadlineDiff . ' days' : null,
            'ml_receipt_date' => ($ml && $ml->doc_receipt_date) ? Carbon::parse($ml->doc_receipt_date)->format('M d, Y') : null,
            'ml_receipt_time' => $ml && $ml->doc_receipt_time ? $this->formatTime($ml->doc_receipt_time) : null,
            'ml_register_date' => ($ml && $ml->doc_registered_date) ? Carbon::parse($ml->doc_registered_date)->format('M d, Y') : null,
            'ml_register_time' => $ml && $ml->doc_registered_time ? $this->formatTime($ml->doc_registered_time) : null,
            'ml_time_spent' => ($ml && $ml->time_spent !== null && $ml->time_spent !== '') ? (int) $ml->time_spent : null,
            'dcn_no' => $dcn?->dcn_no,
            'dcn_date' => ($dcn && $dcn->dcn_date) ? Carbon::parse($dcn->dcn_date)->format('M d, Y') : null,
            'dcn_receipt_date' => ($dcn && $dcn->dcn_receipt_date) ? Carbon::parse($dcn->dcn_receipt_date)->format('M d, Y') : null,
            'dcn_receipt_time' => $dcn && $dcn->dcn_receipt_time ? $this->formatTime($dcn->dcn_receipt_time) : null,
            'dcn_purpose' => $dcnPurpose,
            'dcn_scan' => ($dcn && $dcn->scanned_dcn) ? '/storage/' . $dcn->scanned_dcn : null,
            'drf_no' => $drf?->drf_no,
            'drf_date' => ($drf && $drf->drf_date) ? Carbon::parse($drf->drf_date)->format('M d, Y') : null,
            'drf_receipt_date' => ($drf && $drf->drf_receipt_date) ? Carbon::parse($drf->drf_receipt_date)->format('M d, Y') : null,
            'drf_receipt_time' => $drf && $drf->drf_receipt_time ? $this->formatTime($drf->drf_receipt_time) : null,
            'drf_scan' => ($drf && $drf->scanned_drf) ? '/storage/' . $drf->scanned_drf : null,
            'dist_onfile_date' => ($dist && $dist->doc_distribution_date_file) ? Carbon::parse($dist->doc_distribution_date_file)->format('M d, Y') : null,
            'dist_onfile_time' => $dist && $dist->doc_distribution_time_file ? $this->formatTime($dist->doc_distribution_time_file) : null,
            'dist_actual_date' => ($dist && $dist->doc_distribution_date_actual) ? Carbon::parse($dist->doc_distribution_date_actual)->format('M d, Y') : null,
            'dist_actual_time' => $dist && $dist->doc_distribution_time_actual ? $this->formatTime($dist->doc_distribution_time_actual) : null,
            'dist_offices' => $distOffices,
            'dist_scan' => ($dist && $dist->scanned_distribution) ? '/storage/' . $dist->scanned_distribution : null,
            'ret_onfile' => ($ret && $ret->doc_retrieval_date_file) ? Carbon::parse($ret->doc_retrieval_date_file)->format('M d, Y') : null,
            'ret_actual' => ($ret && $ret->doc_retrieval_date_actual) ? Carbon::parse($ret->doc_retrieval_date_actual)->format('M d, Y') : null,
            'ret_offices' => $retOffices,
            'ret_scan' => ($ret && $ret->scanned_retrieval) ? '/storage/' . $ret->scanned_retrieval : null,
        ];
    }

    private function revisionStatusLabel(?string $status): string
    {
        return strtolower((string) $status) === 'obsolete' ? 'Obsolete' : 'Latest';
    }

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
            $r['ret_onfile'] ?? '',
            $r['ret_actual'] ?? '',
            $r['ret_offices'] ?? '',
            $this->exportFileUrl($r['ret_scan'] ?? null),
        ];
    }

    private function exportFileUrl(?string $path): string
    {
        if (! $path) {
            return '';
        }

        return str_starts_with($path, 'http') ? $path : url($path);
    }

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
    $categoryState = [];
    foreach (($list['data'] ?? []) as $group) {
        $name = $group['parent']['doc_type_name'] ?? 'Uncategorized';
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $categoryState[$slug] = true;
    }
@endphp

<div x-data="{
    filterOpen: false,
    notice: @js($notice),
    open: { approval: true, deadline: true, masterlist: true, dcn: true, drf: true, distribution: true, retrieval: true },
    categories: {{ json_encode($categoryState) }},
    expandedRevs: {},
    openCourses: {},
    init() {
        try {
            const saved = JSON.parse(sessionStorage.getItem('dcs-db-expand') || '{}');
            if (saved.categories && typeof saved.categories === 'object') {
                this.categories = Object.assign({}, this.categories, saved.categories);
            }
            if (saved.expandedRevs && typeof saved.expandedRevs === 'object') {
                this.expandedRevs = saved.expandedRevs;
            }
            if (saved.openCourses && typeof saved.openCourses === 'object') {
                this.openCourses = saved.openCourses;
            }
            if (saved.open && typeof saved.open === 'object') {
                this.open = Object.assign({}, this.open, saved.open);
            }
        } catch (e) {}
    },
    persistExpand() {
        try {
            sessionStorage.setItem('dcs-db-expand', JSON.stringify({
                categories: this.categories,
                expandedRevs: this.expandedRevs,
                openCourses: this.openCourses,
                open: this.open,
            }));
        } catch (e) {}
    },
    get allCollapsed() {
        return Object.values(this.open).every(v => !v);
    },
    toggle(g) { this.open[g] = !this.open[g]; this.persistExpand(); },
    collapseAll() {
        const next = Object.values(this.open).some(Boolean);
        Object.keys(this.open).forEach(k => this.open[k] = !next);
        this.persistExpand();
    },
    toggleCategory(slug) { this.categories[slug] = !this.categories[slug]; this.persistExpand(); },
    toggleRev(id) { this.expandedRevs[id] = !this.expandedRevs[id]; this.persistExpand(); },
    toggleCourses(id) { this.openCourses[id] = !this.openCourses[id]; this.persistExpand(); },
    slug(name) { return String(name || 'uncategorized').toLowerCase().replace(/[^a-z0-9]+/g, '-') }
}">

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
        <button class="db-btn db-btn-primary" type="button" @click="filterOpen = false" wire:click="$refresh">Apply Filters</button>
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
            <button class="db-type-btn {{ $docTypeId === 'all' ? 'active' : '' }}" type="button" wire:click="setType('all')">ALL</button>
            @foreach($docTypes ?? [] as $type)
                <button class="db-type-btn {{ (string) $docTypeId === (string) $type->id ? 'active' : '' }}" type="button" wire:click="setType('{{ $type->id }}')">{{ strtoupper($type->doc_type_name) }}</button>
            @endforeach
        </div>
        <div class="db-controls-right">
            <div class="db-search-wrap">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search documents..." autocomplete="off">
            </div>
            <button class="db-collapse-btn" type="button" :class="{ 'is-collapsed': allCollapsed }" @click="collapseAll()" :title="allCollapsed ? 'Expand all columns' : 'Collapse all columns'">
                <i class="fa-solid" :class="allCollapsed ? 'fa-expand' : 'fa-compress'"></i>
                <span class="btn-label" x-text="allCollapsed ? 'Expand' : 'Collapse'"></span>
            </button>
            <button class="db-filter-btn" type="button" @click="filterOpen = true">
                <i class="fa-solid fa-filter"></i>
                <span class="btn-label">Filter</span>
            </button>
            <button class="db-export-btn" type="button" wire:click="export" title="Download CSV using the current filters">
                <i class="fa-solid fa-download"></i>
                <span class="btn-label">Export CSV</span>
            </button>
        </div>
    </section>

    <section class="db-table-wrap" wire:loading.class="is-loading">
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
                        <th rowspan="3" class="db-offices-col">SOURCE UNIT</th>
                        <th rowspan="3">RELATED DOCS</th>

                        <th rowspan="3" class="col-group-summary" data-group="approval" x-show="!open.approval" x-on:click="toggle('approval')">APPROVAL <span class="collapse-arrow">&#9654;</span></th>
                        <th colspan="2" class="col-group-approval col-group-expanded" x-show="open.approval" x-on:click="toggle('approval')">APPROVAL <span class="collapse-arrow">&#9664;</span></th>

                        <th rowspan="3" class="col-group-summary" data-group="deadline" x-show="!open.deadline" x-on:click="toggle('deadline')">DEADLINE <span class="collapse-arrow">&#9654;</span></th>
                        <th colspan="2" class="col-group-deadline col-group-expanded" x-show="open.deadline" x-on:click="toggle('deadline')">DEADLINE <span class="collapse-arrow">&#9664;</span></th>

                        <th rowspan="3" class="col-group-summary" data-group="masterlist" x-show="!open.masterlist" x-on:click="toggle('masterlist')">MASTERLIST <span class="collapse-arrow">&#9654;</span></th>
                        <th colspan="5" class="col-group-masterlist col-group-expanded" x-show="open.masterlist" x-on:click="toggle('masterlist')">MASTERLIST REGISTRATION <span class="collapse-arrow">&#9664;</span></th>

                        <th rowspan="3" class="col-group-summary" data-group="dcn" x-show="!open.dcn" x-on:click="toggle('dcn')">DCN <span class="collapse-arrow">&#9654;</span></th>
                        <th colspan="6" class="col-group-dcn col-group-expanded" x-show="open.dcn" x-on:click="toggle('dcn')">DOCUMENT CHANGE NOTICE <span class="collapse-arrow">&#9664;</span></th>

                        <th rowspan="3" class="col-group-summary" data-group="drf" x-show="!open.drf" x-on:click="toggle('drf')">DRF <span class="collapse-arrow">&#9654;</span></th>
                        <th colspan="5" class="col-group-drf col-group-expanded" x-show="open.drf" x-on:click="toggle('drf')">DOCUMENT REQUEST FORM <span class="collapse-arrow">&#9664;</span></th>

                        <th rowspan="3" class="col-group-summary" data-group="distribution" x-show="!open.distribution" x-on:click="toggle('distribution')">DISTRIBUTION <span class="collapse-arrow">&#9654;</span></th>
                        <th colspan="6" class="col-group-distribution col-group-expanded" x-show="open.distribution" x-on:click="toggle('distribution')">DISTRIBUTION <span class="collapse-arrow">&#9664;</span></th>

                        <th rowspan="3" class="col-group-summary" data-group="retrieval" x-show="!open.retrieval" x-on:click="toggle('retrieval')">RETRIEVAL <span class="collapse-arrow">&#9654;</span></th>
                        <th colspan="4" class="col-group-retrieval col-group-expanded" x-show="open.retrieval" x-on:click="toggle('retrieval')">RETRIEVAL <span class="collapse-arrow">&#9664;</span></th>
                    </tr>

                    <tr class="db-head-secondary">
                        <th colspan="2" class="col-group-approval col-group-expanded" x-show="open.approval">BOT/ADCO APPROVAL</th>
                        <th rowspan="2" class="col-group-deadline col-group-expanded" x-show="open.deadline">DATE</th>
                        <th rowspan="2" class="col-group-deadline col-group-expanded" x-show="open.deadline">DAY DIFF</th>
                        <th colspan="2" class="col-group-masterlist col-group-expanded" x-show="open.masterlist">DOCUMENT RECEIPT</th>
                        <th colspan="2" class="col-group-masterlist col-group-expanded" x-show="open.masterlist">DOCUMENT REGISTERED</th>
                        <th rowspan="2" class="col-group-masterlist col-group-expanded" x-show="open.masterlist">TIME SPENT (MINS)</th>
                        <th rowspan="2" class="col-group-dcn col-group-expanded" x-show="open.dcn">DCN NO.</th>
                        <th rowspan="2" class="col-group-dcn col-group-expanded" x-show="open.dcn">DCN DATE</th>
                        <th colspan="2" class="col-group-dcn col-group-expanded" x-show="open.dcn">DCN RECEIPT (ACTUAL)</th>
                        <th rowspan="2" class="col-group-dcn col-group-expanded" x-show="open.dcn">PURPOSE OF REVISION</th>
                        <th rowspan="2" class="col-group-dcn col-group-expanded" x-show="open.dcn">SCANNED DCN</th>
                        <th rowspan="2" class="col-group-drf col-group-expanded" x-show="open.drf">DRF NO.</th>
                        <th rowspan="2" class="col-group-drf col-group-expanded" x-show="open.drf">DRF DATE</th>
                        <th colspan="2" class="col-group-drf col-group-expanded" x-show="open.drf">DRF RECEIPT</th>
                        <th rowspan="2" class="col-group-drf col-group-expanded" x-show="open.drf">SCANNED DRF</th>
                        <th colspan="2" class="col-group-distribution col-group-expanded" x-show="open.distribution">DISTRIBUTION (ON FILE)</th>
                        <th colspan="2" class="col-group-distribution col-group-expanded" x-show="open.distribution">DISTRIBUTION (ACTUAL)</th>
                        <th rowspan="2" class="col-group-distribution col-group-expanded db-offices-col" x-show="open.distribution">RECEIVING OFFICE(S)</th>
                        <th rowspan="2" class="col-group-distribution col-group-expanded" x-show="open.distribution">SCANNED DIST.</th>
                        <th colspan="2" class="col-group-retrieval col-group-expanded" x-show="open.retrieval">DATE</th>
                        <th rowspan="2" class="col-group-retrieval col-group-expanded db-offices-col" x-show="open.retrieval">RETRIEVED OFFICE(S)</th>
                        <th rowspan="2" class="col-group-retrieval col-group-expanded" x-show="open.retrieval">SCANNED RET.</th>
                    </tr>

                    <tr class="db-head-tertiary">
                        <th class="col-group-approval col-group-expanded" x-show="open.approval">NO.</th>
                        <th class="col-group-approval col-group-expanded" x-show="open.approval">APPV. DATE</th>
                        <th class="col-group-masterlist col-group-expanded" x-show="open.masterlist">DATE</th>
                        <th class="col-group-masterlist col-group-expanded" x-show="open.masterlist">TIME</th>
                        <th class="col-group-masterlist col-group-expanded" x-show="open.masterlist">DATE</th>
                        <th class="col-group-masterlist col-group-expanded" x-show="open.masterlist">TIME</th>
                        <th class="col-group-dcn col-group-expanded" x-show="open.dcn">DATE</th>
                        <th class="col-group-dcn col-group-expanded" x-show="open.dcn">TIME</th>
                        <th class="col-group-drf col-group-expanded" x-show="open.drf">DATE</th>
                        <th class="col-group-drf col-group-expanded" x-show="open.drf">TIME</th>
                        <th class="col-group-distribution col-group-expanded" x-show="open.distribution">DATE</th>
                        <th class="col-group-distribution col-group-expanded" x-show="open.distribution">TIME</th>
                        <th class="col-group-distribution col-group-expanded" x-show="open.distribution">DATE</th>
                        <th class="col-group-distribution col-group-expanded" x-show="open.distribution">TIME</th>
                        <th class="col-group-retrieval col-group-expanded" x-show="open.retrieval">ON FILE</th>
                        <th class="col-group-retrieval col-group-expanded" x-show="open.retrieval">ACTUAL</th>
                    </tr>
                </thead>

                <tbody>
                    @php $lastCategory = null; @endphp
                    @forelse(($list['data'] ?? []) as $i => $group)
                        @php
                            $r = $group['parent'];
                            $catName = $r['doc_type_name'] ?? 'Uncategorized';
                            $catSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $catName));
                            $itemNo = (($list['page'] ?? 1) - 1) * ($list['per_page'] ?? 50) + $i + 1;
                            $revKey = 'rev-' . $r['request_id'];
                        @endphp

                        @if($catName !== $lastCategory)
                            <tr class="db-category-row">
                                <td colspan="47">
                                    <span class="db-category-toggle" :class="{ expanded: categories['{{ $catSlug }}'] }" x-on:click="toggleCategory('{{ $catSlug }}')">
                                        <span class="db-category-chevron" x-text="categories['{{ $catSlug }}'] ? '▼' : '▶'"></span>
                                        {{ strtoupper($catName) }}
                                    </span>
                                </td>
                            </tr>
                            @php $lastCategory = $catName; @endphp
                        @endif

                        <tr class="db-parent-row" x-show="categories['{{ $catSlug }}']">
                            <td>
                                @if(!empty($group['children']))
                                    <span class="db-expand-btn" :class="{ expanded: expandedRevs['{{ $revKey }}'] }" x-on:click.stop="toggleRev('{{ $revKey }}')" title="Show older revisions" x-text="expandedRevs['{{ $revKey }}'] ? '▼' : '▶'"></span>
                                @endif
                                {{ $itemNo }}
                            </td>
                            @include('pages.dcs.database._row', ['r' => $r])
                        </tr>

                        @if(!empty($r['syllabi_courses']) && count($r['syllabi_courses']))
                            <tr class="db-courses-row" x-show="categories['{{ $catSlug }}']">
                                <td colspan="47">
                                    <div class="db-courses-toggle" :class="{ expanded: openCourses['{{ $r['request_id'] }}'] }" x-on:click="toggleCourses('{{ $r['request_id'] }}')">
                                        <span class="db-courses-chevron" x-text="openCourses['{{ $r['request_id'] }}'] ? '▼' : '▶'"></span>
                                        <span class="db-courses-label"><i class="fa-solid fa-graduation-cap"></i> Courses ({{ count($r['syllabi_courses']) }})</span>
                                    </div>
                                    <div class="db-courses-list" x-show="openCourses['{{ $r['request_id'] }}']" x-cloak>
                                        @foreach($r['syllabi_courses'] as $course)
                                            <span class="db-course-chip">{{ $course }}</span>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endif

                        @foreach($group['children'] ?? [] as $ci => $child)
                            <tr class="db-child-row" x-show="categories['{{ $catSlug }}'] && expandedRevs['{{ $revKey }}']">
                                <td class="db-child-ind"><span class="db-child-dot"></span>{{ $itemNo }}.{{ $ci + 1 }}</td>
                                @include('pages.dcs.database._row', ['r' => $child])
                            </tr>
                            @if(!empty($child['syllabi_courses']) && count($child['syllabi_courses']))
                                <tr class="db-courses-row db-child-courses-row" x-show="categories['{{ $catSlug }}'] && expandedRevs['{{ $revKey }}']">
                                    <td colspan="47">
                                        <div class="db-courses-toggle" :class="{ expanded: openCourses['{{ $child['request_id'] }}'] }" x-on:click="toggleCourses('{{ $child['request_id'] }}')">
                                            <span class="db-courses-chevron" x-text="openCourses['{{ $child['request_id'] }}'] ? '▼' : '▶'"></span>
                                            <span class="db-courses-label"><i class="fa-solid fa-graduation-cap"></i> Courses ({{ count($child['syllabi_courses']) }})</span>
                                        </div>
                                        <div class="db-courses-list" x-show="openCourses['{{ $child['request_id'] }}']" x-cloak>
                                            @foreach($child['syllabi_courses'] as $course)
                                                <span class="db-course-chip">{{ $course }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    @empty
                        <tr><td colspan="47" style="text-align:center;padding:40px;color:#94a3b8;">No documents found</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(($list['last_page'] ?? 1) > 1)
            <div style="display:flex;gap:8px;align-items:center;justify-content:flex-end;padding:12px 16px;">
                @if(($list['page'] ?? 1) > 1)
                    <button type="button" class="db-btn" wire:click="goToPage({{ $list['page'] - 1 }})">Previous</button>
                @endif
                <span>Page {{ $list['page'] }} of {{ $list['last_page'] }}</span>
                @if(($list['page'] ?? 1) < ($list['last_page'] ?? 1))
                    <button type="button" class="db-btn" wire:click="goToPage({{ $list['page'] + 1 }})">Next</button>
                @endif
            </div>
        @endif

    </section>

</main>
</div>
