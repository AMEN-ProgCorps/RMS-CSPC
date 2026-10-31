<?php

/**
 * DCS file: Field-level edit history for a registration without a usable document number.
 */

use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Edit History — CSPC DCS')] class extends Component {
    public int $requestId = 0;

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function with(): array
    {
        return RegisterQueryHelper::editHistoryPageByRequest($this->requestId);
    }
}; ?>

@include('pages.dcs.register.partials.edit-history-page')
