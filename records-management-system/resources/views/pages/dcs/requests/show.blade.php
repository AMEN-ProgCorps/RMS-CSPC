<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Illuminate\Http\RedirectResponse;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Review Request — CSPC DCS')] class extends Component {
    public string $type;
    public int $id;

    public function mount(string $type, $id): ?RedirectResponse
    {
        abort_unless(RegisterQueryHelper::canBrowseAllOfficeIntake(), 403);
        $this->type = strtolower($type);
        abort_unless(in_array($this->type, ['drf', 'dcn'], true), 404);
        $this->id = (int) $id;

        $payload = OfficeIntakeHelper::requestReviewPayload($this->type, $this->id);
        if (! $payload) {
            // Proceed already claimed this intake — resume Register instead of "not found".
            if (OfficeIntakeHelper::isIntakeClaimedPending($this->type, $this->id)) {
                return new RedirectResponse(
                    OfficeIntakeHelper::registerContinueUrl($this->type, $this->id)
                );
            }

            session()->flash('info', 'This submission is already registered or was not found.');

            return new RedirectResponse(route('dcs.requests.index', absolute: false));
        }

        return null;
    }

    public function with(): array
    {
        $payload = OfficeIntakeHelper::requestReviewPayload($this->type, $this->id);
        abort_unless($payload, 404);

        $printUrl = $this->type === 'dcn'
            ? route('dcs.office.dcn.print', ['id' => $this->id, 'view' => 1], absolute: false)
            : route('dcs.office.drf.print', ['id' => $this->id, 'view' => 1], absolute: false);

        return [
            'payload' => $payload,
            'printUrl' => $printUrl,
        ];
    }
}; ?>

@php
    $p = $payload;
    $isReceived = ! empty($p['received']);
    $canProceed = $isReceived && ! empty($p['canRegister']) && ! empty($p['registerUrl']);
@endphp

<div class="ofi-page ofi-request-review-page">
    <div class="ofi-request-review-shell">
        <header class="ofi-request-review-top">
            <a href="{{ route('dcs.requests.index', absolute: false) }}" class="ofi-request-back">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Back to Request</span>
            </a>

            <div class="ofi-request-review-heading">
                <div class="ofi-request-review-badges">
                    <span class="ofi-type-pill is-{{ $type }}">{{ strtoupper($type) }}</span>
                    @if($isReceived)
                        <span class="ofi-status-pill is-received">Received</span>
                    @else
                        <span class="ofi-status-pill is-pending">Awaiting receive</span>
                    @endif
                </div>
                <h1>{{ $p['title'] ?? 'Office submission' }}</h1>
                <p>{{ $p['subtitle'] ?? 'Review the submitted form, confirm receipt, then proceed to registration.' }}</p>
            </div>
        </header>

        <div class="ofi-request-review-layout">
            <section class="ofi-request-preview-panel" aria-label="Form preview">
                <div class="ofi-request-preview-bar">
                    <span><i class="fa-regular fa-file-lines"></i> Print preview</span>
                    <span class="ofi-request-preview-hint">View only</span>
                </div>
                <div class="ofi-request-preview-wrap">
                    <iframe
                        class="ofi-request-preview"
                        title="Form preview"
                        src="{{ $printUrl }}"
                    ></iframe>
                </div>
            </section>

            <aside class="ofi-request-actions" id="ofiRequestActions"
                data-type="{{ $type }}"
                data-id="{{ $id }}"
                data-register-url="{{ $p['registerUrl'] ?? '' }}"
                data-can-register="{{ !empty($p['canRegister']) ? '1' : '0' }}"
                data-received="{{ $isReceived ? '1' : '0' }}"
                data-edit-unlocked="{{ !empty($p['editUnlocked']) ? '1' : '0' }}"
            >
                <div class="ofi-request-actions-head">
                    <h2>RFIO actions</h2>
                    <p>Confirm receipt before registration. Return for correction if the office needs to fix the form.</p>
                </div>

                <div class="ofi-request-step">
                    <div class="ofi-request-step-num" aria-hidden="true">1</div>
                    <div class="ofi-request-step-body">
                        <label class="ofi-receive-check">
                            <input type="checkbox" id="ofiRequestReceived" @checked($isReceived)>
                            <span>I already received the document and reviewed that the inputted data are correct.</span>
                        </label>
                        <p class="ofi-receive-meta" id="ofiRequestReceiveMeta" @if(! $isReceived) hidden @endif>
                            @if($isReceived)
                                Received
                                @if(!empty($p['receivedAt'])) on {{ $p['receivedAt'] }} @endif
                                @if(!empty($p['receivedBy'])) by {{ $p['receivedBy'] }} @endif
                            @endif
                        </p>
                    </div>
                </div>

                <div class="ofi-request-step ofi-unlock-block" id="ofiRequestUnlockBlock" @if($isReceived) hidden @endif>
                    <div class="ofi-request-step-num" aria-hidden="true">2</div>
                    <div class="ofi-request-step-body">
                        <label class="ofi-unlock-label" for="ofiRequestUnlockReason">Return for correction</label>
                        <textarea
                            id="ofiRequestUnlockReason"
                            class="ofi-unlock-reason"
                            rows="3"
                            maxlength="1000"
                            placeholder="Describe what is wrong so the office can fix it…"
                        >{{ $p['editUnlockReason'] ?? '' }}</textarea>
                        <button type="button" class="ofi-unlock-btn" id="ofiRequestUnlockBtn">
                            <i class="fa-solid fa-unlock"></i>
                            Enable edit for office
                        </button>
                        <p class="ofi-receive-meta" id="ofiRequestUnlockMeta" @if(empty($p['editUnlocked'])) hidden @endif>
                            @if(!empty($p['editUnlocked']))
                                Office can edit and resubmit this form.
                            @endif
                        </p>
                    </div>
                </div>

                <div class="ofi-request-step is-final">
                    <div class="ofi-request-step-num" aria-hidden="true">{{ $isReceived ? '2' : '3' }}</div>
                    <div class="ofi-request-step-body">
                        <p class="ofi-request-step-label">Proceed to registration</p>
                        <button type="button" class="ofi-receive-proceed" id="ofiRequestProceed"
                            @disabled(! $canProceed)>
                            Proceed to Registration
                            <i class="fa-solid fa-arrow-right"></i>
                        </button>
                        @unless($canProceed)
                            <p class="ofi-request-step-hint">Receive the document first to unlock this action.</p>
                        @endunless
                    </div>
                </div>
            </aside>
        </div>
    </div>
</div>

<script src="{{ asset('js/dcs/office-request-review.js') }}"></script>
