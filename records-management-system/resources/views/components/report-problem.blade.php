<?php

use App\Services\ErrorTicketRecorder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Component;

new class extends Component {
    public bool $open = false;
    public string $title = '';
    public string $description = '';
    public string $reference = '';
    public string $pageUrl = '';
    public ?string $successTicket = null;
    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->pageUrl = '';
    }

    public function openModal(
        ?string $reference = null,
        ?string $title = null,
        ?string $description = null,
        ?string $pageUrl = null
    ): void {
        $this->resetValidation();
        $this->errorMessage = null;
        $this->successTicket = null;
        $this->reference = trim((string) $reference);
        $this->title = trim((string) ($title ?: 'Problem on this page'));
        $this->description = trim((string) $description);

        $url = trim((string) $pageUrl);
        if ($url === '' || str_contains($url, '/livewire/')) {
            $referer = (string) request()->headers->get('referer', '');
            $url = ($referer !== '' && ! str_contains($referer, '/livewire/'))
                ? $referer
                : (string) (request()->fullUrl() ?? '');
        }
        $this->pageUrl = $url;
        $this->open = true;
    }

    public function closeModal(): void
    {
        $this->open = false;
    }

    public function toggleModal(): void
    {
        if ($this->open) {
            $this->closeModal();
        } else {
            $this->openModal(null, null, null, url()->previous() ?? '');
        }
    }

    public function submit(): void
    {
        $this->errorMessage = null;
        $this->successTicket = null;

        if (! Auth::check()) {
            $this->errorMessage = 'Sign in before reporting a problem.';
            return;
        }

        $this->validate([
            'title' => 'required|string|min:3|max:240',
            'description' => 'required|string|min:10|max:5000',
            'reference' => 'nullable|string|max:32',
        ]);

        $key = 'error_ticket_report:user_'.Auth::id();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            $this->errorMessage = "Too many reports. Try again in {$seconds} second(s).";
            return;
        }

        try {
            $ticket = ErrorTicketRecorder::recordUser([
                'title' => $this->title,
                'description' => $this->description,
                'reference' => $this->reference,
                'url' => $this->pageUrl !== '' ? $this->pageUrl : request()->fullUrl(),
                'priority' => 'medium',
            ]);
            RateLimiter::hit($key, 3600);
            $this->successTicket = $ticket->ticket_number;
            $this->description = '';
        } catch (\Throwable $e) {
            $this->errorMessage = 'Could not submit the report. Please try again.';
        }
    }
};
?>

<div
    x-data="{
        isOpen: @entangle('open'),
        init() {
            this.$watch('isOpen', val => {
                if (val) {
                    document.body.classList.add('report-widget-open');
                    window.dispatchEvent(new CustomEvent('rms-widget-opened', { detail: { widget: 'report' } }));
                } else {
                    document.body.classList.remove('report-widget-open');
                    window.dispatchEvent(new CustomEvent('rms-widget-closed', { detail: { widget: 'report' } }));
                }
            });
            if (this.isOpen) {
                document.body.classList.add('report-widget-open');
            }
            const syncChatifyPresent = () => {
                if (!document.getElementById('chatify-global-widget')) {
                    document.body.classList.add('no-chatify-widget');
                } else {
                    document.body.classList.remove('no-chatify-widget');
                }
            };
            syncChatifyPresent();
            setTimeout(syncChatifyPresent, 400);
        }
    }"
    x-on:rms-open-report-problem.window="$wire.openModal($event.detail.reference || null, $event.detail.title || null, $event.detail.description || null, window.location.href)"
    x-on:rms-widget-opened.window="if ($event.detail && $event.detail.widget !== 'report' && isOpen) { $wire.closeModal(); }"
