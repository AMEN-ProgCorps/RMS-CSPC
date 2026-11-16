{{--
  DCS PAGE: Generate Report browser
  FILE: resources/views/pages/dcs/reports/show.blade.php
  DEBUG: ReportHelper; InteractsWithDcsReport
--}}
<?php

/**
 * DCS file: Generate Report screen. The table markup is partials/report-browser.blade.php. Data cells are Arial 10pt.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    use \App\View\Components\Dcs\InteractsWithDcsReport;
}; ?>

@include('pages.dcs.partials.report-browser')
