<?php

use App\Helpers\OfficeIntakeHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('View DCN — CSPC DCS')] class extends Component {
    public int $id;

    public function mount($id): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
        $this->id = (int) $id;
        $dcn = OfficeIntakeHelper::findOfficeDcn($this->id);
        abort_unless($dcn, 404);
        OfficeIntakeHelper::assertOwnsDcn($dcn);
    }

    public function with(): array
    {
        $dcn = OfficeIntakeHelper::findOfficeDcn($this->id);
        abort_unless($dcn, 404);

        return [
            'dcn' => $dcn,
            'revisions' => OfficeIntakeHelper::dcnRevisions($this->id),
            'sourceOffices' => OfficeIntakeHelper::dcnSourceOffices($this->id),
            'immutableMessage' => OfficeIntakeHelper::IMMUTABLE_MESSAGE,
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner ofi-inner-wide">
        <div class="ofi-header">
            <div>
                <h1>Document Change Notice</h1>
                <p>DCN No. {{ $dcn->dcn_no }}</p>
            </div>
            <div class="ofi-header-actions">
                <a href="{{ route('dcs.office.dcn.index', absolute: false) }}" class="ofi-btn">Back</a>
                <a href="{{ route('dcs.office.dcn.print', $dcn->id, absolute: false) }}" target="_blank" class="ofi-btn primary"><i class="fa-solid fa-print"></i> Print</a>
            </div>
        </div>

        <div class="ofi-lock-banner">
            <i class="fa-solid fa-lock"></i>
            <span>{{ $immutableMessage }}</span>
        </div>

        @if(session('success'))
            <div class="ofi-alert ok">{{ session('success') }}</div>
        @endif

        <div class="ofi-card ofi-readonly">
            <div class="ofi-field">
                <label>Documents for Revision</label>
                <div class="ofi-table-wrap">
                    <table class="ofi-table">
                        <thead>
                            <tr>
                                <th>Document No.</th>
                                <th>Title</th>
                                <th>Effectivity</th>
                                <th>Rev No.</th>
                                <th>Brief Purpose</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($revisions as $rev)
                                <tr>
                                    <td>{{ $rev->document_no ?: '—' }}</td>
                                    <td>{{ $rev->title ?: '—' }}</td>
                                    <td>{{ $rev->effectivity_date ? \Carbon\Carbon::parse($rev->effectivity_date)->format('M d, Y') : '—' }}</td>
                                    <td>{{ $rev->revision_no ?? '—' }}</td>
                                    <td>{{ $rev->brief_purpose ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="ofi-empty">No revision rows</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="ofi-field"><label>Justification</label><div class="ofi-value ofi-pre">{{ $dcn->brief_purpose ?: '—' }}</div></div>
            <div class="ofi-grid-3">
                <div class="ofi-field"><label>DCN No.</label><div class="ofi-value">{{ $dcn->dcn_no }}</div></div>
                <div class="ofi-field"><label>DCN Date</label><div class="ofi-value">{{ $dcn->dcn_date ? \Carbon\Carbon::parse($dcn->dcn_date)->format('M d, Y') : '—' }}</div></div>
                <div class="ofi-field">
                    <label>DCN Receipt</label>
                    <div class="ofi-value">
                        {{ $dcn->dcn_receipt_date ? \Carbon\Carbon::parse($dcn->dcn_receipt_date)->format('M d, Y') : '—' }}
                        @if($dcn->dcn_receipt_time)
                            {{ \Illuminate\Support\Str::of($dcn->dcn_receipt_time)->substr(0, 5) }}
                        @endif
                    </div>
                </div>
            </div>
            <div class="ofi-field">
                <label>Source Unit</label>
                @if($sourceOffices->isNotEmpty())
                    <ul class="ofi-list">@foreach($sourceOffices as $o)<li>{{ $o->office_name ?: '—' }}</li>@endforeach</ul>
                @else
                    <div class="ofi-value">—</div>
                @endif
            </div>
            <div class="ofi-field">
                <label>Scanned DCN</label>
                <div class="ofi-value">
                    @if($dcn->scanned_dcn)
                        <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($dcn->scanned_dcn) }}" target="_blank">View PDF</a>
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
