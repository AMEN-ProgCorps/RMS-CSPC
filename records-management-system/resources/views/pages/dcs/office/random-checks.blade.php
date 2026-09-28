<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RandomCheckHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Random Check Results — CSPC DCS')] class extends Component {
    public function mount(): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
        if (RegisterQueryHelper::canBrowseAllOfficeIntake() && ! RegisterQueryHelper::isLimitedDcsUser()) {
            $this->redirect(route('dcs.random-check', absolute: false));
        }
    }

    public function with(): array
    {
        $inbox = RandomCheckHelper::officeInbox();

        return [
            'upcoming' => $inbox['upcoming'],
            'results' => $inbox['results'],
            'officeName' => RegisterQueryHelper::currentOfficeName(),
        ];
    }
}; ?>

<main class="rc-page">
    <header class="rc-header">
        <div class="rc-header-left">
            <div class="rc-breadcrumb">Document Control System / <span>Random Check</span></div>
            <h1>Random Check</h1>
            <p class="rc-subtitle">
                Scheduled visits and finalized results for <strong>{{ $officeName }}</strong>.
                Prepare your controlled copies before the visit date.
            </p>
        </div>
    </header>

    <section class="rc-panel" style="margin-bottom:16px;">
        <div class="rc-panel-head">
            <h2><i class="fa-regular fa-calendar"></i> Upcoming visits</h2>
        </div>
        @if(count($upcoming) < 1)
            <p class="rc-side-empty">No upcoming random check is scheduled for your office.</p>
        @else
            <div class="rc-year-grid">
                @foreach($upcoming as $row)
                    <div class="rc-year-card">
                        <strong>{{ $row['cycle_label'] }} {{ $row['year'] }}</strong>
                        <span>Visit date: {{ $row['scheduled_date'] }}</span>
                        <span class="rc-year-meta">Please have distributed copies ready.</span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="rc-panel">
        <div class="rc-panel-head">
            <h2><i class="fa-solid fa-clipboard-check"></i> Previous results</h2>
        </div>
        @if(count($results) < 1)
            <p class="rc-side-empty">No finalized random check has been shared for your office yet.</p>
        @else
            <div class="rc-year-grid">
                @foreach($results as $row)
                    <a class="rc-year-card" href="{{ route('dcs.office.random-checks.show', $row['id']) }}">
                        <strong>{{ $row['label'] }}</strong>
                        <span>{{ $row['check_date'] }} · {{ $row['sample_size'] }} document{{ $row['sample_size'] === 1 ? '' : 's' }}</span>
                        <span class="rc-year-meta">View entire result</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</main>
