{{--
  DCS PAGE: Office My DRF list
  FILE: resources/views/pages/dcs/office/drf-index.blade.php
  DEBUG: OfficeIntakeHelper::listMyDrf / paginateMyDrf
--}}
<?php

/**
 * DCS file: My DRF list for the signed-in office. Print stays locked until that row's rfio_print_notified_at is set. The list polls print-ready and swaps the lock for a link.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    /**
     * Initializes component state, authorization gates, and default configurations.
     * @return void
     */
    public function mount(): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();

        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            $this->redirect(route('dcs.requests.index', ['filter' => 'drf'], absolute: false));
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Supplies view data, query collections, and metrics to the Blade template.
     * @return array
     */
    public function with(): array
    {
        return [
            'rows' => OfficeIntakeHelper::paginateMyDrf(25, false, $this->search),
            'search' => $this->search,
            'isLimited' => RegisterQueryHelper::isLimitedDcsUser(),
            'isReviewer' => false,
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner ofi-inner-wide">
        <div class="ofi-header">
            <div>
                @if($isReviewer ?? false)
                    <h1>Office Document Request Forms</h1>
                    <p>Review DRF submissions from offices. Open a form to view the official print template.</p>
                @else
                    <h1>My Document Request Forms</h1>
                    <p>Create a DRF and save it. Print stays locked until RFIU confirms the form and notifies you to print and sign.</p>
                @endif
            </div>
            @unless($isReviewer ?? false)
                <div class="ofi-header-actions">
                    <a href="{{ route('dcs.office.drf.create', absolute: false) }}" class="ofi-btn primary">
                        <i class="fa-solid fa-plus"></i> New DRF
                    </a>
                    <a href="{{ route('dcs.office.drf.create', ['mode' => 'recurring'], absolute: false) }}" class="ofi-btn" title="Revise a registered document with a recurring DRF">
                        <i class="fa-solid fa-rotate"></i> Recurring DRF
                    </a>
                </div>
            @endunless
        </div>

        @if(session('success'))
            <div class="ofi-alert ok">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="ofi-alert err">{{ session('error') }}</div>
        @endif

        @php
            $totalRows = method_exists($rows, 'total') ? (int) $rows->total() : (is_countable($rows) ? count($rows) : 0);
            $hasRows = $totalRows > 0 || (method_exists($rows, 'count') ? $rows->count() > 0 : false);
        @endphp

        @include('pages.dcs.office.partials.ofi-list-search', [
            'search' => $search ?? '',
            'placeholder' => 'Search by title, DRF no., document no., or originator…',
        ])

        <div class="ofi-card ofi-list-card" style="position:relative;" wire:loading.class="is-loading">
            <div class="dcs-loading-overlay" wire:loading.flex wire:target="search,previousPage,nextPage,gotoPage">
                <div class="dcs-loading-spinner" aria-hidden="true"></div>
                <h4>Loading DRF…</h4>
                <p>Fetching your document request forms.</p>
            </div>
            <div class="ofi-doc-panel-head">
                <h2>{{ ($isReviewer ?? false) ? 'Office DRF' : 'My DRF' }}</h2>
                <span>{{ $totalRows }} {{ $totalRows === 1 ? 'form' : 'forms' }}</span>
            </div>

            @if(! $hasRows)
                <div class="ofi-doc-empty ofi-list-empty" role="status">
                    <i class="fa-regular fa-file-lines" aria-hidden="true"></i>
                    @if(trim((string) ($search ?? '')) !== '')
                        <strong>No forms match “{{ $search }}”</strong>
                        <p>Try another title, DRF number, or originator, or clear the search.</p>
                    @else
                        <strong>{{ ($isReviewer ?? false) ? 'No office DRF submissions yet' : 'No Document Request Forms yet' }}</strong>
                        @unless($isReviewer ?? false)
                            <p>Create a DRF for RFIU, or continue from a saved DCN that still needs its request form.</p>
                            <div class="ofi-empty-actions">
                                <a href="{{ route('dcs.office.drf.create', absolute: false) }}" class="ofi-btn primary">
                                    <i class="fa-solid fa-plus"></i> New Document Request Form
                                </a>
                                <a href="{{ route('dcs.office.drf.create', ['mode' => 'recurring'], absolute: false) }}" class="ofi-btn">
                                    <i class="fa-solid fa-rotate"></i> Recurring DRF
                                </a>
                            </div>
                        @endunless
                    @endif
                </div>
            @else
                <div class="ofi-table-wrap ofi-list-table-wrap">
                    <table class="ofi-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Originator</th>
                                @if($isReviewer ?? false)
                                    <th>Office</th>
                                    <th>Submitted by</th>
                                @endif
                                <th>Status</th>
                                <th class="ofi-nowrap">Date</th>
                                <th class="ofi-nowrap">Date Created</th>
                                <th class="ofi-actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr>
                                    <td class="ofi-title-cell">
                                        <a href="{{ route('dcs.office.drf.show', $row->id, absolute: false) }}" class="ofi-title-link">
                                            {{ $row->doc_title ?: 'Untitled DRF' }}
                                        </a>
                                    </td>
                                    <td class="ofi-nowrap"><x-dcs.originator-lines :name="data_get($row, 'originator_name', '')" /></td>
                                    @if($isReviewer ?? false)
                                        <td>{{ $row->submitting_office ?? '—' }}</td>
                                        <td>{{ $row->submitter_name ?? '—' }}</td>
                                    @endif
                                    <td>
                                        @if(!empty($row->is_registered))
                                            <span class="ofi-status-pill is-registered">Registered</span>
                                        @else
                                            <span class="ofi-status-pill is-pending">Submitted</span>
                                        @endif
                                    </td>
                                    <td class="ofi-nowrap">{{ $row->drf_date ? \App\Helpers\RegisterQueryHelper::formatRdpPeriodDate($row->drf_date) : '—' }}</td>
                                    <td class="ofi-nowrap">{{ $row->created_at ? \Carbon\Carbon::parse($row->created_at)->timezone('Asia/Manila')->format('M d, Y g:i A') : '—' }}</td>
                                    <td class="ofi-actions">
                                        <a href="{{ route('dcs.office.drf.show', $row->id, absolute: false) }}" class="ofi-icon-action" title="View">
                                            <i class="fa-solid fa-eye"></i><span>View</span>
                                        </a>
                                        @if(($isReviewer ?? false) || ! empty($row->rfio_print_notified_at))
                                            <a href="{{ route('dcs.office.drf.print', $row->id, absolute: false) }}" class="ofi-icon-action" target="_blank" title="{{ ($isReviewer ?? false) ? 'Open print form' : 'Print' }}">
                                                <i class="fa-solid fa-print"></i><span>Print</span>
                                            </a>
                                        @else
                                            <span class="ofi-print-locked ofi-icon-action is-locked" data-print-locked data-intake-type="drf" data-intake-id="{{ $row->id }}" data-print-url="{{ route('dcs.office.drf.print', $row->id, false) }}" title="Print stays locked until RFIU notifies you to print and sign.">
                                                <i class="fa-solid fa-print"></i><span>Locked</span>
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @include('pages.dcs.partials.list-pagination', [
                    'from' => (int) $rows->firstItem(),
                    'to' => (int) $rows->lastItem(),
                    'total' => (int) $rows->total(),
                    'page' => (int) $rows->currentPage(),
                    'lastPage' => (int) $rows->lastPage(),
                    'goToMethod' => 'gotoPage',
                ])
            @endif
        </div>
    </div>
</div>
@push('scripts')
    @include('pages.dcs.office.partials.print-unlock-list')
@endpush
