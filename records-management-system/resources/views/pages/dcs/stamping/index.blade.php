{{--
  DCS PAGE: Stamping UI
  FILE: resources/views/pages/dcs/stamping/index.blade.php
  DEBUG: StampService; StampBackupService
--}}
<?php

/**
 * DCS file: Stamping. One row per document family. The stamp is applied to that family's scan.
 */

use App\Helpers\RegisterQueryHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    public string $search = '';
    public string $typeId = 'all';
    public int $page = 1;
    public int $perPage = 15;

    #[On('stamp-applied')]
    /**
     * Refreshes after stamp data.
     * @return void
     */
    public function refreshAfterStamp(): void
    {
        // Soft refresh list badges without a full browser reload.
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
        if ($name !== 'page') {
            $this->page = 1;
        }
    }

    /**
     * Lifecycle hook executed after updating the per page property.
     * @return void
     */
    public function updatedPerPage(): void
    {
        $allowed = [10, 15, 25, 50, 100];
        if (! in_array($this->perPage, $allowed, true)) {
            $this->perPage = 15;
        }
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
     * Initializes component state, authorization gates, and default configurations.
     * @return void
     */
    public function mount(): void
    {
        $requestId = (int) request('request_id', 0);
        if ($requestId > 0) {
            $this->search = (string) $requestId;
            return;
        }

        $search = trim((string) request('search', ''));
        if ($search !== '') {
            $this->search = $search;
        }
    }

    /**
     * Supplies view data, query collections, and metrics to the Blade template.
     * @return array
     */
    public function with(): array
    {
        $query = DB::table('dcs_document_requests as dr')
            ->join('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dr.id')
            ->whereNotNull('ml.scanned_masterlist')
            ->where('ml.scanned_masterlist', '<>', '')
            ->select([
                'dr.id',
                'dr.doc_type_id',
                'dr.sub_type_id',
                'ml.doc_no',
                'ml.doc_title',
                'ml.revise_no',
                'ml.revision_status',
            ]);
        if (Schema::hasColumn('dcs_masterlist_registration', 'stack_group')) {
            $query->addSelect('ml.stack_group');
        }
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyRegisteredDocumentScope($query, 'dr');
        $query->orderByDesc('dr.id');

        if ($this->typeId !== 'all' && $this->typeId !== '') {
            $query->where('dr.doc_type_id', $this->typeId);
        }

        if (trim($this->search) !== '') {
            $search = trim($this->search);
            $like = '%' . $search . '%';
            $tokens = RegisterQueryHelper::searchTokens($search);
            $query->where(function ($q) use ($like, $tokens, $search) {
                $q->where(function ($phrase) use ($like, $search) {
                    $phrase->whereExists(function ($q2) use ($like, $search) {
                        $q2->select(DB::raw(1))
                            ->from('dcs_masterlist_registration as ml')
                            ->whereColumn('ml.request_id', 'dr.id')
                            ->where(function ($q3) use ($like, $search) {
                                $q3->where('ml.doc_no', 'ilike', $like)
                                    ->orWhere('ml.doc_title', 'ilike', $like);
                                RegisterQueryHelper::applyMasterlistKeywordSearch($q3, $search);
                            });
                    })->orWhereExists(function ($q2) use ($like) {
                        $q2->select(DB::raw(1))
                            ->from('dcs_doc_types as dt')
                            ->whereColumn('dt.id', 'dr.doc_type_id')
                            ->where('dt.doc_type_name', 'ilike', $like);
                    })->orWhereRaw('dr.id::text ilike ?', [$like]);
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
                                                ->orWhere('ml.doc_title', 'ilike', $tokenLike);
                                            RegisterQueryHelper::applyMasterlistKeywordSearch($q3, $token);
                                        });
                                })->orWhereExists(function ($q2) use ($tokenLike) {
                                    $q2->select(DB::raw(1))
                                        ->from('dcs_doc_types as dt')
                                        ->whereColumn('dt.id', 'dr.doc_type_id')
                                        ->where('dt.doc_type_name', 'ilike', $tokenLike);
                                })->orWhereRaw('dr.id::text ilike ?', [$tokenLike]);
                            });
                        }
                    });
                }
            });
        }

        $scanned = $query->get()->unique(fn ($row) => (int) $row->id)->values();
        $families = $this->stampFamilies($scanned);
        $perPage = $this->pageSize();
        $total = $families->count();
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $this->page), $lastPage);
        $slice = $families->slice(($page - 1) * $perPage, $perPage)->values();
        $ids = [];
        foreach ($slice as $family) {
            $ids[] = (int) ($family['parent']['request_id'] ?? 0);
            foreach ($family['children'] as $child) {
                $ids[] = (int) ($child['request_id'] ?? 0);
            }
        }
        $ids = array_values(array_filter(array_unique($ids)));
        $hydrated = $ids === []
            ? collect()
            : RegisterQueryHelper::hydrateRequests(
                DB::table('dcs_document_requests')->whereIn('id', $ids)->get(),
                ['types', 'masterlist', 'stamps']
            )->keyBy(fn ($doc) => (int) $doc->id);
        $pageFamilies = $slice->map(function (array $family) use ($hydrated) {
            $parent = $hydrated->get((int) ($family['parent']['request_id'] ?? 0));
            $children = collect($family['children'])
                ->map(fn (array $child) => $hydrated->get((int) ($child['request_id'] ?? 0)))
                ->filter()
                ->values();

            return [
                'parent' => $parent,
                'children' => $children,
            ];
        })->filter(fn (array $family) => $family['parent'] !== null)->values();
        $documents = new \Illuminate\Pagination\LengthAwarePaginator(
            $pageFamilies,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        $docTypes = Cache::remember('dcs.stamp.doc-types.v1', 90, function () {
            return DB::table('dcs_doc_types')->whereNull('parent_id')->orderBy('doc_type_name')->get();
        });

        $availableStamps = app(\App\Services\StampService::class)->availableStampsCatalog();
        $stampLabelMap = collect($availableStamps)->pluck('label', 'id')->all();

        return compact('documents', 'docTypes', 'availableStamps', 'stampLabelMap');
    }

    /**
     * Pages size.
     * @return int
     */
    private function pageSize(): int
    {
        return in_array($this->perPage, [10, 15, 25, 50, 100], true) ? $this->perPage : 15;
    }

    /**
     * One stamp row per family. Obsolete and superseded copies sit under the latest.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $scanned
     */
    private function stampFamilies($scanned)
    {
        $typeIds = $scanned->pluck('doc_type_id')->merge($scanned->pluck('sub_type_id'))->filter()->unique()->values();
        $types = $typeIds->isEmpty()
            ? collect()
            : DB::table('dcs_doc_types')->whereIn('id', $typeIds->all())->get()->keyBy(fn ($type) => (int) $type->id);
        $rows = $scanned->map(fn ($row) => $this->stampFamilyRow($row, $types));
        $docNos = $rows->pluck('doc_no')->filter(fn ($no) => trim((string) $no) !== '')->unique()->values();
        if ($docNos->isNotEmpty()) {
            $extra = DB::table('dcs_document_requests as dr')
                ->join('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dr.id')
                ->whereIn('ml.doc_no', $docNos->all())
                ->whereNotIn('dr.id', $rows->pluck('request_id')->all() ?: [0])
                ->where(function ($query) {
                    $query->whereIn('ml.revision_status', ['latest', ''])
                        ->orWhereNull('ml.revision_status');
                })
                ->select([
                    'dr.id',
                    'dr.doc_type_id',
                    'dr.sub_type_id',
                    'ml.doc_no',
                    'ml.doc_title',
                    'ml.revise_no',
                    'ml.revision_status',
                ]);
            if (Schema::hasColumn('dcs_masterlist_registration', 'stack_group')) {
                $extra->addSelect('ml.stack_group');
            }
            RegisterQueryHelper::applyNotDeleted($extra, 'dr');
            RegisterQueryHelper::applyRegisteredDocumentScope($extra, 'dr');
            if ($this->typeId !== 'all' && $this->typeId !== '') {
                $extra->where('dr.doc_type_id', $this->typeId);
            }
            $extraRows = $extra->get();
            $extraTypeIds = $extraRows->pluck('doc_type_id')->merge($extraRows->pluck('sub_type_id'))->filter()->unique()
                ->reject(fn ($id) => $types->has((int) $id));
            if ($extraTypeIds->isNotEmpty()) {
                $types = $types->union(
                    DB::table('dcs_doc_types')->whereIn('id', $extraTypeIds->all())->get()->keyBy(fn ($type) => (int) $type->id)
                );
            }
            foreach ($extraRows as $row) {
                $rows->push($this->stampFamilyRow($row, $types));
            }
        }

        $groups = collect();
        foreach ($rows->groupBy(fn (array $row) => RegisterQueryHelper::inventoryStackKey($row)) as $members) {
            $allowsRevision = (bool) ($members->first()['allows_revision'] ?? true);
            $sorted = $members->sortByDesc('rev_no')->values();
            $parent = $allowsRevision
                ? ($members->first(fn (array $row) => ($row['status'] ?? '') === 'latest' || ($row['status'] ?? '') === '') ?? $sorted->first())
                : $sorted->first();
            $children = $members
                ->filter(fn (array $row) => (int) $row['request_id'] !== (int) ($parent['request_id'] ?? 0))
                ->filter(fn (array $row) => in_array($row['status'] ?? '', ['obsolete', 'superseded'], true))
                ->sortByDesc('rev_no')
                ->values();
            $groups->push([
                'doc_no' => $parent['doc_no'] ?? '',
                'parent' => $parent,
                'children' => $children->all(),
                'has_revisions' => $children->isNotEmpty(),
                'revision_count' => 1 + $children->count(),
                'obsolete_count' => $children->count(),
            ]);
        }

        return RegisterQueryHelper::foldOrphanStatusCopies($groups)
            ->sortByDesc(fn (array $family) => (int) ($family['parent']['request_id'] ?? 0))
            ->values();
    }

    /**
     * Stamps family row.
     *
     * @param object $row
     * @param mixed $types
     *
     * @return array
     */
    private function stampFamilyRow(object $row, $types): array
    {
        $type = $types->get((int) ($row->sub_type_id ?: $row->doc_type_id));

        return [
            'request_id' => (int) $row->id,
            'doc_type_id' => (int) ($row->doc_type_id ?? 0),
            'sub_type_id' => (int) ($row->sub_type_id ?? 0),
            'doc_no' => (string) ($row->doc_no ?? ''),
            'title' => (string) ($row->doc_title ?? ''),
            'rev_no' => (int) ($row->revise_no ?? 0),
            'status' => strtolower(trim((string) ($row->revision_status ?? 'latest'))) ?: 'latest',
            'stack_group' => (string) ($row->stack_group ?? ''),
            'allows_revision' => RegisterQueryHelper::typeAllowsRevision($type),
        ];
    }
}; ?>

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800;1,9..40,500&display=swap" rel="stylesheet">
@endpush

