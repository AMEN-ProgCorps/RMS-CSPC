<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - NAP Form 2')] class extends Component {
    public string $search = '';
    public string $retentionFilter = ''; // 'all', 'permanent', 'temporary'
    public string $officeFilter = ''; // '' for all, or office_code
    
    // Checkbox selections & feedback messages
    public array $selectedIds = [];
    public bool $selectAll = false;
    public string $errorMessage = '';
    public string $successMessage = '';

    // Cluster Creation Modal Properties
    public bool $showClusterModal = false;
    public string $clusterName = '';
    public string $clusterNotes = '';

    public function openClusterModal(): void
    {
        if (empty($this->selectedIds)) {
            $this->errorMessage = 'Please select at least one record series to create an RDS cluster.';
            return;
        }

        $userOffice = Auth::user()?->details?->office_code ?? 'OFFICE';
        $this->clusterName = 'RDS Schedule Cluster — ' . $userOffice . ' (' . Carbon::now()->format('Y-m-d') . ')';
        $this->clusterNotes = '';
        $this->showClusterModal = true;
    }

    public function closeClusterModal(): void
    {
        $this->showClusterModal = false;
    }

    public function submitClusterCreation(): void
    {
        if (empty($this->selectedIds)) {
            $this->errorMessage = 'Please select at least one record series to create an RDS cluster.';
            return;
        }

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOffice = $user?->details?->office_code ?? null;

            $mainPendingTbl = \Illuminate\Support\Facades\Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';
            $mainPendingId = DB::table($mainPendingTbl)->insertGetId([
                'status'     => 'UNUSED',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('rdp_pending_record_series')->insert([
                'cluster_id'   => $mainPendingId,
                'cluster_name' => trim($this->clusterName) ?: ('RDS Schedule Batch — ' . now()->format('Y-m-d')),
                'status_id'    => 1, // Pending Verification
                'office'       => $userOffice,
                'created_by'   => $user?->id,
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            foreach ($this->selectedIds as $sId) {
                DB::table('rdp_grouped_record_series')->insert([
                    'group_head'       => $mainPendingId,
                    'record_series_id' => (int)$sId,
                    'is_active'        => true,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            DB::commit();

            $this->successMessage = 'RDS Schedule cluster created successfully! It is now available under Pending / List for printing and approval.';
            $this->selectedIds = [];
            $this->selectAll = false;
            $this->closeClusterModal();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to create RDS cluster: ' . $e->getMessage();
        }
    }

    // Print Preview Modal Properties
    public bool $showPrintModal = false;

    // View Modal Properties
    public bool $showViewModal = false;
    public ?object $viewSeriesData = null;

    // Edit Modal Properties
    public bool $showEditModal = false;
    public ?int $editingSeriesId = null;
    public string $editSeriesTitle = '';
    public ?string $editItemNumber = '';
    public string $editRemarks = '';
    public bool $isRootParentForEdit = false;
    public bool $isSeriesUsedInNap1 = false;

    // Preview Header & Signature Fields
    public string $agencyName = 'Camarines Sur Polytechnic Colleges';
    public string $agencyAddress = 'San Miguel, Nabua, Camarines Sur';
    public string $scheduleNo = 'RDS-2024-001';
    public string $datePrepared = '';

    // Signature Block Fields (Page 2 of Preview)
    public string $preparedBy = '';
    public string $preparedPosition = 'Records Officer / Custodian';
    public string $assistedBy = '';
    public string $assistedPosition = 'NAP Records Management Analyst';
    public string $recommendingBy = '';
    public string $recommendingPosition = 'Vice President for Administration';
    public string $approvedBy = '';
    public string $approvedPosition = 'College President / Head of Agency';
    public string $committeeChairmanName = '';
    public string $committeeChairmanTitle = 'Chairman, Records Management Committee';
    public string $executiveDirectorName = '';
    public string $executiveDirectorTitle = 'Executive Director, National Archives of the Philippines';

    public function mount(): void
    {
        $user = Auth::user();
        $perms = $user?->permissions;
        // Access clearance check
        if (!$perms || (!(bool)($perms->is_sadm ?? false) && !(bool)($perms->can_rdp_access_form_2 ?? true))) {
            redirect()->route('rdp')->send();
            return;
        }
        $details = $user?->details;
        
        $this->datePrepared = Carbon::now()->format('F d, Y');

        $userOffice = $details?->office_code ?? $details?->office?->office_code ?? null;
        if ($userOffice) {
            $this->officeFilter = $userOffice;
        }

        if ($details) {
            $fullName = trim(($details->first_name ?? '') . ' ' . ($details->last_name ?? ''));
            $this->preparedBy = $fullName ?: ($user->username ?? 'Records Officer');
            $this->preparedPosition = $details->designation ?? 'Records Officer';
        }
    }

    public function openPrintModal(array $specificIds = []): void
    {
        if (!empty($specificIds)) {
            $this->selectedIds = array_map('strval', $specificIds);
        }
        $this->showPrintModal = true;
    }

    public function closePrintModal(): void
    {
        $this->showPrintModal = false;
    }

    public function updatedSelectAll($value): void
    {
        if ($value) {
            $user = Auth::user();
            $perms = $user?->permissions;
            $isSadm = (bool)($perms->is_sadm ?? false);
            $userOffice = $user?->details?->office_code ?? $user?->details?->office?->office_code ?? null;

            $query = DB::table('rdp_record_series')
                ->where('rdp_record_series.is_active', true)
                ->where(function($q) {
                    $q->where('rdp_record_series.is_verified', false)
                      ->orWhereNull('rdp_record_series.is_verified');
                });

            if (!$isSadm) {
                $query->where('rdp_record_series.recorded_at_office', $userOffice ?: '___NONE___');
            } elseif (!empty($this->officeFilter)) {
                $query->where('rdp_record_series.recorded_at_office', $this->officeFilter);
            }

            if (!empty($this->search)) {
                $s = '%' . trim($this->search) . '%';
                $query->where(function ($q) use ($s) {
                    $q->where('rdp_record_series.series_title', 'ilike', $s)
                      ->orWhere('rdp_record_series.remarks', 'ilike', $s)
                      ->orWhere(DB::raw("CAST(rdp_record_series.item_number AS TEXT)"), 'ilike', $s);
                });
            }

            $allIds = $query->pluck('id')->toArray();
            $this->selectedIds = array_map('strval', $allIds);
        } else {
            $this->selectedIds = [];
        }
    }


    public function openViewModal(int $id): void
    {
        $query = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
            ->select([
                'rdp_record_series.*',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
                'parent.series_title as parent_title',
            ]);

        $record = $query->where('rdp_record_series.id', $id)->first();
        if ($record) {
            $allFetchedMap = DB::table('rdp_record_series')
                ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
                ->select(['rdp_record_series.*', 'rdp_retention_period.active_period', 'rdp_retention_period.storage_period', 'rdp_retention_period.total_period'])
                ->get()
                ->keyBy('id')
                ->all();

            $eff = $this->resolveEffectiveRetention($allFetchedMap, $record);
            $record->effective_active = $eff->active_period;
            $record->effective_storage = $eff->storage_period;
            $record->effective_total = $eff->total_period;
            $record->effective_is_permanent = $eff->is_retention_period_permanent;
            $record->is_inherited = $eff->inherited;
            $record->is_root_parent = empty($record->parent_id);

            $this->viewSeriesData = $record;
            $this->showViewModal = true;
        }
    }

    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewSeriesData = null;
    }

    public function openEditModal(int $id): void
    {
        $perms = Auth::user()?->permissions;
        $isSadm = (bool)($perms->is_sadm ?? false);
        // Modify clearance
        if (!$isSadm && !(bool)($perms->can_rdp_modify_form_2 ?? true)) {
            $this->errorMessage = 'You do not have clearance to edit records on NAP Form 2.';
            return;
        }
        $record = DB::table('rdp_record_series')->where('id', $id)->first();
        if ($record) {
            // Edit-others clearance
            $userOffice = Auth::user()?->details?->office_code ?? null;
            $isOtherOffice = $userOffice && $record->recorded_at_office && $record->recorded_at_office !== $userOffice;
            if (!$isSadm && $isOtherOffice && !(bool)($perms->can_rdp_edit_others_form_2 ?? false)) {
                $this->errorMessage = 'You do not have clearance to edit records from another office on NAP Form 2.';
                return;
            }
            $this->editingSeriesId = $record->id;
            $this->editSeriesTitle = $record->series_title ?? '';
            $this->editItemNumber = $record->item_number !== null ? (string)$record->item_number : '';
            $this->editRemarks = $record->remarks ?? '';
            $this->isRootParentForEdit = empty($record->parent_id);

            // Check if this record series (or any of its child series) is currently used in NAP Form 1 (rdp_record)
            $checkIds = [$record->id];
            $childIds = DB::table('rdp_record_series')
                ->where('parent_id', $record->id)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
            if (!empty($childIds)) {
                $checkIds = array_merge($checkIds, $childIds);
            }

            $this->isSeriesUsedInNap1 = DB::table('rdp_record')
                ->whereIn('record_series_id', $checkIds)
                ->where('is_active', true)
                ->exists();

            $this->showEditModal = true;
        }
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editingSeriesId = null;
        $this->isSeriesUsedInNap1 = false;
    }

    public function cancelRecordSeries(): void
    {
        if (!$this->editingSeriesId) return;

        $perms = Auth::user()?->permissions;
        $isSadm = (bool)($perms->is_sadm ?? false);
        if (!$isSadm && !(bool)($perms->can_rdp_modify_form_2 ?? true)) {
            $this->errorMessage = 'You do not have clearance to cancel records on NAP Form 2.';
            return;
        }

        // Safety check: cannot cancel if used in NAP Form 1
        $checkIds = [$this->editingSeriesId];
        $childIds = DB::table('rdp_record_series')
            ->where('parent_id', $this->editingSeriesId)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();
        if (!empty($childIds)) {
            $checkIds = array_merge($checkIds, $childIds);
        }

        $isUsed = DB::table('rdp_record')
            ->whereIn('record_series_id', $checkIds)
            ->where('is_active', true)
            ->exists();

        if ($isUsed) {
            $this->errorMessage = 'Cannot cancel this record series because it is currently used in NAP Form 1.';
            return;
        }

        try {
            DB::beginTransaction();

            $series = DB::table('rdp_record_series')->where('id', $this->editingSeriesId)->first();
            if (!$series) {
                $this->errorMessage = 'Record series not found.';
                return;
            }

            // Deactivate this record series and any children
            DB::table('rdp_record_series')
                ->whereIn('id', $checkIds)
                ->update([
                    'is_active'   => false,
                    'updated_at'  => Carbon::now(),
                ]);

            // Audit Log
            $adminId = auth()->id() ?? 1;
            DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
                'admin_id'     => $adminId,
                'changes'      => 'Canceled Record Series via NAP Form 2: "' . ($series->series_title ?? '') . '" (ID: ' . $this->editingSeriesId . ')',
                'what_system'  => 2,
                'when_changes' => now(),
            ]);

            DB::commit();

            $this->successMessage = 'Record series canceled successfully.';
            $this->closeEditModal();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to cancel record series: ' . $e->getMessage();
        }
    }

    public function saveEditSeries(): void
    {
        if (!$this->editingSeriesId) return;

        $series = DB::table('rdp_record_series')->where('id', $this->editingSeriesId)->first();
        if (!$series) return;

        $updateData = [
            'series_title' => trim($this->editSeriesTitle),
            'remarks'      => trim($this->editRemarks) ?: null,
        ];

        // ONLY root parent record series (parent_id IS NULL) can have an assigned Item No.
        if (empty($series->parent_id)) {
            $itemNumStr = trim((string)$this->editItemNumber);
            if ($itemNumStr !== '') {
                $updateData['item_number'] = (int)$itemNumStr;
                $updateData['is_verified'] = true;
            } else {
                $updateData['item_number'] = null;
                $updateData['is_verified'] = false;
            }
        } else {
            // Subsections CANNOT have an item number
            $updateData['item_number'] = null;
        }

        DB::table('rdp_record_series')->where('id', $this->editingSeriesId)->update($updateData);

        // Audit Log
        $adminId = auth()->id() ?? 1;
        DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
            'admin_id'    => $adminId,
            'changes'     => 'Updated Record Series via NAP Form 2: "' . $this->editSeriesTitle . '"',
            'what_system' => 2,
            'when_changes'=> now(),
        ]);

        $this->showEditModal = false;
        $this->editingSeriesId = null;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->retentionFilter = '';
        $user = Auth::user();
        $userOffice = $user?->details?->office_code ?? $user?->details?->office?->office_code ?? null;
        $this->officeFilter = $userOffice ?? '';
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    private function resolveEffectiveRetention(array $allSeriesMap, object $record): object
    {
        $current = $record;
        $visited = [];

        while ($current) {
            $hasActive = !empty(trim($current->active_period ?? ''));
            $hasStorage = !empty(trim($current->storage_period ?? ''));
            $hasTotal = !empty(trim($current->total_period ?? ''));
            $isPerm = (bool)($current->is_retention_period_permanent ?? false);

            if ($isPerm || $hasActive || $hasStorage || $hasTotal) {
                return (object)[
                    'active_period'                  => $current->active_period,
                    'storage_period'                 => $current->storage_period,
                    'total_period'                   => $current->total_period,
                    'is_retention_period_permanent' => $isPerm,
                    'inherited'                      => $current->id !== $record->id,
                ];
            }

            if (in_array($current->id, $visited, true)) {
                break;
            }
            $visited[] = $current->id;

            $pId = $current->parent_id ?? null;
            $current = ($pId && isset($allSeriesMap[$pId])) ? $allSeriesMap[$pId] : null;
        }

        return (object)[
            'active_period'                  => null,
            'storage_period'                 => null,
            'total_period'                   => null,
            'is_retention_period_permanent' => false,
            'inherited'                      => false,
        ];
    }

    private function buildTreeHierarchy(array $records): array
    {
        $allParentIds = [];
        foreach ($records as $r) {
            if (!empty($r->parent_id)) {
                $allParentIds[] = (int)$r->parent_id;
            }
        }

        $recordsById = [];
        foreach ($records as $r) {
            $recordsById[(int)$r->id] = $r;
        }

        $missingParentIds = array_diff(array_unique($allParentIds), array_keys($recordsById));
        if (!empty($missingParentIds)) {
            $missingParents = DB::table('rdp_record_series')
                ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
                ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
                ->select([
                    'rdp_record_series.*',
                    'rdp_retention_period.active_period',
                    'rdp_retention_period.storage_period',
                    'rdp_retention_period.total_period',
                    'parent.series_title as parent_title',
                    'office.office_name as recorded_office_name',
                ])
                ->whereIn('rdp_record_series.id', $missingParentIds)
                ->get();

            foreach ($missingParents as $mp) {
                $mp->is_parent_context = true;
                $recordsById[(int)$mp->id] = $mp;
            }
        }

        $allRecords = array_values($recordsById);

        $byParent = [];
        foreach ($allRecords as $r) {
            $pId = (int)($r->parent_id ?? 0);
            $byParent[$pId][] = $r;
        }

        $ordered = [];
        $flatten = function ($parentId, $depth, $rootId) use (&$flatten, &$ordered, $byParent) {
            if (!isset($byParent[$parentId])) {
                return;
            }
            foreach ($byParent[$parentId] as $item) {
                $item->depth = $depth;
                $currentRootId = ($depth === 0) ? (int)$item->id : (int)$rootId;
                $item->root_id = $currentRootId;
                $item->has_children = isset($byParent[$item->id]) && count($byParent[$item->id]) > 0;
                $ordered[] = $item;
                $flatten((int)$item->id, $depth + 1, $currentRootId);
            }
        };

        $flatten(0, 0, null);

        $addedIds = array_column($ordered, 'id');
        foreach ($allRecords as $r) {
            if (!in_array($r->id, $addedIds, true)) {
                $r->depth = 0;
                $r->root_id = (int)$r->id;
                $r->has_children = isset($byParent[$r->id]) && count($byParent[$r->id]) > 0;
                $ordered[] = $r;
            }
        }

        return $ordered;
    }

    public function with(): array
    {
        $user = Auth::user();
        $perms = $user?->permissions;
        $isSadm = (bool)($perms->is_sadm ?? false);
        $userOffice = $user?->details?->office_code ?? $user?->details?->office?->office_code ?? null;

        $query = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
            ->select([
                'rdp_record_series.*',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
                'parent.series_title as parent_title',
                'office.office_name as recorded_office_name',
            ]);

        $query->where('rdp_record_series.is_active', true);

        // Only unverified series (added by users)
        $query->where(function($q) {
            $q->where('rdp_record_series.is_verified', false)
              ->orWhereNull('rdp_record_series.is_verified');
        });

        // Strict office scoping: non-sadm is locked to their office; sadm respects officeFilter
        if (!$isSadm) {
            $query->where('rdp_record_series.recorded_at_office', $userOffice ?: '___NONE___');
        } elseif (!empty($this->officeFilter)) {
            $query->where('rdp_record_series.recorded_at_office', $this->officeFilter);
        }

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('rdp_record_series.series_title', 'ilike', '%' . $this->search . '%')
                  ->orWhere('rdp_record_series.remarks', 'ilike', '%' . $this->search . '%')
                  ->orWhere('parent.series_title', 'ilike', '%' . $this->search . '%')
                  ->orWhere(DB::raw("CAST(rdp_record_series.item_number AS TEXT)"), 'ilike', '%' . $this->search . '%');
            });
        }

        $allFetched = $query->orderByRaw('rdp_record_series.recorded_at_office ASC NULLS LAST, rdp_record_series.item_number ASC NULLS LAST, rdp_record_series.series_title ASC')->get();

        $allFetchedMap = [];
        foreach ($allFetched as $item) {
            $allFetchedMap[$item->id] = $item;
        }

        $treeOrdered = $this->buildTreeHierarchy($allFetched->all());

        foreach ($treeOrdered as $item) {
            if (!isset($allFetchedMap[$item->id])) {
                $allFetchedMap[$item->id] = $item;
            }
        }

        foreach ($treeOrdered as $item) {
            $eff = $this->resolveEffectiveRetention($allFetchedMap, $item);
            $item->effective_active = $eff->active_period;
            $item->effective_storage = $eff->storage_period;
            $item->effective_total = $eff->total_period;
            $item->effective_is_permanent = $eff->is_retention_period_permanent;
            $item->is_inherited = $eff->inherited;

            // All items are regular rows; office grouping is handled by the template
            $item->is_root_parent = empty($item->parent_id);

            // Item number assignment: NEVER assign item number if unregistered!
            $isRegistered = (bool)($item->is_verified ?? false);
            if ($isRegistered && !empty($item->item_number)) {
                $item->display_item_no = (string)$item->item_number;
            } else {
                $item->display_item_no = '';
            }
        }

        // Apply retention filter if selected
        if ($this->retentionFilter === 'permanent') {
            $treeOrdered = array_filter($treeOrdered, function ($item) {
                return (bool)($item->effective_is_permanent) || strtolower(trim($item->effective_total ?? '')) === 'permanent';
            });
        } elseif ($this->retentionFilter === 'temporary') {
            $treeOrdered = array_filter($treeOrdered, function ($item) {
                return !(bool)($item->effective_is_permanent) && strtolower(trim($item->effective_total ?? '')) !== 'permanent';
            });
        }

        // Selected items for preview document
        $printItems = [];
        if (!empty($this->selectedIds)) {
            $selectedInts = array_map('intval', $this->selectedIds);
            foreach ($treeOrdered as $item) {
                if (in_array((int)$item->id, $selectedInts, true)) {
                    $printItems[] = $item;
                }
            }
        } else {
            // When NO items are selected, show blank official template
            $printItems = [];
        }

        $totalCount     = count($treeOrdered);
        $permanentCount = count(array_filter($treeOrdered, fn($i) => $i->effective_is_permanent || strtolower(trim($i->effective_total ?? '')) === 'permanent'));
        $temporaryCount = $totalCount - $permanentCount;

        $officesList = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('is_active', true)->orderBy('office_name')->get();

        return [
            'recordSeriesList' => array_values($treeOrdered),
            'printItems'       => $printItems,
            'officesList'      => $officesList,
            'totalCount'       => $totalCount,
            'permanentCount'   => $permanentCount,
            'temporaryCount'   => $temporaryCount,
            'isSadm'           => $isSadm,
            'userOffice'       => $userOffice,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/console.css'])
@endpush

<div class="nap-page-container" style="padding: 24px; min-height: 100vh; font-family: 'Inter', system-ui, -apple-system, sans-serif;">
    <style>
        .nap-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); margin-bottom: 24px; }
        .nap-table { width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: left; }
        .nap-table th { background: #f1f5f9; padding: 10px 12px; font-weight: 700; color: #334155; border-bottom: 2px solid #cbd5e1; }
        .nap-table td { padding: 11px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; color: #0f172a; }
        .nap-btn { padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; border: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .nap-btn-primary { background: #2563eb; color: #ffffff; }
        .nap-btn-primary:hover { background: #1d4ed8; }
        .nap-btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .nap-btn-secondary:hover { background: #e2e8f0; }

        .nap-page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .nap-page-title { font-size: 24px; font-weight: 800; color: #0f172a; margin: 0; }
        .nap-page-subtitle { font-size: 14px; color: #64748b; margin: 4px 0 0 0; }

        .nap-input { padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; background: #ffffff; color: #0f172a; }
        .nap-search-input { min-width: 280px; }
        .nap-select-input { font-weight: 600; }

        .nap-chevron-btn {
            background: transparent;
            border: 1px solid transparent;
            cursor: pointer;
            padding: 2px 4px;
            border-radius: 4px;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease-in-out;
            line-height: 1;
        }
        .nap-chevron-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        /* Dark Mode Overrides */
        [data-theme="dark"] .nap-page-container {
            background: transparent !important;
        }
        [data-theme="dark"] .nap-page-title {
            color: #f8fafc !important;
        }
        [data-theme="dark"] .nap-page-subtitle {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .nap-card {
            background: #131c2e !important;
            border-color: #1e293b !important;
            color: #cbd5e1 !important;
        }
        [data-theme="dark"] .nap-input {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
        [data-theme="dark"] .nap-input::placeholder {
            color: #64748b !important;
        }
        [data-theme="dark"] .nap-table th {
            background: #0f172a !important;
            color: #cbd5e1 !important;
            border-bottom-color: #1e293b !important;
            border-right-color: #1e293b !important;
        }
        [data-theme="dark"] .nap-table tr:nth-child(2) th {
            background: #0f172a !important;
            color: #cbd5e1 !important;
            border-bottom-color: #1e293b !important;
            border-right-color: #1e293b !important;
        }
        [data-theme="dark"] .nap-table td {
            color: #cbd5e1 !important;
            border-bottom-color: #1e293b !important;
        }
        [data-theme="dark"] .nap-table tr:hover td {
            background-color: #1a253c !important;
        }
        [data-theme="dark"] .table-section-divider-row td {
            background: #1e293b !important;
            color: #60a5fa !important;
            border-color: #334155 !important;
        }
        [data-theme="dark"] .nap-btn-secondary {
            background: #0f172a !important;
            color: #cbd5e1 !important;
            border-color: #334155 !important;
        }
        [data-theme="dark"] .modal-dialog {
            background: #131c2e !important;
            border: 1px solid #1e293b !important;
            color: #cbd5e1 !important;
        }

        /* Modal Overlay & Card Styling */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .modal-content { background: #94a3b8; width: 100%; max-width: 900px; max-height: 94vh; border-radius: 14px; overflow-y: auto; box-shadow: 0 20px 40px rgba(0,0,0,0.3); padding: 24px; display: flex; flex-direction: column; gap: 20px; }
        .modal-dialog { background: #ffffff; width: 100%; max-width: 580px; border-radius: 14px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); padding: 24px; }

        /* Printable Document Styling - NAP Form 2 Official 2008 2-Page Layout */
        .print-sheet { 
            width: 100%;
            max-width: 800px; 
            min-height: 1020px; 
            background: #ffffff; 
            border: none; 
            margin: 0 auto 30px auto; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.15); 
            padding: 40px 36px 36px 36px; 
            box-sizing: border-box; 
            color: #000000; 
            font-family: Arial, Helvetica, sans-serif; 
            font-size: 10px;
            position: relative; 
            display: flex;
            flex-direction: column;
        }
    </style>

    <!-- Header Section -->
    <div class="nap-page-header">
        <div>
            <h1 class="nap-page-title">NAP Form 2: Records Disposition Schedule</h1>
            <p class="nap-page-subtitle">Schedule of custom and unverified record series added by your office.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <button type="button" wire:click="openPrintModal" class="nap-btn nap-btn-secondary" style="background: #ffffff; border: 1px solid #cbd5e1; color: #0f172a; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                Print Preview @if(count($selectedIds) > 0) ({{ count($selectedIds) }}) @endif
            </button>
            <button type="button" wire:click="openClusterModal" class="nap-btn nap-btn-primary" {{ empty($selectedIds) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' }}>
                Create RDS Cluster ({{ count($selectedIds) }})
            </button>
        </div>
    </div>

    <!-- Filters & Table Card -->
    <div class="nap-card" x-data="{
        collapsedRoots: {},
        allRootsCollapsed: false,
        
        toggleRoot(key) {
            this.collapsedRoots[key] = !this.isRootCollapsed(key);
        },
        isRootCollapsed(key) {
            if (this.collapsedRoots[key] !== undefined) {
                return this.collapsedRoots[key];
            }
            return this.allRootsCollapsed;
        },
        collapseAll() {
            this.allRootsCollapsed = true;
            this.collapsedRoots = {};
        },
        expandAll() {
            this.allRootsCollapsed = false;
            this.collapsedRoots = {};
        }
    }">
        <div style="display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 20px;">
            <div style="display: flex; gap: 12px; flex-wrap: wrap; flex: 1; align-items: center;">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search series title, remarks..." class="nap-input nap-search-input">

                <select wire:model.live="retentionFilter" class="nap-input nap-select-input">
                    <option value="">All Retention Types</option>
                    <option value="permanent">Permanent Retention</option>
                    <option value="temporary">Temporary Retention</option>
                </select>

                @if($isSadm)
                    <select wire:model.live="officeFilter" class="nap-input nap-select-input">
                        <option value="">All Offices (Super Admin)</option>
                        @foreach($officesList as $off)
                            <option value="{{ $off->office_code }}">{{ $off->office_name }} ({{ $off->office_code }})</option>
                        @endforeach
                    </select>
                @else
                    <div style="display: flex; align-items: center; gap: 8px; padding: 8px 14px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-weight: 700; color: #1e293b;">
                        <span style="color: #2563eb;">🏢</span>
                        <span>Office: {{ $userOffice ?? 'N/A' }}</span>
                    </div>
                @endif

                @if($search || $retentionFilter || ($isSadm && !empty($officeFilter) && $officeFilter !== $userOffice) || count($selectedIds) > 0)
                    <button type="button" wire:click="clearFilters" class="nap-btn nap-btn-secondary">
                        Reset Filters
                    </button>
                @endif

                <div style="display: inline-flex; gap: 8px; align-items: center; margin-left: 4px;">
                    <button type="button" @click="expandAll()" class="nap-btn nap-btn-secondary" style="padding: 7px 12px; font-size: 12px;" title="Expand all parent series">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                        Expand All
                    </button>
                    <button type="button" @click="collapseAll()" class="nap-btn nap-btn-secondary" style="padding: 7px 12px; font-size: 12px;" title="Collapse all parent series">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transform: rotate(-90deg);">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                        Collapse All
                    </button>
                </div>
            </div>

            @if(count($selectedIds) > 0)
                <div style="font-size: 13px; font-weight: 700; color: #2563eb;">
                    {{ count($selectedIds) }} record series selected
                </div>
            @endif
        </div>

        <div style="overflow-x: auto;">
            <table class="nap-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="width: 56px; text-align: center; vertical-align: middle; padding: 8px 4px;">
                            <div style="display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                <input type="checkbox" wire:model.live="selectAll" style="width: 15px; height: 15px; cursor: pointer; accent-color: #2563eb;" title="Select / Deselect All">
                                <span style="width: 20px; height: 20px; display: inline-block;"></span>
                            </div>
                        </th>
                        <th rowspan="2" style="width: 110px; text-align: center; vertical-align: middle;">5. ITEM NO.</th>
                        <th rowspan="2" style="vertical-align: middle; min-width: 280px;">6. RECORD SERIES TITLE AND DESCRIPTION</th>
                        <th colspan="3" style="text-align: center; border-bottom: 1px solid #cbd5e1; padding: 6px;">7. RETENTION PERIOD</th>
                        <th rowspan="2" style="min-width: 180px; vertical-align: middle;">8. REMARKS</th>
                        <th rowspan="2" style="width: 140px; text-align: right; vertical-align: middle;">ACTION</th>
                    </tr>
                    <tr>
                        <th style="width: 90px; text-align: center; font-size: 12px; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Active</th>
                        <th style="width: 90px; text-align: center; font-size: 12px; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Storage</th>
                        <th style="width: 110px; text-align: center; font-size: 12px; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @php $prevOfficeName = null; @endphp
                    @forelse($recordSeriesList as $idx => $item)
                        @php
                            $isPermSeries = (bool)($item->effective_is_permanent) || 
                                            (strtolower(trim($item->effective_total ?? '')) === 'permanent') ||
                                            (strtolower(trim($item->effective_active ?? '')) === 'permanent' && strtolower(trim($item->effective_storage ?? '')) === 'permanent');
                            $itemIdStr = (string)$item->id;
                            $currentOfficeName = $item->recorded_office_name ?? $item->recorded_at_office ?? 'General / Common Office';
                            $isParentCtx = !empty($item->is_parent_context);
                            $isRoot = ($item->depth ?? 0) === 0;
                        @endphp
                        @if($currentOfficeName !== $prevOfficeName)
                            <tr class="table-section-divider-row">
                                <td colspan="8" style="background: #e2e8f0; color: #1e293b; font-weight: 800; text-align: center; text-transform: uppercase; letter-spacing: 0.6px; padding: 7px 12px; font-size: 11.5px; font-family: 'Inter', sans-serif; border-top: 2px solid #cbd5e1; border-bottom: 2px solid #cbd5e1;">
                                    {{ strtoupper($currentOfficeName) }}
                                </td>
                            </tr>
                            @php $prevOfficeName = $currentOfficeName; @endphp
                        @endif
                        <tr style="{{ $isParentCtx ? 'background: #f8fafc;' : (in_array($itemIdStr, $selectedIds) ? 'background: #eff6ff;' : '') }}"
                            @if(!$isRoot) x-show="!isRootCollapsed('root-{{ $item->root_id }}')" @endif>
                            <td style="text-align: center; padding: 6px 4px; white-space: nowrap;">
                                <div style="display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                    @if(!$isParentCtx)
                                        <input type="checkbox" wire:model.live="selectedIds" value="{{ $item->id }}" style="width: 15px; height: 15px; cursor: pointer; accent-color: #2563eb;" title="Select record series">
                                    @else
                                        <span style="font-size: 11px; color: #94a3b8; width: 15px; display: inline-block; text-align: center;">—</span>
                                    @endif

                                    @if($item->has_children)
                                        <button type="button" 
                                                @click.stop="toggleRoot('root-{{ $item->id }}')" 
                                                class="nap-chevron-btn"
                                                :style="isRootCollapsed('root-{{ $item->id }}') ? 'transform: rotate(-90deg);' : 'transform: rotate(0deg);'"
                                                title="Toggle sub-series group">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="6 9 12 15 18 9"></polyline>
                                            </svg>
                                        </button>
                                    @else
                                        <span style="width: 20px; height: 20px; display: inline-block;"></span>
                                    @endif
                                </div>
                            </td>
                            <td style="text-align: center; font-weight: 800; font-size: 13px; color: #1e293b;">
                                {{ $item->display_item_no ?: '—' }}
                            </td>
                            <td style="padding-left: {{ (($item->depth ?? 0) * 22) + 14 }}px; font-weight: {{ $isRoot ? '700' : '600' }}; color: #0f172a;">
                                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                    @if(($item->depth ?? 0) > 0)
                                        <span style="font-family: monospace; font-weight: 800; color: #2563eb; margin-right: 2px;">└─</span> 
                                    @endif

                                    @if($item->has_children)
                                        <span @click="toggleRoot('root-{{ $item->id }}')" style="cursor: pointer;" title="Click to hide/unhide group">{{ $item->series_title }}</span>
                                    @else
                                        <span>{{ $item->series_title }}</span>
                                    @endif

                                    @php
                                        $tagVal = $item->shorted_type ?? ($item->series_type_tag ?? '');
                                    @endphp
                                    @if(!(bool)($item->is_verified ?? false))
                                        <span style="padding: 2px 7px; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 6px; font-weight: 800; font-size: 11px; display: inline-block;">
                                            [ UNREGISTERED ]
                                        </span>
                                    @elseif($tagVal === 'PH-NAP')
                                        <span style="padding: 2px 7px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; border-radius: 6px; font-weight: 800; font-size: 11px; display: inline-block;">
                                            [ PH-NAP ]
                                        </span>
                                    @elseif($tagVal === 'CSPC')
                                        <span style="padding: 2px 7px; background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; border-radius: 6px; font-weight: 800; font-size: 11px; display: inline-block;">
                                            [ CSPC ]
                                        </span>
                                    @elseif($tagVal)
                                        <span style="padding: 2px 7px; background: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd; border-radius: 6px; font-weight: 800; font-size: 11px; display: inline-block;">
                                            [ {{ strtoupper($tagVal) }} ]
                                        </span>
                                    @endif

                                    @if($isParentCtx)
                                        <span style="font-size: 10px; font-weight: 800; background: #e2e8f0; color: #475569; padding: 2px 6px; border-radius: 4px;">PARENT SERIES</span>
                                    @endif
                                    @if(!empty($item->is_inherited))
                                        <span style="font-size: 11px; color: #64748b; font-weight: 500;">(Inherited)</span>
                                    @endif
                                </div>
                            </td>
                            @if($isPermSeries)
                                <td colspan="3" style="text-align: center;">
                                    <span style="display: inline-block; padding: 4px 14px; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 12px; font-weight: 800; font-size: 12px;">
                                        PERMANENT
                                    </span>
                                </td>
                            @else
                                <td style="text-align: center; font-weight: 600; color: #475569; font-size: 12.5px;">{{ $item->effective_active ?: '' }}</td>
                                <td style="text-align: center; font-weight: 600; color: #475569; font-size: 12.5px;">{{ $item->effective_storage ?: '' }}</td>
                                <td style="text-align: center;">
                                    @if(!empty($item->effective_total))
                                        <span style="display: inline-block; padding: 4px 10px; background: #f8fafc; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 700; font-size: 12px;">
                                            {{ $item->effective_total }}
                                        </span>
                                    @endif
                                </td>
                            @endif
                            <td style="font-size: 12.5px; color: #64748b;">{{ $item->remarks ?: '' }}</td>
                            <td style="text-align: right; white-space: nowrap;">
                                <button type="button" wire:click="openEditModal({{ $item->id }})" class="nap-btn nap-btn-primary" style="padding: 5px 12px; font-size: 12px;">
                                    Edit
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="padding: 32px; text-align: center; color: #64748b;">
                                No record series found matching filter criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- VIEW SERIES DETAIL MODAL -->
    @if($showViewModal && $viewSeriesData)
        <div class="modal-overlay" wire:click.self="closeViewModal">
            <div class="modal-dialog">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;">Record Series Details</h3>
                    <button type="button" wire:click="closeViewModal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <div style="display: flex; flex-direction: column; gap: 14px; font-size: 14px;">
                    <div>
                        <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Series Title</span>
                        <div style="font-size: 16px; font-weight: 800; color: #0f172a; margin-top: 2px;">{{ $viewSeriesData->series_title }}</div>
                    </div>

                    @if(!empty($viewSeriesData->parent_title))
                        <div>
                            <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Parent Series Title</span>
                            <div style="font-weight: 700; color: #2563eb; margin-top: 2px;">{{ $viewSeriesData->parent_title }}</div>
                        </div>
                    @endif

                    <div>
                        <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Item Number</span>
                        <div style="font-weight: 700; color: #0f172a; margin-top: 2px;">
                            @if(!empty($viewSeriesData->is_root_parent))
                                @if(!empty($viewSeriesData->item_number))
                                    {{ $viewSeriesData->item_number }} <span style="font-size: 11.5px; color: #16a34a;">(Root Parent Series)</span>
                                @else
                                    <span style="color: #2563eb;">Auto-indexed Root Parent Series</span>
                                @endif
                            @else
                                <span style="color: #64748b; font-style: italic;">None (Subsections/Children do not have item numbers)</span>
                            @endif
                        </div>
                    </div>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px;">
                        <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 8px;">Retention Period Schedule</span>
                        @if($viewSeriesData->effective_is_permanent || strtolower(trim($viewSeriesData->effective_total ?? '')) === 'permanent')
                            <div style="display: inline-block; padding: 4px 12px; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 8px; font-weight: 800; font-size: 13px;">
                                PERMANENT RETENTION
                            </div>
                        @else
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; text-align: center;">
                                <div style="background: #fff; padding: 8px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                    <div style="font-size: 11px; color: #64748b;">Active</div>
                                    <div style="font-weight: 700; color: #0f172a;">{{ $viewSeriesData->effective_active ?: '' }}</div>
                                </div>
                                <div style="background: #fff; padding: 8px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                    <div style="font-size: 11px; color: #64748b;">Storage</div>
                                    <div style="font-weight: 700; color: #0f172a;">{{ $viewSeriesData->effective_storage ?: '' }}</div>
                                </div>
                                <div style="background: #fff; padding: 8px; border-radius: 6px; border: 1px solid #bfdbfe; background: #eff6ff;">
                                    <div style="font-size: 11px; color: #1e40af;">Total</div>
                                    <div style="font-weight: 800; color: #1e40af;">{{ $viewSeriesData->effective_total ?: '' }}</div>
                                </div>
                            </div>
                        @endif
                        @if(!empty($viewSeriesData->is_inherited))
                            <div style="font-size: 11.5px; color: #64748b; margin-top: 8px;">ℹ️ Inherited from parent series hierarchy.</div>
                        @endif
                    </div>

                    <div>
                        <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Remarks & Provisions</span>
                        <div style="color: #334155; margin-top: 2px; line-height: 1.5;">{{ $viewSeriesData->remarks ?: 'No remarks provided.' }}</div>
                    </div>
                </div>

                <div style="margin-top: 20px; text-align: right;">
                    <button type="button" wire:click="closeViewModal" class="nap-btn nap-btn-secondary">Close</button>
                </div>
            </div>
        </div>
    @endif

    <!-- EDIT SERIES MODAL -->
    @if($showEditModal)
        <div class="modal-overlay" wire:click.self="closeEditModal">
            <div class="modal-dialog">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;">Edit Record Series</h3>
                    <button type="button" wire:click="closeEditModal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <form wire:submit.prevent="saveEditSeries" style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Series Title</label>
                        <input type="text" wire:model="editSeriesTitle" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;" required>
                    </div>

                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">
                            Item Number
                            @if(!$isRootParentForEdit)
                                <span style="font-size: 11.5px; font-weight: 500; color: #ea580c; margin-left: 6px;">(Restricted to Root Parent Series Only)</span>
                            @endif
                        </label>
                        @if($isRootParentForEdit)
                            <input type="number" wire:model="editItemNumber" placeholder="e.g. 1, 2, 15" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;">
                            <span style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">Item numbers are assigned exclusively to top-level root series.</span>
                        @else
                            <input type="text" value="— (Subsections/Children cannot have Item No.)" disabled style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; background: #f1f5f9; color: #64748b; cursor: not-allowed; box-sizing: border-box;">
                        @endif
                    </div>

                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Remarks</label>
                        <textarea wire:model="editRemarks" rows="3" placeholder="Additional disposition notes, remarks..." style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;"></textarea>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 14px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
                        @if($isSeriesUsedInNap1)
                            <div style="display: inline-flex; align-items: center; gap: 8px;">
                                <button type="button" disabled class="nap-btn" style="background: #f1f5f9; color: #94a3b8; border: 1px solid #cbd5e1; cursor: not-allowed; opacity: 0.7;" title="Cannot be canceled: this record series is currently used in NAP Form 1">
                                    Cancel Series
                                </button>
                                <span style="font-size: 11.5px; color: #dc2626; font-weight: 600;">(Cannot cancel: currently used in NAP Form 1)</span>
                            </div>
                        @else
                            <button type="button" wire:click="cancelRecordSeries" wire:confirm="Are you sure you want to cancel this record series? This will remove it from NAP Form 2." class="nap-btn" style="background: #fee2e2; color: #dc2626; border: 1px solid #fecaca;">
                                Cancel Series
                            </button>
                        @endif

                        <div style="display: flex; gap: 10px;">
                            <button type="button" wire:click="closeEditModal" class="nap-btn nap-btn-secondary">Close</button>
                            <button type="submit" class="nap-btn nap-btn-primary">Save Changes</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- CREATE CLUSTER MODAL OVERLAY -->
    @if($showClusterModal)
        <div class="modal-overlay" wire:click.self="closeClusterModal">
            <div class="modal-dialog" style="max-width: 550px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;">Create RDS Schedule Cluster</h3>
                    <button type="button" wire:click="closeClusterModal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #1e40af; font-weight: 600;">
                        📦 Packaging <strong>{{ count($selectedIds) }}</strong> selected custom record series into an RDS schedule submission cluster.
                    </div>

                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Cluster Title / Name</label>
                        <input type="text" class="form-control" wire:model="clusterName" placeholder="e.g. RDS Schedule Batch 2026-Q3" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px;">
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                        <button type="button" wire:click="closeClusterModal" class="nap-btn nap-btn-secondary">Cancel</button>
                        <button type="button" wire:click="submitClusterCreation" class="nap-btn nap-btn-primary">Confirm & Create Cluster</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- PRINT PREVIEW MODAL (OFFICIAL NAP FORM 2: 2008 PDF - PREVIEW ONLY, NO PRINT BUTTON) -->
    @if($showPrintModal)
        @php
            $hasSelection = !empty($selectedIds);
            $totalPrintItems = count($printItems);
            $rowsPerPage = 16;

            if ($totalPrintItems === 0) {
                // Blank template mode: 1 blank data page + 1 signatures page = 2 pages total
                $dataPages = [ [] ];
            } else {
                $dataPages = array_chunk($printItems, $rowsPerPage);
            }
            $totalPages = count($dataPages) + 1; // Last page is dedicated Signatures & NAP Approval Sheet
        @endphp
        <div class="modal-overlay" wire:click.self="closePrintModal">
            <div class="modal-content">
                <!-- Modal Toolbar (Preview Only — No Printing Here) -->
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="color: #ffffff; font-size: 16px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                            <span>Print Preview: NAP Form 2 (Records Disposition Schedule)</span>
                            <span style="font-size: 11px; background: rgba(255,255,255,0.2); color: #f1f5f9; padding: 2px 8px; border-radius: 6px; font-weight: 600;">Preview Mode</span>
                        </div>
                        <div style="color: #cbd5e1; font-size: 12px; margin-top: 2px;">
                            @if($hasSelection)
                                Showing schedule document preview ({{ count($selectedIds) }} records selected). Official printing is available once clustered in Pending / List.
                            @else
                                Official NAP Form 2 Blank Template Preview. Official printing is available once clustered in Pending / List.
                            @endif
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button type="button" wire:click="closePrintModal" class="nap-btn nap-btn-secondary" style="background: #ffffff; color: #0f172a; font-weight: 700;">
                            ✕ Close Preview
                        </button>
                    </div>
                </div>


                <!-- DATA PAGES (Page 1 .. N) -->
                @foreach($dataPages as $pageIndex => $pageItems)
                    @php
                        $pageNumber = $pageIndex + 1;
                        $fillerHeight = empty($pageItems) ? 650 : max(40, 650 - (count($pageItems) * 26));
                    @endphp
                    <div class="print-sheet">
                        <!-- Top Form Identifier -->
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px; font-family: Arial, sans-serif;">
                            <div style="font-size: 10px; font-weight: normal; line-height: 1.25;">
                                NAP Form 2<br>2008
                            </div>
                        </div>

                        <!-- Header Box (Outer Border) -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-family: Arial, sans-serif; margin-bottom: 0;">
                            <tr>
                                <td style="width: 50%; border-right: 2px solid #000; padding: 10px 12px; text-align: center; vertical-align: middle;">
                                    <div style="font-size: 11px; font-weight: bold; letter-spacing: 0.3px;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                                    <div style="font-size: 9.5px; font-style: italic; margin-top: 2px; margin-bottom: 8px;">Pambansang Sinupan ng Pilipinas</div>
                                    <div style="font-size: 12px; font-weight: bold; letter-spacing: 0.5px;">RECORDS DISPOSITION SCHEDULE</div>
                                </td>
                                <td style="width: 50%; padding: 0; vertical-align: top;">
                                    <div style="padding: 7px 10px; border-bottom: 1px solid #000; font-size: 9px; line-height: 1.4;">
                                        <strong>1. AGENCY NAME:</strong>
                                        <div style="font-size: 10px; font-weight: bold; margin-top: 2px;">{{ $agencyName }}</div>
                                    </div>
                                    <div style="padding: 7px 10px; font-size: 9px; line-height: 1.4;">
                                        <strong>2. ADDRESS:</strong>
                                        <div style="font-size: 10px; font-weight: bold; margin-top: 2px;">{{ $agencyAddress }}</div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="width: 50%; border-right: 2px solid #000; border-top: 2px solid #000; padding: 6px 10px; font-size: 9px;">
                                    <strong>3. SCHEDULE NO.:</strong> <span style="font-weight: bold; font-size: 10px; margin-left: 4px;">{{ $scheduleNo }}</span>
                                </td>
                                <td style="width: 50%; border-top: 2px solid #000; padding: 6px 10px; font-size: 9px;">
                                    <strong>4. DATE PREPARED:</strong> <span style="font-weight: bold; font-size: 10px; margin-left: 4px;">{{ $datePrepared }}</span>
                                </td>
                            </tr>
                        </table>

                        <!-- Data Table (Boxes 5 - 8) -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; border-top: none; font-family: Arial, sans-serif; font-size: 9px; table-layout: fixed; flex-grow: 1;">
                            <thead>
                                <tr style="background: #ffffff;">
                                    <th rowspan="2" style="width: 11%; border: 1px solid #000; border-top: 2px solid #000; padding: 6px 4px; text-align: center; font-weight: bold; vertical-align: middle;">
                                        5. ITEM NO.:
                                    </th>
                                    <th rowspan="2" style="width: 47%; border: 1px solid #000; border-top: 2px solid #000; padding: 6px 6px; text-align: center; font-weight: bold; vertical-align: middle;">
                                        6. RECORD SERIES TITLE AND DESCRIPTION
                                    </th>
                                    <th colspan="3" style="width: 24%; border: 1px solid #000; border-top: 2px solid #000; padding: 4px; text-align: center; font-weight: bold;">
                                        7. RETENTION PERIOD
                                    </th>
                                    <th rowspan="2" style="width: 18%; border: 1px solid #000; border-top: 2px solid #000; padding: 6px 6px; text-align: center; font-weight: bold; vertical-align: middle;">
                                        8. REMARKS
                                    </th>
                                </tr>
                                <tr style="background: #ffffff;">
                                    <th style="width: 8%; border: 1px solid #000; padding: 4px; text-align: center; font-weight: bold;">Active</th>
                                    <th style="width: 8%; border: 1px solid #000; padding: 4px; text-align: center; font-weight: bold;">Storage</th>
                                    <th style="width: 8%; border: 1px solid #000; padding: 4px; text-align: center; font-weight: bold;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($pageItems as $item)
                                    @php
                                        $isPermSeries = (bool)($item->effective_is_permanent) || 
                                                        (strtolower(trim($item->effective_total ?? '')) === 'permanent') ||
                                                        (strtolower(trim($item->effective_active ?? '')) === 'permanent' && strtolower(trim($item->effective_storage ?? '')) === 'permanent');
                                    @endphp
                                    <tr>
                                        <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 4px; text-align: center; vertical-align: top; font-weight: bold;">
                                            {{ $item->display_item_no }}
                                        </td>
                                        <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 6px; vertical-align: top; padding-left: {{ (($item->depth ?? 0) * 14) + 6 }}px;">
                                            <span style="{{ ($item->depth ?? 0) === 0 ? 'font-weight: bold;' : 'font-weight: 500;' }}">
                                                {{ $item->series_title }}
                                            </span>
                                        </td>
                                        @if($isPermSeries)
                                            <td colspan="3" style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 4px; text-align: center; vertical-align: top; font-weight: bold;">
                                                PERMANENT
                                            </td>
                                        @else
                                            <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 4px; text-align: center; vertical-align: top;">
                                                {{ $item->effective_active }}
                                            </td>
                                            <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 4px; text-align: center; vertical-align: top;">
                                                {{ $item->effective_storage }}
                                            </td>
                                            <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 4px; text-align: center; vertical-align: top; font-weight: bold;">
                                                {{ $item->effective_total }}
                                            </td>
                                        @endif
                                        <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 6px; vertical-align: top; font-size: 8.5px;">
                                            {{ $item->remarks }}
                                        </td>
                                    </tr>
                                @endforeach

                                <!-- Filler row to extend column borders to bottom -->
                                <tr>
                                    <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; height: {{ $fillerHeight }}px;"></td>
                                    <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;"></td>
                                    <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;"></td>
                                    <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;"></td>
                                    <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;"></td>
                                    <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;"></td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Bottom Statutory Notice -->
                        <div style="border-top: 2px solid #000; padding-top: 6px; margin-top: 0; font-family: Arial, sans-serif; font-size: 8px; line-height: 1.35; text-align: justify;">
                            <strong>IMPORTANT:</strong> Pursuant to Section 18, Article III, RA 9470 s. 2007, "No government department, bureau, agency and instrumentality shall dispose of, destroy or authorize the disposal or destruction of any public records, which are in the custody or under its control except with the prior written authority of the executive director."
                        </div>

                        <!-- Bottom Page Number -->
                        <div style="text-align: right; font-size: 9px; margin-top: 8px; font-family: Arial, sans-serif;">
                            Page {{ $pageNumber }} of {{ $totalPages }} Pages
                        </div>
                    </div>
                @endforeach

                <!-- SIGNATURES & NAP APPROVAL PAGE (FINAL PAGE) -->
                <div class="print-sheet">
                    <!-- Top Form Identifier -->
                    <div style="font-size: 10px; font-weight: normal; line-height: 1.25; margin-bottom: 8px; font-family: Arial, sans-serif;">
                        NAP Form 2<br>2008
                    </div>

                    <!-- Signatures Table (Box 9, 11, 10, 12) -->
                    <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-family: Arial, sans-serif; font-size: 9px;">
                        <tr>
                            <!-- 9. Prepared by -->
                            <td style="width: 50%; border: 1px solid #000; padding: 12px 16px; vertical-align: top; height: 160px; box-sizing: border-box;">
                                <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 25px;">9. Prepared by:</div>
                                <div style="display: flex; flex-direction: column; align-items: center; width: 85%; margin: 0 auto;">
                                    <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                        {{ $preparedBy }}
                                    </div>
                                    <div style="font-size: 8.5px; color: #000; margin-top: 2px; margin-bottom: 15px;">Name</div>

                                    <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                        {{ $preparedPosition }}
                                    </div>
                                    <div style="font-size: 8.5px; color: #000; margin-top: 2px;">Position</div>
                                </div>
                            </td>

                            <!-- 11. Recommending Approval -->
                            <td style="width: 50%; border: 1px solid #000; padding: 12px 16px; vertical-align: top; height: 160px; box-sizing: border-box;">
                                <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 25px;">11. Recommending Approval:</div>
                                <div style="display: flex; flex-direction: column; align-items: center; width: 85%; margin: 0 auto;">
                                    <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                        {{ $recommendingBy }}
                                    </div>
                                    <div style="font-size: 8.5px; color: #000; margin-top: 2px; margin-bottom: 15px;">Name</div>

                                    <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                        {{ $recommendingPosition }}
                                    </div>
                                    <div style="font-size: 8.5px; color: #000; margin-top: 2px;">Position</div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <!-- 10. Assisted by -->
                            <td style="width: 50%; border: 1px solid #000; padding: 12px 16px; vertical-align: top; height: 160px; box-sizing: border-box;">
                                <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 25px;">10. Assisted by:</div>
                                <div style="display: flex; flex-direction: column; align-items: center; width: 85%; margin: 0 auto;">
                                    <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                        {{ $assistedBy }}
                                    </div>
                                    <div style="font-size: 8.5px; color: #000; margin-top: 2px; margin-bottom: 15px;">Name</div>

                                    <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                        {{ $assistedPosition }}
                                    </div>
                                    <div style="font-size: 8.5px; color: #000; margin-top: 2px;">Position</div>
                                </div>
                            </td>

                            <!-- 12. Approved -->
                            <td style="width: 50%; border: 1px solid #000; padding: 12px 16px; vertical-align: top; height: 160px; box-sizing: border-box;">
                                <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 25px;">12. Approved</div>
                                <div style="display: flex; flex-direction: column; align-items: center; width: 85%; margin: 0 auto;">
                                    <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                        {{ $approvedBy }}
                                    </div>
                                    <div style="font-size: 8.5px; color: #000; margin-top: 2px; margin-bottom: 15px;">Name</div>

                                    <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                        {{ $approvedPosition }}
                                    </div>
                                    <div style="font-size: 8.5px; color: #000; margin-top: 2px;">Position</div>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <!-- NAP Accomplishment Section -->
                    <div style="border: 2px solid #000; margin-top: 14px; font-family: Arial, sans-serif; font-size: 9.5px; flex-grow: 1; display: flex; flex-direction: column;">
                        <div style="border-bottom: 2px solid #000; padding: 6px; text-align: center; font-weight: bold; font-size: 10px; letter-spacing: 0.5px; text-transform: uppercase;">
                            TO BE ACCOMPLISHED BY THE NATIONAL ARCHIVES OF THE PHILIPPINES
                        </div>
                        <div style="padding: 16px 20px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between;">
                            <div>
                                <div style="margin-bottom: 14px; font-size: 9.5px;">This Records Disposition Schedule</div>
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding-left: 20px;">
                                    <span style="display: inline-block; width: 14px; height: 14px; border: 1.5px solid #000;"></span>
                                    <span>is being returned for improvement / correction</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px; padding-left: 20px;">
                                    <span style="display: inline-block; width: 14px; height: 14px; border: 1.5px solid #000;"></span>
                                    <span>is being recommended for approval</span>
                                </div>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 50px; padding: 0 20px 20px 20px;">
                                <!-- Chairman block -->
                                <div style="text-align: center; width: 42%;">
                                    <div style="border-bottom: 1px solid #000; width: 100%; min-height: 20px; margin-bottom: 4px; font-weight: bold; font-size: 10px;">
                                        {{ $committeeChairmanName }}
                                    </div>
                                    <div style="font-weight: bold; font-size: 9.5px;">Chairman</div>
                                    <div style="font-size: 8.5px; margin-top: 1px;">Records Management Evaluation Committee</div>
                                    <div style="margin-top: 16px; text-align: left; font-size: 9px;">
                                        Date: <span style="display: inline-block; border-bottom: 1px solid #000; width: 130px;"></span>
                                    </div>
                                </div>

                                <!-- Executive Director block -->
                                <div style="text-align: center; width: 42%;">
                                    <div style="font-weight: bold; font-size: 10px; text-align: left; margin-bottom: 30px;">APPROVED:</div>
                                    <div style="border-bottom: 1px solid #000; width: 100%; min-height: 20px; margin-bottom: 4px; font-weight: bold; font-size: 10px;">
                                        {{ $executiveDirectorName }}
                                    </div>
                                    <div style="font-weight: bold; font-size: 9.5px;">Executive Director</div>
                                    <div style="margin-top: 16px; text-align: left; font-size: 9px;">
                                        Date: <span style="display: inline-block; border-bottom: 1px solid #000; width: 130px;"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bottom Page Number -->
                    <div style="text-align: right; font-size: 9px; margin-top: 12px; font-family: Arial, sans-serif;">
                        Page {{ $totalPages }} of {{ $totalPages }} Pages
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>