<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Office Documents — CSPC DCS')] class extends Component {
    #[Url]
    public string $type = 'all';

    public function mount(): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();

        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            session()->flash(
                'info',
                'Office document lists are available to each office. RFIO can browse the full inventory in Database and Reports.'
            );
            $this->redirect(route('dcs', absolute: false), navigate: true);
        }

        $keys = array_keys(OfficeIntakeHelper::documentGroupDefs());
        if ($this->type !== 'all' && ! in_array($this->type, $keys, true)) {
            $this->type = 'all';
        }
    }

    public function selectType(string $type): void
    {
        $keys = array_keys(OfficeIntakeHelper::documentGroupDefs());
        if ($type === 'all' || in_array($type, $keys, true)) {
            $this->type = $type;
        }
    }

    public function with(): array
    {
        $groups = OfficeIntakeHelper::officeDocumentGroups(null, false);
        $total = OfficeIntakeHelper::officeDocumentTotal();
        $active = $this->type;
        $keys = array_column($groups, 'key');

        if ($active !== 'all' && ! in_array($active, $keys, true)) {
            $active = 'all';
        }

        $activeLabel = $active === 'all'
            ? 'All'
            : OfficeIntakeHelper::documentGroupLabel($active);

        $rows = OfficeIntakeHelper::listOfficeDocuments($active);
        $activeCount = $active === 'all'
            ? $total
            : (int) (collect($groups)->firstWhere('key', $active)['count'] ?? count($rows));

        return [
            'groups' => $groups,
            'total' => $total,
            'activeType' => $active,
            'activeLabel' => $activeLabel,
            'activeCount' => $activeCount,
            'rows' => $rows,
            'officeName' => auth()->user()?->details?->office?->office_name
                ?? auth()->user()?->details?->office?->office_code
                ?? 'Your office',
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner ofi-inner-wide">
        <div class="ofi-header">
            <div>
                <h1>Office Documents</h1>
                <p>
                    Documents appear here only when RFIO distributes a controlled document
                    to <strong>{{ $officeName }}</strong>. Forms your office submitted
                    are not listed here.
                </p>
            </div>
        </div>

        <nav class="ofi-doc-nav" aria-label="Document types">
            <button
                type="button"
                class="ofi-doc-nav-item is-all {{ $activeType === 'all' ? 'active' : '' }}"
                wire:click="selectType('all')"
                wire:loading.attr="disabled"
                wire:target="selectType"
            >
                <span>All</span>
                <span class="ofi-doc-nav-count">{{ $total }}</span>
            </button>
            @foreach($groups as $group)
                <button
                    type="button"
                    class="ofi-doc-nav-item is-{{ str_replace('_', '-', $group['key']) }} {{ $activeType === $group['key'] ? 'active' : '' }} {{ $group['count'] < 1 ? 'is-empty' : '' }}"
                    wire:click="selectType('{{ $group['key'] }}')"
                    wire:loading.attr="disabled"
                    wire:target="selectType"
                    title="{{ $group['count'] < 1 ? 'No ' . $group['label'] . ' documents yet' : $group['count'] . ' ' . $group['label'] }}"
                >
                    <span>{{ $group['label'] }}</span>
                    <span class="ofi-doc-nav-count">{{ $group['count'] }}</span>
                </button>
            @endforeach
        </nav>

        <div class="ofi-card" style="position:relative;" wire:loading.class="is-loading">
            <div class="dcs-loading-overlay" wire:loading.flex wire:target="selectType">
                <div class="dcs-loading-spinner" aria-hidden="true"></div>
                <h4>Loading documents…</h4>
                <p>Fetching records and preparing the list.</p>
            </div>
            <div class="ofi-doc-panel-head">
                <h2>{{ $activeLabel }}</h2>
                <span>{{ $activeCount }} {{ $activeCount === 1 ? 'document' : 'documents' }}</span>
            </div>

            @if($activeCount < 1)
                <div class="ofi-doc-empty" role="status">
                    <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
                    @if($total < 1)
                        <strong>No documents yet</strong>
                        <p>No controlled documents list your office in Document Distribution yet.</p>
                    @else
                        <strong>No {{ strtolower($activeLabel) }} documents</strong>
                        <p>
                            Your office has other document types, but none under
                            <strong>{{ $activeLabel }}</strong> yet.
                        </p>
                    @endif
                </div>
            @else
                <div class="ofi-table-wrap">
                    <table class="ofi-table">
                        <thead>
                            <tr>
                                <th style="width:88px;">Item No.</th>
                                <th style="width:160px;">Document No.</th>
                                <th style="width:72px;">Rev.</th>
                                <th>Document Title</th>
                                <th style="width:100px;">Pages</th>
                                <th style="width:140px;">Effectivity Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr>
                                    <td>{{ $row['item_no'] }}</td>
                                    <td>{{ $row['doc_no'] !== '' ? $row['doc_no'] : '—' }}</td>
                                    <td>{{ $row['rev_no'] }}</td>
                                    <td>{{ $row['doc_title'] !== '' ? $row['doc_title'] : '—' }}</td>
                                    <td>{{ ($row['pages'] ?? '') !== '' ? $row['pages'] : '—' }}</td>
                                    <td>{{ $row['effectivity_date'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
