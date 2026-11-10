<?php

/**
 * DCS file: DCS dashboard. Office users see intake steps. Admin DCS sees latest, superseded, and obsolete counts. Those chips open the database with ?revision=.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    /**
     * Do not remorph this page on office-incoming-refresh.
     * A full Livewire redraw (especially with a sibling <script> root) shifts
     * .ofi-page and throws "Snapshot missing". Incoming badges/stats update via JS.
     */

    public function with(): array
    {
        $userDisplayName = trim(implode(' ', array_filter([
            auth()->user()?->details?->first_name,
            auth()->user()?->details?->last_name,
        ]))) ?: (auth()->user()?->username ?? 'User');
        $roleTable = \Illuminate\Support\Facades\Schema::hasTable('sys_condition_key')
            ? 'sys_condition_key'
            : 'condition_key';
        $roleName = trim((string) DB::table($roleTable)
            ->where('id', auth()->user()?->account_role)
            ->value('key_name'));
        $headerDate = now('Asia/Manila')->format('l, F j, Y');
        $headerTime = now('Asia/Manila')->format('g:i:s A');

        if (RegisterQueryHelper::isDcsPathPending()) {
            return [
                'isDcsPathPending' => true,
                'isLimitedDcs' => false,
                'userDisplayName' => $userDisplayName,
                'headerDate' => $headerDate,
                'headerTime' => $headerTime,
                'officeDrfCount' => 0,
                'officeDcnCount' => 0,
                'stats' => [],
                'holidays' => [],
            ];
        }

        if (RegisterQueryHelper::isLimitedDcsUser() && RegisterQueryHelper::canAccessOfficeIntake()) {
            $docGroups = OfficeIntakeHelper::officeDocumentGroups(null, false, 'received');
            $docTotal = OfficeIntakeHelper::officeDocumentTotal(null, 'received');
            $drfPipeline = OfficeIntakeHelper::officeIntakePipelineCounts('drf');
            $dcnPipeline = OfficeIntakeHelper::officeIntakePipelineCounts('dcn');
            $recentDrf = OfficeIntakeHelper::listMyDrf([
                'limit' => 5,
                'with_submission' => false,
            ]);
            $recentDcn = OfficeIntakeHelper::listMyDcn([
                'limit' => 5,
                'with_submission' => false,
            ]);
            $attentionPayload = OfficeIntakeHelper::officeDashboardAttentionPayload();
            $incomingTotal = (int) ($attentionPayload['incoming_total'] ?? 0);

            return [
                'isDcsPathPending' => false,
                'isLimitedDcs' => true,
                'officeName' => auth()->user()?->details?->office?->office_name
                    ?? auth()->user()?->details?->office?->office_code
                    ?? 'Your office',
                'userDisplayName' => $userDisplayName,
                'officeDrfCount' => (int) ($drfPipeline['total'] ?? 0),
                'officeDcnCount' => (int) ($dcnPipeline['total'] ?? 0),
                'officeDocTotal' => $docTotal,
                'officeIncomingTotal' => $incomingTotal,
                'officeDocGroups' => $docGroups,
                'officeDrfPipeline' => $drfPipeline,
                'officeDcnPipeline' => $dcnPipeline,
                'officeAttention' => collect($attentionPayload['items'] ?? [])->map(fn ($item) => (object) $item)->values(),
                'officeAttentionTotal' => (int) ($attentionPayload['total'] ?? 0),
                'officeAttentionUrl' => route('dcs.api.office-intake.attention', absolute: false),
                'recentDrf' => $recentDrf->values(),
                'recentDcn' => $recentDcn->values(),
                'headerDate' => $headerDate,
                'headerTime' => $headerTime,
                'stats' => [],
                'holidays' => [],
            ];
        }

        $typeIds = RegisterQueryHelper::parentTypeIdMap();
        $hasRevStatus = RegisterQueryHelper::supportsRevisionStatus();
        $counts = DB::table('dcs_document_requests as dr');
        RegisterQueryHelper::joinLatestByRequest($counts, 'dcs_masterlist_registration', 'ml');
        $counts->whereIn('dr.approval_status', ['applicable', 'not_applicable']);
        RegisterQueryHelper::applyNotDeleted($counts, 'dr');
        RegisterQueryHelper::applyRegisteredDocumentScope($counts, 'dr');
        $counts->groupBy('dr.doc_type_id')
            ->select('dr.doc_type_id')
            ->selectRaw('COUNT(*)::int as total');
        if ($hasRevStatus) {
            $counts->selectRaw("COUNT(*) FILTER (WHERE COALESCE(NULLIF(TRIM(ml.revision_status::text), ''), 'latest') = 'latest')::int as latest")
                ->selectRaw("COUNT(*) FILTER (WHERE ml.revision_status = 'superseded')::int as superseded")
                ->selectRaw("COUNT(*) FILTER (WHERE ml.revision_status = 'obsolete')::int as obsolete")
                ->selectRaw("COUNT(*) FILTER (WHERE ml.revision_status IS NULL OR TRIM(ml.revision_status::text) = '' OR ml.revision_status = 'latest')::int as current");
        } else {
            $counts->selectRaw('COUNT(*)::int as latest')
                ->selectRaw('0::int as superseded')
                ->selectRaw('0::int as obsolete')
                ->selectRaw('COUNT(*)::int as current');
        }
        $byTypeId = $counts->get()->keyBy(fn ($row) => (int) $row->doc_type_id);
        $countFor = function (string $key) use ($byTypeId, $typeIds): int {
            $row = $byTypeId->get((int) ($typeIds[$key] ?? 0));

            return (int) ($row->current ?? 0);
        };

        $stats = [
            'totalDocuments' => (int) $byTypeId->sum('total'),
            'latestCount' => (int) $byTypeId->sum('latest'),
            'supersededCount' => (int) $byTypeId->sum('superseded'),
            'obsoleteCount' => (int) $byTypeId->sum('obsolete'),
            'internalCount' => $countFor('internal_docs'),
            'internalFormsCount' => $countFor('internal_forms'),
            'externalCount' => $countFor('external_docs'),
            'formsCount' => $countFor('forms'),
            'logbooksCount' => $countFor('logbooks'),
        ];

        $year = (int) now('Asia/Manila')->year;
        $holidays = \App\Helpers\CalendarHelper::philippineHolidays($year, $year + 1);
        $requestCounts = RegisterQueryHelper::canBrowseAllOfficeIntake()
            ? OfficeIntakeHelper::pendingOfficeRequestCounts()
            : null;
        $canRegister = RegisterQueryHelper::canAccessDcsModule('register');
        $attentionItems = RegisterQueryHelper::canBrowseAllOfficeIntake()
            ? OfficeIntakeHelper::dashboardRequestAttentionItems(6)
            : [];
        $attentionTotal = RegisterQueryHelper::canBrowseAllOfficeIntake()
            ? OfficeIntakeHelper::pendingOfficeRequestTotal()
            : 0;
        $draftPayload = $canRegister
            ? RegisterQueryHelper::dashboardRecentDrafts(5)
            : ['total' => 0, 'items' => []];
        $calendarPeek = \App\Helpers\CalendarHelper::todayPeek();

        return [
            'isDcsPathPending' => false,
            'isLimitedDcs' => false,
            'officeDrfCount' => 0,
            'officeDcnCount' => 0,
            'requestCounts' => $requestCounts,
            'stats' => $stats,
            'typeIds' => $typeIds,
            'userDisplayName' => $userDisplayName,
            'roleName' => $roleName,
            'headerDate' => $headerDate,
            'headerTime' => $headerTime,
            'holidays' => $holidays,
            'canDatabase' => RegisterQueryHelper::canAccessDcsModule('database'),
            'canRegister' => $canRegister,
            'canSettings' => RegisterQueryHelper::canAccessDcsModule('settings'),
            'canStamping' => RegisterQueryHelper::canAccessDcsModule('stamping'),
            'dashAttentionItems' => $attentionItems,
            'dashAttentionTotal' => $attentionTotal,
            'dashDraftTotal' => (int) ($draftPayload['total'] ?? 0),
            'dashDraftItems' => $draftPayload['items'] ?? [],
            'calendarPeek' => $calendarPeek,
        ];
    }
}; ?>

