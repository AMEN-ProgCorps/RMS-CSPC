<?php

use App\Helpers\DistributionRetrievalMonitorHelper;
use App\Helpers\RegisterQueryHelper;
use App\Support\ErrorDiagnosis;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Distribution & Retrieval — CSPC DCS')] class extends Component {
    #[Url]
    public string $q = '';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $type = '';

    #[Url]
    public bool $showObsolete = false;

    public ?int $openId = null;

    public function mount(): void
    {
        abort_unless(RegisterQueryHelper::canAccessDcsModule('reports'), 403);
        if (! in_array($this->status, ['all', 'awaiting', 'previous_out'], true)) {
            $this->status = 'all';
        }
    }

    public function updatedQ(): void
    {
        $this->openId = null;
    }

    public function updatedStatus(): void
    {
        if (! in_array($this->status, ['all', 'awaiting', 'previous_out'], true)) {
            $this->status = 'all';
        }
        $this->openId = null;
    }

    public function updatedType(): void
    {
        $this->type = trim($this->type);
        $this->openId = null;
    }

    public function updatedShowObsolete(): void
    {
        $this->openId = null;
    }

    public function setObsolete(string $value): void
    {
        $this->showObsolete = $value === '1';
        $this->openId = null;
    }

    public function showRevision(int $requestId): void
    {
        $this->openId = $requestId > 0 ? $requestId : null;
    }

    public function closeRevision(): void
    {
        $this->openId = null;
    }

    public function with(): array
    {
        try {
            $grouped = $this->grouped();
        } catch (\Throwable $e) {
            $diagnosed = ErrorDiagnosis::from($e);

            return $this->emptyBoard($diagnosed);
        }

        $open = null;
        $openCard = null;
        if ($this->openId) {
            foreach ($grouped['cards'] as $card) {
                foreach ($card['revisions'] as $revision) {
                    if ((int) $revision['request_id'] === (int) $this->openId) {
                        $open = $revision;
                        $openCard = $card;
                        break 2;
                    }
                }
            }
        }
        if ($open === null) {
            $this->openId = null;
        }

        return [
            'summary' => $grouped['summary'],
            'groups' => $grouped['groups'],
            'typeOptions' => $grouped['typeOptions'],
            'open' => $open,
            'openCard' => $openCard,
            'positionTotal' => count($grouped['cards']),
            'obsoleteCount' => $grouped['obsoleteCount'],
            'totalRows' => $grouped['totalRows'],
            'loadError' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyBoard(ErrorDiagnosis $diagnosed): array
    {
        $this->openId = null;

        return [
            'summary' => [
                'documents' => 0,
                'received' => 0,
                'awaiting' => 0,
                'retrieved' => 0,
                'previous_out' => 0,
            ],
            'groups' => [],
            'typeOptions' => [],
            'open' => null,
            'openCard' => null,
            'positionTotal' => 0,
            'obsoleteCount' => 0,
            'totalRows' => 0,
            'loadError' => $diagnosed,
        ];
    }

    /**
     * @return array{summary: array<string, int>, groups: list<array<string, mixed>>, typeOptions: list<array{name: string, count: int}>, cards: list<array<string, mixed>>, obsoleteCount: int, totalRows: int}
     */
    private function grouped(): array
    {
        $monitor = DistributionRetrievalMonitorHelper::monitor($this->q, $this->status);
        $cards = [];
        $obsoleteOnly = 0;
        foreach ($monitor['families'] as $family) {
            $latest = [];
            $older = [];
            foreach ($family['revisions'] as $revision) {
                if ($this->isObsolete($revision)) {
                    $older[] = $revision;
                } else {
                    $latest[] = $revision;
                }
            }
            if ($latest === []) {
                $obsoleteOnly++;
                if (! $this->showObsolete || $older === []) {
                    continue;
                }
                $lead = array_shift($older);
                $cards[] = [
                    'lead' => $lead,
                    'revisions' => array_merge([$lead], $older),
                ];
                continue;
            }
            $lead = array_shift($latest);
            $cards[] = [
                'lead' => $lead,
                'revisions' => array_merge([$lead], $latest, $older),
            ];
        }

        $buckets = [];
        foreach ($cards as $card) {
            $name = trim((string) ($card['lead']['doc_type_name'] ?? ''));
            $buckets[$name !== '' ? $name : 'Unclassified'][] = $card;
        }

        $preferred = ['Internal', 'Internal Forms', 'External', 'Forms', 'Logbooks'];
        $names = array_keys($buckets);
        usort($names, function ($a, $b) use ($preferred) {
            $ia = array_search($a, $preferred, true);
            $ib = array_search($b, $preferred, true);
            $ia = $ia === false ? 100 : $ia;
            $ib = $ib === false ? 100 : $ib;
            if ($ia !== $ib) {
                return $ia <=> $ib;
            }

            return strcasecmp($a, $b);
        });

        $typeOptions = [];
        $allGroups = [];
        foreach ($names as $name) {
            $groupCards = $buckets[$name];
            usort($groupCards, function ($a, $b) {
                $age = ($this->isObsolete($a['lead']) ? 1 : 0) <=> ($this->isObsolete($b['lead']) ? 1 : 0);
                if ($age !== 0) {
                    return $age;
                }
                $doc = strnatcasecmp((string) $a['lead']['doc_no'], (string) $b['lead']['doc_no']);

                return $doc !== 0 ? $doc : ((int) $b['lead']['revise_no']) <=> ((int) $a['lead']['revise_no']);
            });
            $typeOptions[] = ['name' => $name, 'count' => count($groupCards)];
            $allGroups[] = ['name' => $name, 'rows' => $groupCards];
        }

        $groups = $allGroups;
        $selectedType = trim($this->type);
        if ($selectedType !== '') {
            $groups = array_values(array_filter(
                $allGroups,
                fn (array $group) => strcasecmp($group['name'], $selectedType) === 0
            ));
            if ($groups === []) {
                $this->type = '';
                $groups = $allGroups;
            }
        }

        $visible = [];
        foreach ($groups as $group) {
            foreach ($group['rows'] as $card) {
                $visible[] = $card;
            }
        }

        return [
            'summary' => $monitor['summary'],
            'groups' => $groups,
            'typeOptions' => $typeOptions,
            'cards' => $visible,
            'obsoleteCount' => $obsoleteOnly,
            'totalRows' => count($cards),
        ];
    }

    /** @param  array<string, mixed>  $revision */
    private function isObsolete(array $revision): bool
    {
        return ! in_array(strtolower(trim((string) ($revision['revision_status'] ?? 'latest'))), ['', 'latest'], true);
    }

    /** @param  array<string, mixed>  $revision */
    public function revisionChoiceLabel(array $revision, bool $isLead, string $leadDocNo = ''): string
    {
        $docNo = trim((string) ($revision['doc_no'] ?? ''));
        $label = 'Rev ' . (int) ($revision['revise_no'] ?? 0);
        if ($leadDocNo !== '' && strcasecmp($docNo, $leadDocNo) !== 0) {
            $label = $docNo . ' · ' . $label;
        }
        $when = trim((string) ($revision['effectivity'] ?? ''));
        if ($when !== '') {
            $label = $when . ' · ' . $label;
        }
        if ($isLead && ! $this->isObsolete($revision)) {
            return $label . ' · Latest';
        }
        if (! $isLead && ! $this->isObsolete($revision)) {
            return $label . ' · Earlier copy';
        }

        return $label . ' · Previous';
    }
}; ?>

<div class="rpt-page">
    <header class="rpt-hdr">
        <div>
            <div class="rpt-crumb">Document Control System / Monitoring /<span> Distribution &amp; Retrieval</span></div>
            <h1>Distribution &amp; Retrieval</h1>
        </div>
    </header>

    <section class="rpt-inline-filters drt-filters">
        <div class="rpt-inline-filters-grid">
            <div class="rpt-filter-group">
                <label for="drtType">Document type</label>
                <select id="drtType" wire:model.live="type">
                    <option value="">All types</option>
                    @foreach($typeOptions as $option)
                        <option value="{{ $option['name'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="rpt-filter-group">
                <label for="drtStatus">Status</label>
                <select id="drtStatus" wire:model.live="status">
                    <option value="all">All documents</option>
                    <option value="awaiting">Waiting for receipt</option>
                    <option value="previous_out">Old copy still out</option>
                </select>
            </div>
            <div class="rpt-filter-group">
                <label for="drtSearch">Search</label>
                <input id="drtSearch" type="search" wire:model.live.debounce.300ms="q" placeholder="Document no., title, or office">
            </div>
        </div>
    </section>

    @if($loadError)
        <div class="drt-error is-{{ $loadError->kind }}" role="alert">
            <strong>{{ $loadError->kind === 'client' ? 'Client error' : 'Server error' }}</strong>
            <p>{{ $loadError->message }}</p>
            @if($loadError->reference)
                <p>Reference {{ $loadError->reference }}</p>
            @endif
        </div>
    @endif

    @forelse($groups as $group)
        <section class="rpt-results">
            <div class="rpt-results-head">
                <div class="rpt-results-meta">
                    <h3>{{ $group['name'] }}</h3>
                    <span class="rpt-results-count">{{ count($group['rows']) }} {{ count($group['rows']) === 1 ? 'document' : 'documents' }}</span>
                </div>
            </div>
            <div class="rpt-table-scroll">
                <table class="rpt-table">
                    <thead>
                        <tr>
                            <th>Document No.</th>
                            <th>Title</th>
                            <th>Subtype</th>
                            <th>Revision</th>
                            <th>Distributed</th>
                            <th>Received</th>
                            <th>Retrieved</th>
                            <th>Previous copy</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($group['rows'] as $card)
                            @php
                                $revision = $card['lead'];
                                $officeCount = count($revision['offices']);
                                $receivedCount = 0;
                                $retrievedCount = 0;
                                $pendingReturn = 0;
                                foreach ($revision['offices'] as $office) {
                                    if (! empty($office['received'])) {
                                        $receivedCount++;
                                    }
                                    if (! empty($office['retrieved'])) {
                                        $retrievedCount++;
                                    }
                                }
                                foreach (array_slice($card['revisions'], 1) as $past) {
                                    foreach ($past['offices'] as $office) {
                                        if (($office['status'] ?? '') === 'still_out') {
                                            $pendingReturn++;
                                        }
                                    }
                                }
                                $subType = trim((string) ($revision['sub_type_name'] ?? ''));
                            @endphp
                            <tr class="drt-doc">
                                <td>{{ $revision['doc_no'] }}</td>
                                <td>{{ $revision['doc_title'] !== '' ? $revision['doc_title'] : 'Untitled' }}</td>
                                <td>{{ $subType !== '' ? $subType : '—' }}</td>
                                <td>Rev {{ (int) $revision['revise_no'] }}</td>
                                <td>{{ $officeCount }}</td>
                                <td>{{ $receivedCount }}</td>
                                <td>{{ $retrievedCount }}</td>
                                <td>{{ $pendingReturn > 0 ? $pendingReturn.' pending return' : 'Clear' }}</td>
                                <td>
                                    <button type="button" class="drt-offices-btn" wire:click="showRevision({{ (int) $revision['request_id'] }})">Offices</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <section class="rpt-results">
            <p class="rpt-template-status">No distributed documents match this view.</p>
        </section>
    @endforelse

    @if($open && $openCard)
        @teleport('body')
        <div class="drt-modal" wire:keydown.escape.window="closeRevision" role="dialog" aria-modal="true" aria-labelledby="drtModalTitle">
            <button type="button" class="drt-modal-backdrop" wire:click="closeRevision" aria-label="Close offices"></button>
            <div class="drt-modal-dialog">
                <header>
                    <div>
                        <h2 id="drtModalTitle">{{ $open['doc_no'] }}</h2>
                        <p>{{ $open['doc_title'] !== '' ? $open['doc_title'] : 'Untitled' }}</p>
                    </div>
                    <button type="button" wire:click="closeRevision">Close</button>
                </header>
                @if(count($openCard['revisions']) > 1)
                    <div class="drt-copy-list" role="list">
                        @foreach($openCard['revisions'] as $index => $choice)
                            <button type="button" role="listitem" class="{{ (int) $open['request_id'] === (int) $choice['request_id'] ? 'is-current' : '' }}" wire:click="showRevision({{ (int) $choice['request_id'] }})">
                                <strong>{{ $choice['doc_title'] !== '' ? $choice['doc_title'] : 'Untitled' }}</strong>
                                <span>{{ $this->revisionChoiceLabel($choice, $index === 0, (string) $openCard['lead']['doc_no']) }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
                @php
                    $pendingInOpen = 0;
                    foreach ($open['offices'] as $office) {
                        if (($office['status'] ?? '') === 'still_out') {
                            $pendingInOpen++;
                        }
                    }
                @endphp
                @if($pendingInOpen > 0)
                    <p class="drt-modal-note">A newer copy was distributed before this one was returned. {{ $pendingInOpen }} {{ $pendingInOpen === 1 ? 'office still has' : 'offices still have' }} it. That return stays pending until Admin DCS retrieves it.</p>
                @endif
                <div class="drt-modal-table">
                    <table class="rpt-table">
                        <thead>
                            <tr>
                                <th>Office</th>
                                <th>Copies</th>
                                <th>Received</th>
                                <th>Retrieved</th>
                                <th>Previous copy</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($open['offices'] as $office)
                                <tr>
                                    <td>
                                        {{ $office['office_name'] }}
                                        @if($office['office_code'] !== '')
                                            <span class="rpt-na">{{ $office['office_code'] }}</span>
                                        @endif
                                    </td>
                                    <td>{{ (int) ($office['copies'] ?? 1) }}</td>
                                    <td>
                                        @if(! empty($office['received']))
                                            Received
                                            @if($office['received_at'] !== '')
                                                <span class="rpt-na">{{ $office['received_at'] }}</span>
                                            @endif
                                        @else
                                            <span class="rpt-na">Waiting</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if(! empty($office['retrieved']))
                                            Retrieved
                                            @if(($office['retrieved_on'] ?? '') !== '')
                                                <span class="rpt-na">{{ $office['retrieved_on'] }}</span>
                                            @endif
                                        @elseif(($office['status'] ?? '') === 'still_out')
                                            Pending return
                                        @else
                                            <span class="rpt-na">Not retrieved</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if(($office['old_copy'] ?? 'none') === 'pending')
                                            Pending return
                                        @elseif(($office['old_copy'] ?? 'none') === 'retrieved')
                                            Returned
                                        @else
                                            <span class="rpt-na">None</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">No offices for this copy.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endteleport
    @endif
</div>
