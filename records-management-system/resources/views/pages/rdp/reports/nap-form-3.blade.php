<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Services\RdpRetentionService;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - NAP Form 3')] class extends Component {
    public string $search = '';
    public string $officeFilter = ''; // '' for all, or office_code
    public string $retentionFilter = ''; // 'all', 'temporary'
    
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

    // Printable Custom Header & Signature Fields (NAP Form 3 Revised 2012)
    public string $agencyName = 'Camarines Sur Polytechnic Colleges';
    public string $agencyAddress = 'San Miguel, Nabua, Camarines Sur';
    public string $telephoneNumber = '(054) 288-1534 loc. 113';
    public string $orgUnit = 'Records Management Unit';
    public string $personInCharge = 'Gennica Aprille S. Penetrante';
    public string $datePrepared = '';

    // Signatories (NAP Form 3 Revised 2012 Official PDF)
    public string $preparedBy = 'Gennica Aprille S. Penetrante';
    public string $preparedPosition = 'Administrative Officer V / Records Officer';
    public string $approvedBy = 'Dr. Charlito P. Cadag';
    public string $approvedPosition = 'College President / Head of Agency';

    public function mount(): void
    {
        $user = Auth::user();
        $perms = $user?->permissions;

        // Access clearance check
        if (!$perms || (!(bool)($perms->is_sadm ?? false) && !(bool)($perms->can_rdp_access_form_3 ?? true))) {
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
    }

    public function updatedSelectAll($value): void
    {
        if ($value) {
            $user = Auth::user();
            $perms = $user?->permissions;
            $isSadm = (bool)($perms->is_sadm ?? false);
            $userOffice = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
            $effectiveOffice = ($isSadm && !empty($this->officeFilter)) ? $this->officeFilter : $userOffice;

            // Only records transferred to NAP Form 3 (expired)
            $query = DB::table('rdp_record')
                ->where('rdp_record.is_draft', false)
                ->where('rdp_record.is_active', true)
                ->where('rdp_record.transferred_to_nap3', true);

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
            $this->selectedIds = array_values(array_diff($this->selectedIds, $stringIds));
        } else {
            $this->selectedIds = array_values(array_unique(array_merge($this->selectedIds, $stringIds)));
        }
    }

    public function openClusterModal(): void
    {
        if (empty($this->selectedIds)) {
            $this->errorMessage = 'Please select at least one expired record to create a disposal cluster.';
            return;
        }

        $userOffice = Auth::user()?->details?->office?->office_code ?? Auth::user()?->details?->office_code ?? 'OFFICE';
        $this->clusterName = 'Disposal Authority Cluster — ' . $userOffice . ' (' . Carbon::now()->format('Y-m-d') . ')';
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
            $this->errorMessage = 'Please select at least one expired record to create a disposal cluster.';
            return;
        }

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOffice = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;

            $mainPendingTbl = \Illuminate\Support\Facades\Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';
            $mainPendingId = DB::table($mainPendingTbl)->insertGetId([
                'status'     => 'UNUSED',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('rdp_pending_record')->insert([
                'cluster_id'       => $mainPendingId,
                'cluster_name'     => trim($this->clusterName) ?: ('Disposal Authority Batch — ' . ($userOffice ?: 'OFFICE') . ' (' . now()->format('Y-m-d') . ')'),
                'status_id'        => 1, // Pending Verification
                'office'           => $userOffice,
                'created_by'       => $user?->id,
                'is_for_nap_one'   => false,
                'is_for_nap_three' => true,
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

            $this->successMessage = 'Disposal Authority cluster created successfully! It is now available under Pending / List for disposal verification.';
            $this->selectedIds = [];
            $this->selectAll = false;
            $this->closeClusterModal();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to create disposal cluster: ' . $e->getMessage();
        }
    }

    public function openPrintModal(): void
    {
        $perms = Auth::user()?->permissions;
        if (!($perms->is_sadm ?? false) && !(bool)($perms->can_rdp_print_form_3 ?? true)) {
            $this->errorMessage = 'You do not have clearance to print NAP Form 3.';
            return;
        }
        $this->showPrintModal = true;
    }

    public function closePrintModal(): void
    {
        $this->showPrintModal = false;
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

        $this->successMessage = "Record series '{$this->editSeriesTitle}' updated successfully.";
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

    // --- Helper Compilers for Summary Rows & Print Sheet ---
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

    private function formatItemDate(string $rawDate): string
    {
        $rawDate = trim($rawDate);
        if (empty($rawDate) || $rawDate === '—') return '—';

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rawDate, $m)) {
            return Carbon::parse($rawDate)->format('j_F_Y');
        }
        return $rawDate;
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

        // Synchronize retention expiration so newly expired records are automatically transferred
        RdpRetentionService::syncTransferredRecords();

        // 1. Fetch ONLY records that are transferred to NAP Form 3 (transferred_to_nap3 = true)
        $recordsQuery = DB::table('rdp_record')
            ->where('is_draft', false)
            ->where('is_active', true)
            ->where('transferred_to_nap3', true);

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
                'hierarchyTree'       => [],
                'officesList'         => $officesList,
                'totalItemsCount'     => 0,
                'allCompiledLocation' => '—',
                'allCompiledVolume'   => '—',
                'userOfficeCode'      => $userOfficeCode,
                'userOfficeName'      => $userOfficeName,
                'isSadm'              => $isSadm,
            ];
        }

        // Periods covered
        $periods = DB::table('rdp_period_covered')
            ->whereIn('period_owner', $recordIds)
            ->orderBy('id', 'asc')
            ->get()
            ->groupBy('period_owner');

        $recordsBySeries = $allRecords->groupBy('record_series_id');
        $usedSeriesIds = $allRecords->pluck('record_series_id')->unique()->all();

        // 2. Fetch only the Record Series that have transferred records
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

        $allRoots = $parentSeries->concat($directSeries->whereNull('parent_id'))->unique('id');
        $subSeriesByParent = $directSeries->whereNotNull('parent_id')->groupBy('parent_id');

        // 3. Sorting of Record Series based on which one was added first (by minimum record ID)
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

        // 4. Assemble the Hierarchical Tree (Matching Mockup Image 1 & 2 + PDF Template)
        $tree = [];
        $totalItemsCount = 0;
        $allLocs = [];
        $allVols = [];

        foreach ($sortedRoots as $root) {
            $rootNode = (object)[
                'id'            => $root->id,
                'item_number'   => $root->item_number,
                'series_title'  => $root->series_title,
                'shorted_type'  => $root->shorted_type,
                'is_verified'   => $root->is_verified,
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

                $rootDates = [];
                foreach ($sortedSubs as $sub) {
                    $subRecs = $recordsBySeries[$sub->id] ?? collect();
                    if ($subRecs->isEmpty()) continue; // Only show if used in Form 3!

                    $compiledDates = [];
                    $compiledVols = [];
                    $compiledLocs = [];
                    $childItems = [];

                    foreach ($subRecs as $rec) {
                        $pRow = $periods[$rec->id]->first() ?? null;
                        $rawDate = $pRow->date_covered ?? '';

                        $compiledDates[] = $rawDate;
                        $rootDates[] = $rawDate;
                        $compiledVols[] = $rec->volume;
                        $compiledLocs[] = $rec->records_location;

                        $allLocs[] = $rec->records_location;
                        $allVols[] = $rec->volume;

                        $childItems[] = (object)[
                            'id'           => $rec->id,
                            'series_id'    => $sub->id,
                            'description'  => $rec->description,
                            'date_covered' => $this->formatItemDate($rawDate),
                            'volume'       => $rec->volume ?: '—',
                            'location'     => $rec->records_location ?: '—',
                        ];
                        $totalItemsCount++;
                    }

                    // Effective retention
                    $effActive = $sub->active_period ?: ($root->active_period ?: '—');
                    $effStorage = $sub->storage_period ?: ($root->storage_period ?: '');
                    $effTotal = $sub->total_period ?: ($root->total_period ?: '—');

                    $rootNode->sub_series[] = (object)[
                        'id'               => $sub->id,
                        'series_title'     => $sub->series_title,
                        'shorted_type'     => $sub->shorted_type ?: $root->shorted_type,
                        'compiled_period'  => $this->compilePeriodCovered($compiledDates),
                        'compiled_volume'  => $this->compileVolume($compiledVols),
                        'compiled_location'=> $this->compileLocation($compiledLocs),
                        'active_period'    => $effActive,
                        'storage_period'   => $effStorage,
                        'total_period'     => $effTotal,
                        'records'          => $childItems,
                        'record_ids'       => array_column($childItems, 'id'),
                    ];
                }

                $rootNode->compiled_period = $this->compilePeriodCovered($rootDates);
            } else {
                // Direct records under root
                $directRecs = $recordsBySeries[$root->id] ?? collect();
                if ($directRecs->isEmpty()) continue;

                $compiledDates = [];
                $compiledVols = [];
                $compiledLocs = [];
                $childItems = [];

                foreach ($directRecs as $rec) {
                    $pRow = $periods[$rec->id]->first() ?? null;
                    $rawDate = $pRow->date_covered ?? '';

                    $compiledDates[] = $rawDate;
                    $compiledVols[] = $rec->volume;
                    $compiledLocs[] = $rec->records_location;

                    $allLocs[] = $rec->records_location;
                    $allVols[] = $rec->volume;

                    $childItems[] = (object)[
                        'id'           => $rec->id,
                        'series_id'    => $root->id,
                        'description'  => $rec->description,
                        'date_covered' => $this->formatItemDate($rawDate),
                        'volume'       => $rec->volume ?: '—',
                        'location'     => $rec->records_location ?: '—',
                    ];
                    $totalItemsCount++;
                }

                $rootNode->compiled_period   = $this->compilePeriodCovered($compiledDates);
                $rootNode->compiled_volume   = $this->compileVolume($compiledVols);
                $rootNode->compiled_location = $this->compileLocation($compiledLocs);
                $rootNode->active_period     = $root->active_period ?: '—';
                $rootNode->storage_period    = $root->storage_period ?: '';
                $rootNode->total_period      = $root->total_period ?: '—';
                $rootNode->direct_records    = $childItems;
                $rootNode->record_ids        = array_column($childItems, 'id');
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
            'hierarchyTree'       => $tree,
            'officesList'         => $officesList,
            'totalItemsCount'     => $totalItemsCount,
            'allCompiledLocation' => $this->compileLocation($allLocs),
            'allCompiledVolume'   => $this->compileVolume($allVols),
            'userOfficeCode'      => $userOfficeCode,
            'userOfficeName'      => $userOfficeName,
            'isSadm'              => $isSadm,
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
        .nap-btn-primary { background: #dc2626; color: #ffffff; }
        .nap-btn-primary:hover { background: #b91c1c; }
        .nap-btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .nap-btn-secondary:hover { background: #e2e8f0; }

        .nap-page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .nap-page-title { font-size: 22px; font-weight: 800; color: #0f172a; margin: 0; }
        .nap-page-subtitle { font-size: 13.5px; color: #64748b; margin: 4px 0 0 0; }

        .nap-stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .nap-stat-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; align-items: center; gap: 16px; }
        .nap-stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800; }
        .nap-stat-icon-red { background: #fef2f2; color: #dc2626; }
        .nap-stat-icon-blue { background: #eff6ff; color: #2563eb; }
        .nap-stat-value { font-size: 22px; font-weight: 800; color: #0f172a; }
        .nap-stat-label { font-size: 12.5px; font-weight: 600; color: #64748b; }

        .nap-input { padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; background: #ffffff; color: #0f172a; }
        .nap-search-input { min-width: 280px; }
        .nap-select-input { font-weight: 600; }

        /* Row styles strictly matching Mockup */
        .root-series-row { background: #f8fafc; font-weight: 800; border-top: 2px solid #cbd5e1 !important; border-bottom: 2px solid #cbd5e1 !important; }
        .sub-series-row { background: #ffffff; font-weight: 700; border-bottom: 1px solid #cbd5e1; }
        .record-item-row { background: #fafafa; font-size: 12.5px; transition: background 0.15s; }
        .record-item-row:hover { background: #f1f5f9; }
        .record-item-row.is-selected { background: #fee2e2 !important; }

        .corner-symbol { font-family: ui-monospace, SFMono-Regular, monospace; font-size: 14px; color: #dc2626; font-weight: 900; margin-right: 6px; }
        .sub-branch-line { font-family: ui-monospace, SFMono-Regular, monospace; color: #94a3b8; margin-right: 8px; font-weight: 700; }

        /* Print Modal & Sheet Styles - NAP Form No. 3 (Revised 2012) */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .modal-content { background: #94a3b8; width: 100%; max-width: 1200px; max-height: 94vh; border-radius: 14px; overflow-y: auto; box-shadow: 0 20px 40px rgba(0,0,0,0.3); padding: 24px; display: flex; flex-direction: column; gap: 20px; }
        .modal-dialog { background: #ffffff; width: 100%; max-width: 600px; border-radius: 14px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); padding: 24px; }

        .print-sheet {
            width: 100%;
            background: #ffffff;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            padding: 30px;
            box-sizing: border-box;
            color: #000000;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10px;
        }

        .print-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000000;
            font-size: 9px;
            margin-top: 10px;
        }

        .print-table th {
            border: 1px solid #000000;
            padding: 6px 5px;
            background: #f2f2f2;
            text-align: center;
            font-weight: bold;
            font-size: 9px;
            vertical-align: middle;
        }

        .print-table td {
            border: 1px solid #000000;
            padding: 5px 6px;
            vertical-align: middle;
            font-size: 9px;
        }

        @media print {
            body { background: #ffffff !important; margin: 0 !important; padding: 0 !important; }
            header, #navigation, .no-print, .nap-page-header, .nap-stat-grid, .nap-card, footer { display: none !important; }
            .modal-overlay { position: static !important; background: none !important; padding: 0 !important; display: block !important; }
            .modal-content { background: none !important; max-width: 100% !important; max-height: none !important; padding: 0 !important; box-shadow: none !important; overflow: visible !important; }
            .print-sheet { box-shadow: none !important; padding: 0 !important; width: 100% !important; }
            @page { size: legal landscape; margin: 0.5in; }
        }
    </style>

    <!-- Header Section -->
    <div class="nap-page-header">
        <div>
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                <h1 class="nap-page-title">NAP Form 3: Request for Authority to Dispose of Records</h1>
                <span style="padding: 3px 10px; background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; border-radius: 12px; font-weight: 800; font-size: 11px;">
                    DISPOSAL AUTHORITY
                </span>
            </div>
            <p class="nap-page-subtitle">Hierarchical authority matrix for expired temporary records transferred from NAP Form 1.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" wire:click="openPrintModal" class="nap-btn nap-btn-secondary">
                🖨️ Print Preview
            </button>
            <button type="button" wire:click="openClusterModal" class="nap-btn nap-btn-primary" {{ empty($selectedIds) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' }}>
                📦 Create Disposal Cluster ({{ count($selectedIds) }})
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

    <!-- Stat Summary Cards -->
    <div class="nap-stat-grid">
        <div class="nap-stat-card">
            <div class="nap-stat-icon nap-stat-icon-red">🗑️</div>
            <div>
                <div class="nap-stat-value">{{ number_format($totalItemsCount) }}</div>
                <div class="nap-stat-label">Expired Records Ready for Disposal</div>
            </div>
        </div>

        <div class="nap-stat-card">
            <div class="nap-stat-icon nap-stat-icon-blue">📦</div>
            <div>
                <div class="nap-stat-value">{{ count($selectedIds) }}</div>
                <div class="nap-stat-label">Selected for Disposal Cluster</div>
            </div>
        </div>
    </div>

    <!-- Filters & Table Card -->
    <div class="nap-card">
        <div style="display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 20px;">
            <div style="display: flex; gap: 12px; flex-wrap: wrap; flex: 1;">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search expired record subject..." class="nap-input nap-search-input">

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

                @if($search || ($isSadm && !empty($officeFilter) && $officeFilter !== $userOfficeCode) || count($selectedIds) > 0)
                    <button type="button" wire:click="clearFilters" class="nap-btn nap-btn-secondary">
                        Reset Filters
                    </button>
                @endif
            </div>

            @if(count($selectedIds) > 0)
                <div style="font-size: 13px; font-weight: 700; color: #dc2626;">
                    {{ count($selectedIds) }} expired records selected
                </div>
            @endif
        </div>

        <!-- MAIN HIERARCHICAL NAP FORM 3 TABLE (WITH REQUIRED PERIOD COVERED) -->
        <div style="overflow-x: auto;">
            <table class="nap-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="width: 36px; text-align: center;">
                            <input type="checkbox" wire:model.live="selectAll" style="width: 15px; height: 15px; cursor: pointer; accent-color: #dc2626;">
                        </th>
                        <th rowspan="2" style="width: 75px; text-align: center;">ITEM NO.</th>
                        <th rowspan="2" style="min-width: 250px;">RECORD SERIES TITLE & DESCRIPTION</th>
                        <th rowspan="2" style="width: 150px; text-align: center;">PERIOD COVERED</th>
                        <th colspan="3" style="text-align: center; border-bottom: 1px solid #cbd5e1;">RETENTION PERIOD</th>
                        <th rowspan="2" style="width: 110px; text-align: right;">ACTION</th>
                    </tr>
                    <tr>
                        <th style="width: 80px; text-align: center; padding: 6px 4px; font-size: 11px;">ACTIVE</th>
                        <th style="width: 80px; text-align: center; padding: 6px 4px; font-size: 11px;">STORAGE</th>
                        <th style="width: 80px; text-align: center; padding: 6px 4px; font-size: 11px;">TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($hierarchyTree as $root)
                        <!-- ROOT SERIES ROW -->
                        <tr class="root-series-row">
                            <td style="text-align: center;">
                                @if(!$root->has_children && !empty($root->record_ids))
                                    @php
                                        $strIds = array_map('strval', $root->record_ids);
                                        $isAllSelected = count(array_intersect($strIds, $selectedIds)) === count($strIds);
                                    @endphp
                                    <input type="checkbox" wire:click="toggleSeriesSelection({{ $root->id }}, {{ json_encode($root->record_ids) }})" {{ $isAllSelected ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #dc2626;">
                                @else
                                    <span style="color: #94a3b8; font-size: 10px;">—</span>
                                @endif
                            </td>
                            <td style="text-align: center; font-weight: 800; font-size: 14px; color: #1e293b;">
                                {{ $root->item_number ?: '0' }}
                            </td>
                            <td style="font-weight: 800; font-size: 13.5px; color: #0f172a; letter-spacing: 0.3px;">
                                {{ $root->series_title }}
                                @if($root->shorted_type)
                                    <span style="font-size: 11px; padding: 1px 6px; border-radius: 4px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; margin-left: 6px; font-weight: 700;">
                                        {{ $root->shorted_type }}
                                    </span>
                                @endif
                            </td>
                            @if(!$root->has_children)
                                <td style="text-align: center; font-weight: 600; color: #334155; font-size: 12px;">{{ $root->compiled_period }}</td>
                                <td style="text-align: center; font-size: 12px; font-weight: 600;">{{ $root->active_period }}</td>
                                <td style="text-align: center; font-size: 12px; font-weight: 600;">{{ $root->storage_period ?: '—' }}</td>
                                <td style="text-align: center; font-size: 12px; font-weight: 800;">{{ $root->total_period }}</td>
                            @else
                                <td colspan="4" style="background: #f8fafc;"></td>
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
                                <tr class="sub-series-row">
                                    <td style="text-align: center;">
                                        @if(!empty($sub->record_ids))
                                            @php
                                                $strIds = array_map('strval', $sub->record_ids);
                                                $isAllSelected = count(array_intersect($strIds, $selectedIds)) === count($strIds);
                                            @endphp
                                            <input type="checkbox" wire:click="toggleSeriesSelection({{ $sub->id }}, {{ json_encode($sub->record_ids) }})" {{ $isAllSelected ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #dc2626;">
                                        @else
                                            <span style="color: #94a3b8; font-size: 10px;">—</span>
                                        @endif
                                    </td>
                                    <td style="text-align: center; color: #94a3b8; font-size: 12px;"></td>
                                    <td style="padding-left: 20px; font-weight: 700; color: #0f172a;">
                                        <span class="corner-symbol">└</span> {{ $sub->series_title }}
                                    </td>
                                    <td style="text-align: center; font-weight: 600; color: #1e293b; font-size: 12px;">
                                        {{ $sub->compiled_period }}
                                    </td>
                                    <td style="text-align: center; font-size: 12px; font-weight: 600; color: #1e293b;">
                                        {{ $sub->active_period }}
                                    </td>
                                    <td style="text-align: center; font-size: 12px; font-weight: 600; color: #1e293b;">
                                        {{ $sub->storage_period ?: '—' }}
                                    </td>
                                    <td style="text-align: center; font-size: 12px; font-weight: 800; color: #1e293b;">
                                        {{ $sub->total_period }}
                                    </td>
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
                                    <tr class="record-item-row {{ $isSelected ? 'is-selected' : '' }}">
                                        <td style="text-align: center;">
                                            <input type="checkbox" wire:model.live="selectedIds" value="{{ $recIdStr }}" style="width: 14px; height: 14px; cursor: pointer; accent-color: #dc2626;">
                                        </td>
                                        <td></td>
                                        <td style="padding-left: 42px;">
                                            <span class="sub-branch-line">│</span>
                                            <span style="font-weight: 600; color: #1e293b;">{{ $rec->description }}</span>
                                        </td>
                                        <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->date_covered }}</td>
                                        <td colspan="3" style="text-align: center; color: #cbd5e1;">—</td>
                                        <td style="text-align: right;">
                                            <a href="{{ route('rdp.add-records.inventory-and-appraisal') }}" style="font-size: 11px; color: #dc2626; text-decoration: none; font-weight: 600;">Manage</a>
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        @else
                            <!-- DIRECT CHILD RECORDS UNDER ROOT SERIES -->
                            @foreach($root->direct_records as $rec)
                                @php
                                    $recIdStr = (string)$rec->id;
                                    $isSelected = in_array($recIdStr, $selectedIds);
                                @endphp
                                <tr class="record-item-row {{ $isSelected ? 'is-selected' : '' }}">
                                    <td style="text-align: center;">
                                        <input type="checkbox" wire:model.live="selectedIds" value="{{ $recIdStr }}" style="width: 14px; height: 14px; cursor: pointer; accent-color: #dc2626;">
                                    </td>
                                    <td></td>
                                    <td style="padding-left: 28px;">
                                        <span class="sub-branch-line">│</span>
                                        <span style="font-weight: 600; color: #1e293b;">{{ $rec->description }}</span>
                                    </td>
                                    <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->date_covered }}</td>
                                    <td colspan="3" style="text-align: center; color: #cbd5e1;">—</td>
                                    <td style="text-align: right;">
                                        <a href="{{ route('rdp.add-records.inventory-and-appraisal') }}" style="font-size: 11px; color: #dc2626; text-decoration: none; font-weight: 600;">Manage</a>
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    @empty
                        <tr>
                            <td colspan="8" style="padding: 36px; text-align: center; color: #64748b;">
                                No expired records ready for disposal found matching filter criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- PRINT PREVIEW MODAL (OFFICIAL NAP FORM 3 REVISED 2012 PDF LAYOUT) -->
    @if($showPrintModal)
        <div class="modal-overlay" wire:click.self="closePrintModal">
            <div class="modal-content">
                <div style="display: flex; justify-content: space-between; align-items: center;" class="no-print">
                    <div style="color: #ffffff; font-size: 16px; font-weight: 800;">
                        Print Preview: NAP Form No. 3 (Revised 2012)
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" onclick="window.print()" class="nap-btn nap-btn-primary" style="background: #16a34a;">
                            🖨️ Print Document
                        </button>
                        <button type="button" wire:click="closePrintModal" class="nap-btn nap-btn-secondary">
                            ✕ Close
                        </button>
                    </div>
                </div>

                <!-- Printable Sheet Matching the Official PDF Layout -->
                <div class="print-sheet">
                    <!-- Top Form ID Line -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; font-size: 8.5px;">
                        <div>
                            <div style="font-weight: bold;">NAP Form No. 3</div>
                            <div style="font-style: italic;">Revised 2012</div>
                        </div>
                        <div style="font-style: italic; font-size: 8.5px;">
                            Accomplish in 3 copies
                        </div>
                    </div>

                    <!-- Header Box -->
                    <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 9px;">
                        <tr>
                            <td rowspan="2" style="width: 50%; border: 1px solid #000; text-align: center; padding: 10px 8px; vertical-align: middle;">
                                <div style="font-weight: bold; font-size: 11px; text-transform: uppercase;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                                <div style="font-size: 9px; font-style: italic;">Pambansang Sinupan ng Pilipinas</div>
                                <div style="font-weight: 800; font-size: 11.5px; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
                                    REQUEST FOR AUTHORITY TO DISPOSE OF RECORDS
                                </div>
                            </td>
                            <td style="width: 50%; border: 1px solid #000; padding: 6px 8px; vertical-align: top;">
                                <div><strong>AGENCY NAME:</strong> <span style="text-transform: uppercase;">{{ $agencyName }}</span></div>
                            </td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #000; padding: 6px 8px; vertical-align: top;">
                                <div><strong>ADDRESS:</strong> {{ $agencyAddress }}</div>
                            </td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #000; padding: 6px 8px;">
                                <strong>DATE:</strong> {{ $datePrepared }}
                            </td>
                            <td style="border: 1px solid #000; padding: 6px 8px;">
                                <strong>TELEPHONE NUMBER:</strong> {{ $telephoneNumber }}
                            </td>
                        </tr>
                    </table>

                    <!-- Official Table (Revised 2012) -->
                    <table class="print-table">
                        <thead>
                            <tr>
                                <th style="width: 12%;">GRDS/ RDS ITEM NO.</th>
                                <th style="width: 48%;">RECORD SERIES TITLE AND DESCRIPTION</th>
                                <th style="width: 20%;">PERIOD COVERED</th>
                                <th style="width: 20%;">RETENTION PERIOD AND PROVISION/S COMPLIED (If Any)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($hierarchyTree as $root)
                                <!-- Root Series Header Row -->
                                <tr style="background: #e5e5e5; font-weight: bold;">
                                    <td style="text-align: center;">{{ $root->item_number ?: '' }}</td>
                                    <td>{{ $root->series_title }}</td>
                                    <td style="text-align: center;">{{ $root->compiled_period }}</td>
                                    <td style="text-align: center;">{{ $root->total_period }}</td>
                                </tr>

                                @if($root->has_children)
                                    @foreach($root->sub_series as $sub)
                                        <tr style="font-weight: bold; background: #fafafa;">
                                            <td></td>
                                            <td style="padding-left: 14px;">└ {{ $sub->series_title }}</td>
                                            <td style="text-align: center;">{{ $sub->compiled_period }}</td>
                                            <td style="text-align: center;">{{ $sub->total_period }}</td>
                                        </tr>

                                        @foreach($sub->records as $rec)
                                            <tr>
                                                <td></td>
                                                <td style="padding-left: 28px;">{{ $rec->description }}</td>
                                                <td style="text-align: center;">{{ $rec->date_covered }}</td>
                                                <td style="text-align: center; color: #999;">—</td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                @else
                                    @foreach($root->direct_records as $rec)
                                        <tr>
                                            <td></td>
                                            <td style="padding-left: 20px;">{{ $rec->description }}</td>
                                            <td style="text-align: center;">{{ $rec->date_covered }}</td>
                                            <td style="text-align: center; color: #999;">—</td>
                                        </tr>
                                    @endforeach
                                @endif
                            @endforeach
                        </tbody>
                    </table>

                    <!-- Official Footer Blocks (Revised 2012) -->
                    <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; border-top: none; font-size: 8.5px; page-break-inside: avoid;">
                        <tr>
                            <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                                <strong>LOCATION OF RECORDS:</strong> {{ $allCompiledLocation }}
                            </td>
                            <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                                <strong>VOLUME IN CUBIC METER:</strong> {{ $allCompiledVolume }}
                            </td>
                        </tr>
                        <tr>
                            <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                                <strong>PREPARED BY:</strong> {{ $preparedBy }}
                            </td>
                            <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                                <strong>POSITION:</strong> {{ $preparedPosition }}
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" style="border: 1px solid #000; padding: 14px 10px; vertical-align: top;">
                                <strong>CERTIFIED AND APPROVED BY:</strong>
                                <div style="font-size: 8px; margin-top: 6px; font-style: italic; line-height: 1.4;">
                                    This is to certify that the above mentioned records are no longer needed and not involved nor connected in any administrative or judicial cases.
                                </div>
                                <div style="margin-top: 36px; text-align: center; border-bottom: 1px solid #000; width: 50%; margin-left: auto; margin-right: auto; font-weight: bold; font-size: 9px;">
                                    {{ $approvedBy }}
                                </div>
                                <div style="text-align: center; font-size: 8px; margin-top: 3px;">
                                    Name and Signature of Agency Head or Duly Authorized Representative
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
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
                        <textarea wire:model="editRemarks" rows="3" placeholder="Disposal notes, authority references..." style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;"></textarea>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                        <button type="button" wire:click="closeEditModal" class="nap-btn nap-btn-secondary">Cancel</button>
                        <button type="submit" class="nap-btn nap-btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- CREATE DISPOSAL CLUSTER MODAL -->
    @if($showClusterModal)
        <div class="modal-overlay" wire:click.self="closeClusterModal">
            <div class="modal-dialog">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">Create Request for Disposal Authority Cluster</h3>
                    <button type="button" wire:click="closeClusterModal" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #991b1b; font-weight: 600;">
                        🗑️ Packaging <strong>{{ count($selectedIds) }}</strong> selected expired records into a Request for Disposal Authority submission cluster.
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