@if(!empty($isDcsPathPending))
<div class="ofi-page ofi-dash" x-data="ofiDashboardClock()">
    <div class="ofi-inner ofi-inner-wide">
        <div class="ofi-dash-hero">
            <div class="ofi-dash-hero-copy">
                <p class="ofi-dash-kicker">Document Control System</p>
                <h1>Welcome{{ $userDisplayName !== '' ? ', ' . explode(' ', $userDisplayName)[0] : '' }}</h1>
                <p>Your role can open DCS, but no working path is assigned yet.</p>
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
            </div>
        </div>

        @if(session('error'))
            <div class="ofi-alert err">{{ session('error') }}</div>
        @endif
        @if(session('info'))
            <div class="ofi-alert ok">{{ session('info') }}</div>
        @endif

        <section class="ofi-dash-panel">
            <div class="ofi-dash-panel-head">
                <div>
                    <h2>Choose a DCS clearance</h2>
                    <p>Ask an administrator to enable one of these on your role (not both).</p>
                </div>
            </div>
            <div class="ofi-dash-empty" style="text-align:left;max-width:40rem;margin:0 auto;gap:1rem;">
                <p style="margin:0;">
                    <strong>Office Intake (Client)</strong> — My DRF, My DCN, and office documents for submitting offices.
                </p>
                <p style="margin:0;">
                    <strong>Document Controller (Admin DCS)</strong> — Register, Database, Review, Stamping, Reports, and other admin pages campus-wide.
                </p>
                <p style="margin:0.5rem 0 0;color:#64748b;font-size:0.9rem;">
                    Access DCS alone only unlocks this landing page. Until a path is granted, other DCS menus stay hidden.
                </p>
            </div>
        </section>
    </div>
