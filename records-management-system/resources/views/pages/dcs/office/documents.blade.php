<?php

/**
 * DCS file: Controlled documents already held by this office.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    use WithPagination;

    #[Url]
    public string $type = 'all';

    public int $perPage = 25;

    public function mount(): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();

        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            session()->flash(
                'info',
                'Office masterlists are available to each office. RFIO can browse the full inventory in Database and Reports.'
            );

            $this->redirect(route('dcs', absolute: false));

            return;
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
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $groups = OfficeIntakeHelper::officeDocumentGroups(null, false, 'received');
        $total = OfficeIntakeHelper::officeDocumentTotal(null, 'received');
        $pendingTotal = OfficeIntakeHelper::officeDocumentTotal(null, 'pending');
        $active = $this->type;
        $keys = array_column($groups, 'key');

        if ($active !== 'all' && ! in_array($active, $keys, true)) {
            $active = 'all';
        }

        $activeLabel = $active === 'all'
            ? 'All'
            : OfficeIntakeHelper::documentGroupLabel($active);

        $perPage = max(10, min(100, (int) $this->perPage));
        $page = max(1, (int) $this->getPage());
        $rows = OfficeIntakeHelper::paginateOfficeDocuments($active, null, 'received', $perPage, $page);
        $activeCount = $active === 'all'
            ? $total
            : (int) (collect($groups)->firstWhere('key', $active)['count'] ?? $rows->total());

        return [
            'groups' => $groups,
            'total' => $total,
            'pendingTotal' => $pendingTotal,
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
                <h1>Masterlist</h1>
                <p>
                    Latest controlled documents your office has already received —
                    <strong>{{ $officeName }}</strong>.
                </p>
            </div>
            @if(($pendingTotal ?? 0) > 0)
                <div class="ofi-header-actions">
                    <a href="{{ route('dcs.office.incoming', absolute: false) }}" class="ofi-btn primary">
                        <i class="fa-solid fa-inbox"></i>
                        {{ $pendingTotal }} to receive
                    </a>
                </div>
            @endif
        </div>

        @if(session('success'))
            <div class="ofi-alert ok">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="ofi-alert err">{{ session('error') }}</div>
        @endif
        @if(session('info'))
            <div class="ofi-alert ok">{{ session('info') }}</div>
        @endif

        @unless(auth()->user()?->enableTopTabs() ?? true)
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
                >
                    <span>{{ $group['label'] }}</span>
                    <span class="ofi-doc-nav-count">{{ $group['count'] }}</span>
                </button>
            @endforeach
        </nav>
        @endunless

        <div class="ofi-card ofi-list-card" style="position:relative;" wire:loading.class="is-loading">
            <div class="dcs-loading-overlay" wire:loading.flex wire:target="selectType,previousPage,nextPage,gotoPage">
                <div class="dcs-loading-spinner" aria-hidden="true"></div>
                <h4>Loading masterlist…</h4>
                <p>Fetching received documents.</p>
            </div>
            <div class="ofi-doc-panel-head">
                <h2>{{ $activeLabel }}</h2>
                <span>{{ $activeCount }} {{ $activeCount === 1 ? 'document' : 'documents' }}</span>
            </div>

            @if($activeCount < 1)
                <div class="ofi-doc-empty ofi-list-empty" role="status">
                    <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
                    @if($total < 1)
                        <strong>No received documents yet</strong>
                        <p>Mark incoming distributions as received first. They appear here after your office confirms receipt.</p>
                    @else
                        <strong>No {{ strtolower($activeLabel) }} documents</strong>
                        <p>Your office has other received types, but none under <strong>{{ $activeLabel }}</strong>.</p>
                    @endif
                </div>
            @else
                <div class="ofi-table-wrap ofi-list-table-wrap">
                    <table class="ofi-table">
                        <thead>
                            <tr>
                                <th style="width:160px;">Document No.</th>
                                <th style="width:72px;">Rev.</th>
                                <th>Document Title</th>
                                <th style="width:140px;">Effectivity Date</th>
                                <th style="width:100px;">Pages</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr wire:key="masterlist-{{ (int) ($row['request_id'] ?? 0) }}-{{ $row['doc_no'] }}-{{ $row['rev_no'] }}">
                                    <td class="ofi-nowrap">{{ $row['doc_no'] !== '' ? $row['doc_no'] : '—' }}</td>
                                    <td>{{ $row['rev_no'] }}</td>
                                    <td class="ofi-title-cell">{{ $row['doc_title'] !== '' ? $row['doc_title'] : '—' }}</td>
                                    <td class="ofi-nowrap">{{ $row['effectivity_date'] ?? '—' }}</td>
                                    <td>{{ ($row['pages'] ?? '') !== '' ? $row['pages'] : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($rows->hasPages())
                    <div class="ofi-pagination" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 4px 4px;flex-wrap:wrap;">
                        <span style="font-size:12.5px;color:#64748b;">
                            Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }}
                        </span>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <button type="button" class="ofi-btn" wire:click="previousPage" @disabled($rows->onFirstPage())>Previous</button>
                            <span style="font-size:12.5px;color:#475569;">Page {{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
                            <button type="button" class="ofi-btn" wire:click="nextPage" @disabled(! $rows->hasMorePages())>Next</button>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
