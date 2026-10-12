<main class="rpt-page" id="rptPage">
    <template x-teleport="body">
    <div class="rpt-filter-layer">
    @if($isMonitoring)
    <div class="rpt-filter-portal" x-bind:data-open="$wire.columnsOpen ? '1' : null" @if($columnsOpen) data-open="1" @endif>
        <div
            class="rpt-filter-overlay {{ $columnsOpen ? 'visible' : '' }}"
            wire:click="closeColumns"
            @if(!$columnsOpen) style="pointer-events:none;" @endif
        ></div>
        <aside
            class="rpt-filter-panel {{ $columnsOpen ? 'open' : '' }}"
            x-bind:class="{ open: $wire.columnsOpen }"
            role="dialog"
            aria-modal="true"
            aria-label="Export columns"
            @if(!$columnsOpen) aria-hidden="true" @endif
        >
            <div class="rpt-filter-panel-head">
                <h3><i class="fa-solid fa-file-excel"></i> Excel columns</h3>
                <button type="button" class="rpt-filter-close" wire:click="closeColumns" aria-label="Close columns">&times;</button>
            </div>
            <div class="rpt-filter-form">
                <p class="rpt-filter-hint">Choose the columns to include in the Excel file. The table on this page still shows all columns.</p>
                <div class="rpt-subtype-block">
                    <div class="rpt-subtype-head">
                        <span class="rpt-subtype-title">Columns</span>
                        <button type="button" class="rpt-link-btn" wire:click="selectAllExportColumns">Select all</button>
                    </div>
                    <div class="rpt-col-list">
                        @foreach(($result['columns'] ?? []) as $colKey => $colLabel)
                            <label class="rpt-col-item">
                                <input type="checkbox" value="{{ $colKey }}" wire:model="exportColumns">
                                <span>{{ trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $colLabel)))) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="rpt-filter-panel-foot">
                <button type="button" class="rpt-btn rpt-btn-outline" wire:click="closeColumns">Cancel</button>
                <a class="rpt-btn rpt-btn-primary" href="{{ $this->exportUrl('xlsx') }}">Download Excel</a>
            </div>
        </aside>
    </div>
    @endif
    <div class="rpt-filter-portal" x-bind:data-open="$wire.filterOpen ? '1' : null" @if($filterOpen) data-open="1" @endif>
        <div
            class="rpt-filter-overlay {{ $filterOpen ? 'visible' : '' }}"
            x-bind:class="{ visible: $wire.filterOpen }"
            x-on:click="$wire.closeFilters()"
            @if(!$filterOpen) style="pointer-events:none;" @endif
        ></div>
        <aside
            class="rpt-filter-panel {{ $filterOpen ? 'open' : '' }}"
            x-bind:class="{ open: $wire.filterOpen }"
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
                    <label>Show documents from</label>
                    <select wire:model.live="period">
                        @if(in_array($category, ['masterlist', 'monitoring', 'opcr'], true))
                            <option value="all">Up to a date</option>
                        @endif
                        <option value="weekly">This week (Mon–ending date)</option>
                        <option value="monthly">This month (1st–ending date)</option>
                        <option value="quarterly">This quarter (start–ending date)</option>
                        <option value="annually">This year (Jan 1–ending date)</option>
                        <option value="custom">Custom dates</option>
                    </select>
                    <span class="rpt-filter-hint">{{ $periodWindow['label'] }}</span>
                </div>
                @if($period === 'custom')
                    <div class="rpt-filter-group">
                        <label>From</label>
                        <input type="date" wire:model.live="dateFrom">
                    </div>
                    <div class="rpt-filter-group">
                        <label>To</label>
                        <input type="date" wire:model.live="dateTo">
                    </div>
                @else
                    <div class="rpt-filter-group">
                        <label>{{ $periodWindow['dateLabel'] }}</label>
                        <input type="date" wire:model.live="asOf">
                    </div>
                @endif
                @if($isMonitoring && $formYears !== [])
                    <div class="rpt-filter-group">
                        <label>Year</label>
                        <select wire:model="formYear">
                            <option value="">All years</option>
                            @foreach($formYears as $year)
                                <option value="{{ $year }}">{{ $year }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="rpt-filter-group">
                    <label>Sort by</label>
                    <select wire:model="sortBy">
                        <option value="effectivity_date">Effectivity date</option>
                        <option value="doc_no">Doc. No.</option>
                        <option value="doc_title">Document title</option>
                        <option value="originator">Originator</option>
                        <option value="rev_no">Rev. No.</option>
                        <option value="registered">Date registered</option>
                    </select>
                </div>
                <div class="rpt-filter-group">
                    <label>Revision</label>
                    <select wire:model="revisionStatus">
                        <option value="all">All</option>
                        <option value="latest">Latest only</option>
                        <option value="obsolete">Obsolete only</option>
                    </select>
                    <span class="rpt-filter-hint">Blank Item No. = older revision of the row above.</span>
                </div>
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
                @if($childTypes->isNotEmpty() && ! $isMonitoring)
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
            </div>
            <div class="rpt-filter-panel-foot">
                <button type="button" class="rpt-btn rpt-btn-outline" wire:click="resetFilters">Reset</button>
                <button type="button" class="rpt-btn rpt-btn-primary" wire:click="applyFilters">Apply Filters</button>
            </div>
        </aside>
    </div>
    </div>
    </template>

    <header class="rpt-hdr">
        <div>
            <div class="rpt-crumb">Document Control System / {{ $isMonitoring ? 'Monitoring' : 'Generate Report' }} /<span> {{ $pageTitle }}</span></div>
            <h1>{{ $pageTitle }}</h1>
        </div>
    </header>

    @if($category !== 'others')
        <nav class="rpt-subs visible" aria-label="Report types" wire:loading.class="is-busy">
            @if($category === 'opcr')
                <button class="rpt-sub {{ $sub === 'update_masterlist' ? 'active' : '' }}" type="button" wire:click="selectSub('update_masterlist')" wire:loading.attr="disabled" wire:target="selectSub">Updating of Masterlist</button>
                <button class="rpt-sub {{ $sub === 'issuance_internal' ? 'active' : '' }}" type="button" wire:click="selectSub('issuance_internal')" wire:loading.attr="disabled" wire:target="selectSub">Issuance of Internal</button>
                <button class="rpt-sub {{ $sub === 'issuance_external' ? 'active' : '' }}" type="button" wire:click="selectSub('issuance_external')" wire:loading.attr="disabled" wire:target="selectSub">Issuance of External</button>
                <button class="rpt-sub {{ $sub === 'control_forms' ? 'active' : '' }}" type="button" wire:click="selectSub('control_forms')" wire:loading.attr="disabled" wire:target="selectSub">Controlling of Forms</button>
                <button class="rpt-sub {{ $sub === 'control_logbooks' ? 'active' : '' }}" type="button" wire:click="selectSub('control_logbooks')" wire:loading.attr="disabled" wire:target="selectSub">Controlling of Logbooks</button>
                <button class="rpt-sub {{ $sub === 'control_internal_forms' ? 'active' : '' }}" type="button" wire:click="selectSub('control_internal_forms')" wire:loading.attr="disabled" wire:target="selectSub">Controlling of Internal Forms</button>
            @else
                <button class="rpt-sub {{ $sub === 'internal_docs' ? 'active' : '' }}" type="button" wire:click="selectSub('internal_docs')" wire:loading.attr="disabled" wire:target="selectSub">Internal</button>
                <button class="rpt-sub {{ $sub === 'external_docs' ? 'active' : '' }}" type="button" wire:click="selectSub('external_docs')" wire:loading.attr="disabled" wire:target="selectSub">External</button>
                <button class="rpt-sub {{ $sub === 'internal_forms' ? 'active' : '' }}" type="button" wire:click="selectSub('internal_forms')" wire:loading.attr="disabled" wire:target="selectSub">Internal Forms</button>
                <button class="rpt-sub {{ $sub === 'forms' ? 'active' : '' }}" type="button" wire:click="selectSub('forms')" wire:loading.attr="disabled" wire:target="selectSub">Forms</button>
                <button class="rpt-sub {{ $sub === 'logbooks' ? 'active' : '' }}" type="button" wire:click="selectSub('logbooks')" wire:loading.attr="disabled" wire:target="selectSub">Logbooks</button>
                @if($category === 'monitoring')
                    <button class="rpt-sub {{ $sub === 'drf' ? 'active' : '' }}" type="button" wire:click="selectSub('drf')" wire:loading.attr="disabled" wire:target="selectSub">DRF</button>
                    <button class="rpt-sub {{ $sub === 'dcn' ? 'active' : '' }}" type="button" wire:click="selectSub('dcn')" wire:loading.attr="disabled" wire:target="selectSub">DCN</button>
                @endif
            @endif
        </nav>
        @if($isMonitoring && $sub !== '' && $childTypes->isNotEmpty())
            <nav class="rpt-subs rpt-subs-secondary visible" aria-label="Document sub-types">
                <button class="rpt-sub {{ $subTypeIds !== [] && count($subTypeIds) === $childTypes->count() ? 'active' : '' }}" type="button" wire:click="selectSubType('all')" wire:loading.attr="disabled" wire:target="selectSubType">All</button>
                @foreach($childTypes as $child)
                    <button class="rpt-sub {{ $subTypeIds === [(string) $child->id] ? 'active' : '' }}" type="button" wire:click="selectSubType('{{ $child->id }}')" wire:loading.attr="disabled" wire:target="selectSubType">{{ $child->doc_type_name }}</button>
                @endforeach
            </nav>
        @endif
    @endif

    <div class="rpt-body-slot">
        <div
            class="rpt-preview-loading rpt-body-loading"
            wire:loading.flex
            wire:target="selectSub,selectSubType,applyFilters,resetFilters,loadReport,selectAllSubTypes,clearSubTypes,formYear,toggleSortDir"
        >
            <div class="rpt-loading-card">
                <div class="rpt-loading-spinner" aria-hidden="true"></div>
                <h4>Loading report</h4>
                <p>Fetching records and preparing the preview.</p>
            </div>
        </div>
    @if($awaitingSubType)
        <div class="rpt-state rpt-state-pick">
            <div class="rpt-state-icon"><i class="fa-solid fa-layer-group"></i></div>
            <h4>Select a sub-type</h4>
            <p>Choose a document sub-type above to open the monitoring table.</p>
        </div>
    @elseif($sub !== '' || $category === 'others')
        <section class="rpt-results">
            <div class="rpt-results-head">
                <div class="rpt-results-meta">
                    <h3>{{ $result['title'] ?? 'Report Preview' }}</h3>
                    <span class="rpt-results-count">
                        {{ $result['total_rows'] ?? 0 }} records
                        @if($isMonitoring && $formYear !== '')
                            · {{ $formYear }}
                        @endif
                        · {{ $periodWindow['label'] }}
                        · {{ match(true) {
                            $sub === 'drf' => 'DRF date',
                            $sub === 'dcn' => 'DCN date',
                            $sortBy === 'doc_no' => 'Doc. No.',
                            $sortBy === 'doc_title' => 'Title',
                            $sortBy === 'originator' => 'Originator',
                            $sortBy === 'rev_no' => 'Rev. No.',
                            $sortBy === 'registered' => 'Date registered',
                            default => 'Effectivity date',
                        } }} {{ $sortDir === 'desc' ? '↓' : '↑' }}
                    </span>
                </div>
                <div class="rpt-results-actions">
                    <button type="button" class="rpt-btn rpt-btn-outline" wire:click="toggleSortDir" wire:loading.attr="disabled" wire:target="toggleSortDir" title="{{ $sortDir === 'asc' ? 'Ascending' : 'Descending' }}">
                        <i class="fa-solid {{ $sortDir === 'asc' ? 'fa-arrow-up-wide-short' : 'fa-arrow-down-wide-short' }}" wire:loading.remove wire:target="toggleSortDir"></i>
                        <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="toggleSortDir"></i>
                        {{ $sortDir === 'asc' ? 'Asc' : 'Desc' }}
                    </button>
                    <button type="button" class="rpt-btn rpt-btn-outline" wire:click="openFilters" x-on:click.prevent="$wire.openFilters()">
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
                            @if($isMonitoring)
                                <button type="button" class="rpt-export-item" data-format="xlsx" wire:click="openExcelExport">
                                    <i class="fa-solid fa-file-excel"></i>
                                    <span>Download as Excel</span>
                                </button>
                            @elseif($isOpcr || $isOthers)
                                <a class="rpt-export-item" data-format="csv" href="{{ $this->exportUrl('csv') }}">
                                    <i class="fa-solid fa-file-csv"></i>
                                    <span>Download as CSV</span>
                                </a>
                            @else
                                <a class="rpt-export-item" data-format="csv" href="{{ $this->exportUrl('csv') }}">
                                    <i class="fa-solid fa-file-csv"></i>
                                    <span>Download as CSV</span>
                                </a>
                                <div class="rpt-export-sep"></div>
                                <a class="rpt-export-item" data-format="print" href="{{ $this->exportUrl('print') }}" target="_blank" rel="noopener">
                                    <i class="fa-solid fa-print"></i>
                                    <span>Print Report</span>
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="rpt-preview-shell {{ ($isOpcr || $isMonitoring || $isOthers || $category === 'masterlist') ? 'rpt-preview-shell--table' : 'rpt-preview-shell--frame' }}">
                @php
                    $preview = $this->slicePreviewRows($this->reportRows());
                @endphp
                @if($error)
                    <div class="rpt-state">
                        <div class="rpt-state-icon state-error"><i class="fa-solid fa-circle-exclamation"></i></div>
                        <h4>Error</h4>
                        <p>{{ $error }}</p>
                    </div>
                @elseif($isOpcr)
                    @php
                        $cols = $result['columns'] ?? [];
                        $rows = $preview['rows'];
                        $groups = $result['group_headers'] ?? [];
                        $keys = array_keys($cols);
                        $opcrColClass = static function (string $key): string {
                            $dateKeys = ['date_received', 'date_registered', 'date_released'];
                            $timeKeys = ['time_received', 'time_registered', 'time_released'];
                            $controlKeys = ['control_number', 'doc_number', 'doc_no'];
                            if (in_array($key, $dateKeys, true)) {
                                return 'mon-col-date';
                            }
                            if (in_array($key, $timeKeys, true)) {
                                return 'mon-col-time';
                            }
                            if (in_array($key, $controlKeys, true)) {
                                return 'rpt-doc-no mon-col-control';
                            }
                            if (in_array($key, ['rating_q', 'rating_e', 'rating_t', 'rating_a'], true)) {
                                return 'opcr-rating-th';
                            }

                            return '';
                        };
                    @endphp
                    <div class="rpt-table-scroll">
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
                                                    <th rowspan="2" class="{{ $opcrColClass($key) }}">{{ $cols[$key] }}</th>
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
                                                    <th class="{{ $opcrColClass($key) }}">{{ $cols[$key] }}</th>
                                                @endif
                                            @endforeach
                                        </tr>
                                    @else
                                        <tr>
                                            @foreach($keys as $key)
                                                <th class="{{ $opcrColClass($key) }}">{{ $cols[$key] }}</th>
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
                                                        {{ $row[$key] === null ? '—' : abs((int) $row[$key]) }}
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
                                                    <td class="{{ $opcrColClass($key) }}"><strong>{{ $row[$key] ?: '—' }}</strong></td>
                                                @else
                                                    <td class="{{ $opcrColClass($key) }}">{{ $row[$key] ?: '—' }}</td>
                                                @endif
                                            @endforeach
                                        </tr>
                                    @empty
                                        <tr><td colspan="{{ max(count($keys), 1) }}"><div class="rpt-state"><h4>No records found</h4></div></td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                    </div>
                @elseif($isMonitoring || $isOthers)
                    @php
                        $cols = $result['columns'] ?? [];
                        $rows = $preview['rows'];
                        $groups = $result['group_headers'] ?? [];
                        $keys = array_keys($cols);
                        $monColClass = static function (string $key): string {
                            $dateKeys = ['date_received', 'date_registered', 'effectivity_date', 'deadline', 'date_released', 'ml_reg_date'];
                            $timeKeys = ['time_received', 'time_registered', 'time_released', 'ml_reg_time'];
                            $controlKeys = ['control_number', 'doc_number', 'doc_no', 'drf_no', 'dcn_no'];
                            $subjectKeys = ['subject_matter', 'description'];
                            if (in_array($key, $dateKeys, true)) {
                                return 'mon-col-date';
                            }
                            if (in_array($key, $timeKeys, true)) {
                                return 'mon-col-time';
                            }
                            if ($key === 'source') {
                                return 'mon-col-source';
                            }
                            if ($key === 'in_charge') {
                                return 'mon-col-incharge';
                            }
                            if (in_array($key, $controlKeys, true)) {
                                return 'rpt-doc-no mon-col-control';
                            }
                            if (in_array($key, $subjectKeys, true)) {
                                return 'mon-col-subject';
                            }

                            return '';
                        };
                    @endphp
                    <div class="rpt-table-scroll">
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
                                                <th rowspan="2" class="{{ $monColClass($key) }}">{!! $cols[$key] !!}</th>
                                                @php $i++; @endphp
                                            @else
                                                @php
                                                    $span = 1;
                                                    while ($i + $span < count($keys) && ($groups[$keys[$i + $span]] ?? null) === $group) {
                                                        $span++;
                                                    }
                                                @endphp
                                                <th colspan="{{ $span }}">{!! $group !!}</th>
                                                @php $i += $span; @endphp
                                            @endif
                                        @endwhile
                                    </tr>
                                    <tr>
                                        @foreach($keys as $key)
                                            @if(($groups[$key] ?? null) !== null && ($groups[$key] ?? null) !== '')
                                                <th class="{{ $monColClass($key) }}">{!! $cols[$key] !!}</th>
                                            @endif
                                        @endforeach
                                    </tr>
                                @else
                                    <tr>
                                        @foreach($keys as $key)
                                            <th class="{{ $monColClass($key) }}">{!! $cols[$key] !!}</th>
                                        @endforeach
                                    </tr>
                                @endif
                            </thead>
                            <tbody>
                                @forelse($rows as $row)
                                    <tr>
                                        @foreach($keys as $key)
                                            @if($key === 'pdf_path')
                                                <td>
                                                    @if(!empty($row[$key]))
                                                        <a href="{{ $row[$key] }}" target="_blank" rel="noopener">View</a>
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                            @elseif(in_array($key, ['item_no', 'no'], true))
                                                <td>{{ ($row[$key] ?? '') !== '' && ($row[$key] ?? null) !== null ? $row[$key] : '' }}</td>
                                            @elseif(in_array($key, ['doc_no', 'control_number', 'doc_number', 'drf_no', 'dcn_no'], true))
                                                <td class="{{ $monColClass($key) }}"><strong>{{ $row[$key] ?: '—' }}</strong></td>
                                            @elseif($key === 'forwarded_drr')
                                                <td class="mon-drr-td">
                                                    <input type="checkbox" class="mon-drr-check"
                                                        @checked(!empty($row['forwarded_drr']))
                                                        @if(!empty($row['request_id']))
                                                            wire:key="mon-drr-{{ (int) $row['request_id'] }}"
                                                            x-on:change="$wire.saveMonitoringForwardedDrr({{ (int) $row['request_id'] }}, $event.target.checked)"
                                                        @endif>
                                                </td>
                                            @elseif($key === 'remarks')
                                                <td class="mon-remarks-td">
                                                    <textarea
                                                        class="mon-remarks-input"
                                                        rows="2"
                                                        placeholder="Enter remarks"
                                                        wire:key="mon-remark-{{ (int) ($row['request_id'] ?? 0) }}"
                                                        @if(!empty($row['request_id']))
                                                            x-data
                                                            x-init="$nextTick(() => { $el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px' })"
                                                            x-on:input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"
                                                            x-on:blur="$wire.saveMonitoringRemark({{ (int) $row['request_id'] }}, $event.target.value)"
                                                        @endif
                                                    >{{ $row['remarks'] ?? '' }}</textarea>
                                                </td>
                                            @else
                                                <td class="{{ $monColClass($key) }}">{{ $row[$key] ?: '—' }}</td>
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
                    @php
                        $cols = collect($result['columns'] ?? [])->except(['pdf_path'])->all();
                        $rows = $preview['rows'];
                        $keys = array_keys($cols);
                        $previewKey = 'preview-'.$category.'-'.$sub.'-'.$period.'-'.$asOf.'-'.$dateFrom.'-'.$dateTo.'-'.$sortBy.'-'.$sortDir.'-'.md5(json_encode([$originator, $sourceUnit, $revisionStatus, $revNo, $subTypeIds, $monitoringDocType, $monitoringSubTypeIds]));
                    @endphp
                    @php
                        $mlIdsOnPage = $allMlIds;
                        $allMlSelected = $mlIdsOnPage !== [] && count(array_intersect($mlIdsOnPage, array_map('strval', $selectedMlIds))) === count($mlIdsOnPage);
                    @endphp
                    @if($selectedMlIds !== [])
                        <p class="rpt-select-note">
                            {{ count($selectedMlIds) }} selected. Preview, print, PDF, and CSV use only these rows.
                        </p>
                    @endif
                    <div class="rpt-table-scroll">
                        <table class="rpt-table rpt-ml-table">
                            <thead>
                                <tr>
                                    <th class="rpt-select-col">
                                        <input type="checkbox" aria-label="Select all documents" @checked($allMlSelected) wire:click="toggleMasterlistSelection(@js($mlIdsOnPage))">
                                    </th>
                                    @foreach($keys as $key)
                                        <th>{!! $cols[$key] !!}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($rows as $row)
                                    <tr>
                                        <td class="rpt-select-col">
                                            @if(!empty($row['ml_id']))
                                                <input type="checkbox" aria-label="Include {{ $row['doc_no'] ?? 'document' }}" value="{{ $row['ml_id'] }}" wire:model.live="selectedMlIds">
                                            @endif
                                        </td>
                                        @foreach($keys as $key)
                                            <td>{{ ($row[$key] ?? '') !== '' && ($row[$key] ?? null) !== null ? $row[$key] : '—' }}</td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ max(count($keys), 1) + 1 }}"><div class="rpt-state"><h4>No records found</h4></div></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div
                        class="rpt-print-preview"
                        x-data="{
                            open: false,
                            loading: true,
                            fitFrame() {
                                const frame = this.$refs.previewFrame;
                                if (!frame) return;
                                try {
                                    const doc = frame.contentDocument;
                                    if (!doc) return;
                                    const h = Math.max(
                                        doc.documentElement.scrollHeight,
                                        doc.body ? doc.body.scrollHeight : 0
                                    );
                                    frame.style.height = Math.max(h, 640) + 'px';
                                } catch (e) {}
                            }
                        }"
                    >
                        <button type="button" class="rpt-btn rpt-btn-outline" style="margin: 16px 0;" @click="open = true; loading = true">
                            <i class="fa-solid fa-print"></i>
                            <span>Show print preview</span>
                        </button>
                        <template x-teleport="body">
                            <div class="rpt-preview-modal" x-show="open" x-cloak @keydown.escape.window="open = false">
                                <div class="rpt-preview-modal-backdrop" @click="open = false"></div>
                                <div class="rpt-preview-dialog" role="dialog" aria-modal="true" aria-label="Print preview">
                                    <div class="rpt-preview-dialog-head">
                                        <h3>Print preview</h3>
                                        <button type="button" class="rpt-preview-dialog-close" @click="open = false" aria-label="Close preview">&times;</button>
                                    </div>
                                    <div class="rpt-preview-frame-wrap" wire:key="{{ $previewKey }}">
                                        <div class="rpt-preview-loading" x-show="loading" x-cloak>
                                            <div class="rpt-loading-card">
                                                <div class="rpt-loading-spinner" aria-hidden="true"></div>
                                                <h4>Loading print preview</h4>
                                                <p>Preparing the page as it will look when printed.</p>
                                            </div>
                                        </div>
                                        <iframe
                                            class="rpt-preview-frame"
                                            title="Print preview"
                                            :src="open ? @js($this->previewUrl()) : ''"
                                            x-ref="previewFrame"
                                            :class="{ 'is-loading': loading }"
                                            @load="loading = false; fitFrame()"
                                        ></iframe>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                @endif
                @if(empty($error) && ($preview['last'] ?? 1) > 1)
                    <div class="rpt-preview-pager" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-top:1px solid #e2e8f0;">
                        <button type="button" class="rpt-btn rpt-btn-outline" wire:click="goPreviewPage({{ max(1, $preview['page'] - 1) }})" @disabled($preview['page'] <= 1)>Previous</button>
                        <span style="font-size:13px;color:#475569;">Showing {{ $preview['from'] }}–{{ $preview['from'] + count($preview['rows']) - 1 }} of {{ $preview['total'] }}. Download and print still include every row.</span>
                        <button type="button" class="rpt-btn rpt-btn-outline" wire:click="goPreviewPage({{ $preview['page'] + 1 }})" @disabled($preview['page'] >= $preview['last'])>Next</button>
                    </div>
                @endif
            </div>
        </section>
    @elseif($category !== 'others')
        <div class="rpt-state rpt-state-pick">
            <div class="rpt-state-icon"><i class="fa-solid fa-file-lines"></i></div>
            <h4>Select a document type</h4>
            <p>{{ $isMonitoring ? 'Choose Internal, External, Forms, DRF, or DCN. If the type has sub-types, pick one next. Export Excel after choosing columns.' : ($isOpcr ? 'Choose a target type above to open the table.' : 'Choose Internal, External, Forms, or another type above to preview and print the report.') }}</p>
        </div>
    @endif
    </div>
