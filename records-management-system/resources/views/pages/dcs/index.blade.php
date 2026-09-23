<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    public function with(): array
    {
        if (RegisterQueryHelper::isLimitedDcsUser()) {
            $drfRows = OfficeIntakeHelper::listMyDrf();
            $dcnRows = OfficeIntakeHelper::listMyDcn();
            $docGroups = OfficeIntakeHelper::officeDocumentGroups(null, false);
            $docTotal = OfficeIntakeHelper::officeDocumentTotal();

            return [
                'isLimitedDcs' => true,
                'officeName' => auth()->user()?->details?->office?->office_name
                    ?? auth()->user()?->details?->office?->office_code
                    ?? 'Your office',
                'userDisplayName' => trim(implode(' ', array_filter([
                    auth()->user()?->details?->first_name,
                    auth()->user()?->details?->last_name,
                ]))) ?: (auth()->user()?->username ?? ''),
                'officeDrfCount' => $drfRows->count(),
                'officeDcnCount' => $dcnRows->count(),
                'officeDocTotal' => $docTotal,
                'officeDocGroups' => $docGroups,
                'recentDrf' => $drfRows->take(4)->values(),
                'recentDcn' => $dcnRows->take(4)->values(),
                'headerDate' => now('Asia/Manila')->format('l, F j, Y'),
                'headerTime' => now('Asia/Manila')->format('g:i:s A'),
                'stats' => [],
                'holidays' => [],
            ];
        }

        $typeIds = RegisterQueryHelper::parentTypeIdMap();
        $queue = DB::table('dcs_document_requests as dr')
            ->leftJoin('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dr.id')
            ->whereIn('dr.approval_status', ['applicable', 'not_applicable']);
        RegisterQueryHelper::applyNotDeleted($queue, 'dr');
        RegisterQueryHelper::applyRegisteredDocumentScope($queue, 'dr');
        $hasRevStatus = RegisterQueryHelper::supportsRevisionStatus();
        $queue = $queue->selectRaw('COUNT(dr.id)::int as total');
        if ($hasRevStatus) {
            $queue = $queue
                ->selectRaw("COUNT(dr.id) FILTER (WHERE COALESCE(NULLIF(TRIM(ml.revision_status), ''), 'latest') <> 'obsolete')::int as latest")
                ->selectRaw("COUNT(dr.id) FILTER (WHERE ml.revision_status = 'obsolete')::int as obsolete");
        } else {
            $queue = $queue
                ->selectRaw('COUNT(dr.id)::int as latest')
                ->selectRaw('0::int as obsolete');
        }
        $queue = $queue->first();

        $byType = function (int $typeId) use ($hasRevStatus) {
            $q = DB::table('dcs_document_requests as dr')
                ->leftJoin('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dr.id')
                ->whereIn('dr.approval_status', ['applicable', 'not_applicable'])
                ->where('dr.doc_type_id', $typeId);
            if ($hasRevStatus) {
                RegisterQueryHelper::applyLatestRevisionStatus($q, 'ml');
            }
            RegisterQueryHelper::applyNotDeleted($q, 'dr');
            RegisterQueryHelper::applyRegisteredDocumentScope($q, 'dr');

            return $q->count();
        };

        $stats = [
            'totalDocuments' => (int) ($queue->total ?? 0),
            'latestCount' => (int) ($queue->latest ?? 0),
            'obsoleteCount' => (int) ($queue->obsolete ?? 0),
            'internalCount' => $byType($typeIds['internal_docs']),
            'internalFormsCount' => $byType($typeIds['internal_forms']),
            'externalCount' => $byType($typeIds['external_docs']),
            'formsCount' => $byType($typeIds['forms']),
            'logbooksCount' => $byType($typeIds['logbooks']),
        ];

        $year = (int) now('Asia/Manila')->year;
        $holidays = \App\Helpers\CalendarHelper::philippineHolidays($year, $year + 1);

        return [
            'isLimitedDcs' => false,
            'officeDrfCount' => 0,
            'officeDcnCount' => 0,
            'stats' => $stats,
            'typeIds' => $typeIds,
            'userDisplayName' => trim(implode(' ', array_filter([
                auth()->user()?->details?->first_name,
                auth()->user()?->details?->last_name,
            ]))) ?: (auth()->user()?->username ?? 'User'),
            'headerDate' => now('Asia/Manila')->format('l, F j, Y'),
            'headerTime' => now('Asia/Manila')->format('g:i:s A'),
            'holidays' => $holidays,
            'canDatabase' => RegisterQueryHelper::canAccessDcsModule('database'),
            'canRegister' => RegisterQueryHelper::canAccessDcsModule('register'),
            'canSettings' => RegisterQueryHelper::canAccessDcsModule('settings'),
            'canStamping' => RegisterQueryHelper::canAccessDcsModule('stamping'),
        ];
    }
}; ?>

