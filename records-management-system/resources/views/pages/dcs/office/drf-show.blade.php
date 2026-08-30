<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
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

        $catalog = collect(RegisterQueryHelper::jsCatalog()['offices'] ?? []);
        $distributeOffices = collect(OfficeIntakeHelper::decodeDistributeTo($drf->distribute_to ?? null))
            ->map(function ($stored) use ($catalog) {
                $stored = trim((string) $stored);
                $match = $catalog->first(function ($o) use ($stored) {
                    $code = trim((string) ($o['office_code'] ?? ''));
                    $name = trim((string) ($o['office_name'] ?? ''));

                    return ($code !== '' && strcasecmp($code, $stored) === 0)
                        || ($name !== '' && strcasecmp($name, $stored) === 0);
                });

                return [
                    'code' => $match ? trim((string) ($match['office_code'] ?? '')) : $stored,
                    'name' => $match ? trim((string) ($match['office_name'] ?? '')) : '',
                ];
            })
            ->filter(fn ($o) => $o['code'] !== '' || $o['name'] !== '')
            ->values()
            ->all();

        return [
            'drf' => $drf,
            'distributeOffices' => $distributeOffices,
            'immutableMessage' => OfficeIntakeHelper::IMMUTABLE_MESSAGE,
        ];
    }
}; ?>

@php
    $originator = trim((string) ($drf->originator_name ?? ''))
        ?: trim((string) ($drf->prepared_by_name ?? ''));
    $kind = strtolower(trim((string) ($drf->doc_type_kind ?? '')));
    $isInternal = $kind === 'internal';
    $isExternal = $kind === 'external';
    $drfDate = $drf->drf_date
        ? \Carbon\Carbon::parse($drf->drf_date)->format('M d, Y')
        : '—';
@endphp

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-show-toolbar">
            <a href="{{ route('dcs.office.drf.index', absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> Back to list
            </a>
            <a href="{{ route('dcs.office.drf.print', $drf->id, absolute: false) }}" target="_blank" class="reg-btn reg-btn-save">
                <i class="fa-solid fa-print"></i> Print form
            </a>
        </div>

        @if(session('success'))
            <div class="ofi-alert ok">{{ session('success') }}</div>
        @endif

        <div class="ofi-lock-banner">
            <i class="fa-solid fa-lock"></i>
            <span>{{ $immutableMessage }}</span>
        </div>

        <section class="reg-card ofi-show-card">
            <div class="reg-card-header">
                <span>Document Request Form</span>
                <span class="ofi-form-code-badge">CSPC-F-DCC-06</span>
            </div>
            <div class="reg-card-body ofi-drf-form ofi-show-form">
                <div class="reg-grid-2">
                    <div class="reg-field">
                        <label>Request #</label>
                        <div class="ofi-show-value">{{ $drf->drf_no ?: '—' }}</div>
                    </div>
                    <div class="reg-field">
                        <label>Date</label>
                        <div class="ofi-show-value">{{ $drfDate }}</div>
                    </div>
                </div>

                <div class="reg-field">
                    <label>Originator</label>
                    <div class="ofi-show-value">{{ $originator ?: '—' }}</div>
                </div>

                <div class="reg-field">
                    <label>Document Title</label>
                    <div class="ofi-show-value">{{ $drf->doc_title ?: '—' }}</div>
                </div>

                <div class="reg-field">
                    <label>Type of document</label>
                    <div class="ofi-doc-type-badges" aria-label="Type of document">
                        <span class="ofi-doc-type-badge @if($isInternal) is-active @endif">Internal</span>
                        <span class="ofi-doc-type-badge @if($isExternal) is-active @endif">External</span>
                    </div>
                </div>

                <div class="reg-field">
                    <label>Description/reason for request (define in detail)</label>
                    <div class="ofi-show-value is-multiline">{{ $drf->description_reason ?: '—' }}</div>
                </div>

                <div class="reg-field">
                    <label>Distribute document to (department/position)</label>
                    @if(!empty($distributeOffices))
                        <div class="ofi-show-chips">
                            @foreach($distributeOffices as $office)
                                <span class="ofi-show-chip" title="{{ $office['name'] ?: $office['code'] }}">
                                    @if($office['code'] !== '')
                                        <span class="ofi-show-chip-code">{{ $office['code'] }}</span>
                                    @endif
                                    @if($office['name'] !== '')
                                        <span class="ofi-show-chip-name">{{ $office['name'] }}</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    @else
                        <div class="ofi-show-value is-empty">—</div>
                    @endif
                </div>
            </div>
        </section>
    </div>
</div>
