<?php

use App\Helpers\DistributionRetrievalMonitorHelper;
use App\Helpers\RegisterQueryHelper;
use Illuminate\Pagination\LengthAwarePaginator;
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
    public int $page = 1;

    #[Url]
    public bool $showObsolete = false;

    public ?int $openId = null;

    public function mount(): void
    {
        abort_unless(RegisterQueryHelper::canAccessDcsModule('reports'), 403);
        if (! in_array($this->status, ['all', 'awaiting', 'received', 'retrieved', 'previous_out'], true)) {
            $this->status = 'all';
        }
    }

    public function updatedQ(): void
    {
        $this->page = 1;
        $this->openId = null;
        $this->showObsolete = false;
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, ['all', 'awaiting', 'received', 'retrieved', 'previous_out'], true)
            ? $status
            : 'all';
        $this->page = 1;
        $this->openId = null;
        $this->showObsolete = false;
    }

    public function toggleObsolete(): void
    {
        $this->showObsolete = ! $this->showObsolete;
        $this->page = 1;
        $this->openId = null;
    }

    public function openRevision(int $requestId): void
    {
        $this->openId = $this->openId === $requestId ? null : $requestId;
    }

    public function closeRevision(): void
    {
        $this->openId = null;
    }

    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
            $this->openId = null;
        }
    }

    public function nextPage(): void
    {
        $this->page++;
        $this->openId = null;
    }

    public function stepDocument(int $direction): void
    {
        $listed = $this->listedRevisions();
        $rows = $listed['rows'];
        if ($rows === []) {
            return;
        }

        $index = 0;
        foreach ($rows as $i => $revision) {
            if ((int) $revision['request_id'] === (int) $this->openId) {
                $index = $i;
                break;
            }
        }
        $index = max(0, min(count($rows) - 1, $index + ($direction < 0 ? -1 : 1)));
        $this->openId = (int) $rows[$index]['request_id'];
        $this->page = (int) floor($index / 12) + 1;
    }

    /** @return array{rows: list<array<string, mixed>>, obsoleteCount: int, currentCount: int, summary: array<string, int>} */
    private function listedRevisions(): array
    {
        $monitor = DistributionRetrievalMonitorHelper::monitor($this->q, $this->status);
        $current = [];
        $obsolete = [];
        foreach ($monitor['families'] as $family) {
            foreach ($family['revisions'] as $revision) {
                $state = strtolower(trim((string) ($revision['revision_status'] ?? 'latest')));
                if ($state === '' || $state === 'latest') {
                    $current[] = $revision;
                } else {
                    $obsolete[] = $revision;
                }
            }
        }
        $sort = function ($a, $b) {
            $doc = strnatcasecmp((string) $a['doc_no'], (string) $b['doc_no']);

            return $doc !== 0 ? $doc : ((int) $b['revise_no']) <=> ((int) $a['revise_no']);
        };
        usort($current, $sort);
        usort($obsolete, $sort);

        return [
            'rows' => $this->showObsolete ? array_merge($current, $obsolete) : $current,
            'obsoleteCount' => count($obsolete),
            'currentCount' => count($current),
            'summary' => $monitor['summary'],
        ];
    }

    public function with(): array
    {
        $listed = $this->listedRevisions();
        $rows = $listed['rows'];

        $perPage = 12;
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($this->page > $lastPage) {
            $this->page = $lastPage;
        }
        if ($this->page < 1) {
            $this->page = 1;
        }
        $slice = array_slice($rows, ($this->page - 1) * $perPage, $perPage);

        $open = null;
        if ($this->openId) {
            foreach ($slice as $revision) {
                if ((int) $revision['request_id'] === $this->openId) {
                    $open = $revision;
                    break;
                }
            }
        }
        if ($open === null) {
            $this->openId = null;
        }

        $position = 0;
        foreach ($rows as $i => $revision) {
            if ($this->openId && (int) $revision['request_id'] === (int) $this->openId) {
                $position = $i + 1;
                break;
            }
        }

        return [
            'summary' => $listed['summary'],
            'rows' => new LengthAwarePaginator(
                $slice,
                $total,
                $perPage,
                $this->page,
                ['path' => request()->url()]
            ),
            'open' => $open,
            'totalRows' => $total,
            'obsoleteCount' => $listed['obsoleteCount'],
            'currentCount' => $listed['currentCount'],
            'position' => $position,
        ];
    }
}; ?>