@if(!empty($isLimitedDcs))
<div class="ofi-page ofi-dash" x-data="ofiDashboardClock()">
    <div class="ofi-inner ofi-inner-wide">
        <div class="ofi-dash-hero">
            <div class="ofi-dash-hero-copy">
                <p class="ofi-dash-kicker">{{ $officeName }}</p>
                <h1>Welcome{{ $userDisplayName !== '' ? ', ' . explode(' ', $userDisplayName)[0] : '' }}</h1>
                <p>Create and print DRF/DCN forms for RFIO, and browse controlled documents for your office.</p>
            </div>
            <div class="ofi-dash-hero-meta" aria-live="polite">
                <div class="ofi-dash-date">
                    <i class="fa-regular fa-calendar"></i>
                    <div>
                        <span class="ofi-dash-date-label">Today</span>
                        <strong>{{ $headerDate }}</strong>
                        <span class="ofi-dash-clock" x-text="nowClock" x-cloak>{{ $headerTime }}</span>
                    </div>
                </div>
                <div class="ofi-dash-hero-actions">
                    <a href="{{ route('dcs.office.drf.create', absolute: false) }}" class="ofi-btn primary"><i class="fa-solid fa-plus"></i> New DRF</a>
                    <a href="{{ route('dcs.office.dcn.create', absolute: false) }}" class="ofi-btn primary"><i class="fa-solid fa-plus"></i> New DCN</a>
                </div>
            </div>
        </div>

        @if(session('error'))
            <div class="ofi-alert err">{{ session('error') }}</div>
        @endif
        @if(session('success'))
            <div class="ofi-alert ok">{{ session('success') }}</div>
        @endif
        @if(session('info'))
            <div class="ofi-alert ok">{{ session('info') }}</div>
        @endif

        <section class="ofi-dash-stats" aria-label="Office overview">
            <a href="{{ route('dcs.office.drf.index', absolute: false) }}" class="ofi-dash-stat is-drf">
                <div class="ofi-dash-stat-icon"><i class="fa-regular fa-file-lines"></i></div>
                <div class="ofi-dash-stat-body">
                    <span class="ofi-dash-stat-label">My DRF</span>
                    <strong class="ofi-dash-stat-value">{{ (int) $officeDrfCount }}</strong>
                    <span class="ofi-dash-stat-hint">Request forms you created</span>
                </div>
            </a>
            <a href="{{ route('dcs.office.dcn.index', absolute: false) }}" class="ofi-dash-stat is-dcn">
                <div class="ofi-dash-stat-icon"><i class="fa-solid fa-file-pen"></i></div>
                <div class="ofi-dash-stat-body">
                    <span class="ofi-dash-stat-label">My DCN</span>
                    <strong class="ofi-dash-stat-value">{{ (int) $officeDcnCount }}</strong>
                    <span class="ofi-dash-stat-hint">Change notices you created</span>
                </div>
            </a>
            <a href="{{ route('dcs.office.documents', ['type' => 'all'], absolute: false) }}" class="ofi-dash-stat is-docs">
                <div class="ofi-dash-stat-icon"><i class="fa-solid fa-folder-open"></i></div>
                <div class="ofi-dash-stat-body">
                    <span class="ofi-dash-stat-label">Office Documents</span>
                    <strong class="ofi-dash-stat-value">{{ (int) $officeDocTotal }}</strong>
                    <span class="ofi-dash-stat-hint">Distributed to your office</span>
                </div>
            </a>
        </section>

        <section class="ofi-dash-panel">
            <div class="ofi-dash-panel-head">
                <div>
                    <h2>Document types</h2>
                    <p>Open a type to view registered documents for your office.</p>
                </div>
                <a href="{{ route('dcs.office.documents', ['type' => 'all'], absolute: false) }}" class="ofi-dash-link">View all</a>
            </div>
            <div class="ofi-dash-type-grid">
                @foreach($officeDocGroups as $group)
                    <a
                        href="{{ route('dcs.office.documents', ['type' => $group['key']], absolute: false) }}"
                        class="ofi-dash-type {{ $group['count'] < 1 ? 'is-empty' : '' }}"
                    >
                        <span class="ofi-dash-type-label">{{ $group['label'] }}</span>
                        <strong class="ofi-dash-type-count">{{ $group['count'] }}</strong>
                        <span class="ofi-dash-type-meta">{{ $group['count'] === 1 ? 'document' : 'documents' }}</span>
                    </a>
                @endforeach
            </div>
        </section>

        <div class="ofi-dash-split">
            <section class="ofi-dash-panel">
                <div class="ofi-dash-panel-head">
                    <div>
                        <h2>Recent DRF</h2>
                        <p>Latest Document Request Forms from your account.</p>
                    </div>
                    <a href="{{ route('dcs.office.drf.index', absolute: false) }}" class="ofi-dash-link">See all</a>
                </div>
                @if($recentDrf->isEmpty())
                    <div class="ofi-dash-empty">
                        <p>No DRF yet.</p>
                        <a href="{{ route('dcs.office.drf.create', absolute: false) }}" class="ofi-btn primary"><i class="fa-solid fa-plus"></i> Create DRF</a>
                    </div>
                @else
                    <ul class="ofi-dash-list">
                        @foreach($recentDrf as $row)
                            <li>
                                <a href="{{ route('dcs.office.drf.show', $row->id, absolute: false) }}">
                                    <span class="ofi-dash-list-title">{{ $row->doc_title ?: 'Untitled DRF' }}</span>
                                    <span class="ofi-dash-list-meta">
                                        {{ $row->drf_date ? \Carbon\Carbon::parse($row->drf_date)->format('M d, Y') : 'No date' }}
                                        @if(!empty($row->is_registered))
                                            · Registered
                                        @elseif(!empty($row->drf_no))
                                            · {{ $row->drf_no }}
                                        @endif
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="ofi-dash-panel">
                <div class="ofi-dash-panel-head">
                    <div>
                        <h2>Recent DCN</h2>
                        <p>Latest Document Change Notices from your account.</p>
                    </div>
                    <a href="{{ route('dcs.office.dcn.index', absolute: false) }}" class="ofi-dash-link">See all</a>
                </div>
                @if($recentDcn->isEmpty())
                    <div class="ofi-dash-empty">
                        <p>No DCN yet.</p>
                        <a href="{{ route('dcs.office.dcn.create', absolute: false) }}" class="ofi-btn primary"><i class="fa-solid fa-plus"></i> Create DCN</a>
                    </div>
                @else
                    <ul class="ofi-dash-list">
                        @foreach($recentDcn as $row)
                            <li>
                                <a href="{{ route('dcs.office.dcn.show', $row->id, absolute: false) }}">
                                    <span class="ofi-dash-list-title">{{ $row->originator_name ?? ($row->dcn_no ?: 'Untitled DCN') }}</span>
                                    <span class="ofi-dash-list-meta">
                                        {{ $row->dcn_date ? \Carbon\Carbon::parse($row->dcn_date)->format('M d, Y') : 'No date' }}
                                        @if(!empty($row->dcn_no)) · {{ $row->dcn_no }} @endif
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <section class="ofi-dash-panel ofi-dash-steps">
            <div class="ofi-dash-panel-head">
                <div>
                    <h2>How office intake works</h2>
                    <p>Follow these steps when submitting forms to RFIO.</p>
                </div>
            </div>
            <ol class="ofi-dash-step-list">
                <li>
                    <span class="ofi-dash-step-num">1</span>
                    <div>
                        <strong>Create the form</strong>
                        <p>Fill out a new DRF or DCN for your office.</p>
                    </div>
                </li>
                <li>
                    <span class="ofi-dash-step-num">2</span>
                    <div>
                        <strong>Print and sign</strong>
                        <p>Print the saved form, then have it signed as required.</p>
                    </div>
                </li>
                <li>
                    <span class="ofi-dash-step-num">3</span>
                    <div>
                        <strong>Submit to RFIO</strong>
                        <p>Bring the signed copy to Records &amp; Freedom of Information for registration.</p>
                    </div>
                </li>
            </ol>
        </section>
    </div>
