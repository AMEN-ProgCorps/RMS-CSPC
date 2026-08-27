<?php

use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Document Review — CSPC DCS')] class extends Component {
    #[Url]
    public string $search = '';

    #[Url]
    public string $docTypeId = 'all';

    public int $page = 1;

    public string $selectedDocNo = '';

    public string $leftId = '';

    public string $rightId = '';

    public string $tab = 'masterlist';

    public function with(): array
    {
        $compare = $this->selectedDocNo !== ''
            ? RegisterQueryHelper::reviewCompare(
                $this->selectedDocNo,
                $this->leftId !== '' ? (int) $this->leftId : null,
                $this->rightId !== '' ? (int) $this->rightId : null
            )
            : [
                'docNo' => '',
                'docTitle' => '',
                'options' => [],
                'pair_options' => [],
                'prior_options' => [],
                'left_id' => null,
                'right_id' => null,
                'latest_id' => null,
                'latest_revise_no' => null,
                'tabs' => [],
                'pairs' => [],
                'can_compare' => false,
                'can_view' => false,
                'error' => null,
                'left_label' => 'Older revision',
                'right_label' => 'Newer revision',
            ];

        if (($compare['right_id'] ?? null) && (string) $compare['right_id'] !== $this->rightId) {
            $this->rightId = (string) $compare['right_id'];
        }
        if (($compare['left_id'] ?? null) && (string) $compare['left_id'] !== $this->leftId) {
            $this->leftId = (string) $compare['left_id'];
        }

        $tabKeys = array_column($compare['tabs'], 'key');
        $activeTab = in_array($this->tab, $tabKeys, true) ? $this->tab : ($tabKeys[0] ?? $this->tab);
        $pair = $compare['pairs'][$activeTab] ?? null;

        return [
            'docTypes' => RegisterQueryHelper::parentDocTypes(),
            'list' => RegisterQueryHelper::reviewList($this->search, $this->docTypeId, $this->page),
            'compare' => $compare,
            'pair' => $pair,
            'activeTab' => $activeTab,
            'pairOptions' => $compare['pair_options'] ?? [],
        ];
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedDocTypeId(): void
    {
        $this->page = 1;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->docTypeId = 'all';
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function selectDocument(string $docNo): void
    {
        $docNo = trim($docNo);
        if ($docNo === '' || strcasecmp($docNo, 'N/A') === 0) {
            $this->selectedDocNo = '';
            $this->leftId = '';
            $this->rightId = '';

            return;
        }

        $this->selectedDocNo = $docNo;
        $preview = RegisterQueryHelper::reviewCompare($docNo, null, null);
        // Default to the tip consecutive pair (previous → latest).
        $this->rightId = (string) ($preview['right_id'] ?? $preview['latest_id'] ?? '');
        $this->leftId = (string) ($preview['left_id'] ?? '');
        $this->tab = $preview['tabs'][0]['key'] ?? 'masterlist';
    }

    public function clearDocument(): void
    {
        $this->selectedDocNo = '';
        $this->leftId = '';
        $this->rightId = '';
        $this->tab = 'masterlist';
    }

    public function updatedLeftId(): void
    {
        $this->snapToConsecutivePair('left');
    }

    public function updatedRightId(): void
    {
        $this->snapToConsecutivePair('right');
    }

    public function selectPair(string $key): void
    {
        if ($this->selectedDocNo === '' || $key === '') {
            return;
        }
        [$left, $right] = array_pad(explode(':', $key, 2), 2, '');
        $this->leftId = $left;
        $this->rightId = $right;
        $this->snapToConsecutivePair('right');
    }

    private function snapToConsecutivePair(string $prefer): void
    {
        if ($this->selectedDocNo === '') {
            return;
        }

        $left = $this->leftId !== '' ? (int) $this->leftId : null;
        $right = $this->rightId !== '' ? (int) $this->rightId : null;

        if ($prefer === 'left') {
            $preview = RegisterQueryHelper::reviewCompare($this->selectedDocNo, $left, null);
        } else {
            $preview = RegisterQueryHelper::reviewCompare($this->selectedDocNo, null, $right);
        }

        $this->rightId = (string) ($preview['right_id'] ?? '');
        $this->leftId = (string) ($preview['left_id'] ?? '');
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }
}; ?>

<div class="drr-container main-content">
    <div class="drr-header">
        <div>
            <div class="drr-breadcrumb">Document Control System / <span>Document Review</span></div>
            <h1 class="drr-title">Document Review</h1>
        </div>
        @if($selectedDocNo !== '')
            <button type="button" class="drr-btn-ghost" wire:click="clearDocument">
                <i class="fa-solid fa-arrow-left"></i> Back to documents
            </button>
        @endif
    </div>

    @if($selectedDocNo === '')
        <div class="drr-search-bar">
            <div class="drr-search-wrapper">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="drr-search-input" wire:model.live.debounce.400ms="search"
                    placeholder="Search by title or document no..." autocomplete="off">
            </div>
            <select class="drr-filter-select" wire:model.live="docTypeId">
                <option value="all">All Document Types</option>
                @foreach($docTypes as $type)
                    <option value="{{ $type->id }}">{{ $type->doc_type_name }}</option>
                @endforeach
            </select>
            <button type="button" class="drr-btn-clear" wire:click="clearFilters" title="Reset filters">
                <i class="fa-solid fa-xmark"></i> Clear
            </button>
        </div>
        <p class="drr-hint">Each row shows the latest revision. Open a document, then pick a consecutive pair (Rev 0→1, 1→2, 3→5 when 4 is missing, …).</p>

        <div class="drr-table-card">
            <div class="drr-table-scroll" @if(count($list['rows']) === 0) style="display:none" @endif>
                <table class="drr-table">
                    <thead>
                        <tr>
                            <th>Doc Type</th>
                            <th>Title</th>
                            <th>Document No.</th>
                            <th>Latest rev</th>
                            <th>Revisions</th>
                            <th style="width:110px;">Review</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($list['rows'] as $doc)
                            <tr>
                                <td>{{ $doc['doc_type'] }}</td>
                                <td class="drr-doc-title" title="{{ $doc['title'] }}">{{ $doc['title'] }}</td>
                                <td class="drr-doc-no">{{ $doc['doc_no'] }}</td>
                                <td><span class="drr-rev-pill">Rev {{ $doc['rev_no'] }}</span></td>
                                <td>{{ $doc['rev_count'] }}</td>
                                <td>
                                    <button type="button" class="drr-btn-review" wire:click="selectDocument(@js($doc['doc_no']))">
                                        Review
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="drr-empty" @if(count($list['rows']) > 0) style="display:none" @endif>
                <i class="fa-solid fa-folder-open"></i>
                @if($list['filtered'])
                    <p>No documents match these filters.</p>
                    <span>Try another title or document number, or clear the filters.</span>
                @else
                    <p>No documents are available to review.</p>
                    <span>Register a document to start reviewing scanned masterlist copies.</span>
                @endif
            </div>
            <div class="drr-pagination" @if($list['total'] === 0) style="display:none" @endif>
                <div>Page {{ $list['current_page'] }} of {{ $list['last_page'] }} ({{ $list['total'] }} total)</div>
                <div class="drr-pagination-links">
                    @if($list['current_page'] > 1)
                        <button type="button" class="drr-pg" wire:click="goToPage({{ $list['current_page'] - 1 }})">Prev</button>
                    @endif
                    @if($list['current_page'] < $list['last_page'])
                        <button type="button" class="drr-pg" wire:click="goToPage({{ $list['current_page'] + 1 }})">Next</button>
                    @endif
                </div>
            </div>
        </div>
    @else
        @php
            $reviewErrors = [
                'no_doc_no' => 'This record has no document number, so its revisions cannot be reviewed.',
                'not_found' => 'This document could not be found. It may have been removed.',
                'need_scan' => 'This document has no masterlist scan to review.',
                'same_revision' => 'Pick a different older revision to compare against the latest.',
            ];
            $reviewError = $reviewErrors[$compare['error'] ?? ''] ?? null;
            $olderLabel = $compare['left_label'] ?? 'Older revision';
            $newerLabel = $compare['right_label'] ?? 'Newer revision';
            $priorOptions = $compare['prior_options'] ?? [];
            $pairOptions = $compare['pair_options'] ?? ($pairOptions ?? []);
            $canCompare = !empty($compare['can_compare']);
            $canView = !empty($compare['can_view']) || $canCompare;
        @endphp
        <div class="drr-panel">
            <div class="drr-doc">
                <span class="drr-info-label">Document</span>
                <p class="drr-doc-heading" title="{{ $compare['docTitle'] ?: $selectedDocNo }}">{{ $compare['docTitle'] ?: $selectedDocNo }}</p>
                <span class="drr-docno">{{ $compare['docNo'] ?: $selectedDocNo }}</span>
                @if(($compare['latest_revise_no'] ?? null) !== null)
                    <span class="drr-latest-pill">Latest · Rev {{ $compare['latest_revise_no'] }}</span>
                @endif
            </div>

            @if($reviewError)
                <div class="drr-alert" role="alert">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <p>{{ $reviewError }}</p>
                        <span>Compare only consecutive revisions in the lineage (by Rev No order), e.g. 5→6 or 3→5 if 4 was never registered.</span>
                    </div>
                </div>
            @endif

            <div class="drr-rev-bar">
                <label class="drr-rev-select">
                    Compare pair
                    <select
                        wire:model.live="rightId"
                        @disabled(count($pairOptions) < 1)
                    >
                        @forelse($pairOptions as $po)
                            <option value="{{ $po['right_id'] }}">{{ $po['label'] }}</option>
                        @empty
                            <option value="">No consecutive pairs</option>
                        @endforelse
                    </select>
                </label>

                <div class="drr-latest-lock">
                    <span class="drr-info-label">Older (left)</span>
                    <div class="drr-latest-value">
                        <i class="fa-solid fa-file-lines"></i>
                        {{ $compare['left_label'] ?? '—' }}
                    </div>
                </div>

                <div class="drr-latest-lock">
                    <span class="drr-info-label">Newer (right)</span>
                    <div class="drr-latest-value">
                        <i class="fa-solid fa-file-lines"></i>
                        {{ $compare['right_label'] ?? '—' }}
                    </div>
                </div>

                @if($canCompare)
                    <div class="drr-legend">
                        <span class="drr-leg drr-leg-del">Removed</span>
                        <span class="drr-leg drr-leg-ins">Added</span>
                        <span class="drr-leg drr-leg-chg">Changed</span>
                    </div>
                @endif
            </div>

            @if(!$canView)
                @if(!$reviewError)
                    <div class="drr-empty">
                        <i class="fa-solid fa-code-compare"></i>
                        <p>Nothing to review for this document.</p>
                    </div>
                @endif
            @elseif(count($compare['tabs']) === 0)
                <div class="drr-empty">
                    <p>This document has no masterlist scan to compare.</p>
                </div>
            @else
                @if($pair)
                    <div class="drr-section" wire:key="pair-{{ $tab }}-{{ $leftId }}-{{ $rightId }}">
                        <h2 class="drr-section-title">
                            Masterlist — Scanned PDF
                            @if($canCompare)
                                Comparison
                            @else
                                (Latest)
                            @endif
                        </h2>

                        <div
                            class="drr-scans @if(!$canCompare) drr-scans-single @endif"
                            id="drr-pdf-compare"
                            data-left-url="{{ $canCompare && !empty($pair['left_scan']['is_pdf']) ? ($pair['left_scan']['url'] ?? '') : '' }}"
                            data-right-url="{{ $canCompare && !empty($pair['right_scan']['is_pdf']) ? ($pair['right_scan']['url'] ?? '') : '' }}"
                            data-left-img=""
                            data-right-img="{{ !$canCompare && !empty($pair['right_scan']['url']) && empty($pair['right_scan']['is_pdf']) ? ($pair['right_scan']['url'] ?? '') : '' }}"
                            wire:key="scans-{{ $tab }}-{{ $leftId }}-{{ $rightId }}"
                        >
                            @if($canCompare)
                                @php $scanStatus = $pair['scan_status'] ?? 'none'; @endphp
                                <div class="drr-scan is-{{ $scanStatus }}">
                                    <div class="drr-scan-label">{{ $olderLabel }}{{ $pair['left_scan']['name'] ? ' · ' . $pair['left_scan']['name'] : '' }}</div>
                                    @if($pair['left_scan']['url'] && $pair['left_scan']['is_pdf'])
                                        <div class="drr-pdf-stage" data-review-side="left" wire:ignore></div>
                                        <p class="drr-pdf-note" data-review-note="left"></p>
                                    @elseif($pair['left_scan']['url'])
                                        <img class="drr-scan-img" src="{{ $pair['left_scan']['url'] }}" alt="{{ $olderLabel }} scan">
                                        <p class="drr-muted">This file is not a PDF, so words cannot be highlighted on the page.</p>
                                    @else
                                        <div class="drr-scan-empty">No masterlist scan on this revision</div>
                                    @endif
                                </div>

                                <div class="drr-scan is-{{ $scanStatus }}">
                                    <div class="drr-scan-label">{{ $newerLabel }}{{ $pair['right_scan']['name'] ? ' · ' . $pair['right_scan']['name'] : '' }}</div>
                                    @if($pair['right_scan']['url'] && $pair['right_scan']['is_pdf'])
                                        <div class="drr-pdf-stage" data-review-side="right" wire:ignore></div>
                                        <p class="drr-pdf-note" data-review-note="right"></p>
                                    @elseif($pair['right_scan']['url'])
                                        <img class="drr-scan-img" src="{{ $pair['right_scan']['url'] }}" alt="{{ $newerLabel }} scan">
                                        <p class="drr-muted">This file is not a PDF, so words cannot be highlighted on the page.</p>
                                    @else
                                        <div class="drr-scan-empty">No masterlist scan on this revision</div>
                                    @endif
                                </div>
                            @else
                                {{-- View-only: show whichever scan exists; compare needs both PDFs --}}
                                @php
                                    $viewScan = !empty($pair['right_scan']['url']) ? $pair['right_scan'] : $pair['left_scan'];
                                    $viewLabel = !empty($pair['right_scan']['url']) ? $newerLabel : $olderLabel;
                                @endphp
                                <div class="drr-scan is-same">
                                    <div class="drr-scan-label">{{ $viewLabel }}{{ ($viewScan['name'] ?? null) ? ' · ' . $viewScan['name'] : '' }}</div>
                                    @if(!empty($viewScan['url']) && !empty($viewScan['is_pdf']))
                                        <div class="drr-pdf-stage" data-review-side="right" wire:ignore></div>
                                        <p class="drr-pdf-note" data-review-note="right"></p>
                                    @elseif(!empty($viewScan['url']))
                                        <img class="drr-scan-img" src="{{ $viewScan['url'] }}" alt="scan">
                                    @else
                                        <div class="drr-scan-empty">No masterlist scan on this revision</div>
                                    @endif
                                    @if(empty($pair['left_scan']['url']) || empty($pair['right_scan']['url']))
                                        <p class="drr-muted" style="padding:8px 12px;">
                                            Comparison needs a masterlist PDF on <strong>both</strong> the older revision and the latest.
                                            @if(empty($pair['right_scan']['url']))
                                                Latest (Rev {{ $compare['latest_revise_no'] ?? '—' }}) has no scanned masterlist yet.
                                            @endif
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            @endif
        </div>
    @endif
</div>
