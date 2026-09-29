<?php

use App\Helpers\DistributionRetrievalHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    public string $search = '';
    public string $docType = '';
    /** @var array<string, int> */
    public array $cardRev = [];
    /** @var array<int, bool> */
    public array $expandedCards = [];
    public int $focusRequestId = 0;

    public bool $handoverOpen = false;
    public int $handoverId = 0;
    public bool $signatureVerified = false;
    public bool $stillAtRecordsOffice = false;
    public string $distributionStatus = DistributionRetrievalHelper::DIST_PENDING;
    public string $retrievalStatus = DistributionRetrievalHelper::RET_NA;
    public string $error = '';
    public string $success = '';

    public function mount(): void
    {
        RegisterQueryHelper::assertFullDcsUser('reports');

        $focus = (int) request()->query('focus', 0);
        if ($focus > 0) {
            $this->focusRequestId = $focus;
            $this->docType = 'all';
            $this->expandedCards[$focus] = true;
        }

        $verify = (int) request()->query('verify', 0);
        if ($verify > 0) {
            $this->docType = $this->docType !== '' ? $this->docType : 'all';
            $this->openHandover($verify);
        }
    }

    public function selectDocType(string $type): void
    {
        $allowed = array_column(DistributionRetrievalHelper::docTypeFilters(), 'key');
        if (! in_array($type, $allowed, true)) {
            return;
        }
        $this->docType = $type;
        $this->error = '';
        $this->focusRequestId = 0;
    }

    public function updatedSearch(): void
    {
        $this->error = '';
        if (trim($this->search) !== '' && $this->docType === '') {
            $this->docType = 'all';
        }
    }

    public function setCardRevision(string $docKey, int|string $revNo): void
    {
        $this->cardRev[$docKey] = (int) $revNo;
        $this->expandedCards = [];
    }

    public function toggleExpand(int $requestId): void
    {
        $this->expandedCards[$requestId] = empty($this->expandedCards[$requestId]);
    }

    public function focusCard(int $requestId): void
    {
        $this->focusRequestId = $requestId;
        $this->expandedCards[$requestId] = true;
        if ($this->docType === '') {
            $this->docType = 'all';
        }
        $this->dispatch('dr-scroll-to-card', id: $requestId);
    }

    public function openHandover(int $id): void
    {
        RegisterQueryHelper::assertFullDcsUser('reports');
        $payload = DistributionRetrievalHelper::officeRowPayload($id);
        if (! $payload) {
            $this->error = 'That office tracking row was not found.';

            return;
        }

        $this->handoverId = $id;
        $this->signatureVerified = (bool) $payload['physical_signature_verified'];
        $this->stillAtRecordsOffice = false;
        $this->distributionStatus = (string) $payload['distribution_status'];
        $this->retrievalStatus = (string) $payload['copy_retrieval_status'];
        $this->handoverOpen = true;
        $this->error = '';
        $this->success = '';
        $this->focusRequestId = (int) ($payload['request_id'] ?? 0);
        if ($this->focusRequestId > 0) {
            $this->expandedCards[$this->focusRequestId] = true;
        }
    }

    public function closeHandover(): void
    {
        $this->handoverOpen = false;
        $this->handoverId = 0;
        $this->signatureVerified = false;
        $this->stillAtRecordsOffice = false;
    }

    public function saveHandover(): void
    {
        $result = DistributionRetrievalHelper::handoverUpdate(
            $this->handoverId,
            $this->signatureVerified,
            $this->distributionStatus,
            $this->retrievalStatus
        );
        if (! $result['ok']) {
            $this->error = $result['message'];

            return;
        }
        $this->success = $result['message'];
        $this->closeHandover();
    }

    public function sendNotDistributed(): void
    {
        if (! $this->stillAtRecordsOffice) {
            $this->error = 'Check the box confirming the document is still at the Records Office and the D&R form has no signature from your office recipients.';

            return;
        }

        $result = DistributionRetrievalHelper::rejectClientAcknowledgement($this->handoverId);
        $this->error = $result['ok'] ? '' : $result['message'];
        $this->success = $result['ok'] ? $result['message'] : '';
        if ($result['ok']) {
            $this->closeHandover();
        }
    }

    public function with(): array
    {
        $search = trim($this->search);
        $cards = DistributionRetrievalHelper::listAdminCards(
            $search !== '' ? $search : null,
            $this->docType !== '' ? $this->docType : null,
            $this->cardRev
        );

        return [
            'docTypes' => DistributionRetrievalHelper::docTypeFilters(),
            'alerts' => DistributionRetrievalHelper::listVerificationAlerts(),
            'cards' => $cards,
            'isSearching' => $search !== '',
            'handover' => $this->handoverId > 0
                ? DistributionRetrievalHelper::officeRowPayload($this->handoverId)
                : null,
        ];
    }
}; ?>

