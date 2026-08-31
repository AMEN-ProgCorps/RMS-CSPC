<?php

use App\Helpers\RegisterQueryHelper;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    public string $search = '';
    public string $typeId = 'all';
    public int $page = 1;

    public function updated($name): void
    {
        if ($name !== 'page') {
            $this->page = 1;
        }
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

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

    public function with(): array
    {
        $visibleIds = RegisterQueryHelper::visibleRequestIds();
        $query = DB::table('dcs_document_requests as dr')
            ->whereIn('dr.id', $visibleIds ?: [0])
            ->whereExists(fn ($q2) =>
                $q2->select(DB::raw(1))->from('dcs_masterlist_registration as ml')
                    ->whereColumn('ml.request_id', 'dr.id')
                    ->whereNotNull('ml.scanned_masterlist')
                    ->where('ml.scanned_masterlist', '!=', '')
            )
            ->orderByDesc('dr.id');

        if ($this->typeId !== 'all' && $this->typeId !== '') {
            $query->where('dr.doc_type_id', $this->typeId);
        }

        if (trim($this->search) !== '') {
            $like = '%' . trim($this->search) . '%';
            $query->where(function ($q) use ($like) {
                $q->whereExists(function ($q2) use ($like) {
                    $q2->select(DB::raw(1))
                        ->from('dcs_masterlist_registration as ml')
                        ->whereColumn('ml.request_id', 'dr.id')
                        ->where(function ($q3) use ($like) {
                            $q3->where('ml.doc_no', 'ilike', $like)
                                ->orWhere('ml.doc_title', 'ilike', $like);
                        });
                })->orWhereExists(function ($q2) use ($like) {
                    $q2->select(DB::raw(1))
                        ->from('dcs_doc_types as dt')
                        ->whereColumn('dt.id', 'dr.doc_type_id')
                        ->where('dt.doc_type_name', 'ilike', $like);
                })->orWhereRaw('dr.id::text ilike ?', [$like]);
            });
        }

        $total = (clone $query)->count();
        $perPage = 15;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $this->page), $lastPage);
        $rows = RegisterQueryHelper::hydrateRequests(
            (clone $query)->offset(($page - 1) * $perPage)->limit($perPage)->get()
        );
        $documents = new \Illuminate\Pagination\LengthAwarePaginator(
            $rows,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        $docTypes = DB::table('dcs_doc_types')->whereNull('parent_id')->orderBy('doc_type_name')->get();

        return compact('documents', 'docTypes');
    }
}; ?>

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800;1,9..40,500&display=swap" rel="stylesheet">
@endpush

