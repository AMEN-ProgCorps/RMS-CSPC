<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.rdp')] #[Title('Draft Records and Disposition Schedule')] class extends Component {
    public string $search = '';
    public string $successMessage = '';
    public string $errorMessage = '';

    public bool $showEditModal = false;
    public ?int $editingSeriesId = null;
    public string $editSeriesTitle = '';
    public string $editRemarks = '';
    public string $editActivePeriod = '';
    public string $editStoragePeriod = '';
    public bool $editIsPermanent = false;

    public function mount(): void
    {
        $perms = Auth::user()?->permissions;
        if (!$perms || (!(bool)($perms->is_sadm ?? false) && !(bool)($perms->can_access_rdp ?? true))) {
            redirect()->route('rdp')->send();
            return;
        }
    }

    public function openEditSeriesModal(int $seriesId): void
    {
        $series = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->where('rdp_record_series.id', $seriesId)
            ->select([
                'rdp_record_series.*',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period'
            ])
            ->first();

        if (!$series) return;

        $this->editingSeriesId = $series->id;
        $this->editSeriesTitle = $series->series_title ?? '';
        $this->editRemarks = $series->remarks ?? '';
        $this->editActivePeriod = $series->active_period ?? '';
        $this->editStoragePeriod = $series->storage_period ?? '';
        $this->editIsPermanent = (bool)($series->is_retention_period_permanent ?? false) || strtolower($series->total_period ?? '') === 'permanent';
        $this->showEditModal = true;
    }

    public function closeEditSeriesModal(): void
    {
        $this->showEditModal = false;
        $this->editingSeriesId = null;
    }

    public function saveSeriesDraftEdits(bool $andSubmit = false): void
    {
        if (!$this->editingSeriesId) return;

        try {
            DB::beginTransaction();

            $series = DB::table('rdp_record_series')->where('id', $this->editingSeriesId)->first();
            if ($series) {
                $retentionId = $series->retention_period;

                $activeP = $this->editIsPermanent ? 'Permanent' : (trim($this->editActivePeriod) ?: null);
                $storageP = $this->editIsPermanent ? 'Permanent' : (trim($this->editStoragePeriod) ?: null);
                $totalP = $this->editIsPermanent ? 'Permanent' : (trim($this->editActivePeriod) . ' / ' . trim($this->editStoragePeriod));

                if ($retentionId) {
                    DB::table('rdp_retention_period')->where('id', $retentionId)->update([
                        'active_period'  => $activeP,
                        'storage_period' => $storageP,
                        'total_period'   => $totalP,
                        'updated_at'     => now(),
                    ]);
                } elseif ($this->editIsPermanent || !empty($activeP) || !empty($storageP)) {
                    $retentionId = DB::table('rdp_retention_period')->insertGetId([
                        'active_period'  => $activeP,
                        'storage_period' => $storageP,
                        'total_period'   => $totalP,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }

                DB::table('rdp_record_series')->where('id', $this->editingSeriesId)->update([
                    'series_title'                  => mb_strtoupper(trim($this->editSeriesTitle)),
                    'remarks'                       => trim($this->editRemarks) ?: null,
                    'retention_period'              => $retentionId,
                    'is_retention_period_permanent' => $this->editIsPermanent,
                    'updated_at'                    => now(),
                ]);

                if ($andSubmit) {
                    $this->submitForApproval($this->editingSeriesId);
                }
            }

            DB::commit();

            $this->successMessage = $andSubmit 
                ? 'Draft schedule entry updated and submitted for approval!' 
                : 'Draft schedule entry updated successfully!';
            $this->closeEditSeriesModal();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to save draft edits: ' . $e->getMessage();
        }
    }

    public function deleteDraftSeries(int $seriesId): void
    {
        try {
            DB::table('rdp_record_series')->where('id', $seriesId)->where('is_verified', false)->delete();
            $this->successMessage = 'Draft schedule entry deleted successfully.';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete draft: ' . $e->getMessage();
        }
    }

    public function submitForApproval(int $seriesId): void
    {
        try {
            $user = Auth::user();
            $userOffice = $user?->details?->office_code;

            // Create a pending cluster in rdp_pending_record_series
            $series = DB::table('rdp_record_series')->where('id', $seriesId)->first();
            if (!$series) return;

            $mainPendingTbl = \Illuminate\Support\Facades\Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';
            $clusterId = DB::table($mainPendingTbl)->insertGetId([
                'status'     => 'UNUSED',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('rdp_pending_record_series')->insert([
                'cluster_id'   => $clusterId,
                'cluster_name' => 'Schedule Submission — ' . ($series->series_title ?? 'Series Cluster'),
                'status_id'    => 1, // Pending Verification
                'office'       => $userOffice,
                'created_by'   => $user->id,
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            DB::table('rdp_grouped_record_series')->insert([
                'group_head'       => $clusterId,
                'record_series_id' => $seriesId,
                'is_active'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $this->successMessage = 'Draft schedule item submitted for evaluation & approval!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to submit draft: ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        $userOffice = Auth::user()?->details?->office_code;

        $query = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
            ->where('rdp_record_series.is_verified', false);

        if ($userOffice) {
            $query->where('rdp_record_series.recorded_at_office', $userOffice);
        }

        if (!empty(trim($this->search))) {
            $term = '%' . trim($this->search) . '%';
            $query->where(function($q) use ($term) {
                $q->where('rdp_record_series.series_title', 'ILIKE', $term)
                  ->orWhere('rdp_record_series.remarks', 'ILIKE', $term);
            });
        }

        $draftSeries = $query->select([
            'rdp_record_series.*',
            'rdp_retention_period.active_period',
            'rdp_retention_period.storage_period',
            'rdp_retention_period.total_period',
            'parent.series_title as parent_title'
        ])
        ->orderBy('rdp_record_series.updated_at', 'desc')
        ->get();

        return [
            'draftSeries' => $draftSeries,
        ];
    }
}; ?>

<div class="draft-schedule-page">
    <style>
        .draft-schedule-page {
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
        .search-input {
            padding: 9px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            width: 300px;
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

        .draft-table-wrapper {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .draft-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }
        .draft-table th {
            background: #1e293b;
            color: #ffffff;
            font-weight: 600;
            padding: 14px 16px;
            border-right: 1px solid #334155;
        }
        .draft-table td {
            padding: 14px 16px;
            color: #334155;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #f1f5f9;
        }
        .draft-badge {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .btn-resume {
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
        .btn-submit {
            background: #16a34a;
            color: #ffffff;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 4px;
        }
        .btn-delete {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        /* Modal Styles */
        .ia-modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .ia-modal-card {
            background: #ffffff;
            border-radius: 14px;
            max-width: 650px;
            width: 100%;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            overflow: hidden;
        }
        .ia-modal-header {
            padding: 18px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .ia-modal-header h3 {
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .ia-modal-body {
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            max-height: 70vh;
            overflow-y: auto;
        }
        .ia-modal-footer {
            padding: 16px 24px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }
        .form-control {
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }

        /* ==========================================================================
           Draft Schedule - Dark Mode Overrides
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

        [data-theme="dark"] .search-input {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .draft-table-wrapper {
            background: #131c2e !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .draft-table th {
            background: #0f172a !important;
            color: #94a3b8 !important;
            border-right-color: #1e293b !important;
            border-bottom: 2px solid #1e293b !important;
        }

        [data-theme="dark"] .draft-table td {
            color: #cbd5e1 !important;
            border-bottom-color: #1e293b !important;
            border-right-color: #1e293b !important;
        }

        [data-theme="dark"] .draft-table tr:hover td {
            background-color: #1a253c !important;
        }

        [data-theme="dark"] .btn-resume {
            background: #0f172a !important;
            color: #60a5fa !important;
            border-color: #3b82f6 !important;
        }

        [data-theme="dark"] .btn-resume:hover {
            background: #1e293b !important;
        }

        [data-theme="dark"] .btn-delete {
            background: rgba(239, 68, 68, 0.15) !important;
            color: #f87171 !important;
            border-color: rgba(239, 68, 68, 0.3) !important;
        }

        [data-theme="dark"] .btn-delete:hover {
            background: rgba(239, 68, 68, 0.25) !important;
        }

        [data-theme="dark"] .ia-modal-card {
            background: #131c2e !important;
            border: 1px solid #1e293b !important;
        }

        [data-theme="dark"] .ia-modal-header,
        [data-theme="dark"] .ia-modal-footer {
            background: #0f172a !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .ia-modal-header h3 {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .form-group label {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .form-control {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
    </style>

    <div class="header-card">
        <div class="header-title">
            <h1>Draft Records & Disposition Schedule</h1>
            <p>View and manage unapproved NAP Form 2 record series schedule drafts saved by your office</p>
        </div>
        <div>
            <input type="text" wire:model.live.debounce.250ms="search" class="search-input" placeholder="Search draft schedule items...">
        </div>
    </div>

    @if(!empty($successMessage))
        <div class="alert-msg alert-success">{{ $successMessage }}</div>
    @endif
    @if(!empty($errorMessage))
        <div class="alert-msg alert-error">{{ $errorMessage }}</div>
    @endif

    <div class="draft-table-wrapper">
        <table class="draft-table">
            <thead>
                <tr>
                    <th>Record Series Title</th>
                    <th style="width: 140px;">Retention</th>
                    <th>Remarks / Guidance</th>
                    <th style="width: 110px;">Status</th>
                    <th style="width: 150px;">Last Modified</th>
                    <th style="width: 260px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($draftSeries as $ds)
                    <tr>
                        <td>
                            <strong>{{ $ds->series_title }}</strong>
                            @if(!empty($ds->parent_title))
                                <div style="font-size: 11px; color: #64748b;">Sub-series of: {{ $ds->parent_title }}</div>
                            @endif
                        </td>
                        <td>
                            @if($ds->is_retention_period_permanent)
                                <strong style="color: #166534;">Permanent</strong>
                            @else
                                {{ $ds->total_period ?? $ds->active_period ?? '—' }}
                            @endif
                        </td>
                        <td>{{ $ds->remarks ?? '—' }}</td>
                        <td><span class="draft-badge">Draft</span></td>
                        <td>{{ \Carbon\Carbon::parse($ds->updated_at)->format('M d, Y g:i A') }}</td>
                        <td style="text-align: center;">
                            <button wire:click="openEditSeriesModal({{ $ds->id }})" class="btn-resume">Edit</button>
                            <button wire:click="submitForApproval({{ $ds->id }})" class="btn-submit">Submit for Approval</button>
                            <button wire:click="deleteDraftSeries({{ $ds->id }})" wire:confirm="Delete this draft schedule entry?" class="btn-delete">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 48px; color: #64748b;">
                            No draft Records & Disposition Schedule items found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Edit Draft Modal Container -->
    @if($showEditModal)
        <div class="ia-modal-overlay">
            <div class="ia-modal-card">
                <div class="ia-modal-header">
                    <h3>Edit Draft Record Series Schedule</h3>
                    <button wire:click="closeEditSeriesModal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">&times;</button>
                </div>
                <div class="ia-modal-body">
                    <div class="form-group">
                        <label>Record Series Title</label>
                        <input type="text" class="form-control" wire:model="editSeriesTitle">
                    </div>
                    <div class="form-group">
                        <label>Remarks / Disposition Provisions</label>
                        <textarea class="form-control" wire:model="editRemarks" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" wire:model.live="editIsPermanent">
                            Permanent Record Series
                        </label>
                    </div>
                    @if(!$editIsPermanent)
                        <div style="display: flex; gap: 12px;">
                            <div class="form-group" style="flex: 1;">
                                <label>Active Period</label>
                                <input type="text" class="form-control" wire:model="editActivePeriod" placeholder="e.g. 1 Year">
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label>Storage Period</label>
                                <input type="text" class="form-control" wire:model="editStoragePeriod" placeholder="e.g. 4 Years">
                            </div>
                        </div>
                    @endif
                </div>
                <div class="ia-modal-footer">
                    <button type="button" wire:click="closeEditSeriesModal" class="btn-delete" style="border: 1px solid #cbd5e1; background: #ffffff; color: #475569;">Cancel</button>
                    <button type="button" wire:click="saveSeriesDraftEdits(false)" class="btn-resume" style="padding: 8px 16px;">Save</button>
                    <button type="button" wire:click="saveSeriesDraftEdits(true)" class="btn-submit" style="padding: 8px 16px;">Save & Submit</button>
                </div>
            </div>
        </div>
    @endif
</div>
