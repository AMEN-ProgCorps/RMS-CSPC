<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RandomCheckHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Random Check Result — CSPC DCS')] class extends Component {
    #[Locked]
    public int $checkId = 0;

    public string $filter = 'all';

    public function mount(int $id): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
        abort_unless(RandomCheckHelper::officeCanViewCheck($id), 404);
        $this->checkId = $id;
    }

    public function with(): array
    {
        $check = RandomCheckHelper::loadCheck($this->checkId);
        abort_unless($check, 404);
        $rows = $check['rows'] ?? [];
        if ($this->filter === 'actions') {
            $rows = array_values(array_filter(
                $rows,
                fn ($row) => trim((string) ($row['recommended_actions'] ?? '')) !== ''
            ));
        }

        return [
            'check' => $check,
            'rows' => $rows,
            'officeName' => RegisterQueryHelper::currentOfficeName(),
        ];
    }
}; ?>

<main class="rc-page">
    <header class="rc-header">
        <div class="rc-header-left">
            <div class="rc-breadcrumb">
                <a class="rc-crumb-link" href="{{ route('dcs.office.random-checks') }}">Random Check</a>
                / <span>{{ $check['year'] }} {{ $check['cycle_label'] }}</span>
            </div>
            <h1>{{ $check['cycle_label'] }} {{ $check['year'] }}</h1>
            <p class="rc-subtitle">
                Entire result for {{ $check['office_label'] }}. This snapshot is locked and will not change if documents are revised later.
            </p>
        </div>
        <div class="rc-header-right">
            <div class="rc-filter-pills">
                <button type="button" class="{{ $filter === 'all' ? 'active' : '' }}" wire:click="$set('filter', 'all')">Entire result</button>
                <button type="button" class="{{ $filter === 'actions' ? 'active' : '' }}" wire:click="$set('filter', 'actions')">Recommended actions only</button>
            </div>
        </div>
    </header>

    <section class="rc-meta-bar">
        <div><strong>Visit date</strong><div>{{ $check['check_date_label'] }}</div></div>
        <div><strong>Conducted by</strong><div>{{ $check['conducted_by'] ?: '—' }}</div></div>
        <div><strong>Tested by</strong><div>{{ $check['tested_by'] ?: '—' }}</div></div>
    </section>

    <section class="rc-panel">
        <div class="rc-table-wrap">
            <table class="rc-table rc-table-wide">
                <thead>
                    <tr>
                        <th>Item No.</th>
                        <th>Document No.</th>
                        <th>Rev</th>
                        <th>Effectivity Date</th>
                        <th>Availability</th>
                        <th>Remarks</th>
                        <th>Recommended actions</th>
                        <th>Compliance</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td class="col-item">{{ $row['item_no'] }}</td>
                            <td class="col-doc">
                                <span class="rc-doc-no">{{ $row['doc_no'] ?: '—' }}</span>
                                <small>{{ $row['doc_title'] }}</small>
                            </td>
                            <td class="col-rev">{{ $row['rev_no'] }}</td>
                            <td>{{ $row['effectivity_date'] ?: '—' }}</td>
                            <td>{{ strtoupper($row['availability'] ?: '—') }}</td>
                            <td>{{ $row['remarks'] ?: '—' }}</td>
                            <td>{{ $row['recommended_actions'] ?: '—' }}</td>
                            <td>
                                @if($row['compliance_status'] === 'complied') Complied
                                @elseif($row['compliance_status'] === 'not_complied') Not complied
                                @else —
                                @endif
                            </td>
                            <td>{{ $row['notes'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9">No rows for this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
