{{--
  DCS PAGE: DCS dashboard home
  FILE: resources/views/pages/dcs/index.blade.php
  DEBUG: RegisterQueryHelper dashboard helpers; DcsNotificationService
--}}
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

        $dashboardCounts = RegisterQueryHelper::adminDashboardStats();
        $canRegister = RegisterQueryHelper::canAccessDcsModule('register');
        $canBrowseIntake = RegisterQueryHelper::canBrowseAllOfficeIntake();
        $attentionItems = $canBrowseIntake
            ? OfficeIntakeHelper::dashboardRequestAttentionItems(6)
            : [];
        $attentionTotal = $canBrowseIntake
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
            'stats' => $dashboardCounts['stats'],
            'typeIds' => $dashboardCounts['typeIds'],
            'userDisplayName' => $userDisplayName,
            'roleName' => $roleName,
            'headerDate' => $headerDate,
            'headerTime' => $headerTime,
            'canDatabase' => RegisterQueryHelper::canAccessDcsModule('database'),
            'canRegister' => $canRegister,
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
        'submitted' => 'Awaiting RFIU',
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
                <p>Create and print DRF/DCN forms for RFIU, and browse controlled documents for your office.</p>
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
                    <div class="ofi-dash-empty is-drf">
                        <div class="ofi-dash-empty-icon" aria-hidden="true"><i class="fa-regular fa-file-lines"></i></div>
                        <strong>No Document Request Forms yet</strong>
                        <p>Create a DRF to start a request for RFIU.</p>
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
                    <div class="ofi-dash-empty is-dcn">
                        <div class="ofi-dash-empty-icon" aria-hidden="true"><i class="fa-solid fa-file-pen"></i></div>
                        <strong>No Document Change Notices yet</strong>
                        <p>Create a DCN when a controlled document needs revision.</p>
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
                    <p>Three stages from draft to RFIU registration.</p>
                </div>
            </div>
            <ol class="ofi-dash-step-list">
                <li>
                    <span class="ofi-dash-step-num">1</span>
                    <div>
                        <strong>Create the form</strong>
                        <p>Fill out a new DRF or DCN for your office and submit it to RFIU for review.</p>
                    </div>
                </li>
                <li>
                    <span class="ofi-dash-step-num">2</span>
                    <div>
                        <strong>Print and sign</strong>
                        <p>Print stays locked until RFIU confirms the form. Then print and have it signed.</p>
                    </div>
                </li>
                <li>
                    <span class="ofi-dash-step-num">3</span>
                    <div>
                        <strong>Submit to RFIU</strong>
                        <p>Bring the signed hard copy to Records for registration on the masterlist.</p>
                    </div>
                </li>
            </ol>
        </section>
    </div>
</div>
@else
<main class="dashboard-main" wire:ignore x-data>
    <div class="dash-shell">
        @include('pages.dcs.partials.admin-dashboard.hero')

        <div class="dash-stack">
            @include('pages.dcs.partials.admin-dashboard.overview')
            @include('pages.dcs.partials.admin-dashboard.search')
            @include('pages.dcs.partials.admin-dashboard.quick-actions')
            @include('pages.dcs.partials.admin-dashboard.workspace-grid')
        </div>
    </div>
</main>
@endif
