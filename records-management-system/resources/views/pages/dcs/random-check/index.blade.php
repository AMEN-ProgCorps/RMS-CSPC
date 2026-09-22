<?php

use App\Helpers\RandomCheckHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Document Control System - Random Check')] class extends Component {
    public string $officeSearch = '';

    public int $selectedOfficeId = 0;

    public string $selectedOfficeLabel = '';

    public string $docType = 'internal_docs';

    public int $poolSize = 0;

    public ?int $checkId = null;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public string $notice = '';

    public string $noticeType = 'info';

    public function mount(): void
    {
        RandomCheckHelper::assertCanAccess();
        $this->docType = RandomCheckHelper::defaultDocTypeKey();
    }

    public function updatedOfficeSearch(): void
    {
        if ($this->selectedOfficeId > 0 && trim($this->officeSearch) === '') {
            $this->clearOffice();
        }
    }

    public function selectOffice(int $officeId, string $label): void
    {
        RandomCheckHelper::assertCanAccess();
        $this->selectedOfficeId = $officeId;
        $this->selectedOfficeLabel = $label;
        $this->officeSearch = '';
        $this->checkId = null;
        $this->notice = '';
        $this->loadDocuments();
    }

    public function clearOffice(): void
    {
        $this->selectedOfficeId = 0;
        $this->selectedOfficeLabel = '';
        $this->officeSearch = '';
        $this->checkId = null;
        $this->rows = [];
        $this->poolSize = 0;
        $this->notice = '';
    }

    public function selectType(string $type): void
    {
        RandomCheckHelper::assertCanAccess();
        $this->docType = RandomCheckHelper::normalizeDocTypeKey($type);
        $this->checkId = null;
        $this->notice = '';
        if ($this->selectedOfficeId > 0) {
            $this->loadDocuments();
        }
    }

    public function loadDocuments(): void
    {
        RandomCheckHelper::assertCanAccess();
        if ($this->selectedOfficeId < 1) {
            $this->rows = [];
            $this->poolSize = 0;

            return;
        }

        $result = RandomCheckHelper::listCheckRows($this->selectedOfficeId, $this->docType);
        $this->poolSize = (int) ($result['pool'] ?? 0);
        $this->rows = $result['rows'] ?? [];
        $this->docType = RandomCheckHelper::normalizeDocTypeKey(
            (string) ($result['doc_type_key'] ?? $this->docType)
        );
    }

    public function save(): void
    {
        RandomCheckHelper::assertCanAccess();
        $result = RandomCheckHelper::saveCheck(
            $this->selectedOfficeId,
            $this->rows,
            $this->checkId,
            $this->poolSize,
            $this->docType
        );

        if (! ($result['ok'] ?? false)) {
            $this->noticeType = 'err';
            $this->notice = (string) ($result['message'] ?? 'Save failed.');

            return;
        }

        $this->checkId = (int) ($result['id'] ?? 0);
        $this->noticeType = 'ok';
        $this->notice = (string) ($result['message'] ?? 'Saved.');
    }

    public function loadCheck(int $id): void
    {
        RandomCheckHelper::assertCanAccess();
        $check = RandomCheckHelper::loadCheck($id);
        if (! $check) {
            $this->noticeType = 'err';
            $this->notice = 'Random check not found.';

            return;
        }

        $this->checkId = (int) $check['id'];
        $this->selectedOfficeId = (int) $check['office_id'];
        $this->selectedOfficeLabel = (string) $check['office_label'];
        $this->officeSearch = '';
        $this->docType = RandomCheckHelper::normalizeDocTypeKey((string) ($check['doc_type_key'] ?? ''));
        $this->poolSize = (int) ($check['pool_size'] ?? count($check['rows']));
        $this->rows = $check['rows'];
        $this->noticeType = 'info';
        $this->notice = 'Loaded check from ' . ($check['checked_at'] ?? '—')
            . ' · ' . ($check['doc_type_label'] ?? RandomCheckHelper::docTypeLabel($this->docType)) . '.';
    }

    public function with(): array
    {
        $officeSuggestions = $this->selectedOfficeId < 1
            ? RandomCheckHelper::searchOffices($this->officeSearch, 20)
            : [];

        $docTypeGroups = RandomCheckHelper::documentTypeGroups($this->selectedOfficeId);
        $activeTypeLabel = RandomCheckHelper::docTypeLabel($this->docType);

        $recentChecks = $this->selectedOfficeId > 0
            ? RandomCheckHelper::recentChecks($this->selectedOfficeId, 12, $this->docType)
            : [];

        return [
            'officeSuggestions' => $officeSuggestions,
            'docTypeGroups' => $docTypeGroups,
            'activeTypeLabel' => $activeTypeLabel,
            'recentChecks' => $recentChecks,
            'canAccess' => RegisterQueryHelper::canAccessDcsModule('random_check'),
        ];
    }
}; ?>

