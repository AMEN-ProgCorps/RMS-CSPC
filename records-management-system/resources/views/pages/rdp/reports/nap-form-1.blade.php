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

    // Print Modal Properties
    public bool $showPrintModal = false;
    public bool $includeDescriptionOnPrint = false;

    // View Modal Properties
    public bool $showViewModal = false;
    public ?object $viewSeriesData = null;

    // Edit Subject Modal Properties
    public bool $showEditSubjectModal = false;
    public ?int $editingSubjectId = null;
    public ?int $editingPeriodId = null;
    public bool $editingIsBatchSubPeriod = false;
    public string $editSubjectDescription = '';
    public string $editSubjectDateCovered = '';
    public string $editSubjectVolume = '';
    public string $editSubjectLocation = '';
    public ?int $editSubjectMedium = null;
    public ?string $editSubjectRestriction = null;
    public ?string $editSubjectFrequency = null;
    public string $editSubjectTimeValue = 'T';
    public array $editSubjectUtilities = [];
    public bool $canEditDescription = true;
    public bool $canCancelRecord = true;

    // Printable Custom Header & Signature Fields
    public string $agencyName = 'Camarines Sur Polytechnic Colleges';
    public string $agencyAddress = 'San Miguel, Nabua, Camarines Sur';
    public string $departmentDivision = 'Administrative Services Division';
    public string $sectionUnit = 'Records Management Unit';
    public string $emailAddress = 'records@cspc.edu.ph';
    public string $personInCharge = 'Gennica Aprille S. Penetrante';
    public string $telephoneNumber = '(054) 288-1534 loc. 113';
    public string $datePrepared = '';

    // Signature Block Fields
    public string $preparedBy = 'Gennica Aprille S. Penetrante';
    public string $preparedPosition = 'Administrative Officer V / Records Officer';
    public string $assistedBy = '';
    public string $assistedPosition = 'NAP Records Management Analyst';
    public string $approvedBy = '';
    public string $approvedPosition = 'Chief of Division / Department Head';

    public function mount(): void
    {
        $user = Auth::user();
        $perms = $user?->permissions;

        // Access clearance check
        if (!$perms || (!(bool)($perms->is_sadm ?? false) && !(bool)($perms->can_rdp_access_form_1 ?? true))) {
            $this->redirectRoute('rdp');
            return;
        }

        $details = $user?->details;
        $this->datePrepared = Carbon::now()->format('F d, Y');

        $userOfficeCode = $details?->office?->office_code ?? $details?->office_code ?? null;
        $userOfficeName = $details?->office?->office_name ?? null;

        if ($userOfficeCode) {
            $this->officeFilter = $userOfficeCode;
        }
        if ($userOfficeName) {
            $this->orgUnit = $userOfficeName;
            $this->departmentDivision = $userOfficeName;
            $this->sectionUnit = $userOfficeName;
        }
        
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

        $sysTable = \Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        $this->includeDescriptionOnPrint = (\Illuminate\Support\Facades\DB::table($sysTable)->where('key', 'rdp_include_description_on_print')->value('value') === 'true');
    }

    public function updatedSelectAll($value): void
    {
        if ($value) {
            $user = Auth::user();
            $perms = $user?->permissions;
            $isSadm = (bool)($perms->is_sadm ?? false);
            $userOffice = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
            $effectiveOffice = ($isSadm && !empty($this->officeFilter)) ? $this->officeFilter : $userOffice;

            $query = DB::table('rdp_record')
                ->where('rdp_record.is_draft', false)
                ->where('rdp_record.is_active', true)
                ->where('rdp_record.transferred_to_nap3', false);

            if ($effectiveOffice) {
                $query->where(function($q) use ($effectiveOffice) {
                    $q->where('rdp_record.office_own', $effectiveOffice)
                      ->orWhereExists(function($sub) use ($effectiveOffice) {
                          $sub->select(DB::raw(1))
                              ->from('rdp_duplication_section')
                              ->whereColumn('rdp_duplication_section.dup_id_manager', 'rdp_record.duplication_id')
                              ->where('rdp_duplication_section.office_code', $effectiveOffice);
                      });
                });
            }

            if (!empty($this->search)) {
                $this->applySearchFilter($query, [
                    'rdp_record.description',
                    'rdp_record.volume',
                    'rdp_record.records_location',
                ]);
            }

            $allIds = $query->pluck('rdp_record.id')->toArray();
            $this->selectedIds = array_map('strval', $allIds);
        } else {
            $this->selectedIds = [];
        }
    }

    public function toggleSeriesSelection(int $seriesId, array $childRecordIds): void
    {
        $stringIds = array_map('strval', $childRecordIds);
        $allSelected = count(array_intersect($stringIds, $this->selectedIds)) === count($stringIds);

        if ($allSelected) {
            // Deselect all
            $this->selectedIds = array_values(array_diff($this->selectedIds, $stringIds));
        } else {
            // Select all
            $this->selectedIds = array_values(array_unique(array_merge($this->selectedIds, $stringIds)));
        }
    }

    /**
     * Word-level AND search: every typed word must match at least one of the
     * given columns, in any order — so "Pasay Travel" finds
     * "Travel Order to Pasay", and swapping a word ("Pasay certificate")
     * narrows the list to the records containing that word.
     */
    private function applySearchFilter($query, array $columns): void
    {
        $tokens = preg_split('/\s+/', trim($this->search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $like = '%' . addcslashes($token, '%_\\') . '%';

            $query->where(function ($q) use ($columns, $like) {
                foreach ($columns as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';

                    if (stripos($column, 'CAST(') === 0) {
                        $q->{$method}(DB::raw($column), 'ilike', $like);
                    } else {
                        $q->{$method}($column, 'ilike', $like);
                    }
                }
            });
        }
    }

    public function openClusterModal(): void
    {
        if (empty($this->selectedIds)) {
            $this->errorMessage = 'Please select at least one record to create a form.';
            return;
        }

        $user = Auth::user();
        $perms = $user?->permissions;
        $isSadm = (bool)($perms->is_sadm ?? false);
        $userOffice = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
        if (empty($userOffice) && !empty($user?->details?->office_id)) {
            $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
            $userOffice = DB::table($officeTbl)->where('id', $user->details->office_id)->value('office_code');
        }
        if (empty($userOffice) && $isSadm && !empty($this->officeFilter)) {
            $userOffice = $this->officeFilter;
        }
        if (empty($userOffice) && !empty($this->selectedIds)) {
            $firstRec = DB::table('rdp_record')->whereIn('id', array_map('intval', $this->selectedIds))->first();
            $userOffice = $firstRec?->office_own ?? null;
        }

        $officeDisplay = $userOffice ?: 'OFFICE';
        $this->clusterName = 'Inventory Form — ' . $officeDisplay . ' (' . Carbon::now()->format('Y-m-d') . ')';
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
            $this->errorMessage = 'Please select at least one record to create a form.';
            return;
        }

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $perms = $user?->permissions;
            $isSadm = (bool)($perms->is_sadm ?? false);
            $userOffice = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
            if (empty($userOffice) && !empty($user?->details?->office_id)) {
                $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
                $userOffice = DB::table($officeTbl)->where('id', $user->details->office_id)->value('office_code');
            }
            if (empty($userOffice) && $isSadm && !empty($this->officeFilter)) {
                $userOffice = $this->officeFilter;
            }
            if (empty($userOffice) && !empty($this->selectedIds)) {
                $firstRec = DB::table('rdp_record')->whereIn('id', array_map('intval', $this->selectedIds))->first();
                $userOffice = $firstRec?->office_own ?? null;
            }

            $mainPendingTbl = \Illuminate\Support\Facades\Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';
            $mainPendingId = DB::table($mainPendingTbl)->insertGetId([
                'status'     => 'UNUSED',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('rdp_pending_record')->insert([
                'cluster_id'       => $mainPendingId,
                'cluster_name'     => trim($this->clusterName) ?: ('Inventory Form — ' . ($userOffice ?: 'OFFICE') . ' (' . now()->format('Y-m-d') . ')'),
                'status_id'        => 1, // Pending Verification
                'office'           => $userOffice,
                'created_by'       => $user?->id,
                'is_for_nap_one'   => true,
                'is_for_nap_three' => false,
                'is_active'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $selectedInts = array_map('intval', $this->selectedIds);
            $validRecordIds = DB::table('rdp_record')
                ->whereIn('id', $selectedInts)
                ->pluck('id')
                ->all();

            if (empty($validRecordIds)) {
                $this->errorMessage = 'Please select at least one valid record to create a form.';
                DB::rollBack();
                return;
            }

            foreach ($validRecordIds as $recId) {
                DB::table('rdp_grouped_record')->insert([
                    'group_head' => $mainPendingId,
                    'record_id'  => (int)$recId,
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            $this->successMessage = 'Inventory form created successfully! It is now available under Pending / List for submission.';
            $this->selectedIds = [];
            $this->selectAll = false;
            $this->closeClusterModal();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to create form: ' . $e->getMessage();
        }
    }

    public function openPrintModal(): void
    {
        $perms = Auth::user()?->permissions;
        if (!($perms->is_sadm ?? false) && !(bool)($perms->can_rdp_print_form_1 ?? true)) {
            $this->errorMessage = 'You do not have clearance to print NAP Form 1.';
            return;
        }
        $sysTable = \Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        $this->includeDescriptionOnPrint = (\Illuminate\Support\Facades\DB::table($sysTable)->where('key', 'rdp_include_description_on_print')->value('value') === 'true');
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
                'rdp_recorded_value.medium_name as rec_medium',
            ])
            ->where('rdp_record_series.id', $id)
            ->first();

        if ($query) {
            $this->viewSeriesData = $query;
            $this->showViewModal = true;
        }
    }

    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewSeriesData = null;
    }

    public function openEditSubjectModal(int $id, ?int $periodId = null): void
    {
        $rec = DB::table('rdp_record')->where('id', $id)->first();
        if ($rec) {
            $user = Auth::user();
            $perms = $user?->permissions;
            $isSadm = (bool)($perms->is_sadm ?? false);
            $userOffice = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
            if (empty($userOffice) && !empty($user?->details?->office_id)) {
                $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
                $userOffice = DB::table($officeTbl)->where('id', $user->details->office_id)->value('office_code');
            }
            $isOtherOffice = !empty($userOffice) && !empty($rec->office_own) && ($rec->office_own !== $userOffice);
            $this->canEditDescription = $isSadm || (!$isOtherOffice ? (bool)($perms->can_rdp_modify_form_1 ?? true) : ((bool)($perms->can_rdp_modify_form_1 ?? true) && (bool)($perms->can_rdp_edit_others_form_1 ?? false)));
            $this->canCancelRecord = $this->canEditDescription;

            $this->editingSubjectId = $rec->id;
            $this->editingPeriodId = $periodId;
            $this->editingIsBatchSubPeriod = !empty($periodId);

            $this->editSubjectLocation = $rec->records_location ?? '';
            $this->editSubjectMedium = $rec->records_medium ? (int)$rec->records_medium : null;
            $this->editSubjectRestriction = $rec->restriction ?? null;
            $this->editSubjectFrequency = $rec->frequence_use ?? null;
            $this->editSubjectTimeValue = $rec->time_value ?: 'T';

            if ($periodId) {
                $period = DB::table('rdp_period_covered')->where('id', $periodId)->first();
                $allPeriods = DB::table('rdp_period_covered')->where('period_owner', $id)->orderBy('id', 'asc')->get();
                $subIdx = 1;
                foreach ($allPeriods as $idx => $p) {
                    if ($p->id == $periodId) {
                        $subIdx = $idx + 1;
                        break;
                    }
                }
                $this->editSubjectDescription = ($rec->description ?? '') . ' ' . $subIdx;
                $this->editSubjectVolume = $period->volume ?? '';
                if ($period) {
                    if (!empty($period->date_covered_end) && $period->date_covered_end !== $period->date_covered) {
                        $this->editSubjectDateCovered = $period->date_covered . ' to ' . $period->date_covered_end;
                    } else {
                        $this->editSubjectDateCovered = $period->date_covered ?? '';
                    }
                } else {
                    $this->editSubjectDateCovered = '';
                }
            } else {
                $this->editSubjectDescription = $rec->description ?? '';
                $this->editSubjectVolume = $rec->volume ?? '';
                $period = DB::table('rdp_period_covered')->where('period_owner', $id)->orderBy('id', 'desc')->first();
                $this->editSubjectDateCovered = $period->date_covered ?? '';
            }

            // Utilities
            $this->editSubjectUtilities = DB::table('rdp_utility_manager')
                ->where('record_holder', $id)
                ->where('is_active', true)
                ->pluck('utility_medium')
                ->map(fn($v) => (int)$v)
                ->all();

            $this->showEditSubjectModal = true;
        }
    }

    public function closeEditSubjectModal(): void
    {
        $this->showEditSubjectModal = false;
        $this->editingSubjectId = null;
        $this->editingPeriodId = null;
        $this->editingIsBatchSubPeriod = false;
    }

    public function saveEditSubject(): void
    {
        if (!$this->editingSubjectId) return;

        if (!$this->canEditDescription) {
            $this->errorMessage = 'You do not have clearance to edit this record.';
            return;
        }

        $cleanDesc = trim($this->editSubjectDescription);
        if (empty($cleanDesc)) {
            $this->errorMessage = 'Subject description cannot be empty.';
            return;
        }

        try {
            DB::beginTransaction();

            if ($this->editingPeriodId) {
                // Parse date range if entered as range (e.g. "2022-01-01 to 2022-12-31" or "2022-01-01 - 2022-12-31")
                $rawDate = trim($this->editSubjectDateCovered);
                $startDate = $rawDate;
                $endDate = null;

                if (preg_match('/^(.*?)\s+(?:-|to)\s+(.*)$/i', $rawDate, $m)) {
                    $startDate = trim($m[1]);
                    $endDate = trim($m[2]);
                }

                DB::table('rdp_period_covered')->where('id', $this->editingPeriodId)->update([
                    'date_covered'     => $startDate ?: null,
                    'date_covered_end' => $endDate ?: null,
                    'volume'           => mb_strtoupper(trim($this->editSubjectVolume)),
                    'modified_at'      => Carbon::now(),
                ]);

                // Recompile parent volume from all sub-periods
                $allSubVols = DB::table('rdp_period_covered')
                    ->where('period_owner', $this->editingSubjectId)
                    ->whereNotNull('volume')
                    ->pluck('volume')
                    ->all();
                $compiledVol = $this->compileVolume($allSubVols);

                $updatePayload = [
                    'volume'           => mb_strtoupper($compiledVol ?: trim($this->editSubjectVolume)),
                    'records_location' => mb_strtoupper(trim($this->editSubjectLocation)),
                    'records_medium'   => $this->editSubjectMedium ?: null,
                    'restriction'      => $this->editSubjectRestriction ?: null,
                    'frequence_use'    => $this->editSubjectFrequency ?: null,
                    'time_value'       => $this->editSubjectTimeValue ?: 'T',
                    'updated_at'       => Carbon::now(),
                ];

                DB::table('rdp_record')
                    ->where('id', $this->editingSubjectId)
                    ->update($updatePayload);
            } else {
                $updatePayload = [
                    'volume'           => mb_strtoupper(trim($this->editSubjectVolume)),
                    'records_location' => mb_strtoupper(trim($this->editSubjectLocation)),
                    'records_medium'   => $this->editSubjectMedium ?: null,
                    'restriction'      => $this->editSubjectRestriction ?: null,
                    'frequence_use'    => $this->editSubjectFrequency ?: null,
                    'time_value'       => $this->editSubjectTimeValue ?: 'T',
                    'updated_at'       => Carbon::now(),
                ];

                if ($this->canEditDescription) {
                    $updatePayload['description'] = mb_strtoupper($cleanDesc);
                }

                DB::table('rdp_record')
                    ->where('id', $this->editingSubjectId)
                    ->update($updatePayload);

                // Update or insert period covered
                $existingPeriod = DB::table('rdp_period_covered')->where('period_owner', $this->editingSubjectId)->orderBy('id', 'desc')->first();
                if ($existingPeriod) {
                    DB::table('rdp_period_covered')
                        ->where('id', $existingPeriod->id)
                        ->update([
                            'date_covered' => trim($this->editSubjectDateCovered),
                            'modified_at'  => Carbon::now(),
                        ]);
                } elseif (!empty(trim($this->editSubjectDateCovered))) {
                    DB::table('rdp_period_covered')->insert([
                        'period_owner' => $this->editingSubjectId,
                        'date_covered' => trim($this->editSubjectDateCovered),
                        'created_at'   => Carbon::now(),
                        'modified_at'  => Carbon::now(),
                    ]);
                }
            }

            // Update utility manager
            DB::table('rdp_utility_manager')->where('record_holder', $this->editingSubjectId)->delete();
            foreach ($this->editSubjectUtilities as $uId) {
                if (empty($uId)) continue;
                DB::table('rdp_utility_manager')->insert([
                    'record_holder'  => $this->editingSubjectId,
                    'utility_medium' => (int)$uId,
                    'is_active'      => true,
                    'created_at'     => Carbon::now(),
                    'updated_at'     => Carbon::now(),
                ]);
            }

            DB::commit();

            $this->successMessage = $this->editingPeriodId ? "Batch item record updated successfully." : "Record subject updated successfully.";
            $this->closeEditSubjectModal();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to update record: ' . $e->getMessage();
        }
    }

    public function cancelRecord(): void
    {
        if (!$this->editingSubjectId) return;

        if (!$this->canCancelRecord) {
            $this->errorMessage = 'You do not have clearance to cancel records on NAP Form 1.';
            return;
        }

        try {
            DB::beginTransaction();

            if ($this->editingPeriodId) {
                // Delete this sub-period
                DB::table('rdp_period_covered')->where('id', $this->editingPeriodId)->delete();

                $remainingPeriods = DB::table('rdp_period_covered')->where('period_owner', $this->editingSubjectId)->count();
                if ($remainingPeriods === 0) {
                    DB::table('rdp_record')->where('id', $this->editingSubjectId)->update([
                        'is_active'  => false,
                        'updated_at' => Carbon::now(),
                    ]);
                } else {
                    $allSubVols = DB::table('rdp_period_covered')
                        ->where('period_owner', $this->editingSubjectId)
                        ->whereNotNull('volume')
                        ->pluck('volume')
                        ->all();
                    $compiledVol = $this->compileVolume($allSubVols);
                    DB::table('rdp_record')->where('id', $this->editingSubjectId)->update([
                        'volume'     => $compiledVol ?: '—',
                        'updated_at' => Carbon::now(),
                    ]);
                }

                $adminId = auth()->id() ?? 1;
                DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
                    'admin_id'     => $adminId,
                    'changes'      => 'Canceled Batch Sub-Period Record (ID: ' . $this->editingPeriodId . ') of Record ID: ' . $this->editingSubjectId . ' via NAP Form 1',
                    'what_system'  => 2,
                    'when_changes' => now(),
                ]);

                DB::commit();
                $this->successMessage = 'Batch item record removed successfully.';
                $this->closeEditSubjectModal();
                return;
            }

            $record = DB::table('rdp_record')->where('id', $this->editingSubjectId)->first();
            if (!$record) {
                $this->errorMessage = 'Record not found.';
                return;
            }

            DB::table('rdp_record')->where('id', $this->editingSubjectId)->update([
                'is_active'  => false,
                'updated_at' => Carbon::now(),
            ]);

            // Audit Log
            $adminId = auth()->id() ?? 1;
            DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
                'admin_id'     => $adminId,
                'changes'      => 'Canceled Subject Record via NAP Form 1: "' . ($record->description ?? '') . '" (ID: ' . $this->editingSubjectId . ')',
                'what_system'  => 2,
                'when_changes' => now(),
            ]);

            DB::commit();

            $this->successMessage = 'Record canceled successfully.';
            $this->closeEditSubjectModal();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to cancel record: ' . $e->getMessage();
        }
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->retentionFilter = '';
        $user = Auth::user();
        $userOffice = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
        $this->officeFilter = $userOffice ?? '';
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    // Helper compilers for series summary rows
    private function compilePeriodCovered(array $dates): string
    {
        $years = [];
        $rawList = [];
        $maxParsedDate = null;
        $maxParsedYear = null;
        $now = Carbon::now();

        foreach ($dates as $d) {
            $d = trim((string)$d);
            if (empty($d) || $d === '—') continue;

            try {
                $parsed = Carbon::parse($d);
                if ($maxParsedDate === null || $parsed->gt($maxParsedDate)) {
                    $maxParsedDate = $parsed;
                }
            } catch (\Throwable) {}

            if (preg_match_all('/(19\d\d|20\d\d)/', $d, $allMatches)) {
                $matchedYears = array_map('intval', $allMatches[1]);
                $minY = min($matchedYears);
                $maxY = max($matchedYears);
                for ($y = $minY; $y <= $maxY; $y++) {
                    $years[] = $y;
                }
                if ($maxParsedYear === null || $maxY > $maxParsedYear) {
                    $maxParsedYear = $maxY;
                }
            } else {
                $rawList[] = $d;
            }
        }

        $years = array_values(array_unique($years));
        sort($years);

        if (empty($years)) {
            return !empty($rawList) ? implode(', ', array_unique($rawList)) : '—';
        }

        // Check if the period covered extends beyond current date/year
        $isFutureOrPresent = ($maxParsedYear && $maxParsedYear > $now->year)
                          || ($maxParsedDate && $maxParsedDate->year > $now->year);

        // Group consecutive years
        $groups = [];
        $currentGroup = [];
        foreach ($years as $y) {
            if (empty($currentGroup)) {
                $currentGroup[] = $y;
            } else {
                $last = end($currentGroup);
                if ($last + 1 === $y) {
                    $currentGroup[] = $y;
                } else {
                    $groups[] = $currentGroup;
                    $currentGroup = [$y];
                }
            }
        }
        if (!empty($currentGroup)) {
            $groups[] = $currentGroup;
        }

        $formattedGroups = [];
        $numGroups = count($groups);
        foreach ($groups as $gIdx => $grp) {
            $startYear = $grp[0];
            $endYear = end($grp);
            $isLastGroup = ($gIdx === $numGroups - 1);

            if ($isLastGroup && $isFutureOrPresent) {
                $formattedGroups[] = $startYear . ' - Present';
            } elseif ($startYear !== $endYear) {
                $formattedGroups[] = $startYear . '-' . $endYear;
            } else {
                $formattedGroups[] = (string)$startYear;
            }
        }

        $res = implode(', ', $formattedGroups);
        if (!empty($rawList)) {
            $res .= ', ' . implode(', ', array_unique($rawList));
        }
        return $res;
    }

    private function formatBatchDateRange(?string $startDate, ?string $endDate = null, bool $isHeader = false): string
    {
        $startDate = trim((string)$startDate);
        $endDate = trim((string)$endDate);

        if (empty($startDate) && empty($endDate)) return '—';

        if (empty($endDate) && preg_match('/^(.*?)\s+(?:-|to)\s+(.*)$/i', $startDate, $m)) {
            $startDate = trim($m[1]);
            $endDate = trim($m[2]);
        }

        $formatToken = function(string $token): array {
            $token = trim($token);
            if (empty($token) || $token === '—') return ['text' => '—', 'year' => null, 'carbon' => null, 'has_month' => false];

            if (preg_match('/^(19\d\d|20\d\d)$/', $token, $m)) {
                $y = (int)$m[1];
                return ['text' => (string)$y, 'year' => $y, 'carbon' => Carbon::createFromDate($y, 1, 1), 'has_month' => false];
            }

            if (preg_match('/^([a-zA-Z]+)\s+(\d{4})$/', $token, $m)) {
                try {
                    $c = Carbon::parse("1 {$m[1]} {$m[2]}");
                    $text = in_array(strtolower($m[1]), ['june', 'july']) ? $c->format('F Y') : $c->format('M Y');
                    return ['text' => $text, 'year' => (int)$m[2], 'carbon' => $c, 'has_month' => true];
                } catch (\Throwable) {}
            }

            try {
                $c = Carbon::parse($token);
                $text = (in_array($c->month, [6, 7])) ? $c->format('F Y') : $c->format('M Y');
                return ['text' => $text, 'year' => $c->year, 'carbon' => $c, 'has_month' => true];
            } catch (\Throwable) {
                return ['text' => $token, 'year' => null, 'carbon' => null, 'has_month' => false];
            }
        };

        $sInfo = $formatToken($startDate);
        $eInfo = !empty($endDate) ? $formatToken($endDate) : null;

        if ($isHeader) {
            $now = Carbon::now();
            $isFutureOrPresent = false;
            if ($eInfo && $eInfo['carbon']) {
                $isFutureOrPresent = $eInfo['carbon']->year > $now->year;
            } elseif ($sInfo && $sInfo['carbon']) {
                $isFutureOrPresent = $sInfo['carbon']->year > $now->year;
            }

            if ($isFutureOrPresent) {
                $headerStart = ($sInfo['has_month'] ? $sInfo['text'] : (string)$sInfo['year']);
                return $headerStart . ' - Present';
            }
        }

        if (!$eInfo || empty($endDate)) {
            return $sInfo['text'];
        }

        // If start date had no month (year-only)
        if (!$sInfo['has_month']) {
            if ($sInfo['year'] === $eInfo['year']) {
                return (string)$sInfo['year'];
            }
            return (string)$sInfo['year'] . ' - ' . (string)$eInfo['year'];
        }

        if ($sInfo['text'] === $eInfo['text']) {
            return $sInfo['text'];
        }

        return $sInfo['text'] . ' - ' . $eInfo['text'];
    }

    private function formatDateRange(?string $startDate, ?string $endDate = null): string
    {
        return $this->formatBatchDateRange($startDate, $endDate, false);
    }

    private function formatSubPeriodYear(?string $startStr, ?string $endStr): string
    {
        try {
            $startY = !empty($startStr) ? \Carbon\Carbon::parse($startStr)->year : null;
            $endY = !empty($endStr) ? \Carbon\Carbon::parse($endStr)->year : null;
            if ($startY && $endY) {
                return ($startY === $endY) ? (string)$startY : ($startY . '–' . $endY);
            }
            if ($startY) return (string)$startY;
            if ($endY) return (string)$endY;
        } catch (\Throwable $e) {}
        return '';
    }

    private function formatSubPeriodTitle(string $parentSubject, ?string $pDesc, ?string $startStr, ?string $endStr, int $fallbackIndex = 1): string
    {
        $pDesc = trim((string)$pDesc);
        if (!empty($pDesc)) {
            return $pDesc;
        }

        $year = $this->formatSubPeriodYear($startStr, $endStr);
        $parentSubject = trim($parentSubject);
        if (!empty($parentSubject) && !empty($year)) {
            return $parentSubject . ' ' . $year;
        }
        if (!empty($parentSubject)) {
            return $parentSubject . ' ' . $fallbackIndex;
        }
        return $year ?: ('Item ' . $fallbackIndex);
    }

    private function processSeriesRecords(
        $records,
        $seriesId,
        $effTotal,
        $effActive,
        $effStorage,
        $isPerm,
        $periods,
        $utilities,
        $mediumsMap,
        $duplications,
        &$compiledDates,
        &$compiledVols,
        &$compiledMediums,
        &$compiledRestrictions,
        &$compiledLocs,
        &$compiledFreqs,
        &$compiledDups,
        &$compiledTimes,
        &$compiledUtils,
        &$totalItemsCount
    ): array {
        $childItems = [];

        // Group records by office and normalized description (subject)
        $groupedBySubject = $records->groupBy(function($r) {
            $off = trim((string)($r->office_own ?? ''));
            $desc = trim(mb_strtoupper((string)($r->description ?? '')));
            return $off . '___' . $desc;
        });

        foreach ($groupedBySubject as $group) {
            $firstRec = $group->first();
            $primarySubject = $firstRec->description;

            // Collect active periods for all records in this group
            $groupPeriods = [];
            foreach ($group as $r) {
                $rPeriods = $periods[$r->id] ?? collect();
                $isRecordBatch = (bool)($r->ispartof_batch ?? false) || $rPeriods->count() > 1;

                if ($isRecordBatch) {
                    $activeP = $rPeriods->filter(function($p) use ($effTotal, $effActive, $effStorage, $isPerm) {
                        return !\App\Services\RdpRetentionService::isPeriodExpired(
                            $p->date_covered,
                            $p->date_covered_end ?? null,
                            $effTotal,
                            $effActive,
                            $effStorage,
                            $isPerm
                        );
                    });
                } else {
                    $activeP = $rPeriods;
                }

                foreach ($activeP as $p) {
                    $groupPeriods[] = [
                        'rec'    => $r,
                        'period' => $p,
                    ];
                }
            }

            if (empty($groupPeriods)) {
                continue;
            }

            $isBatch = ($group->count() > 1) 
                || count($groupPeriods) > 1 
                || (bool)($firstRec->ispartof_batch ?? false);

            if (!$isBatch) {
                // Single Record
                $r = $groupPeriods[0]['rec'];
                $p = $groupPeriods[0]['period'];

                $rawDate = $p->date_covered ?? '';
                $rawDateEnd = $p->date_covered_end ?? null;
                if (!empty($rawDate)) $compiledDates[] = $rawDate;
                if (!empty($rawDateEnd)) $compiledDates[] = $rawDateEnd;
                $formattedDate = !empty($rawDateEnd) ? $this->formatDateRange($rawDate, $rawDateEnd) : $this->formatItemDate($rawDate);
                $recVolume = $r->volume ?: '—';

                $recMedium = !empty($r->records_medium) ? ($mediumsMap[$r->records_medium] ?? (string)$r->records_medium) : '—';
                $recRestriction = !empty($r->restriction) ? $r->restriction : '—';
                $recFreq = !empty($r->frequence_use) ? $r->frequence_use : '—';

                if (!empty($r->duplication_id) && isset($duplications[$r->duplication_id])) {
                    $dupCodes = $duplications[$r->duplication_id]->pluck('office_code')->unique()->values()->all();
                    $recDup = !empty($dupCodes) ? implode(', ', $dupCodes) : '—';
                } else {
                    $recDup = '—';
                }

                $uRows = ($utilities[$r->id] ?? collect())->pluck('utility_name')->all();

                $compiledVols[] = $recVolume;
                $compiledMediums[] = $recMedium;
                $compiledRestrictions[] = $recRestriction;
                $compiledLocs[] = $r->records_location;
                $compiledFreqs[] = $recFreq;
                $compiledDups[] = $recDup;
                $compiledTimes[] = $r->time_value;
                foreach ($uRows as $un) $compiledUtils[] = $un;

                $childItems[] = (object)[
                    'id'            => $r->id,
                    'series_id'     => $seriesId,
                    'description'   => $r->description,
                    'date_covered'  => $formattedDate,
                    'volume'        => $recVolume,
                    'medium'        => $recMedium,
                    'restriction'   => $recRestriction,
                    'location'      => $r->records_location ?: '—',
                    'frequence_use' => $recFreq,
                    'duplication'   => $recDup,
                    'time_value'    => $r->time_value ?: 'T',
                    'utility'       => $this->formatItemUtility($uRows),
                    'is_batch'      => false,
                    'sub_periods'   => [],
                ];
                $totalItemsCount++;
            } else {
                // Batch Record (Single Batch or Merged Batch from same subject & office)
                $subPeriodItems = [];
                $allStartDates = [];
                $allEndDates = [];

                foreach ($groupPeriods as $gp) {
                    $r = $gp['rec'];
                    $p = $gp['period'];

                    $pStart = $p->date_covered ?? null;
                    $pEnd = $p->date_covered_end ?? null;

                    if (!empty($pStart)) $allStartDates[] = $pStart;
                    if (!empty($pEnd)) $allEndDates[] = $pEnd;

                    $rMedium = !empty($r->records_medium) ? ($mediumsMap[$r->records_medium] ?? (string)$r->records_medium) : '—';
                    $rRestriction = !empty($r->restriction) ? $r->restriction : '—';
                    $rFreq = !empty($r->frequence_use) ? $r->frequence_use : '—';

                    if (!empty($r->duplication_id) && isset($duplications[$r->duplication_id])) {
                        $dupCodes = $duplications[$r->duplication_id]->pluck('office_code')->unique()->values()->all();
                        $rDup = !empty($dupCodes) ? implode(', ', $dupCodes) : '—';
                    } else {
                        $rDup = '—';
                    }

                    $rURows = ($utilities[$r->id] ?? collect())->pluck('utility_name')->all();

                    $subTitle = $this->formatSubPeriodTitle(
                        $primarySubject,
                        $p->description ?? null,
                        $pStart,
                        $pEnd
                    );

                    $subVol = !empty($p->volume) ? $p->volume : (!empty($r->volume) ? $r->volume : '—');

                    $subPeriodItems[] = (object)[
                        'id'            => $r->id . '-sub-' . $p->id,
                        'parent_rec_id' => $r->id,
                        'period_id'     => $p->id,
                        'description'   => $subTitle,
                        'date_covered'  => $this->formatBatchDateRange($pStart, $pEnd, false),
                        'volume'        => $subVol,
                        'medium'        => $rMedium,
                        'restriction'   => $rRestriction,
                        'location'      => $r->records_location ?: '—',
                        'frequence_use' => $rFreq,
                        'duplication'   => $rDup,
                        'time_value'    => $r->time_value ?: 'T',
                        'utility'       => $this->formatItemUtility($rURows),
                        'is_sub_period' => true,
                        'sub_index'     => 1,
                        'raw_sort_date' => $pEnd ?: ($pStart ?: '0000-00-00'),
                    ];
                }

                // Sort sub-periods descending (latest year/date on top, oldest at bottom)
                usort($subPeriodItems, function($a, $b) {
                    return strcmp($b->raw_sort_date, $a->raw_sort_date);
                });

                foreach ($subPeriodItems as $sIdx => $sItem) {
                    $sItem->sub_index = $sIdx + 1;
                }

                $batchStart = !empty($allStartDates) ? min($allStartDates) : null;
                $batchEnd = !empty($allEndDates) ? max($allEndDates) : (!empty($allStartDates) ? max($allStartDates) : null);
                $formattedDate = $this->formatBatchDateRange($batchStart, $batchEnd, true);

                if (!empty($batchStart) && !empty($batchEnd)) {
                    $compiledDates[] = $batchStart . ' - ' . $batchEnd;
                }
                foreach ($groupPeriods as $gp) {
                    $p = $gp['period'];
                    if (!empty($p->date_covered) && !empty($p->date_covered_end)) {
                        $compiledDates[] = $p->date_covered . ' - ' . $p->date_covered_end;
                    } elseif (!empty($p->date_covered)) {
                        $compiledDates[] = $p->date_covered;
                    } elseif (!empty($p->date_covered_end)) {
                        $compiledDates[] = $p->date_covered_end;
                    }
                }

                $subVols = array_filter(array_map(fn($s) => ($s->volume !== '—' ? $s->volume : null), $subPeriodItems));
                $batchActiveVol = $this->compileVolume($subVols);
                $recVolume = $batchActiveVol ?: '—';

                $compiledVols[] = $recVolume;

                $allMediums = array_unique(array_filter(array_map(fn($s) => ($s->medium !== '—' ? $s->medium : null), $subPeriodItems)));
                $parentMedium = !empty($allMediums) ? implode(', ', $allMediums) : '—';
                $compiledMediums[] = $parentMedium;

                $allRestrictions = array_unique(array_filter(array_map(fn($s) => ($s->restriction !== '—' ? $s->restriction : null), $subPeriodItems)));
                $parentRestriction = !empty($allRestrictions) ? implode(', ', $allRestrictions) : '—';
                $compiledRestrictions[] = $parentRestriction;

                $allLocs = array_unique(array_filter(array_map(fn($s) => ($s->location !== '—' ? $s->location : null), $subPeriodItems)));
                $parentLocation = !empty($allLocs) ? implode(', ', $allLocs) : '—';
                $compiledLocs[] = $parentLocation;

                $allFreqs = array_unique(array_filter(array_map(fn($s) => ($s->frequence_use !== '—' ? $s->frequence_use : null), $subPeriodItems)));
                $parentFreq = !empty($allFreqs) ? implode(', ', $allFreqs) : '—';
                $compiledFreqs[] = $parentFreq;

                $allDups = array_unique(array_filter(array_map(fn($s) => ($s->duplication !== '—' ? $s->duplication : null), $subPeriodItems)));
                $parentDup = !empty($allDups) ? implode(', ', $allDups) : '—';
                $compiledDups[] = $parentDup;

                $compiledTimes[] = $firstRec->time_value ?: 'T';

                $allUtils = [];
                foreach ($group as $r) {
                    $uRows = ($utilities[$r->id] ?? collect())->pluck('utility_name')->all();
                    foreach ($uRows as $un) {
                        $compiledUtils[] = $un;
                        $allUtils[] = $un;
                    }
                }
                $parentUtils = array_values(array_unique($allUtils));

                $childItems[] = (object)[
                    'id'            => $firstRec->id,
                    'group_rec_ids' => $group->pluck('id')->all(),
                    'series_id'     => $seriesId,
                    'description'   => $primarySubject,
                    'date_covered'  => $formattedDate,
                    'volume'        => $recVolume,
                    'medium'        => $parentMedium,
                    'restriction'   => $parentRestriction,
                    'location'      => $parentLocation,
                    'frequence_use' => $parentFreq,
                    'duplication'   => $parentDup,
                    'time_value'    => $firstRec->time_value ?: 'T',
                    'utility'       => $this->formatItemUtility($parentUtils),
                    'is_batch'      => true,
                    'sub_periods'   => $subPeriodItems,
                ];
                $totalItemsCount++;
            }
        }

        return $childItems;
    }

    private function compileVolume(array $volumes): string
    {
        $totals = [];
        $unmatched = [];

        foreach ($volumes as $v) {
            $v = trim((string)$v);
            if (empty($v) || $v === '—') continue;

            $matchedAny = false;
            if (preg_match_all('/(\d+(?:\.\d+)?)\s*([a-zA-Z\s\.]+)?/u', $v, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $amount = (float)$m[1];
                    $unit = isset($m[2]) ? trim($m[2]) : '';
                    $unit = trim(preg_replace('/^(and|&|,)\s*/i', '', $unit));
                    $unit = trim(preg_replace('/[,\.]+$/', '', $unit));
                    $unitLower = strtolower($unit);
                    if (str_starts_with($unitLower, 'paper') || str_starts_with($unitLower, 'sheet') || str_starts_with($unitLower, 'page') || $unit === '') {
                        $normUnit = 'papers';
                    } elseif (str_starts_with($unitLower, 'folder')) {
                        $normUnit = 'folders';
                    } elseif (str_starts_with($unitLower, 'box')) {
                        $normUnit = 'boxes';
                    } elseif (str_starts_with($unitLower, 'bundle')) {
                        $normUnit = 'bundles';
                    } elseif (str_starts_with($unitLower, 'cu') || str_contains($unitLower, 'meter') || str_contains($unitLower, 'm.')) {
                        $normUnit = 'cu. m.';
                    } else {
                        $normUnit = $unitLower;
                    }
                    $totals[$normUnit] = ($totals[$normUnit] ?? 0) + $amount;
                    $matchedAny = true;
                }
            }
            if (!$matchedAny) {
                $unmatched[] = $v;
            }
        }

        $compiledParts = [];
        $preferredOrder = ['folders', 'boxes', 'papers', 'bundles', 'cu. m.'];
        foreach ($preferredOrder as $u) {
            if (isset($totals[$u])) {
                $cnt = $totals[$u];
                if ($u === 'folders') {
                    $compiledParts[] = $cnt . ' ' . ($cnt == 1 ? 'folder' : 'folders');
                } elseif ($u === 'papers') {
                    $compiledParts[] = $cnt . ' ' . ($cnt == 1 ? 'paper' : 'papers');
                } elseif ($u === 'boxes') {
                    $compiledParts[] = $cnt . ' ' . ($cnt == 1 ? 'box' : 'boxes');
                } elseif ($u === 'bundles') {
                    $compiledParts[] = $cnt . ' ' . ($cnt == 1 ? 'bundle' : 'bundles');
                } else {
                    $compiledParts[] = $cnt . ' ' . $u;
                }
                unset($totals[$u]);
            }
        }
        foreach ($totals as $u => $cnt) {
            $compiledParts[] = $cnt . ' ' . $u;
        }
        foreach (array_unique($unmatched) as $um) {
            $compiledParts[] = $um;
        }

        return !empty($compiledParts) ? implode(', ', $compiledParts) : '—';
    }

    private function compileLocation(array $locations): string
    {
        $locs = [];
        foreach ($locations as $l) {
            $l = trim((string)$l);
            if (!empty($l) && $l !== '—') {
                $locs[] = $l;
            }
        }
        $unique = array_values(array_unique($locs));
        if (empty($unique)) return '—';

        $hasCabinet = true;
        $cabSub = [];
        foreach ($unique as $item) {
            if (preg_match('/^Cabinet\s+(.+)$/i', $item, $m)) {
                $cabSub[] = $m[1];
            } else {
                $hasCabinet = false;
            }
        }

        if ($hasCabinet && count($cabSub) > 1) {
            return 'Cabinet ' . implode(', ', $cabSub);
        }

        return implode(', ', $unique);
    }

    private function compileTimeValue(array $times): string
    {
        $valid = [];
        foreach ($times as $t) {
            $t = strtoupper(trim((string)$t));
            if (!empty($t) && $t !== '—') {
                $valid[] = $t;
            }
        }
        $unique = array_values(array_unique($valid));
        return !empty($unique) ? implode(', ', $unique) : 'T';
    }

    private function compileUtility(array $utilityNames): string
    {
        $map = [
            'Administrative' => 'A',
            'Archival'       => 'ARC',
            'Fiscal'         => 'F',
            'Legal'          => 'L',
        ];
        $abbrs = [];
        foreach ($utilityNames as $n) {
            $abbrs[] = $map[$n] ?? strtoupper(substr($n, 0, 3));
        }
        $unique = array_values(array_unique($abbrs));
        return !empty($unique) ? implode(', ', $unique) : 'A';
    }

    private function formatItemUtility(array $utilityNames): string
    {
        $map = [
            'Administrative' => 'A - Administrative',
            'Archival'       => 'ARC - ARCHIVAL',
            'Fiscal'         => 'F - FISCAL',
            'Legal'          => 'L - LEGAL',
        ];
        $parts = [];
        foreach ($utilityNames as $n) {
            $parts[] = $map[$n] ?? $n;
        }
        $unique = array_values(array_unique($parts));
        return !empty($unique) ? implode(', ', $unique) : '—';
    }

    private function compileMedium(array $mediums): string
    {
        $valid = [];
        foreach ($mediums as $m) {
            $m = trim((string)$m);
            if (!empty($m) && $m !== '—') {
                $valid[] = $m;
            }
        }
        $unique = array_values(array_unique($valid));
        return !empty($unique) ? implode(', ', $unique) : '—';
    }

    private function compileRestriction(array $restrictions): string
    {
        $valid = [];
        foreach ($restrictions as $r) {
            $r = trim((string)$r);
            if (!empty($r) && $r !== '—') {
                $valid[] = $r;
            }
        }
        $unique = array_values(array_unique($valid));
        return !empty($unique) ? implode(', ', $unique) : '—';
    }

    private function compileFrequency(array $freqs): string
    {
        $valid = [];
        foreach ($freqs as $f) {
            $f = trim((string)$f);
            if (!empty($f) && $f !== '—') {
                $valid[] = $f;
            }
        }
        $unique = array_values(array_unique($valid));
        return !empty($unique) ? implode(', ', $unique) : '—';
    }

    private function compileDuplication(array $dups): string
    {
        $valid = [];
        foreach ($dups as $d) {
            $d = trim((string)$d);
            if (!empty($d) && $d !== '—') {
                $valid[] = $d;
            }
        }
        $unique = array_values(array_unique($valid));
        return !empty($unique) ? implode(', ', $unique) : '—';
    }

    private function formatItemDate(string $rawDate): string
    {
        $rawDate = trim($rawDate);
        if (empty($rawDate) || $rawDate === '—') return '—';

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rawDate, $m)) {
            return Carbon::parse($rawDate)->format('j_F_Y');
        }
        return $rawDate;
    }

    public function cleanVal($val): string
    {
        if ($val === null) return '';
        $str = trim((string)$val);
        if ($str === '—' || $str === '-' || $str === 'N/A' || $str === 'None' || $str === 'null') {
            return '';
        }
        return $str;
    }

    public function with(): array
    {
        $user = Auth::user();
        $perms = $user?->permissions;
        $isSadm = (bool)($perms->is_sadm ?? false);
        $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
        $userOfficeName = $user?->details?->office?->office_name ?? null;

        $officesTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $officesList = DB::table($officesTable)->where('is_active', true)->orderBy('office_name')->get();

        // Effective office: locks strictly to user's office if not sadm; if sadm, respects officeFilter
        $effectiveOffice = ($isSadm && !empty($this->officeFilter)) ? $this->officeFilter : $userOfficeCode;

        // Synchronize retention expiration so expired records are automatically transferred to NAP Form 3
        \App\Services\RdpRetentionService::syncTransferredRecords();

        // 1. Fetch Records added or registered on the user's office (excluding records transferred to NAP Form 3)
        $recordsQuery = DB::table('rdp_record')
            ->where('is_draft', false)
            ->where('is_active', true)
            ->where(function($q) {
                $q->where('rdp_record.transferred_to_nap3', false)
                  ->orWhere('rdp_record.ispartof_batch', true);
            });

        if ($effectiveOffice) {
            $recordsQuery->where(function($q) use ($effectiveOffice) {
                $q->where('rdp_record.office_own', $effectiveOffice)
                  ->orWhereExists(function($sub) use ($effectiveOffice) {
                      $sub->select(DB::raw(1))
                          ->from('rdp_duplication_section')
                          ->whereColumn('rdp_duplication_section.dup_id_manager', 'rdp_record.duplication_id')
                          ->where('rdp_duplication_section.office_code', $effectiveOffice);
                  });
            });
        }

        if (!empty($this->search)) {
            $this->applySearchFilter($recordsQuery, [
                'description',
                'volume',
                'records_location',
            ]);
        }

        // Sorting records chronologically by which one was added first (id ASC)
        $allRecords = $recordsQuery->orderBy('id', 'asc')->get();
        $recordIds = $allRecords->pluck('id')->all();

        if ($allRecords->isEmpty()) {
            return [
                'hierarchyTree'    => [],
                'officesList'      => $officesList,
                'totalItemsCount'  => 0,
                'permanentCount'   => 0,
                'temporaryCount'   => 0,
                'userOfficeCode'   => $userOfficeCode,
                'userOfficeName'   => $userOfficeName,
                'isSadm'           => $isSadm,
                'mediaList'        => DB::table('rdp_recorded_value')->orderBy('medium_name', 'asc')->get(),
                'restrictionsList' => DB::table('rdp_restriction_type')->orderBy('restriction_value', 'asc')->get(),
                'frequenciesList'  => DB::table('rdp_frequence_use')->orderBy('freq_type', 'asc')->get(),
                'timeValuesList'   => DB::table('rdp_time_value')->orderBy('char_value', 'asc')->get(),
                'utilityValuesList'=> DB::table('rdp_utility_medium')->orderBy('utility_name', 'asc')->get(),
            ];
        }

        // Periods covered
        $periods = DB::table('rdp_period_covered')
            ->whereIn('period_owner', $recordIds)
            ->orderBy('id', 'asc')
            ->get()
            ->groupBy('period_owner');

        // Utilities
        $utilities = DB::table('rdp_utility_manager')
            ->join('rdp_utility_medium', 'rdp_utility_manager.utility_medium', '=', 'rdp_utility_medium.id')
            ->whereIn('rdp_utility_manager.record_holder', $recordIds)
            ->where('rdp_utility_manager.is_active', true)
            ->select('rdp_utility_manager.record_holder', 'rdp_utility_medium.utility_name')
            ->get()
            ->groupBy('record_holder');

        // Lookups for Medium and Duplications
        $mediumsMap = DB::table('rdp_recorded_value')->pluck('medium_name', 'id')->all();

        $dupHolders = $allRecords->pluck('duplication_id')->filter()->unique()->all();
        $duplications = empty($dupHolders) ? collect() : DB::table('rdp_duplication_section')
            ->whereIn('dup_id_manager', $dupHolders)
            ->select('dup_id_manager', 'office_code')
            ->get()
            ->groupBy('dup_id_manager');

        $recordsBySeries = $allRecords->groupBy('record_series_id');
        $usedSeriesIds = $allRecords->pluck('record_series_id')->unique()->all();

        // 2. Fetch only the Record Series that are actually used
        $directSeries = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series_type', 'rdp_record_series.series_type', '=', 'rdp_record_series_type.id')
            ->select([
                'rdp_record_series.*',
                'rdp_record_series_type.shorted_type',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
            ])
            ->whereIn('rdp_record_series.id', $usedSeriesIds)
            ->where('rdp_record_series.is_active', true)
            ->get();

        $neededParentIds = $directSeries->pluck('parent_id')->filter()->unique()->all();

        $parentSeries = empty($neededParentIds) ? collect() : DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series_type', 'rdp_record_series.series_type', '=', 'rdp_record_series_type.id')
            ->leftJoin($officesTable . ' as office', 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
            ->select([
                'rdp_record_series.*',
                'rdp_record_series_type.shorted_type',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
                'office.office_name as recorded_office_name',
            ])
            ->whereIn('rdp_record_series.id', $neededParentIds)
            ->where('rdp_record_series.is_active', true)
            ->get();

        // All roots: parent series of sub-series + direct series that are roots (parent_id IS NULL)
        $allRoots = $parentSeries->concat($directSeries->whereNull('parent_id'))->unique('id');
        $subSeriesByParent = $directSeries->whereNotNull('parent_id')->groupBy('parent_id');

        // 3. Sorting of Record Series based on which one is added first:
        // For each root, determine the earliest record ID added (under direct root or its sub-series)
        $sortedRoots = $allRoots->sortBy(function($root) use ($subSeriesByParent, $recordsBySeries) {
            $minId = PHP_INT_MAX;
            if (isset($recordsBySeries[$root->id])) {
                $minId = min($minId, $recordsBySeries[$root->id]->min('id'));
            }
            if (isset($subSeriesByParent[$root->id])) {
                foreach ($subSeriesByParent[$root->id] as $sub) {
                    if (isset($recordsBySeries[$sub->id])) {
                        $minId = min($minId, $recordsBySeries[$sub->id]->min('id'));
                    }
                }
            }
            return $minId;
        })->values();

        // 4. Assemble the Hierarchical Tree
        $tree = [];
        $totalItemsCount = 0;
        $permanentCount = 0;
        $temporaryCount = 0;

        foreach ($sortedRoots as $root) {
            $rootNode = (object)[
                'id'            => $root->id,
                'item_number'   => $root->item_number,
                'series_title'  => $root->series_title,
                'shorted_type'  => $root->shorted_type,
                'is_verified'   => $root->is_verified,
                'remarks'       => $root->remarks ?? '',
                'office_name'   => $root->recorded_office_name ?? $root->recorded_at_office,
                'sub_series'    => [],
                'direct_records'=> [],
                'has_children'  => false,
            ];

            $subs = $subSeriesByParent[$root->id] ?? collect();

            if ($subs->isNotEmpty()) {
                $rootNode->has_children = true;

                // Sort sub-series based on which one was added first
                $sortedSubs = $subs->sortBy(function($sub) use ($recordsBySeries) {
                    return isset($recordsBySeries[$sub->id]) ? $recordsBySeries[$sub->id]->min('id') : PHP_INT_MAX;
                })->values();

                foreach ($sortedSubs as $sub) {
                    $subRecs = $recordsBySeries[$sub->id] ?? collect();
                    if ($subRecs->isEmpty()) continue; // Only show if used!

                    $isPerm = (bool)($sub->is_retention_period_permanent) 
                              || strtolower(trim($sub->total_period ?? '')) === 'permanent'
                              || (empty($sub->total_period) && ((bool)($root->is_retention_period_permanent) || strtolower(trim($root->total_period ?? '')) === 'permanent'));

                    $effActive = $sub->active_period ?: ($root->active_period ?: '—');
                    $effStorage = $sub->storage_period ?: ($root->storage_period ?: '');
                    $effTotal = $sub->total_period ?: ($root->total_period ?: '—');

                    $compiledDates = [];
                    $compiledVols = [];
                    $compiledMediums = [];
                    $compiledRestrictions = [];
                    $compiledLocs = [];
                    $compiledFreqs = [];
                    $compiledDups = [];
                    $compiledTimes = [];
                    $compiledUtils = [];
                    $childItems = $this->processSeriesRecords(
                        $subRecs,
                        $sub->id,
                        $effTotal,
                        $effActive,
                        $effStorage,
                        $isPerm,
                        $periods,
                        $utilities,
                        $mediumsMap,
                        $duplications,
                        $compiledDates,
                        $compiledVols,
                        $compiledMediums,
                        $compiledRestrictions,
                        $compiledLocs,
                        $compiledFreqs,
                        $compiledDups,
                        $compiledTimes,
                        $compiledUtils,
                        $totalItemsCount
                    );

                    if (empty($childItems)) continue;

                    if ($isPerm) $permanentCount++; else $temporaryCount++;

                    $rootNode->sub_series[] = (object)[
                        'id'                   => $sub->id,
                        'series_title'         => $sub->series_title,
                        'shorted_type'         => $sub->shorted_type ?: $root->shorted_type,
                        'compiled_period'      => $this->compilePeriodCovered($compiledDates),
                        'compiled_volume'      => $this->compileVolume($compiledVols),
                        'compiled_medium'      => $this->compileMedium($compiledMediums),
                        'compiled_restriction' => $this->compileRestriction($compiledRestrictions),
                        'compiled_location'    => $this->compileLocation($compiledLocs),
                        'compiled_freq'        => $this->compileFrequency($compiledFreqs),
                        'compiled_duplication' => $this->compileDuplication($compiledDups),
                        'compiled_time'        => $this->compileTimeValue($compiledTimes),
                        'compiled_util'        => $this->compileUtility($compiledUtils),
                        'active_period'        => $isPerm ? 'PERMANENT' : $effActive,
                        'storage_period'       => $isPerm ? '' : $effStorage,
                        'total_period'         => $isPerm ? 'PERMANENT' : $effTotal,
                        'is_permanent'         => $isPerm,
                        'remarks'              => $sub->remarks ?: ($root->remarks ?: ''),
                        'records'              => $childItems,
                        'record_ids'           => array_column($childItems, 'id'),
                    ];
                }
            } else {
                // Direct records under root
                $directRecs = $recordsBySeries[$root->id] ?? collect();
                if ($directRecs->isEmpty()) continue; // Only show if used!

                $isPerm = (bool)($root->is_retention_period_permanent) || strtolower(trim($root->total_period ?? '')) === 'permanent';
                $effActive = $root->active_period ?: '—';
                $effStorage = $root->storage_period ?: '';
                $effTotal = $root->total_period ?: '—';

                $compiledDates = [];
                $compiledVols = [];
                $compiledMediums = [];
                $compiledRestrictions = [];
                $compiledLocs = [];
                $compiledFreqs = [];
                $compiledDups = [];
                $compiledTimes = [];
                $compiledUtils = [];
                $childItems = $this->processSeriesRecords(
                    $directRecs,
                    $root->id,
                    $effTotal,
                    $effActive,
                    $effStorage,
                    $isPerm,
                    $periods,
                    $utilities,
                    $mediumsMap,
                    $duplications,
                    $compiledDates,
                    $compiledVols,
                    $compiledMediums,
                    $compiledRestrictions,
                    $compiledLocs,
                    $compiledFreqs,
                    $compiledDups,
                    $compiledTimes,
                    $compiledUtils,
                    $totalItemsCount
                );

                if (empty($childItems)) continue;

                if ($isPerm) $permanentCount++; else $temporaryCount++;

                $rootNode->compiled_period      = $this->compilePeriodCovered($compiledDates);
                $rootNode->compiled_volume      = $this->compileVolume($compiledVols);
                $rootNode->compiled_medium      = $this->compileMedium($compiledMediums);
                $rootNode->compiled_restriction = $this->compileRestriction($compiledRestrictions);
                $rootNode->compiled_location    = $this->compileLocation($compiledLocs);
                $rootNode->compiled_freq        = $this->compileFrequency($compiledFreqs);
                $rootNode->compiled_duplication = $this->compileDuplication($compiledDups);
                $rootNode->compiled_time        = $this->compileTimeValue($compiledTimes);
                $rootNode->compiled_util        = $this->compileUtility($compiledUtils);
                $rootNode->active_period        = $isPerm ? 'PERMANENT' : ($root->active_period ?: '—');
                $rootNode->storage_period       = $isPerm ? '' : ($root->storage_period ?: '');
                $rootNode->total_period         = $isPerm ? 'PERMANENT' : ($root->total_period ?: '—');
                $rootNode->is_permanent         = $isPerm;
                $rootNode->remarks              = $root->remarks ?? '';
                $rootNode->direct_records       = $childItems;
                $rootNode->record_ids           = array_column($childItems, 'id');
            }

            // Retention filter check
            if ($this->retentionFilter === 'permanent') {
                if ($rootNode->has_children) {
                    $rootNode->sub_series = array_values(array_filter($rootNode->sub_series, fn($s) => $s->is_permanent));
                    if (empty($rootNode->sub_series)) continue;
                } else {
                    if (!$rootNode->is_permanent) continue;
                }
            } elseif ($this->retentionFilter === 'temporary') {
                if ($rootNode->has_children) {
                    $rootNode->sub_series = array_values(array_filter($rootNode->sub_series, fn($s) => !$s->is_permanent));
                    if (empty($rootNode->sub_series)) continue;
                } else {
                    if ($rootNode->is_permanent) continue;
                }
            }

            // Only add root to tree if it actually has visible records
            if ($rootNode->has_children && empty($rootNode->sub_series)) {
                continue;
            }
            if (!$rootNode->has_children && empty($rootNode->direct_records)) {
                continue;
            }

            $tree[] = $rootNode;
        }

        return [
            'hierarchyTree'    => $tree,
            'officesList'      => $officesList,
            'totalItemsCount'  => $totalItemsCount,
            'permanentCount'   => $permanentCount,
            'temporaryCount'   => $temporaryCount,
            'userOfficeCode'   => $userOfficeCode,
            'userOfficeName'   => $userOfficeName,
            'isSadm'           => $isSadm,
            'mediaList'        => DB::table('rdp_recorded_value')->orderBy('medium_name', 'asc')->get(),
            'restrictionsList' => DB::table('rdp_restriction_type')->orderBy('restriction_value', 'asc')->get(),
            'frequenciesList'  => DB::table('rdp_frequence_use')->orderBy('freq_type', 'asc')->get(),
            'timeValuesList'   => DB::table('rdp_time_value')->orderBy('char_value', 'asc')->get(),
            'utilityValuesList'=> DB::table('rdp_utility_medium')->orderBy('utility_name', 'asc')->get(),
            'cleanVal'         => function($val) {
                if ($val === null) return '';
                $str = trim((string)$val);
                if ($str === '—' || $str === '-' || $str === 'N/A' || $str === 'None' || $str === 'null') {
                    return '';
                }
                return $str;
            },
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/console.css'])
@endpush

<div class="nap-page-container" style="padding: 24px; min-height: 100vh; font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <style>
        .nap-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); margin-bottom: 24px; }
        .nap-table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
        .nap-table th { background: #f8fafc; padding: 10px 12px; font-weight: 700; color: #475569; border: 1px solid #cbd5e1; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.5px; }
        .nap-table td { padding: 9px 12px; border: 1px solid #e2e8f0; vertical-align: middle; color: #0f172a; }
        .nap-btn { padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; border: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        .nap-btn-primary { background: #2563eb; color: #ffffff; }
        .nap-btn-primary:hover { background: #1d4ed8; }
        .nap-btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .nap-btn-secondary:hover { background: #e2e8f0; }

        .nap-page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .nap-page-title { font-size: 22px; font-weight: 800; color: #0f172a; margin: 0; }
        .nap-page-subtitle { font-size: 13.5px; color: #64748b; margin: 4px 0 0 0; }

        .nap-stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .nap-stat-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; align-items: center; gap: 16px; }
        .nap-stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800; }
        .nap-stat-icon-blue { background: #eff6ff; color: #2563eb; }
        .nap-stat-icon-green { background: #f0fdf4; color: #16a34a; }
        .nap-stat-icon-orange { background: #fff7ed; color: #ea580c; }
        .nap-stat-value { font-size: 22px; font-weight: 800; color: #0f172a; }
        .nap-stat-label { font-size: 12.5px; font-weight: 600; color: #64748b; }

        .nap-input { padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; background: #ffffff; color: #0f172a; }
        .nap-search-input { min-width: 280px; }
        .nap-select-input { font-weight: 600; }

        .nap-form-control {
            width: 100%;
            height: 40px;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
            color: #0f172a;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .nap-form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .nap-form-control:disabled,
        .nap-form-control[readonly] {
            background-color: #f8fafc !important;
            border-color: #e2e8f0 !important;
            color: #64748b !important;
            cursor: not-allowed !important;
        }
        textarea.nap-form-control {
            height: auto;
            min-height: 60px;
            resize: vertical;
        }
        select.nap-form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.6rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.2rem;
        }

        /* Row styles */
        .root-series-row { background: #f8fafc; font-weight: 800; border-top: 2px solid #cbd5e1 !important; border-bottom: 2px solid #cbd5e1 !important; }
        .sub-series-row { background: #ffffff; font-weight: 700; border-bottom: 1px solid #cbd5e1; }
        .record-item-row { background: #fafafa; font-size: 12.5px; transition: background 0.15s; }
        .record-item-row:hover { background: #f1f5f9; }
        .record-item-row.is-selected { background: #eff6ff !important; }

        .corner-symbol { font-family: ui-monospace, SFMono-Regular, monospace; font-size: 14px; color: #2563eb; font-weight: 900; margin-right: 6px; }
        .sub-branch-line { font-family: ui-monospace, SFMono-Regular, monospace; color: #94a3b8; margin-right: 8px; font-weight: 700; }
        .nap-chevron-btn {
            background: transparent;
            border: none;
            padding: 2px;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            transition: transform 0.2s ease, background-color 0.15s ease, color 0.15s ease;
            width: 20px;
            height: 20px;
        }
        .nap-chevron-btn:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        /* Print Modal & Sheet Styles */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .modal-content { background: #94a3b8; width: 100%; max-width: 1200px; max-height: 94vh; border-radius: 14px; overflow-y: auto; box-shadow: 0 20px 40px rgba(0,0,0,0.3); padding: 24px; display: flex; flex-direction: column; gap: 20px; }
        .modal-dialog { background: #ffffff; width: 100%; max-width: 600px; border-radius: 14px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); padding: 24px; }

        .print-sheet {
            width: 100%;
            background: #ffffff;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            padding: 24px 28px;
            box-sizing: border-box;
            color: #000000;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8.5px;
            margin-bottom: 24px;
        }

        .print-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000000;
            font-size: 8px;
            margin-top: 0;
            background: #ffffff;
        }

        .print-table th {
            border: 1px solid #000000;
            padding: 4px 2px;
            background: #ffffff;
            text-align: center;
            font-weight: bold;
            font-size: 7px;
            vertical-align: middle;
            color: #000000;
        }

        .print-table td {
            border-left: 1px solid #000000;
            border-right: 1px solid #000000;
            border-top: none;
            border-bottom: none;
            padding: 3px 4px;
            vertical-align: top;
            font-size: 7px;
            color: #000000;
            background: #ffffff;
        }

        @media print {
            body { background: #ffffff !important; margin: 0 !important; padding: 0 !important; }
            header, #navigation, .no-print, .nap-page-header, .nap-stat-grid, .nap-card, footer { display: none !important; }
            .modal-overlay { position: static !important; background: none !important; padding: 0 !important; display: block !important; }
            .modal-content { background: none !important; max-width: 100% !important; max-height: none !important; padding: 0 !important; box-shadow: none !important; overflow: visible !important; }
            .print-sheet { box-shadow: none !important; padding: 0 !important; width: 100% !important; margin-bottom: 0 !important; page-break-after: always; break-after: page; }
            .print-sheet:last-child { page-break-after: auto; break-after: auto; }
            @page { size: legal landscape; margin: 0.4in; }
        }
    </style>

    <!-- Header Section -->
    <div class="nap-page-header">
        <div>
            <h1 class="nap-page-title">NAP Form 1: Records Inventory and Appraisal</h1>
            <p class="nap-page-subtitle">Hierarchical inventory appraisal matrix and disposition schedule for National Archives of the Philippines.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" wire:click="openPrintModal" class="nap-btn nap-btn-secondary">
                Print Preview
            </button>
            <button type="button" wire:click="openClusterModal" class="nap-btn nap-btn-primary" {{ empty($selectedIds) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' }}>
                Create Form ({{ count($selectedIds) }})
            </button>
        </div>
    </div>

    <!-- Alert Messages -->
    @if($successMessage)
        <div style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; display: flex; justify-content: space-between; align-items: center;">
            <span>{{ $successMessage }}</span>
            <button type="button" wire:click="$set('successMessage', '')" style="background:none;border:none;cursor:pointer;color:#065f46;font-size:16px;">&times;</button>
        </div>
    @endif

    @if($errorMessage)
        <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; display: flex; justify-content: space-between; align-items: center;">
            <span>{{ $errorMessage }}</span>
            <button type="button" wire:click="$set('errorMessage', '')" style="background:none;border:none;cursor:pointer;color:#991b1b;font-size:16px;">&times;</button>
        </div>
    @endif

    <!-- Filters & Table Card -->
    <div class="nap-card" x-data="{
        collapsedSubjects: {},
        allSubjectsCollapsed: true,
        collapsedRoots: {},
        allRootsCollapsed: false,
        
        toggleSubjects(key) {
            this.collapsedSubjects[key] = !this.isSubjectsCollapsed(key);
        },
        isSubjectsCollapsed(key) {
            if (this.collapsedSubjects[key] !== undefined) {
                return this.collapsedSubjects[key];
            }
            return this.allSubjectsCollapsed;
        },
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
            this.allSubjectsCollapsed = true;
            this.allRootsCollapsed = false;
            this.collapsedSubjects = {};
            this.collapsedRoots = {};
        },
        expandAll() {
            this.allSubjectsCollapsed = false;
            this.allRootsCollapsed = false;
            this.collapsedSubjects = {};
            this.collapsedRoots = {};
        }
    }">
        <div style="display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 20px;">
            <div style="display: flex; gap: 12px; flex-wrap: wrap; flex: 1; align-items: center;">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search subject, volume, location..." class="nap-input nap-search-input">
                
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
                        <span>Office: {{ $userOfficeCode ?? 'N/A' }}</span>
                    </div>
                @endif

                @if($search || $retentionFilter || ($isSadm && !empty($officeFilter) && $officeFilter !== $userOfficeCode) || count($selectedIds) > 0)
                    <button type="button" wire:click="clearFilters" class="nap-btn nap-btn-secondary">
                        Reset Filters
                    </button>
                @endif

                <div style="display: inline-flex; gap: 8px; align-items: center; margin-left: 4px;">
                    <button type="button" @click="expandAll()" class="nap-btn nap-btn-secondary" style="padding: 7px 12px; font-size: 12px;" title="Expand all series to show subjects">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                        Expand All
                    </button>
                    <button type="button" @click="collapseAll()" class="nap-btn nap-btn-secondary" style="padding: 7px 12px; font-size: 12px;" title="Collapse all series to show only compilation totals">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transform: rotate(-90deg);">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                        Collapse All
                    </button>
                </div>
            </div>

            @if(count($selectedIds) > 0)
                <div style="font-size: 13px; font-weight: 700; color: #2563eb;">
                    {{ count($selectedIds) }} records selected
                </div>
            @endif
        </div>

        <!-- MAIN HIERARCHICAL NAP FORM 1 TABLE -->
        <div style="overflow-x: auto;">
            <table class="nap-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="width: 56px; text-align: center; padding: 8px 4px;">
                            <div style="display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                <input type="checkbox" wire:model.live="selectAll" style="width: 15px; height: 15px; cursor: pointer; accent-color: #2563eb;" title="Select / Deselect All">
                                <span style="width: 20px; height: 20px; display: inline-block;"></span>
                            </div>
                        </th>
                        <th rowspan="2" style="min-width: 220px;">RECORD SERIES TITLE & DESCRIPTION</th>
                        <th rowspan="2" style="width: 120px; text-align: center;">PERIOD COVERED</th>
                        <th rowspan="2" style="width: 90px; text-align: center;">VOLUME</th>
                        <th rowspan="2" style="width: 85px; text-align: center;">MEDIUM</th>
                        <th rowspan="2" style="width: 95px; text-align: center;">RESTRICTIONS</th>
                        <th rowspan="2" style="width: 100px; text-align: center;">LOCATION</th>
                        <th rowspan="2" style="width: 85px; text-align: center;">FREQ. OF USE</th>
                        <th rowspan="2" style="width: 90px; text-align: center;">DUPLICATION</th>
                        <th rowspan="2" style="width: 50px; text-align: center;">TIME</th>
                        <th rowspan="2" style="width: 65px; text-align: center;">UTIL</th>
                        <th colspan="3" style="text-align: center; border-bottom: 1px solid #cbd5e1;">RETENTION PERIOD</th>
                        <th rowspan="2" style="width: 130px; text-align: left;">DISPOSITION PROVISION</th>
                        <th rowspan="2" style="width: 80px; text-align: right;">ACTION</th>
                    </tr>
                    <tr>
                        <th style="width: 65px; text-align: center; padding: 6px 4px; font-size: 11px;">ACTIVE</th>
                        <th style="width: 65px; text-align: center; padding: 6px 4px; font-size: 11px;">STORAGE</th>
                        <th style="width: 65px; text-align: center; padding: 6px 4px; font-size: 11px;">TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($hierarchyTree as $root)
                        <!-- ROOT SERIES ROW -->
                        <tr class="root-series-row">
                            <td style="text-align: center; padding: 6px 4px; white-space: nowrap;">
                                <div style="display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                    @if(!$root->has_children && !empty($root->record_ids))
                                        @php
                                            $strIds = array_map('strval', $root->record_ids);
                                            $isAllSelected = !empty($strIds) && count(array_intersect($strIds, $selectedIds)) === count($strIds);
                                        @endphp
                                        <input type="checkbox" wire:click="toggleSeriesSelection({{ $root->id }}, {{ json_encode($root->record_ids) }})" {{ $isAllSelected ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #2563eb;" title="Select record series">
                                    @elseif($root->has_children)
                                        @php
                                            $allRootChildIds = [];
                                            foreach ($root->sub_series as $s) {
                                                foreach ($s->record_ids as $rid) {
                                                    $allRootChildIds[] = $rid;
                                                }
                                            }
                                            $strRootIds = array_map('strval', $allRootChildIds);
                                            $isRootChecked = !empty($strRootIds) && count(array_intersect($strRootIds, $selectedIds)) === count($strRootIds);
                                        @endphp
                                        @if(!empty($allRootChildIds))
                                            <input type="checkbox" wire:click="toggleSeriesSelection({{ $root->id }}, {{ json_encode($allRootChildIds) }})" {{ $isRootChecked ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #2563eb;" title="Select record series group">
                                        @else
                                            <span style="color: #94a3b8; font-size: 11px; width: 15px; display: inline-block; text-align: center;">—</span>
                                        @endif
                                    @else
                                        <span style="color: #94a3b8; font-size: 11px; width: 15px; display: inline-block; text-align: center;">—</span>
                                    @endif

                                    @if(!$root->has_children)
                                        @if(count($root->direct_records) > 0)
                                            <button type="button" 
                                                    @click.stop="toggleSubjects('root-{{ $root->id }}')" 
                                                    class="nap-chevron-btn"
                                                    :style="isSubjectsCollapsed('root-{{ $root->id }}') ? 'transform: rotate(-90deg);' : 'transform: rotate(0deg);'"
                                                    title="Toggle subjects">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <polyline points="6 9 12 15 18 9"></polyline>
                                                </svg>
                                            </button>
                                        @else
                                            <span style="width: 20px; height: 20px; display: inline-block;"></span>
                                        @endif
                                    @else
                                        <button type="button" 
                                                @click.stop="toggleRoot('root-{{ $root->id }}')" 
                                                class="nap-chevron-btn"
                                                :style="isRootCollapsed('root-{{ $root->id }}') ? 'transform: rotate(-90deg);' : 'transform: rotate(0deg);'"
                                                title="Toggle sub-series group">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="6 9 12 15 18 9"></polyline>
                                            </svg>
                                        </button>
                                    @endif
                                </div>
                            </td>
                            <td style="font-weight: 800; font-size: 13.5px; color: #0f172a; letter-spacing: 0.3px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    @if(!$root->has_children)
                                        <span @click="toggleSubjects('root-{{ $root->id }}')" style="cursor: pointer;" title="Click to hide/unhide subjects">{{ $root->series_title }}</span>
                                        @if(count($root->direct_records) > 0)
                                            <span style="font-size: 11px; font-weight: 600; color: #64748b;">({{ count($root->direct_records) }})</span>
                                        @endif
                                    @else
                                        <span @click="toggleRoot('root-{{ $root->id }}')" style="cursor: pointer;" title="Click to hide/unhide group">{{ $root->series_title }}</span>
                                        <span style="font-size: 11px; font-weight: 600; color: #64748b;">({{ count($root->sub_series) }} sub)</span>
                                    @endif
                                    @if($root->shorted_type)
                                        <span style="font-size: 11px; padding: 1px 6px; border-radius: 4px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-weight: 700;">
                                            {{ $root->shorted_type }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            @if(!$root->has_children)
                                <!-- Direct compilation on root row if no sub-series -->
                                <td style="text-align: center; font-weight: 600; color: #334155; font-size: 12px;">{{ $root->compiled_period }}</td>
                                <td style="text-align: center; font-weight: 600; color: #334155; font-size: 12px;">{{ $root->compiled_volume }}</td>
                                <td style="text-align: center; font-size: 12px; color: #334155;">{{ $root->compiled_medium }}</td>
                                <td style="text-align: center; font-size: 12px; color: #334155;">{{ $root->compiled_restriction }}</td>
                                <td style="text-align: center; font-weight: 600; color: #334155; font-size: 12px;">{{ $root->compiled_location }}</td>
                                <td style="text-align: center; font-size: 12px; color: #334155;">{{ $root->compiled_freq }}</td>
                                <td style="text-align: center; font-size: 12px; color: #334155;">{{ $root->compiled_duplication }}</td>
                                <td style="text-align: center; font-weight: 800; color: #1e40af;">{{ $root->compiled_time }}</td>
                                <td style="text-align: center; font-weight: 700; color: #334155; font-size: 11.5px;">{{ $root->compiled_util }}</td>
                                @if($root->is_permanent)
                                    <td colspan="3" style="text-align: center; font-weight: 800; color: #dc2626; background: #fef2f2;">PERMANENT</td>
                                @else
                                    <td style="text-align: center; font-size: 12px; font-weight: 600;">{{ $root->active_period }}</td>
                                    <td style="text-align: center; font-size: 12px; font-weight: 600;">{{ $root->storage_period ?: '—' }}</td>
                                    <td style="text-align: center; font-size: 12px; font-weight: 800;">{{ $root->total_period }}</td>
                                @endif
                                <td style="font-size: 12px; color: #334155;">{{ $root->remarks ?: '—' }}</td>
                            @else
                                <!-- Blank summary columns for parent header row when children exist -->
                                <td colspan="13" style="background: #f8fafc;"></td>
                            @endif
                            <td style="text-align: right; white-space: nowrap;">
                                <span style="color: #94a3b8; font-size: 11px;">—</span>
                            </td>
                        </tr>

                        <!-- SUB-SERIES ROWS (IF ANY) -->
                        @if($root->has_children)
                            @foreach($root->sub_series as $sub)
                                <tr class="sub-series-row" x-show="!isRootCollapsed('root-{{ $root->id }}')">
                                    <td style="text-align: center; padding: 6px 4px; white-space: nowrap;">
                                        <div style="display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                            @if(!empty($sub->record_ids))
                                                @php
                                                    $strIds = array_map('strval', $sub->record_ids);
                                                    $isAllSelected = !empty($strIds) && count(array_intersect($strIds, $selectedIds)) === count($strIds);
                                                @endphp
                                                <input type="checkbox" wire:click="toggleSeriesSelection({{ $sub->id }}, {{ json_encode($sub->record_ids) }})" {{ $isAllSelected ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #2563eb;" title="Select sub-series">
                                            @else
                                                <span style="color: #94a3b8; font-size: 11px; width: 15px; display: inline-block; text-align: center;">—</span>
                                            @endif

                                            @if(count($sub->records) > 0)
                                                <button type="button" 
                                                        @click.stop="toggleSubjects('sub-{{ $sub->id }}')" 
                                                        class="nap-chevron-btn"
                                                        :style="isSubjectsCollapsed('sub-{{ $sub->id }}') ? 'transform: rotate(-90deg);' : 'transform: rotate(0deg);'"
                                                        title="Toggle subjects">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="6 9 12 15 18 9"></polyline>
                                                    </svg>
                                                </button>
                                            @else
                                                <span style="width: 20px; height: 20px; display: inline-block;"></span>
                                            @endif
                                        </div>
                                    </td>
                                    <td style="padding-left: 20px; font-weight: 700; color: #0f172a;">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <span class="corner-symbol">└</span>
                                            <span @click="toggleSubjects('sub-{{ $sub->id }}')" style="cursor: pointer;" title="Click to hide/unhide subjects">{{ $sub->series_title }}</span>
                                            @if(count($sub->records) > 0)
                                                <span style="font-size: 11px; font-weight: 600; color: #64748b;">({{ count($sub->records) }})</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td style="text-align: center; font-weight: 600; color: #1e293b; font-size: 12px;">{{ $sub->compiled_period }}</td>
                                    <td style="text-align: center; font-weight: 600; color: #1e293b; font-size: 12px;">{{ $sub->compiled_volume }}</td>
                                    <td style="text-align: center; font-size: 12px; color: #1e293b;">{{ $sub->compiled_medium }}</td>
                                    <td style="text-align: center; font-size: 12px; color: #1e293b;">{{ $sub->compiled_restriction }}</td>
                                    <td style="text-align: center; font-weight: 600; color: #1e293b; font-size: 12px;">{{ $sub->compiled_location }}</td>
                                    <td style="text-align: center; font-size: 12px; color: #1e293b;">{{ $sub->compiled_freq }}</td>
                                    <td style="text-align: center; font-size: 12px; color: #1e293b;">{{ $sub->compiled_duplication }}</td>
                                    <td style="text-align: center; font-weight: 800; color: #1e40af;">{{ $sub->compiled_time }}</td>
                                    <td style="text-align: center; font-weight: 700; color: #334155; font-size: 11.5px;">{{ $sub->compiled_util }}</td>
                                    @if($sub->is_permanent)
                                        <td colspan="3" style="text-align: center; font-weight: 800; color: #dc2626; background: #fef2f2;">PERMANENT</td>
                                    @else
                                        <td style="text-align: center; font-size: 12px; font-weight: 600;">{{ $sub->active_period }}</td>
                                        <td style="text-align: center; font-size: 12px; font-weight: 600;">{{ $sub->storage_period ?: '—' }}</td>
                                        <td style="text-align: center; font-size: 12px; font-weight: 800;">{{ $sub->total_period }}</td>
                                    @endif
                                    <td style="font-size: 12px; color: #334155;">{{ $sub->remarks ?: ($root->remarks ?: '—') }}</td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <span style="color: #94a3b8; font-size: 11px;">—</span>
                                    </td>
                                </tr>

                                <!-- CHILD RECORDS (SUBJECTS) UNDER THIS SUB-SERIES -->
                                @foreach($sub->records as $rec)
                                    @php
                                        $recIdStr = (string)$rec->id;
                                        $isSelected = in_array($recIdStr, $selectedIds);
                                    @endphp
                                    <tr class="record-item-row {{ $isSelected ? 'is-selected' : '' }}" x-show="!isRootCollapsed('root-{{ $root->id }}') && !isSubjectsCollapsed('sub-{{ $sub->id }}')">
                                        <td style="text-align: center; padding: 6px 4px; white-space: nowrap;">
                                            <div style="display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                                <span style="width: 15px; display: inline-block;"></span>
                                                <span style="width: 20px; height: 20px; display: inline-block;"></span>
                                            </div>
                                        </td>
                                        <td style="padding-left: 42px;">
                                            <span class="sub-branch-line">│</span>
                                            <span style="font-weight: 600; color: #1e293b;">{{ $rec->description }}</span>
                                        </td>
                                        <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->date_covered }}</td>
                                        <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->volume }}</td>
                                        <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->medium }}</td>
                                        <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->restriction }}</td>
                                        <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->location }}</td>
                                        <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->frequence_use }}</td>
                                        <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->duplication }}</td>
                                        <td style="text-align: center; font-weight: 700; color: #475569;">{{ $rec->time_value }}</td>
                                        <td style="text-align: center; font-size: 11px; color: #475569;">{{ $rec->utility }}</td>
                                        <td colspan="3" style="text-align: center; color: #cbd5e1;">—</td>
                                        <td style="text-align: center; color: #cbd5e1;">—</td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            @if(empty($rec->is_batch))
                                                <button type="button" wire:click="openEditSubjectModal({{ $rec->id }})" class="nap-btn nap-btn-secondary" style="padding: 4px 8px; font-size: 11px;">
                                                    Edit
                                                </button>
                                            @else
                                                <span style="color: #94a3b8; font-size: 11px;">—</span>
                                            @endif
                                        </td>
                                    </tr>

                                    @if(!empty($rec->is_batch) && !empty($rec->sub_periods))
                                        @foreach($rec->sub_periods as $subP)
                                            <tr class="record-sub-period-row {{ $isSelected ? 'is-selected' : '' }}" x-show="!isRootCollapsed('root-{{ $root->id }}') && !isSubjectsCollapsed('sub-{{ $sub->id }}')">
                                                <td style="text-align: center; padding: 6px 4px; white-space: nowrap;"></td>
                                                <td style="padding-left: 64px;">
                                                    <span style="color: #94a3b8; margin-right: 4px;">└</span>
                                                    <span style="font-weight: 500; color: #334155; font-size: 12px;">{{ $subP->description }}</span>
                                                </td>
                                                <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->date_covered }}</td>
                                                <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->volume }}</td>
                                                <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->medium }}</td>
                                                <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->restriction }}</td>
                                                <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->location }}</td>
                                                <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->frequence_use }}</td>
                                                <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->duplication }}</td>
                                                <td style="text-align: center; font-weight: 600; color: #64748b; font-size: 11.5px;">{{ $subP->time_value }}</td>
                                                <td style="text-align: center; font-size: 11px; color: #64748b;">{{ $subP->utility }}</td>
                                                <td colspan="3" style="text-align: center; color: #cbd5e1;">—</td>
                                                <td style="text-align: center; color: #cbd5e1;">—</td>
                                                <td style="text-align: right; white-space: nowrap;">
                                                    <button type="button" wire:click="openEditSubjectModal({{ $subP->parent_rec_id ?? $rec->id }}, {{ $subP->period_id }})" class="nap-btn nap-btn-secondary" style="padding: 4px 8px; font-size: 11px;">
                                                        Edit
                                                    </button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    @endif
                                @endforeach
                            @endforeach
                        @else
                            <!-- DIRECT CHILD RECORDS UNDER ROOT SERIES (IF NO SUBSERIES) -->
                            @foreach($root->direct_records as $rec)
                                @php
                                    $recIdStr = (string)$rec->id;
                                    $isSelected = in_array($recIdStr, $selectedIds);
                                @endphp
                                <tr class="record-item-row {{ $isSelected ? 'is-selected' : '' }}" x-show="!isSubjectsCollapsed('root-{{ $root->id }}')">
                                    <td style="text-align: center; padding: 6px 4px; white-space: nowrap;">
                                        <div style="display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                            <span style="width: 15px; display: inline-block;"></span>
                                            <span style="width: 20px; height: 20px; display: inline-block;"></span>
                                        </div>
                                    </td>
                                    <td style="padding-left: 28px;">
                                        <span class="sub-branch-line">│</span>
                                        <span style="font-weight: 600; color: #1e293b;">{{ $rec->description }}</span>
                                    </td>
                                    <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->date_covered }}</td>
                                    <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->volume }}</td>
                                    <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->medium }}</td>
                                    <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->restriction }}</td>
                                    <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->location }}</td>
                                    <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->frequence_use }}</td>
                                    <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->duplication }}</td>
                                    <td style="text-align: center; font-weight: 700; color: #475569;">{{ $rec->time_value }}</td>
                                    <td style="text-align: center; font-size: 11px; color: #475569;">{{ $rec->utility }}</td>
                                    <td colspan="3" style="text-align: center; color: #cbd5e1;">—</td>
                                    <td style="text-align: center; color: #cbd5e1;">—</td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        @if(empty($rec->is_batch))
                                            <button type="button" wire:click="openEditSubjectModal({{ $rec->id }})" class="nap-btn nap-btn-secondary" style="padding: 4px 8px; font-size: 11px;">
                                                Edit
                                            </button>
                                        @else
                                            <span style="color: #94a3b8; font-size: 11px;">—</span>
                                        @endif
                                    </td>
                                </tr>

                                @if(!empty($rec->is_batch) && !empty($rec->sub_periods))
                                    @foreach($rec->sub_periods as $subP)
                                        <tr class="record-sub-period-row {{ $isSelected ? 'is-selected' : '' }}" x-show="!isSubjectsCollapsed('root-{{ $root->id }}')">
                                            <td style="text-align: center; padding: 6px 4px; white-space: nowrap;"></td>
                                            <td style="padding-left: 50px;">
                                                <span style="color: #94a3b8; margin-right: 4px;">└</span>
                                                <span style="font-weight: 500; color: #334155; font-size: 12px;">{{ $subP->description }}</span>
                                            </td>
                                            <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->date_covered }}</td>
                                            <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->volume }}</td>
                                            <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->medium }}</td>
                                            <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->restriction }}</td>
                                            <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->location }}</td>
                                            <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->frequence_use }}</td>
                                            <td style="text-align: center; color: #64748b; font-size: 11.5px;">{{ $subP->duplication }}</td>
                                            <td style="text-align: center; font-weight: 600; color: #64748b; font-size: 11.5px;">{{ $subP->time_value }}</td>
                                            <td style="text-align: center; font-size: 11px; color: #64748b;">{{ $subP->utility }}</td>
                                            <td colspan="3" style="text-align: center; color: #cbd5e1;">—</td>
                                            <td style="text-align: center; color: #cbd5e1;">—</td>
                                            <td style="text-align: right; white-space: nowrap;">
                                                <button type="button" wire:click="openEditSubjectModal({{ $subP->parent_rec_id ?? $rec->id }}, {{ $subP->period_id }})" class="nap-btn nap-btn-secondary" style="padding: 4px 8px; font-size: 11px;">
                                                    Edit
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif
                            @endforeach
                        @endif
                    @empty
                        <tr>
                            <td colspan="16" style="padding: 36px; text-align: center; color: #64748b;">
                                No records or series match the filter criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- PRINT PREVIEW MODAL (OFFICIAL NAP FORM 1 LANDSCAPE) -->
    @if($showPrintModal)
        @php
            $cleanVal = function($val) {
                if ($val === null) return '';
                $str = trim((string)$val);
                if ($str === '—' || $str === '-' || $str === 'N/A' || $str === 'None' || $str === 'null') {
                    return '';
                }
                return $str;
            };

            $hasSelection = !empty($selectedIds);
            $flattenedItems = [];
            if ($hasSelection) {
                foreach ($hierarchyTree as $root) {
                    if (!$root->has_children) {
                        $rootChildStrIds = array_map('strval', $root->record_ids);
                        $isSeriesSelected = !empty(array_intersect($rootChildStrIds, $selectedIds));

                        // If user selected specific record series, only include selected record series
                        if (!$isSeriesSelected) {
                            continue;
                        }

                        $flattenedItems[] = [
                            'type' => 'root_standalone',
                            'root' => $root,
                        ];
                        if ($includeDescriptionOnPrint) {
                            foreach ($root->direct_records as $rec) {
                                $flattenedItems[] = [
                                    'type'   => 'record',
                                    'rec'    => $rec,
                                    'indent' => 20,
                                ];
                                if (!empty($rec->is_batch) && !empty($rec->sub_periods)) {
                                    foreach ($rec->sub_periods as $subP) {
                                        $flattenedItems[] = [
                                            'type'   => 'record',
                                            'rec'    => $subP,
                                            'indent' => 32,
                                        ];
                                    }
                                }
                            }
                        }
                    } else {
                        $selectedSubs = [];
                        foreach ($root->sub_series as $sub) {
                            $subChildStrIds = array_map('strval', $sub->record_ids);
                            $isSubSelected = !empty(array_intersect($subChildStrIds, $selectedIds));

                            if ($isSubSelected) {
                                $selectedSubs[] = $sub;
                            }
                        }

                        if (empty($selectedSubs)) {
                            continue;
                        }

                        $flattenedItems[] = [
                            'type' => 'root_header',
                            'root' => $root,
                        ];
                        foreach ($selectedSubs as $sub) {
                            $flattenedItems[] = [
                                'type'   => 'sub_series',
                                'sub'    => $sub,
                                'root'   => $root,
                                'indent' => 16,
                            ];
                            if ($includeDescriptionOnPrint) {
                                foreach ($sub->records as $rec) {
                                    $flattenedItems[] = [
                                        'type'   => 'record',
                                        'rec'    => $rec,
                                        'indent' => 26,
                                    ];
                                    if (!empty($rec->is_batch) && !empty($rec->sub_periods)) {
                                        foreach ($rec->sub_periods as $subP) {
                                            $flattenedItems[] = [
                                                'type'   => 'record',
                                                'rec'    => $subP,
                                                'indent' => 38,
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $totalItems = count($flattenedItems);
            $pages = [];
            $maxRowsFinalPage = 10;
            $maxRowsOtherPages = 15;

            if ($totalItems === 0) {
                // When no data selected, show no data rows, only the blank official template
                $pages = [ [] ];
            } elseif ($totalItems <= $maxRowsFinalPage) {
                $pages = [ $flattenedItems ];
            } else {
                $remaining = $flattenedItems;
                while (!empty($remaining)) {
                    if (count($remaining) <= $maxRowsFinalPage) {
                        $pages[] = $remaining;
                        break;
                    }
                    $chunkSize = min($maxRowsOtherPages, max(1, count($remaining) - 1));
                    $chunk = array_splice($remaining, 0, $chunkSize);
                    $pages[] = $chunk;
                }
            }
            $totalPages = count($pages);
        @endphp
        <div class="modal-overlay" wire:click.self="closePrintModal">
            <div class="modal-content">
                <div style="display: flex; justify-content: space-between; align-items: center;" class="no-print">
                    <div>
                        <div style="color: #ffffff; font-size: 16px; font-weight: 800;">
                            Print Preview: NAP Form 1 (Records Inventory and Appraisal)
                        </div>
                        <div style="color: #cbd5e1; font-size: 12px; margin-top: 2px;">
                            @if($hasSelection)
                                Showing only selected Record Series ({{ count($selectedIds) }} records selected).
                            @else
                                Official Records Inventory and Appraisal Form Preview (No records selected — Blank Template).
                            @endif
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button type="button" wire:click="closePrintModal" class="nap-btn nap-btn-secondary" style="background: #ffffff; color: #0f172a; font-weight: 700;">
                            ✕ Close Preview
                        </button>
                    </div>
                </div>

                @foreach($pages as $pageIndex => $pageItems)
                    @php
                        $isLastPage = ($pageIndex + 1) === $totalPages;
                        $cellBorder = "border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;";
                        $computedFiller = $isLastPage 
                            ? max(30, 280 - (count($pageItems) * 20)) 
                            : max(50, 470 - (count($pageItems) * 20));
                    @endphp
                    <div class="print-sheet">
                        <!-- Top Form Identifier -->
                        <div style="font-size: 8px; font-weight: normal; margin-bottom: 2px; font-family: Arial, sans-serif; line-height: 1.25;">
                            NAP Records Inventory and Appraisal Form<br>2024
                        </div>
                        <br>

                        <!-- TOP HEADER GRID BOX (Fields 1 to 8) -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; border-bottom: none; font-size: 8px; text-align: left; table-layout: fixed; font-family: Arial, sans-serif;">
                            <tr>
                                <td rowspan="3" style="width: 27.5%; border: 1px solid #000; text-align: center; vertical-align: middle; padding: 4px;">
                                    <div style="border: 1.5px solid #000; padding: 8px 6px; margin: 2px;">
                                        <div style="font-weight: bold; font-size: 10px; font-family: Arial, sans-serif; text-align: center;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                                        <div style="font-style: italic; font-size: 8.5px; margin: 2px 0 8px 0; font-family: Arial, sans-serif; text-align: center;">Pambansang Sinupan ng Pilipinas</div>
                                        <div style="font-weight: bold; font-size: 10px; font-family: Arial, sans-serif; text-align: center;">RECORDS INVENTORY AND APPRAISAL</div>
                                    </div>
                                </td>
                                <td rowspan="2" style="width: 27%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>1. NAME OF OFFICE:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($agencyName) }}</div>
                                </td>
                                <td style="width: 17.5%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>2. DEPARTMENT/DIVISION:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($departmentDivision) }}</div>
                                </td>
                                <td style="width: 28%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>4. TELEPHONE NO.:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px; min-height: 12px;"></div>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>3. SECTION/UNIT:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($sectionUnit) }}</div>
                                </td>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>5. EMAIL ADDRESS.:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px; min-height: 12px;"></div>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>6. ADDRESS:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($agencyAddress) }}</div>
                                </td>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>7. PERSON-IN-CHARGE OF FILES:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($personInCharge) }}</div>
                                </td>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>8. DATE PREPARED:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($datePrepared) }}</div>
                                </td>
                            </tr>
                        </table>

                        <!-- MAIN DATA TABLE (Columns 9 to 20) -->
                        <table class="print-table" style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 7px; text-align: center; table-layout: fixed;">
                            <thead style="font-size: 7px;">
                                <tr style="font-weight: bold;">
                                    <th rowspan="2" style="border: 1px solid #000; width: 17%; padding: 4px 2px; text-align: center;">9. RECORDS SERIES TITLE AND DESCRIPTION</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 8%; padding: 4px 2px; text-align: center;">10. PERIOD COVERED / INCLUSIVE DATES</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 5%; padding: 4px 2px; text-align: center;">11. VOLUME</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px 2px; text-align: center;">12. RECORDS MEDIUM</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px 2px; text-align: center;">13. RESTRICTION/S</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 7.5%; padding: 4px 2px; text-align: center;">14. LOCATION OF RECORDS</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px 2px; text-align: center;">15. FREQUENCY OF USE</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 5.5%; padding: 4px 2px; text-align: center;">16. DUPLICATION</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 5%; padding: 4px 2px; text-align: center;">17. TIME VALUE (T/P)</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px 2px; text-align: center;">18. UTILITY VALUE Adm/F/L/Arc</th>
                                    <th colspan="3" style="border: 1px solid #000; width: 11%; padding: 4px 2px; text-align: center;">19. RETENTION PERIOD</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 15%; padding: 4px 2px; text-align: center;">20. DISPOSITION PROVISION</th>
                                </tr>
                                <tr style="font-weight: bold;">
                                    <th style="border: 1px solid #000; width: 3.6%; padding: 3px 2px; text-align: center;">Active</th>
                                    <th style="border: 1px solid #000; width: 3.6%; padding: 3px 2px; text-align: center;">Storage</th>
                                    <th style="border: 1px solid #000; width: 3.8%; padding: 3px 2px; text-align: center;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Spacing row below header -->
                                <tr style="height: 10px; line-height: 10px;">
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                    <td style="{{ $cellBorder }} padding: 0;">&nbsp;</td>
                                </tr>
                                @foreach($pageItems as $item)
                                    @if($item['type'] === 'root_standalone')
                                        @php $root = $item['root']; @endphp
                                        <tr style="vertical-align: top;">
                                            <td style="{{ $cellBorder }} text-align: left; padding: 3px 6px; font-weight: bold; font-size: 7.5px;">
                                                {{ strtoupper($cleanVal($root->series_title)) }}
                                            </td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_period) }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_volume) }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_medium) }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_restriction) }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_location) }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_freq) }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_duplication) }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center; font-weight: bold;">{{ $cleanVal($root->compiled_time) }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center; font-weight: bold;">{{ $cleanVal($root->compiled_util) }}</td>
                                            @if($root->is_permanent)
                                                <td colspan="3" style="{{ $cellBorder }} padding: 3px 2px; text-align: center; font-weight: bold;">PERMANENT</td>
                                            @else
                                                <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->active_period) }}</td>
                                                <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->storage_period) }}</td>
                                                <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center; font-weight: bold;">{{ $cleanVal($root->total_period) }}</td>
                                            @endif
                                            <td style="{{ $cellBorder }} padding: 3px 4px; text-align: left;">{{ $cleanVal($root->remarks) }}</td>
                                        </tr>
                                    @elseif($item['type'] === 'root_header')
                                        @php $root = $item['root']; @endphp
                                        <tr style="vertical-align: top;">
                                            <td style="{{ $cellBorder }} text-align: left; padding: 4px 6px 2px 6px; font-weight: bold; font-size: 7.5px;">
                                                {{ strtoupper($cleanVal($root->series_title)) }}
                                            </td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                        </tr>
                                    @elseif($item['type'] === 'sub_series')
                                        @php 
                                            $sub = $item['sub']; 
                                            $root = $item['root']; 
                                        @endphp
                                        <tr style="vertical-align: top;">
                                            <td style="{{ $cellBorder }} text-align: left; padding: 2px 6px 3px {{ $item['indent'] ?? 16 }}px; font-weight: normal; font-size: 7.5px;">
                                                {{ $cleanVal($sub->series_title) }}
                                            </td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_period) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_volume) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_medium) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_restriction) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_location) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_freq) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_duplication) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center; font-weight: bold;">{{ $cleanVal($sub->compiled_time) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center; font-weight: bold;">{{ $cleanVal($sub->compiled_util) }}</td>
                                            @if($sub->is_permanent)
                                                <td colspan="3" style="{{ $cellBorder }} padding: 2px; text-align: center; font-weight: bold;">PERMANENT</td>
                                            @else
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->active_period) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->storage_period) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center; font-weight: bold;">{{ $cleanVal($sub->total_period) }}</td>
                                            @endif
                                            <td style="{{ $cellBorder }} padding: 2px 4px; text-align: left;">{{ $cleanVal($sub->remarks ?: $root->remarks) }}</td>
                                        </tr>
                                    @elseif($item['type'] === 'record')
                                        @php $rec = $item['rec']; @endphp
                                        <tr style="vertical-align: top;">
                                            <td style="{{ $cellBorder }} text-align: left; padding: 2px 6px 2px {{ $item['indent'] ?? 20 }}px; font-size: 7px;">
                                                @if(!empty($rec->is_sub_period))
                                                    <span style="margin-right: 2px;">└</span>
                                                @endif
                                                {{ $cleanVal($rec->description) }}
                                            </td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->date_covered) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->volume) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->medium) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->restriction) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->location) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->frequence_use) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->duplication) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->time_value) }}</td>
                                            <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->utility) }}</td>
                                            <td colspan="3" style="{{ $cellBorder }} padding: 2px;"></td>
                                            <td style="{{ $cellBorder }} padding: 2px;"></td>
                                        </tr>
                                    @endif
                                @endforeach

                                <!-- Tall vertical column lines extending to bottom table border -->
                                <tr style="height: {{ $computedFiller }}px;">
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- LEGEND SECTION (Visible on every page) -->
                        <div style="font-size: 8px; font-family: Arial, sans-serif; margin-top: 6px; line-height: 1.35;">
                            <div style="font-weight: bold;">LEGEND:</div>
                            <div style="display: flex; gap: 30px; margin-top: 1px; padding-left: 50px;">
                                <div style="display: flex; gap: 15px;">
                                    <span style="font-weight: normal; width: 90px;">TIME VALUE:</span>
                                    <span><strong>T</strong> - Temporary &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <strong>P</strong> - Permanent</span>
                                </div>
                            </div>
                            <div style="display: flex; gap: 30px; margin-top: 1px; padding-left: 50px;">
                                <div style="display: flex; gap: 15px;">
                                    <span style="font-weight: normal; width: 90px;">UTILITY VALUE:</span>
                                    <span><strong>Adm</strong> - Administrative &nbsp;&nbsp;&nbsp;&nbsp; <strong>F</strong> - Fiscal &nbsp;&nbsp;&nbsp;&nbsp; <strong>L</strong> - Legal &nbsp;&nbsp;&nbsp;&nbsp; <strong>Arc</strong> - Archival</span>
                                </div>
                            </div>
                        </div>

                        <!-- SIGNATURE BLOCK (Visible on the last page) -->
                        @if($isLastPage)
                            <div style="display: flex; justify-content: space-between; font-size: 8.5px; font-family: Arial, sans-serif; margin-top: 20px;">
                                <div style="width: 30%; text-align: center;">
                                    <div style="font-weight: bold; text-align: left; margin-bottom: 25px;">PREPARED BY:</div>
                                    <div style="border-bottom: 1.5px solid #000; width: 90%; margin: 0 auto; font-weight: bold; font-size: 9px; min-height: 13px;">
                                        {{ $cleanVal($preparedBy) }}
                                    </div>
                                    <div style="font-size: 8px; margin-top: 3px;">
                                        {{ $cleanVal($preparedPosition) ?: 'Name and Position' }}
                                    </div>
                                </div>
                                <div style="width: 30%; text-align: center;">
                                    <div style="font-weight: bold; text-align: left; margin-bottom: 25px;">ASSISTED BY:</div>
                                    <div style="border-bottom: 1.5px solid #000; width: 90%; margin: 0 auto; font-weight: bold; font-size: 9px; min-height: 13px;">
                                        {{ $cleanVal($assistedBy) }}
                                    </div>
                                    <div style="font-size: 8px; margin-top: 3px;">
                                        {{ $cleanVal($assistedPosition) ?: 'NAP Records Management Analyst' }}
                                    </div>
                                </div>
                                <div style="width: 30%; text-align: center;">
                                    <div style="font-weight: bold; text-align: left; margin-bottom: 25px;">APPROVED BY:</div>
                                    <div style="border-bottom: 1.5px solid #000; width: 90%; margin: 0 auto; font-weight: bold; font-size: 9px; min-height: 13px;">
                                        {{ $cleanVal($approvedBy) }}
                                    </div>
                                    <div style="font-size: 8px; margin-top: 3px;">
                                        {{ $cleanVal($approvedPosition) ?: 'Chief of the Division/Department' }}
                                    </div>
                                </div>
                            </div>
                        @endif

                        <!-- BOTTOM PAGE NUMBER -->
                        <div style="text-align: right; font-size: 8px; font-family: Arial, sans-serif; margin-top: 10px;">
                            Page {{ $pageIndex + 1 }} of {{ $totalPages }} {{ $totalPages === 1 ? 'Page' : 'Pages' }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- EDIT SUBJECT MODAL -->
    @if($showEditSubjectModal)
        <div class="modal-overlay" wire:click.self="closeEditSubjectModal">
            <div class="modal-dialog" style="max-width: 680px; width: 100%;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <div>
                        <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">{{ $editingIsBatchSubPeriod ? 'Edit Batch Item Record' : 'Edit Record Subject' }}</h3>
                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #64748b;">{{ $editingIsBatchSubPeriod ? 'Update volume, dates, or classifications for this batch item.' : 'Update and fix details, typos, or classifications for this record.' }}</p>
                    </div>
                    <button type="button" wire:click="closeEditSubjectModal" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <form wire:submit.prevent="saveEditSubject" style="display: flex; flex-direction: column; gap: 14px;">
                    <!-- Subject Description -->
                    <div>
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                            <label style="font-size: 12px; font-weight: 700; color: #334155;">{{ $editingIsBatchSubPeriod ? 'Batch Item Name' : 'Subject / Description' }}</label>
                            @if(!$canEditDescription || $editingIsBatchSubPeriod)
                                <span title="{{ $editingIsBatchSubPeriod ? 'Batch item name is generated automatically' : 'You do not have clearance to edit this record\'s description' }}" style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; color: #94a3b8; background: #f1f5f9; padding: 2px 8px; border-radius: 9999px; border: 1px solid #cbd5e1;">
                                    <i class="fa-solid fa-lock" style="font-size: 10px;"></i> {{ $editingIsBatchSubPeriod ? 'Auto' : 'Locked' }}
                                </span>
                            @endif
                        </div>
                        @if($canEditDescription && !$editingIsBatchSubPeriod)
                            <textarea wire:model="editSubjectDescription" rows="2" class="nap-form-control" placeholder="Enter record subject title or description" required></textarea>
                        @else
                            <textarea wire:model="editSubjectDescription" rows="2" class="nap-form-control" readonly disabled title="{{ $editingIsBatchSubPeriod ? 'Batch item title is formatted from the batch series' : 'Editing description is locked due to lack of clearance' }}"></textarea>
                        @endif
                    </div>

                    <!-- Row 1: Period Covered & Volume -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Period Covered / Inclusive Dates</label>
                            <input type="text" wire:model="editSubjectDateCovered" class="nap-form-control" placeholder="e.g. 2020-2024 or 2023" {{ !$canEditDescription ? 'readonly disabled' : '' }}>
                        </div>
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Volume Amount & Unit</label>
                            <input type="text" wire:model="editSubjectVolume" class="nap-form-control" placeholder="e.g. 2 papers, 1 box, 2 bundles" {{ !$canEditDescription ? 'readonly disabled' : '' }}>
                        </div>
                    </div>

                    <!-- Row 2: Location & Medium -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Location of Records</label>
                            <input type="text" wire:model="editSubjectLocation" class="nap-form-control" placeholder="e.g. Cabinet 2L, Shelf 3" {{ !$canEditDescription ? 'readonly disabled' : '' }}>
                        </div>
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Records Medium</label>
                            <select wire:model="editSubjectMedium" class="nap-form-control" {{ !$canEditDescription ? 'disabled' : '' }}>
                                <option value="" {{ empty($editSubjectMedium) ? 'selected' : '' }}>Select Medium...</option>
                                @foreach($mediaList as $med)
                                    <option value="{{ $med->id }}" {{ (string)$editSubjectMedium === (string)$med->id ? 'selected' : '' }}>{{ $med->medium_name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <!-- Row 3: Restriction & Frequency of Use -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Restriction / Access</label>
                            <select wire:model="editSubjectRestriction" class="nap-form-control" {{ !$canEditDescription ? 'disabled' : '' }}>
                                <option value="" {{ empty($editSubjectRestriction) ? 'selected' : '' }}>Select Restriction...</option>
                                @foreach($restrictionsList as $rest)
                                    <option value="{{ $rest->restriction_value }}" {{ $editSubjectRestriction === $rest->restriction_value ? 'selected' : '' }}>{{ $rest->restriction_value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Frequency of Use</label>
                            <select wire:model="editSubjectFrequency" class="nap-form-control" {{ !$canEditDescription ? 'disabled' : '' }}>
                                <option value="" {{ empty($editSubjectFrequency) ? 'selected' : '' }}>Select Frequency...</option>
                                @foreach($frequenciesList as $freq)
                                    <option value="{{ $freq->freq_type }}" {{ $editSubjectFrequency === $freq->freq_type ? 'selected' : '' }}>{{ $freq->freq_type }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <!-- Row 4: Time Value & Utility Value -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: start;">
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Time Value (T/P)</label>
                            <select wire:model="editSubjectTimeValue" class="nap-form-control" {{ !$canEditDescription ? 'disabled' : '' }}>
                                @foreach($timeValuesList as $tv)
                                    <option value="{{ $tv->char_value }}" {{ $editSubjectTimeValue === $tv->char_value ? 'selected' : '' }}>{{ $tv->char_value }} — {{ $tv->description }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Utility Value</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px;">
                                @foreach($utilityValuesList as $uv)
                                    <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; padding: 7px 10px; border-radius: 8px; border: 1px solid {{ !$canEditDescription ? '#e2e8f0' : '#cbd5e1' }}; background: {{ !$canEditDescription ? '#f8fafc' : '#ffffff' }}; color: {{ !$canEditDescription ? '#64748b' : '#334155' }}; cursor: {{ !$canEditDescription ? 'not-allowed' : 'pointer' }}; box-sizing: border-box;">
                                        <input type="checkbox" wire:model="editSubjectUtilities" value="{{ $uv->id }}" {{ !$canEditDescription ? 'disabled' : '' }} style="accent-color: #2563eb; width: 14px; height: 14px; cursor: {{ !$canEditDescription ? 'not-allowed' : 'pointer' }}; margin: 0;">
                                        <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $uv->utility_name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 14px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
                        <div>
                            @if($canCancelRecord)
                                <button type="button" wire:click="cancelRecord" wire:confirm="Are you sure you want to cancel this record? This will remove it from NAP Form 1." class="nap-btn" style="background: #fee2e2; color: #dc2626; border: 1px solid #fecaca;">
                                    Cancel Record
                                </button>
                            @endif
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <button type="button" wire:click="closeEditSubjectModal" class="nap-btn nap-btn-secondary">Close</button>
                            @if($canEditDescription)
                                <button type="submit" class="nap-btn nap-btn-primary">Save Changes</button>
                            @endif
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- CREATE FORM MODAL -->
    @if($showClusterModal)
        <div class="modal-overlay" wire:click.self="closeClusterModal">
            <div class="modal-dialog">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">Create Inventory Submission Form</h3>
                    <button type="button" wire:click="closeClusterModal" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #1e40af; font-weight: 600;">
                        📦 Packaging <strong>{{ count($selectedIds) }}</strong> selected inventory records into a submission form.
                    </div>

                    <div>
                        <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Form Name</label>
                        <input type="text" wire:model="clusterName" class="form-control" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                        <button type="button" wire:click="closeClusterModal" class="nap-btn nap-btn-secondary">Cancel</button>
                        <button type="button" wire:click="submitClusterCreation" class="nap-btn nap-btn-primary">Confirm & Create Form</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>