>
    <!-- Floating Trigger Button -->
    <button
        type="button"
        class="rms-report-fab"
        :class="{ 'is-open': isOpen }"
        :title="isOpen ? 'Minimize Report' : 'Report a problem'"
        @click="isOpen ? $wire.closeModal() : $wire.openModal(null, null, null, window.location.href)"
    >
        <span class="rms-report-fab-icon" aria-hidden="true">
            <!-- Question / Report Icon -->
            <svg x-show="!isOpen" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <!-- Close Icon -->
            <svg x-show="isOpen" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display: none;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </span>
        <span class="rms-report-fab-label" x-text="isOpen ? 'Close' : 'Report'">Report</span>
    </button>

    <!-- Floating Report Widget Card (docked in bottom-right like Chatify) -->
    <div
        x-show="isOpen"
        x-cloak
        class="rms-report-widget-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rms-report-title"
        @click.outside="if (isOpen) $wire.closeModal()"
        @keydown.escape.window="if (isOpen) $wire.closeModal()"
    >
        <!-- Header Bar -->
        <div class="rms-report-widget-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 10px; height: 10px; border-radius: 50%; background: #f59e0b; box-shadow: 0 0 8px #f59e0b;"></div>
                <span style="color: #f8fafc; font-weight: 600; font-size: 14px; letter-spacing: 0.2px;">Report a Problem</span>
                <span style="background: rgba(255, 255, 255, 0.15); color: #cbd5e1; font-size: 10px; font-weight: 600; padding: 2px 7px; border-radius: 999px;">Support</span>
            </div>

            <div style="display: flex; align-items: center; gap: 8px;">
                <!-- Minimize Button -->
                <button type="button" @click="$wire.closeModal()" title="Minimize" class="rms-report-hdr-btn">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <!-- Close Button -->
                <button type="button" @click="$wire.closeModal()" title="Close" class="rms-report-hdr-btn">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        @if($successTicket)
            <div class="rms-report-widget-body" style="display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center;">
                <div class="rms-report-success">
                    <div class="rms-report-success-icon" aria-hidden="true">✓</div>
                    <strong>Ticket {{ $successTicket }} submitted</strong>
                    <span>Thank you — the admin team will review this in Error Tickets.</span>
                </div>
            </div>
            <div class="rms-report-widget-footer">
                <button type="button" class="rms-report-btn primary" @click="$wire.closeModal()">Done</button>
            </div>
        @else
            <form wire:submit.prevent="submit" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
                <div class="rms-report-widget-body">
                    <div style="margin-bottom: 14px; padding: 10px 12px; border-radius: 8px; background: #f8fafc; border: 1px solid #e2e8f0; font-size: 12px; color: #64748b; line-height: 1.45;">
                        Describe what went wrong. The admin team can view details and resolve it in the console.
                    </div>

                    <label class="rms-report-field">
                        <span>Subject *</span>
                        <input type="text" wire:model="title" maxlength="240" required placeholder="Brief summary of the issue...">
                        @error('title') <em>{{ $message }}</em> @enderror
                    </label>

                    <label class="rms-report-field">
                        <span>What happened? *</span>
                        <textarea wire:model="description" rows="5" maxlength="5000" required placeholder="Steps to reproduce, expected vs actual result…"></textarea>
                        @error('description') <em>{{ $message }}</em> @enderror
                    </label>

                    <label class="rms-report-field">
                        <span>Error reference <em class="rms-report-optional">(optional)</em></span>
                        <input type="text" wire:model="reference" maxlength="32" placeholder="e.g. SRV-AB12CD">
                    </label>

                    @if($pageUrl !== '')
                        <p class="rms-report-meta" title="{{ $pageUrl }}">
                            <span>Page</span>
                            {{ \Illuminate\Support\Str::limit($pageUrl, 64) }}
                        </p>
                    @endif

                    @if($errorMessage)
                        <div class="rms-report-error">{{ $errorMessage }}</div>
                    @endif
                </div>

                <div class="rms-report-widget-footer">
                    <button type="button" class="rms-report-btn" @click="$wire.closeModal()">Cancel</button>
                    <button type="submit" class="rms-report-btn primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="submit">Submit Ticket</span>
                        <span wire:loading wire:target="submit">Submitting…</span>
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>