<main
    class="rpt-page dr-page"
    id="rptPage"
    x-data
    x-on:dr-scroll-to-card.window="
        $nextTick(() => {
            const el = document.getElementById('dr-card-' + $event.detail.id);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        })
    "
>
    <header class="rpt-hdr">
        <div>
            <div class="rpt-crumb">Document Control System / Generate Report / Monitoring Reports /<span> Distribution &amp; Retrieval</span></div>
            <h1>Distribution &amp; Retrieval Monitoring</h1>
        </div>
    </header>

    <nav class="rpt-subs visible" aria-label="Report types">
        <a class="rpt-sub" href="{{ route('dcs.reports.monitoring', ['sub' => 'internal_docs'], absolute: false) }}">Internal</a>
        <a class="rpt-sub" href="{{ route('dcs.reports.monitoring', ['sub' => 'external_docs'], absolute: false) }}">External</a>
        <a class="rpt-sub" href="{{ route('dcs.reports.monitoring', ['sub' => 'internal_forms'], absolute: false) }}">Internal Forms</a>
        <a class="rpt-sub" href="{{ route('dcs.reports.monitoring', ['sub' => 'forms'], absolute: false) }}">Forms</a>
        <a class="rpt-sub" href="{{ route('dcs.reports.monitoring', ['sub' => 'logbooks'], absolute: false) }}">Logbooks</a>
        <a class="rpt-sub" href="{{ route('dcs.reports.monitoring', ['sub' => 'drf'], absolute: false) }}">DRF</a>
        <a class="rpt-sub" href="{{ route('dcs.reports.monitoring', ['sub' => 'dcn'], absolute: false) }}">DCN</a>
        <a class="rpt-sub active" href="{{ route('dcs.reports.distributionRetrieval', absolute: false) }}">Distribution &amp; Retrieval</a>
    </nav>

    @if($success)
        <div class="dr-flash dr-flash-ok">{{ $success }}</div>
    @endif
    @if($error)
        <div class="dr-flash dr-flash-err">{{ $error }}</div>
    @endif

    @foreach($alerts as $alert)
        <button
            type="button"
            class="dr-alert dr-alert-click"
            role="alert"
            wire:click="focusCard({{ (int) $alert['request_id'] }})"
        >
            <div>
                <strong>Verification required</strong>
                <p>{{ $alert['message'] }}</p>
            </div>
            <span class="dr-alert-go">View card <i class="fa-solid fa-arrow-right"></i></span>
        </button>
    @endforeach

    <div class="dr-toolbar">
        <div class="dr-search-wrap">
            <label class="dr-search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search document title, number, or office"
                    aria-label="Search tracking cards"
                >
            </label>
            <button
                type="button"
                class="rpt-btn rpt-btn-outline dr-search-btn"
                wire:click="$refresh"
                wire:loading.attr="disabled"
            >
                <i class="fa-solid fa-magnifying-glass" wire:loading.remove wire:target="search"></i>
                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="search"></i>
                Search
            </button>
        </div>
        <span class="dr-toolbar-count">
            @if($docType === '')
                Select a document type to load cards
            @else
                {{ count($cards) }} {{ count($cards) === 1 ? 'document' : 'documents' }}
            @endif
        </span>
    </div>

    <nav class="rpt-subs rpt-subs-secondary visible dr-type-filters" aria-label="Document type filters">
        @foreach($docTypes as $type)
            <button
                type="button"
                class="rpt-sub {{ $docType === $type['key'] ? 'active' : '' }}"
                wire:click="selectDocType('{{ $type['key'] }}')"
                wire:loading.attr="disabled"
                wire:target="selectDocType"
            >{{ $type['label'] }}</button>
        @endforeach
    </nav>

    <div wire:loading.flex wire:target="selectDocType,search,setCardRevision" class="dr-loading">
        <div class="rpt-loading-spinner" aria-hidden="true"></div>
        <span>Loading tracking cards…</span>
    </div>

    @if($docType === '')
        <div class="rpt-state rpt-state-pick">
            <div class="rpt-state-icon"><i class="fa-solid fa-filter"></i></div>
            <h4>Choose a document type</h4>
            <p>Click All, Internal, External, Internal Forms, Forms, or Logbooks above to load Distribution &amp; Retrieval tracking cards.</p>
        </div>
    @else
        @forelse($cards as $card)
            @php
                $officeCount = count($card['offices']);
                $showAll = $isSearching || ! empty($expandedCards[$card['request_id']]);
                $visibleOffices = $showAll ? $card['offices'] : array_slice($card['offices'], 0, 5);
                $hiddenCount = max(0, $officeCount - 5);
            @endphp
            <article
                class="dr-card {{ $focusRequestId === (int) $card['request_id'] ? 'is-focused' : '' }}"
                id="dr-card-{{ $card['request_id'] }}"
                wire:key="dr-card-{{ $card['request_id'] }}-{{ $card['selected_rev'] }}"
            >
                <header class="dr-card-head">
                    <div class="dr-card-head-main">
                        <h2>{{ $card['doc_title'] }}</h2>
                        <p>
                            {{ $card['doc_no'] !== '' ? $card['doc_no'] : 'No document number' }}
                            @if($card['pending_retrieval'] > 0)
                                · <span class="dr-warn-inline">{{ $card['pending_retrieval'] }} pending retrieval</span>
                            @endif
                        </p>
                        @if(count($card['available_revisions']) > 1)
                            <label class="dr-rev-select">
                                <span>Revision</span>
                                <select
                                    wire:change="setCardRevision('{{ $card['doc_key'] }}', $event.target.value)"
                                >
                                    @foreach($card['available_revisions'] as $rev)
                                        <option value="{{ $rev }}" @selected((int) $card['selected_rev'] === (int) $rev)>Rev {{ $rev }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @else
                            <span class="dr-rev-badge">Rev {{ $card['rev_no'] }}</span>
                        @endif
                    </div>
                    <div class="dr-card-stats">
                        <div class="dr-copies">
                            <span>{{ $card['total_copies'] }}</span>
                            <small>Total copies</small>
                        </div>
                        <div class="dr-copies">
                            <span>{{ $card['office_count'] }}</span>
                            <small>Total of office</small>
                        </div>
                        <div class="dr-copies dr-copies-muted">
                            <span>{{ $card['distributed_copies'] }}/{{ $card['total_copies'] }}</span>
                            <small>Distributed</small>
                        </div>
                    </div>
                </header>
                <div class="dr-table-wrap">
                    <table class="dr-table">
                        <thead>
                            <tr>
                                <th>Department / Office Name</th>
                                <th>Number of Copies</th>
                                <th>Distribution Status</th>
                                <th>Copy Retrieval Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($visibleOffices as $office)
                                <tr class="{{ !empty($office['verification_required']) ? 'is-verify' : '' }}">
                                    <td>
                                        <strong>{{ $office['office_name'] }}</strong>
                                        @if($office['office_code'] !== '')
                                            <span class="dr-muted">{{ $office['office_code'] }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $office['copies'] }}</td>
                                    <td>
                                        <span class="dr-pill {{ $office['distribution_status'] === 'distributed' ? 'is-done' : 'is-pending' }}">
                                            {{ $office['distribution_status'] === 'distributed' ? 'Distributed' : 'Pending Pickup' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="dr-pill {{ $office['copy_retrieval_status'] === 'pending' ? 'is-warn' : ($office['copy_retrieval_status'] === 'retrieved' ? 'is-done' : 'is-na') }}">
                                            @if($office['copy_retrieval_status'] === 'pending')
                                                Pending Retrieval
                                            @elseif($office['copy_retrieval_status'] === 'retrieved')
                                                Retrieved
                                            @else
                                                N/A
                                            @endif
                                        </span>
                                        @if($office['copy_retrieval_status'] === 'pending' && $office['old_version_label'] !== '')
                                            <span class="dr-muted">{{ $office['old_version_label'] }}</span>
                                        @endif
                                    </td>
                                    <td class="dr-actions">
                                        @if(!empty($office['verification_required']))
                                            <button type="button" class="rpt-btn rpt-btn-primary" wire:click="openHandover({{ $office['id'] }})">
                                                Verify
                                            </button>
                                        @else
                                            <button type="button" class="rpt-btn rpt-btn-outline" wire:click="openHandover({{ $office['id'] }})">
                                                Handover / Update
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(! $isSearching && $hiddenCount > 0)
                    <div class="dr-show-more">
                        <button type="button" class="rpt-btn rpt-btn-outline" wire:click="toggleExpand({{ $card['request_id'] }})">
                            @if(!empty($expandedCards[$card['request_id']]))
                                Show less
                            @else
                                Show more ({{ $hiddenCount }} more {{ $hiddenCount === 1 ? 'office' : 'offices' }})
                            @endif
                        </button>
                    </div>
                @endif
            </article>
        @empty
            <div class="rpt-state rpt-state-pick">
                <div class="rpt-state-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                <h4>No tracking cards found</h4>
                <p>
                    @if($isSearching)
                        No documents match your search in this document type.
                    @else
                        Register a document in this type, select target recipient offices, and save. A card is created automatically.
                    @endif
                </p>
            </div>
        @endforelse
    @endif

    @if($handoverOpen && $handover)
        <div class="dr-modal-overlay" wire:click="closeHandover">
            <div class="dr-modal" role="dialog" aria-modal="true" aria-labelledby="drHandoverTitle" wire:click.stop>
                <header class="dr-modal-head">
                    <h3 id="drHandoverTitle">
                        {{ !empty($handover['verification_required']) || !empty($handover['client_acknowledged']) ? 'Verify acknowledgement' : 'Handover / Update' }}
                    </h3>
                    <button type="button" class="dr-modal-close" wire:click="closeHandover" aria-label="Close">&times;</button>
                </header>
                <div class="dr-modal-body">
                    <dl class="dr-meta">
                        <div><dt>Doc. number</dt><dd>{{ $handover['doc_no'] }}</dd></div>
                        <div><dt>Doc. Title</dt><dd>{{ $handover['doc_title'] }} (Rev. {{ $handover['rev_no'] }})</dd></div>
                        <div><dt>Recipient</dt><dd>{{ $handover['recipient'] }} · {{ $handover['copies'] }} {{ $handover['copies'] === 1 ? 'copy' : 'copies' }}</dd></div>
                    </dl>

                    <fieldset class="dr-fieldset">
                        <legend>Distribution Status</legend>
                        <label>
                            <input type="radio" wire:model="distributionStatus" value="distributed">
                            Distributed (Handover Complete)
                        </label>
                        <label>
                            <input type="radio" wire:model="distributionStatus" value="pending_pickup">
                            Pending Pickup (Awaiting Recipient)
                        </label>
                    </fieldset>

                    <fieldset class="dr-fieldset">
                        <legend>Copy Retrieval Status (Old Version)</legend>
                        <label>
                            <input type="radio" wire:model="retrievalStatus" value="pending">
                            Pending Retrieval (Unreturned Paper Copy) — Recipient has not yet turned in old physical copy.
                        </label>
                        <label>
                            <input type="radio" wire:model="retrievalStatus" value="retrieved">
                            Retrieved &amp; Stamped "OBSOLETE" — Physical copy returned, red Obsolete stamp applied.
                        </label>
                        <label>
                            <input type="radio" wire:model="retrievalStatus" value="na">
                            N/A (Initial New Document Issue) — No prior paper version exists for this controlled document.
                        </label>
                    </fieldset>

                    <label class="dr-check">
                        <input type="checkbox" wire:model="signatureVerified">
                        <span>
                            <strong>Physical Wet Signature Verified</strong>
                            I confirm the recipient physically signed the printed D&amp;R Form (QMS-FM-082).
                            Physical signature verified on paper log maintained at Records Office. (No digital code generated.)
                        </span>
                    </label>

                    @if(!empty($handover['verification_required']) || !empty($handover['client_acknowledged']))
                        <label class="dr-check dr-check-warn">
                            <input type="checkbox" wire:model="stillAtRecordsOffice">
                            <span>
                                The document is still at the record office and the DnR form has no signature form your office recipients.
                            </span>
                        </label>
                    @endif
                </div>
                <footer class="dr-modal-foot">
                    <button type="button" class="rpt-btn rpt-btn-outline" wire:click="closeHandover">Cancel</button>
                    @if(!empty($handover['verification_required']) || !empty($handover['client_acknowledged']))
                        <button
                            type="button"
                            class="rpt-btn rpt-btn-outline"
                            wire:click="sendNotDistributed"
                            wire:loading.attr="disabled"
                            title="Notify the client that the document has not yet been distributed"
                        >
                            Send
                        </button>
                    @endif
                    <button type="button" class="rpt-btn rpt-btn-primary" wire:click="saveHandover" wire:loading.attr="disabled">Save status</button>
                </footer>
            </div>
        </div>
    @endif
    @if($focusRequestId > 0)
        <div
            x-data
            x-init="$nextTick(() => {
                const el = document.getElementById('dr-card-{{ $focusRequestId }}');
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            })"
        ></div>
    @endif
</main>
