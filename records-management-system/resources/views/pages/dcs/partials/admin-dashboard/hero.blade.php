{{--
  DCS PARTIAL: Admin dashboard hero banner & live calendar trigger
  FILE: resources/views/pages/dcs/partials/admin-dashboard/hero.blade.php
--}}
@php
    $firstName = explode(' ', trim((string) ($userDisplayName ?? '')))[0] ?: 'User';
@endphp
<header class="dashboard-header dash-welcome-bar">
    <div class="welcome-text dash-welcome-copy">
        <p class="header-greeting">{{ !empty($roleName) ? $roleName : 'User' }}</p>
        <h1 class="page-title">
            Welcome, <span class="dash-welcome-name">{{ $firstName }}</span>
        </h1>
        <p class="dash-welcome-sub">Manage registrations, revisions, and office document control from one place.</p>
    </div>

    <button
        type="button"
        class="header-date dash-calendar-trigger dash-welcome-calendar"
        @click.stop="$store.dcsCal.toggle()"
        :aria-expanded="($store.dcsCal.open || false).toString()"
        :title="$store.dcsCal.open ? 'Hide calendar' : 'Open calendar'"
    >
        <i class="fa-regular fa-calendar" aria-hidden="true"></i>
        <span class="dash-calendar-trigger-text">
            <span class="dash-calendar-trigger-date" x-text="$store.dcsCal.headerDate || @js($headerDate)">{{ $headerDate }}</span>
            <span class="dash-calendar-trigger-time" x-text="$store.dcsCal.nowClock || @js($headerTime ?? '')" x-cloak>{{ $headerTime ?? '' }}</span>
        </span>
        <i
            class="fa-solid dash-calendar-chevron"
            :class="$store.dcsCal.open ? 'fa-xmark is-open' : 'fa-calendar-plus'"
            aria-hidden="true"
        ></i>
    </button>
</header>
