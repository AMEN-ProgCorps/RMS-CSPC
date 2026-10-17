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

        $dcnPrintUrl = null;
        $drfPrintUrl = null;
        $linkedDrfId = null;
        $linkedDcnId = null;
        $previewDocs = [];

        if ($this->type === 'dcn') {
            $dcnPrintUrl = route('dcs.office.dcn.print', ['id' => $this->id, 'view' => 1], absolute: false);
            $linkedDrfId = OfficeIntakeHelper::findLinkedDrfIdForOfficeDcn($this->id);
            $previewDocs[] = [
                'key' => 'dcn',
                'label' => 'DCN',
                'url' => $dcnPrintUrl,
            ];
            if ($linkedDrfId) {
                $drfPrintUrl = route('dcs.office.drf.print', ['id' => $linkedDrfId, 'view' => 1], absolute: false);
                $previewDocs[] = [
                    'key' => 'drf',
                    'label' => 'DRF',
                    'url' => $drfPrintUrl,
                ];
            }
        } else {
            $drfPrintUrl = route('dcs.office.drf.print', ['id' => $this->id, 'view' => 1], absolute: false);
            $linkedDcnId = OfficeIntakeHelper::findSourceDcnIdForOfficeDrf($this->id);
            if ($linkedDcnId) {
                $dcnPrintUrl = route('dcs.office.dcn.print', ['id' => $linkedDcnId, 'view' => 1], absolute: false);
                $previewDocs[] = [
                    'key' => 'dcn',
                    'label' => 'DCN',
                    'url' => $dcnPrintUrl,
                ];
            }
            $previewDocs[] = [
                'key' => 'drf',
                'label' => 'DRF',
                'url' => $drfPrintUrl,
            ];
        }

        $activeKey = $this->type === 'dcn' ? 'dcn' : 'drf';
        $printUrl = $this->type === 'dcn' ? $dcnPrintUrl : $drfPrintUrl;

        $linkedPayload = null;
        $linkedType = $linkedDrfId ? 'drf' : ($linkedDcnId ? 'dcn' : null);
        $linkedId = $linkedDrfId ?: $linkedDcnId;
        if ($linkedType && $linkedId) {
            $linkedPayload = OfficeIntakeHelper::modalPayload($linkedType, (int) $linkedId);
        }

        $registerFromDcn = $this->type === 'dcn' || $linkedType === 'dcn';
        $proceedType = $registerFromDcn ? 'dcn' : $this->type;
        $proceedId = $this->type === 'dcn'
            ? $this->id
            : ($linkedType === 'dcn' ? (int) $linkedId : $this->id);
        $proceedPayload = $payload;
        if ($proceedType === 'dcn' && $this->type !== 'dcn' && $linkedPayload) {
            $proceedPayload = $linkedPayload;
        }
        $proceedUrl = $proceedPayload['registerUrl'] ?? '';

        $actionPanels = [[
            'key' => $payload['type'] ?? $this->type,
            'payload' => $payload,
            'pairReceived' => empty($linkedPayload) || ! empty($linkedPayload['received']),
            'pairLabel' => $linkedPayload ? strtoupper((string) ($linkedPayload['type'] ?? '')) : '',
            'proceedType' => $proceedType,
            'proceedId' => $proceedId,
            'registerUrl' => $proceedUrl,
        ]];
        if ($linkedPayload) {
            $linkedPanel = [
                'key' => $linkedPayload['type'],
                'payload' => $linkedPayload,
                'pairReceived' => ! empty($payload['received']),
                'pairLabel' => strtoupper($this->type),
                'proceedType' => $proceedType,
                'proceedId' => $proceedId,
                'registerUrl' => $proceedUrl,
            ];
            if (($linkedPayload['type'] ?? '') === 'dcn') {
                array_unshift($actionPanels, $linkedPanel);
            } else {
                $actionPanels[] = $linkedPanel;
            }
        }

        return [
            'payload' => $payload,
            'printUrl' => $printUrl,
            'previewDocs' => $previewDocs,
            'activePreviewKey' => $activeKey,
            'linkedDrfId' => $linkedDrfId,
            'linkedDcnId' => $linkedDcnId,
            'actionPanels' => $actionPanels,
        ];
    }
}; ?>

