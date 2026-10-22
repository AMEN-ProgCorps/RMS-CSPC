<?php

/**
 * DCS file: Keeps an office's incoming count, bell, and incoming list current
 * while that office is using DCS.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Volt\Component;

new class extends Component {
    public int $knownIncoming = 0;

    public function mount(): void
    {
        if (! $this->watching()) {
            return;
        }

        $this->knownIncoming = OfficeIntakeHelper::officeIncomingAttentionTotal();
    }

    public function tick(): void
    {
        if (! $this->watching()) {
            return;
        }

        $pending = OfficeIntakeHelper::officeIncomingAttentionTotal();
        if ($pending === $this->knownIncoming) {
            return;
        }

        $increased = $pending > $this->knownIncoming;
        $this->knownIncoming = $pending;
        $this->dispatch('office-incoming-count', count: $pending);
        $this->dispatch('office-incoming-refresh');

        if ($increased) {
            $this->dispatch('rms-notification-ping');
        }
    }

    private function watching(): bool
    {
        return RegisterQueryHelper::canAccessOfficeIntake()
            && ! RegisterQueryHelper::canBrowseAllOfficeIntake();
    }
}; ?>

<div wire:poll.5s.keep-alive="tick" class="dcs-incoming-live" hidden></div>
