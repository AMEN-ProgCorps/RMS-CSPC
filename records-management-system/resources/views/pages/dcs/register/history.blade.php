<?php

/**
 * DCS file: Field-level edit history for one document family.
 */

use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    public string $docNo = '';

    public function mount(string $docNo): void
    {
        $this->docNo = $docNo;
    }

    public function with(): array
    {
        return RegisterQueryHelper::editHistoryPage($this->docNo);
    }
}; ?>

@include('pages.dcs.register.partials.edit-history-page')
