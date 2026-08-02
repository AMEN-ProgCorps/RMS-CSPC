<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - NAP Form 1')] class extends Component {
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
            $this->errorMessage = 'Please select at least one record to create a cluster.';
            return;
        }

        $userOffice = Auth::user()?->details?->office_code ?? 'OFFICE';
        $this->clusterName = 'Inventory Cluster — ' . $userOffice . ' (' . Carbon::now()->format('Y-m-d') . ')';
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
            $this->errorMessage = 'Please select at least one record to create a cluster.';
            return;
        }

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOffice = $user?->details?->office_code ?? null;

            $mainPendingId = DB::table('main_pending_id')->insertGetId([
                'status'     => 'UNUSED',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('rdp_pending_record_series')->insert([
                'cluster_id'   => $mainPendingId,
                'cluster_name' => trim($this->clusterName) ?: ('Inventory Batch — ' . now()->format('Y-m-d')),
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

            $this->successMessage = 'Inventory cluster created successfully! It is now available under Pending / List for printing.';
            $this->selectedIds = [];
            $this->selectAll = false;
            $this->closeClusterModal();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to create cluster: ' . $e->getMessage();
        }
    }

    // View Modal Properties
    public bool $showViewModal = false;
    public ?object $viewSeriesData = null;

    // Edit Modal Properties
    public bool $showEditModal = false;
    public ?int $editingSeriesId = null;
    public string $editSeriesTitle = '';
    public string $editDescription = '';
    public ?string $editItemNumber = '';
    public string $editPeriodCovered = '';
    public string $editVolume = '';
    public string $editLocation = '';
    public string $editFreqUse = '';
    public string $editDuplication = '';
    public string $editTimeValue = 'T';
    public string $editUtilityValue = 'Adm';
    public string $editRemarks = '';
    public bool $isRootParentForEdit = false;

    // Printable Custom Header & Signature Fields
    public string $agencyName = 'Camarines Sur Polytechnic Colleges';
    public string $agencyAddress = 'San Miguel, Nabua, Camarines Sur';
    public string $orgUnit = 'Records and Freedom of Info Unit';
    public string $personInCharge = 'Gennica Aprille S. Penetrante';
    public string $telephoneNumber = '(054) 288-1534 to loc. 113';
    public string $datePrepared = '';

    // Signature Block Fields
    public string $preparedBy = 'Gennica Aprille S. Penetrante';
    public string $preparedPosition = 'Administrative Officer V / Records Officer';
    public string $assistedBy = '';
    public string $assistedPosition = 'NAP Records Management Analyst';
    public string $approvedBy = 'Dr. Luningning Q. Bregala';
    public string $approvedPosition = 'Chief of Division / Department Head';

    public function mount(): void
    {
        $user = Auth::user();
        $perms = $user?->permissions;

        // Access clearance check
        if (!$perms || (!(bool)($perms->is_sadm ?? false) && !(bool)($perms->can_rdp_access_form_1 ?? true))) {
            redirect()->route('rdp')->send();
            return;
        }

        $details = $user?->details;
        
        $this->datePrepared = Carbon::now()->format('F d, Y');
        
        if ($details) {
            $fullName = trim(($details->first_name ?? '') . ' ' . ($details->last_name ?? ''));
            if ($fullName) {
                $this->preparedBy = $fullName;
                $this->personInCharge = $fullName;
            }
            if (!empty($details->designation)) {
                $this->preparedPosition = $details->designation;
            }
        }
    }

    public function updatedSelectAll($value): void
    {
        if ($value) {
            $allIds = DB::table('rdp_record_series')->pluck('id')->toArray();
            $this->selectedIds = array_map('strval', $allIds);
        } else {
            $this->selectedIds = [];
        }
    }

    public function openPrintModal(array $specificIds = []): void
    {
        $perms = Auth::user()?->permissions;
        if (!($perms->is_sadm ?? false) && !(bool)($perms->can_rdp_print_form_1 ?? true)) {
            $this->errorMessage = 'You do not have clearance to print NAP Form 1.';
            return;
        }
        if (!empty($specificIds)) {
            $this->selectedIds = array_map('strval', $specificIds);
        }
        $this->showPrintModal = true;
    }

    public function closePrintModal(): void
    {
        $this->showPrintModal = false;
    }

    public function openViewModal(int $id): void
    {
        $query = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
            ->leftJoin('rdp_record', 'rdp_record_series.id', '=', 'rdp_record.record_series_id')
            ->leftJoin('rdp_recorded_value', 'rdp_record.records_medium', '=', 'rdp_recorded_value.id')
            ->leftJoin('rdp_utility_medium', 'rdp_record.utility_value', '=', 'rdp_utility_medium.id')
            ->leftJoin('rdp_period_covered', 'rdp_record.id', '=', 'rdp_period_covered.period_owner')
            ->select([
                'rdp_record_series.*',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
                'parent.series_title as parent_title',
                'rdp_record.description as rec_description',
                'rdp_record.volume as rec_volume',
                'rdp_record.records_location as rec_location',
                'rdp_record.frequence_use as rec_freq',
                'rdp_record.time_value as rec_time_value',
                'rdp_recorded_value.medium_name as rec_medium',
                'rdp_utility_medium.utility_name as rec_utility',
                'rdp_period_covered.start_at as rec_start_at',
                'rdp_period_covered.ends_at as rec_ends_at',
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
        if (!$isSadm && !(bool)($perms->can_rdp_modify_form_1 ?? true)) {
            $this->errorMessage = 'You do not have clearance to edit records on NAP Form 1.';
            return;
        }

        $record = DB::table('rdp_record_series')
            ->leftJoin('rdp_record', 'rdp_record_series.id', '=', 'rdp_record.record_series_id')
            ->leftJoin('rdp_recorded_value', 'rdp_record.records_medium', '=', 'rdp_recorded_value.id')
            ->leftJoin('rdp_utility_medium', 'rdp_record.utility_value', '=', 'rdp_utility_medium.id')
            ->leftJoin('rdp_period_covered', 'rdp_record.id', '=', 'rdp_period_covered.period_owner')
            ->select([
                'rdp_record_series.*',
                'rdp_record.id as rdp_rec_id',
                'rdp_record.description as rec_description',
                'rdp_record.volume as rec_volume',
                'rdp_record.records_location as rec_location',
                'rdp_record.frequence_use as rec_freq',
                'rdp_record.time_value as rec_time_value',
                'rdp_recorded_value.medium_name as rec_medium',
                'rdp_utility_medium.utility_name as rec_utility',
                'rdp_period_covered.start_at as rec_start_at',
                'rdp_period_covered.ends_at as rec_ends_at',
            ])
            ->where('rdp_record_series.id', $id)
            ->first();

        if ($record) {
            // Edit-others clearance: block if record belongs to another office and user lacks clearance
            $userOffice = Auth::user()?->details?->office_code ?? null;
            $isOtherOffice = $userOffice && $record->recorded_at_office && $record->recorded_at_office !== $userOffice;
            if (!$isSadm && $isOtherOffice && !(bool)($perms->can_rdp_edit_others_form_1 ?? false)) {
                $this->errorMessage = 'You do not have clearance to edit records from another office on NAP Form 1.';
                return;
            }

            $this->editingSeriesId = $record->id;
            $this->editSeriesTitle = $record->series_title ?? '';
            $this->editDescription = $record->rec_description ?? '';
            $this->editItemNumber = $record->item_number !== null ? (string)$record->item_number : '';
            $this->editRemarks = $record->remarks ?? '';
            $this->isRootParentForEdit = empty($record->parent_id);

            $this->editVolume = $record->rec_volume ?? '0.5 cu. m.';
            $this->editLocation = $record->rec_location ?? 'Records Office';
            $this->editFreqUse = $record->rec_freq ?? 'Monthly';
            $this->editDuplication = $record->rec_medium ?? 'Hardcopy';
            $this->editTimeValue = $record->rec_time_value ?? ($record->is_retention_period_permanent ? 'P' : 'T');
            $this->editUtilityValue = $record->rec_utility ?? ($record->is_retention_period_permanent ? 'Arc' : 'Adm');

            if ($record->rec_start_at && $record->rec_ends_at) {
                $this->editPeriodCovered = Carbon::parse($record->rec_start_at)->format('Y') . ' - ' . Carbon::parse($record->rec_ends_at)->format('Y');
            } else {
                $this->editPeriodCovered = '2020 - Present';
            }

            $this->showEditModal = true;
        }
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editingSeriesId = null;
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

        // Update or insert rdp_record detail values for Form 1
        $rdpRec = DB::table('rdp_record')->where('record_series_id', $this->editingSeriesId)->first();
        $rdpRecData = [
            'description'      => trim($this->editDescription) ?: null,
            'volume'           => trim($this->editVolume),
            'records_location' => trim($this->editLocation),
            'frequence_use'    => trim($this->editFreqUse),
            'time_value'       => trim($this->editTimeValue) ?: 'T',
            'updated_at'       => now(),
        ];

        if ($rdpRec) {
            DB::table('rdp_record')->where('id', $rdpRec->id)->update($rdpRecData);
        } else {
            $rdpRecData['record_series_id'] = $this->editingSeriesId;
            $rdpRecData['created_at'] = now();
            DB::table('rdp_record')->insert($rdpRecData);
        }

        // Audit Log
        $adminId = auth()->id() ?? 1;
        DB::table('admin_logs')->insert([
            'admin_id'    => $adminId,
            'changes'     => 'Updated Record Series & Inventory Appraisal via NAP Form 1: "' . $this->editSeriesTitle . '"',
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
        $byParent = [];
        foreach ($records as $r) {
            $pId = $r->parent_id ?? 0;
            $byParent[$pId][] = $r;
        }

        $ordered = [];
        $flatten = function ($parentId, $depth) use (&$flatten, &$ordered, $byParent) {
            if (!isset($byParent[$parentId])) {
                return;
            }
            foreach ($byParent[$parentId] as $item) {
                $item->depth = $depth;
                $ordered[] = $item;
                $flatten($item->id, $depth + 1);
            }
        };

        $flatten(0, 0);

        $addedIds = array_column($ordered, 'id');
        foreach ($records as $r) {
            if (!in_array($r->id, $addedIds, true)) {
                $r->depth = 0;
                $ordered[] = $r;
            }
        }

        return $ordered;
    }

    public function with(): array
    {
        $query = DB::table('rdp_record')
            ->join('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series_type', 'rdp_record_series.series_type', '=', 'rdp_record_series_type.id')
            ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
            ->leftJoin('rdp_recorded_value', 'rdp_record.records_medium', '=', 'rdp_recorded_value.id')
            ->leftJoin('rdp_utility_medium', 'rdp_record.utility_value', '=', 'rdp_utility_medium.id')
            ->leftJoin('rdp_period_covered', 'rdp_record.id', '=', 'rdp_period_covered.period_owner')
            ->leftJoin('office', 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
            ->select([
                'rdp_record_series.*',
                'rdp_record_series_type.shorted_type',
                'rdp_record.id as record_id',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
                'parent.series_title as parent_title',
                'rdp_record.description as rec_description',
                'rdp_record.volume as rec_volume',
                'rdp_record.records_location as rec_location',
                'rdp_record.frequence_use as rec_freq',
                'rdp_record.time_value as rec_time_value',
                'rdp_recorded_value.medium_name as rec_medium',
                'rdp_utility_medium.utility_name as rec_utility',
                'rdp_period_covered.start_at as rec_start_at',
                'rdp_period_covered.ends_at as rec_ends_at',
                'office.office_name as recorded_office_name',
            ]);

        if (!empty($this->officeFilter)) {
            $query->where('rdp_record_series.recorded_at_office', $this->officeFilter);
        }

        // View-others clearance: restrict to own office if user lacks cross-office view permission (default false)
        $authPerms = auth()->user()?->permissions;
        $isSadm = (bool)($authPerms?->is_sadm ?? false);
        if (!$isSadm && !(bool)($authPerms?->can_rdp_view_others_form_1 ?? false)) {
            $userOffice = auth()->user()?->details?->office_code ?? null;
            if ($userOffice) {
                $query->where('rdp_record_series.recorded_at_office', $userOffice);
            }
        }

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('rdp_record_series.series_title', 'ilike', '%' . $this->search . '%')
                  ->orWhere('rdp_record_series.remarks', 'ilike', '%' . $this->search . '%')
                  ->orWhere('rdp_record.description', 'ilike', '%' . $this->search . '%')
                  ->orWhere('parent.series_title', 'ilike', '%' . $this->search . '%')
                  ->orWhereCast('rdp_record_series.item_number', 'text', 'like', '%' . $this->search . '%');
            });
        }

        $allFetched = $query->orderByRaw('rdp_record_series.recorded_at_office ASC NULLS LAST, rdp_record_series.item_number ASC NULLS LAST, rdp_record_series.series_title ASC')->get();

        $allRecords = $allFetched->all();
        $existingIds = array_column($allRecords, 'id');
        $parentIds = array_filter(array_unique(array_column($allRecords, 'parent_id')));
        $missingParentIds = array_diff($parentIds, $existingIds);

        if (!empty($missingParentIds)) {
            $parents = DB::table('rdp_record_series')
                ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
                ->leftJoin('rdp_record_series_type', 'rdp_record_series.series_type', '=', 'rdp_record_series_type.id')
                ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
                ->leftJoin('office', 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
                ->select([
                    'rdp_record_series.*',
                    'rdp_record_series_type.shorted_type',
                    'rdp_retention_period.active_period',
                    'rdp_retention_period.storage_period',
                    'rdp_retention_period.total_period',
                    'parent.series_title as parent_title',
                    'office.office_name as recorded_office_name',
                ])
                ->whereIn('rdp_record_series.id', $missingParentIds)
                ->get();

            foreach ($parents as $mp) {
                $mp->is_parent_context = true;
                $allRecords[] = $mp;
            }
        }

        $allFetchedMap = [];
        foreach ($allRecords as $item) {
            $allFetchedMap[$item->id] = $item;
        }

        $treeOrdered = $this->buildTreeHierarchy($allRecords);

        $childCounter = 1;
        foreach ($treeOrdered as $item) {
            $eff = $this->resolveEffectiveRetention($allFetchedMap, $item);
            $item->effective_active = $eff->active_period;
            $item->effective_storage = $eff->storage_period;
            $item->effective_total = $eff->total_period;
            $item->effective_is_permanent = $eff->is_retention_period_permanent;
            $item->is_inherited = $eff->inherited;

            // Type badge determination
            if (!(bool)($item->is_verified ?? false)) {
                $item->series_type_tag = 'UNREGISTERED';
            } else {
                $item->series_type_tag = !empty($item->shorted_type) ? $item->shorted_type : 'PH-NAP';
            }

            // Item Numbers: UNREGISTERED series (is_verified = false) have NO item number until approved on Form 2
            $item->is_root_parent = false;
            if (!(bool)($item->is_verified ?? false)) {
                $item->display_item_no = '—';
            } elseif (!empty($item->item_number)) {
                $item->display_item_no = (string)$item->item_number;
            } else {
                if (($item->depth ?? 0) > 0) {
                    $item->display_item_no = '—';
                } else {
                    $item->display_item_no = (string)$childCounter;
                    $childCounter++;
                }
            }

            // Description formatting
            $item->display_description = $item->rec_description ?? '';

            // Period Covered formatting
            if (!empty($item->rec_start_at) || !empty($item->rec_ends_at)) {
                $start = !empty($item->rec_start_at) ? Carbon::parse($item->rec_start_at)->format('Y') : '';
                $end = !empty($item->rec_ends_at) ? Carbon::parse($item->rec_ends_at)->format('Y') : 'Present';
                $item->display_period_covered = trim($start . ' - ' . $end, ' -');
            } else {
                $item->display_period_covered = '—';
            }

            // Inventory appraisal values
            $item->display_volume = !empty($item->rec_volume) ? $item->rec_volume : '—';
            $item->display_location = !empty($item->rec_location) ? $item->rec_location : '—';
            $item->display_freq = !empty($item->rec_freq) ? $item->rec_freq : '—';
            $item->display_medium = !empty($item->rec_medium) ? $item->rec_medium : '—';
            
            $isPerm = (bool)($item->effective_is_permanent) || strtolower(trim($item->effective_total ?? '')) === 'permanent';
            $item->display_time_value = !empty($item->rec_time_value) ? $item->rec_time_value : ($isPerm ? 'P' : 'T');
            $item->display_utility = !empty($item->rec_utility) ? $item->rec_utility : ($isPerm ? 'Arc' : 'Adm');
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

        // Selected items for print document
        $printItems = [];
        // Print-others clearance: strip other-office records from print if user lacks permission (default false)
        $canPrintOthers = $isSadm || (bool)($authPerms?->can_rdp_print_others_form_1 ?? false);
        $userOfficeForPrint = auth()->user()?->details?->office_code ?? null;

        if (!empty($this->selectedIds)) {
            $selectedInts = array_map('intval', $this->selectedIds);
            foreach ($treeOrdered as $item) {
                if (in_array((int)$item->id, $selectedInts, true)) {
                    if (!$canPrintOthers && $userOfficeForPrint && $item->recorded_at_office !== $userOfficeForPrint) {
                        continue; // skip other-office items
                    }
                    $printItems[] = $item;
                }
            }
        } else {
            foreach ($treeOrdered as $item) {
                if (!$canPrintOthers && $userOfficeForPrint && $item->recorded_at_office !== $userOfficeForPrint) {
                    continue;
                }
                $printItems[] = $item;
            }
        }

        $totalCount     = count($treeOrdered);
        $permanentCount = count(array_filter($treeOrdered, fn($i) => $i->effective_is_permanent || strtolower(trim($i->effective_total ?? '')) === 'permanent'));
        $temporaryCount = $totalCount - $permanentCount;

        $officesList = DB::table('office')->where('is_active', true)->orderBy('office_name')->get();

        return [
            'recordSeriesList' => array_values($treeOrdered),
            'printItems'       => $printItems,
            'officesList'      => $officesList,
            'totalCount'       => $totalCount,
            'permanentCount'   => $permanentCount,
            'temporaryCount'   => $temporaryCount,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/console.css'])
@endpush

<div style="padding: 24px; background: #f8fafc; min-height: 100vh; font-family: 'Inter', system-ui, -apple-system, sans-serif;">
    <style>
        .nap-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); margin-bottom: 24px; }
        .nap-table { width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: left; }
        .nap-table th { background: #f1f5f9; padding: 12px 14px; font-weight: 700; color: #334155; border-bottom: 2px solid #cbd5e1; }
        .nap-table td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; color: #0f172a; }
        .nap-btn { padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; border: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .nap-btn-primary { background: #2563eb; color: #ffffff; }
        .nap-btn-primary:hover { background: #1d4ed8; }
        .nap-btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .nap-btn-secondary:hover { background: #e2e8f0; }

        /* Modal Overlay & Card Styling */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .modal-content { background: #cbd5e1; width: 100%; max-width: 1100px; max-height: 94vh; border-radius: 14px; overflow-y: auto; box-shadow: 0 20px 40px rgba(0,0,0,0.3); padding: 24px; display: flex; flex-direction: column; gap: 20px; }
        
        .modal-dialog { background: #ffffff; width: 100%; max-width: 650px; border-radius: 14px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); padding: 24px; }

        /* Printable Document Styling for NAP Form 1 (Always Landscape Page Layout) */
        .print-page { 
            width: 1020px; 
            min-height: 700px; 
            height: auto;
            background: #ffffff; 
            border: none; 
            margin: 0 auto 30px auto; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.12); 
            padding: 24px 25px 50px 25px; 
            box-sizing: border-box;
            color: #000000;
            font-family: Arial, Helvetica, sans-serif;
            position: relative;
        }

        .doc-table { width: 100%; border-collapse: collapse; border: 2.5px solid #000000; font-size: 9.5px; }
        .doc-table th { border: 1px solid #000000; padding: 6px 5px; vertical-align: middle; line-height: 1.3; text-align: center; }
        .doc-table td { border: 1px solid #000000; padding: 5px 5px; vertical-align: middle; line-height: 1.3; }
        
        /* Remove horizontal row borders exclusively inside data table body (<tbody>) */
        .doc-data-table tbody td { border-left: 1px solid #000000; border-right: 1px solid #000000; border-top: none; border-bottom: none; padding: 4px 5px !important; }

        .header-cell { text-align: center; width: 32%; vertical-align: middle !important; padding: 8px !important; }
        .header-main-text { font-weight: bold; font-size: 11px; margin-top: 2px; }
        .header-sub-text { font-style: italic; font-size: 9px; margin-bottom: 6px; }
        .header-doc-title { font-weight: bold; font-size: 12px; line-height: 1.3; }
        .field-label { font-weight: bold; font-size: 8.5px; display: block; margin-bottom: 2px; color: #000; }
        .field-value { font-weight: bold; font-size: 10.5px; color: #000; }
        
        .sub-header th { font-weight: bold; font-size: 8px; padding: 4px 3px; }
        
        /* Borderless Legend Section */
        .footer-legend { font-size: 8.5px; font-weight: bold; margin-top: 12px; margin-bottom: 12px; border: none; padding: 4px 0; }
        
        .signatures-wrapper { page-break-inside: avoid; break-inside: avoid; }
        .signatures-table { width: 100%; border-collapse: collapse; border: 2.5px solid #000000; font-size: 9px; margin-bottom: 16px; page-break-inside: avoid; break-inside: avoid; }
        .signatures-table td { border: 1px solid #000000; padding: 10px 12px; width: 33.33%; vertical-align: top; height: 95px; }
        .sig-block { display: flex; flex-direction: column; align-items: center; margin-top: 25px; }
        .sig-line { border-bottom: 1px solid #000; width: 85%; text-align: center; margin-bottom: 4px; font-size: 10px; font-weight: bold; padding-bottom: 1px; }
        .sig-label { font-size: 8.5px; text-align: center; color: #1e293b; }

        /* Retention Period Red Color Styling in Print Area */
        .print-retention-red {
            color: #dc2626 !important;
            font-weight: bold !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Force Legal Landscape Print Orientation and 0.5in Margins */
        @page {
            size: legal landscape;
            margin: 0.5in;
        }

        @media print {
            /* Hide everything on the page except the print modal content */
            body { background: #ffffff !important; margin: 0 !important; padding: 0 !important; }
            header, #navigation, .no-print, .toolbar, .nap-card, .nap-btn,
            footer, .modal-header-actions, .chatify-widget, #chatify-global-widget,
            #chatify-widget-card, #chatify-widget-btn, [id^="chatify"], .rdp-fab-nav { display: none !important; opacity: 0 !important; visibility: hidden !important; }
            /* Hide the main page content (article-container) */
            #article-container > div > *:not(.modal-overlay) { display: none !important; }
            /* Make modal print inline (not fixed-positioned overlay) */
            .modal-overlay { 
                position: static !important; 
                background: none !important; 
                padding: 0 !important; 
                display: block !important;
            }
            .modal-content { 
                background: none !important; 
                max-width: 100% !important; 
                max-height: none !important;
                padding: 0 !important; 
                box-shadow: none !important;
                overflow: visible !important;
            }
            .print-page { 
                box-shadow: none !important; 
                border: none !important; 
                width: 100% !important; 
                max-width: 100% !important;
                min-height: auto !important;
                height: auto !important;
                margin: 0 !important; 
                padding: 0 !important; 
                page-break-after: auto;
            }
            .print-page:last-child { page-break-after: auto; }
            .doc-table tr { page-break-inside: avoid !important; break-inside: avoid !important; }
            .print-retention-red { color: #dc2626 !important; font-weight: bold !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .table-section-divider-row { background: #e2e8f0 !important; -webkit-print-color-adjust: exact; }
            .signatures-wrapper { page-break-before: always !important; break-before: page !important; page-break-inside: avoid !important; break-inside: avoid !important; margin-top: 20px !important; }
            .signatures-table { page-break-inside: avoid !important; break-inside: avoid !important; }
            .signatures-table tr, .signatures-table td { page-break-inside: avoid !important; break-inside: avoid !important; }
        }
    </style>

    <!-- Header Section -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
        <div>
            <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0;">NAP Form 1: Records Inventory and Appraisal</h1>
            <p style="font-size: 14px; color: #64748b; margin: 4px 0 0 0;">Official inventory listing & appraisal schedule for all record series.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" wire:click="openClusterModal" class="nap-btn nap-btn-primary" {{ empty($selectedIds) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' }}>
                📦 Create Cluster ({{ count($selectedIds) }})
            </button>
        </div>
    </div>

    <!-- Stat Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                📁
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($totalCount) }}</div>
                <div style="font-size: 12.5px; font-weight: 600; color: #64748b;">Total Record Series</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: #f0fdf4; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                ♾️
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($permanentCount) }}</div>
                <div style="font-size: 12.5px; font-weight: 600; color: #64748b;">Permanent Series</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: #fff7ed; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                ⏳
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($temporaryCount) }}</div>
                <div style="font-size: 12.5px; font-weight: 600; color: #64748b;">Temporary Series</div>
            </div>
        </div>
    </div>

    <!-- Filters & Table Card -->
    <div class="nap-card">
        <div style="display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 20px;">
            <div style="display: flex; gap: 12px; flex-wrap: wrap; flex: 1;">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search item no, series title, description, remarks..." style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 280px; outline: none;">
                
                <select wire:model.live="retentionFilter" style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff; outline: none;">
                    <option value="">All Retention Types</option>
                    <option value="permanent">Permanent Retention</option>
                    <option value="temporary">Temporary Retention</option>
                </select>

                <select wire:model.live="officeFilter" style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff; outline: none; font-weight: 600; color: #0f172a;">
                    <option value="">All Offices</option>
                    @foreach($officesList as $off)
                        <option value="{{ $off->office_code }}">{{ $off->office_name }} ({{ $off->office_code }})</option>
                    @endforeach
                </select>

                @if($search || $retentionFilter || $officeFilter || count($selectedIds) > 0)
                    <button type="button" wire:click="clearFilters" class="nap-btn nap-btn-secondary">
                        Reset Filters
                    </button>
                @endif
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="nap-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="width: 40px; text-align: center;">
                            <input type="checkbox" wire:model.live="selectAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #2563eb;">
                        </th>
                        <th rowspan="2" style="width: 80px; text-align: center;">ITEM NO.</th>
                        <th rowspan="2">RECORD SERIES TITLE & DESCRIPTION</th>
                        <th rowspan="2" style="width: 100px; text-align: center;">PERIOD</th>
                        <th rowspan="2" style="width: 85px; text-align: center;">VOLUME</th>
                        <th rowspan="2" style="width: 100px; text-align: center;">LOCATION</th>
                        <th rowspan="2" style="width: 60px; text-align: center;">TIME</th>
                        <th rowspan="2" style="width: 60px; text-align: center;">UTIL</th>
                        <th colspan="3" style="text-align: center; border-bottom: 1px solid #cbd5e1;">RETENTION PERIOD</th>
                        <th rowspan="2" style="width: 130px; text-align: right;">ACTION</th>
                    </tr>
                    <tr style="background: #f1f5f9; font-size: 11px; text-transform: uppercase;">
                        <th style="width: 65px; text-align: center; border-right: 1px solid #cbd5e1; padding: 6px 4px;">ACTIVE</th>
                        <th style="width: 65px; text-align: center; border-right: 1px solid #cbd5e1; padding: 6px 4px;">STORAGE</th>
                        <th style="width: 70px; text-align: center; padding: 6px 4px;">TOTAL</th>
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
                            $currentOfficeName = $item->recorded_office_name ?? $item->recorded_at_office ?? 'Unknown Office';
                        @endphp
                        @if($currentOfficeName !== $prevOfficeName)
                            <tr class="table-section-divider-row">
                                <td colspan="12" style="background: #e2e8f0; color: #1e293b; font-weight: 800; text-align: center; text-transform: uppercase; letter-spacing: 0.6px; padding: 7px 12px; font-size: 11.5px; font-family: 'Inter', sans-serif; border-top: 2px solid #cbd5e1; border-bottom: 2px solid #cbd5e1;">
                                    {{ strtoupper($currentOfficeName) }}
                                </td>
                            </tr>
                            @php $prevOfficeName = $currentOfficeName; @endphp
                        @endif
                        <tr style="{{ in_array($itemIdStr, $selectedIds) ? 'background: #eff6ff;' : '' }}">
                            <td style="text-align: center;">
                                <input type="checkbox" wire:model.live="selectedIds" value="{{ $item->id }}" style="width: 16px; height: 16px; cursor: pointer; accent-color: #2563eb;">
                            </td>
                            <td style="text-align: center; font-weight: 700; color: #475569;">
                                {{ $item->display_item_no }}
                            </td>
                            <td style="padding-left: {{ (($item->depth ?? 0) * 16) + 14 }}px; font-weight: 700; color: #0f172a;">
                                @if(($item->depth ?? 0) > 0)
                                    <span style="font-family: monospace; font-weight: 800; color: #2563eb;">└─</span> 
                                @endif
                                {{ $item->series_title }}
                                
                                @php
                                    $tagVal = $item->series_type_tag ?? 'UNREGISTERED';
                                @endphp
                                @if(!(bool)($item->is_verified ?? false))
                                    <span style="padding: 2px 7px; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 6px; font-weight: 800; font-size: 11px; margin-left: 8px; display: inline-block;">
                                        [ UNREGISTERED ]
                                    </span>
                                @elseif($tagVal === 'PH-NAP')
                                    <span style="padding: 2px 7px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; border-radius: 6px; font-weight: 800; font-size: 11px; margin-left: 8px; display: inline-block;">
                                        [ PH-NAP ]
                                    </span>
                                @elseif($tagVal === 'CSPC')
                                    <span style="padding: 2px 7px; background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; border-radius: 6px; font-weight: 800; font-size: 11px; margin-left: 8px; display: inline-block;">
                                        [ CSPC ]
                                    </span>
                                @else
                                    <span style="padding: 2px 7px; background: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd; border-radius: 6px; font-weight: 800; font-size: 11px; margin-left: 8px; display: inline-block;">
                                        [ {{ strtoupper($tagVal) }} ]
                                    </span>
                                @endif

                                @if(!empty($item->is_inherited))
                                    <span style="font-size: 11px; color: #64748b; font-weight: 500; margin-left: 6px;">(Inherited)</span>
                                @endif
                                @if(!empty($item->display_description))
                                    <div style="font-size: 11.5px; font-weight: 400; font-style: italic; color: #64748b; margin-top: 2px;">
                                        {{ $item->display_description }}
                                    </div>
                                @endif
                            </td>
                            <td style="text-align: center; font-size: 12px; color: #475569;">{{ $item->display_period_covered }}</td>
                            <td style="text-align: center; font-size: 12px; color: #475569;">{{ $item->display_volume }}</td>
                            <td style="text-align: center; font-size: 12px; color: #475569;">{{ $item->display_location }}</td>
                            <td style="text-align: center; font-weight: 800;">
                                <span style="padding: 2px 8px; background: {{ $item->display_time_value === 'P' ? '#eff6ff' : '#f1f5f9' }}; color: {{ $item->display_time_value === 'P' ? '#1e40af' : '#475569' }}; border-radius: 6px;">
                                    {{ $item->display_time_value }}
                                </span>
                            </td>
                            <td style="text-align: center; font-weight: 800;">
                                <span style="padding: 2px 8px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px;">
                                    {{ $item->display_utility }}
                                </span>
                            </td>
                            @if($isPermSeries)
                                <td colspan="3" style="text-align: center;">
                                    <span style="display: inline-block; padding: 4px 10px; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 12px; font-weight: 800; font-size: 11.5px;">
                                        PERMANENT
                                    </span>
                                </td>
                            @else
                                <td style="text-align: center; font-size: 12px; color: #475569; font-weight: 600;">
                                    {{ $item->effective_active ?: '—' }}
                                </td>
                                <td style="text-align: center; font-size: 12px; color: #475569; font-weight: 600;">
                                    {{ $item->effective_storage ?: '—' }}
                                </td>
                                <td style="text-align: center; font-size: 12px; font-weight: 800; color: #0f172a;">
                                    {{ $item->effective_total ?: '—' }}
                                </td>
                            @endif
                            <td style="text-align: right; white-space: nowrap;">
                                <button type="button" wire:click="openViewModal({{ $item->id }})" class="nap-btn nap-btn-secondary" style="padding: 5px 10px; font-size: 12px; margin-right: 4px;">
                                    👁️ View
                                </button>
                                <button type="button" wire:click="openEditModal({{ $item->id }})" class="nap-btn nap-btn-primary" style="padding: 5px 10px; font-size: 12px;">
                                    ✏️ Edit
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" style="padding: 32px; text-align: center; color: #64748b;">
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
                    <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;">Record Series Appraisal Details</h3>
                    <button type="button" wire:click="closeViewModal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <div style="display: flex; flex-direction: column; gap: 14px; font-size: 14px;">
                    <div>
                        <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Series Title</span>
                        <div style="font-size: 16px; font-weight: 800; color: #0f172a; margin-top: 2px;">{{ $viewSeriesData->series_title }}</div>
                    </div>

                    @if(!empty($viewSeriesData->rec_description))
                        <div>
                            <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Series Description</span>
                            <div style="color: #334155; font-style: italic; margin-top: 2px; line-height: 1.4;">{{ $viewSeriesData->rec_description }}</div>
                        </div>
                    @endif

                    @if(!empty($viewSeriesData->parent_title))
                        <div>
                            <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Parent Series Title</span>
                            <div style="font-weight: 700; color: #2563eb; margin-top: 2px;">{{ $viewSeriesData->parent_title }}</div>
                        </div>
                    @endif

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px;">
                        <div>
                            <span style="font-size: 11px; font-weight: 700; color: #64748b;">PERIOD COVERED</span>
                            <div style="font-weight: 700; color: #0f172a;">{{ $viewSeriesData->rec_start_at ? Carbon::parse($viewSeriesData->rec_start_at)->format('Y') . ' - ' . Carbon::parse($viewSeriesData->rec_ends_at)->format('Y') : '2020 - Present' }}</div>
                        </div>
                        <div>
                            <span style="font-size: 11px; font-weight: 700; color: #64748b;">VOL IN CUBIC METER</span>
                            <div style="font-weight: 700; color: #0f172a;">{{ $viewSeriesData->rec_volume ?: '0.5 cu. m.' }}</div>
                        </div>
                        <div>
                            <span style="font-size: 11px; font-weight: 700; color: #64748b;">LOCATION OF RECORDS</span>
                            <div style="font-weight: 700; color: #0f172a;">{{ $viewSeriesData->rec_location ?: 'Records Office' }}</div>
                        </div>
                        <div>
                            <span style="font-size: 11px; font-weight: 700; color: #64748b;">FREQ OF USE / DUPLICATION</span>
                            <div style="font-weight: 700; color: #0f172a;">{{ $viewSeriesData->rec_freq ?: 'Monthly' }} / {{ $viewSeriesData->rec_medium ?: 'Hardcopy' }}</div>
                        </div>
                        <div>
                            <span style="font-size: 11px; font-weight: 700; color: #64748b;">TIME VALUE (T / P)</span>
                            <div style="font-weight: 800; color: #2563eb;">{{ $viewSeriesData->rec_time_value ?: ($viewSeriesData->effective_is_permanent ? 'P (Permanent)' : 'T (Temporary)') }}</div>
                        </div>
                        <div>
                            <span style="font-size: 11px; font-weight: 700; color: #64748b;">UTILITY VALUE</span>
                            <div style="font-weight: 800; color: #2563eb;">{{ $viewSeriesData->rec_utility ?: ($viewSeriesData->effective_is_permanent ? 'Arc (Archival)' : 'Adm (Administrative)') }}</div>
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
                    <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;">Edit Record Series & Appraisal Data</h3>
                    <button type="button" wire:click="closeEditModal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <form wire:submit.prevent="saveEditSeries" style="display: flex; flex-direction: column; gap: 14px;">
                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Series Title</label>
                        <input type="text" wire:model="editSeriesTitle" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;" required>
                    </div>

                    <div>
                        <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Series Description</label>
                        <textarea wire:model="editDescription" rows="2" placeholder="Detailed description of record series..." style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;"></textarea>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Item Number</label>
                            @if($isRootParentForEdit)
                                <input type="number" wire:model="editItemNumber" placeholder="e.g. 1, 2" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                            @else
                                <input type="text" value="— (Child Subsection)" disabled style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #f1f5f9; color: #64748b; cursor: not-allowed; box-sizing: border-box;">
                            @endif
                        </div>
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Period Covered</label>
                            <input type="text" wire:model="editPeriodCovered" placeholder="e.g. 2020 - Present" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Volume (cu. m.)</label>
                            <input type="text" wire:model="editVolume" placeholder="e.g. 0.5 cu. m." style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Location of Records</label>
                            <input type="text" wire:model="editLocation" placeholder="e.g. Records Office" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Frequency of Use</label>
                            <input type="text" wire:model="editFreqUse" placeholder="e.g. Daily, Monthly" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Duplication / Medium</label>
                            <input type="text" wire:model="editDuplication" placeholder="e.g. Hardcopy" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Time Value (T / P)</label>
                            <select wire:model="editTimeValue" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff; outline: none; box-sizing: border-box;">
                                <option value="T">T - Temporary</option>
                                <option value="P">P - Permanent</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Utility Value</label>
                            <select wire:model="editUtilityValue" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff; outline: none; box-sizing: border-box;">
                                <option value="Adm">Adm - Administrative</option>
                                <option value="F">F - Fiscal</option>
                                <option value="L">L - Legal</option>
                                <option value="Arc">Arc - Archival</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Disposition Provision / Remarks</label>
                        <textarea wire:model="editRemarks" rows="2" placeholder="Additional disposition notes, provisions..." style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;"></textarea>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                        <button type="button" wire:click="closeEditModal" class="nap-btn nap-btn-secondary">Cancel</button>
                        <button type="submit" class="nap-btn nap-btn-primary">Save Changes</button>
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
                    <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;">Create Inventory Submission Cluster</h3>
                    <button type="button" wire:click="closeClusterModal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #1e40af; font-weight: 600;">
                        📦 Packaging <strong>{{ count($selectedIds) }}</strong> selected inventory items into a pending submission cluster.
                    </div>

                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Cluster Title / Name</label>
                        <input type="text" class="form-control" wire:model="clusterName" placeholder="e.g. ICT Office Inventory Batch 2026-Q3" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px;">
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                        <button type="button" wire:click="closeClusterModal" class="nap-btn nap-btn-secondary">Cancel</button>
                        <button type="button" wire:click="submitClusterCreation" class="nap-btn nap-btn-primary">Confirm & Create Cluster</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>