</div>
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('ofiDashboardClock', () => ({
        nowClock: @json($headerTime),
        _timer: null,
        tick() {
            try {
                this.nowClock = new Date().toLocaleTimeString([], {
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit',
                });
            } catch (e) {
                const n = new Date();
                this.nowClock = n.toTimeString().slice(0, 8);
            }
        },
        init() {
            this.tick();
            this._timer = setInterval(() => this.tick(), 1000);
        },
        destroy() {
            if (this._timer) clearInterval(this._timer);
        },
    }));
});
</script>
@else
<main class="dashboard-main" wire:ignore x-data="dcsDashboardCalendar()">
    <div
        class="dash-calendar-shell"
        @keydown.escape.window="modal !== null && (modal = null)"
    >
        <div class="dashboard-header dash-welcome-bar">
            <div class="welcome-text dash-welcome-copy">
                <p class="header-greeting">Document Controller</p>
                <h1 class="page-title">
                    Welcome, <span class="dash-welcome-name">{{ explode(' ', trim((string) $userDisplayName))[0] ?: 'User' }}</span>
                </h1>
                <p class="dash-welcome-sub">Manage registrations, revisions, and office document control from one place.</p>
            </div>
            <button type="button" class="header-date dash-calendar-trigger dash-welcome-calendar" @click.stop="toggleCalendar()" :aria-expanded="calendarOpen.toString()">
                <i class="fa-regular fa-calendar"></i>
                <span class="dash-calendar-trigger-text">
                    <span class="dash-calendar-trigger-date">{{ $headerDate }}</span>
                    <span class="dash-calendar-trigger-time" x-text="nowClock" x-cloak>{{ $headerTime ?? '' }}</span>
                </span>
                <i class="fa-solid fa-chevron-down dash-calendar-chevron" :class="{ 'is-open': calendarOpen }"></i>
            </button>
        </div>

        <div class="dash-body" :class="{ 'is-calendar-open': calendarOpen, 'is-ready': calendarReady }">
            <div class="dashboard-content-wrapper dashboard-content-wrapper--full">
                <div class="main-column">
            <section class="dash-queue-bar">
                @if(!empty($canDatabase))
                    <a href="{{ route('dcs.database.index', absolute: false) }}" class="dash-queue-chip">
                        <span>Total</span><strong>{{ number_format((int) $stats['totalDocuments']) }}</strong>
                    </a>
                    <a href="{{ route('dcs.database.index', ['revision' => 'latest'], absolute: false) }}" class="dash-queue-chip is-latest">
                        <span>Latest</span><strong>{{ number_format((int) $stats['latestCount']) }}</strong>
                    </a>
                    <a href="{{ route('dcs.database.index', ['revision' => 'obsolete'], absolute: false) }}" class="dash-queue-chip is-obsolete">
                        <span>Obsolete</span><strong>{{ number_format((int) $stats['obsoleteCount']) }}</strong>
                    </a>
                @else
                    <div class="dash-queue-chip"><span>Total</span><strong>{{ number_format((int) $stats['totalDocuments']) }}</strong></div>
                    <div class="dash-queue-chip is-latest"><span>Latest</span><strong>{{ number_format((int) $stats['latestCount']) }}</strong></div>
                    <div class="dash-queue-chip is-obsolete"><span>Obsolete</span><strong>{{ number_format((int) $stats['obsoleteCount']) }}</strong></div>
                @endif
            </section>

            <section class="stats-row">
                @foreach([
                    ['id' => 'internalCount', 'typeKey' => 'internal_docs', 'label' => 'Internal Documents', 'icon' => 'fa-file-shield', 'accent' => null],
                    ['id' => 'internalFormsCount', 'typeKey' => 'internal_forms', 'label' => 'Internal Forms', 'icon' => 'fa-file-contract', 'accent' => 'slate'],
                    ['id' => 'externalCount', 'typeKey' => 'external_docs', 'label' => 'External Documents', 'icon' => 'fa-file-export', 'accent' => 'navy'],
                    ['id' => 'formsCount', 'typeKey' => 'forms', 'label' => 'Forms', 'icon' => 'fa-file-signature', 'accent' => 'red'],
                    ['id' => 'logbooksCount', 'typeKey' => 'logbooks', 'label' => 'Logbooks', 'icon' => 'fa-book', 'accent' => 'green'],
                ] as $box)
                    @php $count = (int) ($stats[$box['id']] ?? 0); @endphp
                    @if(!empty($canDatabase))
                        <a href="{{ route('dcs.database.index', ['type' => $typeIds[$box['typeKey']] ?? null, 'revision' => 'latest'], absolute: false) }}" class="stat-box" @if($box['accent']) data-accent="{{ $box['accent'] }}" @endif>
                    @else
                        <div class="stat-box" @if($box['accent']) data-accent="{{ $box['accent'] }}" @endif>
                    @endif
                        <div class="stat-icon-wrap"><i class="fa-solid {{ $box['icon'] }}"></i></div>
                        <div class="stat-body">
                            <p class="stat-label">{{ $box['label'] }}</p>
                            <div class="stat-number-row">
                                <h3 class="stat-value">{{ number_format($count) }}</h3>
                            </div>
                        </div>
                    @if(!empty($canDatabase))
                        </a>
                    @else
                        </div>
                    @endif
                @endforeach
            </section>

            <section class="dash-search-section" x-data="dcsDashboardSearch()" @keydown.escape.window="detailModal ? closeDetailModal() : close()">
                <div class="section-header">
                    <h2>Search Documents</h2>
                    <span class="section-subtitle">Find by document no., title, or keywords</span>
                </div>

                <div class="dash-search-card" :class="{ 'has-results': open && query.trim().length > 0 }">
                    <div class="dash-search-bar">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input
                            type="text"
                            class="dash-search-input"
                            x-model="query"
                            @input="search()"
                            placeholder="Search by document no., title, or keywords..."
                            autocomplete="off"
                        >
                        <button type="button" class="dash-search-clear" x-show="query.length > 0" x-cloak @click="clear()">
                            Clear
                        </button>
                    </div>

                    <div class="dash-search-results" x-show="open && query.trim().length > 0" x-cloak>
                        <div class="dash-search-results-head">
                            <span x-show="loading">Searching...</span>
                            <span x-show="!loading && results.length > 0" x-text="results.length + (results.length === 1 ? ' document found' : ' documents found')"></span>
                            <span x-show="!loading && results.length === 0">No matching documents</span>
                        </div>

                        <template x-if="loading">
                            <div class="dash-search-state">
                                <i class="fa-solid fa-spinner fa-spin"></i>
                                <span>Searching masterlist records...</span>
                            </div>
                        </template>

                        <template x-if="!loading && results.length === 0">
                            <div class="dash-search-state">
                                <i class="fa-regular fa-folder-open"></i>
                                <span>No documents matched your search.</span>
                            </div>
                        </template>

                        <template x-if="!loading && results.length > 0">
                            <div class="dash-search-list">
                                <template x-for="doc in results" :key="doc.masterlist_id">
                                    <article class="dash-search-item dash-search-item-clickable" @click="openDetail(doc)" role="button" tabindex="0" @keydown.enter.prevent="openDetail(doc)">
                                        <div class="dash-search-item-top">
                                            <div class="dash-search-item-main">
                                                <code class="dash-search-docno" x-text="doc.doc_no || 'No number'"></code>
                                                <h3 class="dash-search-title" x-text="doc.doc_title || 'Untitled'"></h3>
                                                <div class="dash-search-meta" x-show="doc.type_name || doc.sub_type_name">
                                                    <span class="dash-search-meta-item" x-show="doc.type_name">
                                                        <span class="dash-search-meta-label">Type</span>
                                                        <span x-text="doc.type_name"></span>
                                                    </span>
                                                    <span class="dash-search-meta-item" x-show="doc.sub_type_name">
                                                        <span class="dash-search-meta-label">Sub type</span>
                                                        <span x-text="doc.sub_type_name"></span>
                                                    </span>
                                                </div>
                                            </div>
                                            <span class="dash-search-rev" x-text="'Rev ' + (doc.revise_no ?? 0) + (doc.revision_status === 'obsolete' ? ' · Obsolete' : '')"></span>
                                        </div>
                                        <div class="dash-search-item-hint">
                                            <span>Click to view checklists and revisions</span>
                                            <i class="fa-solid fa-arrow-right"></i>
                                        </div>
                                    </article>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                <template x-teleport="body">
                    <div
                        class="dash-cl-overlay dash-detail-overlay"
                        x-show="detailModal"
                        x-cloak
                        @click.self="closeDetailModal()"
                        style="display:none;"
                        :style="detailModal ? 'display:flex' : 'display:none'"
                    >
                        <div class="dash-detail-modal" @click.stop role="dialog" aria-modal="true">
                            <div class="dash-cl-modal-head">
                                <div class="dash-cl-modal-head-text">
                                    <p class="dash-cl-modal-kicker">Document Detail</p>
                                    <h3 x-text="detailDoc?.doc_title || 'Untitled'"></h3>
                                    <p class="dash-cl-modal-doc" x-show="detailDocLabel" x-text="detailDocLabel"></p>
                                </div>
                                <button type="button" class="dash-cl-modal-close" @click="closeDetailModal()" aria-label="Close">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>

                            <div class="dash-detail-body">
                                <div class="dash-detail-main">
                                    <div class="dash-detail-checklist-tabs">
                                        <template x-for="cl in detailChecklistOptions()" :key="cl.key">
                                            <button
                                                type="button"
                                                class="dash-cl-btn"
                                                :class="{ 'is-active': activeChecklistKey === cl.key }"
                                                @click="loadChecklist(cl.key)"
                                                x-text="cl.label"
                                            ></button>
                                        </template>
                                    </div>

                                    <div class="dash-detail-preview">
                                        <template x-if="checklistLoading">
                                            <div class="dash-cl-loading">
                                                <i class="fa-solid fa-spinner fa-spin"></i>
                                                <span>Loading checklist data...</span>
                                            </div>
                                        </template>

                                        <template x-if="!checklistLoading && checklistPreview">
                                            <div class="dash-cl-body">
                                                <table class="dash-cl-table">
                                                    <tbody>
                                                        <template x-for="field in checklistPreview.fields" :key="field.label">
                                                            <tr>
                                                                <th x-text="field.label"></th>
                                                                <td x-text="field.value"></td>
                                                            </tr>
                                                        </template>
                                                    </tbody>
                                                </table>

                                                <template x-for="(section, idx) in (checklistPreview.sections || [])" :key="idx">
                                                    <div class="dash-cl-section">
                                                        <h4 x-text="section.heading"></h4>
                                                        <template x-if="section.offices && section.offices.length">
                                                            <div class="dash-cl-office-wrap" :class="{ 'has-copies': section.with_copies }">
                                                                <table class="dash-cl-office-table">
                                                                    <thead>
                                                                        <tr>
                                                                            <th class="dash-cl-office-col-name">Office</th>
                                                                            <th class="dash-cl-office-col-copies" x-show="section.with_copies">Copies</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <template x-for="(office, officeIdx) in section.offices" :key="officeIdx">
                                                                            <tr>
                                                                                <td class="dash-cl-office-col-name" x-text="office.office"></td>
                                                                                <td class="dash-cl-office-col-copies" x-show="section.with_copies" x-text="office.copies"></td>
                                                                            </tr>
                                                                        </template>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </template>
                                                        <template x-if="section.items">
                                                            <ul class="dash-cl-list">
                                                                <template x-for="item in section.items" :key="item">
                                                                    <li x-text="item"></li>
                                                                </template>
                                                            </ul>
                                                        </template>
                                                        <template x-if="section.revisions">
                                                            <div class="dash-cl-revisions">
                                                                <template x-for="(rev, revIdx) in section.revisions" :key="revIdx">
                                                                    <div class="dash-cl-rev-card">
                                                                        <div class="dash-cl-rev-title">
                                                                            <strong x-text="rev.document_no"></strong>
                                                                            <span x-text="rev.title"></span>
                                                                        </div>
                                                                        <div class="dash-cl-rev-meta">
                                                                            <span x-text="'Rev ' + rev.revision_no"></span>
                                                                            <span x-text="rev.effectivity_date"></span>
                                                                        </div>
                                                                        <p x-show="rev.brief_purpose && rev.brief_purpose !== '—'" x-text="rev.brief_purpose"></p>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                                <aside class="dash-detail-revisions">
                                    <h4>Revisions</h4>
                                    <template x-if="revisionsLoading">
                                        <div class="dash-detail-rev-loading">Loading...</div>
                                    </template>
                                    <template x-if="!revisionsLoading && revisions.length === 0">
                                        <p class="dash-detail-rev-empty">No other revisions</p>
                                    </template>
                                    <template x-if="!revisionsLoading && revisions.length > 0">
                                        <div class="dash-detail-rev-list">
                                            <template x-for="rev in revisions" :key="rev.request_id">
                                                <button
                                                    type="button"
                                                    class="dash-detail-rev-btn"
                                                    :class="{ 'is-active': activeRequestId === rev.request_id }"
                                                    @click="selectRevision(rev)"
                                                >
                                                    <span class="dash-detail-rev-no" x-text="'Rev ' + rev.revise_no"></span>
                                                    <span class="dash-detail-rev-status" x-show="rev.revision_status === 'obsolete'">Obsolete</span>
                                                    <span class="dash-detail-rev-date" x-text="rev.effectivity_date || '—'"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                </aside>
                            </div>

                            <div class="dash-cl-modal-foot">
                                <a class="dash-cl-close-btn" :href="checklistEditUrl" x-show="checklistEditUrl">Edit document</a>
                                <a class="dash-cl-close-btn" :href="checklistStampUrl" x-show="checklistStampUrl">Stamp</a>
                                <button type="button" class="dash-cl-close-btn" @click="closeDetailModal()">Close</button>
                            </div>
                        </div>
                    </div>
                </template>
            </section>

            @if(!empty($canRegister))
            <section class="actions-section">
                <div class="section-header">
                    <h2>Quick Actions</h2>
                    <span class="section-subtitle">Frequently used operations</span>
                </div>
                <div class="actions-row">
                    <a href="{{ route('dcs.register.create', ['type' => 'new'], absolute: false) }}" class="action-box">
                        <div class="action-icon-wrap"><i class="fa-solid fa-file-circle-plus"></i></div>
                        <div class="action-content">
                            <h4>Register New Document</h4>
                            <p>Create and route initial document draft</p>
                        </div>
                        <i class="fa-solid fa-arrow-right action-arrow"></i>
                    </a>
                    <a href="{{ route('dcs.register.create', ['type' => 'revised'], absolute: false) }}" class="action-box">
                        <div class="action-icon-wrap"><i class="fa-solid fa-file-pen"></i></div>
                        <div class="action-content">
                            <h4>Register Revised Document</h4>
                            <p>Upload new version for approval</p>
                        </div>
                        <i class="fa-solid fa-arrow-right action-arrow"></i>
                    </a>
                    <a href="{{ route('dcs.register.update', absolute: false) }}" class="action-box">
                        <div class="action-icon-wrap"><i class="fa-solid fa-rotate"></i></div>
                        <div class="action-content">
                            <h4>Update Document</h4>
                            <p>Modify metadata or access permissions</p>
                        </div>
                        <i class="fa-solid fa-arrow-right action-arrow"></i>
                    </a>
                </div>
            </section>
            @endif
        </div>
            </div>

            <aside
                class="dash-calendar-panel"
                :class="{ 'is-open': calendarOpen }"
                :aria-hidden="(!calendarOpen).toString()"
                @click.stop
            >
                <div class="dash-calendar-panel-inner">
                    <div class="dash-calendar-dropdown">
                        <div class="widget calendar-widget white-card">
                            <div class="calendar-header">
                                <h3 x-text="title"></h3>
                                <div class="cal-nav">
                                    <button type="button" x-on:click="changeMonth(-1)"><i class="fa-solid fa-chevron-left"></i></button>
                                    <button type="button" x-on:click="changeMonth(1)"><i class="fa-solid fa-chevron-right"></i></button>
                                </div>
                            </div>
                            <div class="weekdays">
                                <div>S</div><div>M</div><div>T</div><div>W</div><div>T</div><div>F</div><div>S</div>
                            </div>
                            <div class="calendar-grid">
                                <template x-for="cell in cells" :key="cell.iso + cell.outside">
                                    <div class="cal-cell" :class="{ 'out-month': cell.outside }" x-on:click="openDay(cell.iso)">
                                        <div class="day-num"
                                            :class="{ today: cell.today && !cell.colors.length, holiday: cell.holiday && !cell.colors.length }"
                                            :style="cell.colors.length ? ('background-color:' + cell.colors[0] + ';color:#fff;font-weight:700') : ''"
                                            x-text="cell.day"></div>
                                        <div class="day-markers">
                                            <span class="day-marker holiday" x-show="cell.holiday"></span>
                                            <template x-for="(color, idx) in cell.colors" :key="idx">
                                                <span class="day-marker event" x-show="idx > 0" :style="'background-color:' + color"></span>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <div class="cal-legend">
                                <span class="legend-item"><span class="dot holiday-dot"></span> Holiday</span>
                                <span class="legend-item"><span class="dot event-dot"></span> Event</span>
                                <button type="button" class="btn-mini" x-on:click="openAdd(todayIso())">+ Add Event</button>
                            </div>
                        </div>

                        <div class="widget upcoming-widget white-card">
                            <div class="widget-header">
                                <h3>Events</h3>
                                <span class="badge" x-text="filteredUpcoming.length"></span>
                            </div>
                            <div class="ev-category-pills" x-show="categories.length > 0">
                                <button type="button" class="ev-cat-pill" :class="{ 'is-active': eventCategoryFilter === '' }" @click="eventCategoryFilter = ''">All</button>
                                <template x-for="cat in categories" :key="cat.id">
                                    <button
                                        type="button"
                                        class="ev-cat-pill is-category"
                                        :class="{ 'is-active': String(eventCategoryFilter) === String(cat.id) }"
                                        :style="{ '--event-category-color': categoryColor(cat) }"
                                        @click="eventCategoryFilter = cat.id"
                                        x-text="cat.name"
                                    ></button>
                                </template>
                            </div>
                            <div class="upcoming-list">
                                <template x-if="filteredUpcoming.length === 0">
                                    <div class="upcoming-empty">
                                        <i class="fa-regular fa-calendar-check"></i>
                                        <span x-text="eventCategoryFilter ? 'No events in this category' : 'No events yet'"></span>
                                    </div>
                                </template>
                                <template x-for="ev in filteredUpcoming" :key="ev.id">
                                    <div class="upcoming-item" x-on:click="openDay(ev.date)">
                                        <div class="upcoming-event-dot" :style="'background-color:' + (ev.color || '#0d2a7a')"></div>
                                        <div class="upcoming-info">
                                            <div class="upcoming-title-row">
                                                <div class="title" x-text="ev.title"></div>
                                            </div>
                                            <div class="time" x-text="eventListMeta(ev)"></div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <template x-teleport="body">
        <div class="dash-calendar-portal" x-show="modal !== null" x-cloak @keydown.escape.window="modal = null">
            <div class="dash-calendar-backdrop" @click="modal = null"></div>
            <div class="dash-event-layer" x-cloak x-bind:style="modal !== null ? 'display:flex' : 'display:none'">
                <div class="dash-event-panel" @click.stop>
                <template x-if="modal === 'day'">
                    <div class="ev-modal">
                        <div class="ev-modal-top">
                            <div class="ev-modal-icon is-view"><i class="fa-regular fa-calendar"></i></div>
                            <button type="button" class="ev-modal-close" x-on:click="modal = null"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                        <h3 class="ev-modal-title" x-text="displayDate(activeIso)"></h3>
                        <p class="ev-modal-desc" x-text="dayEvents.length + ' event' + (dayEvents.length === 1 ? '' : 's') + ' scheduled'"></p>
                        <div class="ev-holiday" x-show="holidayName(activeIso)">
                            <i class="fa-solid fa-umbrella-beach"></i><span x-text="holidayName(activeIso)"></span>
                        </div>
                        <div class="ev-card-list">
                            <template x-if="dayEvents.length === 0">
                                <div class="ev-empty"><i class="fa-regular fa-calendar"></i><span>No events scheduled</span></div>
                            </template>
                            <template x-for="ev in dayEvents" :key="ev.id">
                                <div class="ev-card">
                                    <div class="ev-card-color" :style="'background:' + (ev.color || '#0d2a7a')"></div>
                                    <div class="ev-card-body">
                                        <div class="ev-card-top">
                                            <div class="ev-card-title-wrap">
                                                <div class="ev-card-title" x-text="ev.title"></div>
                                            </div>
                                            <div class="ev-card-btns" x-show="!ev.readonly">
                                                <button type="button" class="ev-card-btn" x-on:click="openEdit(ev.id)"><i class="fa-solid fa-pen"></i></button>
                                                <button type="button" class="ev-card-btn ev-card-btn-danger" x-on:click="removeEvent(ev.id)"><i class="fa-solid fa-trash-can"></i></button>
                                            </div>
                                        </div>
                                        <div class="ev-card-time">
                                            <i class="fa-regular fa-clock"></i>
                                            <span x-text="formatTime(ev.startTime) + ' — ' + formatTime(ev.endTime)"></span>
                                        </div>
                                        <div class="ev-card-time" x-show="ev.category_name">
                                            <i class="fa-solid fa-tag"></i>
                                            <span x-text="ev.category_name"></span>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="ev-actions-row">
                            <button type="button" class="ev-btn ev-btn-primary" style="width:100%;" x-on:click="openAdd(activeIso)">
                                <i class="fa-solid fa-plus"></i> Add Event
                            </button>
                        </div>
                    </div>
                </template>
                <template x-if="modal === 'form'">
                    <div class="ev-modal">
                        <div class="ev-modal-top">
                            <div class="ev-modal-icon" :class="editingId ? 'is-edit' : 'is-add'">
                                <i class="fa-solid" :class="editingId ? 'fa-pen' : 'fa-plus'"></i>
                            </div>
                            <button type="button" class="ev-modal-close" x-on:click="openDay(form.date)"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                        <h3 class="ev-modal-title" x-text="editingId ? 'Edit Event' : 'New Event'"></h3>
                        <p class="ev-modal-desc" x-text="displayDate(form.date)"></p>
                        <form action="#" method="post" @submit.prevent="saveForm">
                            <div class="ev-field">
                                <label class="ev-label">Title</label>
                                <input class="ev-input" x-model="form.title" required autocomplete="off">
                            </div>
                            <div class="ev-field">
                                <label class="ev-label">Category</label>
                                <div class="ev-cat-select-row">
                                    <select
                                        class="ev-input"
                                        :key="'ev-cat-' + String(editingId ?? 'new')"
                                        x-model="form.category_id"
                                        required
                                    >
                                        <option value="">Select category</option>
                                        <template x-for="cat in categories" :key="cat.id">
                                            <option
                                                :value="String(cat.id)"
                                                :selected="String(form.category_id) === String(cat.id)"
                                                x-text="cat.name"
                                            ></option>
                                        </template>
                                    </select>
                                    <button type="button" class="ev-cat-icon-btn" title="Add category" :class="{ 'is-open': addingCategory }" @click="toggleAddCategory()">
                                        <i class="fa-solid fa-plus"></i>
                                    </button>
                                    <button type="button" class="ev-cat-icon-btn ev-cat-row-del" title="Delete selected category" x-show="form.category_id" @click="removeSelectedCategory()">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                                <div class="ev-cat-add" x-show="addingCategory" x-cloak>
                                    <label class="ev-color-picker" title="Category color">
                                        <span>Color</span>
                                        <input type="color" x-model="newCategoryColor" aria-label="Category color">
                                    </label>
                                    <input class="ev-input" x-ref="newCatInput" x-model="newCategory" placeholder="Category name" autocomplete="off" @keydown.enter.prevent="addCategory">
                                    <button type="button" class="ev-btn ev-btn-ghost" @click="addCategory">Add</button>
                                </div>
                                <div class="ev-cat-add" x-show="form.category_id && !addingCategory" x-cloak>
                                    <label class="ev-color-picker" title="Update category color">
                                        <span>Color</span>
                                        <input
                                            type="color"
                                            :value="selectedCategoryColor"
                                            @input="updateSelectedCategoryColor($event.target.value)"
                                            aria-label="Update category color"
                                        >
                                    </label>
                                    <span class="ev-cat-color-hint">Change color for the selected category</span>
                                </div>
                            </div>
                            <div class="ev-field">
                                <label class="ev-label">Date</label>
                                <input type="date" class="ev-input" x-model="form.date" required>
                            </div>
                            <div class="ev-time-row">
                                <div class="ev-field ev-field-half">
                                    <label class="ev-label">Start</label>
                                    <input type="time" class="ev-input" x-model="form.startTime" required>
                                </div>
                                <div class="ev-field ev-field-half">
                                    <label class="ev-label">End</label>
                                    <input type="time" class="ev-input" x-model="form.endTime" required>
                                </div>
                            </div>
                            <div class="ev-field">
                                <label class="ev-label">Notes <span class="ev-optional">Optional</span></label>
                                <textarea class="ev-textarea" rows="2" x-model="form.description"></textarea>
                            </div>
                            <div class="ev-actions-row">
                                <button type="button" class="ev-btn ev-btn-ghost" x-on:click="openDay(form.date)">Cancel</button>
                                <button type="submit" class="ev-btn ev-btn-primary">
                                    <i class="fa-solid" :class="editingId ? 'fa-check' : 'fa-plus'"></i>
                                    <span x-text="editingId ? 'Save' : 'Create'"></span>
                                </button>
                            </div>
                        </form>
                    </div>
                </template>
                </div>
            </div>
        </div>
    </template>
