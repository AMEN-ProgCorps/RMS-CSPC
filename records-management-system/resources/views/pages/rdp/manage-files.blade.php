<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Cockpit File Manager')] class extends Component {
    use WithPagination;

    public string $activeTab = 'dts'; // 'dts', 'rdp', 'shared'
    public string $viewMode = 'grid'; // 'grid' or 'list'
    public int $perPage = 12; // Dynamic pagination size
    public string $search = '';
    public string $selectedOffice = '';
    public string $sortBy = 'date_added'; // 'date_added', 'date_modified', 'document_name', 'office'
    public string $sortDirection = 'desc'; // 'asc' or 'desc'
    public ?string $selectedFileId = null;

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->selectedFileId = null;
        $this->resetPage();
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = $mode;
        $this->perPage = $mode === 'grid' ? 12 : 15;
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function toggleDirection(): void
    {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
    }

    public function selectFile(?string $fileId): void
    {
        if ($this->selectedFileId === $fileId) {
            $this->selectedFileId = null;
        } else {
            $this->selectedFileId = $fileId;
        }
    }

    public function closeInspector(): void
    {
        $this->selectedFileId = null;
    }

    public function with(): array
    {
        $user = Auth::user();
        $perms = $user?->permissions;
        
        $canViewAll = $perms && ($perms->is_sadm || !empty($perms->rdp_view_all_files));
        $userOfficeCode = $user?->details?->office?->office_code;

        $offices = [];
        if ($canViewAll) {
            $offices = DB::table('office')
                ->select('office_code', 'office_name')
                ->where('is_active', true)
                ->whereNotIn('office_code', ['ORIGIN', '[H]'])
                ->orderBy('office_name', 'asc')
                ->get();
        }

        $totalFilesCount = DB::table('document_data')->count();
        
        $dtsFilesCount = DB::table('document_data')
            ->where(function ($q) {
                $q->whereIn('document_id', function ($sub) {
                    $sub->select('document_id')->from('dts_sequence_list');
                })->orWhere('document_path', 'like', '%dts%');
            })->count();

        $rdpFilesCount = DB::table('document_data')
            ->where(function ($q) {
                $q->whereIn('document_id', function ($sub) {
                    $sub->select('upload_doc_id_handler')->from('rdp_record')->whereNotNull('upload_doc_id_handler');
                })->orWhereIn('document_id', function ($sub) {
                    $sub->select('parent_id')->from('rdp_document_record');
                })->orWhere('document_path', 'like', '%rdp%');
            })->count();

        $sharedFilesCount = DB::table('document_data')
            ->where(function ($q) {
                $q->whereIn('document_id', function ($sub) {
                    $sub->select('upload_doc_id_handler')
                        ->from('rdp_record')
                        ->whereIn('duplication_id', function ($dupSub) {
                            $dupSub->select('dup_id_manager')->from('rdp_duplication_section');
                        });
                })->orWhereIn('user_office', function ($dupSub) {
                    $dupSub->select('office_code')->from('rdp_duplication_section');
                });
            })->count();

        $query = DB::table('document_data')
            ->leftJoin('office', 'document_data.user_office', '=', 'office.office_code')
            ->leftJoin('account_details', 'document_data.uploaded_by', '=', 'account_details.account_id')
            ->select(
                'document_data.*',
                'office.office_name',
                'account_details.first_name',
                'account_details.last_name'
            );

        if (!$canViewAll) {
            $query->where('document_data.user_office', $userOfficeCode ?? '---');
        } elseif (!empty($this->selectedOffice)) {
            $query->where('document_data.user_office', $this->selectedOffice);
        }

        if ($this->activeTab === 'dts') {
            $query->where(function ($q) {
                $q->whereIn('document_data.document_id', function ($sub) {
                    $sub->select('document_id')->from('dts_sequence_list');
                })->orWhere('document_data.document_path', 'like', '%dts%');
            });
        } elseif ($this->activeTab === 'rdp') {
            $query->where(function ($q) {
                $q->whereIn('document_data.document_id', function ($sub) {
                    $sub->select('upload_doc_id_handler')->from('rdp_record')->whereNotNull('upload_doc_id_handler');
                })->orWhereIn('document_data.document_id', function ($sub) {
                    $sub->select('parent_id')->from('rdp_document_record');
                })->orWhere('document_data.document_path', 'like', '%rdp%');
            });
        } elseif ($this->activeTab === 'shared') {
            $query->where(function ($q) {
                $q->whereIn('document_data.document_id', function ($sub) {
                    $sub->select('upload_doc_id_handler')
                        ->from('rdp_record')
                        ->whereIn('duplication_id', function ($dupSub) {
                            $dupSub->select('dup_id_manager')->from('rdp_duplication_section');
                        });
                })->orWhereIn('document_data.user_office', function ($dupSub) {
                    $dupSub->select('office_code')->from('rdp_duplication_section');
                });
            });
        }

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('document_data.document_id', 'like', '%' . $this->search . '%')
                  ->orWhere('document_data.document_name', 'like', '%' . $this->search . '%')
                  ->orWhere('document_data.document_path', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->sortBy === 'office' && $canViewAll) {
            $query->orderBy('office.office_name', $this->sortDirection);
        } elseif ($this->sortBy === 'document_name') {
            $query->orderBy('document_data.document_name', $this->sortDirection);
        } elseif ($this->sortBy === 'date_modified') {
            $query->orderBy('document_data.date_modified', $this->sortDirection);
        } else {
            $query->orderBy('document_data.date_added', $this->sortDirection);
        }

        // Server-Side Database Pagination
        $files = $query->paginate($this->perPage);

        $selectedFile = null;
        if ($this->selectedFileId) {
            $selectedFile = DB::table('document_data')
                ->leftJoin('office', 'document_data.user_office', '=', 'office.office_code')
                ->leftJoin('account_details', 'document_data.uploaded_by', '=', 'account_details.account_id')
                ->select(
                    'document_data.*',
                    'office.office_name',
                    'account_details.first_name',
                    'account_details.last_name'
                )
                ->where('document_data.document_id', $this->selectedFileId)
                ->first();
        }

        return [
            'files' => $files,
            'offices' => $offices,
            'canViewAll' => $canViewAll,
            'userOfficeCode' => $userOfficeCode,
            'selectedFile' => $selectedFile,
            'stats' => [
                'total' => $totalFilesCount,
                'dts' => $dtsFilesCount,
                'rdp' => $rdpFilesCount,
                'shared' => $sharedFilesCount,
            ]
        ];
    }
};
?>

