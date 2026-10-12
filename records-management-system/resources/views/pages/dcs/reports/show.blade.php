<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    use \App\Livewire\Dcs\InteractsWithDcsReport;
}; ?>

@include('pages.dcs.partials.report-browser')
