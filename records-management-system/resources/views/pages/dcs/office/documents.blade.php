<?php

use App\Helpers\DistributionRetrievalHelper;
use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Document Inventory — CSPC DCS')] class extends Component {
    #[Url]
    public string $type = 'all';

    /** @var array<int, bool> */
    public array $showOldVersions = [];

    public function mount(): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();

        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            session()->flash(
                'info',
                'Office document lists are available to each office. RFIO can browse the full inventory in Database and Reports.'
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
            $this->showOldVersions = [];
        }
    }

    public function markReceived(int $requestId): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
        $result = OfficeIntakeHelper::markOfficeDocumentReceived($requestId);
        session()->flash($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function toggleOldVersions(int $requestId): void
    {
        $this->showOldVersions[$requestId] = empty($this->showOldVersions[$requestId]);
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

        $oldVersionsByRequest = [];
        foreach ($rows as $row) {
            $rid = (int) ($row['request_id'] ?? 0);
            if ($rid > 0 && ! empty($this->showOldVersions[$rid])) {
                $oldVersionsByRequest[$rid] = DistributionRetrievalHelper::listOldVersionsForOffice($rid);
            }
        }

        return [
            'groups' => $groups,
            'total' => $total,
            'activeType' => $active,
            'activeLabel' => $activeLabel,
            'activeCount' => $activeCount,
            'rows' => $rows,
            'oldVersionsByRequest' => $oldVersionsByRequest,
            'officeName' => auth()->user()?->details?->office?->office_name
                ?? auth()->user()?->details?->office?->office_code
                ?? 'Your office',
            'notices' => DistributionRetrievalHelper::listClientNotices(),
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner ofi-inner-wide">
        <div class="ofi-header">
            <div>
                <h1>Document Inventory</h1>
                <p>
                    Controlled documents distributed to
                    <strong>{{ $officeName }}</strong>.
                    Collect new copies at the Records Office, return superseded paper copies for the Obsolete stamp,
                    and use Accept / Acknowledge Receipt if the counter update was missed.
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

        @foreach($notices as $notice)
            <div class="ofi-alert dr-client-notice is-{{ $notice['type'] }}">
                <strong>
                    @if($notice['type'] === 'exchange')
                        Exchange reminder
                    @elseif($notice['type'] === 'retrieval')
                        Audit warning
                    @elseif($notice['type'] === 'verify')
                        Waiting for Records Office
                    @else
                        Pending collection
                    @endif
                </strong>
                <p>{{ $notice['text'] }}</p>
            </div>
        @endforeach

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
                    title="{{ $group['count'] < 1 ? 'No ' . $group['label'] . ' documents yet' : $group['count'] . ' ' . $group['label'] }}"
                >
                    <span>{{ $group['label'] }}</span>
                    <span class="ofi-doc-nav-count">{{ $group['count'] }}</span>
                </button>
            @endforeach
        </nav>
        @endunless

        <div class="ofi-card" style="position:relative;" wire:loading.class="is-loading">
            <div class="dcs-loading-overlay" wire:loading.flex wire:target="selectType,markReceived,toggleOldVersions">
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
                        <p>
                            No controlled documents list your office in Document Distribution yet.
                        </p>
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
                                <th style="width:72px;">Item No.</th>
                                <th style="width:140px;">Document No.</th>
                                <th style="width:64px;">Rev.</th>
                                <th>Document Title</th>
                                <th style="width:100px;">Total Copy</th>
                                <th style="width:140px;">Distribution Status</th>
                                <th style="width:240px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                @php $rid = (int) ($row['request_id'] ?? 0); @endphp
                                <tr wire:key="inv-{{ $rid }}-{{ $row['item_no'] }}">
                                    <td>{{ $row['item_no'] }}</td>
                                    <td>{{ $row['doc_no'] !== '' ? $row['doc_no'] : '—' }}</td>
                                    <td>{{ $row['rev_no'] }}</td>
                                    <td>
                                        {{ $row['doc_title'] !== '' ? $row['doc_title'] : '—' }}
                                        @if(($row['old_version_label'] ?? '') !== '' && ($row['copy_retrieval_status'] ?? '') === 'pending')
                                            <span class="ofi-receive-meta">Bring {{ $row['old_version_label'] }} for exchange</span>
                                        @endif
                                    </td>
                                    <td>{{ max(1, (int) ($row['copies'] ?? 1)) }}</td>
                                    <td>
                                        @if(($row['distribution_status'] ?? '') === 'distributed' || !empty($row['received_at']))
                                            <span class="ofi-status-pill is-received">Distributed</span>
                                        @else
                                            <span class="ofi-status-pill is-pending">Pending Pickup</span>
                                        @endif
                                        @if(!empty($row['awaiting_verify']))
                                            <span class="ofi-receive-meta">Acknowledgement awaiting Records Office verification</span>
                                        @endif
                                    </td>
                                    <td class="ofi-receive-cell">
                                        <div class="ofi-inv-actions">
                                            @if(!empty($row['can_receive']) && $rid > 0)
                                                <button
                                                    type="button"
                                                    class="ofi-btn ofi-btn-sm primary"
                                                    wire:click="markReceived({{ $rid }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="markReceived"
                                                >
                                                    Accept / Acknowledge Receipt
                                                </button>
                                            @elseif(!empty($row['awaiting_verify']))
                                                <span class="ofi-receive-meta">Records Office will confirm the wet signature on QMS-FM-082.</span>
                                            @elseif(!empty($row['received_at']))
                                                <span class="ofi-receive-meta">
                                                    {{ ($row['received_by_name'] ?? '') !== '' ? $row['received_by_name'] : 'Your office' }}
                                                    · {{ $row['received_at'] }}
                                                </span>
                                            @endif

                                            @if($rid > 0 && (int) ($row['rev_no'] ?? 0) > 0)
                                                <button
                                                    type="button"
                                                    class="ofi-btn ofi-btn-sm"
                                                    wire:click="toggleOldVersions({{ $rid }})"
                                                >
                                                    {{ !empty($showOldVersions[$rid]) ? 'Hide Old Version Status' : 'Show Old Version Status' }}
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @if($rid > 0 && !empty($showOldVersions[$rid]))
                                    <tr class="ofi-old-version-row" wire:key="inv-old-{{ $rid }}">
                                        <td colspan="7">
                                            <div class="ofi-old-version-panel">
                                                <strong>Previous version status</strong>
                                                @php $oldRows = $oldVersionsByRequest[$rid] ?? []; @endphp
                                                @if($oldRows === [])
                                                    <p class="ofi-receive-meta">No previous versions were distributed to your office.</p>
                                                @else
                                                    <table class="ofi-table ofi-old-version-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Document Title</th>
                                                                <th style="width:80px;">Rev.</th>
                                                                <th style="width:100px;">Total Copy</th>
                                                                <th style="width:140px;">Status</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach($oldRows as $old)
                                                                <tr>
                                                                    <td>{{ $old['doc_title'] }}</td>
                                                                    <td>{{ $old['rev_no'] }}</td>
                                                                    <td>{{ $old['total_copies'] }}</td>
                                                                    <td>
                                                                        <span class="ofi-status-pill {{ $old['status'] === 'returned' ? 'is-received' : 'is-warn' }}">
                                                                            {{ $old['status_label'] }}
                                                                        </span>
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
