<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

new #[Layout('layouts.rdp')] #[Title('Received Documents - Record Requests')] class extends Component {
    use WithPagination;

    public string $activeTab = 'incoming'; // 'incoming' or 'submitted'
    public string $search = '';
    public string $statusFilter = 'all'; // 'all', 'pending', 'accepted', 'declined'
    public string $dateFilter = 'all'; // 'all', 'today', 'week', 'month'
    public string $officeFilter = '';
    public int $perPage = 15;

    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    // View Details Modal
    public bool $showDetailModal = false;
    public ?object $selectedRecord = null;
    public array $selectedSubPeriods = [];
    public array $selectedUtilities = [];
    public array $selectedDuplicates = [];
    public ?object $selectedAttachedFile = null;

    // Action Modals
    public bool $showAcceptModal = false;
    public bool $showDeclineModal = false;
    public ?int $actionRecordId = null;
    public ?string $actionRecordTitle = null;
    public string $declineReason = '';

    public function mount(): void
    {
        $perms = Auth::user()?->permissions;
        if (!$perms || (!(bool)($perms->is_sadm ?? false) && !(bool)($perms->can_access_rdp ?? true))) {
            $this->redirectRoute('rdp');
            return;
        }

        if (session()->has('successMessage')) {
            $this->successMessage = session('successMessage');
        }
    }

    public function switchTab(string $tab): void
    {
        if (in_array($tab, ['incoming', 'submitted'], true)) {
            $this->activeTab = $tab;
            $this->resetPage();
            $this->clearMessages();
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingDateFilter(): void
    {
        $this->resetPage();
    }

    public function updatingOfficeFilter(): void
    {
        $this->resetPage();
    }

    public function clearMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    public function openDetailModal(int $id): void
    {
        $this->clearMessages();
        $accTbl = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

        $record = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->leftJoin('rdp_retention_period', 'rdp_record.period_id', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_recorded_value', 'rdp_record.records_medium', '=', 'rdp_recorded_value.id')
            ->leftJoin($accTbl . ' as acc', 'rdp_record.user_own', '=', 'acc.id')
            ->leftJoin($accDetailsTbl . ' as acc_details', 'acc.id', '=', 'acc_details.account_id')
            ->select(
                'rdp_record.*',
                'rdp_record_series.series_title',
                'rdp_record_series.parent_id as series_parent_id',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
                'rdp_recorded_value.medium_name',
                'acc_details.first_name as creator_first_name',
                'acc_details.last_name as creator_last_name'
            )
            ->where('rdp_record.id', $id)
            ->where('rdp_record.is_request', true)
            ->first();

        if (!$record) {
            $this->errorMessage = 'Record request not found.';
            return;
        }

        $this->selectedRecord = $record;

        // Sub periods
        $this->selectedSubPeriods = DB::table('rdp_period_covered')
            ->where('period_owner', $id)
            ->orderBy('id', 'asc')
            ->get()
            ->toArray();

        // Utilities
        $this->selectedUtilities = DB::table('rdp_utility_manager')
            ->leftJoin('rdp_utility_medium', 'rdp_utility_manager.utility_medium', '=', 'rdp_utility_medium.id')
            ->where('rdp_utility_manager.record_holder', $id)
            ->pluck('rdp_utility_medium.utility_name')
            ->filter()
            ->toArray();

        // Duplicates
        $this->selectedDuplicates = DB::table('rdp_duplication_section')
            ->where('dup_id_manager', $id)
            ->pluck('office_code')
            ->filter()
            ->toArray();

        // Attached document
        $this->selectedAttachedFile = null;
        if (!empty($record->upload_doc_id_handler)) {
            $docTbl = Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data';
            $this->selectedAttachedFile = DB::table($docTbl)
                ->where('document_id', $record->upload_doc_id_handler)
                ->first();
        }

        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedRecord = null;
        $this->selectedSubPeriods = [];
        $this->selectedUtilities = [];
        $this->selectedDuplicates = [];
        $this->selectedAttachedFile = null;
    }

    public function openAcceptModal(int $id): void
    {
        $this->clearMessages();
        $rec = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->where('rdp_record.id', $id)
            ->where('rdp_record.is_request', true)
            ->select('rdp_record.id', 'rdp_record.description', 'rdp_record_series.series_title')
            ->first();

        if ($rec) {
            $this->actionRecordId = $rec->id;
            $this->actionRecordTitle = $rec->series_title ?: $rec->description;
            $this->showAcceptModal = true;
        }
    }

    public function closeAcceptModal(): void
    {
        $this->showAcceptModal = false;
        $this->actionRecordId = null;
        $this->actionRecordTitle = null;
    }

    public function acceptRequest(?int $id = null): void
    {
        $targetId = $id ?: $this->actionRecordId;
        if (!$targetId) return;

        $user = Auth::user();
        $perms = $user?->permissions;
        $isSadm = (bool)($perms->is_sadm ?? false);
        $userOffice = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;

        $record = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->where('rdp_record.id', $targetId)
            ->where('rdp_record.is_request', true)
            ->select('rdp_record.*', 'rdp_record_series.series_title')
            ->first();

        if (!$record) {
            $this->errorMessage = 'Record request not found.';
            $this->closeAcceptModal();
            return;
        }

        if (!$isSadm && $record->request_target_office !== $userOffice) {
            $this->errorMessage = 'You are not authorized to accept this record request. Only members of ' . $record->request_target_office . ' can accept.';
            $this->closeAcceptModal();
            return;
        }

        if ($record->request_status !== 'pending') {
            $this->errorMessage = "This request has already been {$record->request_status}.";
            $this->closeAcceptModal();
            return;
        }

        try {
            DB::beginTransaction();

            // Accept this record or group of records if part of same submission
            $updateQuery = DB::table('rdp_record');
            if (!empty($record->request_group_id)) {
                $updateQuery->where('request_group_id', $record->request_group_id);
            } else {
                $updateQuery->where('id', $targetId);
            }

            $updateQuery->update([
                'request_status'      => 'accepted',
                'is_active'           => true,
                'is_draft'            => false,
                'office_own'          => $record->request_target_office,
                'request_actioned_by' => $user?->id,
                'request_actioned_at' => now(),
                'updated_at'          => now(),
            ]);

            DB::commit();

            // Notify origin office
            if (!empty($record->request_origin_office)) {
                \App\Services\RdpNotificationService::notifyRecordRequestAccepted(
                    $record->request_origin_office,
                    $record->request_target_office ?? $userOffice,
                    $record->series_title ?: ($record->description ?: 'Inventory Record')
                );
            }

            $this->successMessage = "Record request accepted successfully! The record has been saved to {$record->request_target_office}'s active NAP Form 1 records.";
            $this->closeAcceptModal();

            if ($this->showDetailModal && $this->selectedRecord && $this->selectedRecord->id === $targetId) {
                $this->selectedRecord->request_status = 'accepted';
                $this->selectedRecord->is_active = true;
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to accept record request: ' . $e->getMessage();
            $this->closeAcceptModal();
        }
    }

    public function openDeclineModal(int $id): void
    {
        $this->clearMessages();
        $rec = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->where('rdp_record.id', $id)
            ->where('rdp_record.is_request', true)
            ->select('rdp_record.id', 'rdp_record.description', 'rdp_record_series.series_title')
            ->first();

        if ($rec) {
            $this->actionRecordId = $rec->id;
            $this->actionRecordTitle = $rec->series_title ?: $rec->description;
            $this->declineReason = '';
            $this->showDeclineModal = true;
        }
    }

    public function closeDeclineModal(): void
    {
        $this->showDeclineModal = false;
        $this->actionRecordId = null;
        $this->actionRecordTitle = null;
        $this->declineReason = '';
    }

    public function declineRequest(): void
    {
        if (!$this->actionRecordId) return;

        $user = Auth::user();
        $perms = $user?->permissions;
        $isSadm = (bool)($perms->is_sadm ?? false);
        $userOffice = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;

        $record = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->where('rdp_record.id', $this->actionRecordId)
            ->where('rdp_record.is_request', true)
            ->select('rdp_record.*', 'rdp_record_series.series_title')
            ->first();

        if (!$record) {
            $this->errorMessage = 'Record request not found.';
            $this->closeDeclineModal();
            return;
        }

        if (!$isSadm && $record->request_target_office !== $userOffice) {
            $this->errorMessage = 'You are not authorized to decline this record request. Only members of ' . $record->request_target_office . ' can decline.';
            $this->closeDeclineModal();
            return;
        }

        if ($record->request_status !== 'pending') {
            $this->errorMessage = "This request has already been {$record->request_status}.";
            $this->closeDeclineModal();
            return;
        }

        try {
            DB::beginTransaction();

            $reason = trim($this->declineReason);

            // Decline this record or group of records if part of same submission
            $updateQuery = DB::table('rdp_record');
            if (!empty($record->request_group_id)) {
                $updateQuery->where('request_group_id', $record->request_group_id);
            } else {
                $updateQuery->where('id', $this->actionRecordId);
            }

            $updateQuery->update([
                'request_status'         => 'declined',
                'is_active'              => false,
                'request_decline_reason' => $reason ?: null,
                'request_actioned_by'    => $user?->id,
                'request_actioned_at'    => now(),
                'updated_at'             => now(),
            ]);

            DB::commit();

            // Notify origin office
            if (!empty($record->request_origin_office)) {
                \App\Services\RdpNotificationService::notifyRecordRequestDeclined(
                    $record->request_origin_office,
                    $record->request_target_office ?? $userOffice,
                    $record->series_title ?: ($record->description ?: 'Inventory Record'),
                    $reason ?: null
                );
            }

            $this->successMessage = "Record request has been declined. Office {$record->request_origin_office} has been notified.";
            $this->closeDeclineModal();

            if ($this->showDetailModal && $this->selectedRecord && $this->selectedRecord->id === $this->actionRecordId) {
                $this->selectedRecord->request_status = 'declined';
                $this->selectedRecord->is_active = false;
                $this->selectedRecord->request_decline_reason = $reason;
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to decline record request: ' . $e->getMessage();
            $this->closeDeclineModal();
        }
    }

    public function with(): array
    {
        $user = Auth::user();
        $perms = $user?->permissions;
        $isSadm = (bool)($perms->is_sadm ?? false);
        $userOffice = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;

        $officesTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $officesList = DB::table($officesTable)->where('is_active', true)->orderBy('office_name')->get();

        $accTbl = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

        // Base query for requests
        $query = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->leftJoin('rdp_retention_period', 'rdp_record.period_id', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_recorded_value', 'rdp_record.records_medium', '=', 'rdp_recorded_value.id')
            ->leftJoin($accTbl . ' as acc', 'rdp_record.user_own', '=', 'acc.id')
            ->leftJoin($accDetailsTbl . ' as acc_details', 'acc.id', '=', 'acc_details.account_id')
            ->select(
                'rdp_record.*',
                'rdp_record_series.series_title',
                'rdp_record_series.parent_id as series_parent_id',
                'rdp_retention_period.total_period',
                'rdp_recorded_value.medium_name',
                'acc_details.first_name as creator_first_name',
                'acc_details.last_name as creator_last_name'
            )
            ->where('rdp_record.is_request', true);

        // Tab routing: incoming to my office OR submitted by my office
        if ($this->activeTab === 'incoming') {
            if (!$isSadm) {
                $query->where('rdp_record.request_target_office', $userOffice);
            } elseif (!empty($this->officeFilter)) {
                $query->where('rdp_record.request_target_office', $this->officeFilter);
            }
        } else {
            // Submitted requests
            if (!$isSadm) {
                $query->where('rdp_record.request_origin_office', $userOffice);
            } elseif (!empty($this->officeFilter)) {
                $query->where('rdp_record.request_origin_office', $this->officeFilter);
            }
        }

        $activeViewingOffice = ($isSadm && !empty($this->officeFilter))
            ? $this->officeFilter
            : ($isSadm && empty($this->officeFilter) ? null : $userOffice);

        // Apply Status Filter
        if ($this->statusFilter !== 'all') {
            $query->where('rdp_record.request_status', $this->statusFilter);
        }

        // Apply Date Filter
        if ($this->dateFilter === 'today') {
            $query->whereDate('rdp_record.created_at', Carbon::today());
        } elseif ($this->dateFilter === 'week') {
            $query->where('rdp_record.created_at', '>=', Carbon::now()->subDays(7));
        } elseif ($this->dateFilter === 'month') {
            $query->where('rdp_record.created_at', '>=', Carbon::now()->subDays(30));
        }

        // Apply Search Filter
        if (!empty(trim($this->search))) {
            $s = '%' . trim($this->search) . '%';
            $query->where(function($q) use ($s) {
                $q->where('rdp_record_series.series_title', 'ilike', $s)
                  ->orWhere('rdp_record.description', 'ilike', $s)
                  ->orWhere('rdp_record.request_group_id', 'ilike', $s)
                  ->orWhere('rdp_record.request_origin_office', 'ilike', $s)
                  ->orWhere('rdp_record.request_target_office', 'ilike', $s);
            });
        }

        $records = $query->orderBy('rdp_record.id', 'desc')->paginate($this->perPage);

        return [
            'records'             => $records,
            'officesList'         => $officesList,
            'isSadm'              => $isSadm,
            'userOffice'          => $userOffice,
            'activeViewingOffice' => $activeViewingOffice,
        ];
    }
};
?>

<div class="received-docs-container">
    <style>
        .received-docs-container {
            padding: 24px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #1e293b;
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-title-box {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .header-icon {
            width: 46px;
            height: 46px;
            background: #eff6ff;
            color: #2563eb;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dbeafe;
        }

        .header-title-box h1 {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .subsystem-badge {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 2px 8px;
            background: #e0e7ff;
            color: #4338ca;
            border-radius: 6px;
            letter-spacing: 0.5px;
        }

        .header-title-box p {
            font-size: 13px;
            color: #64748b;
            margin: 3px 0 0 0;
        }

        /* Primary Tab Selector (Incoming vs Submitted) */
        .primary-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 8px;
        }

        .primary-tab-btn {
            border: none;
            background: transparent;
            padding: 8px 18px;
            font-size: 14px;
            font-weight: 700;
            color: #64748b;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .primary-tab-btn:hover {
            color: #1e293b;
            background: #f1f5f9;
        }

        .primary-tab-btn.active {
            color: #2563eb;
            background: #eff6ff;
        }

        /* Filter & Search Bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 260px;
        }

        .search-box input {
            width: 100%;
            padding: 9px 14px 9px 36px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            font-size: 13px;
            background: #ffffff;
            color: #1e293b;
            outline: none;
            box-sizing: border-box;
        }

        .search-box input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
        }

        .filters-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-select {
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            font-size: 13px;
            font-weight: 600;
            background: #ffffff;
            color: #1e293b;
            outline: none;
            cursor: pointer;
        }

        /* Table Card */
        .table-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }

        .data-table th {
            background: #f8fafc;
            padding: 12px 16px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e2e8f0;
        }

        .data-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #334155;
        }

        .data-table tr:hover td {
            background: #f8fafc;
        }

        /* Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .status-badge.pending {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .status-badge.accepted {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .status-badge.declined {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .office-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 5px;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Buttons */
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
        }

        .btn-accept {
            background: #16a34a;
            color: #ffffff;
        }
        .btn-accept:hover { background: #15803d; }

        .btn-decline {
            background: #dc2626;
            color: #ffffff;
        }
        .btn-decline:hover { background: #b91c1c; }

        .btn-view {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }
        .btn-view:hover { background: #dbeafe; }

        .btn-nap {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }
        .btn-nap:hover { background: #d1fae5; }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            background: #ffffff;
        }

        .empty-state svg {
            color: #94a3b8;
            margin-bottom: 12px;
        }

        .empty-state h3 {
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 6px 0;
        }

        .empty-state p {
            font-size: 13px;
            color: #64748b;
            margin: 0;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(2px);
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal-card {
            background: #ffffff;
            border-radius: 14px;
            width: 100%;
            max-width: 680px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
            border: 1px solid #e2e8f0;
        }

        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
        }

        .modal-close {
            background: transparent;
            border: none;
            font-size: 22px;
            color: #94a3b8;
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }

        .modal-close:hover { color: #1e293b; }

        .modal-body {
            padding: 22px;
        }

        .modal-footer {
            padding: 16px 22px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            border-radius: 0 0 14px 14px;
        }

        /* Dark Mode Overrides */
        [data-theme="dark"] .received-docs-container { color: #e2e8f0; }
        [data-theme="dark"] .table-card,
        [data-theme="dark"] .modal-card,
        [data-theme="dark"] .stat-card,
        [data-theme="dark"] .empty-state {
            background: #131c2e !important;
            border-color: #1e293b !important;
        }
        [data-theme="dark"] .header-title-box h1,
        [data-theme="dark"] .stat-value,
        [data-theme="dark"] .modal-header h3,
        [data-theme="dark"] .empty-state h3 {
            color: #f8fafc !important;
        }
        [data-theme="dark"] .data-table th {
            background: #0f172a !important;
            color: #cbd5e1 !important;
            border-color: #1e293b !important;
        }
        [data-theme="dark"] .data-table td {
            background: #131c2e !important;
            color: #e2e8f0 !important;
            border-color: #1e293b !important;
        }
        [data-theme="dark"] .search-box input,
        [data-theme="dark"] .filter-select {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
        [data-theme="dark"] .modal-footer {
            background: #0f172a !important;
            border-color: #1e293b !important;
        }
        [data-theme="dark"] .primary-tabs {
            border-color: #1e293b !important;
        }
        [data-theme="dark"] .primary-tab-btn {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .primary-tab-btn.active {
            background: #1e293b !important;
            color: #93c5fd !important;
        }
    </style>

    <!-- Header Section -->
    <div class="header-section">
        <div class="header-title-box">
            <div class="header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="8.5" cy="7" r="4"></circle>
                    <polyline points="17 11 19 13 23 9"></polyline>
                </svg>
            </div>
            <div>
                <h1>
                    Record Requests
                    <span class="subsystem-badge">Inter-Office RDP</span>
                </h1>
                <p>Manage and process inventory records created by or submitted to offices across the institution</p>
            </div>
        </div>

        @if($isSadm)
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 12px; font-weight: 700; color: #64748b;">Office View:</span>
                <select class="filter-select" wire:model.live="officeFilter">
                    <option value="">All Offices</option>
                    @foreach($officesList as $off)
                        <option value="{{ $off->office_code }}">{{ $off->office_code }} — {{ $off->office_name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    <!-- Alert Messages -->
    @if ($successMessage)
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; color: #166534; font-size: 13.5px; font-weight: 600;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span>✅</span>
                <span>{{ $successMessage }}</span>
            </div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; font-size: 16px; color: #166534; cursor: pointer;">&times;</button>
        </div>
    @endif
    @if ($errorMessage)
        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; color: #991b1b; font-size: 13.5px; font-weight: 600;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span>❌</span>
                <span>{{ $errorMessage }}</span>
            </div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; font-size: 16px; color: #991b1b; cursor: pointer;">&times;</button>
        </div>
    @endif

    <!-- Primary Tabs (Incoming vs Submitted) -->
    <div class="primary-tabs">
        <button type="button"
                wire:click="switchTab('incoming')"
                class="primary-tab-btn {{ $activeTab === 'incoming' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
            Incoming Requests
            @if($activeViewingOffice)
                <span style="font-size: 11px; opacity: 0.8;">(For {{ $activeViewingOffice }})</span>
            @else
                <span style="font-size: 11px; opacity: 0.8;">(All Offices)</span>
            @endif
        </button>
        <button type="button"
                wire:click="switchTab('submitted')"
                class="primary-tab-btn {{ $activeTab === 'submitted' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            Submitted Requests
            @if($activeViewingOffice)
                <span style="font-size: 11px; opacity: 0.8;">(Created by {{ $activeViewingOffice }})</span>
            @else
                <span style="font-size: 11px; opacity: 0.8;">(All Offices)</span>
            @endif
        </button>
    </div>

    <!-- Filters & Search Bar -->
    <div class="action-bar">
        <div class="search-box">
            <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search record series, subject, office, or request tracking ID...">
        </div>

        <div class="filters-group">
            <select class="filter-select" wire:model.live="statusFilter">
                <option value="all">All Statuses</option>
                <option value="pending">Pending Review</option>
                <option value="accepted">Accepted</option>
                <option value="declined">Declined</option>
            </select>

            <select class="filter-select" wire:model.live="dateFilter">
                <option value="all">All Time</option>
                <option value="today">Today</option>
                <option value="week">Past 7 Days</option>
                <option value="month">Past 30 Days</option>
            </select>
        </div>
    </div>

    <!-- Requests Table -->
    <div class="table-card">
        @if($records->count() > 0)
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Request ID</th>
                        <th>Record Series & Subject</th>
                        <th style="width: 140px;">{{ $activeTab === 'incoming' ? 'Requested By' : 'Target Office' }}</th>
                        <th style="width: 120px;">Retention</th>
                        <th style="width: 130px;">Date Submitted</th>
                        <th style="width: 130px; text-align: center;">Status</th>
                        <th style="width: 220px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($records as $rec)
                        @php
                            $isPending = $rec->request_status === 'pending';
                            $isAccepted = $rec->request_status === 'accepted';
                            $isDeclined = $rec->request_status === 'declined';
                            $canAction = ($activeTab === 'incoming' && $isPending);
                        @endphp
                        <tr wire:key="request-row-{{ $rec->id }}">
                            <td>
                                <span style="font-family: ui-monospace, SFMono-Regular, monospace; font-size: 11.5px; font-weight: 700; color: #2563eb;">
                                    {{ $rec->request_group_id ?: ('REQ-' . str_pad($rec->id, 5, '0', STR_PAD_LEFT)) }}
                                </span>
                            </td>
                            <td>
                                <div style="font-weight: 700; color: #0f172a; font-size: 13.5px; margin-bottom: 2px;">
                                    {{ $rec->series_title ?: 'Untitled Series' }}
                                </div>
                                <div style="font-size: 12px; color: #64748b; line-height: 1.35;">
                                    {{ \Illuminate\Support\Str::limit($rec->description, 80) }}
                                </div>
                                @if(!empty($rec->volume))
                                    <span style="font-size: 11px; color: #475569; font-weight: 600; background: #f1f5f9; padding: 1px 6px; border-radius: 4px; display: inline-block; margin-top: 4px;">
                                        Vol: {{ $rec->volume }}
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if($activeTab === 'incoming')
                                    <div class="office-pill" title="Origin Office">
                                        🏢 {{ $rec->request_origin_office ?: 'N/A' }}
                                    </div>
                                    @if($rec->creator_first_name)
                                        <div style="font-size: 11px; color: #64748b; margin-top: 3px;">
                                            {{ $rec->creator_first_name }} {{ $rec->creator_last_name }}
                                        </div>
                                    @endif
                                @else
                                    <div class="office-pill" style="background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe;" title="Target Office">
                                        🎯 {{ $rec->request_target_office ?: 'N/A' }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                <span style="font-size: 12px; font-weight: 600; color: #334155;">
                                    {{ $rec->total_period ?: 'N/A' }}
                                </span>
                            </td>
                            <td>
                                <div style="font-size: 12px; font-weight: 600; color: #334155;">
                                    {{ Carbon::parse($rec->created_at)->format('M d, Y') }}
                                </div>
                                <div style="font-size: 11px; color: #94a3b8;">
                                    {{ Carbon::parse($rec->created_at)->format('h:i A') }}
                                </div>
                            </td>
                            <td style="text-align: center;">
                                @if($isPending)
                                    <span class="status-badge pending">⏳ Pending</span>
                                @elseif($isAccepted)
                                    <span class="status-badge accepted">✅ Accepted</span>
                                @elseif($isDeclined)
                                    <span class="status-badge declined">❌ Declined</span>
                                @else
                                    <span class="status-badge">{{ ucfirst($rec->request_status ?? 'Unknown') }}</span>
                                @endif
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                                    <button type="button"
                                            wire:click="openDetailModal({{ $rec->id }})"
                                            class="btn-action btn-view"
                                            title="View Record Details">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                        View
                                    </button>

                                    @if($canAction)
                                        <button type="button"
                                                wire:click="openAcceptModal({{ $rec->id }})"
                                                class="btn-action btn-accept"
                                                title="Accept record to NAP Form 1">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                            Accept
                                        </button>

                                        <button type="button"
                                                wire:click="openDeclineModal({{ $rec->id }})"
                                                class="btn-action btn-decline"
                                                title="Decline record creation">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                            Decline
                                        </button>
                                    @elseif($isAccepted)
                                        <a href="{{ route('rdp.reports.nap-form-1', ['search' => $rec->description]) }}"
                                           class="btn-action btn-nap"
                                           title="View this record in NAP Form 1">
                                            NAP Form 1 &rarr;
                                        </a>
                                    @elseif($isDeclined && !empty($rec->request_decline_reason))
                                        <span style="font-size: 11px; color: #dc2626; font-style: italic; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $rec->request_decline_reason }}">
                                            "{{ $rec->request_decline_reason }}"
                                        </span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div style="padding: 16px 20px; border-top: 1px solid #e2e8f0;">
                {{ $records->links() }}
            </div>
        @else
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                <h3>No Record Requests Found</h3>
                <p>
                    @if($activeTab === 'incoming')
                        @if($activeViewingOffice)
                            There are currently no incoming record requests routed to {{ $activeViewingOffice }}.
                        @else
                            There are currently no incoming record requests across all offices.
                        @endif
                    @else
                        @if($activeViewingOffice)
                            Office {{ $activeViewingOffice }} has not submitted any record requests for other offices.
                        @else
                            There are currently no submitted record requests across all offices.
                        @endif
                    @endif
                </p>
            </div>
        @endif
    </div>

    <!-- ═══════ Accept Confirmation Modal ═══════ -->
    @if($showAcceptModal)
        <div class="modal-overlay">
            <div class="modal-card" style="max-width: 480px;">
                <div class="modal-header">
                    <h3>Accept Record Creation</h3>
                    <button wire:click="closeAcceptModal" class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <p style="font-size: 14px; color: #334155; margin-top: 0;">
                        Are you sure you want to accept this record request for <strong>"{{ $actionRecordTitle }}"</strong>?
                    </p>
                    <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 12px; font-size: 13px; color: #065f46; display: flex; align-items: flex-start; gap: 8px;">
                        <span>💡</span>
                        <div>
                            Accepting will officially record this entry into your office's <strong>NAP Form 1 (Inventory and Appraisal)</strong> records. The creator office will receive an acceptance notification.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" wire:click="closeAcceptModal" class="btn-action btn-view" style="padding: 8px 16px;">Cancel</button>
                    <button type="button" wire:click="acceptRequest" class="btn-action btn-accept" style="padding: 8px 16px;">Confirm & Accept Record</button>
                </div>
            </div>
        </div>
    @endif

    <!-- ═══════ Decline Modal ═══════ -->
    @if($showDeclineModal)
        <div class="modal-overlay">
            <div class="modal-card" style="max-width: 500px;">
                <div class="modal-header">
                    <h3 style="color: #991b1b;">Decline Record Creation</h3>
                    <button wire:click="closeDeclineModal" class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <p style="font-size: 14px; color: #334155; margin-top: 0;">
                        Are you sure you want to decline this record request for <strong>"{{ $actionRecordTitle }}"</strong>?
                    </p>
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                            Reason for Declining (Optional):
                        </label>
                        <textarea wire:model="declineReason"
                                  rows="3"
                                  class="search-box input"
                                  placeholder="e.g. Document series does not belong to our office, please revise..."
                                  style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 13px; box-sizing: border-box;"></textarea>
                    </div>
                    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px; font-size: 13px; color: #991b1b; display: flex; align-items: flex-start; gap: 8px;">
                        <span>⚠️</span>
                        <div>
                            This record will <strong>NOT</strong> be saved to your office's NAP Form 1 records. A notification will be dispatched to the creator office informing them of the rejection.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" wire:click="closeDeclineModal" class="btn-action btn-view" style="padding: 8px 16px;">Cancel</button>
                    <button type="button" wire:click="declineRequest" class="btn-action btn-decline" style="padding: 8px 16px;">Confirm Decline</button>
                </div>
            </div>
        </div>
    @endif

    <!-- ═══════ Record Details Modal ═══════ -->
    @if($showDetailModal && $selectedRecord)
        <div class="modal-overlay">
            <div class="modal-card">
                <div class="modal-header">
                    <div>
                        <h3>Record Request Details</h3>
                        <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                            {{ $selectedRecord->request_group_id ?: ('REQ-' . str_pad($selectedRecord->id, 5, '0', STR_PAD_LEFT)) }}
                        </div>
                    </div>
                    <button wire:click="closeDetailModal" class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <!-- Status Banner -->
                    <div style="margin-bottom: 20px; padding: 12px 16px; border-radius: 8px; display: flex; align-items: center; justify-content: space-between;
                        @if($selectedRecord->request_status === 'accepted') background: #dcfce7; border: 1px solid #bbf7d0; color: #166534;
                        @elseif($selectedRecord->request_status === 'declined') background: #fee2e2; border: 1px solid #fecaca; color: #991b1b;
                        @else background: #fef3c7; border: 1px solid #fde68a; color: #92400e; @endif">
                        <div style="font-weight: 700; font-size: 13px;">
                            Status: {{ strtoupper($selectedRecord->request_status ?? 'PENDING') }}
                        </div>
                        @if($selectedRecord->request_actioned_at)
                            <div style="font-size: 12px;">
                                Actioned on {{ Carbon::parse($selectedRecord->request_actioned_at)->format('M d, Y h:i A') }}
                            </div>
                        @endif
                    </div>

                    @if($selectedRecord->request_status === 'declined' && !empty($selectedRecord->request_decline_reason))
                        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 14px; margin-bottom: 20px; font-size: 13px; color: #991b1b;">
                            <strong>Decline Reason:</strong> {{ $selectedRecord->request_decline_reason }}
                        </div>
                    @endif

                    <!-- Details Grid -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px;">
                        <div>
                            <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b;">Origin Office (Creator)</div>
                            <div style="font-weight: 700; color: #1e293b; margin-top: 3px;">
                                {{ $selectedRecord->request_origin_office }}
                                @if($selectedRecord->creator_first_name)
                                    <span style="font-weight: 400; font-size: 12px; color: #64748b;">({{ $selectedRecord->creator_first_name }} {{ $selectedRecord->creator_last_name }})</span>
                                @endif
                            </div>
                        </div>

                        <div>
                            <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b;">Target Office (Recipient)</div>
                            <div style="font-weight: 700; color: #1e293b; margin-top: 3px;">
                                {{ $selectedRecord->request_target_office }}
                            </div>
                        </div>

                        <div>
                            <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b;">Record Series Title</div>
                            <div style="font-weight: 700; color: #1e293b; margin-top: 3px;">
                                {{ $selectedRecord->series_title }}
                            </div>
                        </div>

                        <div>
                            <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b;">Submission Date</div>
                            <div style="font-weight: 600; color: #1e293b; margin-top: 3px;">
                                {{ Carbon::parse($selectedRecord->created_at)->format('F d, Y h:i A') }}
                            </div>
                        </div>
                    </div>

                    <!-- Subject / Description -->
                    <div style="margin-bottom: 20px;">
                        <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b;">Description / Subject</div>
                        <div style="font-size: 13.5px; color: #1e293b; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 4px; white-space: pre-wrap;">{{ $selectedRecord->description }}</div>
                    </div>

                    <!-- Technical Specs -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 20px; background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <div>
                            <span style="font-size: 11px; color: #64748b; font-weight: 600;">Volume:</span>
                            <div style="font-weight: 700; font-size: 13px; color: #1e293b;">{{ $selectedRecord->volume ?: 'N/A' }}</div>
                        </div>
                        <div>
                            <span style="font-size: 11px; color: #64748b; font-weight: 600;">Records Medium:</span>
                            <div style="font-weight: 700; font-size: 13px; color: #1e293b;">{{ $selectedRecord->medium_name ?: 'N/A' }}</div>
                        </div>
                        <div>
                            <span style="font-size: 11px; color: #64748b; font-weight: 600;">Location:</span>
                            <div style="font-weight: 700; font-size: 13px; color: #1e293b;">{{ $selectedRecord->records_location ?: 'N/A' }}</div>
                        </div>
                        <div>
                            <span style="font-size: 11px; color: #64748b; font-weight: 600;">Retention:</span>
                            <div style="font-weight: 700; font-size: 13px; color: #1e293b;">{{ $selectedRecord->total_period ?: 'N/A' }}</div>
                        </div>
                    </div>

                    <!-- Periods Covered (if sub periods exist) -->
                    @if(!empty($selectedSubPeriods))
                        <div style="margin-bottom: 20px;">
                            <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b; margin-bottom: 6px;">Periods Covered</div>
                            <div style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                                    <thead style="background: #f1f5f9;">
                                        <tr>
                                            <th style="padding: 6px 10px; text-align: left;">Date Covered</th>
                                            <th style="padding: 6px 10px; text-align: left;">Description</th>
                                            <th style="padding: 6px 10px; text-align: right;">Volume</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($selectedSubPeriods as $p)
                                            <tr style="border-top: 1px solid #f1f5f9;">
                                                <td style="padding: 6px 10px; font-weight: 600;">
                                                    {{ $p->date_covered }} {{ !empty($p->date_covered_end) ? '➔ ' . $p->date_covered_end : '' }}
                                                </td>
                                                <td style="padding: 6px 10px; color: #64748b;">{{ $p->description ?? '-' }}</td>
                                                <td style="padding: 6px 10px; text-align: right; font-weight: 600;">{{ $p->volume ?? '-' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <!-- Attached Document -->
                    @if($selectedAttachedFile)
                        <div style="background: #f1f5f9; border-radius: 8px; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span style="font-size: 20px;">📎</span>
                                <div>
                                    <div style="font-weight: 700; font-size: 13px; color: #1e293b;">
                                        {{ $selectedAttachedFile->document_name ?? 'Attached Document' }}
                                    </div>
                                    <div style="font-size: 11px; color: #64748b;">
                                        {{ $selectedAttachedFile->file_type ?? 'File' }} &bull; {{ !empty($selectedAttachedFile->file_size) ? round($selectedAttachedFile->file_size / 1024, 1) . ' KB' : '' }}
                                    </div>
                                </div>
                            </div>
                            <a href="{{ route('rdp.view-document', ['path' => $selectedAttachedFile->document_path ?? '', 'id' => $selectedAttachedFile->document_id ?? '']) }}"
                               target="_blank"
                               class="btn-action btn-view"
                               style="font-size: 12px;">
                                Open File &rarr;
                            </a>
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    @if($activeTab === 'incoming' && $selectedRecord->request_status === 'pending')
                        <button type="button"
                                wire:click="openDeclineModal({{ $selectedRecord->id }})"
                                class="btn-action btn-decline">
                            Decline Record
                        </button>
                        <button type="button"
                                wire:click="openAcceptModal({{ $selectedRecord->id }})"
                                class="btn-action btn-accept">
                            Accept Record
                        </button>
                    @elseif($selectedRecord->request_status === 'accepted')
                        <a href="{{ route('rdp.reports.nap-form-1', ['search' => $selectedRecord->description]) }}"
                           class="btn-action btn-nap">
                            View in NAP Form 1 &rarr;
                        </a>
                    @endif
                    <button type="button" wire:click="closeDetailModal" class="btn-action btn-view">Close</button>
                </div>
            </div>
        </div>
    @endif
</div>
