<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Pending For Approval')] class extends Component {
    public string $search = '';
    public string $activeTab = 'all'; // 'all', 'nap1', 'nap2', 'nap3'
    public string $statusFilter = '';
    public string $layoutMode = 'table'; // 'table' or 'box'

    // Approval Action Modal State
    public bool $showApprovalModal = false;
    public ?object $targetCluster = null;
    public array $clusterItems = [];
    public ?int $selectedSeriesTypeId = null;

    // Print Modal State
    public bool $showPrintModal = false;
    public ?object $printCluster = null;
    public array $printItems = [];
    public string $agencyName = 'Camarines Sur Polytechnic Colleges';
    public string $agencyAddress = 'San Miguel, Nabua, Camarines Sur';
    public string $evaluatorName = '';

    public string $successMessage = '';
    public string $errorMessage = '';

    public function mount(): void
    {
        $perms = Auth::user()?->permissions;
        $isSadm = (bool)($perms->is_sadm ?? false);
        $canViewAllPending = (bool)($perms->is_rdp_view_all_pending_list ?? false);
        $canRdpAdmin = (bool)($perms->can_access_rdp_admin ?? false);
        $canModifySeries = (bool)($perms->can_rdp_modify_series ?? true);

        if (!$perms || (!$isSadm && !$canViewAllPending && !$canRdpAdmin && !$canModifySeries)) {
            redirect()->route('rdp')->send();
            return;
        }

        $details = Auth::user()?->details;
        if ($details) {
            $fullName = trim(($details->first_name ?? '') . ' ' . ($details->last_name ?? ''));
            $this->evaluatorName = $fullName ?: (Auth::user()->username ?? 'Records Evaluator');
        }

        $defaultType = DB::table('rdp_record_series_type')->where('shorted_type', 'CSPC')->first();
        if ($defaultType) {
            $this->selectedSeriesTypeId = $defaultType->id;
        }
    }

    public function toggleLayout(): void
    {
        $this->layoutMode = $this->layoutMode === 'table' ? 'box' : 'table';
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
        $this->statusFilter = '';
        $this->clearMessages();
    }

    public function openApprovalModal(int $clusterId, string $formType): void
    {
        $this->clearMessages();
        $cluster = null;
        $items = [];

        if ($formType === 'nap2' || $formType === 'NAP Form 2') {
            $cluster = DB::table('rdp_pending_record_series')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record_series.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'rdp_pending_record_series.office', '=', 'office.office_code')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as account_details', 'rdp_pending_record_series.created_by', '=', 'account_details.account_id')
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
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'rdp_pending_record.office', '=', 'office.office_code')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as account_details', 'rdp_pending_record.created_by', '=', 'account_details.account_id')
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
            $this->targetCluster = $cluster;
            $this->clusterItems = $items;
            $this->showApprovalModal = true;
        }
    }

    public function closeApprovalModal(): void
    {
        $this->showApprovalModal = false;
        $this->targetCluster = null;
        $this->clusterItems = [];
    }

    public function openPrintModal(int $clusterId, string $formType): void
    {
        $this->openApprovalModal($clusterId, $formType);
        $this->printCluster = $this->targetCluster;
        $this->printItems = $this->clusterItems;
        $this->showApprovalModal = false;
        $this->showPrintModal = true;
    }

    public function closePrintModal(): void
    {
        $this->showPrintModal = false;
        $this->printCluster = null;
        $this->printItems = [];
    }

    public function approveCluster(): void
    {
        if (!$this->targetCluster) return;

        try {
            DB::beginTransaction();

            $formCode = $this->targetCluster->form_code ?? '';
            $formLabel = $this->targetCluster->form_label ?? '';

            if ($formLabel === 'NAP Form 2' || $formCode === 'nap2') {
                // NAP Form 2: Verify cluster AND all contained Record Series so they become official and leave NAP Form 2 draft list
                DB::table('rdp_pending_record_series')
                    ->where('cluster_id', $this->targetCluster->cluster_id)
                    ->update([
                        'is_verified' => true,
                        'status_id'  => 2, // Verified
                        'updated_at' => now(),
                    ]);

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
            } elseif ($formLabel === 'NAP Form 3' || $formCode === 'nap3') {
                // NAP Form 3: Verify cluster AND all contained Records so they are marked disposed/verified
                DB::table('rdp_pending_record')
                    ->where('cluster_id', $this->targetCluster->cluster_id)
                    ->update([
                        'is_verified' => true,
                        'status_id'  => 2, // Verified
                        'updated_at' => now(),
                    ]);

                $recordIds = array_column($this->clusterItems, 'id');
                if (!empty($recordIds)) {
                    DB::table('rdp_record')
                        ->whereIn('id', $recordIds)
                        ->update([
                            'is_verified' => true,
                            'updated_at'  => now(),
                        ]);
                }
            } else {
                // NAP Form 1: Verify cluster ONLY. Record series/records inside remain unchanged
                DB::table('rdp_pending_record')
                    ->where('cluster_id', $this->targetCluster->cluster_id)
                    ->update([
                        'is_verified' => true,
                        'status_id'  => 2, // Verified
                        'updated_at' => now(),
                    ]);
            }

            DB::commit();

            $this->successMessage = "Submission cluster '{$this->targetCluster->cluster_name}' successfully VERIFIED!";
            $this->closeApprovalModal();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Verification failed: ' . $e->getMessage();
        }
    }

    public function returnCluster(): void
    {
        if (!$this->targetCluster) return;

        try {
            if ($this->targetCluster->form_label === 'NAP Form 2') {
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
            if ($this->targetCluster->form_label === 'NAP Form 2') {
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
        $statuses = DB::table('rdp_pending_status')->where('is_active', true)->get();
        $clustersCollection = collect();

        $mainPendingTbl = \Illuminate\Support\Facades\Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';
        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

        // 1. NAP Form 2 Series Clusters (status_id IS NOT NULL)
        if ($this->activeTab === 'all' || $this->activeTab === 'nap2') {
            $qSeries = DB::table($mainPendingTbl)
                ->join('rdp_pending_record_series', "{$mainPendingTbl}.id", '=', 'rdp_pending_record_series.cluster_id')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record_series.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin($officeTbl, 'rdp_pending_record_series.office', '=', "{$officeTbl}.office_code")
                ->leftJoin($accDetailsTbl, 'rdp_pending_record_series.created_by', '=', "{$accDetailsTbl}.account_id")
                ->whereNotNull('rdp_pending_record_series.status_id')
                ->select([
                    "{$mainPendingTbl}.id as main_id",
                    'rdp_pending_record_series.cluster_id',
                    'rdp_pending_record_series.cluster_name',
                    'rdp_pending_record_series.status_id',
                    'rdp_pending_record_series.office',
                    'rdp_pending_record_series.created_at',
                    'rdp_pending_status.status_name',
                    "{$officeTbl}.office_name",
                    DB::raw("CONCAT({$accDetailsTbl}.first_name, ' ', {$accDetailsTbl}.last_name) as submitter_name"),
                    DB::raw("'NAP Form 2' as form_label"),
                    DB::raw("'nap2' as form_code"),
                    DB::raw("(SELECT COUNT(*) FROM rdp_grouped_record_series WHERE group_head = rdp_pending_record_series.cluster_id) as total_items")
                ]);

            if (!empty($this->statusFilter)) {
                $qSeries->where('rdp_pending_record_series.status_id', $this->statusFilter);
            }

            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $qSeries->where(function($q) use ($term, $officeTbl) {
                    $q->where('rdp_pending_record_series.cluster_name', 'ILIKE', $term)
                      ->orWhere("{$officeTbl}.office_name", 'ILIKE', $term);
                });
            }

            $clustersCollection = $clustersCollection->concat($qSeries->get());
        }

        // 2. Pending Record Clusters (status_id IS NOT NULL)
        if ($this->activeTab === 'all' || $this->activeTab === 'nap1' || $this->activeTab === 'nap3') {
            $qRec = DB::table($mainPendingTbl)
                ->join('rdp_pending_record', "{$mainPendingTbl}.id", '=', 'rdp_pending_record.cluster_id')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin($officeTbl, 'rdp_pending_record.office', '=', "{$officeTbl}.office_code")
                ->leftJoin($accDetailsTbl, 'rdp_pending_record.created_by', '=', "{$accDetailsTbl}.account_id")
                ->whereNotNull('rdp_pending_record.status_id')
                ->select([
                    "{$mainPendingTbl}.id as main_id",
                    'rdp_pending_record.cluster_id',
                    'rdp_pending_record.cluster_name',
                    'rdp_pending_record.status_id',
                    'rdp_pending_record.office',
                    'rdp_pending_record.is_for_nap_one',
                    'rdp_pending_record.is_for_nap_three',
                    'rdp_pending_record.created_at',
                    'rdp_pending_status.status_name',
                    "{$officeTbl}.office_name",
                    DB::raw("CONCAT({$accDetailsTbl}.first_name, ' ', {$accDetailsTbl}.last_name) as submitter_name"),
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
                $qRec->where(function($q) use ($term, $officeTbl) {
                    $q->where('rdp_pending_record.cluster_name', 'ILIKE', $term)
                      ->orWhere("{$officeTbl}.office_name", 'ILIKE', $term);
                });
            }

            $clustersCollection = $clustersCollection->concat($qRec->get());
        }

        $sortedClusters = $clustersCollection->sortByDesc('main_id')->values();

        return [
            'pendingClusters' => $sortedClusters,
            'seriesTypes'     => $seriesTypes,
            'statuses'        => $statuses,
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
        }

        .search-input, .select-filter {
            padding: 9px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }
        .search-input { width: 240px; }

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

        .btn-action-review {
            background: #2563eb;
            color: #ffffff;
            border: none;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 4px;
        }
        .btn-action-review:hover { background: #1d4ed8; }

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
        }
        .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .card-title { font-size: 16px; font-weight: 700; color: #0f172a; margin: 4px 0 0 0; }
        .card-meta { font-size: 13px; color: #64748b; margin-bottom: 16px; line-height: 1.5; }
        .card-footer { display: flex; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid #f1f5f9; }

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
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .form-select { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
        }
        .btn-approve { background: #16a34a; color: #ffffff; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-return { background: #0284c7; color: #ffffff; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-reject { background: #dc2626; color: #ffffff; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; }

        /* ==========================================================================
           Pending For Approval - Dark Mode Overrides
           ========================================================================== */
        [data-theme="dark"] .header-card {
            background: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
        }

        [data-theme="dark"] .header-title h1 {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .header-title p {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .dts-tab-bar {
            background: #0f172a !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .dts-tab-btn {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .dts-tab-btn.active {
            background: #131c2e !important;
            color: #60a5fa !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.4) !important;
        }

        [data-theme="dark"] .layout-toggle-btn {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .search-input,
        [data-theme="dark"] .select-filter {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .approval-table-wrapper {
            background: #131c2e !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .approval-table th {
            background: #0f172a !important;
            color: #94a3b8 !important;
            border-right-color: #1e293b !important;
            border-bottom: 2px solid #1e293b !important;
        }

        [data-theme="dark"] .approval-table td {
            color: #cbd5e1 !important;
            border-bottom-color: #1e293b !important;
            border-right-color: #1e293b !important;
        }

        [data-theme="dark"] .approval-table tr:hover td {
            background-color: #1a253c !important;
        }

        [data-theme="dark"] .cluster-card {
            background: #131c2e !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .card-title {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .card-meta {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .card-footer {
            border-top-color: #1e293b !important;
        }

        [data-theme="dark"] .modal-card {
            background: #131c2e !important;
            border: 1px solid #1e293b !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .form-label {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .form-select {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .modal-actions {
            border-top-color: #1e293b !important;
        }

        @media print {
            @page {
                size: portrait;
                margin: 10px;
            }
            :root {
                zoom: 1 !important;
            }
            html, body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                overflow: visible !important;
                position: static !important;
                font-family: Arial, sans-serif !important;
                font-size: 12px !important;
            }
            header,
            nav,
            .navigation,
            #navigation,
            .chatify-floating-widget,
            .header-card,
            .controls-group,
            .approval-table-wrapper,
            .card-grid,
            .no-print,
            .modal-card > *:not(.printable-report-area) {
                display: none !important;
            }
            section,
            .article-container,
            #article-container {
                display: block !important;
                position: static !important;
                float: none !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                overflow: visible !important;
            }
            .modal-overlay {
                position: static !important;
                display: block !important;
                width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
            }
            .modal-card {
                position: static !important;
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
                max-height: none !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                border: none !important;
                box-shadow: none !important;
            }
            .printable-report-area {
                display: block !important;
                visibility: visible !important;
                position: static !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 10px !important;
                box-sizing: border-box !important;
                font-family: Arial, sans-serif !important;
                font-size: 12px !important;
            }
            .printable-report-area * {
                visibility: visible !important;
            }
        }
    </style>

    <div class="header-card">
        <div class="header-title">
            <h1>Pending Submissions — For Approval</h1>
            <p>Evaluate office submissions, assign reference series types, and verify items for official inclusion</p>
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
                <option value="">All Evaluated Statuses</option>
                @foreach($statuses as $st)
                    <option value="{{ $st->id }}">{{ $st->status_name }}</option>
                @endforeach
            </select>
            <input type="text" wire:model.live.debounce.250ms="search" class="search-input" placeholder="Search cluster or office...">
        </div>
    </div>

    @if(!empty($successMessage))
        <div class="alert-msg alert-success">{{ $successMessage }}</div>
    @endif
    @if(!empty($errorMessage))
        <div class="alert-msg alert-error">{{ $errorMessage }}</div>
    @endif

    @if($layoutMode === 'table')
        <div class="approval-table-wrapper">
            <table class="approval-table">
                <thead>
                    <tr>
                        <th style="width: 90px;">Main ID</th>
                        <th>Cluster Name</th>
                        <th>Submitting Office</th>
                        <th>Submitted By</th>
                        <th style="width: 110px; text-align: center;">Total Items</th>
                        <th style="width: 160px;">Status</th>
                        <th style="width: 140px;">Date Submitted</th>
                        <th style="width: 170px; text-align: center;">Evaluation Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pendingClusters as $pc)
                        <tr>
                            <td><strong>#{{ $pc->main_id }}</strong></td>
                            <td>
                                <span class="form-type-pill">{{ $pc->form_label }}</span>
                                <strong>{{ $pc->cluster_name }}</strong>
                            </td>
                            <td>{{ $pc->office_name ?? $pc->office ?? 'N/A' }}</td>
                            <td>{{ $pc->submitter_name ?: 'System User' }}</td>
                            <td style="text-align: center;"><strong>{{ $pc->total_items }}</strong></td>
                            <td>
                                <span class="status-badge status-{{ $pc->status_id }}">
                                    {{ $pc->status_name }}
                                </span>
                            </td>
                            <td>{{ \Carbon\Carbon::parse($pc->created_at)->format('M d, Y g:i A') }}</td>
                            <td style="text-align: center;">
                                <button wire:click="openApprovalModal({{ $pc->cluster_id }}, '{{ $pc->form_code }}')" class="btn-action-review">Evaluate</button>
                                <button wire:click="openPrintModal({{ $pc->cluster_id }}, '{{ $pc->form_code }}')" class="btn-print">Print</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 48px; color: #64748b;">
                                No pending clusters currently awaiting evaluation.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="card-grid">
            @forelse($pendingClusters as $pc)
                <div class="cluster-card">
                    <div>
                        <div class="card-header">
                            <div>
                                <span class="form-type-pill">{{ $pc->form_label }}</span>
                                <span style="font-size: 12px; font-weight: 700; color: #64748b;">#{{ $pc->main_id }}</span>
                            </div>
                            <span class="status-badge status-{{ $pc->status_id }}">
                                {{ $pc->status_name }}
                            </span>
                        </div>
                        <h3 class="card-title">{{ $pc->cluster_name }}</h3>
                        <div class="card-meta">
                            <div><strong>Office:</strong> {{ $pc->office_name ?? $pc->office ?? 'N/A' }}</div>
                            <div><strong>Submitted by:</strong> {{ $pc->submitter_name ?: 'System User' }}</div>
                            <div><strong>Items:</strong> {{ $pc->total_items }} records</div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <span style="font-size: 12px; color: #94a3b8;">{{ \Carbon\Carbon::parse($pc->created_at)->format('M d, Y') }}</span>
                        <div>
                            <button wire:click="openApprovalModal({{ $pc->cluster_id }}, '{{ $pc->form_code }}')" class="btn-action-review">Evaluate</button>
                            <button wire:click="openPrintModal({{ $pc->cluster_id }}, '{{ $pc->form_code }}')" class="btn-print">Print</button>
                        </div>
                    </div>
                </div>
            @empty
                <div style="grid-column: 1 / -1; text-align: center; padding: 48px; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; color: #64748b;">
                    No pending clusters currently awaiting evaluation.
                </div>
            @endforelse
        </div>
    @endif

    {{-- Approval Modal --}}
    @if($showApprovalModal && $targetCluster)
        <div class="modal-overlay">
            <div class="modal-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 14px;">
                    <div>
                        <h3 style="font-size: 20px; font-weight: 700; color: #0f172a; margin: 0;">Evaluation: {{ $targetCluster->cluster_name }}</h3>
                        <span style="font-size: 13px; color: #64748b;">Submitting Office: {{ $targetCluster->office_name ?? $targetCluster->office }}</span>
                    </div>
                    <button wire:click="closeApprovalModal" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                </div>

                @if($targetCluster->form_label === 'NAP Form 2')
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
                    <button wire:click="rejectCluster" class="btn-reject">Unverify / Reject</button>
                    <button wire:click="returnCluster" class="btn-return">Return for Correction</button>
                    <button wire:click="approveCluster" class="btn-approve">Verify Cluster</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Print Modal --}}
    @if($showPrintModal && $printCluster)
        <div class="modal-overlay no-print">
            <div class="modal-card" style="width: 900px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;" class="no-print">
                    <h3 style="font-size: 18px; font-weight: 700; color: #0f172a; margin: 0;">Evaluation Report Preview — {{ $printCluster->cluster_name }}</h3>
                    <div>
                        <button onclick="window.print()" class="btn-print" style="padding: 8px 16px; margin-right: 8px;">Print Now</button>
                        <button wire:click="closePrintModal" class="btn-action-review" style="padding: 8px 16px; background: #64748b;">Close</button>
                    </div>
                </div>

                <div class="printable-report-area" style="background: #ffffff; padding: 24px; font-family: 'Inter', sans-serif; color: #0f172a;">
                    <div style="text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 16px; margin-bottom: 24px;">
                        <h2 style="margin: 0; font-size: 20px; font-weight: 700;">{{ $agencyName }}</h2>
                        <p style="margin: 4px 0 0 0; font-size: 13px; color: #475569;">{{ $agencyAddress }}</p>
                        <h3 style="margin: 12px 0 0 0; font-size: 16px; font-weight: 700; text-transform: uppercase; color: #1e293b;">
                            OFFICIAL RECORDS EVALUATION & APPROVAL SUMMARY REPORT
                        </h3>
                    </div>

                    <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 20px; line-height: 1.6;">
                        <div>
                            <div><strong>Cluster ID:</strong> #{{ $printCluster->cluster_id }}</div>
                            <div><strong>Cluster Name:</strong> {{ $printCluster->cluster_name }}</div>
                            <div><strong>Form Type:</strong> {{ $printCluster->form_label }}</div>
                            <div><strong>Status:</strong> {{ $printCluster->status_name }}</div>
                        </div>
                        <div style="text-align: right;">
                            <div><strong>Submitting Office:</strong> {{ $printCluster->office_name ?? $printCluster->office }}</div>
                            <div><strong>Submitted By:</strong> {{ $printCluster->submitter_name ?: 'System User' }}</div>
                            <div><strong>Date Evaluated:</strong> {{ \Carbon\Carbon::parse($printCluster->created_at)->format('F d, Y') }}</div>
                        </div>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 32px;" border="1" cellpadding="8">
                        <thead>
                            <tr style="background: #f1f5f9; text-align: left;">
                                <th style="width: 40px; text-align: center;">#</th>
                                <th>Item / Record Series Title</th>
                                <th>Details / Location</th>
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
                            <div style="border-bottom: 1px solid #0f172a; padding-bottom: 4px; font-weight: 700;">{{ $printCluster->submitter_name ?: 'Office Custodian' }}</div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Submitting Custodian</div>
                        </div>
                        <div style="width: 240px; text-align: center;">
                            <div style="border-bottom: 1px solid #0f172a; padding-bottom: 4px; font-weight: 700;">{{ $evaluatorName }}</div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Records Evaluator / Officer</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
