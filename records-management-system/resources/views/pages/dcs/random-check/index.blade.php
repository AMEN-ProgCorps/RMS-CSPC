<?php

use App\Helpers\RandomCheckHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Document Control System - Random Check')] class extends Component {
    public string $screen = 'home';

    public int $selectedYear = 0;

    public string $selectedCycle = 'june';

    public int $selectedOfficeId = 0;

    public string $selectedOfficeLabel = '';

    public ?int $checkId = null;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public int $poolSize = 0;

    public string $conductedBy = '';

    public string $testedBy = '';

    public string $checkDate = '';

    public bool $locked = false;

    public bool $isDraft = true;

    public string $rowFilter = 'all';

    public bool $showSchedule = false;

    public int $scheduleYear = 0;

    public string $scheduleCycle = 'june';

    public int $scheduleOfficeId = 0;

    public string $scheduleOfficeLabel = '';

    public string $scheduleDate = '';

    public bool $scheduleNotify = true;

    public string $scheduleSearch = '';

    public bool $showCompare = false;

    public string $compareFilter = 'actions';

    public ?int $compareCheckId = null;

    public string $notice = '';

    public string $noticeType = 'info';

    public string $scheduleError = '';

    public bool $showOfficePicker = false;

    public function mount(): void
    {
        RandomCheckHelper::assertCanAccess();
        $this->selectedYear = (int) now()->format('Y');
        $this->selectedCycle = RandomCheckHelper::defaultCycle($this->selectedYear);
        $this->scheduleYear = $this->selectedYear;
        $this->scheduleCycle = $this->selectedCycle;
        $this->scheduleDate = now()->addDays(RandomCheckHelper::noticeLeadDays())->format('Y-m-d');
        $this->conductedBy = RegisterQueryHelper::currentUserDisplayName();
        $this->checkDate = now()->format('Y-m-d');
    }

    public function openYear(int $year): void
    {
        RandomCheckHelper::assertCanAccess();
        $this->selectedYear = $year;
        $this->selectedCycle = RandomCheckHelper::defaultCycle($year);
        $this->screen = 'year';
        $this->resetWork();
        $this->notice = '';
    }

    public function selectCycle(string $cycle): void
    {
        $this->selectedCycle = RandomCheckHelper::normalizeCycle($cycle);
    }

    public function backHome(): void
    {
        $this->screen = 'home';
        $this->resetWork();
        $this->notice = '';
    }

    public function backYear(): void
    {
        $this->screen = 'year';
        $this->resetWork();
        $this->notice = '';
    }

    public function openOffice(int $officeId, string $label = ''): void
    {
        RandomCheckHelper::assertCanAccess();
        $meta = RandomCheckHelper::officeMeta($officeId);
        $this->selectedOfficeId = $officeId;
        $this->selectedOfficeLabel = $label !== '' ? $label : $meta['label'];
        $this->showCompare = false;
        $this->compareCheckId = null;
        $this->rowFilter = 'all';

        $status = RandomCheckHelper::officeStatusMap($this->selectedYear, $this->selectedCycle)[$officeId] ?? null;
        if ($status && ! empty($status['check_id'])) {
            $this->hydrateCheck((int) $status['check_id']);

            return;
        }

        $this->noticeType = 'info';
        $this->notice = 'This office is not scheduled yet for '
            . RandomCheckHelper::cycleLabel($this->selectedCycle)
            . ' ' . $this->selectedYear
            . '. Use Schedule Random Check, then pick an office.';
        $this->openSchedule();
    }

    public function openCheck(int $id): void
    {
        RandomCheckHelper::assertCanAccess();
        $this->hydrateCheck($id);
    }

    public function updatedShowSchedule($value): void
    {
        if ($value) {
            $this->prepareScheduleForm();
        }
    }

    public function openSchedule(): void
    {
        RandomCheckHelper::assertCanAccess();
        $this->prepareScheduleForm();
        $this->showSchedule = true;
    }

    public function closeSchedule(): void
    {
        $this->showSchedule = false;
        $this->scheduleError = '';
        $this->showOfficePicker = false;
        $this->clearScheduleOffice();
    }

    private function prepareScheduleForm(): void
    {
        $this->scheduleYear = $this->selectedYear ?: (int) now()->format('Y');
        $this->scheduleCycle = $this->selectedCycle ?: RandomCheckHelper::defaultCycle($this->scheduleYear);
        $this->scheduleDate = now()->addDays(RandomCheckHelper::noticeLeadDays())->format('Y-m-d');
        $this->scheduleSearch = '';
        $this->scheduleError = '';
        $this->showOfficePicker = false;
        $this->clearScheduleOffice();
    }

    public function clearScheduleOffice(): void
    {
        $this->scheduleOfficeId = 0;
        $this->scheduleOfficeLabel = '';
    }

    public function updatedScheduleYear(): void
    {
        $this->clearScheduleOffice();
    }

    public function updatedScheduleCycle(): void
    {
        $this->clearScheduleOffice();
    }

    public function selectScheduleOffice(int $officeId, string $label): void
    {
        $this->scheduleOfficeId = $officeId;
        $this->scheduleOfficeLabel = $label;
        $this->scheduleSearch = '';
        $this->scheduleError = '';
        $this->showOfficePicker = false;
    }

    public function pickRandomOffice(): void
    {
        RandomCheckHelper::assertCanAccess();
        $this->scheduleError = '';
        $picked = RandomCheckHelper::pickRandomOffice($this->scheduleYear, $this->scheduleCycle);
        if (! $picked) {
            $this->scheduleError = 'Every distribution office already has a '
                . RandomCheckHelper::cycleLabel($this->scheduleCycle)
                . ' for ' . $this->scheduleYear . '.';

            return;
        }
        $this->scheduleOfficeId = (int) $picked['office_id'];
        $this->scheduleOfficeLabel = (string) $picked['label'];
        $this->showOfficePicker = false;
    }

    public function confirmSchedule(): void
    {
        RandomCheckHelper::assertCanAccess();
        $this->scheduleError = '';
        if ($this->scheduleOfficeId < 1) {
            $this->scheduleError = 'Pick a random office first.';

            return;
        }

        $result = RandomCheckHelper::scheduleVisit(
            $this->scheduleOfficeId,
            $this->scheduleYear,
            $this->scheduleCycle,
            $this->scheduleDate,
            $this->scheduleNotify,
            true
        );

        if (! ($result['ok'] ?? false)) {
            $this->scheduleError = (string) ($result['message'] ?? 'Could not schedule this visit.');

            return;
        }

        $this->showSchedule = false;
        $this->scheduleError = '';
        $this->showOfficePicker = false;
        $this->selectedYear = $this->scheduleYear;
        $this->selectedCycle = RandomCheckHelper::normalizeCycle($this->scheduleCycle);
        $this->noticeType = ($result['short_notice'] ?? false) ? 'info' : 'ok';
        $this->notice = (string) $result['message'];

        if (! empty($result['check_id'])) {
            $this->hydrateCheck((int) $result['check_id']);
        } else {
            $this->screen = 'year';
        }
    }

    public function saveDraft(): void
    {
        $this->persist(true);
    }

    public function finalize(): void
    {
        $this->persist(false);
    }

    public function setAvailability(int $index, string $value): void
    {
        $this->patchRowFlag($index, 'availability', $value);
    }

    public function setCompliance(int $index, string $value): void
    {
        $this->patchRowFlag($index, 'compliance_status', $value);
    }

    public function toggleOfficePicker(): void
    {
        $this->showOfficePicker = ! $this->showOfficePicker;
    }

    private function patchRowFlag(int $index, string $field, string $value): void
    {
        if ($this->locked || ! isset($this->rows[$index])) {
            return;
        }

        $rows = $this->rows;
        $current = (string) ($rows[$index][$field] ?? '');
        $rows[$index][$field] = $current === $value ? '' : $value;
        $this->rows = $rows;
    }

    public function toggleCompare(): void
    {
        RandomCheckHelper::assertCanAccess();
        if ($this->selectedOfficeId < 1) {
            return;
        }
        $this->showCompare = ! $this->showCompare;
        if ($this->showCompare && ! $this->compareCheckId) {
            $prev = RandomCheckHelper::previousCheckForOffice(
                $this->selectedOfficeId,
                $this->selectedYear,
                $this->selectedCycle
            );
            $this->compareCheckId = $prev['id'] ?? null;
            if (! $this->compareCheckId) {
                $this->showCompare = false;
                $this->noticeType = 'info';
                $this->notice = 'No previous random check was found for this office.';
            }
        }
    }

    public function shareOffice(string $filter = 'actions'): void
    {
        RandomCheckHelper::assertCanAccess();
        if (! $this->checkId) {
            return;
        }
        $result = RandomCheckHelper::shareWithOffice((int) $this->checkId, $filter !== 'all');
        $this->noticeType = ($result['ok'] ?? false) ? 'ok' : 'err';
        $this->notice = (string) ($result['message'] ?? '');
    }

    public function with(): array
    {
        $years = RandomCheckHelper::yearSummaries();
        $clusterOffices = $this->screen === 'year'
            ? RandomCheckHelper::officesByCluster($this->selectedYear, $this->selectedCycle, true)
            : [];

        $scheduleClusters = ($this->showSchedule && $this->showOfficePicker)
            ? $this->filterScheduleClusters(RandomCheckHelper::officesByCluster($this->scheduleYear, $this->scheduleCycle, false))
            : [];

        $visibleRows = $this->filteredRows($this->rows, $this->rowFilter);
        $actionCount = count($this->filteredRows($this->rows, 'actions'));

        $compare = null;
        if ($this->showCompare && $this->compareCheckId) {
            $compare = RandomCheckHelper::loadCheck((int) $this->compareCheckId);
            if ($compare) {
                $compare['rows'] = $this->filteredRows($compare['rows'] ?? [], $this->compareFilter);
            }
        }

        $yearForWindow = $this->selectedYear ?: (int) now()->format('Y');
        $minNoticeDate = now()->addDays(RandomCheckHelper::noticeLeadDays())->format('Y-m-d');

        return [
            'yearSummaries' => $years,
            'clusterOffices' => $clusterOffices,
            'scheduleClusters' => $scheduleClusters,
            'visibleRows' => $visibleRows,
            'actionCount' => $actionCount,
            'compare' => $compare,
            'cycleLabel' => RandomCheckHelper::cycleLabel($this->selectedCycle),
            'cycleWindow' => $this->selectedYear > 0
                ? RandomCheckHelper::cycleWindow($this->selectedYear, $this->selectedCycle)
                : '',
            'juneWindow' => RandomCheckHelper::cycleWindow($yearForWindow, 'june'),
            'decemberWindow' => RandomCheckHelper::cycleWindow($yearForWindow, 'december'),
            'minNoticeDate' => $minNoticeDate,
            'reportUrlActions' => $this->checkId
                ? route('dcs.random-check.report', ['id' => $this->checkId, 'filter' => 'actions'])
                : '#',
            'reportUrlAll' => $this->checkId
                ? route('dcs.random-check.report', ['id' => $this->checkId, 'filter' => 'all'])
                : '#',
            'canAccess' => RegisterQueryHelper::canAccessDcsModule('random_check'),
        ];
    }

    private function persist(bool $asDraft): void
    {
        RandomCheckHelper::assertCanAccess();
        if ($this->locked && ! $asDraft) {
            $this->noticeType = 'err';
            $this->notice = 'This random check is already finalized.';

            return;
        }
        if ($this->locked) {
            $this->noticeType = 'info';
            $this->notice = 'Finalized visits cannot be edited.';

            return;
        }

        $result = RandomCheckHelper::saveCheck(
            $this->selectedOfficeId,
            $this->rows,
            $this->checkId,
            $this->poolSize,
            $asDraft,
            $this->selectedYear,
            $this->selectedCycle,
            $this->checkDate,
            $this->conductedBy,
            $this->testedBy
        );

        if (! ($result['ok'] ?? false)) {
            $this->noticeType = 'err';
            $this->notice = (string) ($result['message'] ?? 'Save failed.');

            return;
        }

        $this->checkId = (int) ($result['id'] ?? 0);
        $this->isDraft = $asDraft;
        $this->locked = ! $asDraft;
        $this->noticeType = 'ok';
        $this->notice = (string) ($result['message'] ?? 'Saved.');
    }

    private function hydrateCheck(int $id): void
    {
        $check = RandomCheckHelper::loadCheck($id);
        if (! $check) {
            $this->noticeType = 'err';
            $this->notice = 'Random check not found.';

            return;
        }

        $this->checkId = (int) $check['id'];
        $this->selectedOfficeId = (int) $check['office_id'];
        $this->selectedOfficeLabel = (string) $check['office_label'];
        $this->selectedYear = (int) ($check['year'] ?? $this->selectedYear);
        $this->selectedCycle = RandomCheckHelper::normalizeCycle((string) ($check['cycle'] ?? $this->selectedCycle));
        $this->poolSize = (int) ($check['pool_size'] ?? count($check['rows']));
        $this->rows = $check['rows'];
        $this->conductedBy = (string) ($check['conducted_by'] ?: RegisterQueryHelper::currentUserDisplayName());
        $this->testedBy = (string) ($check['tested_by'] ?? '');
        $this->checkDate = (string) ($check['check_date'] ?: now()->format('Y-m-d'));
        $this->isDraft = (bool) $check['is_draft'];
        $this->locked = (bool) $check['locked'];
        $this->screen = 'work';
        $this->notice = $this->locked
            ? 'This visit is locked. Previous-year results stay as they were recorded.'
            : '';
        $this->noticeType = 'info';
    }

    private function resetWork(): void
    {
        $this->selectedOfficeId = 0;
        $this->selectedOfficeLabel = '';
        $this->checkId = null;
        $this->rows = [];
        $this->poolSize = 0;
        $this->locked = false;
        $this->isDraft = true;
        $this->showCompare = false;
        $this->compareCheckId = null;
        $this->testedBy = '';
        $this->conductedBy = RegisterQueryHelper::currentUserDisplayName();
        $this->checkDate = now()->format('Y-m-d');
        $this->rowFilter = 'all';
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function filteredRows(array $rows, string $filter): array
    {
        if ($filter === 'actions') {
            return array_values(array_filter(
                $rows,
                fn ($row) => trim((string) ($row['recommended_actions'] ?? '')) !== ''
            ));
        }
        if ($filter === 'not_complied') {
            return array_values(array_filter(
                $rows,
                fn ($row) => ($row['compliance_status'] ?? '') === 'not_complied'
            ));
        }

        return array_values($rows);
    }

    /**
     * @param  list<array<string,mixed>>  $groups
     * @return list<array<string,mixed>>
     */
    private function filterScheduleClusters(array $groups): array
    {
        $needle = mb_strtolower(trim($this->scheduleSearch));
        if ($needle === '') {
            return $groups;
        }
        $out = [];
        foreach ($groups as $group) {
            $offices = array_values(array_filter(
                $group['offices'] ?? [],
                function ($office) use ($needle) {
                    $hay = mb_strtolower(($office['office_name'] ?? '') . ' ' . ($office['office_code'] ?? ''));

                    return str_contains($hay, $needle);
                }
            ));
            if ($offices !== []) {
                $group['offices'] = $offices;
                $out[] = $group;
            }
        }

        return $out;
    }
}; ?>

