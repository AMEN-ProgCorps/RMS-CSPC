<?php

/**
 * DCS file: Documents sent to this office that are waiting to be received.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use App\Services\RdpIntakeService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
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

    #[On('office-incoming-refresh')]
    public function refreshIncoming(): void
    {
    }

    public function markReceived(int $requestId): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
        $result = OfficeIntakeHelper::markOfficeDocumentReceived($requestId);
        $pending = OfficeIntakeHelper::officeIncomingAttentionTotal();

        $this->dispatch('office-incoming-count', count: $pending);
        $this->dispatch('dcs-toast', message: $result['message'], type: $result['ok'] ? 'success' : 'error');
    }

    public function with(): array
    {
        $groups = OfficeIntakeHelper::officeDocumentGroups(null, false, 'pending');
        $total = OfficeIntakeHelper::officeDocumentTotal(null, 'pending');
        $active = $this->type;
        $keys = array_column($groups, 'key');

        if ($active !== 'all' && ! in_array($active, $keys, true)) {
            $active = 'all';
        }

        $activeLabel = $active === 'all'
            ? 'All'
            : OfficeIntakeHelper::documentGroupLabel($active);

        $rows = OfficeIntakeHelper::listOfficeDocuments($active, null, 'pending');
        $canAppraise = (bool) (auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_access_rdp);
        $receivedRows = $canAppraise
            ? OfficeIntakeHelper::listOfficeDocuments($active, null, 'received_for_appraisal')
            : [];
        $appraisalUrls = $canAppraise
            ? RdpIntakeService::appraisalUrlsForReceivedOfficeDocs(
                RegisterQueryHelper::currentOfficeCode(),
                $receivedRows
            )
            : [];
        $seen = [];
        foreach ($rows as $row) {
            $seen[(int) ($row['request_id'] ?? 0)] = true;
        }
        foreach ($receivedRows as $row) {
            $requestId = (int) ($row['request_id'] ?? 0);
            $docNo = trim((string) ($row['doc_no'] ?? ''));
            $url = ($docNo !== '' ? ($appraisalUrls[$docNo] ?? null) : null)
                ?? ($appraisalUrls['request:'.$requestId] ?? null)
                ?? ($appraisalUrls[RdpIntakeService::documentCodeForRequest($requestId, $docNo)] ?? null);
            if ($requestId < 1 || isset($seen[$requestId]) || $url === null) {
                continue;
            }
            $row['appraisal_url'] = $url;
            $row['can_receive'] = false;
            $rows[] = $row;
            $seen[$requestId] = true;
        }
        if ($active === 'all') {
            $total = count($rows);
        }
        $activeCount = count($rows);

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
                <h1>Incoming Documents</h1>
                <p>
                    Controlled documents distributed to
                    <strong>{{ $officeName }}</strong>
                    that are waiting to be marked received.
                    @if(auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_access_rdp)
                        After receipt, a Save and Appraise document stays here until Appraisal is finished. A plain save moves to the
                    @else
                        After receipt, they move to the
                    @endif
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
        @if(collect($rows)->contains(fn ($row) => !empty($row['appraisal_url'])))
            <div class="ofi-alert">Save and Appraise documents stay on Incoming, and the notice stays, until you finish Appraisal.</div>
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
            <div class="dcs-loading-overlay" wire:loading.flex wire:target="selectType,markReceived">
                <div class="dcs-loading-spinner" aria-hidden="true"></div>
                <h4>Loading incoming…</h4>
                <p>Fetching documents awaiting receipt.</p>
            </div>
            <div class="ofi-doc-panel-head">
                <h2>{{ $activeLabel }}</h2>
                <span>{{ $activeCount }} {{ $activeCount === 1 ? 'document' : 'documents' }}</span>
            </div>

            @if($activeCount < 1)
                <div class="ofi-doc-empty" role="status">
                    <i class="fa-solid fa-inbox" aria-hidden="true"></i>
                    <strong>Nothing waiting to receive</strong>
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
                                <th style="width:180px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr wire:key="incoming-{{ (int) ($row['request_id'] ?? 0) }}">
                                    <td>{{ $row['doc_no'] !== '' ? $row['doc_no'] : '—' }}</td>
                                    <td>{{ $row['rev_no'] }}</td>
                                    <td>{{ $row['doc_title'] !== '' ? $row['doc_title'] : '—' }}</td>
                                    <td>{{ $row['effectivity_date'] ?? '—' }}</td>
                                    <td>{{ ($row['pages'] ?? '') !== '' ? $row['pages'] : '—' }}</td>
                                    <td class="ofi-receive-cell">
                                        @if(!empty($row['appraisal_url']) && empty($row['can_receive']))
                                            <a href="{{ $row['appraisal_url'] }}" class="ofi-btn ofi-btn-sm primary">Appraisal</a>
                                        @elseif(!empty($row['can_receive']) && (int) ($row['request_id'] ?? 0) > 0)
                                            <button
                                                type="button"
                                                class="ofi-btn ofi-btn-sm primary"
                                                wire:click="markReceived({{ (int) $row['request_id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="markReceived({{ (int) $row['request_id'] }})"
                                            >
                                                <span wire:loading.remove wire:target="markReceived({{ (int) $row['request_id'] }})">
                                                    Mark as received
                                                </span>
                                                <span wire:loading wire:target="markReceived({{ (int) $row['request_id'] }})">
                                                    Receiving…
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
