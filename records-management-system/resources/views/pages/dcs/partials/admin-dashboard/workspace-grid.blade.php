{{--
  DCS PARTIAL: Admin dashboard bottom 3-column workspace grid (Needs attention, Continue draft, Today)
  FILE: resources/views/pages/dcs/partials/admin-dashboard/workspace-grid.blade.php
--}}
@php
    $dashAttentionItems = $dashAttentionItems ?? [];
    $dashAttentionTotal = (int) ($dashAttentionTotal ?? 0);
    $dashDraftItems = $dashDraftItems ?? [];
    $dashDraftTotal = (int) ($dashDraftTotal ?? 0);
    $calendarPeek = $calendarPeek ?? ['label' => '', 'holiday' => null, 'events' => [], 'upcoming' => []];
    $dashAttentionUrl = route('dcs.api.dashboard.request-attention', absolute: false);
@endphp

<section class="dash-access-row" aria-label="Workspace queues and schedule">
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
            <div class="dash-access-body">
                <ul class="dash-access-list">
                    <template x-for="item in attentionItems" :key="(item.type || 'drf') + '-' + (item.href || item.title)">
                        <li>
                            <a :href="item.href || '#'" class="dash-access-item">
                                <span class="dash-access-item-icon" :class="'is-' + (item.type || 'drf')">
                                    <i class="fa-solid" :class="(item.type || '') === 'dcn' ? 'fa-file-pen' : 'fa-file-lines'" aria-hidden="true"></i>
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
                <a href="{{ route('dcs.requests.index', absolute: false) }}" class="dash-access-foot">
                    Open Request queue <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        </template>
        <template x-if="!attentionItems.length">
            <div class="dash-access-empty">
                <i class="fa-regular fa-circle-check" aria-hidden="true"></i>
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
            <div class="dash-access-body">
                <ul class="dash-access-list">
                    @foreach($dashDraftItems as $draft)
                        <li>
                            <a href="{{ $draft['href'] }}" class="dash-access-item">
                                <span class="dash-access-item-icon is-draft">
                                    <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
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
                <a href="{{ route('dcs.register.drafts', absolute: false) }}" class="dash-access-foot">
                    View all drafts <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        @elseif(empty($canRegister))
            <div class="dash-access-empty">
                <i class="fa-solid fa-lock" aria-hidden="true"></i>
                <span>Register access is required to continue drafts.</span>
            </div>
        @else
            <div class="dash-access-empty">
                <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
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
            <button
                type="button"
                class="dash-access-cal-btn"
                @click.stop="$store.dcsCal.toggle()"
                :aria-expanded="($store.dcsCal.open || false).toString()"
                :class="{ 'is-active': $store.dcsCal.open }"
                :title="$store.dcsCal.open ? 'Hide calendar' : 'Open calendar'"
                aria-label="Toggle calendar"
            >
                <i class="fa-regular fa-calendar" aria-hidden="true"></i>
            </button>
        </div>

        <div class="dash-access-body">
            @if(!empty($calendarPeek['holiday']))
                <div class="dash-access-holiday">
                    <i class="fa-solid fa-flag" aria-hidden="true"></i>
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
                    <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                    <span>No events scheduled for today.</span>
                </div>
            @endif
        </div>
    </article>
</section>