<div class="rc-root">
<main class="rc-page">
    <div
        class="rc-loading"
        wire:loading.class="is-visible"
        wire:target="openYear,openOffice,openCheck,saveDraft,finalize,toggleCompare"
    >
        <div class="dcs-loading-spinner" aria-hidden="true"></div>
        <h4>Loading…</h4>
        <p>Preparing random check.</p>
    </div>

    <header class="rc-header">
        <div class="rc-header-left">
            <div class="rc-breadcrumb">
                Document Control System /
                @if($screen === 'home')
                    <span>Random Check</span>
                @else
                    <button type="button" class="rc-crumb-link" wire:click="backHome">Random Check</button>
                    @if($screen === 'year' || $screen === 'work')
                        /
                        @if($screen === 'work')
                            <button type="button" class="rc-crumb-link" wire:click="backYear">{{ $selectedYear }} Random Check</button>
                            / <span>{{ $selectedOfficeLabel }}</span>
                        @else
                            <span>{{ $selectedYear }} Random Check</span>
                        @endif
                    @endif
                @endif
            </div>
            <h1>
                @if($screen === 'home')
                    Random Check
                @elseif($screen === 'year')
                    {{ $selectedYear }} Random Check
                @else
                    {{ $cycleLabel }} · {{ $selectedOfficeLabel }}
                @endif
            </h1>
            <p class="rc-subtitle">
                @if($screen === 'home')
                    Two cycles a year (June and December). Schedule an office one week ahead, check every distributed copy, then send a letter excerpt.
                @elseif($screen === 'year')
                    {{ $cycleWindow }}
                @elseif($locked)
                    Finalized visit — this snapshot will not change if the document is revised later.
                @else
                    Record availability, remarks, recommended actions, and compliance. Save a draft until the visit is done.
                @endif
            </p>
        </div>
        <div class="rc-header-right">
            @if($screen === 'work')
                <span class="rc-count-badge">{{ count($rows) }} document{{ count($rows) === 1 ? '' : 's' }}</span>
                @if($locked)
                    <span class="rc-lock-badge"><i class="fa-solid fa-lock"></i> Locked</span>
                @endif
            @endif
            <button
                type="button"
                class="rc-btn-secondary"
                wire:click="openSchedule"
                wire:loading.attr="disabled"
                wire:target="openSchedule"
            >
                <i class="fa-regular fa-calendar" wire:loading.remove wire:target="openSchedule"></i>
                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="openSchedule"></i>
                Schedule Random Check
            </button>
            @if($screen === 'work' && ! $locked)
                <button type="button" class="rc-btn-ghost" wire:click="saveDraft" wire:loading.attr="disabled">
                    <i class="fa-regular fa-floppy-disk"></i>
                    Save draft
                </button>
                <button type="button" class="rc-btn-primary" wire:click="finalize" wire:loading.attr="disabled">
                    <i class="fa-solid fa-check"></i>
                    Finalize visit
                </button>
            @endif
        </div>
    </header>

    @if($notice !== '')
        <div class="rc-banner rc-banner-{{ $noticeType }}" role="status">{{ $notice }}</div>
    @endif

    @if($screen === 'home')
        <section class="rc-summary">
            <div class="rc-panel-head">
                <h2><i class="fa-solid fa-clock-rotate-left"></i> Summary of Random Checks</h2>
            </div>
            <div class="rc-year-grid">
                @forelse($yearSummaries as $year)
                    <button
                        type="button"
                        class="rc-year-card {{ $selectedYear === $year['year'] ? 'is-current' : '' }}"
                        wire:click="openYear({{ $year['year'] }})"
                    >
                        <strong>{{ $year['label'] }}</strong>
                        <span>{{ $year['june']['offices'] + $year['december']['offices'] }} office visit{{ ($year['june']['offices'] + $year['december']['offices']) === 1 ? '' : 's' }}</span>
                        <span class="rc-year-meta">
                            June {{ $year['june']['finalized'] }} done
                            · December {{ $year['december']['finalized'] }} done
                        </span>
                    </button>
                @empty
                    <div class="rc-empty">
                        <i class="fa-regular fa-calendar"></i>
                        <h3>No years yet</h3>
                        <p>Schedule the first office visit to start {{ now()->format('Y') }} Random Check.</p>
                    </div>
                @endforelse
            </div>
        </section>
    @endif

    @if($screen === 'year')
        <section class="rc-cycle-row" aria-label="Random check cycles">
            @foreach(['june' => 'June Random Check', 'december' => 'December Random Check'] as $key => $label)
                @php
                    $yearRow = collect($yearSummaries)->firstWhere('year', $selectedYear) ?? [];
                    $sum = $yearRow[$key] ?? [];
                @endphp
                <button
                    type="button"
                    class="rc-cycle-card {{ $selectedCycle === $key ? 'active' : '' }}"
                    wire:click="selectCycle('{{ $key }}')"
                >
                    <strong>{{ $label }}</strong>
                    <span>{{ $key === 'december' ? $decemberWindow : $juneWindow }}</span>
                    <span class="rc-year-meta">
                        {{ (int) ($sum['offices'] ?? 0) }} office{{ (int) ($sum['offices'] ?? 0) === 1 ? '' : 's' }}
                        · {{ (int) ($sum['finalized'] ?? 0) }} finalized
                        · {{ (int) ($sum['drafts'] ?? 0) }} draft{{ (int) ($sum['drafts'] ?? 0) === 1 ? '' : 's' }}
                    </span>
                </button>
            @endforeach
        </section>

        <section class="rc-panel">
            <div class="rc-panel-head">
                <h2><i class="fa-solid fa-building"></i> Offices that were random checked</h2>
                <span class="rc-panel-count">By cluster · {{ $cycleLabel }}</span>
            </div>
            @if(count($clusterOffices) < 1)
                <div class="rc-empty">
                    <i class="fa-solid fa-building"></i>
                    <h3>No offices in this cycle yet</h3>
                    <p>Schedule a visit. Offices appear here after they are scheduled or checked.</p>
                </div>
            @else
                <div class="rc-cluster-list">
                    @foreach($clusterOffices as $cluster)
                        <details class="rc-cluster" open>
                            <summary>{{ $cluster['cluster_name'] }} <span>{{ count($cluster['offices']) }}</span></summary>
                            <div class="rc-office-grid">
                                @foreach($cluster['offices'] as $office)
                                    <button
                                        type="button"
                                        class="rc-office-card is-{{ $office['status'] ?? 'open' }}"
                                        wire:click="openOffice({{ $office['office_id'] }}, @js($office['label']))"
                                    >
                                        <strong>{{ $office['office_name'] }}</strong>
                                        @if(($office['office_code'] ?? '') !== '')
                                            <em>{{ $office['office_code'] }}</em>
                                        @endif
                                        <span>
                                            @if(($office['status'] ?? '') === 'finalized')
                                                Finalized{{ !empty($office['check_date']) ? ' · '.$office['check_date'] : '' }}
                                            @elseif(($office['status'] ?? '') === 'draft')
                                                Draft in progress
                                            @else
                                                Scheduled{{ !empty($office['scheduled_date']) ? ' · '.$office['scheduled_date'] : '' }}
                                            @endif
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    @if($screen === 'work')
        <section class="rc-meta-bar">
            <label>
                Visit date
                <input type="date" wire:model="checkDate" @disabled($locked)>
            </label>
            <label>
                Conducted by
                <input type="text" wire:model.blur="conductedBy" placeholder="Who conducted" @disabled($locked)>
            </label>
            <label>
                Tested by
                <input type="text" wire:model.blur="testedBy" placeholder="Office counterpart" @disabled($locked)>
            </label>
            <div class="rc-meta-actions">
                <button type="button" class="rc-btn-ghost" wire:click="toggleCompare">
                    <i class="fa-solid fa-table-columns"></i>
                    {{ $showCompare ? 'Hide previous result' : 'View previous year' }}
                </button>
                @if($checkId && $locked)
                    <a class="rc-btn-ghost" href="{{ $reportUrlActions }}" target="_blank" rel="noopener">
                        <i class="fa-solid fa-envelope-open-text"></i>
                        Letter excerpt
                    </a>
                    <a class="rc-btn-ghost" href="{{ $reportUrlAll }}" target="_blank" rel="noopener">
                        <i class="fa-solid fa-print"></i>
                        Entire report
                    </a>
                    <button type="button" class="rc-btn-secondary" wire:click="shareOffice('actions')">
                        <i class="fa-solid fa-share"></i>
                        Share with office
                    </button>
                @endif
            </div>
        </section>

        <div class="rc-work {{ $showCompare ? 'has-compare' : '' }}">
            <section class="rc-panel">
                <div class="rc-panel-head">
                    <h2><i class="fa-solid fa-clipboard-check"></i> Documents checked</h2>
                    <div class="rc-filter-pills">
                        <button type="button" class="{{ $rowFilter === 'all' ? 'active' : '' }}" wire:click="$set('rowFilter', 'all')">All</button>
                        <button type="button" class="{{ $rowFilter === 'actions' ? 'active' : '' }}" wire:click="$set('rowFilter', 'actions')">With recommended actions ({{ $actionCount }})</button>
                        <button type="button" class="{{ $rowFilter === 'not_complied' ? 'active' : '' }}" wire:click="$set('rowFilter', 'not_complied')">Not complied</button>
                    </div>
                </div>
                @if(count($rows) < 1)
                    <div class="rc-empty">
                        <i class="fa-regular fa-folder-open"></i>
                        <h3>No distributed documents</h3>
                        <p>This office has no document copies on Document Distribution.</p>
                    </div>
                @else
                    <div class="rc-table-wrap">
                        <table class="rc-table rc-table-wide">
                            <thead>
                                <tr>
                                    <th class="col-item">Item No.</th>
                                    <th class="col-doc">Document No.</th>
                                    <th class="col-rev">Rev No.</th>
                                    <th class="col-date">Effectivity Date</th>
                                    <th class="col-avail">Availability</th>
                                    <th class="col-remarks">Remarks</th>
                                    <th class="col-notes">Recommended actions</th>
                                    <th class="col-comp">Status of compliance</th>
                                    <th class="col-notes">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $index => $row)
                                    @php
                                        $show = $rowFilter === 'all'
                                            || ($rowFilter === 'actions' && trim((string) ($row['recommended_actions'] ?? '')) !== '')
                                            || ($rowFilter === 'not_complied' && ($row['compliance_status'] ?? '') === 'not_complied');
                                    @endphp
                                    @if($show)
                                        <tr wire:key="rc-row-{{ $row['masterlist_id'] ?? $index }}-{{ $index }}">
                                            <td class="col-item">{{ $row['item_no'] ?? ($index + 1) }}</td>
                                            <td class="col-doc">
                                                <span class="rc-doc-no">{{ ($row['doc_no'] ?? '') !== '' ? $row['doc_no'] : '—' }}</span>
                                                <small>{{ ($row['doc_title'] ?? '') !== '' ? $row['doc_title'] : '' }}</small>
                                            </td>
                                            <td class="col-rev"><span class="rc-rev">{{ $row['rev_no'] ?? 0 }}</span></td>
                                            <td class="col-date">{{ $row['effectivity_date'] ?? '—' }}</td>
                                            <td class="col-avail">
                                                <div class="rc-yn-wrap" role="group" aria-label="Availability item {{ $index + 1 }}">
                                                    <button
                                                        type="button"
                                                        class="rc-checkbtn {{ ($row['availability'] ?? '') === 'yes' ? 'is-on' : '' }}"
                                                        wire:click="setAvailability({{ $index }}, 'yes')"
                                                        @disabled($locked)
                                                    >
                                                        <span class="rc-check-box" aria-hidden="true"></span>
                                                        Yes
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="rc-checkbtn is-no {{ ($row['availability'] ?? '') === 'no' ? 'is-on' : '' }}"
                                                        wire:click="setAvailability({{ $index }}, 'no')"
                                                        @disabled($locked)
                                                    >
                                                        <span class="rc-check-box" aria-hidden="true"></span>
                                                        No
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="col-remarks">
                                                <textarea class="rc-field-textarea" rows="2" wire:model.blur="rows.{{ $index }}.remarks" placeholder="What’s wrong / what’s missing…" @disabled($locked)></textarea>
                                            </td>
                                            <td class="col-notes">
                                                <textarea class="rc-field-textarea" rows="2" wire:model.blur="rows.{{ $index }}.recommended_actions" placeholder="Recommended actions…" @disabled($locked)></textarea>
                                            </td>
                                            <td class="col-comp">
                                                <div class="rc-yn-wrap" role="group" aria-label="Compliance item {{ $index + 1 }}">
                                                    <button
                                                        type="button"
                                                        class="rc-checkbtn {{ ($row['compliance_status'] ?? '') === 'complied' ? 'is-on' : '' }}"
                                                        wire:click="setCompliance({{ $index }}, 'complied')"
                                                        @disabled($locked)
                                                    >
                                                        <span class="rc-check-box" aria-hidden="true"></span>
                                                        Complied
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="rc-checkbtn is-no {{ ($row['compliance_status'] ?? '') === 'not_complied' ? 'is-on' : '' }}"
                                                        wire:click="setCompliance({{ $index }}, 'not_complied')"
                                                        @disabled($locked)
                                                    >
                                                        <span class="rc-check-box" aria-hidden="true"></span>
                                                        Not
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="col-notes">
                                                <textarea class="rc-field-textarea" rows="2" wire:model.blur="rows.{{ $index }}.notes" placeholder="Notes…" @disabled($locked)></textarea>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            @if($showCompare && $compare)
                <aside class="rc-panel rc-compare">
                    <div class="rc-panel-head">
                        <h2><i class="fa-solid fa-clock-rotate-left"></i> {{ $compare['year'] ?? '' }} result</h2>
                        <div class="rc-filter-pills">
                            <button type="button" class="{{ $compareFilter === 'all' ? 'active' : '' }}" wire:click="$set('compareFilter', 'all')">All</button>
                            <button type="button" class="{{ $compareFilter === 'actions' ? 'active' : '' }}" wire:click="$set('compareFilter', 'actions')">With actions</button>
                            <button type="button" class="{{ $compareFilter === 'not_complied' ? 'active' : '' }}" wire:click="$set('compareFilter', 'not_complied')">Not complied</button>
                        </div>
                    </div>
                    <p class="rc-compare-lead">
                        Frozen snapshot from {{ $compare['cycle_label'] ?? 'previous visit' }}.
                        Use this to see if last year’s recommended actions were complied.
                    </p>
                    <div class="rc-table-wrap">
                        <table class="rc-table">
                            <thead>
                                <tr>
                                    <th>Doc. No.</th>
                                    <th>Rev</th>
                                    <th>Avail.</th>
                                    <th>Actions / compliance</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($compare['rows'] as $prev)
                                    <tr>
                                        <td>
                                            <span class="rc-doc-no">{{ $prev['doc_no'] ?: '—' }}</span>
                                            <small>{{ $prev['doc_title'] ?? '' }}</small>
                                        </td>
                                        <td>{{ $prev['rev_no'] ?? 0 }}</td>
                                        <td>{{ strtoupper($prev['availability'] ?: '—') }}</td>
                                        <td>
                                            <div>{{ $prev['recommended_actions'] ?: '—' }}</div>
                                            <small>
                                                {{ ($prev['compliance_status'] ?? '') === 'complied' ? 'Complied' : (($prev['compliance_status'] ?? '') === 'not_complied' ? 'Not complied' : 'No status') }}
                                            </small>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4">No matching previous rows.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </aside>
            @endif
        </div>
    @endif