@php
    $p = $payload;
    $isReceived = ! empty($p['received']);
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
                    <div class="ofi-request-preview-bar-right">
                        @if(count($previewDocs) > 1)
                            <div class="ofi-preview-switch" role="tablist" aria-label="Preview form">
                                @foreach($previewDocs as $doc)
                                    <button
                                        type="button"
                                        class="ofi-preview-switch-btn{{ ($doc['key'] ?? '') === $activePreviewKey ? ' is-active' : '' }}"
                                        role="tab"
                                        aria-selected="{{ ($doc['key'] ?? '') === $activePreviewKey ? 'true' : 'false' }}"
                                        data-ofi-preview-key="{{ $doc['key'] }}"
                                        data-ofi-preview-url="{{ $doc['url'] }}"
                                    >{{ $doc['label'] }}</button>
                                @endforeach
                            </div>
                        @endif
                        <span class="ofi-request-preview-hint">View only</span>
                    </div>
                </div>
                <div class="ofi-request-preview-wrap">
                    <iframe
                        id="ofiRequestPreviewFrame"
                        class="ofi-request-preview"
                        title="Form preview"
                        src="{{ $printUrl }}"
                    ></iframe>
                </div>
            </section>

            @foreach($actionPanels as $panel)
                @php
                    $ap = $panel['payload'];
                    $panelType = (string) ($ap['type'] ?? $panel['key']);
                    $panelReceived = ! empty($ap['received']);
                    $panelNotified = ! empty($ap['printNotified']);
                    $panelPairPending = empty($panel['pairReceived']) && ($panel['pairLabel'] ?? '') !== '';
                    $panelCanProceed = $panelReceived && ! $panelPairPending && ! empty($ap['canRegister']) && ! empty($panel['registerUrl']);
                @endphp
                <aside class="ofi-request-actions" data-ofi-actions data-preview-key="{{ $panel['key'] }}"
                    @if($panel['key'] !== $activePreviewKey) hidden @endif
                    data-type="{{ $panelType }}"
                    data-id="{{ (int) ($ap['id'] ?? 0) }}"
                    data-proceed-type="{{ $panel['proceedType'] }}"
                    data-proceed-id="{{ (int) $panel['proceedId'] }}"
                    data-register-url="{{ $panel['registerUrl'] ?? '' }}"
                    data-can-register="{{ !empty($ap['canRegister']) ? '1' : '0' }}"
                    data-received="{{ $panelReceived ? '1' : '0' }}"
                    data-pair-received="{{ $panelPairPending ? '0' : '1' }}"
                    data-pair-label="{{ $panel['pairLabel'] ?? '' }}"
                    data-print-notified="{{ $panelNotified ? '1' : '0' }}"
                >
                    <div class="ofi-request-actions-head">
                        <h2>{{ strtoupper($panelType) }} actions</h2>
                        <p>Notify the office when this {{ strtoupper($panelType) }} is correct, confirm you have the signed hard copy, then proceed to registration.</p>
                    </div>

                    <div class="ofi-request-step" data-ofi-notify-block>
                        <div class="ofi-request-step-num" aria-hidden="true">1</div>
                        <div class="ofi-request-step-body">
                            <p class="ofi-request-step-label">Notify office — ready to print &amp; sign</p>
                            <p class="ofi-request-step-hint">
                                Tell the submitting office this {{ strtoupper($panelType) }} looks correct so they can print, sign, and bring the hard copy to the Records Office.
                            </p>
                            <button type="button" class="ofi-notify-print-btn" data-ofi-notify-btn>
                                <i class="fa-regular fa-bell"></i>
                                <span data-ofi-notify-label>{{ $panelNotified ? 'Resend print & sign notice' : 'Notify client to print & sign' }}</span>
                            </button>
                            <p class="ofi-receive-meta" data-ofi-notify-meta @if(! $panelNotified) hidden @endif>
                                @if($panelNotified)
                                    Client notified
                                    @if(!empty($ap['printNotifiedAt'])) on {{ $ap['printNotifiedAt'] }} @endif
                                    @if(!empty($ap['printNotifiedBy'])) by {{ $ap['printNotifiedBy'] }} @endif
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="ofi-request-step">
                        <div class="ofi-request-step-num" aria-hidden="true">2</div>
                        <div class="ofi-request-step-body">
                            <label class="ofi-receive-check">
                                <input type="checkbox" data-ofi-received @checked($panelReceived)>
                                <span>I have received the signed {{ strtoupper($panelType) }} and verified that the data matches this submission.</span>
                            </label>
                            <p class="ofi-receive-meta" data-ofi-receive-meta @if(! $panelReceived) hidden @endif>
                                @if($panelReceived)
                                    {{ strtoupper($panelType) }} received
                                    @if(!empty($ap['receivedAt'])) on {{ $ap['receivedAt'] }} @endif
                                    @if(!empty($ap['receivedBy'])) by {{ $ap['receivedBy'] }} @endif
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="ofi-request-step ofi-unlock-block" data-ofi-unlock-block @if($panelReceived) hidden @endif>
                        <div class="ofi-request-step-num" aria-hidden="true">3</div>
                        <div class="ofi-request-step-body">
                            <label class="ofi-unlock-label">Return for correction</label>
                            <textarea
                                data-ofi-unlock-reason
                                class="ofi-unlock-reason"
                                rows="3"
                                maxlength="1000"
                                placeholder="Describe what is wrong so the office can fix it…"
                                @disabled($panelNotified)
                            >{{ $ap['editUnlockReason'] ?? '' }}</textarea>
                            <button type="button" class="ofi-unlock-btn" data-ofi-unlock-btn
                                @disabled($panelNotified)
                                title="{{ $panelNotified ? 'Not available after notifying the office that the form is correct.' : '' }}">
                                <i class="fa-solid fa-{{ $panelNotified ? 'lock' : 'unlock' }}"></i>
                                Enable edit for office
                            </button>
                            <p class="ofi-receive-meta" data-ofi-unlock-meta @if(empty($ap['editUnlocked']) && ! $panelNotified) hidden @endif>
                                @if($panelNotified)
                                    Locked — office was already notified that this submission is correct.
                                @elseif(!empty($ap['editUnlocked']))
                                    Office can edit and resubmit this form.
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="ofi-request-step is-final">
                        <div class="ofi-request-step-num" aria-hidden="true">{{ $panelReceived ? '3' : '4' }}</div>
                        <div class="ofi-request-step-body">
                            <p class="ofi-request-step-label">Proceed to registration</p>
                            <button type="button" class="ofi-receive-proceed" data-ofi-proceed
                                @disabled(! $panelCanProceed)>
                                Proceed to Registration
                                <i class="fa-solid fa-arrow-right"></i>
                            </button>
                            <p class="ofi-request-step-hint" data-ofi-proceed-hint @if($panelCanProceed) hidden @endif>
                                @if(! $panelReceived)
                                    Confirm receipt of the signed {{ strtoupper($panelType) }} first to unlock registration.
                                @elseif($panelPairPending)
                                    Switch to the {{ $panel['pairLabel'] }} preview and confirm receipt of that signed form before registration.
                                @else
                                    Confirm receipt of the signed form first to unlock registration.
                                @endif
                            </p>
                        </div>
                    </div>
                </aside>
            @endforeach
        </div>
    </div>
