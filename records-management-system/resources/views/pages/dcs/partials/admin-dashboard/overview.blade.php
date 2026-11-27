{{--
  DCS PARTIAL: Admin dashboard revision summary chips & document type stats
  FILE: resources/views/pages/dcs/partials/admin-dashboard/overview.blade.php
--}}
@php
    $revisionChips = [
        [
            'label' => 'Total',
            'count' => (int) ($stats['totalDocuments'] ?? 0),
            'class' => '',
            'title' => 'Every registered revision on the masterlist',
            'params' => [],
        ],
        [
            'label' => 'Latest',
            'count' => (int) ($stats['latestCount'] ?? 0),
            'class' => 'is-latest',
            'title' => 'Current controlled copies only',
            'params' => ['revision' => 'latest'],
        ],
        [
            'label' => 'Superseded',
            'count' => (int) ($stats['supersededCount'] ?? 0),
            'class' => 'is-superseded',
            'title' => 'Replaced by a newer revision',
            'params' => ['revision' => 'superseded'],
        ],
        [
            'label' => 'Obsolete',
            'count' => (int) ($stats['obsoleteCount'] ?? 0),
            'class' => 'is-obsolete',
            'title' => 'Withdrawn from use',
            'params' => ['revision' => 'obsolete'],
        ],
    ];

    $statCards = [
        ['id' => 'internalCount', 'typeKey' => 'internal_docs', 'label' => 'Internal Documents', 'icon' => 'fa-file-shield', 'accent' => 'blue'],
        ['id' => 'internalFormsCount', 'typeKey' => 'internal_forms', 'label' => 'Internal Forms', 'icon' => 'fa-file-contract', 'accent' => 'slate'],
        ['id' => 'externalCount', 'typeKey' => 'external_docs', 'label' => 'External Documents', 'icon' => 'fa-file-export', 'accent' => 'navy'],
        ['id' => 'formsCount', 'typeKey' => 'forms', 'label' => 'Forms', 'icon' => 'fa-file-signature', 'accent' => 'red'],
        ['id' => 'logbooksCount', 'typeKey' => 'logbooks', 'label' => 'Logbooks', 'icon' => 'fa-book', 'accent' => 'green'],
    ];
@endphp

<section class="dash-overview-section" aria-label="Document overview">
    <div class="dash-queue-bar" role="group" aria-label="Document revision queues">
        @foreach($revisionChips as $chip)
            @if(!empty($canDatabase))
                <a
                    href="{{ route('dcs.database.index', $chip['params'], absolute: false) }}"
                    class="dash-queue-chip {{ $chip['class'] }}"
                    title="{{ $chip['title'] }}"
                >
                    <span class="dash-queue-dot" aria-hidden="true"></span>
                    <span>{{ $chip['label'] }}</span>
                    <strong>{{ number_format($chip['count']) }}</strong>
                </a>
            @else
                <div class="dash-queue-chip {{ $chip['class'] }}" title="{{ $chip['title'] }}">
                    <span class="dash-queue-dot" aria-hidden="true"></span>
                    <span>{{ $chip['label'] }}</span>
                    <strong>{{ number_format($chip['count']) }}</strong>
                </div>
            @endif
        @endforeach
    </div>

    <div class="stats-row">
        @foreach($statCards as $box)
            @php $count = (int) ($stats[$box['id']] ?? 0); @endphp
            @if(!empty($canDatabase))
                <a
                    href="{{ route('dcs.database.index', ['type' => $typeIds[$box['typeKey']] ?? null, 'revision' => 'latest'], absolute: false) }}"
                    class="stat-box"
                    data-accent="{{ $box['accent'] }}"
                >
            @else
                <div class="stat-box" data-accent="{{ $box['accent'] }}">
            @endif
                <div class="stat-icon-wrap"><i class="fa-solid {{ $box['icon'] }}" aria-hidden="true"></i></div>
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
    </div>
</section>
