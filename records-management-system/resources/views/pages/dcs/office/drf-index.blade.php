<?php

/**
 * DCS file: My DRF list for the signed-in office. Print stays locked until that row's rfio_print_notified_at is set. The list polls print-ready and swaps the lock for a link.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('My DRF — CSPC DCS')] class extends Component {
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
            'rows' => OfficeIntakeHelper::listMyDrf(),
            'isLimited' => RegisterQueryHelper::isLimitedDcsUser(),
            'isReviewer' => false,
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner">
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
                <a href="{{ route('dcs.office.drf.create', absolute: false) }}" class="ofi-btn primary">
                    <i class="fa-solid fa-plus"></i> New DRF
                </a>
            @endunless
        </div>

        @if(session('success'))
            <div class="ofi-alert ok">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="ofi-alert err">{{ session('error') }}</div>
        @endif

        <div class="ofi-card">
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
                        <th style="width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>{{ $row->doc_title ?: '—' }}</td>
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
                            <td class="ofi-nowrap">{{ $row->drf_date ? \Carbon\Carbon::parse($row->drf_date)->format('M d, Y') : '—' }}</td>
                            <td class="ofi-nowrap">{{ $row->created_at ? \Carbon\Carbon::parse($row->created_at)->timezone('Asia/Manila')->format('M d, Y g:i A') : '—' }}</td>
                            <td class="ofi-actions">
                                <a href="{{ route('dcs.office.drf.show', $row->id, absolute: false) }}" title="View"><i class="fa-solid fa-eye"></i></a>
                                @if(($isReviewer ?? false) || ! empty($row->rfio_print_notified_at))
                                    <a href="{{ route('dcs.office.drf.print', $row->id, absolute: false) }}" target="_blank" title="{{ ($isReviewer ?? false) ? 'Open print form' : 'Print' }}"><i class="fa-solid fa-print"></i></a>
                                @else
                                    <span class="ofi-print-locked" data-print-locked data-intake-type="drf" data-intake-id="{{ $row->id }}" data-print-url="{{ route('dcs.office.drf.print', $row->id, false) }}" title="Print stays locked until RFIO notifies you to print and sign."><i class="fa-solid fa-print"></i></span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ ($isReviewer ?? false) ? 8 : 6 }}" class="ofi-empty">
                                {{ ($isReviewer ?? false) ? 'No office DRF submissions yet.' : 'No Document Request Forms yet.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@include('pages.dcs.office.partials.print-unlock-list')
