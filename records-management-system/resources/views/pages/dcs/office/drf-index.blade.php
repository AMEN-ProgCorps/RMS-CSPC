<?php

/**
 * DCS file: My DRF list for the signed-in office. Print stays locked until that row's rfio_print_notified_at is set. The list polls print-ready and swaps the lock for a link.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    use WithPagination;

    public function mount(): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();

        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            $this->redirect(route('dcs.requests.index', ['filter' => 'drf'], absolute: false));
        }
    }

    public function with(): array
    {
        return [
            'rows' => OfficeIntakeHelper::paginateMyDrf(25, false),
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
                    <p>Create a DRF and save it. Print stays locked until RFIO confirms the form and notifies you to print and sign.</p>
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

        <div class="ofi-card ofi-list-card">
            <div class="ofi-doc-panel-head">
                <h2>{{ ($isReviewer ?? false) ? 'Office DRF' : 'My DRF' }}</h2>
                <span>{{ $totalRows }} {{ $totalRows === 1 ? 'form' : 'forms' }}</span>
            </div>

            @if(! $hasRows)
                <div class="ofi-doc-empty ofi-list-empty" role="status">
                    <i class="fa-regular fa-file-lines" aria-hidden="true"></i>
                    <strong>{{ ($isReviewer ?? false) ? 'No office DRF submissions yet' : 'No Document Request Forms yet' }}</strong>
                    @unless($isReviewer ?? false)
                        <p>Create a DRF for RFIO, or continue from a saved DCN that still needs its request form.</p>
                        <div class="ofi-empty-actions">
                            <a href="{{ route('dcs.office.drf.create', absolute: false) }}" class="ofi-btn primary">
                                <i class="fa-solid fa-plus"></i> New Document Request Form
                            </a>
                            <a href="{{ route('dcs.office.drf.create', ['mode' => 'recurring'], absolute: false) }}" class="ofi-btn">
                                <i class="fa-solid fa-rotate"></i> Recurring DRF
                            </a>
                        </div>
                    @endunless
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
                                    <td class="ofi-nowrap"><x-dcs.originator-lines :name="$row->originator_name" /></td>
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
                                            <span class="ofi-print-locked ofi-icon-action is-locked" data-print-locked data-intake-type="drf" data-intake-id="{{ $row->id }}" data-print-url="{{ route('dcs.office.drf.print', $row->id, false) }}" title="Print stays locked until RFIO notifies you to print and sign.">
                                                <i class="fa-solid fa-print"></i><span>Locked</span>
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if(method_exists($rows, 'hasPages') && $rows->hasPages())
                    <div class="ofi-pagination">
                        <span class="ofi-pagination-meta">
                            Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }}
                        </span>
                        <div class="ofi-pagination-controls">
                            <button type="button" class="ofi-btn" wire:click="previousPage" @disabled($rows->onFirstPage())>Previous</button>
                            <span class="ofi-pagination-page">Page {{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
                            <button type="button" class="ofi-btn" wire:click="nextPage" @disabled(! $rows->hasMorePages())>Next</button>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
@include('pages.dcs.office.partials.print-unlock-list')
