<?php

use App\Helpers\RegisterQueryHelper;
use App\Helpers\ReportHelper;
use App\Helpers\ReportTemplateHelper;
use Illuminate\Support\Facades\DB;
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
    public bool $exportOpen = false;
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
            'originators' => DB::table('dcs_originators')->orderBy('originator_name')->get(),
            'offices' => DB::table('office')->where('is_active', true)->orderBy('office_name')->get(),
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
        $this->loadReport();
    }

    public function updated($name): void
    {
        if (in_array($name, ['period', 'asOf', 'dateFrom', 'dateTo', 'originator', 'sourceUnit', 'revisionStatus', 'revNo', 'subTypeIds'], true)) {
            if ($this->category === 'others' || $this->sub !== '') {
                $this->loadReport();
            }
        }
    }

    public function selectAllSubTypes(): void
    {
        $this->selectSub($this->sub);
    }

    public function clearSubTypes(): void
    {
        $this->subTypeIds = [];
        $this->loadReport();
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

    public function saveRatingField(int $requestId, string $field, $value): void
    {
        $row = collect($this->result['rows'] ?? [])->firstWhere('request_id', $requestId) ?? [];
        request()->merge([
            'request_id' => $requestId,
            'sub' => $this->sub,
            'rating_q' => $field === 'rating_q' ? $value : ($row['rating_q'] ?? null),
            'rating_e' => $field === 'rating_e' ? $value : ($row['rating_e'] ?? null),
            'rating_t' => $field === 'rating_t' ? $value : ($row['rating_t'] ?? null),
            'rating_a' => $field === 'rating_a' ? $value : ($row['rating_a'] ?? null),
        ]);
        app(ReportHelper::class)->saveOpcrRatings(request());
        $this->loadReport();
    }

    public function exportUrl(string $format): string
    {
        $query = $this->queryInput();
        $query['format'] = $format === 'print' ? 'html' : $format;
        if ($format === 'print') {
            $query['autoPrint'] = 1;
        }

        return route('dcs.reports.export', $query);
    }

    private function queryInput(): array
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
        if ($allIds && count($this->subTypeIds) > 0 && count($this->subTypeIds) < count($allIds)) {
            $input['sub_type_ids'] = implode(',', $this->subTypeIds);
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

    <section class="rpt-inline-filters" @if($category !== 'others' && $sub === '') hidden @endif>
        <div class="rpt-inline-filters-grid">
            <div class="rpt-filter-group">
                <label>Report period</label>
                <select wire:model.live="period">
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="annually">Annually</option>
                    <option value="custom">Custom range</option>
                </select>
            </div>
            @if($period === 'custom')
                <div class="rpt-filter-group">
                    <label>Date from</label>
                    <input type="date" wire:model.live="dateFrom">
                </div>
                <div class="rpt-filter-group">
                    <label>Date to</label>
                    <input type="date" wire:model.live="dateTo">
                </div>
            @else
                <div class="rpt-filter-group">
                    <label>As of</label>
                    <input type="date" wire:model.live="asOf">
                </div>
            @endif
            <div class="rpt-filter-group">
                <label>Originator</label>
                <select wire:model.live="originator">
                    <option value="">All Originators</option>
                    @foreach($originators as $o)
                        <option value="{{ $o->originator_name }}">{{ $o->originator_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="rpt-filter-group">
                <label>Source Office</label>
                <select wire:model.live="sourceUnit">
                    <option value="">All Offices</option>
                    @foreach($offices as $o)
                        <option value="{{ $o->id }}">{{ $o->office_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="rpt-filter-group">
                <label>Revision</label>
                <select wire:model.live="revisionStatus">
                    <option value="all">All revisions</option>
                    <option value="latest">Latest only</option>
                    <option value="obsolete">Obsolete only</option>
                </select>
            </div>
            <div class="rpt-filter-group">
                <label>Revision No.</label>
                <select wire:model.live="revNo">
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
                            <input type="checkbox" value="{{ $child->id }}" wire:model.live="subTypeIds">
                            <span>{{ $child->doc_type_name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif
    </section>

    @if($sub !== '' || $category === 'others')
        <section class="rpt-results">
            <div class="rpt-results-head">
                <div class="rpt-results-meta">
                    <h3>{{ $result['title'] ?? 'Report Preview' }}</h3>
                    <span class="rpt-results-count">{{ $result['total_rows'] ?? 0 }} records</span>
                </div>
                <div class="rpt-results-actions" x-data="{ open: false }" @click.outside="open = false">
                    <div class="rpt-export-wrap" :class="{ open: open }">
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

            <div class="rpt-preview-shell {{ $isOpcr ? 'rpt-preview-shell--table' : 'rpt-preview-shell--frame' }}">
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
                    @endphp
                    <div class="rpt-table-scroll">
                        <table class="rpt-table">
                            <thead>
                                <tr>
                                    @foreach($keys as $key)
                                        <th>{{ $cols[$key] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($rows as $row)
                                    <tr>
                                        @foreach($keys as $key)
                                            @if(in_array($key, ['rating_q', 'rating_e', 'rating_t', 'rating_a'], true))
                                                <td class="opcr-rating-td">
                                                    <input type="number" class="opcr-rating-input" min="0" max="10" step="0.01"
                                                        value="{{ $row[$key] }}"
                                                        wire:blur="saveRatingField({{ (int) $row['request_id'] }}, '{{ $key }}', $event.target.value)">
                                                </td>
                                            @elseif($key === 'days_diff')
                                                <td class="{{ ($row[$key] ?? 0) > 0 ? 'opcr-days-advanced' : (($row[$key] ?? 0) < 0 ? 'opcr-days-delayed' : 'opcr-days-zero') }}">
                                                    {{ $row[$key] === null ? '—' : (($row[$key] > 0 ? '+' : '') . $row[$key]) }}
                                                </td>
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
                @else
                    <iframe class="rpt-preview-frame" title="Report preview" src="{{ $this->previewUrl() }}" wire:key="preview-{{ $templateId }}"></iframe>
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
