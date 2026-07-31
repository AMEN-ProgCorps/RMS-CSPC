<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Pending For Approval')] class extends Component {
    public string $search = '';
    public string $activeTab = 'series';

    // Approval Action Modal State
    public bool $showApprovalModal = false;
    public ?object $targetCluster = null;
    public array $clusterItems = [];
    public ?int $selectedSeriesTypeId = null;
    public string $approvalRemarks = '';

    public string $successMessage = '';
    public string $errorMessage = '';

    public function mount(): void
    {
        $perms = Auth::user()?->permissions;
        $isSadm = (bool)($perms->is_sadm ?? false);
        $canModifySeries = (bool)($perms->can_rdp_modify_series ?? true);

        if (!$perms || (!$isSadm && !$canModifySeries)) {
            redirect()->route('rdp')->send();
            return;
        }

        // Set default series type to CSPC
        $defaultType = DB::table('rdp_record_series_type')->where('shorted_type', 'CSPC')->first();
        if ($defaultType) {
            $this->selectedSeriesTypeId = $defaultType->id;
        }
    }

    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->search = '';
        $this->clearMessages();
    }

    public function openApprovalModal(int $clusterId, string $type): void
    {
        $this->clearMessages();
        if ($type === 'series') {
            $cluster = DB::table('rdp_pending_record_series')
                ->leftJoin('office', 'rdp_pending_record_series.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record_series.created_by', '=', 'account_details.account_id')
                ->where('rdp_pending_record_series.cluster_id', $clusterId)
                ->select([
                    'rdp_pending_record_series.*',
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

                $this->targetCluster = $cluster;
                $this->clusterItems = $items;
                $this->showApprovalModal = true;
            }
        } else {
            $cluster = DB::table('rdp_pending_record')
                ->leftJoin('office', 'rdp_pending_record.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record.created_by', '=', 'account_details.account_id')
                ->where('rdp_pending_record.cluster_id', $clusterId)
                ->select([
                    'rdp_pending_record.*',
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

                $this->targetCluster = $cluster;
                $this->clusterItems = $items;
                $this->showApprovalModal = true;
            }
        }
    }

    public function closeApprovalModal(): void
    {
        $this->showApprovalModal = false;
        $this->targetCluster = null;
        $this->clusterItems = [];
        $this->approvalRemarks = '';
    }

    public function approveCluster(): void
    {
        if (!$this->targetCluster) return;

        try {
            DB::beginTransaction();

            if ($this->activeTab === 'series') {
                // Update pending cluster status to 2 (Approved)
                DB::table('rdp_pending_record_series')
                    ->where('cluster_id', $this->targetCluster->cluster_id)
                    ->update([
                        'status_id'  => 2,
                        'updated_at' => now(),
                    ]);

                // Update grouped record series to be verified and assigned to selected series_type
                $seriesIds = array_column($this->clusterItems, 'id');
                if (!empty($seriesIds)) {
                    DB::table('rdp_record_series')
                        ->whereIn('id', $seriesIds)
                        ->update([
                            'is_verified' => true,
                            'series_type' => $this->selectedSeriesTypeId,
                            'updated_at'  => now(),
                        ]);
                }
            } else {
                DB::table('rdp_pending_record')
                    ->where('cluster_id', $this->targetCluster->cluster_id)
                    ->update([
                        'status_id'  => 2,
                        'updated_at' => now(),
                    ]);
            }

            DB::commit();

            $this->successMessage = "Submission cluster '{$this->targetCluster->cluster_name}' successfully APPROVED and added to the official References list!";
            $this->closeApprovalModal();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Approval failed: ' . $e->getMessage();
        }
    }

    public function returnCluster(): void
    {
        if (!$this->targetCluster) return;

        try {
            if ($this->activeTab === 'series') {
                DB::table('rdp_pending_record_series')
                    ->where('cluster_id', $this->targetCluster->cluster_id)
                    ->update([
                        'status_id'  => 4, // Returned for Correction
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('rdp_pending_record')
                    ->where('cluster_id', $this->targetCluster->cluster_id)
                    ->update([
                        'status_id'  => 4,
                        'updated_at' => now(),
                    ]);
            }

            $this->successMessage = "Cluster '{$this->targetCluster->cluster_name}' returned for correction.";
            $this->closeApprovalModal();
        } catch (\Exception $e) {
            $this->errorMessage = 'Action failed: ' . $e->getMessage();
        }
    }

    public function rejectCluster(): void
    {
        if (!$this->targetCluster) return;

        try {
            if ($this->activeTab === 'series') {
                DB::table('rdp_pending_record_series')
                    ->where('cluster_id', $this->targetCluster->cluster_id)
                    ->update([
                        'status_id'  => 3, // Rejected
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('rdp_pending_record')
                    ->where('cluster_id', $this->targetCluster->cluster_id)
                    ->update([
                        'status_id'  => 3,
                        'updated_at' => now(),
                    ]);
            }

            $this->successMessage = "Cluster '{$this->targetCluster->cluster_name}' rejected.";
            $this->closeApprovalModal();
        } catch (\Exception $e) {
            $this->errorMessage = 'Action failed: ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        $seriesTypes = DB::table('rdp_record_series_type')->where('is_active', true)->get();

        if ($this->activeTab === 'series') {
            $query = DB::table('rdp_pending_record_series')
                ->leftJoin('office', 'rdp_pending_record_series.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record_series.created_by', '=', 'account_details.account_id')
                ->where('rdp_pending_record_series.status_id', 1) // Only Pending Verification
                ->select([
                    'rdp_pending_record_series.*',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("(SELECT COUNT(*) FROM rdp_grouped_record_series WHERE group_head = rdp_pending_record_series.cluster_id) as total_items")
                ]);

            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $query->where(function($q) use ($term) {
                    $q->where('rdp_pending_record_series.cluster_name', 'ILIKE', $term)
                      ->orWhere('office.office_name', 'ILIKE', $term);
                });
            }

            $pendingClusters = $query->orderBy('rdp_pending_record_series.created_at', 'asc')->get();
        } else {
            $query = DB::table('rdp_pending_record')
                ->leftJoin('office', 'rdp_pending_record.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record.created_by', '=', 'account_details.account_id')
                ->where('rdp_pending_record.status_id', 1)
                ->select([
                    'rdp_pending_record.*',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("(SELECT COUNT(*) FROM rdp_grouped_record WHERE group_head = rdp_pending_record.cluster_id) as total_items")
                ]);

            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $query->where(function($q) use ($term) {
                    $q->where('rdp_pending_record.cluster_name', 'ILIKE', $term)
                      ->orWhere('office.office_name', 'ILIKE', $term);
                });
            }

            $pendingClusters = $query->orderBy('rdp_pending_record.created_at', 'asc')->get();
        }

        return [
            'pendingClusters' => $pendingClusters,
            'seriesTypes'     => $seriesTypes,
        ];
    }
}; ?>

<div class="pending-approval-page">
    <style>
        .pending-approval-page {
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
            display: flex;
            align-items: center;
            gap: 10px;
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
        .search-input {
            padding: 9px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            width: 280px;
        }
        .alert-msg {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 500;
        }
        .alert-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        .approval-table-wrapper {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .approval-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }
        .approval-table th {
            background: #0f172a;
            color: #ffffff;
            font-weight: 600;
            padding: 14px 16px;
            border-right: 1px solid #1e293b;
        }
        .approval-table td {
            padding: 14px 16px;
            color: #334155;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #f1f5f9;
        }
        .btn-action-review {
            background: #2563eb;
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-action-review:hover { background: #1d4ed8; }

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
            padding: 28px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 14px;
        }
        .modal-title { font-size: 20px; font-weight: 700; color: #0f172a; margin: 0; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .form-select { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
        }
        .btn-approve { background: #16a34a; color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-approve:hover { background: #15803d; }
        .btn-return { background: #0284c7; color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-return:hover { background: #0369a1; }
        .btn-reject { background: #dc2626; color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-reject:hover { background: #b91c1c; }
    </style>

    <div class="header-card">
        <div class="header-title">
            <h1>Pending Submissions — For Approval</h1>
            <p>Review, assign series types, and verify office record series submissions for official reference inclusion</p>
        </div>
        <div class="controls-group">
            <div class="tab-buttons">
                <button wire:click="setTab('series')" class="tab-btn {{ $activeTab === 'series' ? 'active' : '' }}">Record Series</button>
                <button wire:click="setTab('files')" class="tab-btn {{ $activeTab === 'files' ? 'active' : '' }}">Record Files</button>
            </div>
            <input type="text" wire:model.live.debounce.250ms="search" class="search-input" placeholder="Search cluster or office...">
        </div>
    </div>

    @if(!empty($successMessage))
        <div class="alert-msg alert-success">{{ $successMessage }}</div>
    @endif
    @if(!empty($errorMessage))
        <div class="alert-msg alert-error">{{ $errorMessage }}</div>
    @endif

    <div class="approval-table-wrapper">
        <table class="approval-table">
            <thead>
                <tr>
                    <th style="width: 80px;">Cluster ID</th>
                    <th>Cluster Name</th>
                    <th>Submitting Office</th>
                    <th>Submitted By</th>
                    <th style="width: 110px; text-align: center;">Total Items</th>
                    <th style="width: 140px;">Date Submitted</th>
                    <th style="width: 140px; text-align: center;">Review Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($pendingClusters as $pc)
                    <tr>
                        <td><strong>#{{ $pc->cluster_id }}</strong></td>
                        <td><strong>{{ $pc->cluster_name }}</strong></td>
                        <td>{{ $pc->office_name ?? $pc->office ?? 'N/A' }}</td>
                        <td>{{ $pc->submitter_name ?: 'System User' }}</td>
                        <td style="text-align: center;"><strong>{{ $pc->total_items }}</strong></td>
                        <td>{{ \Carbon\Carbon::parse($pc->created_at)->format('M d, Y g:i A') }}</td>
                        <td style="text-align: center;">
                            <button wire:click="openApprovalModal({{ $pc->cluster_id }}, '{{ $activeTab }}')" class="btn-action-review">Evaluate</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 48px; color: #64748b;">
                            No pending {{ $activeTab === 'series' ? 'record series' : 'record files' }} currently awaiting evaluation.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($showApprovalModal && $targetCluster)
        <div class="modal-overlay">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h3 class="modal-title">Evaluation: {{ $targetCluster->cluster_name }}</h3>
                        <span style="font-size: 13px; color: #64748b;">Submitting Office: {{ $targetCluster->office_name ?? $targetCluster->office }}</span>
                    </div>
                    <button wire:click="closeApprovalModal" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                </div>

                @if($activeTab === 'series')
                    <div class="form-group">
                        <label class="form-label">Target Reference Series Type for Approved Items:</label>
                        <select wire:model="selectedSeriesTypeId" class="form-select">
                            @foreach($seriesTypes as $st)
                                <option value="{{ $st->id }}">{{ $st->shorted_type }} — {{ $st->type_name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <h4 style="font-size: 15px; font-weight: 700; color: #0f172a; margin: 16px 0 10px 0;">Items in Cluster:</h4>
                <div style="max-height: 260px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 16px;">
                    <table class="approval-table" style="font-size: 13px;">
                        <thead>
                            <tr>
                                <th>Item / Title</th>
                                <th>Retention Period</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($clusterItems as $item)
                                <tr>
                                    <td>
                                        <strong>{{ $item->series_title ?? $item->doc_name ?? 'Untitled Item' }}</strong>
                                        @if(!empty($item->parent_title))
                                            <div style="font-size: 11px; color: #64748b;">Parent: {{ $item->parent_title }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $item->total_period ?? $item->active_period ?? '—' }}</td>
                                    <td>{{ $item->remarks ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="modal-actions">
                    <button wire:click="rejectCluster" class="btn-reject">Reject</button>
                    <button wire:click="returnCluster" class="btn-return">Return for Correction</button>
                    <button wire:click="approveCluster" class="btn-approve">Approve & Publish to References</button>
                </div>
            </div>
        </div>
    @endif
</div>