</div>
@elseif(!empty($isLimitedDcs))
@php
    $drfPipe = $officeDrfPipeline ?? ['total' => 0, 'registered' => 0, 'correction' => 0, 'print_ready' => 0, 'submitted' => 0];
    $dcnPipe = $officeDcnPipeline ?? ['total' => 0, 'registered' => 0, 'correction' => 0, 'print_ready' => 0, 'submitted' => 0];
    $attention = $officeAttention ?? collect();
    $attentionTotal = (int) ($officeAttentionTotal ?? $attention->count());
    $statusLabel = [
        'registered' => 'Registered',
        'correction' => 'Needs correction',
        'print_ready' => 'Ready to print',
        'printed' => 'Printed',
        'submitted' => 'Awaiting RFIO',
    ];
@endphp
<div
    class="ofi-page ofi-dash"
    x-data="ofiDashboardClock(@js([
        'attentionUrl' => $officeAttentionUrl ?? route('dcs.api.office-intake.attention', absolute: false),
        'attentionTotal' => (int) $attentionTotal,
        'attentionItems' => collect($attention)->map(fn ($item) => [
            'kind' => $item->kind ?? '',
            'type' => $item->type ?? '',
            'title' => $item->title ?? '',
            'meta' => $item->meta ?? '',
            'meta_label' => $item->meta_label ?? null,
            'href' => $item->href ?? '#',
        ])->values()->all(),
        'headerTime' => $headerTime ?? '',
    ]))"
