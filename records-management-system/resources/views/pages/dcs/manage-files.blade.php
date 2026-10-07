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
    public string $folder = 'DCS';
    public int $filePage = 1;
    public int $filePerPage = 15;

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
        $this->filePage = 1;
        $this->selectedReportId = null;
    }

    public function updatedFilePerPage(): void
    {
        if (! in_array($this->filePerPage, [10, 15, 25, 50, 100], true)) {
            $this->filePerPage = 15;
        }
        $this->filePage = 1;
    }

    public function goToFilePage(int $page): void
    {
        $this->filePage = max(1, $page);
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

    public function openFolder(string $path): void
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '.' || str_contains($path, '..')) {
            $path = '';
        }
        $this->folder = $path;
        $this->selectedReportId = null;
        $this->filePage = 1;
        $this->resetPage();
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
            $needle = $this->search;
            $reports = $reports->filter(function ($report) use ($needle) {
                $hay = implode(' ', [
                    $report->report_token,
                    $report->title,
                    $report->category,
                    (string) ($report->sub_category ?? ''),
                    $report->file_name,
                ]);

                return RegisterQueryHelper::looseSearchScore($needle, $hay) > 0;
            })->sortByDesc(function ($report) use ($needle) {
                $hay = implode(' ', [
                    $report->report_token,
                    $report->title,
                    $report->category,
                    (string) ($report->sub_category ?? ''),
                    $report->file_name,
                ]);

                return RegisterQueryHelper::looseSearchScore($needle, $hay);
            })->values();
        } else {
            $reports = $reports->sortByDesc('date_added')->values();
        }

        return $reports;
    }

    public function with(): array
    {
        $user = Auth::user();
        $perms = $user?->permissions;

        $canViewAll = RegisterQueryHelper::canViewAllDocuments();
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

        return [
            'reports' => new LengthAwarePaginator([], 0, $this->perPage),
            'offices' => [],
            'canViewAll' => $canViewAll,
            'userOfficeCode' => $userOfficeCode,
            'selectedReport' => null,
            'hasOfficeAccess' => true,
            'totalReports' => 0,
            'drive' => $this->driveListing(),
        ];
    }

    private function driveListing(): array
    {
        $current = trim(str_replace('\\', '/', $this->folder), '/');
        if ($current === '.' || str_contains($current, '..')) {
            $current = '';
        }

        $allFiles = $this->collectDriveFiles();
        $folderSet = [];
        foreach (DocumentStorageService::dccStaticFolders() as $path) {
            $folderSet[$path] = $path;
        }
        foreach ($allFiles as $file) {
            $dir = $file['folder'];
            while ($dir !== '' && $dir !== '.' && $dir !== '/') {
                $folderSet[$dir] = $dir;
                $parent = $this->parentDrivePath($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }
        if ($current !== '' && ! isset($folderSet[$current])) {
            $current = '';
            $this->folder = '';
        }

        $needle = mb_strtolower(trim($this->search));
        $searching = $needle !== '';

        $folders = [];
        foreach ($folderSet as $path) {
            $parent = $this->parentDrivePath($path);
            $name = basename($path);
            if ($searching) {
                $hay = mb_strtolower($path.' '.$name);
                if (! str_contains($hay, $needle) && RegisterQueryHelper::looseSearchScore($this->search, $path.' '.$name) < 1) {
                    continue;
                }
            } elseif ($parent !== $current) {
                continue;
            }
            $count = 0;
            $prefix = $path.'/';
            foreach ($allFiles as $file) {
                if ($file['folder'] === $path || str_starts_with($file['path'], $prefix)) {
                    $count++;
                }
            }
            $folders[] = [
                'path' => $path,
                'name' => $this->driveDisplayName($name),
                'count' => $count,
            ];
        }
        usort($folders, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        $files = [];
        foreach ($allFiles as $file) {
            if ($searching) {
                $hay = $file['name'].' '.$file['title'].' '.$file['kind'].' '.$file['path'].' '.$file['folder'];
                if (! str_contains(mb_strtolower($hay), $needle) && RegisterQueryHelper::looseSearchScore($this->search, $hay) < 1) {
                    continue;
                }
            } elseif ($file['folder'] !== $current) {
                continue;
            }
            $files[] = $file;
        }

        $fileTotal = count($files);
        $filePerPage = in_array($this->filePerPage, [10, 15, 25, 50, 100], true) ? $this->filePerPage : 15;
        $fileLastPage = max(1, (int) ceil($fileTotal / $filePerPage));
        $filePage = min(max(1, $this->filePage), $fileLastPage);
        if ($filePage !== $this->filePage) {
            $this->filePage = $filePage;
        }
        $files = array_slice($files, ($filePage - 1) * $filePerPage, $filePerPage);

        $crumbs = [['path' => '', 'name' => 'My Drive']];
        if ($current !== '') {
            $built = '';
            foreach (explode('/', $current) as $part) {
                if ($part === '') {
                    continue;
                }
                $built = $built === '' ? $part : $built.'/'.$part;
                $crumbs[] = ['path' => $built, 'name' => $this->driveDisplayName($part)];
            }
        }

        return [
            'current' => $current,
            'crumbs' => $crumbs,
            'folders' => $folders,
            'files' => $files,
            'fileTotal' => count($allFiles),
            'folderFileTotal' => $fileTotal,
            'filePage' => $filePage,
            'filePerPage' => $filePerPage,
            'fileLastPage' => $fileLastPage,
            'searching' => $searching,
            'isReports' => ! $searching && (bool) preg_match('/(^|\/)(DCC_GENERATED_REPORTS|generated_reports)$/i', $current),
        ];
    }

    private function driveDisplayName(string $name): string
    {
        $name = preg_replace('/^DCC_/', '', $name) ?? $name;
        $name = str_replace('_', ' ', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return 'Folder';
        }

        $keep = ['DCS', 'DRF', 'DCN', 'ECOPY', 'DOCINFO'];
        $words = [];
        foreach (explode(' ', $name) as $word) {
            if ($word === '' || in_array($word, $keep, true) || preg_match('/^\d/', $word) || str_contains($word, '&')) {
                $words[] = $word;
                continue;
            }
            $words[] = mb_convert_case(mb_strtolower($word), MB_CASE_TITLE, 'UTF-8');
        }

        return implode(' ', $words);
    }

    private function parentDrivePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || ! str_contains($path, '/')) {
            return '';
        }

        return dirname($path);
    }

    private function collectDriveFiles(): array
    {
        $files = [];
        $seen = [];
        foreach (DocumentStorageService::DCS_SCAN_SOURCES as $source) {
            $table = $source['table'];
            $column = $source['column'];
            if (! \Illuminate\Support\Facades\Schema::hasTable($table) || ! \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                continue;
            }
            $select = ['id', $column];
            foreach (['doc_title', 'doc_no'] as $extra) {
                if (\Illuminate\Support\Facades\Schema::hasColumn($table, $extra)) {
                    $select[] = $extra;
                }
            }
            $rows = DB::table($table)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->orderByDesc('id')
                ->limit(5000)
                ->get($select);
            foreach ($rows as $row) {
                $this->pushDriveFile($files, $seen, (string) $row->{$column}, (string) ($source['category'] ?? ''), trim((string) ($row->doc_title ?? '')), trim((string) ($row->doc_no ?? '')));
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('dcs_generated_reports')) {
            $reports = DB::table('dcs_generated_reports')
                ->whereNotNull('file_path')
                ->where('file_path', '!=', '')
                ->orderByDesc('id')
                ->limit(2000)
                ->get(['file_path', 'title', 'file_name']);
            foreach ($reports as $report) {
                $this->pushDriveFile($files, $seen, (string) $report->file_path, 'generated_reports', trim((string) ($report->title ?: $report->file_name)), '');
            }
        }

        return $files;
    }

    private function pushDriveFile(array &$files, array &$seen, string $path, string $category, string $title, string $docNo): void
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || isset($seen[$path])) {
            return;
        }
        $seen[$path] = true;
        $name = basename($path);
        $label = $title !== '' ? $title : $name;
        if ($docNo !== '' && ! str_contains($label, $docNo)) {
            $label = $docNo.' — '.$label;
        }
        $files[] = [
            'path' => $path,
            'folder' => $this->driveFolder($path, $category),
            'name' => $name,
            'title' => $label,
            'kind' => $this->driveKindLabel($path, $category),
            'url' => $this->driveFileUrl($path),
        ];
    }

    private function driveFolder(string $path, string $category): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (preg_match('#^DCS/DCC_#i', $path)) {
            return $this->parentDrivePath($path);
        }

        $category = strtolower($category);
        $year = now()->format('Y');
        if (preg_match('/(20\d{2})/', basename($path), $matches)) {
            $year = $matches[1];
        }

        if (in_array($category, ['drf', 'syllabi'], true) || preg_match('#/(drf)(/|$)#i', $path)) {
            return "DCS/DCC_ECOPY/DCC_DRF_ECOPY/{$year}_DRF_ECOPY";
        }
        if (in_array($category, ['dcn', 'revisions'], true) || preg_match('#/(dcn)(/|$)#i', $path)) {
            return "DCS/DCC_ECOPY/DCC_DCN_ECOPY/{$year}_DCN_ECOPY";
        }
        if (in_array($category, ['distribution', 'retrieval'], true) || preg_match('#/(distribution|retrieval)(/|$)#i', $path)) {
            return "DCS/DCC_ECOPY/DCC_D&R_ECOPY/{$year}_D&R_ECOPY";
        }
        if ($category === 'generated_reports' || preg_match('#generated_reports#i', $path)) {
            return 'DCS/DCC_GENERATED_REPORTS';
        }
        if (preg_match('#stamp#i', $path)) {
            return 'DCS/DCC_STAMPED_DOCUMENTS';
        }
        if (preg_match('#random#i', $path)) {
            return 'DCS/DCC_RANDOM_CHECKING';
        }

        return 'DCS/DCC_MASTERLIST';
    }

    private function driveFileUrl(string $path): ?string
    {
        $normalized = DocumentStorageService::normalizeDcsScanPath($path);
        if ($normalized === null) {
            return null;
        }

        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'dcs.view-document',
            now()->addHour(),
            [
                'downloadAs' => DocumentStorageService::dcsDownloadFilename(null, $normalized),
                'path' => $normalized,
            ]
        );
    }

    private function driveKindLabel(string $path, string $category): string
    {
        $category = strtolower($category);
        if (str_contains($path, 'DRF_ECOPY') || $category === 'drf' || $category === 'syllabi') {
            return 'DRF';
        }
        if (str_contains($path, 'DCN_ECOPY') || $category === 'dcn' || $category === 'revisions') {
            return 'DCN';
        }
        if (str_contains($path, 'D&R_ECOPY') || in_array($category, ['distribution', 'retrieval'], true)) {
            return 'Distribution';
        }
        if (str_contains($path, 'MASTERLIST') || $category === 'masterlist') {
            return 'Masterlist';
        }
        if (str_contains($path, 'DOCINFO') || $category === 'docinfo') {
            return 'Document';
        }
        if (str_contains($path, 'STAMPED')) {
            return 'Stamped';
        }
        if (str_contains($path, 'GENERATED_REPORTS') || $category === 'generated_reports') {
            return 'Report';
        }

        return 'File';
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
            </div>
            <span class="mf-count-badge">{{ number_format($drive['fileTotal'] ?? 0) }} file{{ ($drive['fileTotal'] ?? 0) === 1 ? '' : 's' }}</span>
        </header>

        @if(!$canViewAll && $userOfficeCode)
            <div class="mf-office-banner">
                Showing reports for your office only: <strong>{{ $userOfficeCode }}</strong>
            </div>
        @endif

        <section class="mf-toolbar">
            <div class="mf-search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search documents, document numbers, or folders…">
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

        <div class="mf-stage {{ !empty($drive['isReports']) ? 'mf-stage-reports' : '' }}">
            <div class="mf-loading" wire:loading.flex wire:target="search, openFolder, setLayoutMode, filePerPage, goToFilePage">
                <div class="rpt-loading-card">
                    <div class="rpt-loading-spinner" aria-hidden="true"></div>
                    <h4>Loading files</h4>
                    <p>Opening this folder.</p>
                </div>
            </div>

            <nav class="mf-crumbs" aria-label="Folders">
                @foreach($drive['crumbs'] as $crumb)
                    @if(!$loop->first)<span class="mf-crumb-sep">/</span>@endif
                    <button type="button" class="mf-crumb" wire:click="openFolder('{{ $crumb['path'] }}')">{{ $crumb['name'] }}</button>
                @endforeach
                @if(!empty($drive['searching']))
                    <span class="mf-crumb-sep">/</span>
                    <span class="mf-crumb mf-crumb-current">Search results</span>
                @endif
            </nav>

            @if($layoutMode === 'details')
                <section class="mf-panel mf-file-panel">
                    <div class="mf-table-wrap">
                        <table class="mf-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Kind</th>
                                    <th>Items</th>
                                    <th>Location</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($drive['folders'] as $driveFolder)
                                    <tr wire:click="openFolder('{{ $driveFolder['path'] }}')" wire:key="mf-df-{{ md5($driveFolder['path']) }}" class="mf-drive-row">
                                        <td><i class="fa-solid fa-folder mf-drive-icon"></i> {{ $driveFolder['name'] }}</td>
                                        <td>Folder</td>
                                        <td>{{ $driveFolder['count'] }}</td>
                                        <td class="path">{{ $driveFolder['path'] }}</td>
                                    </tr>
                                @endforeach
                                @foreach($drive['files'] as $file)
                                    <tr wire:key="mf-dfile-{{ md5($file['path']) }}" class="mf-drive-row">
                                        <td>
                                            @if($file['url'])
                                                <a href="{{ $file['url'] }}" target="_blank" rel="noopener">{{ $file['title'] }}</a>
                                            @else
                                                {{ $file['title'] }}
                                            @endif
                                        </td>
                                        <td>{{ $file['kind'] }}</td>
                                        <td>{{ $file['name'] }}</td>
                                        <td class="path">{{ $file['folder'] !== '' ? $file['folder'] : 'My Drive' }}</td>
                                    </tr>
                                @endforeach
                                @if($drive['folders'] === [] && $drive['files'] === [] && ($drive['folderFileTotal'] ?? 0) === 0)
                                    <tr>
                                        <td colspan="4">
                                            <div class="mf-empty mf-empty-compact">
                                                <i class="fa-regular fa-folder-open"></i>
                                                <h3>{{ !empty($drive['searching']) ? 'No matching files' : 'This folder is empty' }}</h3>
                                                <p>{{ !empty($drive['searching']) ? 'Try another document number, title, or folder name.' : 'Documents saved to this Google Drive folder will show up here.' }}</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </section>
            @elseif($layoutMode === 'list')
                <section class="mf-list-rows" aria-label="Drive contents">
                    @foreach($drive['folders'] as $driveFolder)
                        <button type="button" class="mf-list-row" wire:click="openFolder('{{ $driveFolder['path'] }}')" wire:key="mf-lf-{{ md5($driveFolder['path']) }}">
                            <i class="fa-solid fa-folder mf-drive-icon"></i>
                            <span class="mf-list-title">{{ $driveFolder['name'] }}</span>
                            <span class="mf-list-meta">Folder</span>
                            <span class="mf-list-meta">{{ $driveFolder['count'] }}</span>
                            <span class="mf-list-date path">{{ $driveFolder['path'] }}</span>
                        </button>
                    @endforeach
                    @foreach($drive['files'] as $file)
                        <a class="mf-list-row" href="{{ $file['url'] ?: '#' }}" @if($file['url']) target="_blank" rel="noopener" @endif wire:key="mf-lfile-{{ md5($file['path']) }}">
                            <i class="fa-solid fa-file-pdf mf-list-icon mf-list-icon-pdf"></i>
                            <span class="mf-list-title">{{ $file['title'] }}</span>
                            <span class="mf-list-meta">{{ $file['kind'] }}</span>
                            <span class="mf-list-meta">{{ $file['name'] }}</span>
                            <span class="mf-list-date path">{{ $file['folder'] !== '' ? $file['folder'] : 'My Drive' }}</span>
                        </a>
                    @endforeach
                    @if($drive['folders'] === [] && $drive['files'] === [] && ($drive['folderFileTotal'] ?? 0) === 0)
                        <div class="mf-empty">
                            <i class="fa-regular fa-folder-open"></i>
                            <h3>{{ !empty($drive['searching']) ? 'No matching files' : 'This folder is empty' }}</h3>
                            <p>{{ !empty($drive['searching']) ? 'Try another document number, title, or folder name.' : 'Documents saved to this Google Drive folder will show up here.' }}</p>
                        </div>
                    @endif
                </section>
            @else
                @if($drive['folders'] !== [])
                    <section class="mf-folder-grid" aria-label="Folders">
                        @foreach($drive['folders'] as $driveFolder)
                            <button type="button" class="mf-folder" wire:click="openFolder('{{ $driveFolder['path'] }}')" wire:key="mf-folder-{{ md5($driveFolder['path']) }}">
                                <i class="fa-solid fa-folder"></i>
                                <span class="mf-folder-name">{{ $driveFolder['name'] }}</span>
                                <span class="mf-folder-count">{{ $driveFolder['count'] }}</span>
                            </button>
                        @endforeach
                    </section>
                @endif

                @if($drive['files'] !== [])
                    <section class="mf-panel mf-file-panel">
                        <div class="mf-panel-head mf-panel-head-compact">
                            <h2><i class="fa-solid fa-file"></i> {{ !empty($drive['searching']) ? 'Matching documents' : 'Documents in this folder' }}</h2>
                        </div>
                        <div class="mf-file-list">
                            @foreach($drive['files'] as $file)
                                <a class="mf-file-row" href="{{ $file['url'] ?: '#' }}" @if($file['url']) target="_blank" rel="noopener" @endif wire:key="mf-file-{{ md5($file['path']) }}">
                                    <i class="fa-solid fa-file-pdf"></i>
                                    <span class="mf-file-title">{{ $file['title'] }}</span>
                                    <span class="mf-file-kind">{{ $file['kind'] }}</span>
                                    <span class="mf-file-name">{{ $file['name'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @elseif($drive['folders'] === [] && ($drive['folderFileTotal'] ?? 0) === 0)
                    <div class="mf-empty">
                        <i class="fa-regular fa-folder-open"></i>
                        <h3>{{ !empty($drive['searching']) ? 'No matching files' : 'This folder is empty' }}</h3>
                        <p>{{ !empty($drive['searching']) ? 'Try another document number, title, or folder name.' : 'Documents saved to this Google Drive folder will show up here.' }}</p>
                    </div>
                @endif
            @endif
        </div>

        @if(($drive['folderFileTotal'] ?? 0) > 0)
            <div class="mf-pagination">
                <div class="mf-pagination-summary">
                    Showing <strong>{{ (($drive['filePage'] - 1) * $drive['filePerPage']) + 1 }}</strong>–<strong>{{ min($drive['folderFileTotal'], $drive['filePage'] * $drive['filePerPage']) }}</strong>
                    of <strong>{{ number_format($drive['folderFileTotal']) }}</strong>
                </div>
                <div class="mf-pagination-controls">
                    <label class="mf-page-size" for="mfFilePerPage">
                        Show
                        <select id="mfFilePerPage" class="mf-select" wire:model.live="filePerPage">
                            @foreach([10, 15, 25, 50, 100] as $size)
                                <option value="{{ $size }}">{{ $size }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="button" class="mf-pg" wire:click="goToFilePage({{ $drive['filePage'] - 1 }})" @disabled($drive['filePage'] <= 1) aria-label="Previous page">
                        <i class="fa-solid fa-chevron-left"></i>
                    </button>
                    <span class="mf-pagination-summary">Page {{ $drive['filePage'] }} of {{ $drive['fileLastPage'] }}</span>
                    <button type="button" class="mf-pg" wire:click="goToFilePage({{ $drive['filePage'] + 1 }})" @disabled($drive['filePage'] >= $drive['fileLastPage']) aria-label="Next page">
                        <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        @endif

    </main>
@endif
</div>