</main>

@if($showSchedule)
<div
    class="rc-modal is-open"
    style="display:flex; position:fixed; inset:0; z-index:1000001;"
    wire:key="rc-schedule-modal"
    wire:click.self="closeSchedule"
    role="dialog"
    aria-modal="true"
    aria-labelledby="rc-schedule-title"
>
    <div class="rc-modal-card">
        <div
            class="rc-modal-loading"
            wire:loading.flex
            wire:target="confirmSchedule,pickRandomOffice"
        >
            <div class="dcs-loading-spinner" aria-hidden="true"></div>
            <h4 wire:loading wire:target="confirmSchedule">Generating document list…</h4>
            <h4 wire:loading.remove wire:target="confirmSchedule">Picking an office…</h4>
            <p>Please wait. This can take a few seconds.</p>
        </div>
        <header>
            <h2 id="rc-schedule-title">Schedule Random Checking of Documents</h2>
            <p>Pick a random office, set the visit date (1 week ahead), then generate the distributed document list as a draft.</p>
        </header>
        @if($scheduleError !== '')
            <div class="rc-banner rc-banner-err" role="alert">{{ $scheduleError }}</div>
        @endif
        <div class="rc-modal-grid">
            <label>
                Year
                <input type="number" min="2020" max="2100" wire:model.live="scheduleYear">
            </label>
            <label>
                Cycle
                <select wire:model.live="scheduleCycle">
                    <option value="june">June Random Check</option>
                    <option value="december">December Random Check</option>
                </select>
            </label>
            <label>
                Visit date
                <input type="date" wire:model="scheduleDate">
                <small>Recommended on or after {{ \Carbon\Carbon::parse($minNoticeDate)->format('M d, Y') }}</small>
            </label>
        </div>

        <div class="rc-office-slot {{ $scheduleOfficeId > 0 ? 'has-office' : 'is-empty' }}">
            @if($scheduleOfficeId < 1)
                <div class="rc-office-empty">
                    <i class="fa-solid fa-building-circle-arrow-right"></i>
                    <strong>No office selected</strong>
                    <span>Click Pick a random office. DCS will draw one that is not yet scheduled this cycle.</span>
                </div>
            @else
                <div class="rc-office-picked">
                    <span class="rc-office-picked-label">Drawn office</span>
                    <strong>{{ $scheduleOfficeLabel }}</strong>
                    <button type="button" class="rc-btn-ghost rc-btn-tiny" wire:click="clearScheduleOffice">Clear</button>
                </div>
            @endif
            <button
                type="button"
                class="rc-btn-primary rc-btn-draw"
                wire:click="pickRandomOffice"
                wire:loading.attr="disabled"
                wire:target="pickRandomOffice,confirmSchedule"
            >
                <i class="fa-solid fa-shuffle"></i>
                {{ $scheduleOfficeId > 0 ? 'Draw another office' : 'Pick a random office' }}
            </button>
        </div>

        <button type="button" class="rc-linkish" wire:click="toggleOfficePicker">
            {{ $showOfficePicker ? 'Hide office list' : 'Or choose a specific office' }}
        </button>

        @if($showOfficePicker)
            <div class="rc-schedule-office">
                <div class="rc-search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" placeholder="Search offices…" wire:model.live.debounce.250ms="scheduleSearch">
                </div>
                <div class="rc-cluster-list rc-cluster-list-compact">
                    @foreach($scheduleClusters as $cluster)
                        <details class="rc-cluster" {{ $scheduleSearch !== '' ? 'open' : '' }}>
                            <summary>{{ $cluster['cluster_name'] }} <span>{{ count($cluster['offices']) }}</span></summary>
                            <div class="rc-office-grid">
                                @foreach($cluster['offices'] as $office)
                                    <button
                                        type="button"
                                        class="rc-office-card is-{{ $office['status'] ?? 'open' }} {{ $scheduleOfficeId === $office['office_id'] ? 'is-picked' : '' }}"
                                        wire:click="selectScheduleOffice({{ $office['office_id'] }}, @js($office['label']))"
                                    >
                                        <strong>{{ $office['office_name'] }}</strong>
                                        <span>{{ ($office['status'] ?? 'open') === 'open' ? 'Not yet this cycle' : ucfirst($office['status']) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                </div>
            </div>
        @endif

        <label class="rc-check-line">
            <input type="checkbox" wire:model="scheduleNotify">
            Notify the office now
        </label>
        <footer>
            <button type="button" class="rc-btn-ghost" wire:click="closeSchedule">Cancel</button>
            <button
                type="button"
                class="rc-btn-primary"
                wire:click="confirmSchedule"
                wire:loading.attr="disabled"
                wire:target="confirmSchedule,pickRandomOffice"
                @disabled($scheduleOfficeId < 1)
            >
                <span wire:loading.remove wire:target="confirmSchedule">Schedule and generate list</span>
                <span wire:loading wire:target="confirmSchedule">Generating list…</span>
            </button>
        </footer>
    </div>
</div>
@endif
</div>