>
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
                    <a href="{{ route('dcs.office.drf.create', ['mode' => 'recurring'], absolute: false) }}" class="ofi-btn primary"><i class="fa-solid fa-rotate"></i> Recurring DRF</a>
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
            <a href="{{ route('dcs.office.incoming', absolute: false) }}" class="ofi-dash-stat is-incoming">
                <div class="ofi-dash-stat-icon"><i class="fa-solid fa-inbox"></i></div>
                <div class="ofi-dash-stat-body">
                    <span class="ofi-dash-stat-label">Incoming</span>
                    <strong class="ofi-dash-stat-value" data-ofi-incoming-stat>{{ (int) ($officeIncomingTotal ?? 0) }}</strong>
                    <span class="ofi-dash-stat-hint">Waiting to be received</span>
                </div>
            </a>
            <a href="{{ route('dcs.office.documents', ['type' => 'all'], absolute: false) }}" class="ofi-dash-stat is-docs">
                <div class="ofi-dash-stat-icon"><i class="fa-solid fa-book"></i></div>
                <div class="ofi-dash-stat-body">
                    <span class="ofi-dash-stat-label">Masterlist</span>
                    <strong class="ofi-dash-stat-value">{{ (int) $officeDocTotal }}</strong>
                    <span class="ofi-dash-stat-hint">Received by your office</span>
                </div>
            </a>
        </section>

        <div class="ofi-dash-layout">
            <div class="ofi-dash-main">
                <section class="ofi-dash-panel ofi-dash-attention" x-show="attentionTotal > 0" x-cloak>
                    <div class="ofi-dash-panel-head">
                        <div>
                            <h2>Needs your action</h2>
                            <p>
                                <span x-text="attentionTotal"></span>
                                <span x-text="attentionTotal === 1 ? ' item' : ' items'"></span>
                                waiting — corrections, print unlocks, receipt, or appraisal.
                            </p>
                        </div>
                        <span class="ofi-dash-attention-count" x-text="attentionTotal"></span>
                    </div>
                    <ul class="ofi-dash-attention-list">
                        <template x-for="item in attentionItems" :key="(item.kind || '') + ':' + (item.type || '') + ':' + (item.href || '') + ':' + (item.title || '')">
                            <li>
                                <a :href="item.href" class="ofi-dash-attention-item" :class="'is-' + attentionKindClass(item.kind)">
                                    <span class="ofi-dash-attention-icon" aria-hidden="true">
                                        <i :class="attentionIcon(item.kind)"></i>
                                    </span>
                                    <span class="ofi-dash-attention-copy">
                                        <span class="ofi-dash-attention-kind" x-text="attentionKindLabel(item)"></span>
                                        <strong x-text="item.title"></strong>
                                        <template x-if="item.meta_label">
                                            <span class="ofi-dash-attention-meta-label" x-text="item.meta_label"></span>
                                        </template>
                                        <span class="ofi-dash-attention-meta" x-text="item.meta"></span>
                                    </span>
                                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                </a>
                            </li>
                        </template>
                    </ul>
                </section>

                <section class="ofi-dash-panel ofi-dash-types-panel">
            <div class="ofi-dash-panel-head">
                <div>
                    <h2>Masterlist by type</h2>
                            <p>Jump into controlled documents your office has already received.</p>
                </div>
                        <a href="{{ route('dcs.office.documents', ['type' => 'all'], absolute: false) }}" class="ofi-dash-link">View all <i class="fa-solid fa-arrow-right"></i></a>
            </div>
            <div class="ofi-dash-type-grid">
                @foreach($officeDocGroups as $group)
                            @php
                                $typeIcon = match ($group['key'] ?? '') {
                                    'internal', 'internal_docs' => 'fa-building',
                                    'internal_forms' => 'fa-clipboard-list',
                                    'external', 'external_docs' => 'fa-globe',
                                    'forms' => 'fa-file-lines',
                                    'logbooks' => 'fa-book-open',
                                    default => 'fa-folder',
                                };
                                $share = $officeDocTotal > 0 ? (int) round(($group['count'] / max(1, $officeDocTotal)) * 100) : 0;
                            @endphp
                    <a
                        href="{{ route('dcs.office.documents', ['type' => $group['key']], absolute: false) }}"
                        class="ofi-dash-type {{ $group['count'] < 1 ? 'is-empty' : '' }}"
                    >
                                <span class="ofi-dash-type-icon"><i class="fa-solid {{ $typeIcon }}"></i></span>
                                <span class="ofi-dash-type-copy">
                        <span class="ofi-dash-type-label">{{ $group['label'] }}</span>
                                    <span class="ofi-dash-type-meta">{{ $group['count'] === 1 ? '1 document' : $group['count'].' documents' }}</span>
                                    <span class="ofi-dash-type-bar" aria-hidden="true"><span style="width: {{ $share }}%"></span></span>
                                </span>
                        <strong class="ofi-dash-type-count">{{ $group['count'] }}</strong>
                    </a>
                @endforeach
            </div>
        </section>

        <div class="ofi-dash-split">
                    <section class="ofi-dash-panel ofi-dash-activity">
                <div class="ofi-dash-panel-head">
                    <div>
                        <h2>Recent DRF</h2>
                                <p>{{ (int) $drfPipe['submitted'] + (int) $drfPipe['correction'] + (int) $drfPipe['print_ready'] + (int) ($drfPipe['printed'] ?? 0) }} still in progress · {{ (int) $drfPipe['registered'] }} registered</p>
                    </div>
                            <a href="{{ route('dcs.office.drf.index', absolute: false) }}" class="ofi-dash-link">See all <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                @if($recentDrf->isEmpty())
                    <div class="ofi-dash-empty">
                                <div class="ofi-dash-empty-icon"><i class="fa-regular fa-file-lines"></i></div>
                                <p>No DRF yet. Create one to start a request for RFIO.</p>
                        <a href="{{ route('dcs.office.drf.create', absolute: false) }}" class="ofi-btn primary"><i class="fa-solid fa-plus"></i> Create DRF</a>
                    </div>
                @else
                    <ul class="ofi-dash-list">
                        @foreach($recentDrf as $row)
                                    @php $pipe = $row->pipeline_status ?? (!empty($row->is_registered) ? 'registered' : 'submitted'); @endphp
                            <li>
                                <a href="{{ route('dcs.office.drf.show', $row->id, absolute: false) }}">
                                            <span class="ofi-dash-list-mark is-drf" aria-hidden="true"><i class="fa-regular fa-file-lines"></i></span>
                                            <span class="ofi-dash-list-main">
                                    <span class="ofi-dash-list-title">{{ $row->doc_title ?: 'Untitled DRF' }}</span>
                                    <span class="ofi-dash-list-meta">
                                                    <span class="ofi-dash-list-date">{{ $row->drf_date ? \App\Helpers\RegisterQueryHelper::formatSmartDate($row->drf_date) : 'No date' }}</span>
                                                    @if(!empty($row->registered_doc_no))
                                                        <span class="ofi-dash-list-sep">·</span>
                                                        <span>{{ $row->registered_doc_no }}</span>
                                        @elseif(!empty($row->drf_no))
                                                        <span class="ofi-dash-list-sep">·</span>
                                                        <span>{{ $row->drf_no }}</span>
                                        @endif
                                    </span>
                                            </span>
                                            <span class="ofi-dash-status is-{{ $pipe }}">{{ $statusLabel[$pipe] ?? 'Submitted' }}</span>
                                            <i class="fa-solid fa-chevron-right ofi-dash-list-chevron" aria-hidden="true"></i>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

                    <section class="ofi-dash-panel ofi-dash-activity">
                <div class="ofi-dash-panel-head">
                    <div>
                        <h2>Recent DCN</h2>
                                <p>{{ (int) $dcnPipe['submitted'] + (int) $dcnPipe['correction'] + (int) $dcnPipe['print_ready'] + (int) ($dcnPipe['printed'] ?? 0) }} still in progress · {{ (int) $dcnPipe['registered'] }} registered</p>
                    </div>
                            <a href="{{ route('dcs.office.dcn.index', absolute: false) }}" class="ofi-dash-link">See all <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                @if($recentDcn->isEmpty())
                    <div class="ofi-dash-empty">
                                <div class="ofi-dash-empty-icon"><i class="fa-solid fa-file-pen"></i></div>
                                <p>No DCN yet. Create one when a controlled document needs revision.</p>
                        <a href="{{ route('dcs.office.dcn.create', absolute: false) }}" class="ofi-btn primary"><i class="fa-solid fa-plus"></i> Create DCN</a>
                    </div>
                @else
                    <ul class="ofi-dash-list">
                        @foreach($recentDcn as $row)
                                    @php $pipe = $row->pipeline_status ?? (!empty($row->is_registered) ? 'registered' : 'submitted'); @endphp
                            <li>
                                <a href="{{ route('dcs.office.dcn.show', $row->id, absolute: false) }}">
                                            <span class="ofi-dash-list-mark is-dcn" aria-hidden="true"><i class="fa-solid fa-file-pen"></i></span>
                                            <span class="ofi-dash-list-main">
                                                <span class="ofi-dash-list-title">{{ $row->document_title ?? $row->originator_name ?? ($row->dcn_no ?: 'Untitled DCN') }}</span>
                                    <span class="ofi-dash-list-meta">
                                                    <span class="ofi-dash-list-date">{{ $row->dcn_date ? \App\Helpers\RegisterQueryHelper::formatSmartDate($row->dcn_date) : 'No date' }}</span>
                                                    @if(!empty($row->registered_doc_no))
                                                        <span class="ofi-dash-list-sep">·</span>
                                                        <span>{{ $row->registered_doc_no }}</span>
                                                    @elseif(!empty($row->document_no))
                                                        <span class="ofi-dash-list-sep">·</span>
                                                        <span>{{ $row->document_no }}</span>
                                                    @elseif(!empty($row->dcn_no))
                                                        <span class="ofi-dash-list-sep">·</span>
                                                        <span>{{ $row->dcn_no }}</span>
                                                    @endif
                                    </span>
                                            </span>
                                            <span class="ofi-dash-status is-{{ $pipe }}">{{ $statusLabel[$pipe] ?? 'Submitted' }}</span>
                                            <i class="fa-solid fa-chevron-right ofi-dash-list-chevron" aria-hidden="true"></i>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
                </div>
        </div>

        </div>

        <section class="ofi-dash-panel ofi-dash-guide">
            <div class="ofi-dash-panel-head">
                <div>
                    <h2>How intake works</h2>
                    <p>Three stages from draft to RFIO registration.</p>
                </div>
            </div>
            <ol class="ofi-dash-step-list">
                <li>
                    <span class="ofi-dash-step-num">1</span>
                    <div>
                        <strong>Create the form</strong>
                        <p>Fill out a new DRF or DCN for your office and submit it to RFIO for review.</p>
                    </div>
                </li>
                <li>
                    <span class="ofi-dash-step-num">2</span>
                    <div>
                        <strong>Print and sign</strong>
                        <p>Print stays locked until RFIO confirms the form. Then print and have it signed.</p>
                    </div>
                </li>
                <li>
                    <span class="ofi-dash-step-num">3</span>
                    <div>
                        <strong>Submit to RFIO</strong>
                        <p>Bring the signed hard copy to Records for registration on the masterlist.</p>
                    </div>
                </li>
            </ol>
        </section>
    </div>
