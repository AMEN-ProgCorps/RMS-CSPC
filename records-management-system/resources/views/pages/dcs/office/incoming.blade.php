<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Incoming Documents — CSPC DCS')] class extends Component {
    #[Url]
    public string $type = 'all';

    public function mount(): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();

        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            session()->flash(
                'info',
                'Incoming distributions are handled by each receiving office.'
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
        }
    }

    public function acknowledgeReceipt(int $requestId): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
        $result = OfficeIntakeHelper::markOfficeDocumentReceived($requestId);
        $pending = OfficeIntakeHelper::officeDocumentTotal(null, 'pending');

        $this->dispatch('office-incoming-count', count: $pending);
        $this->dispatch('dcs-toast', message: $result['message'], type: $result['ok'] ? 'success' : 'error');
    }

    /** @deprecated Use acknowledgeReceipt — kept for any leftover wire:click targets. */
    public function markReceived(int $requestId): void
    {
        $this->acknowledgeReceipt($requestId);
    }

    public function with(): array
    {
        $groups = OfficeIntakeHelper::officeDocumentGroups(null, false, 'incoming');
        $total = OfficeIntakeHelper::officeDocumentTotal(null, 'incoming');
        $pendingAction = OfficeIntakeHelper::officeDocumentTotal(null, 'pending');
        $active = $this->type;
        $keys = array_column($groups, 'key');

        if ($active !== 'all' && ! in_array($active, $keys, true)) {
            $active = 'all';
        }

        $activeLabel = $active === 'all'
            ? 'All'
            : OfficeIntakeHelper::documentGroupLabel($active);

        $rows = OfficeIntakeHelper::listOfficeDocuments($active, null, 'incoming');
        $activeCount = $active === 'all'
            ? $total
            : (int) (collect($groups)->firstWhere('key', $active)['count'] ?? count($rows));

        return [
            'groups' => $groups,
            'total' => $total,
            'pendingAction' => $pendingAction,
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
                <h1>Incoming Documents</h1>
                <p>
                    Controlled documents distributed to
                    <strong>{{ $officeName }}</strong>.
                    Use <strong>Accept / Acknowledge Receipt</strong> if Records missed the counter update.
                    Distributed documents stay listed with an Appraisal action.
                    Confirmed distributions also appear on
                    <a href="{{ route('dcs.office.documents', ['type' => 'all'], absolute: false) }}">Masterlist</a>.
                </p>
            </div>
        </div>

        @if(session('success'))
            <div class="ofi-alert ok">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="ofi-alert err">{{ session('error') }}</div>
        @endif
        @if(session('info'))
            <div class="ofi-alert">{{ session('info') }}</div>
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

        <div class="ofi-card" style="position:relative;" wire:loading.class="is-loading">
            <div class="dcs-loading-overlay" wire:loading.flex wire:target="selectType,acknowledgeReceipt,markReceived">
                <div class="dcs-loading-spinner" aria-hidden="true"></div>
                <h4>Loading incoming…</h4>
                <p>Fetching documents for your office.</p>
            </div>
            <div class="ofi-doc-panel-head">
                <h2>{{ $activeLabel }}</h2>
                <span>{{ $activeCount }} {{ $activeCount === 1 ? 'document' : 'documents' }}</span>
            </div>

            @if($activeCount < 1)
                <div class="ofi-doc-empty" role="status">
                    <i class="fa-solid fa-inbox" aria-hidden="true"></i>
                    <strong>Nothing in Incoming</strong>
                    <p>
                        When Document Control distributes a document to your office, it will appear here.
                    </p>
                </div>
            @else
                <div class="ofi-table-wrap">
                    <table class="ofi-table">
                        <thead>
                            <tr>
                                <th style="width:160px;">Document No.</th>
                                <th style="width:72px;">Rev.</th>
                                <th>Document Title</th>
                                <th style="width:140px;">Effectivity Date</th>
                                <th style="width:100px;">Pages</th>
                                <th style="width:220px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                @php
                                    $action = $row['incoming_action'] ?? 'acknowledge';
                                    $rid = (int) ($row['request_id'] ?? 0);
                                @endphp
                                <tr wire:key="incoming-{{ $rid }}">
                                    <td>{{ $row['doc_no'] !== '' ? $row['doc_no'] : '—' }}</td>
                                    <td>{{ $row['rev_no'] }}</td>
                                    <td>{{ $row['doc_title'] !== '' ? $row['doc_title'] : '—' }}</td>
                                    <td>{{ $row['effectivity_date'] ?? '—' }}</td>
                                    <td>{{ ($row['pages'] ?? '') !== '' ? $row['pages'] : '—' }}</td>
                                    <td class="ofi-receive-cell">
                                        @if($action === 'appraisal' && $rid > 0)
                                            <button
                                                type="button"
                                                class="ofi-btn ofi-btn-sm"
                                                disabled
                                                title="Appraisal is not available yet"
                                            >
                                                Appraisal
                                            </button>
                                        @elseif($action === 'pending_verification')
                                            <span class="ofi-status-pill is-pending" title="Waiting for Records Office wet-signature verification">
                                                Pending admin verification
                                            </span>
                                        @elseif(!empty($row['can_acknowledge']) && $rid > 0)
                                            <button
                                                type="button"
                                                class="ofi-btn ofi-btn-sm primary"
                                                wire:click="acknowledgeReceipt({{ $rid }})"
                                                wire:loading.attr="disabled"
                                                wire:target="acknowledgeReceipt({{ $rid }})"
                                            >
                                                <span wire:loading.remove wire:target="acknowledgeReceipt({{ $rid }})">
                                                    Accept / Acknowledge Receipt
                                                </span>
                                                <span wire:loading wire:target="acknowledgeReceipt({{ $rid }})">
                                                    Sending…
                                                </span>
                                            </button>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
