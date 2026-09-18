<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Illuminate\Http\RedirectResponse;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('My DCN — CSPC DCS')] class extends Component {
    public function mount(): ?RedirectResponse
    {
        OfficeIntakeHelper::assertCanAccessIntake();

        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            return new RedirectResponse(
                route('dcs.requests.index', ['filter' => 'dcn'], absolute: false)
            );
        }

        return null;
    }

    public function with(): array
    {
        return [
            'rows' => OfficeIntakeHelper::listMyDcn(),
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
                    <h1>Office Document Change Notices</h1>
                    <p>Review DCN submissions from offices. Open a form to view the official print template.</p>
                @else
                    <h1>My Document Change Notices</h1>
                    <p>Create a DCN, print it, then submit the printed form to RFIO. Saved forms cannot be edited.</p>
                @endif
            </div>
            @unless($isReviewer ?? false)
                <a href="{{ route('dcs.office.dcn.create', absolute: false) }}" class="ofi-btn primary">
                    <i class="fa-solid fa-plus"></i> New DCN
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
                        @if($isReviewer ?? false)
                            <th>Office</th>
                            <th>Submitted by</th>
                        @endif
                        <th>Status</th>
                        <th>Date</th>
                        <th>Date Created</th>
                        <th>Originator</th>
                        <th style="width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>{{ ($row->document_title ?? '') !== '' ? $row->document_title : '—' }}</td>
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
                            <td>{{ $row->dcn_date ? \Carbon\Carbon::parse($row->dcn_date)->format('M d, Y') : '—' }}</td>
                            <td>{{ $row->created_at ? \Carbon\Carbon::parse($row->created_at)->timezone('Asia/Manila')->format('M d, Y g:i A') : '—' }}</td>
                            <td>{{ $row->originator_name ?: '—' }}</td>
                            <td class="ofi-actions">
                                <a href="{{ route('dcs.office.dcn.show', $row->id, absolute: false) }}" title="View"><i class="fa-solid fa-eye"></i></a>
                                <a href="{{ route('dcs.office.dcn.print', $row->id, absolute: false) }}" target="_blank" title="{{ ($isReviewer ?? false) ? 'Open print form' : 'Print' }}"><i class="fa-solid fa-print"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ ($isReviewer ?? false) ? 8 : 6 }}" class="ofi-empty">
                                {{ ($isReviewer ?? false) ? 'No office DCN submissions yet.' : 'No Document Change Notices yet. Create one to get started.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
