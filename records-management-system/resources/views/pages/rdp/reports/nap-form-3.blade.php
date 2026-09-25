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
    public array $seriesSelectionMode = [];
    public array $selectedSubjectIds = [];
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

    // Edit Subject Modal Properties
    public bool $showEditSubjectModal = false;
    public ?int $editingSubjectId = null;
    public string $editSubjectDescription = '';
    public string $editSubjectDateCovered = '';
    public string $editSubjectVolume = '';
    public string $editSubjectLocation = '';
    public ?int $editSubjectMedium = null;
    public ?string $editSubjectRestriction = null;
    public ?string $editSubjectFrequency = null;
    public string $editSubjectTimeValue = 'T';
    public array $editSubjectUtilities = [];

    // Printable Custom Header & Signature Fields (NAP Form 3 Revised 2012)
    public string $agencyName = 'Camarines Sur Polytechnic Colleges';
    public string $agencyAddress = 'San Miguel, Nabua, Camarines Sur';
    public string $telephoneNumber = '(054) 288-1534 loc. 113';
    public string $orgUnit = 'Records Management Unit';
    public string $personInCharge = 'Gennica Aprille S. Penetrante';
    public string $datePrepared = '';

    // Signatories (NAP Form 3 Revised 2012 Official PDF)
    public string $preparedBy = 'Gennica Aprille S. Penetrante';
    public string $preparedPosition = '';
    public string $approvedBy = '';
    public string $approvedPosition = '';
    public bool $includeDescriptionOnPrint = false;

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

            $allRecords = $query->select('rdp_record.id', 'rdp_record.record_series_id')->get();
            $this->selectedIds = array_map('strval', $allRecords->pluck('id')->toArray());

            // Mark all series as 'series' mode
            $this->seriesSelectionMode = [];
            foreach ($allRecords->pluck('record_series_id')->filter()->unique() as $sId) {
                $this->seriesSelectionMode[(string)$sId] = 'series';
            }
            $this->selectedSubjectIds = [];
        } else {
            $this->selectedIds = [];
            $this->seriesSelectionMode = [];
            $this->selectedSubjectIds = [];
        }
    }

    public function toggleSeriesSelection(int $seriesId, array $childRecordIds): void
    {
        $strChildIds = array_map('strval', $childRecordIds);
        $seriesKey = (string)$seriesId;

        // Check if currently selected either via 'series' mode or any child
        $isCurrentlyChecked = ($this->seriesSelectionMode[$seriesKey] ?? null) === 'series'
            || !empty(array_intersect($strChildIds, $this->selectedIds));

        if ($isCurrentlyChecked) {
            // Deselect series and all child records
            $this->selectedIds = array_values(array_diff($this->selectedIds, $strChildIds));
            unset($this->seriesSelectionMode[$seriesKey]);
            foreach ($strChildIds as $id) {
                unset($this->selectedSubjectIds[$id]);
            }
        } else {
            // Select whole series: all records included in cluster, but on print preview only series will be visible
            $this->selectedIds = array_values(array_unique(array_merge($this->selectedIds, $strChildIds)));
            $this->seriesSelectionMode[$seriesKey] = 'series';
            foreach ($strChildIds as $id) {
                unset($this->selectedSubjectIds[$id]);
            }
        }
    }

    public function toggleSubjectSelection(int $recordId, int $seriesId, array $allSeriesRecordIds = []): void
    {
        $recIdStr = (string)$recordId;
        $seriesKey = (string)$seriesId;
        $isRecSelected = in_array($recIdStr, $this->selectedIds, true);

        if ($isRecSelected) {
            // If the series was previously in whole 'series' mode, preserve remaining records as explicit subjects
            if (($this->seriesSelectionMode[$seriesKey] ?? null) === 'series' && !empty($allSeriesRecordIds)) {
                foreach ($allSeriesRecordIds as $rid) {
                    $ridStr = (string)$rid;
                    if ($ridStr !== $recIdStr && in_array($ridStr, $this->selectedIds, true)) {
                        $this->selectedSubjectIds[$ridStr] = true;
                    }
                }
            }

            $this->selectedIds = array_values(array_diff($this->selectedIds, [$recIdStr]));
            unset($this->selectedSubjectIds[$recIdStr]);

            $strChildIds = array_map('strval', $allSeriesRecordIds);
            if (empty(array_intersect($strChildIds, $this->selectedIds))) {
                unset($this->seriesSelectionMode[$seriesKey]);
            } else {
                $this->seriesSelectionMode[$seriesKey] = 'subjects';
            }
        } else {
            $this->selectedIds[] = $recIdStr;
            $this->selectedIds = array_values(array_unique($this->selectedIds));
            $this->selectedSubjectIds[$recIdStr] = true;
            // Record series is automatically checked in 'subjects' mode
            $this->seriesSelectionMode[$seriesKey] = 'subjects';
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
            $this->seriesSelectionMode = [];
            $this->selectedSubjectIds = [];
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

        $sysTable = \Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        $this->includeDescriptionOnPrint = (\Illuminate\Support\Facades\DB::table($sysTable)->where('key', 'rdp_include_description_on_print')->value('value') === 'true');

        $this->showPrintModal = true;
    }

    public function closePrintModal(): void
    {
        $this->showPrintModal = false;
    }

    public function openEditSubjectModal(int $id): void
    {
        $rec = DB::table('rdp_record')->where('id', $id)->first();
        if ($rec) {
            $this->editingSubjectId = $rec->id;
            $this->editSubjectDescription = $rec->description ?? '';
            $this->editSubjectVolume = $rec->volume ?? '';
            $this->editSubjectLocation = $rec->records_location ?? '';
            $this->editSubjectMedium = $rec->records_medium ? (int)$rec->records_medium : null;
            $this->editSubjectRestriction = $rec->restriction ?? null;
            $this->editSubjectFrequency = $rec->frequence_use ?? null;
            $this->editSubjectTimeValue = $rec->time_value ?: 'T';

            // Period covered
            $period = DB::table('rdp_period_covered')->where('period_owner', $id)->orderBy('id', 'desc')->first();
            $this->editSubjectDateCovered = $period->date_covered ?? '';

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
    }

    public function saveEditSubject(): void
    {
        if (!$this->editingSubjectId) return;

        $cleanDesc = trim($this->editSubjectDescription);
        if (empty($cleanDesc)) {
            $this->errorMessage = 'Subject description cannot be empty.';
            return;
        }

        try {
            DB::beginTransaction();

            DB::table('rdp_record')
                ->where('id', $this->editingSubjectId)
                ->update([
                    'description'      => mb_strtoupper($cleanDesc),
                    'volume'           => mb_strtoupper(trim($this->editSubjectVolume)),
                    'records_location' => mb_strtoupper(trim($this->editSubjectLocation)),
                    'records_medium'   => $this->editSubjectMedium ?: null,
                    'restriction'      => $this->editSubjectRestriction ?: null,
                    'frequence_use'    => $this->editSubjectFrequency ?: null,
                    'time_value'       => $this->editSubjectTimeValue ?: 'T',
                    'updated_at'       => Carbon::now(),
                ]);

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

            $this->successMessage = "Record subject updated successfully.";
            $this->closeEditSubjectModal();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to update record: ' . $e->getMessage();
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
        $this->seriesSelectionMode = [];
        $this->selectedSubjectIds = [];
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

        $sysTable = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        $includeDescriptionOnPrint = DB::table($sysTable)
            ->where('key', 'rdp_include_description_on_print')
            ->value('value') === 'true';

        if ($allRecords->isEmpty()) {
            return [
                'hierarchyTree'             => [],
                'officesList'               => $officesList,
                'totalItemsCount'           => 0,
                'allCompiledLocation'       => '',
                'allCompiledVolume'         => '',
                'userOfficeCode'            => $userOfficeCode,
                'userOfficeName'            => $userOfficeName,
                'isSadm'                    => $isSadm,
                'includeDescriptionOnPrint' => $includeDescriptionOnPrint,
                'cleanVal'                  => fn($v) => $this->cleanVal($v),
                'mediaList'                 => DB::table('rdp_recorded_value')->orderBy('medium_name', 'asc')->get(),
                'restrictionsList'          => DB::table('rdp_restriction_type')->orderBy('restriction_value', 'asc')->get(),
                'frequenciesList'           => DB::table('rdp_frequence_use')->orderBy('freq_type', 'asc')->get(),
                'timeValuesList'            => DB::table('rdp_time_value')->orderBy('char_value', 'asc')->get(),
                'utilityValuesList'         => DB::table('rdp_utility_medium')->orderBy('utility_name', 'asc')->get(),
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
                'remarks'       => $root->remarks ?? '',
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
                $rootVols = [];
                $rootMediums = [];
                $rootRestrictions = [];
                $rootLocs = [];
                $rootFreqs = [];
                $rootDups = [];
                $rootTimes = [];
                $rootUtils = [];

                foreach ($sortedSubs as $sub) {
                    $subRecs = $recordsBySeries[$sub->id] ?? collect();
                    if ($subRecs->isEmpty()) continue; // Only show if used in Form 3!

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

                        $recMedium = '';
                        if (!empty($rec->records_medium)) {
                            $recMedium = $mediumsMap[$rec->records_medium] ?? (string)$rec->records_medium;
                        }

                        $recRestriction = !empty($rec->restriction) ? $rec->restriction : '';
                        $recFreq = !empty($rec->frequence_use) ? $rec->frequence_use : '';

                        $recDup = '';
                        if (!empty($rec->duplication_id) && isset($duplications[$rec->duplication_id])) {
                            $dupCodes = $duplications[$rec->duplication_id]->pluck('office_code')->unique()->values()->all();
                            $recDup = !empty($dupCodes) ? implode(', ', $dupCodes) : '';
                        }

                        $compiledDates[] = $rawDate;
                        $rootDates[] = $rawDate;
                        $compiledVols[] = $rec->volume;
                        $rootVols[] = $rec->volume;
                        $compiledMediums[] = $recMedium;
                        $rootMediums[] = $recMedium;
                        $compiledRestrictions[] = $recRestriction;
                        $rootRestrictions[] = $recRestriction;
                        $compiledLocs[] = $rec->records_location;
                        $rootLocs[] = $rec->records_location;
                        $compiledFreqs[] = $recFreq;
                        $rootFreqs[] = $recFreq;
                        $compiledDups[] = $recDup;
                        $rootDups[] = $recDup;
                        $compiledTimes[] = $rec->time_value;
                        $rootTimes[] = $rec->time_value;
                        $compiledUtils = array_merge($compiledUtils, $uRows);
                        $rootUtils = array_merge($rootUtils, $uRows);

                        $allLocs[] = $rec->records_location;
                        $allVols[] = $rec->volume;

                        $childItems[] = (object)[
                            'id'           => $rec->id,
                            'series_id'    => $sub->id,
                            'description'  => $rec->description,
                            'date_covered' => $this->formatItemDate($rawDate),
                            'volume'       => $rec->volume ?: '',
                            'medium'       => $recMedium,
                            'restriction'  => $recRestriction,
                            'location'     => $rec->records_location ?: '',
                            'frequence_use'=> $recFreq,
                            'duplication'  => $recDup,
                            'time_value'   => $rec->time_value ?: '',
                            'utility'      => $this->formatItemUtility($uRows),
                            'utility_abbr' => $this->compileUtility($uRows),
                        ];
                        $totalItemsCount++;
                    }

                    // Effective retention
                    $isPerm = (bool)($sub->is_retention_period_permanent ?? false) || strtolower(trim($sub->total_period ?? '')) === 'permanent';
                    $effActive = $sub->active_period ?: ($root->active_period ?: '');
                    $effStorage = $sub->storage_period ?: ($root->storage_period ?: '');
                    $effTotal = $sub->total_period ?: ($root->total_period ?: '');

                    $rootNode->sub_series[] = (object)[
                        'id'                  => $sub->id,
                        'series_title'        => $sub->series_title,
                        'shorted_type'        => $sub->shorted_type ?: $root->shorted_type,
                        'compiled_period'     => $this->compilePeriodCovered($compiledDates),
                        'compiled_volume'     => $this->compileVolume($compiledVols),
                        'compiled_medium'     => $this->compileMedium($compiledMediums),
                        'compiled_restriction'=> $this->compileRestriction($compiledRestrictions),
                        'compiled_location'   => $this->compileLocation($compiledLocs),
                        'compiled_freq'       => $this->compileFrequency($compiledFreqs),
                        'compiled_duplication'=> $this->compileDuplication($compiledDups),
                        'compiled_time'       => $this->compileTimeValue($compiledTimes),
                        'compiled_util'       => $this->compileUtility($compiledUtils),
                        'active_period'       => $isPerm ? 'PERMANENT' : $effActive,
                        'storage_period'      => $isPerm ? '' : $effStorage,
                        'total_period'        => $isPerm ? 'PERMANENT' : $effTotal,
                        'is_permanent'        => $isPerm,
                        'remarks'             => $sub->remarks ?: ($root->remarks ?: ''),
                        'records'             => $childItems,
                        'record_ids'          => array_column($childItems, 'id'),
                    ];
                }

                $isRootPerm = (bool)($root->is_retention_period_permanent ?? false) || strtolower(trim($root->total_period ?? '')) === 'permanent';
                $rootNode->compiled_period      = $this->compilePeriodCovered($rootDates);
                $rootNode->compiled_volume      = $this->compileVolume($rootVols);
                $rootNode->compiled_medium      = $this->compileMedium($rootMediums);
                $rootNode->compiled_restriction = $this->compileRestriction($rootRestrictions);
                $rootNode->compiled_location    = $this->compileLocation($rootLocs);
                $rootNode->compiled_freq        = $this->compileFrequency($rootFreqs);
                $rootNode->compiled_duplication = $this->compileDuplication($rootDups);
                $rootNode->compiled_time        = $this->compileTimeValue($rootTimes);
                $rootNode->compiled_util        = $this->compileUtility($rootUtils);
                $rootNode->active_period        = $isRootPerm ? 'PERMANENT' : ($root->active_period ?: '');
                $rootNode->storage_period       = $isRootPerm ? '' : ($root->storage_period ?: '');
                $rootNode->total_period         = $isRootPerm ? 'PERMANENT' : ($root->total_period ?: '');
                $rootNode->is_permanent         = $isRootPerm;
            } else {
                // Direct records under root
                $directRecs = $recordsBySeries[$root->id] ?? collect();
                if ($directRecs->isEmpty()) continue;

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

                    $recMedium = '';
                    if (!empty($rec->records_medium)) {
                        $recMedium = $mediumsMap[$rec->records_medium] ?? (string)$rec->records_medium;
                    }

                    $recRestriction = !empty($rec->restriction) ? $rec->restriction : '';
                    $recFreq = !empty($rec->frequence_use) ? $rec->frequence_use : '';

                    $recDup = '';
                    if (!empty($rec->duplication_id) && isset($duplications[$rec->duplication_id])) {
                        $dupCodes = $duplications[$rec->duplication_id]->pluck('office_code')->unique()->values()->all();
                        $recDup = !empty($dupCodes) ? implode(', ', $dupCodes) : '';
                    }

                    $compiledDates[] = $rawDate;
                    $compiledVols[] = $rec->volume;
                    $compiledMediums[] = $recMedium;
                    $compiledRestrictions[] = $recRestriction;
                    $compiledLocs[] = $rec->records_location;
                    $compiledFreqs[] = $recFreq;
                    $compiledDups[] = $recDup;
                    $compiledTimes[] = $rec->time_value;
                    $compiledUtils = array_merge($compiledUtils, $uRows);

                    $allLocs[] = $rec->records_location;
                    $allVols[] = $rec->volume;

                    $childItems[] = (object)[
                        'id'           => $rec->id,
                        'series_id'    => $root->id,
                        'description'  => $rec->description,
                        'date_covered' => $this->formatItemDate($rawDate),
                        'volume'       => $rec->volume ?: '',
                        'medium'       => $recMedium,
                        'restriction'  => $recRestriction,
                        'location'     => $rec->records_location ?: '',
                        'frequence_use'=> $recFreq,
                        'duplication'  => $recDup,
                        'time_value'   => $rec->time_value ?: '',
                        'utility'      => $this->formatItemUtility($uRows),
                        'utility_abbr' => $this->compileUtility($uRows),
                    ];
                    $totalItemsCount++;
                }

                $isPerm = (bool)($root->is_retention_period_permanent ?? false) || strtolower(trim($root->total_period ?? '')) === 'permanent';

                $rootNode->compiled_period      = $this->compilePeriodCovered($compiledDates);
                $rootNode->compiled_volume      = $this->compileVolume($compiledVols);
                $rootNode->compiled_medium      = $this->compileMedium($compiledMediums);
                $rootNode->compiled_restriction = $this->compileRestriction($compiledRestrictions);
                $rootNode->compiled_location    = $this->compileLocation($compiledLocs);
                $rootNode->compiled_freq        = $this->compileFrequency($compiledFreqs);
                $rootNode->compiled_duplication = $this->compileDuplication($compiledDups);
                $rootNode->compiled_time        = $this->compileTimeValue($compiledTimes);
                $rootNode->compiled_util        = $this->compileUtility($compiledUtils);
                $rootNode->active_period        = $isPerm ? 'PERMANENT' : ($root->active_period ?: '');
                $rootNode->storage_period       = $isPerm ? '' : ($root->storage_period ?: '');
                $rootNode->total_period         = $isPerm ? 'PERMANENT' : ($root->total_period ?: '');
                $rootNode->is_permanent         = $isPerm;
                $rootNode->direct_records       = $childItems;
                $rootNode->record_ids           = array_column($childItems, 'id');
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
            'hierarchyTree'             => $tree,
            'officesList'               => $officesList,
            'totalItemsCount'           => $totalItemsCount,
            'allCompiledLocation'       => $this->compileLocation($allLocs),
            'allCompiledVolume'         => $this->compileVolume($allVols),
            'userOfficeCode'            => $userOfficeCode,
            'userOfficeName'            => $userOfficeName,
            'isSadm'                    => $isSadm,
            'includeDescriptionOnPrint' => $includeDescriptionOnPrint,
            'cleanVal'                  => fn($v) => $this->cleanVal($v),
            'seriesSelectionMode'       => $this->seriesSelectionMode,
            'selectedSubjectIds'        => $this->selectedSubjectIds,
            'compileLocationHelper'     => fn(array $locs) => $this->compileLocation($locs),
            'compileVolumeHelper'       => fn(array $vols) => $this->compileVolume($vols),
            'mediaList'                 => DB::table('rdp_recorded_value')->orderBy('medium_name', 'asc')->get(),
            'restrictionsList'          => DB::table('rdp_restriction_type')->orderBy('restriction_value', 'asc')->get(),
            'frequenciesList'           => DB::table('rdp_frequence_use')->orderBy('freq_type', 'asc')->get(),
            'timeValuesList'            => DB::table('rdp_time_value')->orderBy('char_value', 'asc')->get(),
            'utilityValuesList'         => DB::table('rdp_utility_medium')->orderBy('utility_name', 'asc')->get(),
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

        /* Print Modal & Sheet Styles - NAP Form No. 3 (Revised 2012 Portrait) */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .modal-content { background: #94a3b8; width: 100%; max-width: 900px; max-height: 94vh; border-radius: 14px; overflow-y: auto; box-shadow: 0 20px 40px rgba(0,0,0,0.3); padding: 24px; display: flex; flex-direction: column; gap: 20px; }
        .modal-dialog { background: #ffffff; width: 100%; max-width: 600px; border-radius: 14px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); padding: 24px; }

        .print-sheet {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            background: #ffffff;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            padding: 36px 32px;
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
            margin-top: 0;
            table-layout: fixed;
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
            .print-sheet { box-shadow: none !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; }
            @page { size: legal portrait; margin: 0.5in; }
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
                Print Preview
            </button>
            <button type="button" wire:click="openClusterModal" class="nap-btn nap-btn-primary" {{ empty($selectedIds) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' }}>
                Create Disposal Cluster ({{ count($selectedIds) }})
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
                        <span style="color: #dc2626;">🏢</span>
                        <span>Office: {{ $userOfficeCode ?? 'N/A' }}</span>
                    </div>
                @endif

                @if($search || ($isSadm && !empty($officeFilter) && $officeFilter !== $userOfficeCode) || count($selectedIds) > 0)
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
                <div style="font-size: 13px; font-weight: 700; color: #dc2626;">
                    {{ count($selectedIds) }} expired records selected
                </div>
            @endif
        </div>

        <!-- MAIN HIERARCHICAL NAP FORM 3 TABLE (OFFICIAL 4-COLUMN MATRIX) -->
        <div style="overflow-x: auto;">
            <table class="nap-table">
                <thead>
                    <tr>
                        <th style="width: 56px; text-align: center; padding: 8px 4px;">
                            <div style="display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                <input type="checkbox" wire:model.live="selectAll" style="width: 15px; height: 15px; cursor: pointer; accent-color: #dc2626;" title="Select / Deselect All">
                                <span style="width: 20px; height: 20px; display: inline-block;"></span>
                            </div>
                        </th>
                        <th style="width: 110px; text-align: center;">GRDS/ RDS ITEM NO.</th>
                        <th style="min-width: 260px;">RECORD SERIES TITLE AND DESCRIPTION</th>
                        <th style="width: 160px; text-align: center;">PERIOD COVERED</th>
                        <th style="width: 260px; text-align: center;">RETENTION PERIOD AND PROVISION/S COMPLIED (If Any)</th>
                        <th style="width: 80px; text-align: right;">ACTION</th>
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
                                            $isSeriesChecked = (($seriesSelectionMode[(string)$root->id] ?? null) === 'series')
                                                || !empty(array_intersect($strIds, $selectedIds));
                                        @endphp
                                        <input type="checkbox" wire:click="toggleSeriesSelection({{ $root->id }}, {{ json_encode($root->record_ids) }})" {{ $isSeriesChecked ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #dc2626;" title="Select record series">
                                    @elseif($root->has_children)
                                        @php
                                            $allRootChildIds = [];
                                            foreach ($root->sub_series as $s) {
                                                foreach ($s->record_ids as $rid) {
                                                    $allRootChildIds[] = $rid;
                                                }
                                            }
                                            $strRootIds = array_map('strval', $allRootChildIds);
                                            $isRootChecked = (($seriesSelectionMode[(string)$root->id] ?? null) === 'series')
                                                || !empty(array_intersect($strRootIds, $selectedIds));
                                        @endphp
                                        @if(!empty($allRootChildIds))
                                            <input type="checkbox" wire:click="toggleSeriesSelection({{ $root->id }}, {{ json_encode($allRootChildIds) }})" {{ $isRootChecked ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #dc2626;" title="Select record series group">
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
                            <td style="text-align: center; font-weight: 800; font-size: 13.5px; color: #1e293b;">
                                {{ $root->item_number ?: '—' }}
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
                                        <span style="font-size: 11px; padding: 1px 6px; border-radius: 4px; background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; font-weight: 700;">
                                            {{ $root->shorted_type }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            @if(!$root->has_children)
                                <td style="text-align: center; font-weight: 600; color: #334155; font-size: 12px;">{{ $root->compiled_period }}</td>
                                <td style="text-align: center; font-size: 12px; font-weight: 700; color: #0f172a;">
                                    {{ $root->total_period }}
                                    @if($root->remarks)
                                        <span style="font-weight: normal; color: #64748b; font-size: 11px; display: block;">{{ $root->remarks }}</span>
                                    @endif
                                </td>
                            @else
                                <td colspan="2" style="background: #f8fafc;"></td>
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
                                                    $isSeriesChecked = (($seriesSelectionMode[(string)$sub->id] ?? null) === 'series')
                                                        || !empty(array_intersect($strIds, $selectedIds));
                                                @endphp
                                                <input type="checkbox" wire:click="toggleSeriesSelection({{ $sub->id }}, {{ json_encode($sub->record_ids) }})" {{ $isSeriesChecked ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #dc2626;" title="Select record series">
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
                                    <td style="text-align: center; color: #94a3b8; font-size: 12px;">—</td>
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
                                    <td style="text-align: center; font-size: 12px; font-weight: 700; color: #0f172a;">
                                        {{ $sub->total_period }}
                                        @php $subRem = $sub->remarks ?: $root->remarks; @endphp
                                        @if($subRem)
                                            <span style="font-weight: normal; color: #64748b; font-size: 11px; display: block;">{{ $subRem }}</span>
                                        @endif
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <span style="color: #94a3b8; font-size: 11px;">—</span>
                                    </td>
                                </tr>

                                <!-- CHILD RECORDS (SUBJECTS) UNDER THIS SUB-SERIES -->
                                @foreach($sub->records as $rec)
                                    @php
                                        $recIdStr = (string)$rec->id;
                                        $isSelected = in_array($recIdStr, $selectedIds, true);
                                    @endphp
                                    <tr class="record-item-row {{ $isSelected ? 'is-selected' : '' }}" x-show="!isRootCollapsed('root-{{ $root->id }}') && !isSubjectsCollapsed('sub-{{ $sub->id }}')">
                                        <td style="text-align: center; padding: 6px 4px; white-space: nowrap;">
                                            <div style="display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                                <input type="checkbox" wire:click="toggleSubjectSelection({{ $rec->id }}, {{ $sub->id }}, {{ json_encode($sub->record_ids) }})" {{ $isSelected ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #dc2626;" title="Select subject">
                                                <span style="width: 20px; height: 20px; display: inline-block;"></span>
                                            </div>
                                        </td>
                                        <td></td>
                                        <td style="padding-left: 42px;">
                                            <span class="sub-branch-line">│</span>
                                            <span style="font-weight: 600; color: #1e293b;">{{ $rec->description }}</span>
                                        </td>
                                        <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->date_covered }}</td>
                                        <td style="text-align: center; color: #cbd5e1;">—</td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <button type="button" wire:click="openEditSubjectModal({{ $rec->id }})" class="nap-btn nap-btn-secondary" style="padding: 4px 8px; font-size: 11px;">
                                                Edit
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        @else
                            <!-- DIRECT CHILD RECORDS UNDER ROOT SERIES (IF NO SUBSERIES) -->
                            @foreach($root->direct_records as $rec)
                                @php
                                    $recIdStr = (string)$rec->id;
                                    $isSelected = in_array($recIdStr, $selectedIds, true);
                                @endphp
                                <tr class="record-item-row {{ $isSelected ? 'is-selected' : '' }}" x-show="!isSubjectsCollapsed('root-{{ $root->id }}')">
                                    <td style="text-align: center; padding: 6px 4px; white-space: nowrap;">
                                        <div style="display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                            <input type="checkbox" wire:click="toggleSubjectSelection({{ $rec->id }}, {{ $root->id }}, {{ json_encode($root->record_ids) }})" {{ $isSelected ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #dc2626;" title="Select subject">
                                            <span style="width: 20px; height: 20px; display: inline-block;"></span>
                                        </div>
                                    </td>
                                    <td></td>
                                    <td style="padding-left: 28px;">
                                        <span class="sub-branch-line">│</span>
                                        <span style="font-weight: 600; color: #1e293b;">{{ $rec->description }}</span>
                                    </td>
                                    <td style="text-align: center; color: #475569; font-size: 12px;">{{ $rec->date_covered }}</td>
                                    <td style="text-align: center; color: #cbd5e1;">—</td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button type="button" wire:click="openEditSubjectModal({{ $rec->id }})" class="nap-btn nap-btn-secondary" style="padding: 4px 8px; font-size: 11px;">
                                            Edit
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    @empty
                        <tr>
                            <td colspan="6" style="padding: 36px; text-align: center; color: #64748b;">
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
            $previewLocs = [];
            $previewVols = [];

            if ($hasSelection) {
                foreach ($hierarchyTree as $root) {
                    if (!$root->has_children) {
                        $rootChildStrIds = array_map('strval', $root->record_ids);
                        $seriesMode = $seriesSelectionMode[(string)$root->id] ?? null;
                        $isSeriesSelected = ($seriesMode === 'series') || !empty(array_intersect($rootChildStrIds, $selectedIds));

                        if (!$isSeriesSelected) {
                            continue;
                        }

                        $flattenedItems[] = [
                            'type' => 'root_standalone',
                            'root' => $root,
                        ];
                        if (!empty($root->compiled_location)) $previewLocs[] = $root->compiled_location;
                        if (!empty($root->compiled_volume)) $previewVols[] = $root->compiled_volume;

                        // Conditional inclusion for subjects:
                        // If user selected the series directly, only the series is visible.
                        // If user selected specific subject(s), only those specific subject(s) are visible.
                        if ($seriesMode !== 'series') {
                            foreach ($root->direct_records as $rec) {
                                $recIdStr = (string)$rec->id;
                                if (!empty($selectedSubjectIds[$recIdStr]) || in_array($recIdStr, $selectedIds, true)) {
                                    $flattenedItems[] = [
                                        'type'   => 'record',
                                        'rec'    => $rec,
                                        'indent' => 20,
                                    ];
                                }
                            }
                        }
                    } else {
                        $selectedSubs = [];
                        foreach ($root->sub_series as $sub) {
                            $subChildStrIds = array_map('strval', $sub->record_ids);
                            $subMode = $seriesSelectionMode[(string)$sub->id] ?? null;
                            $isSubSelected = ($subMode === 'series') || !empty(array_intersect($subChildStrIds, $selectedIds));

                            if ($isSubSelected) {
                                $selectedSubs[] = [
                                    'sub'     => $sub,
                                    'subMode' => $subMode,
                                ];
                            }
                        }

                        if (empty($selectedSubs)) {
                            continue;
                        }

                        $flattenedItems[] = [
                            'type' => 'root_header',
                            'root' => $root,
                        ];

                        foreach ($selectedSubs as $subData) {
                            $sub = $subData['sub'];
                            $subMode = $subData['subMode'];

                            $flattenedItems[] = [
                                'type'   => 'sub_series',
                                'sub'    => $sub,
                                'root'   => $root,
                                'indent' => 14,
                            ];
                            if (!empty($sub->compiled_location)) $previewLocs[] = $sub->compiled_location;
                            if (!empty($sub->compiled_volume)) $previewVols[] = $sub->compiled_volume;

                            if ($subMode !== 'series') {
                                foreach ($sub->records as $rec) {
                                    $recIdStr = (string)$rec->id;
                                    if (!empty($selectedSubjectIds[$recIdStr]) || in_array($recIdStr, $selectedIds, true)) {
                                        $flattenedItems[] = [
                                            'type'   => 'record',
                                            'rec'    => $rec,
                                            'indent' => 28,
                                        ];
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

            $effectivePrintLocation = ($hasSelection && !empty($previewLocs)) ? $compileLocationHelper($previewLocs) : '';
            $effectivePrintVolume = ($hasSelection && !empty($previewVols)) ? $compileVolumeHelper($previewVols) : '';
        @endphp
        <div class="modal-overlay" wire:click.self="closePrintModal">
            <div class="modal-content">
                <div style="display: flex; justify-content: space-between; align-items: center;" class="no-print">
                    <div>
                        <div style="color: #ffffff; font-size: 16px; font-weight: 800;">
                            Print Preview: NAP Form No. 3 (Revised 2012)
                        </div>
                        <div style="color: #cbd5e1; font-size: 12px; margin-top: 2px;">
                            @if($hasSelection)
                                Showing only selected Record Series / Subjects ({{ count($selectedIds) }} records selected).
                            @else
                                Official Request for Authority to Dispose of Records Preview (No records selected — Blank Template).
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
                            ? max(60, 480 - (count($pageItems) * 22)) 
                            : max(60, 680 - (count($pageItems) * 22));
                    @endphp
                    <!-- Printable Sheet Matching the Official PDF Layout -->
                    <div class="print-sheet">
                        <!-- Top Form ID Line -->
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; font-size: 8.5px; font-family: Arial, sans-serif;">
                            <div>
                                <div style="font-weight: bold;">NAP Form No. 3</div>
                                <div style="font-style: italic;">Revised 2012</div>
                            </div>
                            <div style="font-style: italic; font-size: 8.5px;">
                                Accomplish in 3 copies
                            </div>
                        </div>

                        <!-- Header Box -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 9px; font-family: Arial, sans-serif; table-layout: fixed;">
                            <tr>
                                <td rowspan="2" style="width: 50%; border: 1px solid #000; text-align: center; padding: 6px; vertical-align: middle;">
                                    <div style="border: 1.5px solid #000; padding: 8px 6px; margin: 2px;">
                                        <div style="font-weight: bold; font-size: 10px; text-transform: uppercase;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                                        <div style="font-size: 8.5px; font-style: italic; margin: 2px 0 6px 0;">Pambansang Sinupan ng Pilipinas</div>
                                        <div style="font-weight: 800; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px;">
                                            REQUEST FOR AUTHORITY TO DISPOSE<br>OF RECORDS
                                        </div>
                                    </div>
                                </td>
                                <td style="width: 50%; border: 1px solid #000; padding: 6px 8px; vertical-align: top;">
                                    <div><strong>AGENCY NAME:</strong> <span style="text-transform: uppercase;">{{ $cleanVal($agencyName) }}</span></div>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #000; padding: 6px 8px; vertical-align: top;">
                                    <div><strong>ADDRESS:</strong> {{ $cleanVal($agencyAddress) }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #000; padding: 6px 8px;">
                                    <strong>DATE:</strong> {{ $cleanVal($datePrepared) }}
                                </td>
                                <td style="border: 1px solid #000; padding: 6px 8px;">
                                    <strong>TELEPHONE NUMBER:</strong>
                                </td>
                            </tr>
                        </table>

                        <!-- Official Table (Revised 2012) -->
                        <table class="print-table" style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 9px; margin-top: 0; border-top: none; table-layout: fixed;">
                            <thead>
                                <tr>
                                    <th style="width: 12%; border: 1px solid #000; padding: 6px 5px; text-align: center; font-weight: bold;">GRDS/ RDS ITEM NO.</th>
                                    <th style="width: 48%; border: 1px solid #000; padding: 6px 5px; text-align: center; font-weight: bold;">RECORD SERIES TITLE AND DESCRIPTION</th>
                                    <th style="width: 20%; border: 1px solid #000; padding: 6px 5px; text-align: center; font-weight: bold;">PERIOD COVERED</th>
                                    <th style="width: 20%; border: 1px solid #000; padding: 6px 5px; text-align: center; font-weight: bold;">RETENTION PERIOD AND PROVISION/S COMPLIED (If Any)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($pageItems as $item)
                                    @if($item['type'] === 'root_standalone')
                                        @php $root = $item['root']; @endphp
                                        <tr style="vertical-align: top;">
                                            <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;">{{ $cleanVal($root->item_number) }}</td>
                                            <td style="{{ $cellBorder }} text-align: left; padding: 4px 6px; font-weight: bold;">{{ strtoupper($cleanVal($root->series_title)) }}</td>
                                            <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;">{{ $cleanVal($root->compiled_period) }}</td>
                                            <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;">{{ $cleanVal(trim($root->total_period . ($root->remarks ? ' / ' . $root->remarks : ''))) }}</td>
                                        </tr>
                                    @elseif($item['type'] === 'root_header')
                                        @php $root = $item['root']; @endphp
                                        <tr style="vertical-align: top;">
                                            <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;">{{ $cleanVal($root->item_number) }}</td>
                                            <td style="{{ $cellBorder }} text-align: left; padding: 4px 6px; font-weight: bold;">{{ strtoupper($cleanVal($root->series_title)) }}</td>
                                            <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;"></td>
                                            <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;"></td>
                                        </tr>
                                    @elseif($item['type'] === 'sub_series')
                                        @php $sub = $item['sub']; @endphp
                                        <tr style="vertical-align: top;">
                                            <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;"></td>
                                            <td style="{{ $cellBorder }} text-align: left; padding: 4px 6px 4px {{ $item['indent'] ?? 14 }}px; font-weight: normal;">
                                                └ {{ $cleanVal($sub->series_title) }}
                                            </td>
                                            <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;">{{ $cleanVal($sub->compiled_period) }}</td>
                                            <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;">{{ $cleanVal(trim($sub->total_period . (($sub->remarks ?: $root->remarks) ? ' / ' . ($sub->remarks ?: $root->remarks) : ''))) }}</td>
                                        </tr>
                                    @elseif($item['type'] === 'record')
                                        @php $rec = $item['rec']; @endphp
                                        <tr style="vertical-align: top;">
                                            <td style="{{ $cellBorder }} text-align: center; padding: 3px 6px;"></td>
                                            <td style="{{ $cellBorder }} text-align: left; padding: 3px 6px 3px {{ $item['indent'] ?? 20 }}px; font-size: 8.5px;">
                                                {{ $cleanVal($rec->description) }}
                                            </td>
                                            <td style="{{ $cellBorder }} text-align: center; padding: 3px 6px;">{{ $cleanVal($rec->date_covered) }}</td>
                                            <td style="{{ $cellBorder }} text-align: center; padding: 3px 6px;"></td>
                                        </tr>
                                    @endif
                                @endforeach

                                <!-- Tall vertical column lines extending to bottom table border -->
                                <tr style="height: {{ $computedFiller }}px;">
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                    <td style="{{ $cellBorder }}">&nbsp;</td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Official Footer Blocks (Revised 2012) -->
                        @if($isLastPage)
                            <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; border-top: none; font-size: 8.5px; page-break-inside: avoid; font-family: Arial, sans-serif;">
                                <tr>
                                    <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                                        <strong>LOCATION OF RECORDS:</strong> {{ $cleanVal($effectivePrintLocation) }}
                                    </td>
                                    <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                                        <strong>VOLUME IN CUBIC METER:</strong> {{ $cleanVal($effectivePrintVolume) }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                                        <strong>PREPARED BY:</strong> {{ $cleanVal($preparedBy) }}
                                    </td>
                                    <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                                        <strong>POSITION:</strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="border: 1px solid #000; padding: 14px 10px; vertical-align: top;">
                                        <strong>CERTIFIED AND APPROVED BY:</strong>
                                        <div style="font-size: 8px; margin-top: 6px; text-align: center; line-height: 1.4;">
                                            This is to certify that the above mentioned records are no longer needed and<br>not involved nor connected in any administrative or judicial cases.
                                        </div>
                                        <div style="margin-top: 36px; text-align: center; border-bottom: 1px solid #000; width: 45%; margin-left: auto; margin-right: 40px; font-weight: bold; font-size: 9px; min-height: 13px;">
                                            {{ $cleanVal($approvedBy) }}
                                        </div>
                                        <div style="text-align: center; font-size: 8px; margin-top: 3px; width: 45%; margin-left: auto; margin-right: 40px; line-height: 1.3;">
                                            Name and Signature of Agency Head<br>or Duly Authorized Representative
                                        </div>
                                    </td>
                                </tr>
                            </table>
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
                        <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;">Edit Record Subject</h3>
                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #64748b;">Update and fix details, typos, or classifications for this record.</p>
                    </div>
                    <button type="button" wire:click="closeEditSubjectModal" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <form wire:submit.prevent="saveEditSubject" style="display: flex; flex-direction: column; gap: 14px;">
                    <!-- Subject Description -->
                    <div>
                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Subject / Description</label>
                        <textarea wire:model="editSubjectDescription" rows="2" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;" placeholder="Enter record subject title or description" required></textarea>
                    </div>

                    <!-- Row 1: Period Covered & Volume -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Period Covered / Inclusive Dates</label>
                            <input type="text" wire:model="editSubjectDateCovered" placeholder="e.g. 2020-2024 or 2023" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Volume Amount & Unit</label>
                            <input type="text" wire:model="editSubjectVolume" placeholder="e.g. 2 papers, 1 box, 2 bundles" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                        </div>
                    </div>

                    <!-- Row 2: Location & Medium -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Location of Records</label>
                            <input type="text" wire:model="editSubjectLocation" placeholder="e.g. Cabinet 2L, Shelf 3" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Records Medium</label>
                            <select wire:model="editSubjectMedium" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box; background: #fff;">
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
                            <select wire:model="editSubjectRestriction" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box; background: #fff;">
                                <option value="" {{ empty($editSubjectRestriction) ? 'selected' : '' }}>Select Restriction...</option>
                                @foreach($restrictionsList as $rest)
                                    <option value="{{ $rest->restriction_value }}" {{ $editSubjectRestriction === $rest->restriction_value ? 'selected' : '' }}>{{ $rest->restriction_value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Frequency of Use</label>
                            <select wire:model="editSubjectFrequency" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box; background: #fff;">
                                <option value="" {{ empty($editSubjectFrequency) ? 'selected' : '' }}>Select Frequency...</option>
                                @foreach($frequenciesList as $freq)
                                    <option value="{{ $freq->freq_type }}" {{ $editSubjectFrequency === $freq->freq_type ? 'selected' : '' }}>{{ $freq->freq_type }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <!-- Row 4: Time Value & Utility Value -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Time Value (T/P)</label>
                            <select wire:model="editSubjectTimeValue" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box; background: #fff;">
                                @foreach($timeValuesList as $tv)
                                    <option value="{{ $tv->char_value }}" {{ $editSubjectTimeValue === $tv->char_value ? 'selected' : '' }}>{{ $tv->char_value }} — {{ $tv->description }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Utility Value</label>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px; padding-top: 4px;">
                                @foreach($utilityValuesList as $uv)
                                    <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; color: #334155; background: #f8fafc; border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 6px; cursor: pointer;">
                                        <input type="checkbox" wire:model="editSubjectUtilities" value="{{ $uv->id }}" style="accent-color: #dc2626;">
                                        <span>{{ $uv->utility_name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
                        <button type="button" wire:click="closeEditSubjectModal" class="nap-btn nap-btn-secondary">Cancel</button>
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