<main class="rc-page">
    <div
        class="rc-loading"
        wire:loading.flex
        wire:target="selectOffice,selectType,save,loadCheck,clearOffice,loadDocuments"
    >
        <div class="dcs-loading-spinner" aria-hidden="true"></div>
        <h4>Loading…</h4>
        <p>Fetching distributed documents.</p>
    </div>

    <header class="rc-header">
        <div class="rc-header-left">
            <div class="rc-breadcrumb">Document Control System / <span>Random Check</span></div>
            <h1>Random Check</h1>
            <p class="rc-subtitle">
                Search a distribution office, choose a document type, then record availability and notes.
            </p>
        </div>
        <div class="rc-header-right">
            <span class="rc-count-badge">
                {{ $selectedOfficeId > 0 ? count($rows) : 0 }}
                document{{ count($rows) === 1 ? '' : 's' }}
            </span>
            <button
                type="button"
                class="rc-btn-primary"
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
                @disabled($selectedOfficeId < 1 || count($rows) < 1)
            >
                <i class="fa-solid fa-floppy-disk"></i>
                Save check
            </button>
        </div>
    </header>

    @if($notice !== '')
        <div class="rc-banner rc-banner-{{ $noticeType }}" role="status">{{ $notice }}</div>
    @endif

    <section class="rc-toolbar">
        @if($selectedOfficeId > 0)
            <div class="rc-office-selected" role="status">
                <div class="rc-office-selected-main">
                    <i class="fa-solid fa-building" aria-hidden="true"></i>
                    <div>
                        <span class="rc-office-selected-label">Selected office</span>
                        <strong>{{ $selectedOfficeLabel }}</strong>
                    </div>
                </div>
                <button type="button" class="rc-office-clear" wire:click="clearOffice">
                    <i class="fa-solid fa-xmark"></i>
                    Change
                </button>
            </div>
        @else
            <div
                class="rc-search-wrap"
                x-data="{ open: false }"
                @click.outside="open = false"
            >
                <i class="fa-solid fa-magnifying-glass"></i>
                <input
                    type="search"
                    placeholder="Search distribution offices by name or code…"
                    wire:model.live.debounce.250ms="officeSearch"
                    @focus="open = true"
                    @input="open = true"
                    autocomplete="off"
                    aria-label="Search offices"
                >
                <div class="rc-dropdown" role="listbox" x-show="open" x-cloak>
                    @forelse($officeSuggestions as $office)
                        <button
                            type="button"
                            role="option"
                            wire:click="selectOffice({{ $office['office_id'] }}, @js($office['label']))"
                            @click="open = false"
                        >
                            <span class="rc-dropdown-name">{{ $office['office_name'] }}</span>
                            @if(($office['office_code'] ?? '') !== '')
                                <span class="rc-dropdown-code">{{ $office['office_code'] }}</span>
                            @endif
                        </button>
                    @empty
                        <div class="rc-dropdown-empty">
                            {{ trim($officeSearch) === ''
                                ? 'Type to search offices with distributed documents…'
                                : 'No matching distribution offices.' }}
                        </div>
                    @endforelse
                </div>
            </div>
        @endif
    </section>

    <section class="rc-type-row" aria-label="Document types">
        @foreach($docTypeGroups as $group)
            <button
                type="button"
                class="rc-type-btn {{ $docType === $group['key'] ? 'active' : '' }} {{ ($group['count'] ?? 0) < 1 ? 'is-empty' : '' }}"
                wire:click="selectType('{{ $group['key'] }}')"
                wire:loading.attr="disabled"
                wire:target="selectType"
                @disabled($selectedOfficeId < 1)
                title="{{ ($group['count'] ?? 0) < 1 ? 'No ' . $group['label'] . ' documents' : $group['count'] . ' ' . $group['label'] }}"
            >
                {{ $group['label'] }}
                <span class="rc-type-count">{{ $selectedOfficeId > 0 ? $group['count'] : '—' }}</span>
            </button>
        @endforeach
    </section>

    <div class="rc-body">
        <section class="rc-panel">
            <div class="rc-panel-head">
                <h2>
                    <i class="fa-solid fa-clipboard-check"></i>
                    {{ $activeTypeLabel }}
                </h2>
                <span class="rc-panel-count">
                    @if($selectedOfficeId < 1)
                        Select an office
                    @else
                        {{ count($rows) }} {{ count($rows) === 1 ? 'document' : 'documents' }}
                    @endif
                </span>
            </div>

            @if($selectedOfficeId < 1)
                <div class="rc-empty">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <h3>Search an office</h3>
                    <p>Only offices on Document Distribution are listed. Documents load automatically after you select one.</p>
                </div>
            @elseif(count($rows) < 1)
                <div class="rc-empty">
                    <i class="fa-regular fa-folder-open"></i>
                    <h3>No {{ strtolower($activeTypeLabel) }} documents</h3>
                    <p>
                        No distributed <strong>{{ $activeTypeLabel }}</strong> documents were found for
                        <strong>{{ $selectedOfficeLabel }}</strong>.
                    </p>
                </div>
            @else
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                            <tr>
                                <th class="col-item">Item No.</th>
                                <th class="col-doc">Document No.</th>
                                <th class="col-rev">Rev No.</th>
                                <th class="col-title">Document Title</th>
                                <th class="col-date">Effectivity Date</th>
                                <th class="col-avail">Availability</th>
                                <th class="col-remarks">Remarks</th>
                                <th class="col-notes">Recommended actions / notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $index => $row)
                                <tr wire:key="rc-row-{{ $row['masterlist_id'] ?? $index }}-{{ $index }}">
                                    <td class="col-item">{{ $row['item_no'] ?? ($index + 1) }}</td>
                                    <td class="col-doc">
                                        <span class="rc-doc-no">{{ ($row['doc_no'] ?? '') !== '' ? $row['doc_no'] : '—' }}</span>
                                    </td>
                                    <td class="col-rev">
                                        <span class="rc-rev">{{ $row['rev_no'] ?? 0 }}</span>
                                    </td>
                                    <td class="col-title">{{ ($row['doc_title'] ?? '') !== '' ? $row['doc_title'] : '—' }}</td>
                                    <td class="col-date">{{ $row['effectivity_date'] ?? '—' }}</td>
                                    <td class="col-avail">
                                        <select
                                            class="rc-field-select"
                                            wire:model="rows.{{ $index }}.availability"
                                            aria-label="Availability item {{ $index + 1 }}"
                                        >
                                            <option value="">—</option>
                                            <option value="yes">Yes</option>
                                            <option value="no">No</option>
                                        </select>
                                    </td>
                                    <td class="col-remarks">
                                        <textarea
                                            class="rc-field-textarea"
                                            rows="2"
                                            wire:model.blur="rows.{{ $index }}.remarks"
                                            placeholder="Remarks…"
                                            aria-label="Remarks item {{ $index + 1 }}"
                                        ></textarea>
                                    </td>
                                    <td class="col-notes">
                                        <textarea
                                            class="rc-field-textarea"
                                            rows="2"
                                            wire:model.blur="rows.{{ $index }}.recommended_actions"
                                            placeholder="Recommended actions / notes…"
                                            aria-label="Recommended actions item {{ $index + 1 }}"
                                        ></textarea>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <aside class="rc-panel rc-side-panel">
            <div class="rc-panel-head rc-panel-head-compact">
                <h2><i class="fa-regular fa-clock"></i> Recent checks</h2>
            </div>
            @if($selectedOfficeId < 1)
                <p class="rc-side-empty">Select an office to see saved checks for the active document type.</p>
            @elseif(count($recentChecks) < 1)
                <p class="rc-side-empty">No saved {{ strtolower($activeTypeLabel) }} checks for this office yet.</p>
            @else
                <div class="rc-side-list">
                    @foreach($recentChecks as $recent)
                        <button
                            type="button"
                            class="rc-side-item {{ $checkId === $recent['id'] ? 'is-active' : '' }}"
                            wire:key="rc-recent-{{ $recent['id'] }}"
                            wire:click="loadCheck({{ $recent['id'] }})"
                        >
                            <strong>{{ $recent['checked_at'] }}</strong>
                            <span>{{ $recent['sample_size'] }} document{{ $recent['sample_size'] === 1 ? '' : 's' }}</span>
                            <span>{{ $recent['checked_by_name'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </aside>
    </div>
</main>
