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

    public function tick(): void
    {
        if ($this->watchingIncoming()) {
            $pending = OfficeIntakeHelper::officeIncomingAttentionTotal();
            if ($pending !== $this->knownIncoming) {
                $increased = $pending > $this->knownIncoming;
                $this->knownIncoming = $pending;
                $this->dispatch('office-incoming-count', count: $pending);
                $this->dispatch('office-incoming-refresh');
                if ($increased) {
                    $this->dispatch('rms-notification-ping');
                }
            }
        }

        if ($this->watchingRequests()) {
            $pending = OfficeIntakeHelper::pendingOfficeRequestTotal();
            if ($pending !== $this->knownRequests) {
                $increased = $pending > $this->knownRequests;
                $this->knownRequests = $pending;
                $this->dispatch('office-request-count', count: $pending);
                if ($increased) {
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

@if(
    (\App\Helpers\RegisterQueryHelper::canAccessOfficeIntake() && ! \App\Helpers\RegisterQueryHelper::canBrowseAllOfficeIntake())
    || \App\Helpers\RegisterQueryHelper::canBrowseAllOfficeIntake()
)
    <div wire:poll.5s.keep-alive="tick" class="dcs-incoming-live" hidden></div>
@endif
