<?php

/**
 * DCS file: Field-level edit history for a registration without a usable document number.
 */

use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    public int $requestId = 0;

    /**
     * Initializes component state, authorization gates, and default configurations.
     *
     * @param int $requestId
     *
     * @return void
     */
    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    /**
     * Supplies view data, query collections, and metrics to the Blade template.
     * @return array
     */
    public function with(): array
    {
        return RegisterQueryHelper::editHistoryPageByRequest($this->requestId);
    }
}; ?>

@include('pages.dcs.register.partials.edit-history-page')
