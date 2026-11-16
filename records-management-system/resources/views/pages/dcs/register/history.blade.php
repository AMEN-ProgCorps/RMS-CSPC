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

    /**
     * Initializes component state, authorization gates, and default configurations.
     *
     * @param string $docNo
     *
     * @return void
     */
    public function mount(string $docNo): void
    {
        $this->docNo = $docNo;
    }

    /**
     * Supplies view data, query collections, and metrics to the Blade template.
     * @return array
     */
    public function with(): array
    {
        return RegisterQueryHelper::editHistoryPage($this->docNo);
    }
}; ?>

@include('pages.dcs.register.partials.edit-history-page')
