<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Pending List')] class extends Component {
    public string $search = '';
    public string $activeTab = 'all'; // 'all', 'nap1', 'nap2', 'nap3'
    public string $statusFilter = '';
    public string $layoutMode = 'table'; // 'table' or 'box'

    // Detail Modal Properties
    public bool $showDetailModal = false;
    public ?object $selectedCluster = null;
    public array $clusterItems = [];

    // Print Modal Properties
    public bool $showPrintModal = false;
    public ?object $printCluster = null;
    public array $printItems = [];
    public string $agencyName = 'Camarines Sur Polytechnic Colleges';
    public string $agencyAddress = 'San Miguel, Nabua, Camarines Sur';
    public string $preparedBy = '';

    public function mount(): void
    {
        $perms = Auth::user()?->permissions;
        if (!$perms || (!(bool)($perms->is_sadm ?? false) && !(bool)($perms->can_access_rdp ?? true))) {
            redirect()->route('rdp')->send();
            return;
        }

        $details = Auth::user()?->details;
        if ($details) {
            $fullName = trim(($details->first_name ?? '') . ' ' . ($details->last_name ?? ''));
            $this->preparedBy = $fullName ?: (Auth::user()->username ?? 'Records Officer');
        }
    }

    public function toggleLayout(): void
    {
        $this->layoutMode = $this->layoutMode === 'table' ? 'box' : 'table';
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->search = '';
        $this->statusFilter = '';
    }

    public function openDetailModal(int $clusterId, string $formType): void
    {
        $cluster = null;
        $items = [];

        if ($formType === 'nap2' || $formType === 'NAP Form 2') {
            $cluster = DB::table('rdp_pending_record_series')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record_series.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin('office', 'rdp_pending_record_series.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record_series.created_by', '=', 'account_details.account_id')
                ->where('rdp_pending_record_series.cluster_id', $clusterId)
                ->select([
                    'rdp_pending_record_series.cluster_id',
                    'rdp_pending_record_series.cluster_name',
                    'rdp_pending_record_series.status_id',
                    'rdp_pending_record_series.office',
                    'rdp_pending_record_series.created_at',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("'NAP Form 2' as form_label")
                ])
                ->first();

            if ($cluster) {
                $items = DB::table('rdp_grouped_record_series')
                    ->join('rdp_record_series', 'rdp_grouped_record_series.record_series_id', '=', 'rdp_record_series.id')
                    ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
                    ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
                    ->where('rdp_grouped_record_series.group_head', $clusterId)
                    ->select([
                        'rdp_record_series.*',
                        'rdp_retention_period.active_period',
                        'rdp_retention_period.storage_period',
                        'rdp_retention_period.total_period',
                        'parent.series_title as parent_title'
                    ])
                    ->get()
                    ->toArray();
            }
        } else {
            $cluster = DB::table('rdp_pending_record')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin('office', 'rdp_pending_record.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record.created_by', '=', 'account_details.account_id')
                ->where('rdp_pending_record.cluster_id', $clusterId)
                ->select([
                    'rdp_pending_record.cluster_id',
                    'rdp_pending_record.cluster_name',
                    'rdp_pending_record.status_id',
                    'rdp_pending_record.office',
                    'rdp_pending_record.is_for_nap_one',
                    'rdp_pending_record.is_for_nap_three',
                    'rdp_pending_record.created_at',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'NAP Form 3' ELSE 'NAP Form 1' END as form_label")
                ])
                ->first();

            if ($cluster) {
                $items = DB::table('rdp_grouped_record')
                    ->join('rdp_record', 'rdp_grouped_record.record_id', '=', 'rdp_record.id')
                    ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
                    ->where('rdp_grouped_record.group_head', $clusterId)
                    ->select(['rdp_record.*', 'rdp_record_series.series_title'])
                    ->get()
                    ->toArray();
            }
        }

        if ($cluster) {
            $this->selectedCluster = $cluster;
            $this->clusterItems = $items;
            $this->showDetailModal = true;
        }
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedCluster = null;
        $this->clusterItems = [];
    }

    public function openPrintModal(int $clusterId, string $formType): void
    {
        $this->openDetailModal($clusterId, $formType);
        $this->printCluster = $this->selectedCluster;
        $this->printItems = $this->clusterItems;
        $this->showPrintModal = true;
    }

    public function closePrintModal(): void
    {
        $this->showPrintModal = false;
        $this->printCluster = null;
        $this->printItems = [];
    }

    public function with(): array
    {
        $statuses = DB::table('rdp_pending_status')->where('is_active', true)->get();
        $clustersCollection = collect();

        // 1. Fetch NAP Form 2 Series Clusters if tab is 'all' or 'nap2'
        if ($this->activeTab === 'all' || $this->activeTab === 'nap2') {
            $qSeries = DB::table('main_pending_id')
                ->join('rdp_pending_record_series', 'main_pending_id.id', '=', 'rdp_pending_record_series.cluster_id')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record_series.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin('office', 'rdp_pending_record_series.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record_series.created_by', '=', 'account_details.account_id')
                ->select([
                    'main_pending_id.id as main_id',
                    'rdp_pending_record_series.cluster_id',
                    'rdp_pending_record_series.cluster_name',
                    'rdp_pending_record_series.status_id',
                    'rdp_pending_record_series.office',
                    'rdp_pending_record_series.created_at',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("'NAP Form 2' as form_label"),
                    DB::raw("'nap2' as form_code"),
                    DB::raw("(SELECT COUNT(*) FROM rdp_grouped_record_series WHERE group_head = rdp_pending_record_series.cluster_id) as total_items")
                ]);

            if (!empty($this->statusFilter)) {
                $qSeries->where('rdp_pending_record_series.status_id', $this->statusFilter);
            }

            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $qSeries->where(function($q) use ($term) {
                    $q->where('rdp_pending_record_series.cluster_name', 'ILIKE', $term)
                      ->orWhere('office.office_name', 'ILIKE', $term);
                });
            }

            $clustersCollection = $clustersCollection->concat($qSeries->get());
        }

        // 2. Fetch Pending Record Clusters (NAP Form 1 or 3) if tab is 'all', 'nap1', or 'nap3'
        if ($this->activeTab === 'all' || $this->activeTab === 'nap1' || $this->activeTab === 'nap3') {
            $qRec = DB::table('main_pending_id')
                ->join('rdp_pending_record', 'main_pending_id.id', '=', 'rdp_pending_record.cluster_id')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin('office', 'rdp_pending_record.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record.created_by', '=', 'account_details.account_id')
                ->select([
                    'main_pending_id.id as main_id',
                    'rdp_pending_record.cluster_id',
                    'rdp_pending_record.cluster_name',
                    'rdp_pending_record.status_id',
                    'rdp_pending_record.office',
                    'rdp_pending_record.is_for_nap_one',
                    'rdp_pending_record.is_for_nap_three',
                    'rdp_pending_record.created_at',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'NAP Form 3' ELSE 'NAP Form 1' END as form_label"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'nap3' ELSE 'nap1' END as form_code"),
                    DB::raw("(SELECT COUNT(*) FROM rdp_grouped_record WHERE group_head = rdp_pending_record.cluster_id) as total_items")
                ]);

            if ($this->activeTab === 'nap1') {
                $qRec->where('rdp_pending_record.is_for_nap_one', true);
            } elseif ($this->activeTab === 'nap3') {
                $qRec->where('rdp_pending_record.is_for_nap_three', true);
            }

            if (!empty($this->statusFilter)) {
                $qRec->where('rdp_pending_record.status_id', $this->statusFilter);
            }

            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $qRec->where(function($q) use ($term) {
                    $q->where('rdp_pending_record.cluster_name', 'ILIKE', $term)
                      ->orWhere('office.office_name', 'ILIKE', $term);
                });
            }

            $clustersCollection = $clustersCollection->concat($qRec->get());
        }

        // Sort by main_id descending
        $sortedClusters = $clustersCollection->sortByDesc('main_id')->values();

        return [
            'clusters' => $sortedClusters,
            'statuses' => $statuses,
        ];
    }
}; ?>

