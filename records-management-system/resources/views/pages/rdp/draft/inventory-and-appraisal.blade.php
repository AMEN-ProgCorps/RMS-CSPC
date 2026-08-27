<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.rdp')] #[Title('Draft Inventory and Appraisal')] class extends Component {
    public string $search = '';
    public string $successMessage = '';
    public string $errorMessage = '';

    public bool $showEditModal = false;
    public ?int $editingDraftId = null;
    public string $editSeriesTitle = '';
    public string $editDescription = '';
    public string $editVolume = '';
    public string $editLocation = '';
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

    public function openEditDraftModal(int $recordId): void
    {
        $rec = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->leftJoin('rdp_retention_period', 'rdp_record.period_id', '=', 'rdp_retention_period.id')
            ->where('rdp_record.id', $recordId)
            ->select([
                'rdp_record.*',
                'rdp_record_series.series_title',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period'
            ])
            ->first();

        if (!$rec) return;

        $this->editingDraftId = $rec->id;
        $this->editSeriesTitle = $rec->series_title ?? '';
        $this->editDescription = $rec->description ?? '';
        $this->editVolume = $rec->volume ?? '';
        $this->editLocation = $rec->records_location ?? '';
        $this->editActivePeriod = $rec->active_period ?? '';
        $this->editStoragePeriod = $rec->storage_period ?? '';
        $this->editIsPermanent = strtolower($rec->total_period ?? '') === 'permanent' || strtolower($rec->active_period ?? '') === 'permanent';
        $this->showEditModal = true;
    }

    public function closeEditDraftModal(): void
    {
        $this->showEditModal = false;
        $this->editingDraftId = null;
    }

    public function saveDraftEdits(bool $andSubmit = false): void
    {
        if (!$this->editingDraftId) return;

        try {
            DB::beginTransaction();

            $rec = DB::table('rdp_record')->where('id', $this->editingDraftId)->first();
            if ($rec) {
                DB::table('rdp_record')->where('id', $this->editingDraftId)->update([
                    'description'      => mb_strtoupper($this->editDescription),
                    'volume'           => mb_strtoupper($this->editVolume),
                    'records_location' => mb_strtoupper($this->editLocation),
                    'is_draft'         => !$andSubmit,
                    'updated_at'       => now(),
                ]);

                if ($rec->period_id) {
                    $activeP = $this->editIsPermanent ? 'Permanent' : (trim($this->editActivePeriod) ?: null);
                    $storageP = $this->editIsPermanent ? 'Permanent' : (trim($this->editStoragePeriod) ?: null);
                    $totalP = $this->editIsPermanent ? 'Permanent' : (trim($this->editActivePeriod) . ' / ' . trim($this->editStoragePeriod));

                    DB::table('rdp_retention_period')->where('id', $rec->period_id)->update([
                        'active_period'  => $activeP,
                        'storage_period' => $storageP,
                        'total_period'   => $totalP,
                        'updated_at'     => now(),
                    ]);
                }
            }

            DB::commit();

            $this->successMessage = $andSubmit 
                ? 'Draft record updated and submitted successfully!' 
                : 'Draft record updated successfully!';
            $this->closeEditDraftModal();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to save draft edits: ' . $e->getMessage();
        }
    }

    public function deleteDraft(int $recordId): void
    {
        try {
            DB::table('rdp_record')->where('id', $recordId)->where('is_draft', true)->delete();
            $this->successMessage = 'Draft record deleted successfully.';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete draft: ' . $e->getMessage();
        }
    }

    public function submitDraft(int $recordId): void
    {
        try {
            DB::table('rdp_record')->where('id', $recordId)->update([
                'is_draft'   => false,
                'updated_at' => now(),
            ]);
            $this->successMessage = 'Draft record submitted successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to submit draft: ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        $userOffice = Auth::user()?->details?->office_code;

        $query = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->leftJoin('rdp_retention_period', 'rdp_record.period_id', '=', 'rdp_retention_period.id')
            ->where('rdp_record.is_draft', true);

        if ($userOffice) {
            $query->where('rdp_record.office_own', $userOffice);
        }

        if (!empty(trim($this->search))) {
            $term = '%' . trim($this->search) . '%';
            $query->where(function($q) use ($term) {
                $q->where('rdp_record.description', 'ILIKE', $term)
                  ->orWhere('rdp_record_series.series_title', 'ILIKE', $term);
            });
        }

        $drafts = $query->select([
            'rdp_record.*',
            'rdp_record_series.series_title',
            'rdp_retention_period.total_period'
        ])
        ->orderBy('rdp_record.updated_at', 'desc')
        ->get();

        return [
            'drafts' => $drafts,
        ];
    }
}; ?>

<div class="draft-page">
    <style>
        .draft-page {
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
           Draft Inventory & Appraisal - Dark Mode Overrides
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
            <h1>Draft Inventory & Appraisal Records</h1>
            <p>View and manage unfinished NAP Form 1 draft entries saved by your office</p>
        </div>
        <div>
            <input type="text" wire:model.live.debounce.250ms="search" class="search-input" placeholder="Search draft records...">
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
                    <th>Series Title</th>
                    <th>Description / Details</th>
                    <th style="width: 140px;">Retention</th>
                    <th style="width: 110px;">Status</th>
                    <th style="width: 150px;">Last Saved</th>
                    <th style="width: 220px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($drafts as $d)
                    <tr>
                        <td><strong>{{ $d->series_title ?? 'Unassigned Series' }}</strong></td>
                        <td>{{ $d->description ?: 'No description entered' }}</td>
                        <td>{{ $d->total_period ?? '—' }}</td>
                        <td><span class="draft-badge">Draft</span></td>
                        <td>{{ \Carbon\Carbon::parse($d->updated_at)->format('M d, Y g:i A') }}</td>
                        <td style="text-align: center;">
                            <button wire:click="openEditDraftModal({{ $d->id }})" class="btn-resume">Edit</button>
                            <button wire:click="submitDraft({{ $d->id }})" class="btn-submit">Submit</button>
                            <button wire:click="deleteDraft({{ $d->id }})" wire:confirm="Delete this draft?" class="btn-delete">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 48px; color: #64748b;">
                            No draft Inventory & Appraisal records found.
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
                    <h3>Edit Draft Inventory Record</h3>
                    <button wire:click="closeEditDraftModal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">&times;</button>
                </div>
                <div class="ia-modal-body">
                    <div class="form-group">
                        <label>Record Series Title</label>
                        <input type="text" class="form-control" value="{{ $editSeriesTitle }}" disabled style="background: #f1f5f9; color: #64748b;">
                    </div>
                    <div class="form-group">
                        <label>Description / Specific Details</label>
                        <textarea class="form-control" wire:model="editDescription" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Volume / Quantity</label>
                        <input type="text" class="form-control" wire:model="editVolume" placeholder="e.g. 0.5 cu. m.">
                    </div>
                    <div class="form-group">
                        <label>Location of Records</label>
                        <input type="text" class="form-control" wire:model="editLocation" placeholder="e.g. Records Office Cabinet 1">
                    </div>
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" wire:model.live="editIsPermanent">
                            Permanent Record
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
                    <button type="button" wire:click="closeEditDraftModal" class="btn-delete" style="border: 1px solid #cbd5e1; background: #ffffff; color: #475569;">Cancel</button>
                    <button type="button" wire:click="saveDraftEdits(false)" class="btn-resume" style="padding: 8px 16px;">Save</button>
                    <button type="button" wire:click="saveDraftEdits(true)" class="btn-submit" style="padding: 8px 16px;">Save & Submit</button>
                </div>
            </div>
        </div>
    @endif
</div>