<div>
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
                       placeholder="Search by title, document number, or type..." autocomplete="off">
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
        <div class="st-table-card">
            <div class="st-table-scroll">
                <table class="st-table">
                    <thead>
                        <tr>
                            <th class="col-idx">#</th>
                            <th class="col-type">Type</th>
                            <th class="col-docno">Document No.</th>
                            <th class="col-title">Document Title</th>
                            <th class="col-rev">Rev</th>
                            <th class="col-files"><i class="fa-solid fa-paperclip"></i>&nbsp; Scanned File</th>
                            <th class="col-action">Action</th>
                        </tr>
                    </thead>
                    <tbody id="stTableBody">
                        @forelse($documents as $i => $doc)
                            @php
                                $ml   = $doc->masterlistRegistration;
                                $drf  = $doc->documentRequestForm;

                                $docNo   = $ml->doc_no ?? 'N/A';
                                $title   = $ml->doc_title ?? $drf->doc_title ?? 'Untitled';
                                $revNo   = $ml->revise_no ?? 0;
                                $docType = $doc->docType->doc_type_name ?? 'N/A';

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
                                        'preview_url' => RegisterQueryHelper::scanUrl($ml->scanned_masterlist),
                                        'stamped'     => !!$s,
                                        'stamp_type'  => $s?->stamp_type,
                                    ];
                                }

                                $anyStamped = collect($files)->contains(fn($f) => $f['stamped']);
                            @endphp
                            <tr>
                                <td class="col-idx">{{ $documents->firstItem() + $i }}</td>
                                <td class="col-type"><span class="st-badge-type">{{ $docType }}</span></td>
                                <td class="col-docno"><span class="st-doc-no">{{ $docNo }}</span></td>
                                <td class="col-title"><span class="st-doc-title" title="{{ $title }}">{{ $title }}</span></td>
                                <td class="col-rev"><span class="st-badge-rev">{{ $revNo }}</span></td>
                                <td class="col-files">
                                    @if(count($files) > 0)
                                        <div class="st-files-group">
                                            @foreach($files as $file)
                                                <div class="st-file-tag-wrap">
                                                    <a href="{{ RegisterQueryHelper::scanUrl($file['path']) }}"
                                                       target="_blank"
                                                       rel="noopener"
                                                       class="st-file-tag st-file-{{ $file['cls'] }} {{ $file['stamped'] ? 'st-file-stamped' : '' }}"
                                                       title="{{ $file['label'] }}{{ $file['stamped'] ? ' — ' . strtoupper(str_replace('_',' ',$file['stamp_type'])) : '' }}">
                                                        @if($file['stamped'])
                                                            <i class="fa-solid fa-stamp"></i>
                                                        @else
                                                            <i class="fa-solid fa-file-pdf"></i>
                                                        @endif
                                                        {{ $file['abbr'] }}
                                                    </a>
                                                    @if($file['stamped'])
                                                        <span class="st-stamp-badge" title="{{ strtoupper(str_replace('_',' ',$file['stamp_type'])) }}">
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
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
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

            @if($documents->hasPages())
            <div class="st-pagination">
                <div class="st-page-info">
                    Showing <strong>{{ $documents->firstItem() }}&ndash;{{ $documents->lastItem() }}</strong> of <strong>{{ $documents->total() }}</strong>
                </div>
                <div class="st-page-links">
                    @if($documents->onFirstPage())
                        <span class="st-pg disabled"><i class="fa-solid fa-chevron-left"></i></span>
                    @else
                        <button type="button" class="st-pg" wire:click="goToPage({{ $documents->currentPage() - 1 }})"><i class="fa-solid fa-chevron-left"></i></button>
                    @endif

                    @foreach(range(1, $documents->lastPage()) as $page)
                        @if($page === $documents->currentPage())
                            <span class="st-pg st-pg-active">{{ $page }}</span>
                        @else
                            <button type="button" class="st-pg" wire:click="goToPage({{ $page }})">{{ $page }}</button>
                        @endif
                    @endforeach

                    @if($documents->hasMorePages())
                        <button type="button" class="st-pg" wire:click="goToPage({{ $documents->currentPage() + 1 }})"><i class="fa-solid fa-chevron-right"></i></button>
                    @else
                        <span class="st-pg disabled"><i class="fa-solid fa-chevron-right"></i></span>
                    @endif
                </div>
            </div>
            @endif
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
                        <button class="st-preview-nav" id="prevPage" type="button" title="Previous page" disabled>
                            <i class="fa-solid fa-chevron-left"></i>
                        </button>
                        <span class="st-preview-page" id="pageIndicator">Page 1 / 1</span>
                        <button class="st-preview-nav" id="nextPage" type="button" title="Next page" disabled>
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                        <div class="st-preview-spacer"></div>
                        <button class="st-preview-refresh" id="refreshPreview" type="button" title="Refresh preview">
                            <i class="fa-solid fa-rotate-right"></i> Refresh
                        </button>
                    </div>
                    <div class="st-preview-frame" id="previewFrame">
                        <iframe id="pdfPreview" src="" title="PDF Preview"></iframe>

                        <div class="st-stamp-overlay pos-bottom-right" id="stampOverlay" style="display:none;">
                            <div class="st-stamp-box st-stamp-box-image" id="stampBox">
                                <img src="{{ asset('images/stamps/reference.png') }}" alt="REFERENCE" class="st-stamp-art" id="overlayStampArt">
                                <div class="st-stamp-inner" id="overlayStampFallback" style="display:none;">
                                    <div class="st-stamp-title" id="overlayTitle">REFERENCE</div>
                                    <div class="st-stamp-divider" id="overlayDivider" style="display:none;"></div>
                                    <div class="st-stamp-fields" id="overlayFields" style="display:none;">
                                        <div class="st-stamp-field">
                                            <span class="st-sf-label">Certified by:</span>
                                            <span class="st-sf-value" id="overlayCertBy">...</span>
                                        </div>
                                        <div class="st-stamp-field">
                                            <span class="st-sf-label">Designation:</span>
                                            <span class="st-sf-value" id="overlayDesig">...</span>
                                        </div>
                                    </div>
                                    <div class="st-stamp-date" id="overlayDate" style="display:none;"></div>
                                    <div class="st-stamp-sub" id="overlaySub" style="display:none;"></div>
                                </div>
                            </div>
                            <span class="st-stamp-pages" id="overlayPages">All pages</span>
                            <span class="st-auto-badge" id="autoBadge" style="display:none;">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Empty area detected
                            </span>
                        </div>

                        <div class="st-preview-fallback" id="previewFallback" style="display:none;">
                            <i class="fa-solid fa-file-pdf"></i>
                            <p>PDF preview not available</p>
                            <a id="openPdfLink" href="#" target="_blank">Open PDF in new tab</a>
                        </div>
                    </div>
                    <p class="st-preview-note">
                        <i class="fa-solid fa-eye"></i>
                        Preview shows stamp on the detected empty area of the scanned page
                    </p>
                </div>

                {{-- RIGHT: Configuration --}}
                <div class="st-config-panel">
                    <div class="st-section" id="fileSection" style="display:none;">
                        <label class="st-label">File</label>
                        <div class="st-file-options" id="fileOptions"></div>
                    </div>

                    <div class="st-section">
                        <label class="st-label">Stamp Type</label>
                        <div class="st-type-pills" id="typePills">
                            <button class="st-type-pill selected" data-type="reference" type="button">
                                <span class="st-pill-color" style="background:#b03030"></span> Reference
                            </button>
                        </div>
                    </div>

                    <div class="st-section" id="certifiedFields" style="display:none;" hidden>
                        <div class="st-form-group">
                            <label class="st-label-sm">Certified By</label>
                            <input type="text" id="certifiedBy" class="st-input" placeholder="Full name">
                        </div>
                        <div class="st-form-group">
                            <label class="st-label-sm">Designation</label>
                            <input type="text" id="designation" class="st-input" placeholder="Position / Title">
                        </div>
                    </div>

                    <div class="st-section">
                        <label class="st-label">Position</label>
                        <label class="st-checkbox-wrap" style="margin-bottom:10px;">
                            <input type="checkbox" id="autoPlace" checked>
                            <span class="st-checkbox-custom"></span>
                            <span>Auto-place in empty area <small style="color:var(--st-text-subtle);font-weight:400;">(prefers bottom margin)</small></span>
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
                    <button class="st-btn-restore" id="restoreBtn" type="button" disabled>
                        <i class="fa-solid fa-rotate-left"></i>
                        <span>Restore Original</span>
                    </button>
                    <button class="st-btn-download" id="downloadBtn" type="button" disabled>
                        <i class="fa-solid fa-download"></i>
                        <span>Download Copy</span>
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
            <h3>Restore Original File?</h3>
            <p>This removes the stamp from the live file and restores the unstamped original from backup.</p>
            <div class="st-confirm-actions">
                <button class="st-btn-secondary" id="restoreCancel" type="button">Cancel</button>
                <button class="st-btn-primary" id="restoreConfirm" type="button">
                    <i class="fa-solid fa-check"></i> Restore
                </button>
            </div>
        </div>
    </div>

    <div wire:ignore>
        <script src="{{ asset('js/stamping.js') }}"></script>
    </div>
</div>
