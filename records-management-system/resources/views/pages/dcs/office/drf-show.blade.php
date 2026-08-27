<?php

use App\Helpers\OfficeIntakeHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('View DRF — CSPC DCS')] class extends Component {
    public int $id;

    public function mount($id): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
        $this->id = (int) $id;
        $drf = OfficeIntakeHelper::findOfficeDrf($this->id);
        abort_unless($drf, 404);
        OfficeIntakeHelper::assertOwnsDrf($drf);
    }

    public function with(): array
    {
        $drf = OfficeIntakeHelper::findOfficeDrf($this->id);
        abort_unless($drf, 404);

        return [
            'drf' => $drf,
            'sourceOffices' => OfficeIntakeHelper::drfSourceOffices($this->id),
            'immutableMessage' => OfficeIntakeHelper::IMMUTABLE_MESSAGE,
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-header">
            <div>
                <h1>Document Request Form</h1>
                <p>DRF No. {{ $drf->drf_no }}</p>
            </div>
            <div class="ofi-header-actions">
                <a href="{{ route('dcs.office.drf.index', absolute: false) }}" class="ofi-btn">Back</a>
                <a href="{{ route('dcs.office.drf.print', $drf->id, absolute: false) }}" target="_blank" class="ofi-btn primary"><i class="fa-solid fa-print"></i> Print</a>
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
            <div class="ofi-grid-3">
                <div class="ofi-field"><label>DRF No.</label><div class="ofi-value">{{ $drf->drf_no }}</div></div>
                <div class="ofi-field"><label>DRF Date</label><div class="ofi-value">{{ $drf->drf_date ? \Carbon\Carbon::parse($drf->drf_date)->format('M d, Y') : '—' }}</div></div>
                <div class="ofi-field">
                    <label>Date Receipt</label>
                    <div class="ofi-value">
                        {{ $drf->drf_receipt_date ? \Carbon\Carbon::parse($drf->drf_receipt_date)->format('M d, Y') : '—' }}
                        @if($drf->drf_receipt_time)
                            {{ \Illuminate\Support\Str::of($drf->drf_receipt_time)->substr(0, 5) }}
                        @endif
                    </div>
                </div>
            </div>
            <div class="ofi-field"><label>Document Title</label><div class="ofi-value">{{ $drf->doc_title ?: '—' }}</div></div>
            <div class="ofi-field">
                <label>Source Unit</label>
                @if($sourceOffices->isNotEmpty())
                    <ul class="ofi-list">@foreach($sourceOffices as $o)<li>{{ $o->office_name ?: '—' }}</li>@endforeach</ul>
                @else
                    <div class="ofi-value">—</div>
                @endif
            </div>
            <div class="ofi-field">
                <label>Scanned DRF</label>
                <div class="ofi-value">
                    @if($drf->scanned_drf)
                        <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($drf->scanned_drf) }}" target="_blank">View PDF</a>
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
