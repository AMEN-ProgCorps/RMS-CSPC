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
                $s = '%' . trim($this->search) . '%';
                $query->where(function ($q) use ($s) {
                    $q->where('rdp_record.description', 'ilike', $s)
                      ->orWhere('rdp_record.volume', 'ilike', $s)
                      ->orWhere('rdp_record.records_location', 'ilike', $s);
                });
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

            $mainPendingTbl = \Illuminate\Support\Facades\Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';
            $mainPendingId = DB::table($mainPendingTbl)->insertGetId([
                'status'     => 'UNUSED',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('rdp_pending_record')->insert([
                'cluster_id'       => $mainPendingId,
                'cluster_name'     => trim($this->clusterName) ?: ('Inventory Cluster — ' . ($userOffice ?: 'OFFICE') . ' (' . now()->format('Y-m-d') . ')'),
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
                $this->errorMessage = 'Please select at least one valid record to cluster.';
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

            $this->successMessage = 'Inventory cluster created successfully! It is now available under Pending / List for submission.';
            $this->selectedIds = [];
            $this->selectAll = false;
            $this->closeClusterModal();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to create cluster: ' . $e->getMessage();
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

    public function openEditModal(int $id): void
    {
        $series = DB::table('rdp_record_series')->where('id', $id)->first();
        if ($series) {
            $this->editingSeriesId = $series->id;
            $this->editSeriesTitle = $series->series_title ?? '';
            $this->editItemNumber = $series->item_number !== null ? (string)$series->item_number : '';
            $this->editRemarks = $series->remarks ?? '';
            $this->isRootParentForEdit = empty($series->parent_id);
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
            'series_title' => mb_strtoupper(trim($this->editSeriesTitle)),
            'remarks'      => trim($this->editRemarks) ?: null,
        ];

        if (empty($series->parent_id)) {
            $itemNumStr = trim((string)$this->editItemNumber);
            if ($itemNumStr !== '') {
                $updateData['item_number'] = (int)$itemNumStr;
                $updateData['is_verified'] = true;
            } else {
                $updateData['item_number'] = null;
                $updateData['is_verified'] = false;
            }
        }

        DB::table('rdp_record_series')->where('id', $this->editingSeriesId)->update($updateData);

        $this->successMessage = "Series '{$this->editSeriesTitle}' updated successfully.";
        $this->closeEditModal();
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
        foreach ($dates as $d) {
            $d = trim((string)$d);
            if (empty($d) || $d === '—') continue;
            if (preg_match('/(19\d\d|20\d\d)/', $d, $m)) {
                $years[] = (int)$m[1];
            } else {
                $rawList[] = $d;
            }
        }
        $years = array_values(array_unique($years));
        rsort($years);

        if (empty($years)) {
            return !empty($rawList) ? implode(', ', array_unique($rawList)) : '—';
        }

        $groups = [];
        $currentGroup = [];
        foreach ($years as $y) {
            if (empty($currentGroup)) {
                $currentGroup[] = $y;
            } else {
                $last = end($currentGroup);
                if ($last - 1 === $y) {
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
        foreach ($groups as $grp) {
            if (count($grp) >= 2) {
                $formattedGroups[] = $grp[0] . '-' . end($grp);
            } else {
                $formattedGroups[] = (string)$grp[0];
            }
        }

        $res = implode(', ', $formattedGroups);
        if (!empty($rawList)) {
            $res .= ', ' . implode(', ', array_unique($rawList));
        }
        return $res;
    }

    private function compileVolume(array $volumes): string
    {
        $totals = [];
        $unmatched = [];

        foreach ($volumes as $v) {
            $v = trim((string)$v);
            if (empty($v) || $v === '—') continue;

            $parts = preg_split('/[,+&]|\band\b/i', $v);
            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part)) continue;

                if (preg_match('/^(\d+(?:\.\d+)?)\s*([a-zA-Z\s\.]+)/', $part, $m)) {
                    $amount = (float)$m[1];
                    $unit = strtolower(trim($m[2]));
                    if (str_starts_with($unit, 'paper') || str_starts_with($unit, 'sheet') || str_starts_with($unit, 'page')) {
                        $normUnit = 'papers';
                    } elseif (str_starts_with($unit, 'folder')) {
                        $normUnit = 'folders';
                    } elseif (str_starts_with($unit, 'box')) {
                        $normUnit = 'boxes';
                    } elseif (str_starts_with($unit, 'bundle')) {
                        $normUnit = 'bundles';
                    } elseif (str_starts_with($unit, 'cu') || str_contains($unit, 'meter') || str_contains($unit, 'm.')) {
                        $normUnit = 'cu. m.';
                    } else {
                        $normUnit = $unit;
                    }
                    $totals[$normUnit] = ($totals[$normUnit] ?? 0) + $amount;
                } else {
                    $unmatched[] = $part;
                }
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
            ->where('transferred_to_nap3', false);

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
            $s = '%' . trim($this->search) . '%';
            $recordsQuery->where(function($q) use ($s) {
                $q->where('description', 'ilike', $s)
                  ->orWhere('volume', 'ilike', $s)
                  ->orWhere('records_location', 'ilike', $s);
            });
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

                    $compiledDates = [];
                    $compiledVols = [];
                    $compiledMediums = [];
                    $compiledRestrictions = [];
                    $compiledLocs = [];
                    $compiledFreqs = [];
                    $compiledDups = [];
                    $compiledTimes = [];
                    $compiledUtils = [];
                    $childItems = [];

                    foreach ($subRecs as $rec) {
                        $pRow = $periods[$rec->id]->first() ?? null;
                        $rawDate = $pRow->date_covered ?? '';
                        $uRows = ($utilities[$rec->id] ?? collect())->pluck('utility_name')->all();

                        $recMedium = '—';
                        if (!empty($rec->records_medium)) {
                            $recMedium = $mediumsMap[$rec->records_medium] ?? (string)$rec->records_medium;
                        }

                        $recRestriction = !empty($rec->restriction) ? $rec->restriction : '—';
                        $recFreq = !empty($rec->frequence_use) ? $rec->frequence_use : '—';

                        if (!empty($rec->duplication_id) && isset($duplications[$rec->duplication_id])) {
                            $dupCodes = $duplications[$rec->duplication_id]->pluck('office_code')->unique()->values()->all();
                            $recDup = !empty($dupCodes) ? implode(', ', $dupCodes) : '—';
                        } else {
                            $recDup = '—';
                        }

                        $compiledDates[] = $rawDate;
                        $compiledVols[] = $rec->volume;
                        $compiledMediums[] = $recMedium;
                        $compiledRestrictions[] = $recRestriction;
                        $compiledLocs[] = $rec->records_location;
                        $compiledFreqs[] = $recFreq;
                        $compiledDups[] = $recDup;
                        $compiledTimes[] = $rec->time_value;
                        foreach ($uRows as $un) $compiledUtils[] = $un;

                        $childItems[] = (object)[
                            'id'            => $rec->id,
                            'series_id'     => $sub->id,
                            'description'   => $rec->description,
                            'date_covered'  => $this->formatItemDate($rawDate),
                            'volume'        => $rec->volume ?: '—',
                            'medium'        => $recMedium,
                            'restriction'   => $recRestriction,
                            'location'      => $rec->records_location ?: '—',
                            'frequence_use' => $recFreq,
                            'duplication'   => $recDup,
                            'time_value'    => $rec->time_value ?: 'T',
                            'utility'       => $this->formatItemUtility($uRows),
                        ];
                        $totalItemsCount++;
                    }

                    $isPerm = (bool)($sub->is_retention_period_permanent) 
                              || strtolower(trim($sub->total_period ?? '')) === 'permanent'
                              || (empty($sub->total_period) && ((bool)($root->is_retention_period_permanent) || strtolower(trim($root->total_period ?? '')) === 'permanent'));

                    if ($isPerm) $permanentCount++; else $temporaryCount++;

                    // Effective retention
                    $effActive = $sub->active_period ?: ($root->active_period ?: '—');
                    $effStorage = $sub->storage_period ?: ($root->storage_period ?: '');
                    $effTotal = $sub->total_period ?: ($root->total_period ?: '—');

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

                $compiledDates = [];
                $compiledVols = [];
                $compiledMediums = [];
                $compiledRestrictions = [];
                $compiledLocs = [];
                $compiledFreqs = [];
                $compiledDups = [];
                $compiledTimes = [];
                $compiledUtils = [];
                $childItems = [];

                foreach ($directRecs as $rec) {
                    $pRow = $periods[$rec->id]->first() ?? null;
                    $rawDate = $pRow->date_covered ?? '';
                    $uRows = ($utilities[$rec->id] ?? collect())->pluck('utility_name')->all();

                    $recMedium = '—';
                    if (!empty($rec->records_medium)) {
                        $recMedium = $mediumsMap[$rec->records_medium] ?? (string)$rec->records_medium;
                    }

                    $recRestriction = !empty($rec->restriction) ? $rec->restriction : '—';
                    $recFreq = !empty($rec->frequence_use) ? $rec->frequence_use : '—';

                    if (!empty($rec->duplication_id) && isset($duplications[$rec->duplication_id])) {
                        $dupCodes = $duplications[$rec->duplication_id]->pluck('office_code')->unique()->values()->all();
                        $recDup = !empty($dupCodes) ? implode(', ', $dupCodes) : '—';
                    } else {
                        $recDup = '—';
                    }

                    $compiledDates[] = $rawDate;
                    $compiledVols[] = $rec->volume;
                    $compiledMediums[] = $recMedium;
                    $compiledRestrictions[] = $recRestriction;
                    $compiledLocs[] = $rec->records_location;
                    $compiledFreqs[] = $recFreq;
                    $compiledDups[] = $recDup;
                    $compiledTimes[] = $rec->time_value;
                    foreach ($uRows as $un) $compiledUtils[] = $un;

                    $childItems[] = (object)[
                        'id'            => $rec->id,
                        'series_id'     => $root->id,
                        'description'   => $rec->description,
                        'date_covered'  => $this->formatItemDate($rawDate),
                        'volume'        => $rec->volume ?: '—',
                        'medium'        => $recMedium,
                        'restriction'   => $recRestriction,
                        'location'      => $rec->records_location ?: '—',
                        'frequence_use' => $recFreq,
                        'duplication'   => $recDup,
                        'time_value'    => $rec->time_value ?: 'T',
                        'utility'       => $this->formatItemUtility($uRows),
                    ];
                    $totalItemsCount++;
                }

                $isPerm = (bool)($root->is_retention_period_permanent) || strtolower(trim($root->total_period ?? '')) === 'permanent';
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
            font-size: 8px;
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
            font-size: 8px;
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
                🖨️ Print Preview
            </button>
            <button type="button" wire:click="openClusterModal" class="nap-btn nap-btn-primary" {{ empty($selectedIds) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' }}>
                📦 Create Cluster ({{ count($selectedIds) }})
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
        allSubjectsCollapsed: false,
        collapsedRoots: {},
        
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
            return !!this.collapsedRoots[key];
        },
        collapseAll() {
            this.allSubjectsCollapsed = true;
            this.collapsedSubjects = {};
        },
        expandAll() {
            this.allSubjectsCollapsed = false;
            this.collapsedSubjects = {};
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
                        🔽 Expand All
                    </button>
                    <button type="button" @click="collapseAll()" class="nap-btn nap-btn-secondary" style="padding: 7px 12px; font-size: 12px;" title="Collapse all series to show only compilation totals">
                        ▶️ Collapse All
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
                                            $isAllSelected = count(array_intersect($strIds, $selectedIds)) === count($strIds);
                                        @endphp
                                        <input type="checkbox" wire:click="toggleSeriesSelection({{ $root->id }}, {{ json_encode($root->record_ids) }})" {{ $isAllSelected ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #2563eb;" title="Select all in series">
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
                                                <svg style="width: 14px; height: 14px; stroke: currentColor; stroke-width: 2.2; fill: none; stroke-linecap: round; stroke-linejoin: round;" viewBox="0 0 24 24">
                                                    <path d="M6 9l6 6 6-6"></path>
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
                                            <svg style="width: 14px; height: 14px; stroke: currentColor; stroke-width: 2.2; fill: none; stroke-linecap: round; stroke-linejoin: round;" viewBox="0 0 24 24">
                                                <path d="M6 9l6 6 6-6"></path>
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
                                <button type="button" wire:click="openEditModal({{ $root->id }})" class="nap-btn nap-btn-secondary" style="padding: 4px 8px; font-size: 11px;">
                                    ✏️ Edit
                                </button>
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
                                                    $isAllSelected = count(array_intersect($strIds, $selectedIds)) === count($strIds);
                                                @endphp
                                                <input type="checkbox" wire:click="toggleSeriesSelection({{ $sub->id }}, {{ json_encode($sub->record_ids) }})" {{ $isAllSelected ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #2563eb;" title="Select all in sub-series">
                                            @else
                                                <span style="color: #94a3b8; font-size: 11px; width: 15px; display: inline-block; text-align: center;">—</span>
                                            @endif

                                            @if(count($sub->records) > 0)
                                                <button type="button" 
                                                        @click.stop="toggleSubjects('sub-{{ $sub->id }}')" 
                                                        class="nap-chevron-btn"
                                                        :style="isSubjectsCollapsed('sub-{{ $sub->id }}') ? 'transform: rotate(-90deg);' : 'transform: rotate(0deg);'"
                                                        title="Toggle subjects">
                                                    <svg style="width: 14px; height: 14px; stroke: currentColor; stroke-width: 2.2; fill: none; stroke-linecap: round; stroke-linejoin: round;" viewBox="0 0 24 24">
                                                        <path d="M6 9l6 6 6-6"></path>
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
                                        <button type="button" wire:click="openEditModal({{ $sub->id }})" class="nap-btn nap-btn-secondary" style="padding: 4px 8px; font-size: 11px;">
                                            ✏️ Edit
                                        </button>
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
                                                <input type="checkbox" wire:model.live="selectedIds" value="{{ $recIdStr }}" style="width: 15px; height: 15px; cursor: pointer; accent-color: #2563eb;">
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
                                        <td style="text-align: right;">
                                            <a href="{{ route('rdp.add-records.inventory-and-appraisal') }}" style="font-size: 11px; color: #2563eb; text-decoration: none; font-weight: 600;">Manage</a>
                                        </td>
                                    </tr>
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
                                            <input type="checkbox" wire:model.live="selectedIds" value="{{ $recIdStr }}" style="width: 15px; height: 15px; cursor: pointer; accent-color: #2563eb;">
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
                                    <td style="text-align: right;">
                                        <a href="{{ route('rdp.add-records.inventory-and-appraisal') }}" style="font-size: 11px; color: #2563eb; text-decoration: none; font-weight: 600;">Manage</a>
                                    </td>
                                </tr>
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

            $flattenedItems = [];
            foreach ($hierarchyTree as $root) {
                if (!$root->has_children) {
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
                        }
                    }
                } else {
                    $flattenedItems[] = [
                        'type' => 'root_header',
                        'root' => $root,
                    ];
                    foreach ($root->sub_series as $sub) {
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
                            }
                        }
                    }
                }
            }

            $totalItems = count($flattenedItems);
            $pages = [];
            $maxRowsFinalPage = 10;
            $maxRowsOtherPages = 15;

            if ($totalItems <= $maxRowsFinalPage) {
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
                            Official 20-Column Appraisal Matrix Preview. Official document printing is managed in the Pending / List section.
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
                            ? max(60, 360 - (count($pageItems) * 22)) 
                            : max(60, 460 - (count($pageItems) * 22));
                    @endphp
                    <div class="print-sheet">
                        <!-- Top Form Identifier -->
                        <div style="font-size: 8px; font-weight: normal; margin-bottom: 3px; font-family: Arial, sans-serif; line-height: 1.25;">
                            NAP Records Inventory and Appraisal Form<br>2024
                        </div>

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
                        <table class="print-table" style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 8px; text-align: center; table-layout: fixed;">
                            <thead>
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
                                @foreach($pageItems as $item)
                                    @if($item['type'] === 'root_standalone')
                                        @php $root = $item['root']; @endphp
                                        <tr style="vertical-align: top;">
                                            <td style="{{ $cellBorder }} text-align: left; padding: 3px 6px; font-weight: bold; font-size: 8.5px;">
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
                                            <td style="{{ $cellBorder }} text-align: left; padding: 4px 6px 2px 6px; font-weight: bold; font-size: 8.5px;">
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
                                            <td style="{{ $cellBorder }} text-align: left; padding: 2px 6px 3px {{ $item['indent'] ?? 16 }}px; font-weight: normal; font-size: 8.5px;">
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
                                            <td style="{{ $cellBorder }} text-align: left; padding: 2px 6px 2px {{ $item['indent'] ?? 20 }}px; font-size: 8px;">
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
                            <div style="display: flex; gap: 30px; margin-top: 1px;">
                                <div style="display: flex; gap: 15px;">
                                    <span style="font-weight: bold; width: 90px;">TIME VALUE:</span>
                                    <span><strong>T</strong> - Temporary &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <strong>P</strong> - Permanent</span>
                                </div>
                            </div>
                            <div style="display: flex; gap: 30px; margin-top: 1px;">
                                <div style="display: flex; gap: 15px;">
                                    <span style="font-weight: bold; width: 90px;">UTILITY VALUE:</span>
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

    <!-- EDIT SERIES MODAL -->
    @if($showEditModal)
        <div class="modal-overlay" wire:click.self="closeEditModal">
            <div class="modal-dialog">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">Edit Record Series</h3>
                    <button type="button" wire:click="closeEditModal" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <form wire:submit.prevent="saveEditSeries" style="display: flex; flex-direction: column; gap: 14px;">
                    <div>
                        <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Series Title</label>
                        <input type="text" wire:model="editSeriesTitle" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;" required>
                    </div>

                    @if($isRootParentForEdit)
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Item Number</label>
                            <input type="number" wire:model="editItemNumber" placeholder="e.g. 5" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                        </div>
                    @endif

                    <div>
                        <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Remarks & Provisions</label>
                        <textarea wire:model="editRemarks" rows="3" placeholder="Disposition notes, authority references..." style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;"></textarea>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                        <button type="button" wire:click="closeEditModal" class="nap-btn nap-btn-secondary">Cancel</button>
                        <button type="submit" class="nap-btn nap-btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- CREATE CLUSTER MODAL -->
    @if($showClusterModal)
        <div class="modal-overlay" wire:click.self="closeClusterModal">
            <div class="modal-dialog">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">Create Inventory Submission Cluster</h3>
                    <button type="button" wire:click="closeClusterModal" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #1e40af; font-weight: 600;">
                        📦 Packaging <strong>{{ count($selectedIds) }}</strong> selected inventory records into a submission cluster.
                    </div>

                    <div>
                        <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Cluster Name</label>
                        <input type="text" wire:model="clusterName" class="form-control" style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                        <button type="button" wire:click="closeClusterModal" class="nap-btn nap-btn-secondary">Cancel</button>
                        <button type="button" wire:click="submitClusterCreation" class="nap-btn nap-btn-primary">Confirm & Create Cluster</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>