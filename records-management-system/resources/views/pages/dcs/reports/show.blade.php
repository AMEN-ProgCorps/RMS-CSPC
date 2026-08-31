<?php

use App\Helpers\RegisterQueryHelper;
use App\Helpers\ReportHelper;
use App\Helpers\ReportTemplateHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
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
    public bool $exportOpen = false;
    public bool $filterOpen = false;
    public string $error = '';
    public array $result = [];
    public string $templateId = '0';
    public string $templateStatus = '';

    public function mount(): void
    {
        $this->asOf = now('Asia/Manila')->toDateString();
        $this->category = match (request()->route()?->getName()) {
            'dcs.reports.monitoring' => 'monitoring',
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

    public function with(): array
    {
        $allDocTypes = DB::table('dcs_doc_types')->orderBy('id')->get(['id', 'doc_type_name', 'parent_id']);
        $parentId = RegisterQueryHelper::parentTypeIdMap()[$this->sub] ?? null;
        $childTypes = $parentId
            ? $allDocTypes->filter(fn ($d) => (string) $d->parent_id === (string) $parentId)->values()
            : collect();

        return [
            'originators' => Schema::hasTable('dcs_originators')
                ? DB::table('dcs_originators')->orderBy('originator_name')->get()
                : collect(),
            'offices' => DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('is_active', true)->orderBy('office_name')->get(),
            'revisionNos' => DB::table('dcs_masterlist_registration')
                ->whereNotNull('revise_no')
                ->distinct()
                ->orderBy('revise_no')
                ->pluck('revise_no'),
            'allDocTypes' => $allDocTypes,
            'childTypes' => $childTypes,
            'isOpcr' => $this->category === 'opcr',
            'templates' => ReportTemplateHelper::list(),
            'pageTitle' => match ($this->category) {
                'monitoring' => 'Monitoring Reports',
                'opcr' => 'OPCR Targets',
                'others' => 'General Report',
                default => 'Document Masterlist',
            },
        ];
    }

    public function selectSub(string $sub): void
    {
        $this->sub = $sub;
        $parentId = RegisterQueryHelper::parentTypeIdMap()[$sub] ?? null;
        if ($parentId) {
            $this->subTypeIds = DB::table('dcs_doc_types')
                ->where('parent_id', $parentId)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();
        } else {
            $this->subTypeIds = [];
        }
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
        }
        $this->loadReport();
    }

    public function openFilters(): void
    {
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
        if ($this->category === 'others' && in_array($name, ['period', 'asOf', 'dateFrom', 'dateTo', 'originator', 'sourceUnit', 'revisionStatus', 'revNo', 'subTypeIds'], true)) {
            $this->loadReport();
        }
    }

    public function selectAllSubTypes(): void
    {
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
        $this->error = '';
        $input = $this->queryInput();
        if (($this->category !== 'others') && $this->sub === '') {
            return;
        }
        $this->result = ReportHelper::payload($input);
        if (! empty($this->result['error'])) {
            $this->error = $this->result['error'];
            $this->result['rows'] = $this->result['rows'] ?? [];
            $this->result['columns'] = $this->result['columns'] ?? [];
        }
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

        $rows = $this->result['rows'] ?? [];
        foreach ($rows as $i => $r) {
            if ((int) ($r['request_id'] ?? 0) !== $requestId) {
                continue;
            }
            if ($field === 'remarks') {
                $rows[$i]['remarks'] = $saved;
                $rows[$i]['remarks_override'] = $saved;
            } else {
                $rows[$i][$field] = $saved;
            }
            break;
        }
        $this->result['rows'] = $rows;
    }

    public function exportUrl(string $format): string
    {
        $query = $this->queryInput(forExport: true);
        // Always print via PDF in a blank window so Chrome does not inject URL/timestamp headers
        if ($format === 'print') {
            $query['format'] = 'pdf';
            $query['inline'] = 1;
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
            'template_id' => $this->templateId !== '0' ? $this->templateId : null,
        ];
        if ($this->period === 'custom') {
            $input['date_from'] = $this->dateFrom;
            $input['date_to'] = $this->dateTo;
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

        if (!$forExport && $this->category === 'monitoring') {
            if ($this->monitoringDocType !== '') {
                $input['ui_doc_type'] = $this->monitoringDocType;
            }
            if ($this->monitoringSubTypeIds !== []) {
                $input['ui_sub_type_ids'] = implode(',', $this->monitoringSubTypeIds);
            }
        }

        return array_filter($input, fn ($v) => $v !== '' && $v !== null);
    }

    public function selectTemplate(string $id): void
    {
        $this->templateId = $id;
    }

    public function importTemplate(): void
    {
        // Handled client-side; this method re-renders the template gallery.
    }
}; ?>

<main class="rpt-page" id="rptPage">
    @teleport('body')
    <div class="rpt-filter-portal" @if($filterOpen) data-open="1" @endif>
        <div
            class="rpt-filter-overlay {{ $filterOpen ? 'visible' : '' }}"
            wire:click="closeFilters"
            @if(!$filterOpen) style="pointer-events:none;" @endif
        ></div>
        <aside
            class="rpt-filter-panel {{ $filterOpen ? 'open' : '' }}"
            id="rptFilterPanel"
            role="dialog"
            aria-modal="true"
            aria-label="Report filters"
            @if(!$filterOpen) aria-hidden="true" @endif
        >
            <div class="rpt-filter-panel-head">
                <h3><i class="fa-solid fa-filter"></i> Filters</h3>
                <button type="button" class="rpt-filter-close" wire:click="closeFilters" aria-label="Close filters">&times;</button>
            </div>
            <div class="rpt-filter-form">
                <div class="rpt-filter-group">
                    <label>Report period</label>
                    <select wire:model="period">
                        @if(in_array($category, ['masterlist', 'monitoring', 'opcr'], true))
                            <option value="all">All time</option>
                        @endif
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="annually">Annually</option>
                        <option value="custom">Custom range</option>
                    </select>
                </div>
                @if($period === 'custom')
                    <div class="rpt-filter-group">
                        <label>Date from</label>
                        <input type="date" wire:model="dateFrom">
                    </div>
                    <div class="rpt-filter-group">
                        <label>Date to</label>
                        <input type="date" wire:model="dateTo">
                    </div>
                @else
                    <div class="rpt-filter-group">
                        <label>As of</label>
                        <input type="date" wire:model="asOf">
                    </div>
                @endif
                <div class="rpt-filter-group">
                    <label>Originator</label>
                    <select wire:model="originator">
                        <option value="">All Originators</option>
                        @foreach($originators as $o)
                            <option value="{{ $o->originator_name }}">{{ $o->originator_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rpt-filter-group">
                    <label>Source Office</label>
                    <select wire:model="sourceUnit">
                        <option value="">All Offices</option>
                        @foreach($offices as $o)
                            <option value="{{ $o->id }}">{{ $o->office_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rpt-filter-group">
                    <label>Revision</label>
                    <select wire:model="revisionStatus">
                        <option value="all">All revisions</option>
                        <option value="latest">Latest only</option>
                        <option value="obsolete">Obsolete only</option>
                    </select>
                </div>
                <div class="rpt-filter-group">
                    <label>Revision No.</label>
                    <select wire:model="revNo">
                        <option value="">Any</option>
                        @forelse($revisionNos as $rev)
                            <option value="{{ $rev }}">{{ $rev }}</option>
                        @empty
                            @for($i = 0; $i <= 10; $i++)
                                <option value="{{ $i }}">{{ $i }}</option>
                            @endfor
                        @endforelse
                    </select>
                </div>
                @if($childTypes->isNotEmpty())
                    <div class="rpt-subtype-block">
                        <div class="rpt-subtype-head">
                            <span class="rpt-subtype-title">Sub-types</span>
                            <button type="button" class="rpt-link-btn" wire:click="selectAllSubTypes">Select all</button>
                            <button type="button" class="rpt-link-btn" wire:click="clearSubTypes">Clear</button>
                        </div>
                        <div class="rpt-subtype-grid">
                            @foreach($childTypes as $child)
                                <label class="rpt-subtype-item">
                                    <input type="checkbox" value="{{ $child->id }}" wire:model="subTypeIds">
                                    <span>{{ $child->doc_type_name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if($category === 'monitoring' && in_array($sub, ['internal_docs', 'external_docs', 'internal_forms', 'forms', 'logbooks'], true))
                    <div class="rpt-filter-sep">Preview-only filters (not included in export)</div>
                    <div class="rpt-filter-group">
                        <label>Document type</label>
                        <select wire:model="monitoringDocType">
                            <option value="">Use tab default</option>
                            <option value="Internal">Internal</option>
                            <option value="External">External</option>
                            <option value="Internal Forms">Internal Forms</option>
                            <option value="Forms">Forms</option>
                            <option value="Logbooks">Logbooks</option>
                        </select>
                    </div>
                    @php
                        $monitorParentId = RegisterQueryHelper::parentTypeIdMap()[$sub] ?? null;
                        $monitorChildTypes = $monitorParentId
                            ? $allDocTypes->filter(fn ($d) => (string) $d->parent_id === (string) $monitorParentId)->values()
                            : collect();
                    @endphp
                    @if($monitorChildTypes->isNotEmpty())
                        <div class="rpt-subtype-block">
                            <div class="rpt-subtype-head">
                                <span class="rpt-subtype-title">Sub-types (preview)</span>
                            </div>
                            <div class="rpt-subtype-grid">
                                @foreach($monitorChildTypes as $child)
                                    <label class="rpt-subtype-item">
                                        <input type="checkbox" value="{{ $child->id }}" wire:model="monitoringSubTypeIds">
                                        <span>{{ $child->doc_type_name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif
            </div>
            <div class="rpt-filter-panel-foot">
                <button type="button" class="rpt-btn rpt-btn-outline" wire:click="resetFilters">Reset</button>
                <button type="button" class="rpt-btn rpt-btn-primary" wire:click="applyFilters">Apply Filters</button>
            </div>
        </aside>
    </div>
    @endteleport

    <header class="rpt-hdr">
        <div>
            <div class="rpt-crumb">Document Control System / Generate Report /<span> {{ $pageTitle }}</span></div>
            <h1>{{ $pageTitle }}</h1>
        </div>
    </header>

    @if($category !== 'others')
        <nav class="rpt-subs visible" aria-label="Report types">
            @if($category === 'opcr')
                <button class="rpt-sub {{ $sub === 'update_masterlist' ? 'active' : '' }}" type="button" wire:click="selectSub('update_masterlist')">Updating of Masterlist</button>
                <button class="rpt-sub {{ $sub === 'issuance_internal' ? 'active' : '' }}" type="button" wire:click="selectSub('issuance_internal')">Issuance of Internal</button>
                <button class="rpt-sub {{ $sub === 'issuance_external' ? 'active' : '' }}" type="button" wire:click="selectSub('issuance_external')">Issuance of External</button>
                <button class="rpt-sub {{ $sub === 'control_forms' ? 'active' : '' }}" type="button" wire:click="selectSub('control_forms')">Controlling of Forms</button>
                <button class="rpt-sub {{ $sub === 'control_logbooks' ? 'active' : '' }}" type="button" wire:click="selectSub('control_logbooks')">Controlling of Logbooks</button>
                <button class="rpt-sub {{ $sub === 'control_internal_forms' ? 'active' : '' }}" type="button" wire:click="selectSub('control_internal_forms')">Controlling of Internal Forms</button>
            @else
                <button class="rpt-sub {{ $sub === 'internal_docs' ? 'active' : '' }}" type="button" wire:click="selectSub('internal_docs')">Internal</button>
                <button class="rpt-sub {{ $sub === 'external_docs' ? 'active' : '' }}" type="button" wire:click="selectSub('external_docs')">External</button>
                <button class="rpt-sub {{ $sub === 'internal_forms' ? 'active' : '' }}" type="button" wire:click="selectSub('internal_forms')">Internal Forms</button>
                <button class="rpt-sub {{ $sub === 'forms' ? 'active' : '' }}" type="button" wire:click="selectSub('forms')">Forms</button>
                <button class="rpt-sub {{ $sub === 'logbooks' ? 'active' : '' }}" type="button" wire:click="selectSub('logbooks')">Logbooks</button>
                @if($category === 'monitoring')
                    <button class="rpt-sub {{ $sub === 'drf' ? 'active' : '' }}" type="button" wire:click="selectSub('drf')">DRF</button>
                    <button class="rpt-sub {{ $sub === 'dcn' ? 'active' : '' }}" type="button" wire:click="selectSub('dcn')">DCN</button>
                @endif
            @endif
        </nav>
    @endif

    @if($sub !== '' || $category === 'others')
        <section class="rpt-results">
            <div class="rpt-results-head">
                <div class="rpt-results-meta">
                    <h3>{{ $result['title'] ?? 'Report Preview' }}</h3>
                    <span class="rpt-results-count">{{ $result['total_rows'] ?? 0 }} records</span>
                </div>
                <div class="rpt-results-actions">
                    <button type="button" class="rpt-btn rpt-btn-outline" wire:click="openFilters">
                        <i class="fa-solid fa-filter"></i> Filter
                    </button>
                    <div class="rpt-export-wrap" :class="{ open: open }" x-data="{ open: false }" @click.outside="open = false">
                        <button class="rpt-btn rpt-btn-outline" type="button" @click="open = !open">
                            <i class="fa-solid fa-download"></i> Export
                            <i class="fa-solid fa-chevron-down rpt-chevron"></i>
                        </button>
                        <div class="rpt-export-menu" :class="{ open: open }">
                            <a class="rpt-export-item" data-format="pdf" href="{{ $this->exportUrl('pdf') }}" target="_blank" rel="noopener">
                                <i class="fa-solid fa-file-pdf"></i>
                                <span>Download as PDF</span>
                            </a>
                            <a class="rpt-export-item" data-format="csv" href="{{ $this->exportUrl('csv') }}">
                                <i class="fa-solid fa-file-csv"></i>
                                <span>Download as CSV</span>
                            </a>
                            <div class="rpt-export-sep"></div>
                            <a class="rpt-export-item" data-format="print" href="{{ $this->exportUrl('print') }}" target="_blank" rel="noopener">
                                <i class="fa-solid fa-print"></i>
                                <span>Print Report</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <section class="rpt-template-picker" x-data="dcsReportTemplates()">
                <div class="rpt-template-picker-head">
                    <div>
                        <strong>Letterhead template</strong>
                        <span>Choose a template to apply to this report. Imported PDFs show page 1 as the preview.</span>
                    </div>
                    <label class="rpt-btn rpt-btn-outline rpt-template-upload">
                        <i class="fa-solid fa-file-import"></i> Import PDF
                        <input type="file" accept="application/pdf" @change="upload($event)" hidden>
                    </label>
                </div>
                <p class="rpt-template-status" x-show="status" x-text="status"></p>
                <div class="rpt-tpl-grid">
                    <button type="button" class="rpt-tpl-card {{ $templateId === '0' ? 'is-active' : '' }}" wire:click="selectTemplate('0')">
                        <div class="rpt-tpl-preview rpt-tpl-preview--builtin">
                            <span>CSPC</span>
                            Built-in header
                        </div>
                        <span class="rpt-tpl-name">Built-in DCS</span>
                    </button>
                    @forelse($templates as $tpl)
                        <div class="rpt-tpl-card {{ (string) $templateId === (string) $tpl['id'] ? 'is-active' : '' }}" wire:click="selectTemplate('{{ $tpl['id'] }}')" role="button" tabindex="0">
                            <button type="button" class="rpt-tpl-delete" title="Delete template" @click.stop.prevent="remove({{ (int) $tpl['id'] }})">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                            @if(!empty($tpl['preview_url']))
                                <img class="rpt-tpl-preview" src="{{ $tpl['preview_url'] }}" alt="{{ $tpl['name'] }}">
                            @else
                                <div class="rpt-tpl-preview rpt-tpl-preview--builtin">No preview</div>
                            @endif
                            <span class="rpt-tpl-name">{{ $tpl['name'] }}</span>
                        </div>
                    @empty
                    @endforelse
                </div>
            </section>

            <div class="rpt-preview-shell {{ $isOpcr ? 'rpt-preview-shell--opcr' : 'rpt-preview-shell--frame' }}">
                @if($error)
                    <div class="rpt-state">
                        <div class="rpt-state-icon state-error"><i class="fa-solid fa-circle-exclamation"></i></div>
                        <h4>Error</h4>
                        <p>{{ $error }}</p>
                    </div>
                @elseif($isOpcr)
                    @php
                        $cols = $result['columns'] ?? [];
                        $rows = $result['rows'] ?? [];
                        $groups = $result['group_headers'] ?? [];
                        $keys = array_keys($cols);
                        $rowCount = count($rows);
                        $rowsPerPage = 12;
                        $pageTotal = max(1, (int) ceil(max($rowCount, 1) / $rowsPerPage));
                        $logoPath = public_path('images/logo.png');
                        $logoSrc = file_exists($logoPath) ? asset('images/logo.png') : '';
                    @endphp
                    <div class="opcr-doc">
                        <div class="opcr-doc-header">
                            <div class="opcr-doc-brand">
                                @if($logoSrc)
                                    <img src="{{ $logoSrc }}" alt="" class="opcr-doc-logo">
                                @endif
                                <div>
                                    <div class="opcr-doc-republic">Republic of the Philippines</div>
                                    <div class="opcr-doc-name">Camarines Sur Polytechnic Colleges</div>
                                    <div class="opcr-doc-loc">Nabua, Camarines Sur</div>
                                </div>
                            </div>
                            <div class="opcr-doc-rule"></div>
                            <h2 class="opcr-doc-title">{{ $result['title'] ?? 'OPCR Targets' }}</h2>
                        </div>

                        <div class="rpt-table-scroll opcr-doc-body">
                            <table class="rpt-table">
                                <thead>
                                    @php
                                        $hasGroups = collect($groups)->contains(fn ($g) => $g !== null && $g !== '');
                                    @endphp
                                    @if($hasGroups)
                                        <tr>
                                            @php $i = 0; @endphp
                                            @while($i < count($keys))
                                                @php
                                                    $key = $keys[$i];
                                                    $group = $groups[$key] ?? null;
                                                @endphp
                                                @if($group === null || $group === '')
                                                    <th rowspan="2">{{ $cols[$key] }}</th>
                                                    @php $i++; @endphp
                                                @else
                                                    @php
                                                        $span = 1;
                                                        while ($i + $span < count($keys) && ($groups[$keys[$i + $span]] ?? null) === $group) {
                                                            $span++;
                                                        }
                                                    @endphp
                                                    <th colspan="{{ $span }}">{{ $group }}</th>
                                                    @php $i += $span; @endphp
                                                @endif
                                            @endwhile
                                        </tr>
                                        <tr>
                                            @foreach($keys as $key)
                                                @if(($groups[$key] ?? null) !== null && ($groups[$key] ?? null) !== '')
                                                    <th @class(['opcr-rating-th' => in_array($key, ['rating_q', 'rating_e', 'rating_t', 'rating_a'], true)])>{{ $cols[$key] }}</th>
                                                @endif
                                            @endforeach
                                        </tr>
                                    @else
                                        <tr>
                                            @foreach($keys as $key)
                                                <th @class(['opcr-rating-th' => in_array($key, ['rating_q', 'rating_e', 'rating_t', 'rating_a'], true)])>{{ $cols[$key] }}</th>
                                            @endforeach
                                        </tr>
                                    @endif
                                </thead>
                                <tbody>
                                    @forelse($rows as $row)
                                        <tr>
                                            @foreach($keys as $key)
                                                @if(in_array($key, ['rating_q', 'rating_e', 'rating_t', 'rating_a'], true))
                                                    <td class="opcr-rating-td">
                                                        <input type="number" class="opcr-rating-input" min="1" max="5" step="1" inputmode="numeric"
                                                            wire:key="opcr-{{ (int) $row['request_id'] }}-{{ $key }}"
                                                            value="{{ $row[$key] !== null && $row[$key] !== '' ? (int) $row[$key] : '' }}"
                                                            x-on:change="$wire.saveRatingField({{ (int) $row['request_id'] }}, '{{ $key }}', $event.target.value)"
                                                            x-on:blur="$wire.saveRatingField({{ (int) $row['request_id'] }}, '{{ $key }}', $event.target.value)">
                                                    </td>
                                                @elseif($key === 'days_diff')
                                                    <td class="opcr-days-td {{ ($row[$key] ?? 0) > 0 ? 'opcr-days-advanced' : (($row[$key] ?? 0) < 0 ? 'opcr-days-delayed' : 'opcr-days-zero') }}">
                                                        {{ $row[$key] === null ? '—' : (($row[$key] > 0 ? '+' : '') . $row[$key]) }}
                                                    </td>
                                                @elseif($key === 'remarks')
                                                    <td class="opcr-remarks-td">
                                                        <input type="text" class="opcr-remarks-input" placeholder="Enter remarks"
                                                            wire:key="opcr-{{ (int) $row['request_id'] }}-remarks"
                                                            value="{{ $row['remarks_override'] ?? ($row[$key] ?? '') }}"
                                                            x-on:change="$wire.saveRatingField({{ (int) $row['request_id'] }}, 'remarks', $event.target.value)"
                                                            x-on:blur="$wire.saveRatingField({{ (int) $row['request_id'] }}, 'remarks', $event.target.value)">
                                                    </td>
                                                @elseif(in_array($key, ['item_no', 'no'], true))
                                                    <td>{{ ($row[$key] ?? '') !== '' && ($row[$key] ?? null) !== null ? $row[$key] : '' }}</td>
                                                @elseif(in_array($key, ['doc_no', 'control_number', 'doc_number'], true))
                                                    <td class="rpt-doc-no"><strong>{{ $row[$key] ?: '—' }}</strong></td>
                                                @else
                                                    <td>{{ $row[$key] ?: '—' }}</td>
                                                @endif
                                            @endforeach
                                        </tr>
                                    @empty
                                        <tr><td colspan="{{ max(count($keys), 1) }}"><div class="rpt-state"><h4>No records found</h4></div></td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <div class="opcr-doc-footer">
                            <div class="opcr-doc-footer-rule"></div>
                            <div class="opcr-doc-footer-row">
                                <span>Effectivity Date:</span>
                                <span>Rev.</span>
                                <span>Page 1 of {{ $pageTotal }}</span>
                            </div>
                        </div>
                    </div>
                @else
                    <iframe class="rpt-preview-frame" title="Report preview" src="{{ $this->previewUrl() }}" wire:key="preview-{{ $category }}-{{ $sub }}-{{ $period }}-{{ $asOf }}-{{ $dateFrom }}-{{ $dateTo }}-{{ $templateId }}-{{ md5(json_encode([$originator, $sourceUnit, $revisionStatus, $revNo, $subTypeIds, $monitoringDocType, $monitoringSubTypeIds])) }}"></iframe>
                @endif
            </div>
        </section>
    @elseif($category !== 'others')
        <div class="rpt-state rpt-state-pick">
            <div class="rpt-state-icon"><i class="fa-solid fa-file-lines"></i></div>
            <h4>Select a document type</h4>
            <p>Choose Internal, External, Forms, or another type above. Then pick a letterhead template to generate the report.</p>
        </div>
    @endif
</main>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('dcsReportTemplates', () => ({
        status: '',
        csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },
        async upload(event) {
            const file = event.target.files?.[0];
            event.target.value = '';
            if (!file) return;
            this.status = 'Uploading template...';
            const body = new FormData();
            body.append('template', file);
            body.append('name', file.name.replace(/\.pdf$/i, ''));
            try {
                const res = await fetch('/dcs/api/report-templates', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                    body,
                });
                const data = await res.json();
                if (!res.ok) {
                    this.status = data.message || 'Upload failed.';
                    return;
                }
                this.status = 'Template imported.';
                if (this.$wire) {
                    await this.$wire.selectTemplate(String(data.id));
                    await this.$wire.$refresh();
                }
            } catch (e) {
                this.status = 'Upload failed.';
            }
        },
        async remove(id) {
            if (!confirm('Delete this letterhead template?')) return;
            this.status = 'Deleting template...';
            try {
                const res = await fetch('/dcs/api/report-templates/' + id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    this.status = data.message || 'Could not delete template.';
                    return;
                }
                this.status = 'Template deleted.';
                if (this.$wire) {
                    if (String(this.$wire.templateId) === String(id)) {
                        await this.$wire.selectTemplate('0');
                    }
                    await this.$wire.$refresh();
                }
            } catch (e) {
                this.status = 'Could not delete template.';
            }
        },
    }));
});
</script>
