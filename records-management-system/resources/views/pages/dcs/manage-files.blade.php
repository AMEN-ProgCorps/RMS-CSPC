<?php

use App\Helpers\RegisterQueryHelper;
use App\Services\DocumentStorageService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Pagination\LengthAwarePaginator;

new #[Layout('layouts.dcs')] #[Title('Document Control System - Manage Files')] class extends Component {
    use WithPagination;

    public int $perPage = 12;
    public string $search = '';
    public string $selectedOffice = '';
    public string $reportCategory = '';
    public string $reportFormat = '';
    public string $layoutMode = 'tiles';
    public ?int $selectedReportId = null;

    /** @var array<string, string> */
    public array $reportCategoryOptions = [
        '' => 'All report types',
        'masterlist' => 'Document Masterlist',
        'monitoring' => 'Monitoring',
        'opcr' => 'OPCR',
        'others' => 'Others',
    ];

    public function setLayoutMode(string $mode): void
    {
        if (!in_array($mode, ['tiles', 'list', 'details'], true)) {
            return;
        }
        $this->layoutMode = $mode;
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selectedReportId = null;
    }

    public function updatedSelectedOffice(): void
    {
        $this->resetPage();
    }

    public function updatedReportCategory(): void
    {
        $this->resetPage();
    }

    public function updatedReportFormat(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->selectedOffice = '';
        $this->reportCategory = '';
        $this->reportFormat = '';
        $this->resetPage();
    }

    public function selectReport(?int $reportId): void
    {
        $this->selectedReportId = $this->selectedReportId === $reportId ? null : $reportId;
    }

    public function closeInspector(): void
    {
        $this->selectedReportId = null;
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->selectedOffice !== ''
            || $this->reportCategory !== ''
            || $this->reportFormat !== '';
    }

    private function filterReports($reports, bool $canViewAll, ?string $userOfficeCode): \Illuminate\Support\Collection
    {
        if (!$canViewAll) {
            $code = strtoupper((string) $userOfficeCode);
            $reports = $reports->where('user_office', $code)->values();
        } elseif (!empty($this->selectedOffice)) {
            $reports = $reports->where('user_office', strtoupper($this->selectedOffice))->values();
        }

        if ($this->reportCategory !== '') {
            $reports = $reports->where('category', $this->reportCategory)->values();
        }

        if ($this->reportFormat !== '') {
            $reports = $reports->where('format', $this->reportFormat)->values();
        }

        if (!empty($this->search)) {
            $needle = strtolower($this->search);
            $reports = $reports->filter(function ($report) use ($needle) {
                return str_contains(strtolower($report->report_token), $needle)
                    || str_contains(strtolower($report->title), $needle)
                    || str_contains(strtolower($report->category), $needle)
                    || str_contains(strtolower((string) ($report->sub_category ?? '')), $needle)
                    || str_contains(strtolower($report->file_name), $needle);
            })->values();
        }

        return $reports->sortByDesc('date_added')->values();
    }

    public function with(): array
    {
        $user = Auth::user();
        $perms = $user?->permissions;

        $canViewAll = RegisterQueryHelper::isFullDcsUser();
        $userOfficeCode = $user?->details?->office?->office_code;
        $hasOfficeAccess = !empty($userOfficeCode) || $canViewAll;

        if (!$hasOfficeAccess) {
            return [
                'reports' => new LengthAwarePaginator([], 0, $this->perPage),
                'offices' => [],
                'canViewAll' => false,
                'userOfficeCode' => null,
                'selectedReport' => null,
                'hasOfficeAccess' => false,
                'totalReports' => 0,
            ];
        }

        $offices = [];
        if ($canViewAll) {
            $offices = DB::table('office')
                ->select('office_code', 'office_name')
                ->where('is_active', true)
                ->whereNotIn('office_code', ['ORIGIN', '[H]'])
                ->orderBy('office_name', 'asc')
                ->get();
        }

        $allReports = $this->filterReports(
            DocumentStorageService::collectDcsGeneratedReportEntries(),
            $canViewAll,
            $userOfficeCode
        );

        $page = $this->getPage();
        $reports = new LengthAwarePaginator(
            $allReports->slice(($page - 1) * $this->perPage, $this->perPage)->values(),
            $allReports->count(),
            $this->perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );

        $selectedReport = $this->selectedReportId
            ? $allReports->firstWhere('id', $this->selectedReportId)
            : null;

        return [
            'reports' => $reports,
            'offices' => $offices,
            'canViewAll' => $canViewAll,
            'userOfficeCode' => $userOfficeCode,
            'selectedReport' => $selectedReport,
            'hasOfficeAccess' => true,
            'totalReports' => $allReports->count(),
        ];
    }
}; ?>

