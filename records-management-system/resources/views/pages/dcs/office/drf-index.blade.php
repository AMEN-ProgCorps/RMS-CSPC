<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('My DRF — CSPC DCS')] class extends Component {
    public function mount(): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
    }

    public function with(): array
    {
        return [
            'rows' => OfficeIntakeHelper::listMyDrf(),
            'isLimited' => RegisterQueryHelper::isLimitedDcsUser(),
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-header">
            <div>
                <h1>My Document Request Forms</h1>
                <p>Create a DRF, print it, then submit the printed form to RFIO. Saved forms cannot be edited.</p>
            </div>
            <a href="{{ route('dcs.office.drf.create', absolute: false) }}" class="ofi-btn primary">
                <i class="fa-solid fa-plus"></i> New DRF
            </a>
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
                        <th>DRF No.</th>
                        <th>Date</th>
                        <th>Title</th>
                        <th style="width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>{{ $row->drf_no ?: '—' }}</td>
                            <td>{{ $row->drf_date ? \Carbon\Carbon::parse($row->drf_date)->format('M d, Y') : '—' }}</td>
                            <td>{{ $row->doc_title ?: '—' }}</td>
                            <td class="ofi-actions">
                                <a href="{{ route('dcs.office.drf.show', $row->id, absolute: false) }}" title="View"><i class="fa-solid fa-eye"></i></a>
                                <a href="{{ route('dcs.office.drf.print', $row->id, absolute: false) }}" target="_blank" title="Print"><i class="fa-solid fa-print"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="ofi-empty">No Document Request Forms yet. Create one to get started.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