<div>
    <div class="dcs-loading-overlay is-page" wire:loading.flex>
        <div class="dcs-loading-spinner" aria-hidden="true"></div>
        <h4>Loading stamp documents…</h4>
        <p>Fetching records and preparing the list.</p>
    </div>

    <div class="st-container main-content">

        {{-- ═══ Header ═══ --}}
        <header class="st-header">
            <div>
                <nav class="st-breadcrumb">Document Control System / <span>Stamp Document</span></nav>
                <h1 class="st-title">Stamp Document</h1>
            </div>
            <div class="st-header-right">
                <div class="st-stat-pill">
                    <i class="fa-solid fa-file-lines"></i>
                    <span>{{ $documents->total() }} Document{{ $documents->total() !== 1 ? 's' : '' }}</span>
                </div>
            </div>
        </header>

        {{-- ═══ Toolbar ═══ --}}
        <div class="st-toolbar">
            <div class="st-search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="stSearch" class="st-search" wire:model.live.debounce.400ms="search"
                       placeholder="Search by title, document number, keywords, or type..." autocomplete="off">
            </div>
            <select id="stTypeFilter" class="st-filter" wire:model.live="typeId">
                <option value="all">All Document Types</option>
                @foreach($docTypes as $type)
                    <option value="{{ $type->id }}">{{ $type->doc_type_name }}</option>
                @endforeach
            </select>
            <button type="button" id="stClearBtn" class="st-btn-clear" wire:click="$set('search', ''); $set('typeId', 'all')">
                <i class="fa-solid fa-xmark"></i> Clear
            </button>
        </div>

        {{-- ═══ Table ═══ --}}
        <div class="st-table-card" style="position:relative;" wire:loading.class="is-loading" x-data="{ open: {} }">
            <div class="st-table-scroll">
                <table class="st-table">
                    <thead>
                        <tr>
                            <th class="col-idx">#</th>
                            <th class="col-type">Type</th>
                            <th class="col-docno">Document No.</th>
                            <th class="col-title">Document Title</th>
                            <th class="col-rev">Rev</th>
                            <th class="col-status">Status</th>
                            <th class="col-files"><i class="fa-solid fa-paperclip"></i>&nbsp; Scanned File</th>
                            <th class="col-action">Action</th>
                        </tr>
                    </thead>
                    <tbody id="stTableBody">
                        @forelse($documents as $i => $family)
                            @php $revKey = (int) ($family['parent']->id ?? 0); $childCount = $family['children']->count(); @endphp
                            @foreach(collect([$family['parent']])->merge($family['children']) as $memberIndex => $doc)
                            @php
                                $isChild = $memberIndex > 0;
                                $ml   = $doc->masterlistRegistration;
                                $drf  = $doc->documentRequestForm;

                                $docNo   = $ml->doc_no ?? 'N/A';
                                $title   = $ml->doc_title ?? $drf->doc_title ?? 'Untitled';
                                $revNo   = $ml->revise_no ?? 0;
                                $docType = $doc->docType->doc_type_name ?? 'N/A';
                                $copyStatus = strtolower(trim((string) ($ml->revision_status ?? '')));
                                $statusLabel = match ($copyStatus) {
                                    'obsolete' => 'Obsolete',
                                    'superseded' => 'Superseded',
                                    'archived' => 'Archived',
                                    default => 'Latest',
                                };

                                // Build stamp lookup
                                $stampMap = [];
                                foreach ($doc->stamps as $stamp) {
                                    $stampMap[$stamp->file_key] = $stamp;
                                }

                                // Only the masterlist scanned PDF can be stamped.
                                $files = [];
                                if ($ml && !empty($ml->scanned_masterlist)) {
                                    $s = $stampMap['masterlist'] ?? null;
                                    $fileName = trim((string) ($ml->scanned_masterlist_original_name ?? ''));
                                    if ($fileName === '') {
                                        $fileName = basename((string) $ml->scanned_masterlist);
                                    }
                                    $files[] = [
                                        'key'         => 'masterlist',
                                        'label'       => $fileName,
                                        'abbr'        => $fileName,
                                        'cls'         => 'ml',
                                        'path'        => $ml->scanned_masterlist,
                                        'preview_url' => RegisterQueryHelper::scanUrl($ml->scanned_masterlist, false),
                                        'stamped'     => !!$s,
                                        'stamp_type'  => $s?->stamp_type,
                                    ];
                                }

                                $anyStamped = collect($files)->contains(fn($f) => $f['stamped']);
                            @endphp
                            <tr class="{{ $isChild ? 'st-family-child' : 'st-family-lead' }}" @if($isChild) x-show="open[{{ $revKey }}]" x-cloak @endif>
                                <td class="col-idx">
                                    @if(! $isChild && $childCount > 0)
                                        <button type="button" class="st-expand" :class="{ expanded: open[{{ $revKey }}] }" x-on:click.stop="open[{{ $revKey }}] = !open[{{ $revKey }}]" :title="open[{{ $revKey }}] ? 'Hide older copies' : 'Show {{ $childCount }} older {{ $childCount === 1 ? 'copy' : 'copies' }}'">
                                            <span x-text="open[{{ $revKey }}] ? '▼' : '▶'"></span>
                                        </button>
                                    @endif
                                    {{ $isChild ? '' : $documents->firstItem() + $i }}
                                </td>
                                <td class="col-type"><span class="st-badge-type">{{ $docType }}</span></td>
                                <td class="col-docno"><span class="st-doc-no">{{ $docNo }}</span></td>
                                <td class="col-title"><span class="st-doc-title" title="{{ $title }}">{{ $title }}</span></td>
                                <td class="col-rev"><span class="st-badge-rev">{{ $revNo }}</span></td>
                                <td class="col-status"><span class="st-copy-status is-{{ $copyStatus !== '' ? $copyStatus : 'latest' }}">{{ $statusLabel }}</span></td>
                                <td class="col-files">
                                    @if(count($files) > 0)
                                        <div class="st-files-group">
                                            @foreach($files as $file)
                                                <div class="st-file-tag-wrap">
                                                    <a href="{{ $file['preview_url'] }}"
                                                       target="_blank"
                                                       rel="noopener"
                                                       class="st-file-tag st-file-{{ $file['cls'] }} {{ $file['stamped'] ? 'st-file-stamped' : '' }}"
                                                       title="{{ $file['label'] }}{{ $file['stamped'] ? ' — ' . strtoupper($stampLabelMap[$file['stamp_type']] ?? str_replace('_',' ',$file['stamp_type'])) : '' }}">
                                                        @if($file['stamped'])
                                                            <i class="fa-solid fa-stamp"></i>
                                                        @else
                                                            <i class="fa-solid fa-file-pdf"></i>
                                                        @endif
                                                        {{ $file['abbr'] }}
                                                    </a>
                                                    @if($file['stamped'])
                                                        <span class="st-stamp-badge" title="{{ strtoupper($stampLabelMap[$file['stamp_type']] ?? str_replace('_',' ',$file['stamp_type'])) }}">
                                                            <i class="fa-solid fa-lock"></i>
                                                        </span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="st-no-files">No files</span>
                                    @endif
                                </td>
                                <td class="col-action">
                                    @if(count($files) > 0)
                                    <button type="button" class="st-btn-stamp {{ $anyStamped ? 'st-btn-stamp-change' : '' }}"
                                        data-files='@json($files)'
                                        data-request-id="{{ $doc->id }}"
                                        data-title="{{ $title }}"
                                        data-doc-no="{{ $docNo }}"
                                        data-rev="{{ $revNo }}">
                                        @if($anyStamped)
                                            <i class="fa-solid fa-pen-to-square"></i>
                                            <span>Change</span>
                                        @else
                                            <i class="fa-solid fa-stamp"></i>
                                            <span>Stamp</span>
                                        @endif
                                    </button>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="8">
                                    <div class="st-empty">
                                        <div class="st-empty-icon">
                                            <i class="fa-solid fa-stamp"></i>
                                        </div>
                                        <h3>No documents found</h3>
                                        <p>There are no documents with a scanned masterlist matching your criteria.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @include('pages.dcs.partials.list-pagination', [
                'from' => (int) $documents->firstItem(),
                'to' => (int) $documents->lastItem(),
                'total' => (int) $documents->total(),
                'page' => (int) $documents->currentPage(),
                'lastPage' => (int) $documents->lastPage(),
            ])
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         STAMP MODAL
         ═══════════════════════════════════════════════════════════════ --}}
    <div class="st-modal-overlay" id="stampModal" style="display:none;">
        <div class="st-modal">
            <div class="st-modal-header">
                <div>
                    <h3>Apply Stamp</h3>
                    <p class="st-modal-doc" id="modalDocInfo"></p>
                </div>
                <button class="st-modal-close" id="closeModal" type="button">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="st-modal-body">
                {{-- LEFT: PDF Preview --}}
                <div class="st-preview-panel">
                    <div class="st-preview-toolbar">
                        <span class="st-preview-note-inline">Stamp preview</span>
                        <span class="st-preview-page" id="pageIndicator">—</span>
                        <div class="st-preview-spacer"></div>
                        <button class="st-preview-refresh" id="refreshPreview" type="button" title="Refresh preview">
                            <i class="fa-solid fa-rotate-right"></i> Refresh
                        </button>
                    </div>
                    <div class="st-preview-frame" id="previewFrame">
                        <div class="st-preview-scroll" id="previewScroll">
                            <div class="st-page-stack" id="pageStack"></div>
                        </div>
                        <template id="stampOverlayTpl">
                            <div class="st-stamp-overlay pos-bottom-right" style="display:none;">
                                <div class="st-stamp-box st-stamp-box-image" title="Drag to move">
                                    <img src="{{ asset('images/stamps/reference.png') }}?v={{ @filemtime(public_path('images/stamps/reference.png')) ?: time() }}" alt="REFERENCE" class="st-stamp-art">
                                    <div class="st-stamp-inner" style="display:none;">
                                        <div class="st-stamp-title">REFERENCE</div>
                                        <div class="st-stamp-divider" style="display:none;"></div>
                                        <div class="st-stamp-date" style="display:none;"></div>
                                        <div class="st-stamp-sub" style="display:none;"></div>
                                    </div>
                                </div>
                                <span class="st-stamp-resize-handle" title="Drag corner to resize" aria-label="Resize stamp"></span>
                                <span class="st-stamp-pages">All pages</span>
                                <span class="st-auto-badge is-detecting st-detecting-badge" style="display:none;">
                                    <i class="fa-solid fa-spinner fa-spin"></i> Finding empty area…
                                </span>
                                <span class="st-auto-badge st-auto-place-badge" style="display:none;">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i> Preview position — drag to adjust
                                </span>
                            </div>
                        </template>

                        <div class="st-preview-fallback" id="previewFallback" style="display:none;">
                            <i class="fa-solid fa-file-pdf"></i>
                            <p>PDF preview not available</p>
                            <a id="openPdfLink" href="#" target="_blank">Open PDF in new tab</a>
                        </div>
                    </div>
                    <p class="st-preview-note">
                        <i class="fa-solid fa-hand"></i>
                        Auto-place finds an empty area on each page. Drag any page to override just that page, then Apply Stamp.
                    </p>
                </div>

                {{-- RIGHT: Configuration --}}
                <div class="st-config-panel">
                    <div class="st-section" id="fileSection" style="display:none;">
                        <label class="st-label">File</label>
                        <div class="st-file-options" id="fileOptions"></div>
                    </div>

                    <div class="st-section">
                        <div class="st-label-row">
                            <label class="st-label" style="margin-bottom:0;">Stamp Type</label>
                            <button type="button" class="st-import-toggle-btn" id="toggleImportStampBtn">
                                <i class="fa-solid fa-plus"></i> Import Stamp
                            </button>
                        </div>
                        <p style="margin:6px 0 8px;font-size:0.75rem;color:var(--st-text-subtle);">
                            Pick a stamp or import a PNG/JPG image. Drag to move or drag the bottom-right corner to resize.
                        </p>

                        <div class="st-import-box" id="importStampBox" style="display:none;">
                            <div class="st-form-group">
                                <label class="st-label-sm" for="importStampLabel">Stamp Name</label>
                                <input type="text" id="importStampLabel" class="st-input" placeholder="e.g. Controlled Copy, Approved" maxlength="60">
                            </div>
                            <div class="st-form-group">
                                <label class="st-label-sm" for="importStampFile">Stamp Image (PNG, JPG, WebP)</label>
                                <input type="file" id="importStampFile" class="st-file-input" accept="image/png,image/jpeg,image/webp">
                            </div>
                            <div class="st-import-preview" id="importStampPreviewWrap" style="display:none;">
                                <img id="importStampPreviewImg" src="" alt="Stamp preview">
                            </div>
                            <div class="st-import-actions">
                                <button type="button" class="st-import-cancel" id="cancelImportStampBtn">Cancel</button>
                                <button type="button" class="st-import-submit" id="submitImportStampBtn">
                                    <i class="fa-solid fa-upload"></i> Save Stamp
                                </button>
                            </div>
                        </div>

                        <div class="st-type-pills" id="typePills">
                            @foreach($availableStamps as $st)
                                <button class="st-type-pill {{ !empty($st['is_custom']) ? 'is-custom' : '' }}" data-type="{{ $st['id'] }}" type="button">
                                    @if(!empty($st['is_custom']))
                                        <img src="{{ $st['image_url'] }}" alt="" class="st-pill-thumb">
                                    @else
                                        <span class="st-pill-color" style="background:{{ $st['color'] ?? '#b03030' }}"></span>
                                    @endif
                                    <span class="st-pill-text">{{ $st['label'] }}</span>
                                    @if(!empty($st['is_custom']))
                                        <span class="st-pill-delete" data-delete-stamp="{{ $st['id'] }}" title="Remove custom stamp" role="button" tabindex="0">&times;</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="st-section" id="stampSizeSection">
                        <div class="st-label-row">
                            <label class="st-label" for="stampSizeSlider" style="margin-bottom:0;">Stamp Size</label>
                            <button type="button" class="st-size-reset-btn" id="resetStampSizeBtn" title="Reset to default size">Reset</button>
                        </div>
                        <div class="st-size-control">
                            <input type="range" id="stampSizeSlider" class="st-size-slider" min="40" max="250" step="5" value="100">
                            <div class="st-size-readout" id="stampSizeReadout">100% · 36 × 13 mm</div>
                        </div>
                    </div>

                    <div class="st-section">
                        <div class="st-label-row">
                            <label class="st-label" style="margin-bottom:0;">Position</label>
                            <button type="button" class="st-redetect-btn" id="redetectAutoPlaceBtn" title="Scan each page again for an empty area">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Find empty area
                            </button>
                        </div>
                        <label class="st-checkbox-wrap" style="margin-bottom:10px;">
                            <input type="checkbox" id="autoPlace" checked>
                            <span class="st-checkbox-custom"></span>
                            <span>Auto-place in empty area <small style="color:var(--st-text-subtle);font-weight:400;">(drag any page to adjust)</small></span>
                        </label>

                        <div id="manualPosWrap" style="display:none;">
                            <div class="st-pos-wrap">
                                <div class="st-pos-map" id="positionMap">
                                    <div class="st-pos-dot" data-pos="top-left">TL</div>
                                    <div class="st-pos-dot" data-pos="top-right">TR</div>
                                    <div class="st-pos-dot" data-pos="center">C</div>
                                    <div class="st-pos-dot" data-pos="bottom-left">BL</div>
                                    <div class="st-pos-dot active" data-pos="bottom-right">BR</div>
                                </div>
                                <span class="st-pos-label" id="positionLabel">Bottom Right</span>
                            </div>
                        </div>
                        <p class="st-pos-drag-hint" id="customPosHint" style="display:none;margin:8px 0 0;font-size:0.75rem;color:var(--st-text-subtle);">
                            <i class="fa-solid fa-arrows-up-down-left-right"></i>
                            Drag each page’s stamp independently, then Apply Stamp
                        </p>
                    </div>

                    <div class="st-section">
                        <label class="st-checkbox-wrap">
                            <input type="checkbox" id="stampAllPages" checked>
                            <span class="st-checkbox-custom"></span>
                            <span>Stamp all pages</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="st-modal-footer">
                <button class="st-btn-cancel" id="cancelBtn" type="button">Cancel</button>
                <div class="st-footer-actions">
                    <button class="st-btn-download" id="downloadBtn" type="button" disabled>
                        <i class="fa-solid fa-download"></i>
                        <span>Download Copy</span>
                    </button>
                    <button class="st-btn-restore st-btn-remove" id="restoreBtn" type="button" disabled style="display:none;">
                        <i class="fa-solid fa-trash-can"></i>
                        <span>Remove Stamp</span>
                    </button>
                    <button class="st-btn-apply" id="applyBtn" type="button" disabled>
                        <i class="fa-solid fa-stamp"></i>
                        <span>Apply Stamp</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         CONFIRM MODAL
         ═══════════════════════════════════════════════════════════════ --}}
    <div class="st-confirm-overlay" id="confirmModal" style="display:none;">
        <div class="st-confirm">
            <div class="st-confirm-icon">
                <i class="fa-solid fa-stamp"></i>
            </div>
            <h3>Apply Stamp?</h3>
            <p>This will overwrite the live file with the <strong id="confirmStampLabel"></strong> stamp. A backup of the unstamped original is kept so you can restore it later.</p>
            <div class="st-confirm-actions">
                <button class="st-btn-secondary" id="confirmCancel" type="button">Cancel</button>
                <button class="st-btn-primary" id="confirmApply" type="button">
                    <i class="fa-solid fa-check"></i> Confirm Apply
                </button>
            </div>
        </div>
    </div>

    <div class="st-confirm-overlay" id="restoreModal" style="display:none;">
        <div class="st-confirm">
            <div class="st-confirm-icon">
                <i class="fa-solid fa-rotate-left"></i>
            </div>
            <h3>Remove Stamp?</h3>
            <p>This removes the stamp from the live file and restores the unstamped original from backup.</p>
            <div class="st-confirm-actions">
                <button class="st-btn-secondary" id="restoreCancel" type="button">Cancel</button>
                <button class="st-btn-primary" id="restoreConfirm" type="button">
                    <i class="fa-solid fa-trash-can"></i> Remove Stamp
                </button>
            </div>
        </div>
    </div>

    <div class="st-confirm-overlay" id="deleteCustomStampModal" style="display:none;">
        <div class="st-confirm">
            <div class="st-confirm-icon is-danger">
                <i class="fa-solid fa-trash-can"></i>
            </div>
            <h3>Remove Custom Stamp?</h3>
            <p>Remove custom stamp <strong id="deleteCustomStampName"></strong>? Previously stamped documents will not be affected.</p>
            <div class="st-confirm-actions">
                <button class="st-btn-secondary" id="deleteCustomStampCancel" type="button">Cancel</button>
                <button class="st-btn-primary st-btn-danger-confirm" id="deleteCustomStampConfirm" type="button">
                    <i class="fa-solid fa-trash-can"></i> Remove
                </button>
            </div>
        </div>
    </div>

    <div wire:ignore>
        <script>
            window.DCS_STAMPS_CATALOG = @json($availableStamps);
        </script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
        <script>
            if (window.pdfjsLib) {
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
            }
        </script>
        <script src="{{ asset('js/stamping.js') }}?v={{ @filemtime(public_path('js/stamping.js')) ?: time() }}"></script>
    </div>
</div>
