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
    $formatEventTime = static function (?string $time): string {
        $raw = trim((string) $time);
        if ($raw === '') {
            return '';
        }
        try {
            return \Carbon\Carbon::parse($raw)->format('g:i A');
        } catch (\Throwable) {
            return $raw;
        }
    };
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
                            <a :href="item.href || '#'" class="dash-access-item" :title="item.title">
                                <span class="dash-access-item-icon" :class="'is-' + (item.type || 'drf')">
                                    <i class="fa-solid" :class="(item.type || '') === 'dcn' ? 'fa-file-pen' : 'fa-file-lines'" aria-hidden="true"></i>
                                </span>
                                <span class="dash-access-item-copy">
                                    <span class="dash-access-item-title-row">
                                        <span
                                            class="dash-access-type-pill"
                                            :class="'is-' + (item.type || 'drf')"
                                            x-text="String(item.type || 'DRF').toUpperCase()"
                                        ></span>
                                        <strong x-text="item.title"></strong>
                                    </span>
                                    <span class="dash-access-item-sub">
                                        <i class="fa-regular fa-building" aria-hidden="true"></i>
                                        <span x-text="item.meta"></span>
                                    </span>
                                </span>
                                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                            </a>
                        </li>
                    </template>
                </ul>
                <a href="{{ route('dcs.requests.index', absolute: false) }}" class="dash-access-foot">
                    <span>Open Request queue</span>
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        </template>
        <template x-if="!attentionItems.length">
            <div class="dash-access-empty">
                <span class="dash-access-empty-icon is-ok" aria-hidden="true">
                    <i class="fa-regular fa-circle-check"></i>
                </span>
                <strong>All caught up</strong>
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
                        @php
                            $draftDocNo = trim((string) ($draft['doc_no'] ?? ''));
                            $hasDraftDocNo = $draftDocNo !== '' && $draftDocNo !== '—';
                        @endphp
                        <li>
                            <a href="{{ $draft['href'] }}" class="dash-access-item" title="{{ $draft['title'] }}">
                                <span class="dash-access-item-icon is-draft">
                                    <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                </span>
                                <span class="dash-access-item-copy">
                                    <span class="dash-access-item-title-row">
                                        <strong>{{ $draft['title'] }}</strong>
                                    </span>
                                    <span class="dash-access-item-tags">
                                        @if(!empty($draft['doc_type']))
                                            <span class="dash-access-type-pill is-draft">{{ $draft['doc_type'] }}</span>
                                        @endif
                                        @if($hasDraftDocNo)
                                            <span class="dash-access-code-pill">{{ $draftDocNo }}</span>
                                        @endif
                                        @if(!empty($draft['updated_at']))
                                            <span class="dash-access-item-time">
                                                <i class="fa-regular fa-clock" aria-hidden="true"></i>{{ $draft['updated_at'] }}
                                            </span>
                                        @endif
                                    </span>
                                </span>
                                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('dcs.register.drafts', absolute: false) }}" class="dash-access-foot">
                    <span>View all drafts</span>
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        @elseif(empty($canRegister))
            <div class="dash-access-empty">
                <span class="dash-access-empty-icon" aria-hidden="true">
                    <i class="fa-solid fa-lock"></i>
                </span>
                <strong>Restricted</strong>
                <span>Register access is required to continue drafts.</span>
            </div>
        @else
            <div class="dash-access-empty">
                <span class="dash-access-empty-icon" aria-hidden="true">
                    <i class="fa-regular fa-folder-open"></i>
                </span>
                <strong>No drafts saved</strong>
                <span>Unfinished registrations will appear here.</span>
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
                        @php
                            $startLabel = $formatEventTime($event['startTime'] ?? '');
                            $endLabel = $formatEventTime($event['endTime'] ?? '');
                        @endphp
                        <li>
                            <div class="dash-access-item is-static" title="{{ $event['title'] }}">
                                <span class="dash-access-item-dot" style="background: {{ $event['color'] ?? '#0d2a7a' }}"></span>
                                <span class="dash-access-item-copy">
                                    <span class="dash-access-item-title-row">
                                        <strong>{{ $event['title'] }}</strong>
                                    </span>
                                    <span class="dash-access-item-tags">
                                        @if($startLabel !== '')
                                            <span class="dash-access-item-time">
                                                <i class="fa-regular fa-clock" aria-hidden="true"></i>{{ $startLabel }}{{ $endLabel !== '' ? ' – '.$endLabel : '' }}
                                            </span>
                                        @endif
                                        @if(!empty($event['category_name']))
                                            <span class="dash-access-code-pill">{{ $event['category_name'] }}</span>
                                        @endif
                                    </span>
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @elseif(!empty($calendarPeek['upcoming']))
                <p class="dash-access-upcoming-label">Coming up</p>
                <ul class="dash-access-list">
                    @foreach($calendarPeek['upcoming'] as $event)
                        @php
                            $upcomingStart = $formatEventTime($event['startTime'] ?? '');
                            $upcomingEnd = $formatEventTime($event['endTime'] ?? '');
                        @endphp
                        <li>
                            <div class="dash-access-item is-static" title="{{ $event['title'] }}">
                                <span class="dash-access-item-dot" style="background: {{ $event['color'] ?? '#0d2a7a' }}"></span>
                                <span class="dash-access-item-copy">
                                    <span class="dash-access-item-title-row">
                                        <strong>{{ $event['title'] }}</strong>
                                    </span>
                                    <span class="dash-access-item-tags">
                                        <span class="dash-access-code-pill">{{ \Carbon\Carbon::parse($event['date'])->timezone('Asia/Manila')->format('D, M j') }}</span>
                                        @if($upcomingStart !== '')
                                            <span class="dash-access-item-time">
                                                <i class="fa-regular fa-clock" aria-hidden="true"></i>{{ $upcomingStart }}{{ $upcomingEnd !== '' ? ' – '.$upcomingEnd : '' }}
                                            </span>
                                        @endif
                                    </span>
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @elseif(empty($calendarPeek['holiday']))
                <div class="dash-access-empty">
                    <span class="dash-access-empty-icon" aria-hidden="true">
                        <i class="fa-regular fa-calendar"></i>
                    </span>
                    <strong>Clear schedule</strong>
                    <span>No events scheduled for today.</span>
                    <button type="button" class="dash-access-empty-btn" @click.stop="$store.dcsCal.toggle()">
                        <i class="fa-regular fa-calendar-plus" aria-hidden="true"></i> Open calendar
                    </button>
                </div>
            @endif
        </div>
    </article>
</section>