<div x-data="{ layoutOpen: false }">
@if(isset($hasOfficeAccess) && !$hasOfficeAccess)
    <main class="mf-page">
        <div class="mf-restricted">
            <i class="fa-solid fa-lock"></i>
            <h3>Manage Files access restricted</h3>
            <p>Your account is not assigned to an office. An administrator must assign your office before you can browse saved reports.</p>
            <a href="{{ route('dcs', absolute: false) }}" class="mf-btn-primary">
                <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </main>
@else
    <main class="mf-page">
        <header class="mf-header">
            <div class="mf-header-left">
                <div class="mf-breadcrumb">Document Control System / <span>Manage Files</span></div>
                <h1>Manage Files</h1>
                <p class="mf-subtitle">Saved PDF and CSV exports from Generate Report.</p>
            </div>
            <span class="mf-count-badge">{{ number_format($totalReports) }} saved report{{ $totalReports === 1 ? '' : 's' }}</span>
        </header>

        @if(!$canViewAll && $userOfficeCode)
            <div class="mf-office-banner">
                Showing reports for your office only: <strong>{{ $userOfficeCode }}</strong>
            </div>
        @endif

        <section class="mf-toolbar">
            <div class="mf-search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search report ID, title, or file…">
            </div>

            <div class="mf-toolbar-actions">
                <div class="mf-layout-menu" @click.outside="layoutOpen = false">
                    <button type="button" class="mf-layout-trigger" @click="layoutOpen = !layoutOpen" aria-haspopup="true" :aria-expanded="layoutOpen">
                        <i class="fa-solid fa-table-cells-large"></i>
                        View
                        <i class="fa-solid fa-chevron-down mf-layout-chevron"></i>
                    </button>
                    <div class="mf-layout-dropdown" x-show="layoutOpen" x-transition x-cloak>
                        <button type="button" class="mf-layout-option {{ $layoutMode === 'tiles' ? 'active' : '' }}" wire:click="setLayoutMode('tiles')" @click="layoutOpen = false">
                            <i class="fa-solid fa-grip"></i>
                            <span>Tiles</span>
                            @if($layoutMode === 'tiles')<i class="fa-solid fa-check mf-layout-check"></i>@endif
                        </button>
                        <button type="button" class="mf-layout-option {{ $layoutMode === 'list' ? 'active' : '' }}" wire:click="setLayoutMode('list')" @click="layoutOpen = false">
                            <i class="fa-solid fa-list"></i>
                            <span>List</span>
                            @if($layoutMode === 'list')<i class="fa-solid fa-check mf-layout-check"></i>@endif
                        </button>
                        <button type="button" class="mf-layout-option {{ $layoutMode === 'details' ? 'active' : '' }}" wire:click="setLayoutMode('details')" @click="layoutOpen = false">
                            <i class="fa-solid fa-table-list"></i>
                            <span>Details</span>
                            @if($layoutMode === 'details')<i class="fa-solid fa-check mf-layout-check"></i>@endif
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <section class="mf-inline-filters">
            @if($canViewAll)
                <div class="mf-inline-filter">
                    <label for="mf-office">Office</label>
                    <select id="mf-office" class="mf-select" wire:model.live="selectedOffice">
                        <option value="">All offices</option>
                        @foreach($offices as $office)
                            <option value="{{ $office->office_code }}">{{ $office->office_name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="mf-inline-filter">
                <label for="mf-report-cat">Report type</label>
                <select id="mf-report-cat" class="mf-select" wire:model.live="reportCategory">
                    @foreach($reportCategoryOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mf-inline-filter">
                <label for="mf-report-fmt">Format</label>
                <select id="mf-report-fmt" class="mf-select" wire:model.live="reportFormat">
                    <option value="">All formats</option>
                    <option value="pdf">PDF</option>
                    <option value="csv">CSV</option>
                </select>
            </div>
            @if($this->hasActiveFilters())
                <button type="button" class="mf-btn-outline mf-btn-inline-clear" wire:click="resetFilters">Clear filters</button>
            @endif
        </section>

        <div class="mf-body {{ $selectedReport ? 'mf-has-drawer' : '' }}">
            <div class="mf-content">
                <section class="mf-panel">
                    <div class="mf-panel-head mf-panel-head-compact">
                        <h2><i class="fa-solid fa-file-export"></i> Saved reports</h2>
                    </div>

                    @if($layoutMode === 'tiles')
                        <div class="mf-tiles-grid">
                            @forelse($reports as $report)
                                <article class="mf-tile mf-tile-report {{ $selectedReportId === $report->id ? 'selected' : '' }}"
                                         wire:click="selectReport({{ $report->id }})"
                                         wire:key="mf-rtile-{{ $report->id }}">
                                    <div class="mf-tile-icon mf-tile-icon-{{ $report->format }}">
                                        <i class="fa-solid fa-file-{{ $report->format === 'csv' ? 'csv' : 'pdf' }}"></i>
                                    </div>
                                    <div class="mf-tile-body">
                                        <div class="mf-tile-title">{{ $report->title }}</div>
                                        <div class="mf-tile-line mono">{{ $report->report_token }}</div>
                                        <div class="mf-tile-line mf-tile-muted">
                                            {{ strtoupper($report->format) }}
                                            @if($report->row_count) · {{ number_format($report->row_count) }} rows @endif
                                        </div>
                                        <div class="mf-tile-line mf-tile-muted">
                                            @if($report->date_added){{ \Carbon\Carbon::parse($report->date_added)->format('M j, Y g:i A') }}@endif
                                        </div>
                                        <span class="mf-tag mf-tag-report mf-tag-report-{{ $report->category }} mf-tile-tag">{{ strtoupper($report->category) }}</span>
                                    </div>
                                </article>
                            @empty
                                <div class="mf-empty mf-empty-grid-span">
                                    <i class="fa-regular fa-file-lines"></i>
                                    <h3>No saved reports yet</h3>
                                    <p>Export a PDF or CSV from Generate Report — it will appear here automatically.</p>
                                </div>
                            @endforelse
                        </div>
                    @elseif($layoutMode === 'list')
                        <div class="mf-list-rows">
                            @forelse($reports as $report)
                                <article class="mf-list-row {{ $selectedReportId === $report->id ? 'selected' : '' }}"
                                         wire:click="selectReport({{ $report->id }})"
                                         wire:key="mf-rlist-{{ $report->id }}">
                                    <i class="fa-solid fa-file-{{ $report->format === 'csv' ? 'csv' : 'pdf' }} mf-list-icon mf-list-icon-{{ $report->format }}"></i>
                                    <span class="mf-list-primary mono">{{ $report->report_token }}</span>
                                    <span class="mf-list-title">{{ $report->title }}</span>
                                    <span class="mf-list-meta">{{ strtoupper($report->category) }}</span>
                                    <span class="mf-list-meta">{{ strtoupper($report->format) }}</span>
                                    <span class="mf-list-date">@if($report->date_added){{ \Carbon\Carbon::parse($report->date_added)->format('M j, Y') }}@else—@endif</span>
                                </article>
                            @empty
                                <div class="mf-empty">
                                    <i class="fa-regular fa-file-lines"></i>
                                    <h3>No saved reports yet</h3>
                                    <p>Export a PDF or CSV from Generate Report — it will appear here automatically.</p>
                                </div>
                            @endforelse
                        </div>
                    @else
                        <div class="mf-table-wrap">
                            <table class="mf-table">
                                <thead>
                                    <tr>
                                        <th>Report ID</th>
                                        <th>Title</th>
                                        <th>Category</th>
                                        <th>Format</th>
                                        <th>Rows</th>
                                        <th>Office</th>
                                        <th>Generated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($reports as $report)
                                        <tr class="{{ $selectedReportId === $report->id ? 'selected' : '' }}"
                                            wire:click="selectReport({{ $report->id }})"
                                            wire:key="mf-rdetail-{{ $report->id }}">
                                            <td class="mono">{{ $report->report_token }}</td>
                                            <td>{{ $report->title }}</td>
                                            <td><span class="mf-tag mf-tag-report mf-tag-report-{{ $report->category }}">{{ strtoupper($report->category) }}</span></td>
                                            <td>{{ strtoupper($report->format) }}</td>
                                            <td>{{ number_format($report->row_count) }}</td>
                                            <td title="{{ $report->office_name ?: '' }}">{{ $report->user_office ?: '—' }}</td>
                                            <td>@if($report->date_added){{ \Carbon\Carbon::parse($report->date_added)->format('M j, Y g:i A') }}@else—@endif</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7">
                                                <div class="mf-empty mf-empty-compact">
                                                    <i class="fa-regular fa-file-lines"></i>
                                                    <h3>No saved reports yet</h3>
                                                    <p>Export a PDF or CSV from Generate Report — it will appear here automatically.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if($reports->hasPages() || $reports->total() > 0)
                        <div class="mf-pagination">
                            <div class="mf-pagination-summary">
                                Showing <strong>{{ $reports->firstItem() ?? 0 }}</strong>–<strong>{{ $reports->lastItem() ?? 0 }}</strong>
                                of <strong>{{ number_format($reports->total()) }}</strong>
                            </div>
                            <div class="mf-pagination-controls">
                                <select class="mf-select" wire:model.live="perPage" aria-label="Reports per page">
                                    <option value="8">8 / page</option>
                                    <option value="12">12 / page</option>
                                    <option value="24">24 / page</option>
                                    <option value="50">50 / page</option>
                                </select>
                                {{ $reports->links() }}
                            </div>
                        </div>
                    @endif
                </section>
            </div>

            @if($selectedReport)
                <aside class="mf-drawer">
                    <div class="mf-drawer-head">
                        <h3>Report details</h3>
                        <button type="button" class="mf-drawer-close" wire:click="closeInspector" aria-label="Close">&times;</button>
                    </div>
                    <div class="mf-drawer-body">
                        <div class="mf-field">
                            <label>Report ID</label>
                            <span class="mono">{{ $selectedReport->report_token }}</span>
                        </div>
                        <div class="mf-field">
                            <label>Title</label>
                            <span>{{ $selectedReport->title }}</span>
                        </div>
                        <div class="mf-field">
                            <label>Category</label>
                            <span>{{ ucfirst($selectedReport->category) }}@if($selectedReport->sub_category) — {{ str_replace('_', ' ', $selectedReport->sub_category) }}@endif</span>
                        </div>
                        <div class="mf-field">
                            <label>Format</label>
                            <span>{{ strtoupper($selectedReport->format) }}</span>
                        </div>
                        <div class="mf-field">
                            <label>Rows exported</label>
                            <span>{{ number_format($selectedReport->row_count) }}</span>
                        </div>
                        <div class="mf-field">
                            <label>Generated by</label>
                            <span>
                                @if($selectedReport->first_name || $selectedReport->last_name)
                                    {{ trim($selectedReport->first_name . ' ' . $selectedReport->last_name) }}
                                @else
                                    —
                                @endif
                            </span>
                        </div>
                        <div class="mf-field">
                            <label>Date</label>
                            <span>@if($selectedReport->date_added){{ \Carbon\Carbon::parse($selectedReport->date_added)->format('M j, Y g:i A') }}@else—@endif</span>
                        </div>
                        <div class="mf-field">
                            <label>Storage path</label>
                            <span class="path">{{ $selectedReport->file_path }}</span>
                        </div>
                        @php $reportUrl = RegisterQueryHelper::scanUrl($selectedReport->file_path); @endphp
                        @if($reportUrl)
                            <a href="{{ $reportUrl }}" target="_blank" rel="noopener" class="mf-btn-outline">
                                <i class="fa-solid fa-up-right-from-square"></i> Open report
                            </a>
                        @endif
                    </div>
                </aside>
            @endif
        </div>
    </main>
@endif
</div>