</main>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('dcsDashboardSearch', () => ({
        query: '',
        results: [],
        loading: false,
        open: false,
        timer: null,
        detailModal: false,
        detailDoc: null,
        detailDocLabel: '',
        revisions: [],
        revisionsLoading: false,
        checklistLoading: false,
        checklistPreview: null,
        checklistEditUrl: '',
        checklistStampUrl: '',
        activeChecklistKey: '',
        activeRequestId: null,
        checklistOptions: [
            { key: 'drf', label: 'DRF' },
            { key: 'dcn', label: 'DCN' },
            { key: 'masterlist', label: 'Masterlist' },
            { key: 'approval', label: 'Approving Body' },
            { key: 'distribution', label: 'Distribution' },
            { key: 'retrieval', label: 'Retrieval' },
        ],
        activeChecklists: null,
        search() {
            clearTimeout(this.timer);
            const q = this.query.trim();
            if (q.length < 1) {
                this.results = [];
                this.open = false;
                this.loading = false;
                return;
            }

            this.loading = true;
            this.open = true;
            this.timer = setTimeout(async () => {
                try {
                    const res = await fetch('/dcs/api/documents/search?q=' + encodeURIComponent(q));
                    this.results = res.ok ? await res.json() : [];
                } catch (e) {
                    this.results = [];
                } finally {
                    this.loading = false;
                }
            }, 300);
        },
        clear() {
            this.query = '';
            this.results = [];
            this.open = false;
            this.loading = false;
            this.closeDetailModal();
        },
        close() {
            this.open = false;
        },
        detailChecklistOptions() {
            const flags = this.activeChecklists || this.detailDoc?.checklists;
            if (!flags) return [];
            // Only tabs for checklists that were actually saved on this revision
            return this.checklistOptions.filter((cl) => !!flags[cl.key]);
        },
        resolveMatchedRevision(doc, revisions) {
            const targetId = doc.match_request_id || doc.request_id;
            if (!targetId) return null;
            const fromList = (revisions || []).find((r) => r.request_id === targetId);
            if (fromList) return fromList;
            if (targetId === doc.request_id) {
                return {
                    request_id: doc.request_id,
                    doc_no: doc.doc_no,
                    revise_no: doc.revise_no ?? 0,
                    checklists: doc.checklists || null,
                };
            }
            if (doc.match_request_id && doc.match_revise_no != null) {
                return {
                    request_id: doc.match_request_id,
                    doc_no: doc.doc_no,
                    revise_no: doc.match_revise_no,
                    checklists: null,
                };
            }
            return null;
        },
        applyActiveRevision(rev) {
            if (!rev?.request_id) return;
            this.activeRequestId = rev.request_id;
            this.detailDocLabel = (rev.doc_no || this.detailDoc?.doc_no || 'No number')
                + ' — Rev ' + (rev.revise_no ?? 0);
            this.checklistEditUrl = '/dcs/register/' + rev.request_id + '/edit';
            // Prefer per-revision checklist presence (excludes unchecked sections like DCN/Retrieval on first register)
            if (rev.checklists) {
                this.activeChecklists = rev.checklists;
            } else if (this.detailDoc?.request_id === rev.request_id && this.detailDoc?.checklists) {
                this.activeChecklists = this.detailDoc.checklists;
            } else {
                this.activeChecklists = { masterlist: true };
            }
        },
        scrollActiveRevisionIntoView() {
            this.$nextTick(() => {
                const el = document.querySelector('.dash-detail-rev-btn.is-active');
                el?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
        },
        async openDetail(doc) {
            this.detailDoc = doc;
            this.detailModal = true;
            this.revisions = [];
            this.checklistPreview = null;
            this.activeChecklistKey = '';
            this.activeChecklists = doc.checklists || null;
            this.checklistStampUrl = doc.stamp_url || '';
            document.body.classList.add('dash-cl-open');

            this.revisionsLoading = true;
            try {
                // Load the full lineage (tip), but activate the matched revision (may be obsolete).
                const lineageId = doc.lineage_request_id || doc.request_id;
                const res = await fetch('/dcs/api/documents/revisions?request_id=' + lineageId);
                this.revisions = res.ok ? await res.json() : [];
            } catch (e) {
                this.revisions = [];
            } finally {
                this.revisionsLoading = false;
            }

            const matched = this.resolveMatchedRevision(doc, this.revisions);
            this.applyActiveRevision(matched || doc);
            if (matched && matched.request_id !== (doc.lineage_request_id || doc.request_id)) {
                this.scrollActiveRevisionIntoView();
            }

            await this.loadFirstAvailableChecklist();
        },
        async selectRevision(rev) {
            if (!rev || rev.request_id === this.activeRequestId) return;
            this.applyActiveRevision(rev);
            await this.loadFirstAvailableChecklist();
        },
        async loadFirstAvailableChecklist() {
            const options = this.detailChecklistOptions();
            if (!options.length) {
                this.activeChecklistKey = '';
                this.checklistPreview = null;
                return;
            }

            // Keep current tab if it still exists on this revision; otherwise first available
            const current = this.activeChecklistKey;
            const preferred = options.find((cl) => cl.key === current) || options[0];
            await this.loadChecklist(preferred.key);
        },
        async loadChecklist(type) {
            this.activeChecklistKey = type;
            this.checklistLoading = true;
            this.checklistPreview = null;
            try {
                const res = await fetch('/dcs/api/documents/' + this.activeRequestId + '/checklist/' + type);
                if (!res.ok) throw new Error('not found');
                this.checklistPreview = await res.json();
            } catch (e) {
                this.checklistPreview = {
                    title: 'Checklist unavailable',
                    fields: [{ label: 'Message', value: 'Could not load this checklist.' }],
                    sections: [],
                };
            } finally {
                this.checklistLoading = false;
            }
        },
        closeDetailModal() {
            this.detailModal = false;
            this.detailDoc = null;
            this.revisions = [];
            this.checklistLoading = false;
            this.checklistPreview = null;
            this.detailDocLabel = '';
            this.checklistEditUrl = '';
            this.checklistStampUrl = '';
            this.activeChecklistKey = '';
            this.activeRequestId = null;
            this.activeChecklists = null;
            document.body.classList.remove('dash-cl-open');
        },
    }));

    Alpine.data('dcsDashboardCalendar', () => ({
        months: ['January','February','March','April','May','June','July','August','September','October','November','December'],
        holidays: @json($holidays),
        calendarOpen: false,
        calendarReady: false,
        calendarPersistKey: 'dcs.dashboard.calendarOpen',
        eventCategoryFilter: '',
        nowClock: '',
        _clockTimer: null,
        year: new Date().getFullYear(),
        month: new Date().getMonth(),
        events: [],
        categories: [],
        modal: null,
        activeIso: '',
        editingId: null,
        saving: false,
        addingCategory: false,
        newCategory: '',
        newCategoryColor: '#0369a1',
        form: { title: '', category_id: '', date: '', startTime: '09:00', endTime: '10:00', description: '' },
        csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },
        headers(json) {
            const h = { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' };
            if (json) h['Content-Type'] = 'application/json';
            return h;
        },
        tickClock() {
            try {
                this.nowClock = new Date().toLocaleTimeString([], {
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit',
                });
            } catch (e) {
                const n = new Date();
                this.nowClock = n.toTimeString().slice(0, 8);
            }
        },
        async init() {
            this.tickClock();
            this._clockTimer = setInterval(() => this.tickClock(), 1000);

            try {
                this.calendarOpen = localStorage.getItem(this.calendarPersistKey) === '1';
            } catch (e) {
                this.calendarOpen = false;
            }

            this.$watch('calendarOpen', (open) => {
                try {
                    localStorage.setItem(this.calendarPersistKey, open ? '1' : '0');
                } catch (e) {}
                if (open) {
                    this.loadAll();
                }
            });

            this.$nextTick(() => {
                requestAnimationFrame(() => {
                    this.calendarReady = true;
                });
            });

            await this.loadAll();
        },
        destroy() {
            if (this._clockTimer) {
                clearInterval(this._clockTimer);
                this._clockTimer = null;
            }
        },
        toggleCalendar() {
            this.calendarOpen = !this.calendarOpen;
        },
        dismissCalendarPortal() {
            if (this.modal !== null) {
                this.modal = null;
            }
        },
        async loadAll() {
            try {
                const [cats, evs] = await Promise.all([
                    fetch('/dcs/api/calendar/categories', { headers: this.headers() }).then(r => r.json()),
                    fetch('/dcs/api/calendar/events', { headers: this.headers() }).then(r => r.json()),
                ]);
                this.categories = Array.isArray(cats) ? cats : [];
                this.events = Array.isArray(evs) ? evs : [];
            } catch (e) {
                this.categories = [];
                this.events = [];
            }
        },
        get title() { return this.months[this.month] + ' ' + this.year; },
        pad(n) { return String(n).padStart(2, '0'); },
        todayIso() {
            const t = new Date();
            return `${t.getFullYear()}-${this.pad(t.getMonth() + 1)}-${this.pad(t.getDate())}`;
        },
        toIso(y, m, d) { return `${y}-${this.pad(m + 1)}-${this.pad(d)}`; },
        occursOn(ev, iso) { return String(ev.date || '').slice(0, 10) === iso; },
        holidayName(iso) { return this.holidays[iso] || ''; },
        formatTime(time24) {
            if (!time24) return '';
            const [h, m] = String(time24).split(':').map(Number);
            return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h >= 12 ? 'PM' : 'AM'}`;
        },
        displayDate(iso) {
            return new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
        },
        displayDateShort(iso) {
            return new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        },
        eventListMeta(ev) {
            const d = String(ev.date || '').slice(0, 10);
            const t = this.todayIso();
            const datePart = d === t ? 'Today' : this.displayDateShort(d);
            const cat = ev.category_name ? ev.category_name + ' · ' : '';
            return cat + datePart + ' · ' + this.formatTime(ev.startTime) + ' — ' + this.formatTime(ev.endTime);
        },
        categoryColor(category) {
            const color = String(category?.color || '').trim();
            return /^#[0-9a-f]{6}$/i.test(color) ? color : '#0d2a7a';
        },
        changeMonth(dir) {
            this.month += dir;
            if (this.month > 11) { this.month = 0; this.year += 1; }
            else if (this.month < 0) { this.month = 11; this.year -= 1; }
        },
        get cells() {
            const first = new Date(this.year, this.month, 1).getDay();
            const days = new Date(this.year, this.month + 1, 0).getDate();
            const prevDays = new Date(this.year, this.month, 0).getDate();
            const today = this.todayIso();
            const out = [];
            for (let i = 0; i < 42; i++) {
                const offset = i - first + 1;
                let day = offset, m = this.month, y = this.year, outside = false;
                if (offset < 1) { day = prevDays + offset; m -= 1; outside = true; if (m < 0) { m = 11; y -= 1; } }
                else if (offset > days) { day = offset - days; m += 1; outside = true; if (m > 11) { m = 0; y += 1; } }
                const iso = this.toIso(y, m, day);
                const evs = this.events.filter(ev => this.occursOn(ev, iso));
                out.push({ iso, day, outside, today: iso === today, holiday: !!this.holidays[iso], colors: evs.slice(0, 3).map(e => e.color || '#0d2a7a') });
            }
            return out;
        },
        get dayEvents() { return this.events.filter(ev => this.occursOn(ev, this.activeIso)); },
        get allEventsList() {
            return (Array.isArray(this.events) ? this.events : [])
                .slice()
                .sort((a, b) => {
                    const da = String(b.date || '').slice(0, 10).localeCompare(String(a.date || '').slice(0, 10));
                    if (da !== 0) return da;
                    return (b.startTime || '').localeCompare(a.startTime || '');
                });
        },
        get filteredUpcoming() {
            const list = this.allEventsList;
            if (!this.eventCategoryFilter) return list;
            return list.filter(ev => String(ev.category_id) === String(this.eventCategoryFilter));
        },
        openDay(iso) {
            this.activeIso = iso;
            this.modal = 'day';
        },
        openAdd(iso) {
            this.editingId = null;
            const categoryId = this.categories[0]?.id != null ? String(this.categories[0].id) : '';
            this.form = {
                title: '',
                category_id: '',
                date: iso || this.todayIso(),
                startTime: '09:00',
                endTime: '10:00',
                description: '',
            };
            this.modal = 'form';
            // Select options render via x-for after the modal mounts; set value on next tick.
            this.$nextTick(() => { this.form.category_id = categoryId; });
        },
        openEdit(id) {
            const ev = this.events.find(e => String(e.id) === String(id));
            if (!ev || ev.readonly) return;
            this.editingId = id;
            const categoryId = ev.category_id != null ? String(ev.category_id) : '';
            this.form = {
                title: ev.title,
                category_id: '',
                date: String(ev.date).slice(0, 10),
                startTime: ev.startTime,
                endTime: ev.endTime,
                description: ev.description || '',
            };
            this.modal = 'form';
            // Select options render via x-for after the modal mounts; set value on next tick.
            this.$nextTick(() => { this.form.category_id = categoryId; });
        },
        toggleAddCategory() {
            this.addingCategory = !this.addingCategory;
            if (this.addingCategory) {
                this.$nextTick(() => this.$refs.newCatInput?.focus());
            } else {
                this.newCategory = '';
                this.newCategoryColor = '#0369a1';
            }
        },
        async addCategory() {
            const name = (this.newCategory || '').trim();
            if (!name) return;
            try {
                const res = await fetch('/dcs/api/calendar/categories', {
                    method: 'POST',
                    headers: this.headers(true),
                    body: JSON.stringify({ name, color: this.newCategoryColor }),
                });
                const data = await res.json();
                if (!res.ok) {
                    alert(data.message || data.errors?.name?.[0] || 'Could not add category.');
                    return;
                }
                this.categories.push(data);
                this.form.category_id = String(data.id);
                this.newCategory = '';
                this.newCategoryColor = '#0369a1';
                this.addingCategory = false;
            } catch (e) {
                alert('Could not add category.');
            }
        },
        get selectedCategoryColor() {
            const cat = this.categories.find(c => String(c.id) === String(this.form.category_id));
            return cat?.color || '#0369a1';
        },
        async updateSelectedCategoryColor(color) {
            const cat = this.categories.find(c => String(c.id) === String(this.form.category_id));
            if (!cat || !color) return;
            const previous = cat.color;
            cat.color = color;
            try {
                const res = await fetch('/dcs/api/calendar/categories/' + cat.id, {
                    method: 'PUT',
                    headers: this.headers(true),
                    body: JSON.stringify({ color }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    cat.color = previous;
                    alert(data.message || data.errors?.color?.[0] || 'Could not update category color.');
                    return;
                }
                if (data?.color) cat.color = data.color;
                this.events = this.events.map(ev => String(ev.category_id) === String(cat.id)
                    ? { ...ev, color: cat.color }
                    : ev);
            } catch (e) {
                cat.color = previous;
                alert('Could not update category color.');
            }
        },
        async removeSelectedCategory() {
            const cat = this.categories.find(c => String(c.id) === String(this.form.category_id));
            if (cat) await this.removeCategory(cat);
        },
        async removeCategory(cat) {
            if (!cat) return;
            if (!confirm('Delete the "' + cat.name + '" category?')) return;
            try {
                const res = await fetch('/dcs/api/calendar/categories/' + cat.id, {
                    method: 'DELETE',
                    headers: this.headers(),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    alert(data.message || 'Could not delete category.');
                    return;
                }
                this.categories = this.categories.filter(c => c.id !== cat.id);
                if (String(this.form.category_id) === String(cat.id)) {
                    this.form.category_id = this.categories[0]?.id != null ? String(this.categories[0].id) : '';
                }
            } catch (e) {
                alert('Could not delete category.');
            }
        },
        async saveForm() {
            if (this.form.endTime < this.form.startTime) { alert('End time cannot be earlier than start time.'); return; }
            if (!this.form.category_id) { alert('Please select a category.'); return; }
            this.saving = true;
            const payload = {
                title: this.form.title,
                category_id: Number(this.form.category_id),
                date: this.form.date,
                start_time: this.form.startTime,
                end_time: this.form.endTime,
                description: this.form.description,
            };
            try {
                const url = this.editingId
                    ? '/dcs/api/calendar/events/' + this.editingId
                    : '/dcs/api/calendar/events';
                const res = await fetch(url, {
                    method: this.editingId ? 'PUT' : 'POST',
                    headers: this.headers(true),
                    body: JSON.stringify(payload),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    const firstError = data.errors ? Object.values(data.errors).flat()[0] : null;
                    alert(firstError || data.message || 'Could not save event.');
                    return;
                }
                await this.loadAll();
                this.openDay(this.form.date);
            } catch (e) {
                alert('Could not save event.');
            } finally {
                this.saving = false;
            }
        },
        async removeEvent(id) {
            const ev = this.events.find(e => String(e.id) === String(id));
            if (ev?.readonly) return;
            if (!confirm('Delete this event?')) return;
            try {
                const res = await fetch('/dcs/api/calendar/events/' + id, {
                    method: 'DELETE',
                    headers: this.headers(),
                });
                if (!res.ok) {
                    alert('Could not delete event.');
                    return;
                }
                this.events = this.events.filter(e => e.id !== id);
            } catch (e) {
                alert('Could not delete event.');
            }
        },
    }));
});
</script>
@endif