<div class="rpt-page drt-page">
    <header class="drt-top">
        <div>
            <h1>Distribution &amp; Retrieval</h1>
            <p>Current copies sent to offices. Open a document to see who has received it, and whether the previous copy was retrieved.</p>
        </div>
        <label class="drt-search">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" wire:model.live.debounce.300ms="q" placeholder="Document no., title, or office">
        </label>
    </header>

    <div class="drt-filters" role="tablist" aria-label="Receipt status">
        @foreach([
            'all' => ['All documents', $summary['documents'] ?? 0],
            'awaiting' => ['Waiting for receipt', $summary['awaiting'] ?? 0],
            'previous_out' => ['Old copy still out', $summary['previous_out'] ?? 0],
        ] as $key => [$label, $count])
            <button type="button" role="tab" class="drt-filter {{ $status === $key ? 'is-active' : '' }} is-{{ $key }}" wire:click="setStatus('{{ $key }}')" aria-selected="{{ $status === $key ? 'true' : 'false' }}">
                {{ $label }}
                <span>{{ number_format((int) $count) }}</span>
            </button>
        @endforeach
        @if($obsoleteCount > 0)
            <button type="button" class="drt-filter {{ $showObsolete ? 'is-active' : '' }}" wire:click="toggleObsolete">
                {{ $showObsolete ? 'Hide obsolete' : 'Show obsolete' }}
                <span>{{ number_format($obsoleteCount) }}</span>
            </button>
        @endif
    </div>

    <div class="drt-sheet">
        <table class="drt-table">
            <thead>
                <tr>
                    <th>Document</th>
                    <th>Received</th>
                    <th>Waiting</th>
                    <th>Old copy</th>
                </tr>
            </thead>
            <tbody>
                @php $obsoleteLabelShown = false; @endphp
                @forelse($rows as $revision)
                    @php
                        $officeCount = count($revision['offices']);
                        $receivedCount = 0;
                        $stillOut = 0;
                        foreach ($revision['offices'] as $office) {
                            if (! empty($office['received'])) {
                                $receivedCount++;
                            }
                            if (($office['old_copy'] ?? 'none') === 'pending' || ($office['status'] ?? '') === 'still_out') {
                                $stillOut++;
                            }
                        }
                        $waitingCount = max(0, $officeCount - $receivedCount);
                        $isObsolete = ! in_array(strtolower(trim((string) ($revision['revision_status'] ?? 'latest'))), ['', 'latest'], true);
                        $selected = $open && (int) $open['request_id'] === (int) $revision['request_id'];
                    @endphp
                    @if($isObsolete && ! $obsoleteLabelShown)
                        @php $obsoleteLabelShown = true; @endphp
                        <tr class="drt-section">
                            <td colspan="4">Obsolete copies</td>
                        </tr>
                    @endif
                    <tr
                        class="drt-doc {{ $selected ? 'is-open' : '' }} {{ $isObsolete ? 'is-obsolete' : '' }}"
                        wire:click="openRevision({{ (int) $revision['request_id'] }})"
                        @if($selected) x-init="$nextTick(() => $el.scrollIntoView({ block: 'start', behavior: 'smooth' }))" @endif
                    >
                        <td>
                            <strong>{{ $revision['doc_no'] }}</strong>
                            <span>{{ $revision['doc_title'] !== '' ? $revision['doc_title'] : 'Untitled' }}</span>
                            <span class="drt-quiet">Rev {{ (int) $revision['revise_no'] }}{{ $isObsolete ? ' · Obsolete' : '' }}</span>
                        </td>
                        <td>{{ $receivedCount }} of {{ $officeCount }}</td>
                        <td class="{{ $waitingCount > 0 ? 'is-wait' : '' }}">{{ $waitingCount }}</td>
                        <td class="{{ $stillOut > 0 ? 'is-alert' : '' }}">{{ $stillOut > 0 ? $stillOut.' still out' : 'Clear' }}</td>
                    </tr>
                    @if($selected)
                        <tr class="drt-expand">
                            <td colspan="4">
                                <div class="drt-jump">
                                    <button type="button" wire:click="stepDocument(-1)" @disabled($position <= 1)>Previous document</button>
                                    <span>Document {{ $position }} of {{ $totalRows }} · {{ $officeCount }} {{ $officeCount === 1 ? 'office' : 'offices' }}</span>
                                    <button type="button" wire:click="stepDocument(1)" @disabled($position >= $totalRows)>Next document</button>
                                    <button type="button" class="drt-hide" wire:click="openRevision({{ (int) $revision['request_id'] }})">Hide offices</button>
                                </div>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Office</th>
                                            <th>Copies</th>
                                            <th>Received the copy</th>
                                            <th>Previous copy</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($revision['offices'] as $office)
                                            <tr>
                                                <td>
                                                    <strong>{{ $office['office_name'] }}</strong>
                                                    @if($office['office_code'] !== '')
                                                        <span class="drt-quiet">{{ $office['office_code'] }}</span>
                                                    @endif
                                                </td>
                                                <td>{{ (int) ($office['copies'] ?? 1) }}</td>
                                                <td>
                                                    @if(! empty($office['received']))
                                                        <span class="drt-badge is-ok">Received</span>
                                                        @if($office['received_at'] !== '')
                                                            <span class="drt-quiet">{{ $office['received_at'] }}</span>
                                                        @endif
                                                    @else
                                                        <span class="drt-badge is-wait">Waiting</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if(($office['old_copy'] ?? 'none') === 'pending' || ($office['status'] ?? '') === 'still_out')
                                                        <span class="drt-badge is-alert">Still at the office</span>
                                                    @elseif(($office['old_copy'] ?? 'none') === 'retrieved' || ! empty($office['retrieved']))
                                                        <span class="drt-badge is-info">Retrieved</span>
                                                    @else
                                                        <span class="drt-quiet">None</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="4" class="drt-empty">No distributed documents match this view.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($rows->lastPage() > 1)
        <div class="drt-pager">
            <button type="button" wire:click="previousPage" @disabled($page <= 1)>Previous</button>
            <span>Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }}</span>
            <button type="button" wire:click="nextPage" @disabled($rows->currentPage() >= $rows->lastPage())>Next</button>
        </div>
    @endif
</div>
