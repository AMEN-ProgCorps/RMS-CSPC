<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    use \App\View\Components\Dcs\InteractsWithDcsReport;
}; ?>

@include('pages.dcs.partials.report-browser')