</div>

<script src="{{ asset('js/dcs/office-request-review.js') }}"></script>
<script>
(function () {
    const frame = document.getElementById('ofiRequestPreviewFrame');
    if (!frame) return;

    function fitPreview() {
        let doc;
        try { doc = frame.contentDocument; } catch (e) { return; }
        if (!doc) return;
        const sheet = doc.querySelector('.sheet');
        const toolbar = doc.querySelector('.print-toolbar');
        let height = 0;
        if (toolbar) height += Math.ceil(toolbar.getBoundingClientRect().height);
        if (sheet) height += Math.ceil(sheet.getBoundingClientRect().height);
        if (height > 80) frame.style.height = (height + 8) + 'px';
    }

    frame.addEventListener('load', fitPreview);

    const switchRoot = document.querySelector('.ofi-preview-switch');
    if (!switchRoot) return;

    switchRoot.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-ofi-preview-url]');
        if (!btn || !switchRoot.contains(btn)) return;
        const url = btn.getAttribute('data-ofi-preview-url') || '';
        if (!url) return;

        frame.src = url;
        const key = btn.getAttribute('data-ofi-preview-key') || '';
        switchRoot.querySelectorAll('.ofi-preview-switch-btn').forEach(function (el) {
            const on = el === btn;
            el.classList.toggle('is-active', on);
            el.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('[data-ofi-actions]').forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-preview-key') !== key;
        });
    });
})();
</script>