</main>

@push('scripts')
<script>
(function () {
    function pinReportSubhead() {
        document.querySelectorAll('.rpt-table').forEach(function (table) {
            var row = table.querySelector('thead tr:first-child');
            var sub = table.querySelector('thead tr:nth-child(2)');
            if (!row || !sub) {
                table.style.removeProperty('--rpt-head-row1');
                return;
            }
            // Rowspan cells stretch across both header rows, so their height is the whole header.
            // Measure the gap after clearing the sticky offset, then pull the sub-header up to close it.
            var subCell = sub.querySelector('th');
            var band = null;
            var cells = row.querySelectorAll('th');
            for (var i = 0; i < cells.length; i++) {
                if (parseInt(cells[i].getAttribute('rowspan') || '1', 10) > 1) continue;
                band = cells[i];
                break;
            }
            if (!subCell || !band) {
                table.style.removeProperty('--rpt-head-row1');
                return;
            }
            // Clear any previous offset first. A sticky top that is too large
            // pushes this row down, and measuring that pushed row repeats the gap.
            table.style.setProperty('--rpt-head-row1', '0px');
            var natural = sub.offsetTop - row.offsetTop;
            if (!(natural > 0)) natural = band.offsetHeight || 0;
            var top = Math.max(0, Math.round(natural));
            table.style.setProperty('--rpt-head-row1', top + 'px');
            var gap = subCell.getBoundingClientRect().top - band.getBoundingClientRect().bottom;
            if (Math.abs(gap) > 0.5) {
                table.style.setProperty('--rpt-head-row1', Math.max(0, Math.round(top - gap)) + 'px');
            }
        });
    }

    pinReportSubhead();
    window.addEventListener('resize', pinReportSubhead);

    var root = document.querySelector('.rpt-page');
    if (root && window.MutationObserver) {
        new MutationObserver(function () { pinReportSubhead(); }).observe(root, { childList: true, subtree: true });
    }
})();
</script>
@endpush
