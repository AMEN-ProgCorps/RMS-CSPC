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

        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            $this->redirect(route('dcs.requests.show', ['type' => 'drf', 'id' => $this->id], absolute: false));

            return;
        }

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
            'isIntakeReviewer' => RegisterQueryHelper::canBrowseAllOfficeIntake(),
            'canEdit' => OfficeIntakeHelper::canOfficeEditIntake('drf', $this->id),
            'editReason' => trim((string) ($drf->edit_unlock_reason ?? '')),
            'isRegistered' => OfficeIntakeHelper::isIntakeRegistered('drf', $this->id),
        ];
    }
}; ?>

@php
    $originator = trim((string) ($drf->originator_name ?? ''));
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
            <a href="{{ ($isIntakeReviewer ?? false) ? route('dcs', absolute: false) : route('dcs.office.drf.index', absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> {{ ($isIntakeReviewer ?? false) ? 'Back to DCS' : 'Back to list' }}
            </a>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                @if($canEdit ?? false)
                    <a href="{{ route('dcs.office.drf.edit', $drf->id, absolute: false) }}" class="reg-btn reg-btn-save">
                        <i class="fa-solid fa-pen"></i> Edit form
                    </a>
                @endif
                <a href="{{ route('dcs.office.drf.print', $drf->id, absolute: false) }}" target="_blank" class="reg-btn reg-btn-save">
                    <i class="fa-solid fa-print"></i> Print form
                </a>
            </div>
        </div>

        @if(session('success'))
            <div class="ofi-alert ok">{{ session('success') }}</div>
        @endif

        @if(($canEdit ?? false) && ($editReason ?? '') !== '')
            <div class="ofi-alert err">
                <strong>RFIO asked for corrections:</strong> {{ $editReason }}
            </div>
        @endif

        <div class="ofi-lock-banner">
            @if($isRegistered ?? false)
                <i class="fa-solid fa-circle-check"></i>
                <span>This document has been registered / controlled by RFIO.</span>
            @elseif($canEdit ?? false)
                <i class="fa-solid fa-unlock"></i>
                <span>RFIO enabled editing so you can correct and resubmit this form.</span>
            @else
                <i class="fa-solid fa-lock"></i>
                <span>{{ $immutableMessage }}</span>
            @endif
        </div>

        <section class="reg-card ofi-show-card">
            <div class="reg-card-header">
                <span>Document Request Form</span>
                <span class="ofi-form-code-badge">CSPC-F-DCC-06</span>
            </div>
            <div class="reg-card-body ofi-drf-form ofi-show-form">
                <div class="reg-grid-2-1">
                    <div class="reg-field">
                        <label>Originator</label>
                        <div class="ofi-show-value">{{ $originator ?: '—' }}</div>
                    </div>
                    <div class="reg-field">
                        <label>Date</label>
                        <div class="ofi-show-value">{{ $drfDate }}</div>
                    </div>
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
                    <label class="ofi-distribute-label">
                        <span>Distribute document to (department/position)</span>
                        <span class="ofi-total-offices">
                            total offices: <strong>{{ count($distributeOffices) }}</strong>
                        </span>
                    </label>
                    <p class="ofi-field-hint" style="margin:0 0 8px;font-size:0.82rem;color:#64748b;line-height:1.4;">
                        All offices below are for <strong>distribution</strong> of this document.
                    </p>
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

                <div class="ofi-sig-block ofi-sig-block--show">
                    <p class="ofi-sig-heading">Signatories</p>
                    <div class="ofi-sig-group">
                        <p class="ofi-sig-label">Prepared by</p>
                        <div class="reg-grid-2">
                            <div class="reg-field">
                                <label>Name</label>
                                <div class="ofi-show-value">{{ trim((string) data_get($drf, 'prepared_by_name', '')) ?: '—' }}</div>
                            </div>
                            <div class="reg-field">
                                <label>Designation</label>
                                <div class="ofi-show-value">{{ trim((string) data_get($drf, 'prepared_by_designation', '')) ?: '—' }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="ofi-sig-group">
                        <p class="ofi-sig-label">Reviewed by</p>
                        <div class="reg-grid-2">
                            <div class="reg-field">
                                <label>Name</label>
                                <div class="ofi-show-value">{{ trim((string) data_get($drf, 'reviewed_by_name', '')) ?: '—' }}</div>
                            </div>
                            <div class="reg-field">
                                <label>Designation</label>
                                <div class="ofi-show-value">{{ trim((string) data_get($drf, 'reviewed_by_designation', '')) ?: '—' }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="ofi-sig-group">
                        <p class="ofi-sig-label">Approved by</p>
                        <div class="reg-grid-2">
                            <div class="reg-field">
                                <label>Name</label>
                                <div class="ofi-show-value">{{ trim((string) data_get($drf, 'approved_by_name', '')) ?: '—' }}</div>
                            </div>
                            <div class="reg-field">
                                <label>Designation</label>
                                <div class="ofi-show-value">{{ trim((string) data_get($drf, 'approved_by_designation', '')) ?: '—' }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
