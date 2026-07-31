<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Pending List')] class extends Component {
    public string $search = '';
    public string $activeTab = 'series'; // 'series' or 'files'
    public string $statusFilter = '';

    // Modal View Properties
    public bool $showDetailModal = false;
    public ?object $selectedCluster = null;
    public array $clusterItems = [];

    public function mount(): void
    {
        $perms = Auth::user()?->permissions;
        if (!$perms || (!(bool)($perms->is_sadm ?? false) && !(bool)($perms->can_access_rdp ?? true))) {
            redirect()->route('rdp')->send();
            return;
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->search = '';
        $this->statusFilter = '';
    }

    public function openDetailModal(int $clusterId, string $type): void
    {
        if ($type === 'series') {
            $cluster = DB::table('rdp_pending_record_series')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record_series.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin('office', 'rdp_pending_record_series.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record_series.created_by', '=', 'account_details.account_id')
                ->where('rdp_pending_record_series.cluster_id', $clusterId)
                ->select([
                    'rdp_pending_record_series.*',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name")
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

                $this->selectedCluster = $cluster;
                $this->clusterItems = $items;
                $this->showDetailModal = true;
            }
        } else {
            $cluster = DB::table('rdp_pending_record')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin('office', 'rdp_pending_record.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record.created_by', '=', 'account_details.account_id')
                ->where('rdp_pending_record.cluster_id', $clusterId)
                ->select([
                    'rdp_pending_record.*',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name")
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

                $this->selectedCluster = $cluster;
                $this->clusterItems = $items;
                $this->showDetailModal = true;
            }
        }
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedCluster = null;
        $this->clusterItems = [];
    }

    public function with(): array
    {
        $statuses = DB::table('rdp_pending_status')->where('is_active', true)->get();

        if ($this->activeTab === 'series') {
            $query = DB::table('rdp_pending_record_series')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record_series.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin('office', 'rdp_pending_record_series.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record_series.created_by', '=', 'account_details.account_id')
                ->select([
                    'rdp_pending_record_series.*',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("(SELECT COUNT(*) FROM rdp_grouped_record_series WHERE group_head = rdp_pending_record_series.cluster_id) as total_items")
                ]);

            if (!empty($this->statusFilter)) {
                $query->where('rdp_pending_record_series.status_id', $this->statusFilter);
            }

            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $query->where(function($q) use ($term) {
                    $q->where('rdp_pending_record_series.cluster_name', 'ILIKE', $term)
                      ->orWhere('office.office_name', 'ILIKE', $term);
                });
            }

            $clusters = $query->orderBy('rdp_pending_record_series.created_at', 'desc')->get();
        } else {
            $query = DB::table('rdp_pending_record')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin('office', 'rdp_pending_record.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record.created_by', '=', 'account_details.account_id')
                ->select([
                    'rdp_pending_record.*',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("(SELECT COUNT(*) FROM rdp_grouped_record WHERE group_head = rdp_pending_record.cluster_id) as total_items")
                ]);

            if (!empty($this->statusFilter)) {
                $query->where('rdp_pending_record.status_id', $this->statusFilter);
            }

            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $query->where(function($q) use ($term) {
                    $q->where('rdp_pending_record.cluster_name', 'ILIKE', $term)
                      ->orWhere('office.office_name', 'ILIKE', $term);
                });
            }

            $clusters = $query->orderBy('rdp_pending_record.created_at', 'desc')->get();
        }

        return [
            'clusters' => $clusters,
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
            padding: 24px;
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
            margin: 0 0 6px 0;
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
        .tab-buttons {
            display: flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 8px;
            gap: 4px;
        }
        .tab-btn {
            border: none;
            background: transparent;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
        }
        .tab-btn.active {
            background: #ffffff;
            color: #2563eb;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .search-input, .select-filter {
            padding: 9px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }
        .search-input { width: 260px; }
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
        .status-1 { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; } /* Pending */
        .status-2 { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; } /* Approved */
        .status-3 { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; } /* Rejected */
        .status-4 { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; } /* Returned */
        .btn-view {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
        }
        .btn-view:hover { background: #dbeafe; }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 999;
        }
        .modal-card {
            background: #ffffff;
            border-radius: 12px;
            width: 750px;
            max-width: 92vw;
            max-height: 85vh;
            overflow-y: auto;
            padding: 24px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 12px;
        }
        .modal-title { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0; }
        .btn-close-modal {
            background: none; border: none; font-size: 20px; color: #64748b; cursor: pointer;
        }
    </style>

    <div class="header-card">
        <div class="header-title">
            <h1>Pending Records & Series List</h1>
            <p>Track office submissions awaiting evaluation and approval for the Records Disposition Program</p>
        </div>
        <div class="controls-group">
            <div class="tab-buttons">
                <button wire:click="setTab('series')" class="tab-btn {{ $activeTab === 'series' ? 'active' : '' }}">Record Series</button>
                <button wire:click="setTab('files')" class="tab-btn {{ $activeTab === 'files' ? 'active' : '' }}">Record Files</button>
            </div>
            <select wire:model.live="statusFilter" class="select-filter">
                <option value="">All Statuses</option>
                @foreach($statuses as $st)
                    <option value="{{ $st->id }}">{{ $st->status_name }}</option>
                @endforeach
            </select>
            <input type="text" wire:model.live.debounce.250ms="search" class="search-input" placeholder="Search cluster or office...">
        </div>
    </div>

    <div class="pending-table-wrapper">
        <table class="pending-table">
            <thead>
                <tr>
                    <th style="width: 80px;">ID</th>
                    <th>Cluster Name</th>
                    <th>Submitting Office</th>
                    <th>Submitted By</th>
                    <th style="width: 110px; text-align: center;">Total Items</th>
                    <th style="width: 160px;">Status</th>
                    <th style="width: 140px;">Date Submitted</th>
                    <th style="width: 110px; text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($clusters as $c)
                    <tr>
                        <td><strong>#{{ $c->cluster_id }}</strong></td>
                        <td><strong>{{ $c->cluster_name }}</strong></td>
                        <td>{{ $c->office_name ?? $c->office ?? 'N/A' }}</td>
                        <td>{{ $c->submitter_name ?: 'System User' }}</td>
                        <td style="text-align: center;"><strong>{{ $c->total_items }}</strong></td>
                        <td>
                            <span class="status-badge status-{{ $c->status_id }}">
                                {{ $c->status_name ?? 'Pending' }}
                            </span>
                        </td>
                        <td>{{ \Carbon\Carbon::parse($c->created_at)->format('M d, Y g:i A') }}</td>
                        <td style="text-align: center;">
                            <button wire:click="openDetailModal({{ $c->cluster_id }}, '{{ $activeTab }}')" class="btn-view">View Details</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #64748b;">
                            No pending {{ $activeTab === 'series' ? 'record series' : 'record files' }} found matching your criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($showDetailModal && $selectedCluster)
        <div class="modal-overlay">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h3 class="modal-title">Cluster Details: {{ $selectedCluster->cluster_name }}</h3>
                        <span style="font-size: 13px; color: #64748b;">Submitted by {{ $selectedCluster->office_name ?? $selectedCluster->office }}</span>
                    </div>
                    <button wire:click="closeDetailModal" class="btn-close-modal">&times;</button>
                </div>
                <div style="margin-bottom: 16px;">
                    <span class="status-badge status-{{ $selectedCluster->status_id }}">
                        Status: {{ $selectedCluster->status_name }}
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
</div>