<div class="pending-list-page">
    <style>
        .pending-list-page {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .header-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .header-title h1 {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 4px 0;
        }
        .header-title p {
            font-size: 14px;
            color: #64748b;
            margin: 0;
        }
        .controls-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        /* DTS-Styled Tabs Bar */
        .dts-tab-bar {
            display: flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 10px;
            gap: 4px;
            border: 1px solid #e2e8f0;
        }
        .dts-tab-btn {
            border: none;
            background: transparent;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .dts-tab-btn.active {
            background: #ffffff;
            color: #2563eb;
            box-shadow: 0 2px 4px rgba(37,99,235,0.1);
        }

        .layout-toggle-btn {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
        }
        .layout-toggle-btn:hover { background: #f8fafc; }

        .search-input, .select-filter {
            padding: 9px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }
        .search-input { width: 240px; }

        /* Table & Card Grid Views */
        .pending-table-wrapper {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .pending-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }
        .pending-table th {
            background: #1e293b;
            color: #ffffff;
            font-weight: 600;
            padding: 14px 16px;
            border-right: 1px solid #334155;
        }
        .pending-table td {
            padding: 14px 16px;
            color: #334155;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #f1f5f9;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-null, .status-1 { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; } /* Pending */
        .status-2 { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; } /* Approved */
        .status-3 { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; } /* Rejected */
        .status-4 { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; } /* Returned */

        .form-type-pill {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 6px;
        }

        .btn-view {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 4px;
        }
        .btn-view:hover { background: #dbeafe; }

        .btn-print {
            background: #1e293b;
            color: #ffffff;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-print:hover { background: #0f172a; }

        /* Box / Card Grid Layout */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        .cluster-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .cluster-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px -2px rgba(0,0,0,0.08);
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .card-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 4px 0 0 0;
        }
        .card-meta {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 16px;
            line-height: 1.5;
        }
        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
        }

        /* Modal Layout */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 999;
        }
        .modal-card {
            background: #ffffff;
            border-radius: 12px;
            width: 800px;
            max-width: 94vw;
            max-height: 88vh;
            overflow-y: auto;
            padding: 26px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
        }

        /* Print Modal & Media Query */
        @media print {
            body * { visibility: hidden; }
            .printable-report-area, .printable-report-area * { visibility: visible; }
            .printable-report-area { position: absolute; left: 0; top: 0; width: 100%; }
            .no-print { display: none !important; }
        }
    </style>

    <div class="header-card">
        <div class="header-title">
            <h1>Pending Records & Series List</h1>
            <p>Track office submissions awaiting evaluation and approval for the Records Disposition Program</p>
        </div>
        <div class="controls-group">
            <div class="dts-tab-bar">
                <button wire:click="setTab('all')" class="dts-tab-btn {{ $activeTab === 'all' ? 'active' : '' }}">ALL</button>
                <button wire:click="setTab('nap1')" class="dts-tab-btn {{ $activeTab === 'nap1' ? 'active' : '' }}">NAP Form 1</button>
                <button wire:click="setTab('nap2')" class="dts-tab-btn {{ $activeTab === 'nap2' ? 'active' : '' }}">NAP Form 2</button>
                <button wire:click="setTab('nap3')" class="dts-tab-btn {{ $activeTab === 'nap3' ? 'active' : '' }}">NAP Form 3</button>
            </div>

            <button wire:click="toggleLayout" class="layout-toggle-btn">
                @if($layoutMode === 'table')
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    Box View
                @else
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                    Table View
                @endif
            </button>

            <select wire:model.live="statusFilter" class="select-filter">
                <option value="">All Statuses</option>
                @foreach($statuses as $st)
                    <option value="{{ $st->id }}">{{ $st->status_name }}</option>
                @endforeach
            </select>
            <input type="text" wire:model.live.debounce.250ms="search" class="search-input" placeholder="Search cluster or office...">
        </div>
    </div>

    @if($layoutMode === 'table')
        <div class="pending-table-wrapper">
            <table class="pending-table">
                <thead>
                    <tr>
                        <th style="width: 90px;">Main ID</th>
                        <th>Cluster Name</th>
                        <th>Submitting Office</th>
                        <th>Submitted By</th>
                        <th style="width: 110px; text-align: center;">Total Items</th>
                        <th style="width: 150px;">Status</th>
                        <th style="width: 140px;">Date Submitted</th>
                        <th style="width: 160px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clusters as $c)
                        <tr>
                            <td><strong>#{{ $c->main_id }}</strong></td>
                            <td>
                                <span class="form-type-pill">{{ $c->form_label }}</span>
                                <strong>{{ $c->cluster_name }}</strong>
                            </td>
                            <td>{{ $c->office_name ?? $c->office ?? 'N/A' }}</td>
                            <td>{{ $c->submitter_name ?: 'System User' }}</td>
                            <td style="text-align: center;"><strong>{{ $c->total_items }}</strong></td>
                            <td>
                                <span class="status-badge status-{{ $c->status_id ?? 'null' }}">
                                    {{ $c->status_name ?? 'Draft Group' }}
                                </span>
                            </td>
                            <td>{{ \Carbon\Carbon::parse($c->created_at)->format('M d, Y g:i A') }}</td>
                            <td style="text-align: center;">
                                <button wire:click="openDetailModal({{ $c->cluster_id }}, '{{ $c->form_code }}')" class="btn-view">View</button>
                                <button wire:click="openPrintModal({{ $c->cluster_id }}, '{{ $c->form_code }}')" class="btn-print">Print</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 48px; color: #64748b;">
                                No pending clusters found matching your query.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="card-grid">
            @forelse($clusters as $c)
                <div class="cluster-card">
                    <div>
                        <div class="card-header">
                            <div>
                                <span class="form-type-pill">{{ $c->form_label }}</span>
                                <span style="font-size: 12px; font-weight: 700; color: #64748b;">#{{ $c->main_id }}</span>
                            </div>
                            <span class="status-badge status-{{ $c->status_id ?? 'null' }}">
                                {{ $c->status_name ?? 'Draft Group' }}
                            </span>
                        </div>
                        <h3 class="card-title">{{ $c->cluster_name }}</h3>
                        <div class="card-meta">
                            <div><strong>Office:</strong> {{ $c->office_name ?? $c->office ?? 'N/A' }}</div>
                            <div><strong>Submitted by:</strong> {{ $c->submitter_name ?: 'System User' }}</div>
                            <div><strong>Items:</strong> {{ $c->total_items }} records</div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <span style="font-size: 12px; color: #94a3b8;">{{ \Carbon\Carbon::parse($c->created_at)->format('M d, Y') }}</span>
                        <div>
                            <button wire:click="openDetailModal({{ $c->cluster_id }}, '{{ $c->form_code }}')" class="btn-view">View</button>
                            <button wire:click="openPrintModal({{ $c->cluster_id }}, '{{ $c->form_code }}')" class="btn-print">Print</button>
                        </div>
                    </div>
                </div>
            @empty
                <div style="grid-column: 1 / -1; text-align: center; padding: 48px; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; color: #64748b;">
                    No pending clusters found matching your query.
                </div>
            @endforelse
        </div>
    @endif

    {{-- Detail Modal --}}
    @if($showDetailModal && $selectedCluster)
        <div class="modal-overlay">
            <div class="modal-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <div>
                        <h3 style="font-size: 18px; font-weight: 700; color: #0f172a; margin: 0;">
                            [{{ $selectedCluster->form_label }}] {{ $selectedCluster->cluster_name }}
                        </h3>
                        <span style="font-size: 13px; color: #64748b;">Submitted by {{ $selectedCluster->office_name ?? $selectedCluster->office }}</span>
                    </div>
                    <button wire:click="closeDetailModal" style="background: none; border: none; font-size: 20px; color: #64748b; cursor: pointer;">&times;</button>
                </div>
                <div style="margin-bottom: 16px;">
                    <span class="status-badge status-{{ $selectedCluster->status_id ?? 'null' }}">
                        Status: {{ $selectedCluster->status_name ?? 'Draft Group' }}
                    </span>
                </div>
                <table class="pending-table" style="font-size: 13px;">
                    <thead>
                        <tr>
                            <th>Item / Title</th>
                            <th>Details</th>
                            <th>Retention / Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($clusterItems as $item)
                            <tr>
                                <td>
                                    <strong>{{ $item->series_title ?? $item->doc_name ?? 'Untitled Item' }}</strong>
                                    @if(!empty($item->parent_title))
                                        <div style="font-size: 11px; color: #64748b;">Sub-series of: {{ $item->parent_title }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($item->item_number))
                                        <div>Item No: <strong>{{ sprintf('%03d', $item->item_number) }}</strong></div>
                                    @endif
                                    @if(isset($item->volume))
                                        <div>Volume: {{ $item->volume }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($item->total_period))
                                        <div>Retention: <strong>{{ $item->total_period }}</strong></div>
                                    @endif
                                    <div style="color: #64748b;">{{ $item->remarks ?? $item->description ?? '—' }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Printable Modal --}}
    @if($showPrintModal && $printCluster)
        <div class="modal-overlay no-print">
            <div class="modal-card" style="width: 900px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;" class="no-print">
                    <h3 style="font-size: 18px; font-weight: 700; color: #0f172a; margin: 0;">Print Preview — {{ $printCluster->cluster_name }}</h3>
                    <div>
                        <button onclick="window.print()" class="btn-print" style="padding: 8px 16px; margin-right: 8px;">Print Now</button>
                        <button wire:click="closePrintModal" class="btn-view" style="padding: 8px 16px;">Close</button>
                    </div>
                </div>

                <div class="printable-report-area" style="background: #ffffff; padding: 24px; font-family: 'Inter', sans-serif; color: #0f172a;">
                    <div style="text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 16px; margin-bottom: 24px;">
                        <h2 style="margin: 0; font-size: 20px; font-weight: 700;">{{ $agencyName }}</h2>
                        <p style="margin: 4px 0 0 0; font-size: 13px; color: #475569;">{{ $agencyAddress }}</p>
                        <h3 style="margin: 12px 0 0 0; font-size: 16px; font-weight: 700; text-transform: uppercase; color: #1e293b;">
                            RECORDS DISPOSITION PROGRAM — PENDING SUBMISSION EVALUATION REPORT
                        </h3>
                    </div>

                    <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 20px; line-height: 1.6;">
                        <div>
                            <div><strong>Cluster ID:</strong> #{{ $printCluster->cluster_id }}</div>
                            <div><strong>Cluster Name:</strong> {{ $printCluster->cluster_name }}</div>
                            <div><strong>Form Type:</strong> {{ $printCluster->form_label }}</div>
                        </div>
                        <div style="text-align: right;">
                            <div><strong>Submitting Office:</strong> {{ $printCluster->office_name ?? $printCluster->office }}</div>
                            <div><strong>Submitted By:</strong> {{ $printCluster->submitter_name ?: 'System User' }}</div>
                            <div><strong>Date:</strong> {{ \Carbon\Carbon::parse($printCluster->created_at)->format('F d, Y') }}</div>
                        </div>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 32px;" border="1" cellpadding="8">
                        <thead>
                            <tr style="background: #f1f5f9; text-align: left;">
                                <th style="width: 40px; text-align: center;">#</th>
                                <th>Item / Record Series Title</th>
                                <th>Details / Volume</th>
                                <th>Retention Period</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($printItems as $idx => $pi)
                                <tr>
                                    <td style="text-align: center;">{{ $idx + 1 }}</td>
                                    <td>
                                        <strong>{{ $pi->series_title ?? $pi->doc_name ?? 'Untitled' }}</strong>
                                        @if(!empty($pi->parent_title))
                                            <div style="font-size: 11px; color: #64748b;">Sub-series of: {{ $pi->parent_title }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $pi->volume ?? $pi->records_location ?? '—' }}</td>
                                    <td>{{ $pi->total_period ?? $pi->active_period ?? '—' }}</td>
                                    <td>{{ $pi->remarks ?? $pi->description ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div style="display: flex; justify-content: space-between; font-size: 13px; margin-top: 48px; padding-top: 24px;">
                        <div style="width: 240px; text-align: center;">
                            <div style="border-bottom: 1px solid #0f172a; padding-bottom: 4px; font-weight: 700;">{{ $preparedBy }}</div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Prepared By / Custodian</div>
                        </div>
                        <div style="width: 240px; text-align: center;">
                            <div style="border-bottom: 1px solid #0f172a; padding-bottom: 4px; font-weight: 700;">Records Officer / Evaluator</div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Approved & Verified By</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
