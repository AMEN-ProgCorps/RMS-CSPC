<?php

/**
 * DCS file: My DCN list. Same print lock as My DRF.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.dcs')] #[Title('My DCN — CSPC DCS')] class extends Component {
    use WithPagination;

    public function mount(): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();

        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            $this->redirect(route('dcs.requests.index', ['filter' => 'dcn'], absolute: false));
        }
    }

    public function with(): array
    {
        return [
            'rows' => OfficeIntakeHelper::paginateMyDcn(25, false),
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
                    <h1>Office Document Change Notices</h1>
                    <p>Review DCN submissions from offices. Open a form to view the official print template.</p>
                @else
                    <h1>My Document Change Notices</h1>
                    <p>Create a DCN and save it. Print stays locked until RFIO confirms the form and notifies you to print and sign.</p>
                @endif
            </div>
            @unless($isReviewer ?? false)
                <div class="ofi-header-actions">
                    <a href="{{ route('dcs.office.dcn.create', absolute: false) }}" class="ofi-btn primary">
                        <i class="fa-solid fa-plus"></i> New DCN
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
                <h2>{{ ($isReviewer ?? false) ? 'Office DCN' : 'My DCN' }}</h2>
                <span>{{ $totalRows }} {{ $totalRows === 1 ? 'notice' : 'notices' }}</span>
            </div>

            @if(! $hasRows)
                <div class="ofi-doc-empty ofi-list-empty" role="status">
                    <i class="fa-regular fa-file-pen" aria-hidden="true"></i>
                    <strong>{{ ($isReviewer ?? false) ? 'No office DCN submissions yet' : 'No Document Change Notices yet' }}</strong>
                    @unless($isReviewer ?? false)
                        <p>Start a DCN for a registered document. You can also create the matching DRF right after save.</p>
                        <div class="ofi-empty-actions">
                            <a href="{{ route('dcs.office.dcn.create', absolute: false) }}" class="ofi-btn primary">
                                <i class="fa-solid fa-plus"></i> New Document Change Notice
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
                                @if($isReviewer ?? false)
                                    <th>Office</th>
                                    <th>Submitted by</th>
                                @endif
                                <th>Status</th>
                                <th class="ofi-nowrap">Date</th>
                                <th class="ofi-nowrap">Date Created</th>
                                <th>Originator</th>
                                <th class="ofi-actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr>
                                    <td class="ofi-title-cell">
                                        <a href="{{ route('dcs.office.dcn.show', $row->id, absolute: false) }}" class="ofi-title-link">
                                            {{ ($row->document_title ?? '') !== '' ? $row->document_title : 'Untitled DCN' }}
                                        </a>
                                    </td>
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
                                    <td class="ofi-nowrap">{{ $row->dcn_date ? \App\Helpers\RegisterQueryHelper::formatRdpPeriodDate($row->dcn_date) : '—' }}</td>
                                    <td class="ofi-nowrap">{{ $row->created_at ? \Carbon\Carbon::parse($row->created_at)->timezone('Asia/Manila')->format('M d, Y g:i A') : '—' }}</td>
                                    <td><x-dcs.originator-lines :name="$row->originator_name" /></td>
                                    <td class="ofi-actions">
                                        <a href="{{ route('dcs.office.dcn.show', $row->id, absolute: false) }}" class="ofi-icon-action" title="View">
                                            <i class="fa-solid fa-eye"></i><span>View</span>
                                        </a>
                                        @unless($isReviewer ?? false)
                                            @if(! empty($row->linked_drf_id))
                                                <a href="{{ route('dcs.office.drf.show', $row->linked_drf_id, absolute: false) }}" class="ofi-icon-action" title="View linked DRF">
                                                    <i class="fa-solid fa-file-lines"></i><span>DRF</span>
                                                </a>
                                            @elseif(! empty($row->expects_drf))
                                                <a href="{{ route('dcs.office.drf.create', ['from_dcn' => $row->id], absolute: false) }}" class="ofi-icon-action is-accent" title="Create DRF">
                                                    <i class="fa-solid fa-file-circle-plus"></i><span>Create DRF</span>
                                                </a>
                                            @endif
                                        @endunless
                                        @if(($isReviewer ?? false) || ! empty($row->rfio_print_notified_at))
                                            <a href="{{ route('dcs.office.dcn.print', $row->id, absolute: false) }}" class="ofi-icon-action" target="_blank" title="{{ ($isReviewer ?? false) ? 'Open print form' : 'Print' }}">
                                                <i class="fa-solid fa-print"></i><span>Print</span>
                                            </a>
                                        @else
                                            <span class="ofi-print-locked ofi-icon-action is-locked" data-print-locked data-intake-type="dcn" data-intake-id="{{ $row->id }}" data-print-url="{{ route('dcs.office.dcn.print', $row->id, false) }}" title="Print stays locked until RFIO notifies you to print and sign.">
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