<div class="rms-container cockpit-wrapper">
    <style>
        .cockpit-wrapper {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #1e293b;
        }
        .cockpit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
        }
        .cockpit-header h2 {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .cockpit-header h2 svg {
            width: 28px;
            height: 28px;
            fill: #2563eb;
        }

        /* Cockpit Stat Cards Bar */
        .cockpit-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
        }
        .stat-info .stat-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
        }
        .stat-info .stat-value {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            margin-top: 4px;
        }
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stat-icon.total { background: #eff6ff; color: #2563eb; }
        .stat-icon.dts { background: #f0fdf4; color: #16a34a; }
        .stat-icon.rdp { background: #fff7ed; color: #ea580c; }
        .stat-icon.shared { background: #faf5ff; color: #9333ea; }
        .stat-icon svg { width: 24px; height: 24px; fill: currentColor; }

        /* Top Subsystem Bar */
        .subsystem-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #cbd5e1;
            padding-bottom: 4px;
        }
        .subsystem-tabs {
            display: flex;
            gap: 10px;
        }
        .subsystem-tab-btn {
            padding: 10px 24px;
            font-size: 14px;
            font-weight: 700;
            border-radius: 8px 8px 0 0;
            border: 1px solid transparent;
            border-bottom: none;
            background: #f1f5f9;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .subsystem-tab-btn:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
        .subsystem-tab-btn.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
        }
        .top-office-select {
            min-width: 220px;
            padding: 8px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            background-color: #fff;
            color: #1e293b;
            font-weight: 600;
            outline: none;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        /* Toolbar Controls */
        .files-toolbar {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .files-search {
            flex: 1;
            min-width: 260px;
            position: relative;
        }
        .files-search input {
            width: 100%;
            padding: 10px 14px 10px 36px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
            background: #fff;
        }
        .files-search input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .files-search svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            fill: #94a3b8;
        }
        .sort-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-select {
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            background-color: #fff;
            outline: none;
            cursor: pointer;
        }
        .direction-toggle-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 14px;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            transition: all 0.2s ease;
        }
        .direction-toggle-btn:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }
        .direction-toggle-btn svg {
            width: 16px;
            height: 16px;
            fill: #2563eb;
            transition: transform 0.2s ease;
        }
        .direction-toggle-btn.desc svg {
            transform: rotate(180deg);
        }
        .view-switcher {
            display: flex;
            background: #e2e8f0;
            border-radius: 8px;
            padding: 2px;
        }
        .view-btn {
            padding: 8px 12px;
            border: none;
            background: transparent;
            border-radius: 6px;
            cursor: pointer;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .view-btn.active {
            background: #fff;
            color: #2563eb;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }
        .view-btn svg { width: 18px; height: 18px; fill: currentColor; }

        /* Main Cockpit Layout */
        .cockpit-main-layout {
            display: flex;
            gap: 20px;
            align-items: flex-start;
        }
        .cockpit-content {
            flex: 1;
            min-width: 0;
        }

        /* GRID VIEW STYLING */
        .file-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
        }
        .file-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.02);
        }
        .file-card:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.12);
        }
        .file-card.selected {
            border-color: #2563eb;
            background-color: #f0f6ff;
            box-shadow: 0 0 0 2px #2563eb;
        }
        .file-card-preview {
            height: 100px;
            background: #f8fafc;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            border: 1px dashed #cbd5e1;
        }
        .file-card-preview svg { width: 42px; height: 42px; fill: #64748b; }
        .file-card-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .file-card-meta {
            font-size: 12px;
            color: #64748b;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .office-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            background-color: #eff6ff;
            color: #1e40af;
            border: 1px solid #dbeafe;
        }
        .user-office-banner {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background-color: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        /* LIST VIEW STYLING */
        .files-table-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            overflow: hidden;
        }
        .files-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }
        .files-table th {
            background: #f8fafc;
            padding: 14px 16px;
            font-weight: 600;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
            user-select: none;
        }
        .files-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            vertical-align: middle;
        }
        .files-table tr { cursor: pointer; }
        .files-table tr:hover td { background-color: #f8fafc; }
        .files-table tr.selected td { background-color: #eff6ff; }

        /* SIDE INSPECTOR PANEL */
        .cockpit-inspector {
            width: 320px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.06);
            position: sticky;
            top: 20px;
        }
        .inspector-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f1f5f9;
        }
        .inspector-header h3 {
            font-size: 16px;
            font-weight: 700;
            margin: 0;
            color: #0f172a;
        }
        .close-inspector-btn {
            background: transparent;
            border: none;
            font-size: 18px;
            color: #94a3b8;
            cursor: pointer;
        }
        .close-inspector-btn:hover { color: #0f172a; }
        .inspector-icon-large {
            height: 120px;
            background: #f8fafc;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            border: 1px solid #e2e8f0;
        }
        .inspector-icon-large svg { width: 56px; height: 56px; fill: #2563eb; }
        .inspector-field {
            margin-bottom: 14px;
        }
        .inspector-field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }
        .inspector-field span {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            word-break: break-all;
        }
        .btn-action-block {
            width: 100%;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            background: #2563eb;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            margin-top: 10px;
            transition: background 0.2s;
        }
        .btn-action-block:hover { background: #1d4ed8; }

        /* CUSTOM PAGINATION BAR */
        .cockpit-pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding: 14px 20px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
            flex-wrap: wrap;
            gap: 12px;
        }
        .pagination-summary {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
        }
        .pagination-summary strong {
            color: #0f172a;
        }
        .pagination-controls-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .per-page-select {
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            background: #fff;
            color: #334155;
            outline: none;
            cursor: pointer;
        }
        .empty-state { text-align: center; padding: 48px 24px; color: #64748b; }
    </style>

    <!-- Cockpit Title Header -->
    <div class="cockpit-header">
        <h2>
            <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zM7 10h2v7H7zm4-3h2v10h-2zm4 6h2v4h-2z"/></svg>
            RMS File Manager
        </h2>
        @if(!$canViewAll && $userOfficeCode)
            <div class="user-office-banner">
                <span>Restricted to your office: <strong>{{ $userOfficeCode }}</strong></span>
            </div>
        @endif
    </div>

    <!-- Cockpit Quick Metrics Bar -->
    <div class="cockpit-stats-grid">
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Total Registry Files</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
            <div class="stat-icon total">
                <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">DTS Tracking Files</div>
                <div class="stat-value">{{ number_format($stats['dts']) }}</div>
            </div>
            <div class="stat-icon dts">
                <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">RDP Inventory Files</div>
                <div class="stat-value">{{ number_format($stats['rdp']) }}</div>
            </div>
            <div class="stat-icon rdp">
                <svg viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Shared & Duplicated</div>
                <div class="stat-value">{{ number_format($stats['shared']) }}</div>
            </div>
            <div class="stat-icon shared">
                <svg viewBox="0 0 24 24"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"/></svg>
            </div>
        </div>
    </div>

    <!-- Subsystem Navigation Tabs & Top Office Selector -->
    <div class="subsystem-bar">
        <div class="subsystem-tabs">
            <button type="button" 
                    class="subsystem-tab-btn {{ $activeTab === 'dts' ? 'active' : '' }}" 
                    wire:click="setTab('dts')">
                DTS
            </button>

            <button type="button" 
                    class="subsystem-tab-btn {{ $activeTab === 'rdp' ? 'active' : '' }}" 
                    wire:click="setTab('rdp')">
                RDP
            </button>

            <button type="button" 
                    class="subsystem-tab-btn {{ $activeTab === 'shared' ? 'active' : '' }}" 
                    wire:click="setTab('shared')">
                Shared
            </button>
        </div>

        @if($canViewAll)
            <div>
                <select class="top-office-select" wire:model.live="selectedOffice">
                    <option value="">All Offices</option>
                    @foreach($offices as $office)
                        <option value="{{ $office->office_code }}">{{ $office->office_name }} ({{ $office->office_code }})</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    <!-- Toolbar Controls (Search + Attribute Sorting + Direction + View Switcher) -->
    <div class="files-toolbar">
        <div class="files-search">
            <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search by Document ID or Name...">
        </div>

        <div class="sort-controls">
            <!-- Sort Attribute Selector -->
            <select class="filter-select" wire:model.live="sortBy">
                <option value="date_added">Sort by Date Added</option>
                <option value="date_modified">Sort by Date Modified</option>
                <option value="document_name">Sort Alphabetically (A-Z)</option>
                @if($canViewAll)
                    <option value="office">Sort by Office Name</option>
                @endif
            </select>

            <!-- Direction Toggle Button -->
            <button type="button" 
                    class="direction-toggle-btn {{ $sortDirection === 'desc' ? 'desc' : 'asc' }}" 
                    wire:click="toggleDirection" 
                    title="Toggle Direction ({{ strtoupper($sortDirection) }})">
                <svg viewBox="0 0 24 24">
                    <path d="M12 4l-8 8h6v8h4v-8h6z"/>
                </svg>
                <span>{{ strtoupper($sortDirection) }}</span>
            </button>

            <!-- Grid vs List View Switcher -->
            <div class="view-switcher">
                <button type="button" 
                        class="view-btn {{ $viewMode === 'grid' ? 'active' : '' }}" 
                        wire:click="setViewMode('grid')"
                        title="Grid View">
                    <svg viewBox="0 0 24 24"><path d="M4 11h5V5H4v6zm0 7h5v-6H4v6zm6 0h5v-6h-5v6zm6 0h5v-6h-5v6zm-6-7h5V5h-5v6zm6-6v6h5V5h-5z"/></svg>
                </button>
                <button type="button" 
                        class="view-btn {{ $viewMode === 'list' ? 'active' : '' }}" 
                        wire:click="setViewMode('list')"
                        title="List View">
                    <svg viewBox="0 0 24 24"><path d="M4 14h4v-4H4v4zm0 5h4v-4H4v4zM4 9h4V5H4v4zm5 5h12v-4H9v4zm0 5h12v-4H9v4zM9 5v4h12V5H9z"/></svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Main Content & Cockpit Inspector Drawer Layout -->
    <div class="cockpit-main-layout">
        <div class="cockpit-content">
            @if($viewMode === 'grid')
                <!-- GRID VIEW -->
                <div class="file-cards-grid">
                    @forelse($files as $file)
                        <div class="file-card {{ $selectedFileId === $file->document_id ? 'selected' : '' }}"
                             wire:click="selectFile('{{ $file->document_id }}')">
                            <div class="file-card-preview">
                                <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                            </div>
                            <div class="file-card-title" title="{{ $file->document_name }}">
                                {{ $file->document_name }}
                            </div>
                            <div class="file-card-meta">
                                <span>{{ \Carbon\Carbon::parse($file->date_added)->format('M d, Y') }}</span>
                                @if($file->office_name)
                                    <span class="office-badge">{{ $file->office_name }}</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div style="grid-column: 1 / -1;" class="empty-state">
                            No files found matching the criteria in <strong>{{ strtoupper($activeTab) }}</strong>.
                        </div>
                    @endforelse
                </div>
            @else
                <!-- LIST VIEW -->
                <div class="files-table-card">
                    <table class="files-table">
                        <thead>
                            <tr>
                                <th>Document ID</th>
                                <th>Document Name</th>
                                <th>Office Files</th>
                                <th>Uploaded By</th>
                                <th>Date Added</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($files as $file)
                                <tr class="{{ $selectedFileId === $file->document_id ? 'selected' : '' }}"
                                    wire:click="selectFile('{{ $file->document_id }}')">
                                    <td style="font-weight: 600; font-family: monospace;">{{ $file->document_id }}</td>
                                    <td>{{ $file->document_name }}</td>
                                    <td>
                                        @if($file->office_name)
                                            <span class="office-badge">{{ $file->office_name }}</span>
                                        @else
                                            <span style="color: #94a3b8; font-style: italic;">Unassigned</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($file->first_name || $file->last_name)
                                            {{ $file->first_name }} {{ $file->last_name }}
                                        @else
                                            <span style="color: #94a3b8; font-style: italic;">System</span>
                                        @endif
                                    </td>
                                    <td>{{ \Carbon\Carbon::parse($file->date_added)->format('M d, Y h:i A') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            No files found in <strong>{{ strtoupper($activeTab) }}</strong>.
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif

            <!-- FAST SERVER-SIDE PAGINATION BAR -->
            @if($files->hasPages() || $files->total() > 0)
                <div class="cockpit-pagination-bar">
                    <div class="pagination-summary">
                        Showing <strong>{{ $files->firstItem() ?? 0 }}</strong> to <strong>{{ $files->lastItem() ?? 0 }}</strong> of <strong>{{ number_format($files->total()) }}</strong> items
                    </div>

                    <div class="pagination-controls-right">
                        <select class="per-page-select" wire:model.live="perPage">
                            <option value="12">12 per page</option>
                            <option value="24">24 per page</option>
                            <option value="48">48 per page</option>
                            <option value="96">96 per page</option>
                        </select>

                        <div>
                            {{ $files->links() }}
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <!-- COCKPIT FILE INSPECTOR (SIDE DRAWER) -->
        @if($selectedFile)
            <div class="cockpit-inspector">
                <div class="inspector-header">
                    <h3>File Details</h3>
                    <button type="button" class="close-inspector-btn" wire:click="closeInspector">&times;</button>
                </div>

                <div class="inspector-icon-large">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                </div>

                <div class="inspector-field">
                    <label>Document ID</label>
                    <span style="font-family: monospace;">{{ $selectedFile->document_id }}</span>
                </div>

                <div class="inspector-field">
                    <label>Document Name</label>
                    <span>{{ $selectedFile->document_name }}</span>
                </div>

                <div class="inspector-field">
                    <label>Subsystem Classification</label>
                    <span class="office-badge" style="background: #eff6ff; color: #2563eb;">{{ strtoupper($activeTab) }}</span>
                </div>

                <div class="inspector-field">
                    <label>Office Assignment</label>
                    <span>{{ $selectedFile->office_name ?? 'Unassigned' }}</span>
                </div>

                <div class="inspector-field">
                    <label>Uploaded By</label>
                    <span>
                        @if($selectedFile->first_name || $selectedFile->last_name)
                            {{ $selectedFile->first_name }} {{ $selectedFile->last_name }}
                        @else
                            System Admin
                        @endif
                    </span>
                </div>

                <div class="inspector-field">
                    <label>Date Added</label>
                    <span>{{ \Carbon\Carbon::parse($selectedFile->date_added)->format('M d, Y h:i:s A') }}</span>
                </div>

                <div class="inspector-field">
                    <label>Server Path</label>
                    <span style="font-size: 11px; color: #64748b;">{{ $selectedFile->document_path ?? 'Local Registry' }}</span>
                </div>

                <button type="button" class="btn-action-block">
                    View Metadata
                </button>
            </div>
        @endif
    </div>
</div>
