<?php

/**
 * DCS file: Keeps office Incoming and admin Request nav badges current while using DCS.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Volt\Component;

new class extends Component {
    public int $knownIncoming = 0;
    public int $knownRequests = 0;

    public function mount(): void
    {
        if ($this->watchingIncoming()) {
            $this->knownIncoming = OfficeIntakeHelper::officeIncomingAttentionTotal();
        }
        if ($this->watchingRequests()) {
            $this->knownRequests = OfficeIntakeHelper::pendingOfficeRequestTotal();
        }
    }

    /**
     * @param  bool  $force  Bust inventory cache (browser back / tab focus after appraisal).
     */
    public function tick(bool $force = false): void
    {
        if ($this->watchingIncoming()) {
            $pending = OfficeIntakeHelper::officeIncomingAttentionTotal(null, $force);
            if ($force || $pending !== $this->knownIncoming) {
                $increased = $pending > $this->knownIncoming;
                $this->knownIncoming = $pending;
                $this->dispatch('office-incoming-count', count: $pending);
                $this->dispatch('office-incoming-refresh');
                if ($increased && ! $force) {
                    $this->dispatch('rms-notification-ping');
                }
            }
        }

        if ($this->watchingRequests()) {
            $pending = OfficeIntakeHelper::pendingOfficeRequestTotal();
            if ($force || $pending !== $this->knownRequests) {
                $increased = $pending > $this->knownRequests;
                $this->knownRequests = $pending;
                $this->dispatch('office-request-count', count: $pending);
                if ($increased && ! $force) {
                    $this->dispatch('rms-notification-ping');
                }
            }
        }
    }

    private function watchingIncoming(): bool
    {
        return RegisterQueryHelper::canAccessOfficeIntake()
            && ! RegisterQueryHelper::canBrowseAllOfficeIntake();
    }

    private function watchingRequests(): bool
    {
        return RegisterQueryHelper::canBrowseAllOfficeIntake();
    }
}; ?>

{{-- Always keep a single root tag; Livewire cannot root on Blade @if markers. --}}
<div
    wire:poll.5s.keep-alive="tick"
    class="dcs-incoming-live"
    hidden
    aria-hidden="true"
    x-data
    x-init="
        const forceTick = () => { try { $wire.tick(true); } catch (e) {} };
        window.addEventListener('pageshow', (e) => { if (e.persisted) forceTick(); });
        document.addEventListener('visibilitychange', () => { if (!document.hidden) forceTick(); });
    "
></div>