<style>
    /* Default state: Circular icon button matching Chatify */
    .rms-report-fab {
        position: fixed;
        right: 24px;
        bottom: 92px; /* clear Chatify FAB (56px @ bottom 24px + 12px gap) */
        z-index: 999998;
        height: 56px;
        min-width: 56px;
        width: 56px;
        border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, 0.12);
        background: #0f172a;
        color: #f8fafc;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.35);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: flex-start;
        padding: 0 0 0 14px;
        overflow: hidden;
        white-space: nowrap;
        user-select: none;
        box-sizing: border-box;
        transition: width 0.28s cubic-bezier(0.4, 0, 0.2, 1),
                    padding 0.28s cubic-bezier(0.4, 0, 0.2, 1),
                    bottom 0.35s cubic-bezier(0.34, 1.25, 0.64, 1),
                    transform 0.2s ease,
                    background 0.2s ease,
                    box-shadow 0.2s ease;
    }

    /* Hover state: Expands to pill with label */
    .rms-report-fab:hover {
        width: 138px;
        padding: 0 16px 0 14px;
        background: #1e293b;
        transform: translateY(-1px);
        box-shadow: 0 14px 32px rgba(15, 23, 42, 0.45);
    }

    .rms-report-fab-icon {
        width: 28px;
        height: 28px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.12);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .rms-report-fab-label {
        max-width: 0;
        opacity: 0;
        overflow: hidden;
        margin-left: 0;
        font: 700 0.88rem Inter, system-ui, sans-serif;
        letter-spacing: 0.01em;
        color: #f8fafc;
        transition: max-width 0.28s cubic-bezier(0.4, 0, 0.2, 1),
                    opacity 0.2s ease,
                    margin-left 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .rms-report-fab:hover .rms-report-fab-label {
        max-width: 75px;
        opacity: 1;
        margin-left: 8px;
    }

    /* Open state hover */
    .rms-report-fab.is-open:hover {
        width: 124px;
    }

    /* When Chatify widget is open, Report FAB moves UP above the Chatify card */
    body.chatify-widget-open .rms-report-fab {
        bottom: calc(min(620px, calc(100vh - 100px)) + 104px) !important;
        z-index: 1000000 !important;
    }

    /* When Report Problem widget is opened, its FAB sits at bottom 24px as active toggle */
    body.report-widget-open .rms-report-fab {
        bottom: 24px !important;
        z-index: 1000000 !important;
    }

    /* If Chatify is absent/disabled, Report FAB rests at bottom 24px */
    body.no-chatify-widget .rms-report-fab {
        bottom: 24px;
    }

    /* Floating Report Card (matches Chatify widget card layout & animation) */
    .rms-report-widget-card {
        position: fixed;
        bottom: 88px;
        right: 24px;
        width: 420px;
        height: 580px;
        max-width: calc(100vw - 32px);
        max-height: calc(100vh - 100px);
        border-radius: 16px;
        box-shadow: 0 20px 40px -8px rgba(15, 23, 42, 0.25), 0 0 0 1px rgba(148, 163, 184, 0.2);
        background: #ffffff;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        z-index: 999999;
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        animation: rmsReportCardPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes rmsReportCardPop {
        from {
            opacity: 0;
            transform: translateY(16px) scale(0.96);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .rms-report-widget-header {
        background: #0f172a;
        padding: 12px 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        user-select: none;
        flex-shrink: 0;
    }

    .rms-report-hdr-btn {
        background: transparent;
        border: none;
        color: #94a3b8;
        cursor: pointer;
        padding: 4px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: color 0.2s, background 0.2s;
    }
    .rms-report-hdr-btn:hover {
        color: #f8fafc;
        background: rgba(255, 255, 255, 0.1);
    }

    .rms-report-widget-body {
        flex: 1;
        overflow-y: auto;
        padding: 18px 20px;
        background: #ffffff;
    }

    .rms-report-widget-footer {
        padding: 12px 18px;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        flex-shrink: 0;
    }

    .rms-report-field {
        display: block;
        margin-bottom: 14px;
    }
    .rms-report-field > span {
        display: block;
        margin-bottom: 6px;
        font-size: 0.78rem;
        font-weight: 600;
        color: #334155;
    }
    .rms-report-optional {
        font-style: normal;
        font-weight: 500;
        color: #94a3b8;
    }
    .rms-report-field input,
    .rms-report-field textarea {
        width: 100%;
        box-sizing: border-box;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        padding: 10px 12px;
        font: 0.9rem/1.4 Inter, system-ui, sans-serif;
        color: #0f172a;
        background: #fff;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .rms-report-field input:focus,
    .rms-report-field textarea:focus {
        outline: none;
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }
    .rms-report-field em {
        display: block;
        margin-top: 5px;
        color: #dc2626;
        font-size: 0.78rem;
        font-style: normal;
    }
    .rms-report-meta {
        margin: 0 0 4px;
        padding: 10px 12px;
        border-radius: 8px;
        background: #f1f5f9;
        color: #64748b;
        font-size: 0.75rem;
        line-height: 1.4;
        word-break: break-all;
    }
    .rms-report-meta span {
        display: block;
        margin-bottom: 2px;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-size: 0.68rem;
    }
    .rms-report-error {
        margin-top: 10px;
        padding: 10px 12px;
        border-radius: 8px;
        background: rgba(239, 68, 68, 0.08);
        border: 1px solid rgba(239, 68, 68, 0.18);
        color: #b91c1c;
        font-size: 0.85rem;
    }
    .rms-report-success {
        display: grid;
        justify-items: center;
        text-align: center;
        gap: 8px;
        padding: 24px 8px;
    }
    .rms-report-success-icon {
        width: 48px;
        height: 48px;
        border-radius: 999px;
        background: #dcfce7;
        color: #15803d;
        display: grid;
        place-items: center;
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 4px;
    }
    .rms-report-success strong { color: #047857; font-size: 1rem; }
    .rms-report-success span { color: #475569; font-size: 0.88rem; line-height: 1.45; max-width: 32ch; }

    .rms-report-btn {
        border: 1.5px solid #cbd5e1;
        background: #fff;
        color: #334155;
        border-radius: 8px;
        padding: 9px 16px;
        font: 600 0.85rem Inter, system-ui, sans-serif;
        cursor: pointer;
        transition: background 0.15s ease, border-color 0.15s ease;
    }
    .rms-report-btn:hover { background: #f1f5f9; }
    .rms-report-btn.primary {
        background: #0f172a;
        border-color: #0f172a;
        color: #fff;
        box-shadow: 0 4px 10px rgba(15, 23, 42, 0.2);
    }
    .rms-report-btn.primary:hover { background: #1e293b; border-color: #1e293b; }
    .rms-report-btn:disabled { opacity: 0.65; cursor: wait; }

    @media (max-width: 767px) {
        .rms-report-fab {
            right: 16px;
            bottom: 24px;
        }
        .rms-report-widget-card {
            right: 12px;
            bottom: 12px;
            width: calc(100vw - 24px);
            max-height: calc(100vh - 24px);
            height: calc(100vh - 24px);
        }
    }

    @media print {
        .rms-report-fab,
        .rms-report-widget-card { display: none !important; }
    }
</style>
