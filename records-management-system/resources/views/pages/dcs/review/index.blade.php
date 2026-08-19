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
                'left_id' => null,
                'right_id' => null,
                'tabs' => [],
                'pairs' => [],
                'can_compare' => false,
                'error' => null,
                'left_label' => 'Older',
                'right_label' => 'Newer',
            ];

        if (($compare['left_id'] ?? null) && (string) $compare['left_id'] !== $this->leftId) {
            $this->leftId = (string) $compare['left_id'];
        }
        if (($compare['right_id'] ?? null) && (string) $compare['right_id'] !== $this->rightId) {
            $this->rightId = (string) $compare['right_id'];
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
        $this->leftId = (string) ($preview['left_id'] ?? '');
        $this->rightId = (string) ($preview['right_id'] ?? '');
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
        $this->normalizeRevisionOrder();
    }

    public function updatedRightId(): void
    {
        $this->normalizeRevisionOrder();
    }

    private function normalizeRevisionOrder(): void
    {
        if ($this->selectedDocNo === '' || $this->leftId === '' || $this->rightId === '') {
            return;
        }

        $preview = RegisterQueryHelper::reviewCompare(
            $this->selectedDocNo,
            (int) $this->leftId,
            (int) $this->rightId
        );
        $this->leftId = (string) ($preview['left_id'] ?? $this->leftId);
        $this->rightId = (string) ($preview['right_id'] ?? $this->rightId);
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
        <p class="drr-hint">You can only compare two revisions of the same document number — not two different documents.</p>

        <div class="drr-table-card">
            <div class="drr-table-scroll" @if(count($list['rows']) === 0) style="display:none" @endif>
                <table class="drr-table">
                    <thead>
                        <tr>
                            <th>Doc Type</th>
                            <th>Title</th>
                            <th>Document No.</th>
                            <th>Current rev</th>
                            <th>Revisions</th>
                            <th>Checklists</th>
                            <th style="width:110px;">Review</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($list['rows'] as $doc)
                            <tr>
                                <td>{{ $doc['doc_type'] }}</td>
                                <td class="drr-doc-title" title="{{ $doc['title'] }}">{{ $doc['title'] }}</td>
                                <td class="drr-doc-no">{{ $doc['doc_no'] }}</td>
                                <td>{{ $doc['rev_no'] }}</td>
                                <td>{{ $doc['rev_count'] }}</td>
                                <td>
                                    @foreach($doc['checklists'] as $cl)
                                        <span class="drr-chip">{{ $cl }}</span>
                                    @endforeach
                                </td>
                                <td>
                                    <button type="button" class="drr-btn-review" wire:click="selectDocument(@js($doc['doc_no']))">
                                        Compare
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
                    <span>Register a document and at least one later revision. Comparison is only between revisions of the same document.</span>
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
                'no_doc_no' => 'This record has no document number, so its revisions cannot be compared.',
                'not_found' => 'This document could not be found. It may have been removed.',
                'need_two_revisions' => 'Only one revision is registered. Comparison needs two revisions of this same document.',
                'same_revision' => 'Older and newer must be two different revisions of this document.',
            ];
            $reviewError = $reviewErrors[$compare['error'] ?? ''] ?? null;
            $olderLabel = $compare['left_label'] ?? 'Older';
            $newerLabel = $compare['right_label'] ?? 'Newer';
        @endphp
        <div class="drr-panel">
            <div class="drr-doc">
                <span class="drr-info-label">Document</span>
                <p class="drr-doc-heading" title="{{ $compare['docTitle'] ?: $selectedDocNo }}">{{ $compare['docTitle'] ?: $selectedDocNo }}</p>
                <span class="drr-docno">{{ $compare['docNo'] ?: $selectedDocNo }}</span>
            </div>

            @if($reviewError)
                <div class="drr-alert" role="alert">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <p>{{ $reviewError }}</p>
                        <span>You can only compare revisions that belong to this document number.</span>
                    </div>
                </div>
            @endif

            @if(count($compare['options']) > 0)
                <div class="drr-rev-bar">
                    <label>
                        Older (left)
                        <select wire:model.live="leftId" @disabled(count($compare['options']) < 2)>
                            @foreach($compare['options'] as $opt)
                                <option value="{{ $opt['id'] }}">{{ $opt['label'] }} — {{ $opt['created_label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        Newer (right)
                        <select wire:model.live="rightId" @disabled(count($compare['options']) < 2)>
                            @foreach($compare['options'] as $opt)
                                <option value="{{ $opt['id'] }}">{{ $opt['label'] }} — {{ $opt['created_label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if($compare['can_compare'])
                        <div class="drr-legend">
                            <span class="drr-leg drr-leg-del">Removed</span>
                            <span class="drr-leg drr-leg-ins">Added</span>
                            <span class="drr-leg drr-leg-chg">Changed</span>
                        </div>
                    @endif
                </div>
            @endif

            @if(!$compare['can_compare'])
                @if(!$reviewError)
                    <div class="drr-empty">
                        <i class="fa-solid fa-code-compare"></i>
                        <p>Nothing to compare for this document.</p>
                    </div>
                @endif
            @elseif(count($compare['tabs']) === 0)
                <div class="drr-empty">
                    <p>This document has no registered checklist data to compare.</p>
                </div>
            @else
                <div class="drr-tabs">
                    @foreach($compare['tabs'] as $cl)
                        <button
                            type="button"
                            class="drr-tab {{ $activeTab === $cl['key'] ? 'is-active' : '' }}"
                            wire:click="setTab(@js($cl['key']))"
                        >{{ $cl['label'] }}</button>
                    @endforeach
                </div>

                @if($pair)
                    <div class="drr-section" wire:key="pair-{{ $tab }}-{{ $leftId }}-{{ $rightId }}">
                        <h2 class="drr-section-title">{{ $pair['title'] }}</h2>

                        @if($activeTab === 'syllabi' && $pair['syllabi'])
                            @if(!empty($pair['syllabi']['meta']))
                                <table class="drr-compare-table">
                                    <thead>
                                        <tr>
                                            <th>Field</th>
                                            <th>{{ $olderLabel }}</th>
                                            <th>{{ $newerLabel }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($pair['syllabi']['meta'] as $row)
                                            <tr class="is-{{ $row['status'] }}">
                                                <td class="drr-field-name">{{ $row['label'] }}</td>
                                                <td class="is-{{ $row['status'] }}">{!! $row['left_html'] !!}</td>
                                                <td class="is-{{ $row['status'] }}">{!! $row['right_html'] !!}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                            <div class="drr-table-scroll">
                                <table class="drr-syl-table">
                                    <thead>
                                        <tr>
                                            <th>Course</th>
                                            <th>Available<br><span class="drr-th-sub">{{ $olderLabel }} / {{ $newerLabel }}</span></th>
                                            <th>Copies<br><span class="drr-th-sub">{{ $olderLabel }} / {{ $newerLabel }}</span></th>
                                            <th>Pages<br><span class="drr-th-sub">{{ $olderLabel }} / {{ $newerLabel }}</span></th>
                                            <th>Received<br><span class="drr-th-sub">{{ $olderLabel }} / {{ $newerLabel }}</span></th>
                                            <th>Faculty<br><span class="drr-th-sub">{{ $olderLabel }} / {{ $newerLabel }}</span></th>
                                            <th>DRF<br><span class="drr-th-sub">{{ $olderLabel }} / {{ $newerLabel }}</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($pair['syllabi']['courses'] as $course)
                                            <tr class="is-{{ $course['status'] }}">
                                                @foreach(['course', 'avail', 'copies', 'pages', 'received', 'faculty', 'drf'] as $field)
                                                    <td class="is-{{ $course['cells'][$field]['status'] }}">
                                                        <div class="drr-cell-pair">
                                                            <div><span class="drr-side">Older</span> {!! $course['cells'][$field]['left_html'] !!}</div>
                                                            <div><span class="drr-side">Newer</span> {!! $course['cells'][$field]['right_html'] !!}</div>
                                                        </div>
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @empty
                                            <tr><td colspan="7">No courses registered.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <table class="drr-compare-table">
                                <thead>
                                    <tr>
                                        <th>Field</th>
                                        <th>{{ $olderLabel }}</th>
                                        <th>{{ $newerLabel }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($pair['rows'] as $row)
                                        @if(($row['kind'] ?? '') === 'offices')
                                            <tr>
                                                <td colspan="3" class="drr-nested-cell">
                                                    <span class="drr-field-label">{{ $row['label'] }}</span>
                                                    <table class="drr-office-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Office</th>
                                                                <th>{{ !empty($row['with_copies']) ? $olderLabel . ' copies' : $olderLabel }}</th>
                                                                <th>{{ !empty($row['with_copies']) ? $newerLabel . ' copies' : $newerLabel }}</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @forelse($row['offices'] as $office)
                                                                <tr class="is-{{ $office['status'] }}">
                                                                    <td class="drr-office-name">{{ $office['name'] }}</td>
                                                                    <td class="is-{{ $office['status'] }}">{!! $office['left_html'] !!}</td>
                                                                    <td class="is-{{ $office['status'] }}">{!! $office['right_html'] !!}</td>
                                                                </tr>
                                                            @empty
                                                                <tr><td colspan="3">No offices listed.</td></tr>
                                                            @endforelse
                                                        </tbody>
                                                    </table>
                                                </td>
                                            </tr>
                                        @else
                                            <tr class="is-{{ $row['status'] }}">
                                                <td class="drr-field-name">{{ $row['label'] }}</td>
                                                <td class="is-{{ $row['status'] }}">{!! $row['left_html'] !!}</td>
                                                <td class="is-{{ $row['status'] }}">{!! $row['right_html'] !!}</td>
                                            </tr>
                                        @endif
                                    @empty
                                        <tr><td colspan="3">No field data for this checklist on the selected revisions.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        @endif

                        <h3 class="drr-scans-title">Scanned copies</h3>
                        <div
                            class="drr-scans"
                            id="drr-pdf-compare"
                            data-left-url="{{ $pair['left_scan']['is_pdf'] ? ($pair['left_scan']['url'] ?? '') : '' }}"
                            data-right-url="{{ $pair['right_scan']['is_pdf'] ? ($pair['right_scan']['url'] ?? '') : '' }}"
                            data-left-img="{{ !$pair['left_scan']['is_pdf'] ? ($pair['left_scan']['url'] ?? '') : '' }}"
                            data-right-img="{{ !$pair['right_scan']['is_pdf'] ? ($pair['right_scan']['url'] ?? '') : '' }}"
                            wire:key="scans-{{ $tab }}-{{ $leftId }}-{{ $rightId }}"
                        >
                            @foreach([
                                ['side' => 'left', 'scan' => $pair['left_scan'], 'label' => $olderLabel],
                                ['side' => 'right', 'scan' => $pair['right_scan'], 'label' => $newerLabel],
                            ] as $pane)
                                @php $scanStatus = $pair['scan_status'] ?? 'none'; @endphp
                                <div class="drr-scan is-{{ $scanStatus }}">
                                    <div class="drr-scan-label">{{ $pane['label'] }}{{ $pane['scan']['name'] ? ' · ' . $pane['scan']['name'] : '' }}</div>
                                    @if($pane['scan']['url'] && $pane['scan']['is_pdf'])
                                        <div class="drr-pdf-stage" data-review-side="{{ $pane['side'] }}" wire:ignore></div>
                                        <p class="drr-pdf-note" data-review-note="{{ $pane['side'] }}"></p>
                                    @elseif($pane['scan']['url'])
                                        <img class="drr-scan-img" src="{{ $pane['scan']['url'] }}" alt="{{ $pane['label'] }} scan">
                                        <p class="drr-muted">This file is not a PDF, so words cannot be highlighted on the page.</p>
                                    @else
                                        <div class="drr-scan-empty">No scan on this revision</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </div>
    @endif
</div>