</div>
@else
<main class="dashboard-main" wire:ignore x-data="dcsDashboardCalendar(@js(['holidays' => $holidays ?? []]))">
    <div
        class="dash-calendar-shell"
        @keydown.escape.window="modal !== null && (modal = null)"
    >
        <div class="dashboard-header dash-welcome-bar">
            <div class="welcome-text dash-welcome-copy">
                <p class="header-greeting">{{ $roleName !== '' ? $roleName : 'User' }}</p>
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
            <section class="dash-queue-bar" aria-label="Document queues">
                @if(!empty($canDatabase))
                    <a href="{{ route('dcs.database.index', absolute: false) }}" class="dash-queue-chip" title="Every registered revision on the masterlist">
                        <span>Total</span><strong>{{ number_format((int) $stats['totalDocuments']) }}</strong>
                    </a>
                    <a href="{{ route('dcs.database.index', ['revision' => 'latest'], absolute: false) }}" class="dash-queue-chip is-latest" title="Current controlled copies only">
                        <span>Latest</span><strong>{{ number_format((int) $stats['latestCount']) }}</strong>
                    </a>
                    <a href="{{ route('dcs.database.index', ['revision' => 'superseded'], absolute: false) }}" class="dash-queue-chip is-superseded" title="Replaced by a newer revision">
                        <span>Superseded</span><strong>{{ number_format((int) $stats['supersededCount']) }}</strong>
                    </a>
                    <a href="{{ route('dcs.database.index', ['revision' => 'obsolete'], absolute: false) }}" class="dash-queue-chip is-obsolete" title="Withdrawn from use">
                        <span>Obsolete</span><strong>{{ number_format((int) $stats['obsoleteCount']) }}</strong>
                    </a>
                @else
                    <div class="dash-queue-chip" title="Every registered revision on the masterlist"><span>Total</span><strong>{{ number_format((int) $stats['totalDocuments']) }}</strong></div>
                    <div class="dash-queue-chip is-latest" title="Current controlled copies only"><span>Latest</span><strong>{{ number_format((int) $stats['latestCount']) }}</strong></div>
                    <div class="dash-queue-chip is-superseded" title="Replaced by a newer revision"><span>Superseded</span><strong>{{ number_format((int) $stats['supersededCount']) }}</strong></div>
                    <div class="dash-queue-chip is-obsolete" title="Withdrawn from use"><span>Obsolete</span><strong>{{ number_format((int) $stats['obsoleteCount']) }}</strong></div>
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
                        <span class="dash-search-hint" x-show="query.trim().length === 1" x-cloak>Type at least 2 characters</span>
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

                        <template x-if="loading && results.length === 0">
                            <div class="dash-search-state">
                                <i class="fa-solid fa-spinner fa-spin"></i>
                                <span>Searching masterlist records...</span>
                            </div>
                        </template>

                        <template x-if="!loading && results.length === 0">
                            <div class="dash-search-state">
                                <i class="fa-regular fa-folder-open"></i>
                                <span x-text="query.trim().length < 2 ? 'Type at least 2 characters to search.' : 'No documents matched your search.'"></span>
                            </div>
                        </template>

                        <template x-if="results.length > 0">
                            <div class="dash-search-list" :class="{ 'is-refreshing': loading }">
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
                                                <template x-if="activeChecklistKey === 'file' && checklistPreview && checklistPreview.preview_url">
                                                    <div class="dash-file-preview">
                                                        <iframe
                                                            class="dash-file-preview-frame"
                                                            :src="checklistPreview.preview_url"
                                                            title="Document scan preview"
                                                        ></iframe>
                                                    </div>
                                                </template>

                                                <template x-if="activeChecklistKey === 'file' && checklistPreview && checklistPreview.can_verify">
                                                    <div class="dash-file-verify">
                                                        <button
                                                            type="button"
                                                            class="dash-file-verify-btn"
                                                            :disabled="fileVerifyLoading"
                                                            @click="verifyFileChecksum()"
                                                        >
                                                            <i class="fa-solid" :class="fileVerifyLoading ? 'fa-spinner fa-spin' : 'fa-shield-halved'"></i>
                                                            <span x-text="fileVerifyLoading ? 'Verifying…' : 'Verify checksum'"></span>
                                                        </button>
                                                        <p
                                                            class="dash-file-verify-msg"
                                                            :class="{
                                                                'is-ok': fileVerifyState === 'match',
                                                                'is-bad': fileVerifyState === 'mismatch' || fileVerifyState === 'missing'
                                                            }"
                                                            x-show="fileVerifyMessage"
                                                            x-text="fileVerifyMessage"
                                                        ></p>
                                                    </div>
                                                </template>

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
                                                                        <template x-for="(office, officeIdx) in visibleSectionOffices(section)" :key="officeIdx">
                                                                            <tr>
                                                                                <td class="dash-cl-office-col-name" x-text="office.office"></td>
                                                                                <td class="dash-cl-office-col-copies" x-show="section.with_copies" x-text="office.copies"></td>
                                                                            </tr>
                                                                        </template>
                                                                    </tbody>
                                                                </table>
                                                                <button
                                                                    type="button"
                                                                    class="dash-cl-office-more"
                                                                    x-show="sectionOfficesNeedMore(section)"
                                                                    @click="toggleSectionOffices(section)"
                                                                    x-text="officeListExpanded[section.heading] ? 'See less' : ('See more (' + (section.offices.length - 4) + ' more)')"
                                                                ></button>
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
                                                                            <span x-text="rev.effectivity_date_label || rev.effectivity_date"></span>
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
                                                    <span
                                                        class="dash-detail-rev-status"
                                                        :class="{
                                                            'is-latest': !rev.revision_status || rev.revision_status === 'latest',
                                                            'is-superseded': rev.revision_status === 'superseded',
                                                            'is-obsolete': rev.revision_status === 'obsolete'
                                                        }"
                                                        x-text="rev.revision_status === 'obsolete' ? 'Obsolete' : (rev.revision_status === 'superseded' ? 'Superseded' : 'Latest')"
                                                    ></span>
                                                    <span class="dash-detail-rev-date" x-text="rev.effectivity_date_label || rev.effectivity_date || '—'"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                </aside>
                            </div>

                            <div class="dash-cl-modal-foot">
                                <a class="dash-cl-close-btn" :href="checklistInventoryUrl" x-show="checklistInventoryUrl">Open in Database</a>
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
                            <p>Register a document that is not in the system yet</p>
                        </div>
                        <i class="fa-solid fa-arrow-right action-arrow"></i>
                    </a>
                    <a href="{{ route('dcs.register.create', ['type' => 'revised'], absolute: false) }}" class="action-box">
                        <div class="action-icon-wrap"><i class="fa-solid fa-file-pen"></i></div>
                        <div class="action-content">
                            <h4>Register Revised Document</h4>
                            <p>Register the next revision of a document already on file</p>
                        </div>
                        <i class="fa-solid fa-arrow-right action-arrow"></i>
                    </a>
                    <a href="{{ route('dcs.register.update', absolute: false) }}" class="action-box">
                        <div class="action-icon-wrap"><i class="fa-solid fa-rotate"></i></div>
                        <div class="action-content">
                            <h4>Update Document</h4>
                            <p>Change the details of a document that is already registered</p>
                        </div>
                        <i class="fa-solid fa-arrow-right action-arrow"></i>
                    </a>
                </div>
            </section>
            @endif

            @php
                $dashAttentionItems = $dashAttentionItems ?? [];
                $dashAttentionTotal = (int) ($dashAttentionTotal ?? 0);
                $dashDraftItems = $dashDraftItems ?? [];
                $dashDraftTotal = (int) ($dashDraftTotal ?? 0);
                $calendarPeek = $calendarPeek ?? ['label' => '', 'holiday' => null, 'events' => [], 'upcoming' => []];
                $dashAttentionUrl = route('dcs.api.dashboard.request-attention', absolute: false);
            @endphp
            <section class="dash-access-row" aria-label="Quick access">
                <article
                    class="dash-access-card dash-access-attention"
                    x-data="dcsDashAttention(@js([
                        'attentionUrl' => $dashAttentionUrl,
                        'attentionTotal' => $dashAttentionTotal,
                        'attentionItems' => $dashAttentionItems,
                    ]))"
                >
                    <div class="dash-access-head">
                        <div>
                            <h2>Needs attention</h2>
                            <p>Office DRF/DCN waiting in Request</p>
        </div>
                        <span
                            class="dash-access-count"
                            x-show="attentionTotal > 0"
                            x-text="attentionTotal"
                            x-cloak
                            @if($dashAttentionTotal < 1) style="display:none" @endif
                        >{{ $dashAttentionTotal }}</span>
                    </div>
                    <template x-if="attentionItems.length">
                        <div>
                            <ul class="dash-access-list">
                                <template x-for="item in attentionItems" :key="(item.type || 'drf') + '-' + (item.href || item.title)">
                                    <li>
                                        <a :href="item.href || '#'" class="dash-access-item">
                                            <span class="dash-access-item-icon" :class="'is-' + (item.type || 'drf')">
                                                <i class="fa-solid" :class="(item.type || '') === 'dcn' ? 'fa-file-pen' : 'fa-file-lines'"></i>
                                            </span>
                                            <span class="dash-access-item-copy">
                                                <span class="dash-access-item-kind" x-text="String(item.type || 'DRF').toUpperCase()"></span>
                                                <strong x-text="item.title"></strong>
                                                <span x-text="item.meta"></span>
                                            </span>
                                            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                        </a>
                                    </li>
                                </template>
                            </ul>
                            <a href="{{ route('dcs.requests.index', absolute: false) }}" class="dash-access-foot">Open Request queue <i class="fa-solid fa-arrow-right"></i></a>
                        </div>
                    </template>
                    <template x-if="!attentionItems.length">
                        <div class="dash-access-empty">
                            <i class="fa-regular fa-circle-check"></i>
                            <span>No open office requests right now.</span>
                        </div>
                    </template>
                </article>

                <article class="dash-access-card dash-access-drafts">
                    <div class="dash-access-head">
                        <div>
                            <h2>Continue draft</h2>
                            <p>Unfinished registrations</p>
                        </div>
                        @if(!empty($canRegister) && $dashDraftTotal > 0)
                            <span class="dash-access-count">{{ $dashDraftTotal }}</span>
                        @endif
                    </div>
                    @if(!empty($canRegister) && $dashDraftItems !== [])
                        <ul class="dash-access-list">
                            @foreach($dashDraftItems as $draft)
                                <li>
                                    <a href="{{ $draft['href'] }}" class="dash-access-item">
                                        <span class="dash-access-item-icon is-draft">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </span>
                                        <span class="dash-access-item-copy">
                                            <span class="dash-access-item-kind">{{ $draft['doc_type'] }} · {{ $draft['doc_no'] }}</span>
                                            <strong>{{ $draft['title'] }}</strong>
                                            @if(!empty($draft['updated_at']))
                                                <span>Updated {{ $draft['updated_at'] }}</span>
                                            @endif
                                        </span>
                                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        <a href="{{ route('dcs.register.drafts', absolute: false) }}" class="dash-access-foot">View all drafts <i class="fa-solid fa-arrow-right"></i></a>
                    @elseif(empty($canRegister))
                        <div class="dash-access-empty">
                            <i class="fa-solid fa-lock"></i>
                            <span>Register access is required to continue drafts.</span>
                        </div>
                    @else
                        <div class="dash-access-empty">
                            <i class="fa-regular fa-folder-open"></i>
                            <span>No saved drafts yet.</span>
                        </div>
                    @endif
                </article>

                <article class="dash-access-card dash-access-calendar">
                    <div class="dash-access-head">
                        <div>
                            <h2>Today</h2>
                            <p>{{ $calendarPeek['label'] ?? $headerDate }}</p>
                        </div>
                        <button type="button" class="dash-access-cal-btn" @click="toggleCalendar()" title="Open calendar">
                            <i class="fa-regular fa-calendar"></i>
                        </button>
                    </div>
                    @if(!empty($calendarPeek['holiday']))
                        <div class="dash-access-holiday">
                            <i class="fa-solid fa-flag"></i>
                            <span>{{ $calendarPeek['holiday'] }}</span>
                        </div>
                    @endif
                    @if(!empty($calendarPeek['events']))
                        <ul class="dash-access-list">
                            @foreach($calendarPeek['events'] as $event)
                                <li>
                                    <div class="dash-access-item is-static">
                                        <span class="dash-access-item-dot" style="background: {{ $event['color'] ?? '#0d2a7a' }}"></span>
                                        <span class="dash-access-item-copy">
                                            <span class="dash-access-item-kind">{{ $event['startTime'] }}{{ !empty($event['endTime']) ? ' – '.$event['endTime'] : '' }}@if(!empty($event['category_name'])) · {{ $event['category_name'] }}@endif</span>
                                            <strong>{{ $event['title'] }}</strong>
                                        </span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @elseif(!empty($calendarPeek['upcoming']))
                        <p class="dash-access-upcoming-label">Coming up</p>
                        <ul class="dash-access-list">
                            @foreach($calendarPeek['upcoming'] as $event)
                                <li>
                                    <div class="dash-access-item is-static">
                                        <span class="dash-access-item-dot" style="background: {{ $event['color'] ?? '#0d2a7a' }}"></span>
                                        <span class="dash-access-item-copy">
                                            <span class="dash-access-item-kind">{{ \Carbon\Carbon::parse($event['date'])->timezone('Asia/Manila')->format('D, M j') }} · {{ $event['startTime'] }}</span>
                                            <strong>{{ $event['title'] }}</strong>
                                        </span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @elseif(empty($calendarPeek['holiday']))
                        <div class="dash-access-empty">
                            <i class="fa-regular fa-calendar"></i>
                            <span>No events scheduled for today.</span>
                        </div>
                    @endif
                </article>
            </section>
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
                <template x-if="modal === 'delete'">
                    <div class="ev-modal">
                        <div class="ev-modal-top">
                            <div class="ev-modal-icon is-delete"><i class="fa-solid fa-trash-can"></i></div>
                            <button type="button" class="ev-modal-close" x-on:click="cancelDeleteEvent()"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                        <h3 class="ev-modal-title">Delete event?</h3>
                        <p class="ev-modal-desc" x-text="pendingDeleteTitle ? ('“' + pendingDeleteTitle + '” will be removed from the calendar.') : 'This event will be removed from the calendar.'"></p>
                        <div class="ev-actions-row">
                            <button type="button" class="ev-btn ev-btn-ghost" x-on:click="cancelDeleteEvent()">Cancel</button>
                            <button type="button" class="ev-btn ev-btn-danger" x-on:click="confirmRemoveEvent()" :disabled="saving">
                                <i class="fa-solid fa-trash-can"></i>
                                <span x-text="saving ? 'Deleting…' : 'Delete'"></span>
                            </button>
                        </div>
                    </div>
                </template>
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
                                                <button type="button" class="ev-card-btn ev-card-btn-danger" x-on:click="askDeleteEvent(ev.id)"><i class="fa-solid fa-trash-can"></i></button>
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
@endif

{{-- Alpine dashboard components live in public/js (keep out of this blade). Load before @livewireScripts. --}}
<script>
    window.__DCS_DASH_BOOT = @json([
        'holidays' => $holidays ?? [],
    ]);
</script>
<script src="{{ asset('js/dcs/dashboard-page.js') }}?v={{ @filemtime(public_path('js/dcs/dashboard-page.js')) ?: time() }}"></script>
