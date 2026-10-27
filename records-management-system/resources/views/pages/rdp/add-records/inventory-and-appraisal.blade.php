<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Carbon\Carbon;

new #[Layout('layouts.rdp')] #[Title('Inventory and Appraisal')] class extends Component {
    use WithFileUploads;

    // Series Modal Hierarchy State
    public bool $showSeriesModal = false;
    public string $parentSeriesTitle = '';
    public bool $showParentDropdown = false;
    public array $subsections = [];
    public ?int $activeSubDropdownIndex = null;
    public ?string $seriesModalError = null;

    // Selected Series Staged State (Deferred DB save)
    public ?int $record_series_id = null;
    public ?string $selectedSeriesTitle = null;
    public array $selectedSeriesHierarchy = [];
    public bool $hasPredefinedRemarks = false;
    public bool $hasPredefinedRetention = false;

    // Series Type Filter for Search
    public mixed $selectedSeriesTypeFilter = null;

    public function updatedSelectedSeriesTypeFilter($val): void
    {
        if (empty($val)) {
            $this->selectedSeriesTypeFilter = null;
        } else {
            $this->selectedSeriesTypeFilter = (int)$val;
        }
        $this->selectedParentId = null;
        $this->selectedParentTypeId = null;
        $this->selectedParentOffice = null;
        $this->seriesModalError = null;
    }

    public function setSeriesTypeFilter(?int $typeId): void
    {
        $this->selectedSeriesTypeFilter = $typeId;
        $this->selectedParentId = null;
        $this->selectedParentTypeId = null;
        $this->selectedParentOffice = null;
    }

    // Predefined vs Custom Series Flag
    public bool $isCustomSeries = false;

    // Selected Date Staged State (Deferred DB save)
    public string $date_covered = '';

    // Form Input Properties
    public string $description = '';
    public string $volume = '';
    public string $volume_amount = '';
    public string $volume_unit = 'Folder';
    public ?int $records_medium = null;
    public ?string $restriction = null;
    public string $records_location = '';
    public bool $showLocationDropdown = false;
    public ?int $activeBatchLocationIndex = null;
    public ?string $frequence_use = null;
    public ?string $duplication = null;
    public array $duplicate_offices = [];
    public array $duplicate_file_view = []; // [office_code => bool] controls whether office can view attached file
    public bool $spreadToDuplicateOffices = false; // Combined Office Collective: spread record requests to duplicate offices
    public string $duplicate_search = '';
    public bool $showDuplicateDropdown = false;
    public ?string $time_value = 'T';
    public array $utility_values = []; // Multi-choice array of selected utility IDs
    public string $retention_period = '';
    public bool $is_permanent = false;
    public string $active_period = '';
    public string $storage_period = '';
    public string $disposition_provision = '';

    // Duplicate Offices Modal
    public bool $showDuplicateModal = false;
    public ?int $duplicateModalBatchIndex = null;
    public string $duplicateModalSearch = '';

    public function openDuplicateModal(?int $batchIndex = null): void
    {
        $this->duplicateModalBatchIndex = $batchIndex;
        $this->duplicateModalSearch = '';
        $this->showDuplicateModal = true;
    }

    public function closeDuplicateModal(): void
    {
        $this->showDuplicateModal = false;
        $this->duplicateModalBatchIndex = null;
        $this->duplicateModalSearch = '';
    }

    public function toggleModalDuplicateOffice(string $code): void
    {
        $bIdx = $this->duplicateModalBatchIndex;
        if ($bIdx !== null) {
            $dups = $this->batchItems[$bIdx]['duplicate_offices'] ?? [];
            if (in_array($code, $dups, true)) {
                $this->batchItems[$bIdx]['duplicate_offices'] = array_values(array_filter($dups, fn($c) => $c !== $code));
                unset($this->batchItems[$bIdx]['duplicate_file_view'][$code]);
            } else {
                $this->batchItems[$bIdx]['duplicate_offices'][] = $code;
                $this->batchItems[$bIdx]['duplicate_file_view'][$code] = false;
            }
        } else {
            if (in_array($code, $this->duplicate_offices, true)) {
                $this->duplicate_offices = array_values(array_filter($this->duplicate_offices, fn($c) => $c !== $code));
                unset($this->duplicate_file_view[$code]);
            } else {
                $this->duplicate_offices[] = $code;
                $this->duplicate_file_view[$code] = false;
            }
        }
    }

    public function toggleModalFileView(string $code): void
    {
        $bIdx = $this->duplicateModalBatchIndex;
        if ($bIdx !== null) {
            $cur = !empty($this->batchItems[$bIdx]['duplicate_file_view'][$code]);
            $this->batchItems[$bIdx]['duplicate_file_view'][$code] = !$cur;
        } else {
            $cur = !empty($this->duplicate_file_view[$code]);
            $this->duplicate_file_view[$code] = !$cur;
        }
    }

    public function selectAllModalDuplicateOffices(): void
    {
        $bIdx = $this->duplicateModalBatchIndex;
        $user = Auth::user();
        $userOffice = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
        $officesTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $allCodes = DB::table($officesTable)->where('is_active', true)->whereNotIn('office_code', ['ORIGIN', '[H]'])->pluck('office_code')->toArray();
        $validCodes = array_values(array_filter($allCodes, fn($c) => !empty($c) && $c !== $userOffice));

        if ($bIdx !== null) {
            $this->batchItems[$bIdx]['duplicate_offices'] = $validCodes;
            foreach ($validCodes as $c) {
                if (!isset($this->batchItems[$bIdx]['duplicate_file_view'][$c])) {
                    $this->batchItems[$bIdx]['duplicate_file_view'][$c] = false;
                }
            }
        } else {
            $this->duplicate_offices = $validCodes;
            foreach ($validCodes as $c) {
                if (!isset($this->duplicate_file_view[$c])) {
                    $this->duplicate_file_view[$c] = false;
                }
            }
        }
    }

    public function clearAllModalDuplicateOffices(): void
    {
        $bIdx = $this->duplicateModalBatchIndex;
        if ($bIdx !== null) {
            $this->batchItems[$bIdx]['duplicate_offices'] = [];
            $this->batchItems[$bIdx]['duplicate_file_view'] = [];
        } else {
            $this->duplicate_offices = [];
            $this->duplicate_file_view = [];
        }
    }

    // Legacy Office Collective compatibility
    public array $collectiveOffices = [];
    public bool $showCollectiveModal = false;
    public string $collectiveSearch = '';
    public array $tempCollectiveOffices = [];

    public function toggleDuplicateFileView(string $code): void
    {
        $this->duplicate_file_view[$code] = !($this->duplicate_file_view[$code] ?? false);
    }

    public function toggleBatchDuplicateFileView(int $bIdx, string $code): void
    {
        if (isset($this->batchItems[$bIdx])) {
            $curr = $this->batchItems[$bIdx]['duplicate_file_view'][$code] ?? false;
            $this->batchItems[$bIdx]['duplicate_file_view'][$code] = !$curr;
        }
    }

    public function openCollectiveModal(): void
    {
        $this->tempCollectiveOffices = $this->collectiveOffices;
        $this->collectiveSearch = '';
        $this->showCollectiveModal = true;
    }

    public function closeCollectiveModal(): void
    {
        $this->showCollectiveModal = false;
        $this->collectiveSearch = '';
    }

    public function toggleCollectiveOffice(string $code): void
    {
        if (in_array($code, $this->tempCollectiveOffices, true)) {
            $this->tempCollectiveOffices = array_values(array_diff($this->tempCollectiveOffices, [$code]));
        } else {
            $this->tempCollectiveOffices[] = $code;
        }
    }

    public function selectAllCollectiveOffices(): void
    {
        $user = Auth::user();
        $userOffice = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
        $officesTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $allCodes = DB::table($officesTable)->where('is_active', true)->pluck('office_code')->toArray();
        $this->tempCollectiveOffices = array_values(array_filter($allCodes, fn($c) => !empty($c) && $c !== $userOffice));
    }

    public function clearAllTempCollectiveOffices(): void
    {
        $this->tempCollectiveOffices = [];
    }

    public function applyCollectiveModal(): void
    {
        $user = Auth::user();
        $userOffice = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
        $this->collectiveOffices = array_values(array_filter(array_unique($this->tempCollectiveOffices), fn($c) => !empty($c) && $c !== $userOffice));
        $this->showCollectiveModal = false;
    }

    public function removeCollectiveOffice(int $index): void
    {
        if (isset($this->collectiveOffices[$index])) {
            unset($this->collectiveOffices[$index]);
            $this->collectiveOffices = array_values($this->collectiveOffices);
        }
    }

    public function clearCollectiveOffices(): void
    {
        $this->collectiveOffices = [];
    }

    // Verification Modal State & Actions
    public bool $showVerificationModal = false;

    public function openVerificationModal(): void
    {
        if (!$this->validateRecordData()) {
            return;
        }
        $this->showVerificationModal = true;
    }

    public function closeVerificationModal(): void
    {
        $this->showVerificationModal = false;
    }

    public function confirmCreateRecord(): void
    {
        $this->showVerificationModal = false;
        $this->createRecord();
    }

    public function confirmSaveDraft(): void
    {
        $this->showVerificationModal = false;
        $this->saveDraft();
    }

    public function getMediumNameById(?int $id): string
    {
        if (!$id) return 'Paper';
        $med = DB::table('rdp_recorded_value')->where('id', $id)->value('medium_name');
        return $med ?: 'Paper';
    }

    public function formatUtilityValuesForPreview(array $utilityIds): string
    {
        if (empty($utilityIds)) return '—';
        $names = DB::table('rdp_utility_medium')
            ->whereIn('id', $utilityIds)
            ->pluck('utility_name')
            ->toArray();
        if (empty($names)) return '—';

        $map = [
            'Administrative' => 'Adm',
            'Archival'       => 'Arc',
            'Fiscal'         => 'F',
            'Legal'          => 'L',
        ];
        $abbrs = [];
        foreach ($names as $n) {
            $abbrs[] = $map[$n] ?? strtoupper(substr($n, 0, 3));
        }
        return implode(', ', array_unique($abbrs));
    }

    public function formatPreviewDate(?string $date): string
    {
        if (empty($date)) return '—';
        try {
            return \App\Helpers\RegisterQueryHelper::formatRdpPeriodDate($date, '—');
        } catch (\Throwable) {
            return $date;
        }
    }

    public bool $isAppraising = false;
    public bool $dcsAdminForward = false;
    public bool $dcsOfficeLock = false;
    public bool $dcsOfficeBlocked = false;
    public bool $dcsOfficeAlreadyDone = false;
    public bool $dcsAdminAlreadySent = false;
    public ?int $dcsRequestId = null;

    public function updatedVolumeAmount($val): void
    {
        $val = trim((string)$val);
        if (!empty($val)) {
            $this->volume = $val . ' ' . ($this->volume_unit ?: 'Folder');
        } else {
            $this->volume = '';
        }
    }

    public function updatedVolumeUnit($val): void
    {
        $amt = trim((string)$this->volume_amount);
        if (!empty($amt)) {
            $this->volume = $amt . ' ' . ($val ?: 'Folder');
        } else {
            $this->volume = '';
        }
    }

    public $uploadedFile = null;
    public ?string $successMessage = null;
    public ?string $errorMessage = null;
    public bool $showValidationErrors = false;

    // Mode State: 'single', 'multi' (formerly batch), 'batch' (combined period range & volume breakdown)
    public string $entryMode = 'single';
    public bool $isBatchMode = false;
    public array $batchItems = [];

    // Batch (Combined Period Covered & Volume) State
    public bool $showBatchModal = false;
    public string $batch_start_date = '';
    public string $batch_end_date = '';
    public string $batch_start_day = '';
    public string $batch_start_month = '';
    public string $batch_start_year = '';
    public string $batch_end_day = '';
    public string $batch_end_month = '';
    public string $batch_end_year = '';
    public string $batch_volume_unit = 'papers';
    public string $batch_default_volume = '';
    public bool $batch_dropdown_expanded = true;
    public array $batch_sub_periods = [];

    public function applyBatchDefaultVolume(): void
    {
        $val = trim($this->batch_default_volume);
        if ($val === '') return;
        foreach ($this->batch_sub_periods as $idx => $sub) {
            $this->batch_sub_periods[$idx]['volume'] = $val;
        }
    }

    public function formatSubPeriodYear(?string $startStr, ?string $endStr): string
    {
        try {
            $startY = !empty($startStr) ? \Carbon\Carbon::parse($startStr)->year : null;
            $endY = !empty($endStr) ? \Carbon\Carbon::parse($endStr)->year : null;
            if ($startY && $endY) {
                return ($startY === $endY) ? (string)$startY : ($startY . '–' . $endY);
            }
            if ($startY) return (string)$startY;
            if ($endY) return (string)$endY;
        } catch (\Throwable) {}
        return '';
    }

    public function formatSubPeriodDefaultDescription(?string $startStr, ?string $endStr, ?string $subject = null): string
    {
        $subj = trim($subject ?? $this->description);
        $year = $this->formatSubPeriodYear($startStr, $endStr);
        if (!empty($subj) && !empty($year)) {
            return $subj . ' ' . $year;
        }
        if (!empty($subj)) {
            return $subj;
        }
        return $year;
    }

    public function syncSubPeriodDescriptions(?string $subject = null): void
    {
        $subj = trim($subject ?? $this->description);
        foreach ($this->batch_sub_periods as $idx => $sub) {
            $curDesc = trim((string)($sub['description'] ?? ''));
            if (empty($curDesc) || empty($sub['is_custom_desc'])) {
                $this->batch_sub_periods[$idx]['description'] = $this->formatSubPeriodDefaultDescription(
                    $sub['start_date'] ?? '',
                    $sub['end_date'] ?? '',
                    $subj
                );
            }
        }
    }

    public function updatedDescription($val): void
    {
        $this->syncSubPeriodDescriptions((string)$val);
    }

    public function updatedBatchStartDate(): void
    {
        $this->syncDmyFromBatchDates();
        $this->generateBatchSubPeriods();
    }

    public function updatedBatchEndDate(): void
    {
        $this->syncDmyFromBatchDates();
        $this->generateBatchSubPeriods();
    }

    public function updatedBatchStartDay(): void { $this->syncBatchDatesFromDmy(); }
    public function updatedBatchStartMonth(): void { $this->syncBatchDatesFromDmy(); }
    public function updatedBatchStartYear(): void { $this->syncBatchDatesFromDmy(); }
    public function updatedBatchEndDay(): void { $this->syncBatchDatesFromDmy(); }
    public function updatedBatchEndMonth(): void { $this->syncBatchDatesFromDmy(); }
    public function updatedBatchEndYear(): void { $this->syncBatchDatesFromDmy(); }

    public function syncBatchDatesFromDmy(bool $forceDefaults = false): void
    {
        if (!empty($this->batch_start_year)) {
            $sY = (int)$this->batch_start_year;
            $sM = !empty($this->batch_start_month) ? (int)$this->batch_start_month : 1;
            $sD = !empty($this->batch_start_day) ? (int)$this->batch_start_day : 1;

            if ($sY >= 1800 && $sY <= 2200 && checkdate($sM, $sD, $sY)) {
                $this->batch_start_date = sprintf('%04d-%02d-%02d', $sY, $sM, $sD);
                $this->batch_start_day = (string)$sD;
                $this->batch_start_month = (string)$sM;
            }
        }

        if (!empty($this->batch_end_year)) {
            $eY = (int)$this->batch_end_year;
            $eM = !empty($this->batch_end_month) ? (int)$this->batch_end_month : 12;

            if ($eY >= 1800 && $eY <= 2200) {
                if (!empty($this->batch_end_day)) {
                    $eD = (int)$this->batch_end_day;
                } else {
                    $eD = Carbon::create($eY, $eM, 1)->endOfMonth()->day;
                }

                if (checkdate($eM, $eD, $eY)) {
                    $this->batch_end_date = sprintf('%04d-%02d-%02d', $eY, $eM, $eD);
                    $this->batch_end_day = (string)$eD;
                    $this->batch_end_month = (string)$eM;
                }
            }
        }

        if (!empty($this->batch_start_date) && !empty($this->batch_end_date)) {
            $this->generateBatchSubPeriods();
        }
    }

    public function syncDmyFromBatchDates(): void
    {
        if (!empty($this->batch_start_date)) {
            try {
                $c = Carbon::parse($this->batch_start_date);
                $this->batch_start_day = (string)$c->day;
                $this->batch_start_month = (string)$c->month;
                $this->batch_start_year = (string)$c->year;
            } catch (\Throwable $e) {}
        }
        if (!empty($this->batch_end_date)) {
            try {
                $c = Carbon::parse($this->batch_end_date);
                $this->batch_end_day = (string)$c->day;
                $this->batch_end_month = (string)$c->month;
                $this->batch_end_year = (string)$c->year;
            } catch (\Throwable $e) {}
        }
    }

    public function updatedBatchSubPeriods($value, $key): void
    {
        $parts = explode('.', (string)$key);
        if (count($parts) >= 2) {
            $pIdx = (int)$parts[0];
            $field = $parts[1];

            if ($field === 'description') {
                $this->batch_sub_periods[$pIdx]['is_custom_desc'] = !empty(trim((string)$value));
            } elseif (in_array($field, ['start_date', 'end_date'], true)) {
                if (!empty($value)) {
                    try {
                        $c = Carbon::parse($value);
                        if ($field === 'start_date') {
                            $this->batch_sub_periods[$pIdx]['start_day'] = (string)$c->day;
                            $this->batch_sub_periods[$pIdx]['start_month'] = (string)$c->month;
                            $this->batch_sub_periods[$pIdx]['start_year'] = (string)$c->year;
                        } else {
                            $this->batch_sub_periods[$pIdx]['end_day'] = (string)$c->day;
                            $this->batch_sub_periods[$pIdx]['end_month'] = (string)$c->month;
                            $this->batch_sub_periods[$pIdx]['end_year'] = (string)$c->year;
                        }
                    } catch (\Throwable $e) {}
                }
                if (empty($this->batch_sub_periods[$pIdx]['is_custom_desc'])) {
                    $this->batch_sub_periods[$pIdx]['description'] = $this->formatSubPeriodDefaultDescription(
                        $this->batch_sub_periods[$pIdx]['start_date'] ?? '',
                        $this->batch_sub_periods[$pIdx]['end_date'] ?? ''
                    );
                }
                $this->recalculateBatchRangeFromSubPeriods();
            } elseif (in_array($field, ['start_day', 'start_month', 'start_year', 'end_day', 'end_month', 'end_year'], true)) {
                $this->syncSubPeriodDmy($pIdx);
                if (empty($this->batch_sub_periods[$pIdx]['is_custom_desc'])) {
                    $this->batch_sub_periods[$pIdx]['description'] = $this->formatSubPeriodDefaultDescription(
                        $this->batch_sub_periods[$pIdx]['start_date'] ?? '',
                        $this->batch_sub_periods[$pIdx]['end_date'] ?? ''
                    );
                }
                $this->recalculateBatchRangeFromSubPeriods();
            }
        }
    }

    public function syncSubPeriodDmy(int $pIdx): void
    {
        if (!isset($this->batch_sub_periods[$pIdx])) {
            return;
        }

        $sub = &$this->batch_sub_periods[$pIdx];

        $sY = (int)($sub['start_year'] ?? 0);
        $sM = !empty($sub['start_month']) ? (int)$sub['start_month'] : 1;
        $sD = !empty($sub['start_day']) ? (int)$sub['start_day'] : 1;

        if ($sY >= 1800 && $sY <= 2200 && checkdate($sM, $sD, $sY)) {
            $sub['start_date'] = sprintf('%04d-%02d-%02d', $sY, $sM, $sD);
            $sub['start_day'] = (string)$sD;
            $sub['start_month'] = (string)$sM;
        }

        $eY = (int)($sub['end_year'] ?? 0);
        $eM = !empty($sub['end_month']) ? (int)$sub['end_month'] : 12;
        if ($eY >= 1800 && $eY <= 2200) {
            $eD = !empty($sub['end_day']) ? (int)$sub['end_day'] : Carbon::create($eY, $eM, 1)->endOfMonth()->day;
            if (checkdate($eM, $eD, $eY)) {
                $sub['end_date'] = sprintf('%04d-%02d-%02d', $eY, $eM, $eD);
                $sub['end_day'] = (string)$eD;
                $sub['end_month'] = (string)$eM;
            }
        }
    }

    public function recalculateBatchRangeFromSubPeriods(): void
    {
        if (empty($this->batch_sub_periods)) {
            return;
        }

        $startDates = [];
        $endDates = [];

        foreach ($this->batch_sub_periods as $sub) {
            if (!empty($sub['start_date'])) {
                $startDates[] = $sub['start_date'];
            }
            if (!empty($sub['end_date'])) {
                $endDates[] = $sub['end_date'];
            }
        }

        if (!empty($startDates)) {
            sort($startDates);
            $this->batch_start_date = $startDates[0];
        }

        if (!empty($endDates)) {
            sort($endDates);
            $this->batch_end_date = end($endDates);
        }

        $this->syncDmyFromBatchDates();
    }

    public function generateBatchSubPeriods(): void
    {
        if (empty($this->batch_start_date) || empty($this->batch_end_date)) {
            $this->syncBatchDatesFromDmy(forceDefaults: true);
        }

        if (empty($this->batch_start_date) || empty($this->batch_end_date)) {
            return;
        }

        try {
            $start = Carbon::parse($this->batch_start_date);
            $end = Carbon::parse($this->batch_end_date);
        } catch (\Throwable $e) {
            return;
        }

        if ($end->lt($start)) {
            return;
        }

        $startYear = (int)$start->year;
        $endYear = (int)$end->year;

        $existingVolumes = [];
        $existingDescriptions = [];
        $existingCustomFlags = [];
        foreach ($this->batch_sub_periods as $sub) {
            $key = ($sub['start_date'] ?? '') . '_' . ($sub['end_date'] ?? '');
            $existingVolumes[$key] = $sub['volume'] ?? '';
            if (!empty($sub['description'])) {
                $existingDescriptions[$key] = $sub['description'];
            }
            if (!empty($sub['is_custom_desc'])) {
                $existingCustomFlags[$key] = true;
            }
            if (!empty($sub['start_date'])) {
                $y = (int)substr($sub['start_date'], 0, 4);
                $existingVolumes['year_' . $y] = $sub['volume'] ?? '';
                if (!empty($sub['description'])) {
                    $existingDescriptions['year_' . $y] = $sub['description'];
                }
                if (!empty($sub['is_custom_desc'])) {
                    $existingCustomFlags['year_' . $y] = true;
                }
            }
        }

        $newSubPeriods = [];

        if ($startYear === $endYear) {
            $sStr = $start->format('Y-m-d');
            $eStr = $end->format('Y-m-d');
            $vol = $existingVolumes[$sStr . '_' . $eStr] ?? $existingVolumes['year_' . $startYear] ?? ($this->batch_default_volume ?: '');
            $desc = $existingDescriptions[$sStr . '_' . $eStr] ?? $existingDescriptions['year_' . $startYear] ?? $this->formatSubPeriodDefaultDescription($sStr, $eStr);
            $isCustom = $existingCustomFlags[$sStr . '_' . $eStr] ?? $existingCustomFlags['year_' . $startYear] ?? false;
            $subStart = Carbon::parse($sStr);
            $subEnd = Carbon::parse($eStr);
            $newSubPeriods[] = [
                'id'             => (string)Str::uuid(),
                'description'    => $desc,
                'is_custom_desc' => $isCustom,
                'start_date'     => $sStr,
                'end_date'       => $eStr,
                'start_day'      => (string)$subStart->day,
                'start_month'    => (string)$subStart->month,
                'start_year'     => (string)$subStart->year,
                'end_day'        => (string)$subEnd->day,
                'end_month'      => (string)$subEnd->month,
                'end_year'       => (string)$subEnd->year,
                'volume'         => $vol,
            ];
        } else {
            for ($y = $endYear; $y >= $startYear; $y--) {
                if ($y === $startYear) {
                    $sStr = $start->format('Y-m-d');
                    $eStr = Carbon::create($y, 12, 31)->format('Y-m-d');
                } elseif ($y === $endYear) {
                    $sStr = Carbon::create($y, 1, 1)->format('Y-m-d');
                    $eStr = $end->format('Y-m-d');
                } else {
                    $sStr = Carbon::create($y, 1, 1)->format('Y-m-d');
                    $eStr = Carbon::create($y, 12, 31)->format('Y-m-d');
                }

                $vol = $existingVolumes[$sStr . '_' . $eStr] ?? $existingVolumes['year_' . $y] ?? ($this->batch_default_volume ?: '');
                $desc = $existingDescriptions[$sStr . '_' . $eStr] ?? $existingDescriptions['year_' . $y] ?? $this->formatSubPeriodDefaultDescription($sStr, $eStr);
                $isCustom = $existingCustomFlags[$sStr . '_' . $eStr] ?? $existingCustomFlags['year_' . $y] ?? false;
                $subStart = Carbon::parse($sStr);
                $subEnd = Carbon::parse($eStr);

                $newSubPeriods[] = [
                    'id'             => (string)Str::uuid(),
                    'description'    => $desc,
                    'is_custom_desc' => $isCustom,
                    'start_date'     => $sStr,
                    'end_date'       => $eStr,
                    'start_day'      => (string)$subStart->day,
                    'start_month'    => (string)$subStart->month,
                    'start_year'     => (string)$subStart->year,
                    'end_day'        => (string)$subEnd->day,
                    'end_month'      => (string)$subEnd->month,
                    'end_year'       => (string)$subEnd->year,
                    'volume'         => $vol,
                ];
            }
        }

        $this->batch_sub_periods = $newSubPeriods;
    }

    public function addBatchSubPeriod(): void
    {
        $maxEndDate = null;
        if (!empty($this->batch_sub_periods)) {
            foreach ($this->batch_sub_periods as $sub) {
                if (!empty($sub['end_date'])) {
                    if ($maxEndDate === null || $sub['end_date'] > $maxEndDate) {
                        $maxEndDate = $sub['end_date'];
                    }
                }
            }
        }
        if (!$maxEndDate && !empty($this->batch_end_date)) {
            $maxEndDate = $this->batch_end_date;
        }

        if ($maxEndDate) {
            try {
                $lastEnd = Carbon::parse($maxEndDate);
                if ($lastEnd->month == 12 && $lastEnd->day >= 20) {
                    $nextYear = $lastEnd->year + 1;
                    $sStr = Carbon::create($nextYear, 1, 1)->format('Y-m-d');
                    $eStr = Carbon::create($nextYear, 12, 31)->format('Y-m-d');
                } else {
                    $nextStart = $lastEnd->copy()->addDay();
                    $sStr = $nextStart->format('Y-m-d');
                    $eStr = $nextStart->copy()->endOfYear()->format('Y-m-d');
                }
            } catch (\Throwable $e) {
                $sStr = Carbon::now()->format('Y-m-d');
                $eStr = Carbon::now()->endOfYear()->format('Y-m-d');
            }
        } else {
            $sStr = Carbon::now()->startOfYear()->format('Y-m-d');
            $eStr = Carbon::now()->endOfYear()->format('Y-m-d');
        }

        $cStart = Carbon::parse($sStr);
        $cEnd = Carbon::parse($eStr);

        $this->batch_sub_periods[] = [
            'id'             => (string)Str::uuid(),
            'description'    => $this->formatSubPeriodDefaultDescription($sStr, $eStr),
            'is_custom_desc' => false,
            'start_date'     => $sStr,
            'end_date'       => $eStr,
            'start_day'      => (string)$cStart->day,
            'start_month'    => (string)$cStart->month,
            'start_year'     => (string)$cStart->year,
            'end_day'        => (string)$cEnd->day,
            'end_month'      => (string)$cEnd->month,
            'end_year'       => (string)$cEnd->year,
            'volume'         => $this->batch_default_volume ?: '',
        ];

        $this->recalculateBatchRangeFromSubPeriods();
        $this->sortBatchSubPeriodsDesc();
    }

    public function sortBatchSubPeriodsDesc(): void
    {
        usort($this->batch_sub_periods, function($a, $b) {
            $dateA = $a['end_date'] ?: ($a['start_date'] ?? '');
            $dateB = $b['end_date'] ?: ($b['start_date'] ?? '');
            return strcmp($dateB, $dateA);
        });
        $this->batch_sub_periods = array_values($this->batch_sub_periods);
    }

    public function removeBatchSubPeriod(int $index): void
    {
        if (isset($this->batch_sub_periods[$index])) {
            unset($this->batch_sub_periods[$index]);
            $this->batch_sub_periods = array_values($this->batch_sub_periods);
            $this->recalculateBatchRangeFromSubPeriods();
        }
    }

    public function toggleBatchDropdown(): void
    {
        $this->batch_dropdown_expanded = !$this->batch_dropdown_expanded;
    }

    public function parseVolumeSegments(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return [];
        }

        // Clean leading dashes, bullets, spaces
        $input = ltrim($input, "-•* \t\n\r");

        $segments = [];
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*([a-zA-Z\s]+)?/u', $input, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $amount = (float)$m[1];
                $unit = isset($m[2]) ? trim($m[2]) : '';
                $unit = trim(preg_replace('/^(and|&|,)\s*/i', '', $unit));
                $unit = trim(preg_replace('/[,\.]+$/', '', $unit));
                if ($unit === '' && preg_match('/^\d+(\.\d+)?$/', $input)) {
                    $unit = 'Papers';
                }
                if ($amount > 0 || $unit !== '') {
                    $segments[] = [
                        'amount' => $amount,
                        'unit'   => $unit ?: 'Papers',
                    ];
                }
            }
        }

        return $segments;
    }

    public function canonicalizeVolumeUnit(string $unit): string
    {
        $u = strtolower(trim($unit));
        if (in_array($u, ['paper', 'papers', 'page', 'pages', 'pc', 'pcs', 'piece', 'pieces', 'leaf', 'leaves', 'sheet', 'sheets'], true)) {
            return 'Pages';
        }
        if (in_array($u, ['folder', 'folders', 'fldr', 'fldrs'], true)) {
            return 'Folder';
        }
        if (in_array($u, ['box', 'boxes', 'bx', 'bxs'], true)) {
            return 'Box';
        }
        if (in_array($u, ['archival box', 'archival boxes'], true)) {
            return 'Archival Box';
        }
        if (in_array($u, ['transfer box', 'transfer boxes'], true)) {
            return 'Transfer Box';
        }
        if (in_array($u, ['cubic meter', 'cubic meters', 'cu m', 'cu.m'], true)) {
            return 'Cubic Meter';
        }
        if (in_array($u, ['cubic feet', 'cubic foot', 'cu ft', 'cu.ft'], true)) {
            return 'Cubic Feet';
        }
        if (in_array($u, ['bundle', 'bundles', 'bdl'], true)) {
            return 'Bundle';
        }
        if (in_array($u, ['volume', 'volumes', 'vol', 'vols'], true)) {
            return 'Volume';
        }
        return Str::title($unit);
    }

    public function pluralizeVolumeUnit(string $unit, float|int $amount): string
    {
        if ($amount == 1) {
            $singular = rtrim($unit, 's');
            if (strtolower($singular) === 'boxe') return 'Box';
            if (strtolower($singular) === 'page') return 'Page';
            if (strtolower($singular) === 'paper') return 'Paper';
            return $singular;
        }

        $lower = strtolower($unit);
        if ($lower === 'box' || $lower === 'archival box' || $lower === 'transfer box') {
            return $unit . 'es';
        }
        if (str_ends_with($lower, 's')) {
            return $unit;
        }
        return $unit . 's';
    }

    public function compileBatchVolumeTotals(): array
    {
        $rawTotals = [];
        $displayUnits = [];

        foreach ($this->batch_sub_periods as $p) {
            $val = trim((string)($p['volume'] ?? ''));
            if ($val === '') continue;

            $segments = $this->parseVolumeSegments($val);
            if (empty($segments)) {
                if (is_numeric($val)) {
                    $canonical = 'Pages';
                    $rawTotals[$canonical] = ($rawTotals[$canonical] ?? 0) + (float)$val;
                    $displayUnits[$canonical] = 'Papers';
                }
                continue;
            }

            foreach ($segments as $seg) {
                $canonical = $this->canonicalizeVolumeUnit($seg['unit']);
                $rawTotals[$canonical] = ($rawTotals[$canonical] ?? 0) + (float)$seg['amount'];
                if (!isset($displayUnits[$canonical])) {
                    $origLower = strtolower($seg['unit']);
                    if (str_contains($origLower, 'paper')) {
                        $displayUnits[$canonical] = 'Papers';
                    } elseif (str_contains($origLower, 'page')) {
                        $displayUnits[$canonical] = 'Pages';
                    } else {
                        $displayUnits[$canonical] = $canonical;
                    }
                }
            }
        }

        // Apply DB conversion rules (e.g. 100 Pages = 1 Folder)
        try {
            $conversions = DB::table('rdp_volume_conversion as c')
                ->join('rdp_volume_value as std', 'c.value_standard', '=', 'std.volume_id')
                ->join('rdp_volume_value as conv', 'c.value_converted', '=', 'conv.volume_id')
                ->where('c.is_active', true)
                ->select(
                    'c.amount_standard',
                    'c.amount_converted',
                    'std.value_standard as standard_name',
                    'conv.value_standard as converted_name'
                )
                ->get();

            foreach ($conversions as $rule) {
                $stdCanon = $this->canonicalizeVolumeUnit($rule->standard_name);
                $convCanon = $this->canonicalizeVolumeUnit($rule->converted_name);

                if (isset($rawTotals[$stdCanon]) && $rawTotals[$stdCanon] >= $rule->amount_standard && $rule->amount_standard > 0) {
                    $multiplier = intdiv((int)$rawTotals[$stdCanon], (int)$rule->amount_standard);
                    $convertedAddition = $multiplier * (int)$rule->amount_converted;
                    $rawTotals[$convCanon] = ($rawTotals[$convCanon] ?? 0) + $convertedAddition;
                    $rawTotals[$stdCanon] = fmod($rawTotals[$stdCanon], (float)$rule->amount_standard);

                    if (!isset($displayUnits[$convCanon])) {
                        $displayUnits[$convCanon] = $convCanon;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fallback gracefully
        }

        return [
            'totals'        => $rawTotals,
            'display_units' => $displayUnits,
        ];
    }

    public function getBatchTotalVolume(): int
    {
        $compiled = $this->compileBatchVolumeTotals();
        $total = 0;
        foreach ($compiled['totals'] as $amt) {
            $total += (int)$amt;
        }
        return $total;
    }

    public function getBatchTotalVolumeFormatted(): string
    {
        $compiled = $this->compileBatchVolumeTotals();
        $totals = $compiled['totals'];
        $displayUnits = $compiled['display_units'];

        $parts = [];
        $order = ['Box', 'Archival Box', 'Transfer Box', 'Bundle', 'Volume', 'Cubic Meter', 'Cubic Feet', 'Folder', 'Pages'];
        $sortedKeys = array_unique(array_merge($order, array_keys($totals)));

        foreach ($sortedKeys as $key) {
            if (!isset($totals[$key])) continue;
            $amt = $totals[$key];
            if ($amt <= 0) continue;

            $unitName = $displayUnits[$key] ?? $key;
            $amtDisplay = (floor($amt) == $amt) ? (int)$amt : $amt;

            $plural = $this->pluralizeVolumeUnit($unitName, $amtDisplay);
            $parts[] = "{$amtDisplay} {$plural}";
        }

        return implode(', ', $parts);
    }

    public function switchToMultiMode(): void
    {
        $this->entryMode = 'multi';
        if (empty($this->batchItems)) {
            $this->addBatchItem();
        } else {
            $this->isBatchMode = true;
        }
    }

    public function openBatchModal(): void
    {
        $this->syncDmyFromBatchDates();
        $this->syncSubPeriodDescriptions();
        $this->showBatchModal = true;
    }

    public function closeBatchModal(): void
    {
        $this->showBatchModal = false;
    }

    public function applyBatchModal(): void
    {
        $this->showBatchModal = false;
    }

    public function switchToBatchMode(): void
    {
        $this->entryMode = 'batch';
        $this->isBatchMode = false;
        $this->syncDmyFromBatchDates();
        if (!empty($this->batch_start_date) && !empty($this->batch_end_date)) {
            $this->generateBatchSubPeriods();
        } else {
            $this->syncSubPeriodDescriptions();
        }
    }

    public function getDefaultMediumId(): ?int
    {
        try {
            $id = DB::table('rdp_recorded_value')
                ->whereRaw('LOWER(medium_name) = ?', ['paper'])
                ->value('id');
            return $id ? (int)$id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function addBatchItem(): void
    {
        $this->clearMessages();
        $this->entryMode = 'multi';

        $defaultMedium = $this->records_medium ?: $this->getDefaultMediumId();
        $defaultRestriction = $this->restriction ?: 'Restricted';
        $defaultDate = !empty($this->date_covered) ? $this->date_covered : Carbon::now()->format('Y-m-d');

        if (!$this->isBatchMode) {
            $this->isBatchMode = true;
            $this->batchItems = [
                [
                    'description'           => $this->description,
                    'date_covered'          => $defaultDate,
                    'volume'                => $this->volume,
                    'records_medium'        => $defaultMedium,
                    'restriction'           => $defaultRestriction,
                    'records_location'      => $this->records_location,
                    'frequence_use'         => $this->frequence_use,
                    'utility_values'        => $this->utility_values,
                    'time_value'            => $this->time_value ?: 'T',
                    'duplicate_offices'     => $this->duplicate_offices,
                    'duplicate_file_view'   => $this->duplicate_file_view,
                    'duplicate_search'      => '',
                    'showDuplicateDropdown' => false,
                ],
                [
                    'description'           => '',
                    'date_covered'          => $defaultDate,
                    'volume'                => '',
                    'records_medium'        => $defaultMedium,
                    'restriction'           => $defaultRestriction,
                    'records_location'      => $this->records_location,
                    'frequence_use'         => $this->frequence_use,
                    'utility_values'        => $this->utility_values,
                    'time_value'            => $this->time_value ?: 'T',
                    'duplicate_offices'     => [],
                    'duplicate_file_view'   => [],
                    'duplicate_search'      => '',
                    'showDuplicateDropdown' => false,
                ],
            ];
        } else {
            $last = end($this->batchItems) ?: [];
            $this->batchItems[] = [
                'description'           => '',
                'date_covered'          => $last['date_covered'] ?? $defaultDate,
                'volume'                => '',
                'records_medium'        => $last['records_medium'] ?? $defaultMedium,
                'restriction'           => $last['restriction'] ?? $defaultRestriction,
                'records_location'      => $last['records_location'] ?? $this->records_location,
                'frequence_use'         => $last['frequence_use'] ?? $this->frequence_use,
                'utility_values'        => $last['utility_values'] ?? $this->utility_values,
                'time_value'            => $last['time_value'] ?? ($this->time_value ?: 'T'),
                'duplicate_offices'     => [],
                'duplicate_file_view'   => [],
                'duplicate_search'      => '',
                'showDuplicateDropdown' => false,
            ];
        }
    }

    public function removeBatchItem(int $index): void
    {
        if (isset($this->batchItems[$index])) {
            unset($this->batchItems[$index]);
            $this->batchItems = array_values($this->batchItems);
        }

        if (count($this->batchItems) <= 1) {
            $this->switchToSingleMode();
        }
    }

    public function duplicateBatchItem(int $index): void
    {
        if (isset($this->batchItems[$index])) {
            $clone = $this->batchItems[$index];
            $clone['description'] = $clone['description'] ? ($clone['description'] . ' (COPY)') : '';
            $clone['duplicate_offices'] = $clone['duplicate_offices'] ?? [];
            $clone['duplicate_file_view'] = $clone['duplicate_file_view'] ?? [];
            $clone['duplicate_search'] = '';
            $clone['showDuplicateDropdown'] = false;
            array_splice($this->batchItems, $index + 1, 0, [$clone]);
            $this->batchItems = array_values($this->batchItems);
        }
    }

    public function switchToSingleMode(): void
    {
        $this->entryMode = 'single';
        if (!empty($this->batchItems)) {
            $first = $this->batchItems[0];
            $this->description       = $first['description'] ?? $this->description;
            $this->date_covered      = $first['date_covered'] ?? $this->date_covered;
            $this->volume            = $first['volume'] ?? $this->volume;
            $this->records_medium    = $first['records_medium'] ?? $this->records_medium;
            $this->restriction       = $first['restriction'] ?? $this->restriction;
            $this->records_location  = $first['records_location'] ?? $this->records_location;
            $this->frequence_use     = $first['frequence_use'] ?? $this->frequence_use;
            $this->utility_values    = $first['utility_values'] ?? $this->utility_values;
            $this->duplicate_offices = $first['duplicate_offices'] ?? $this->duplicate_offices;
        }
        $this->batchItems = [];
        $this->isBatchMode = false;
        $this->showBatchModal = false;
    }

    public function addBatchDuplicateOffice(int $bIdx, ?string $officeCode = null): void
    {
        if (!isset($this->batchItems[$bIdx])) {
            return;
        }

        $search = $this->batchItems[$bIdx]['duplicate_search'] ?? '';
        $codeToAdd = strtoupper(trim($officeCode ?? $search));
        if (empty($codeToAdd)) {
            return;
        }

        $officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $exists = DB::table($officeTable)
            ->where('is_active', true)
            ->where(function($q) use ($codeToAdd) {
                $q->where('office_code', $codeToAdd)
                  ->orWhere('office_name', 'ilike', $codeToAdd);
            })
            ->first();

        $offices = $this->batchItems[$bIdx]['duplicate_offices'] ?? [];
        $views = $this->batchItems[$bIdx]['duplicate_file_view'] ?? [];

        if ($exists) {
            $realCode = $exists->office_code;
            if (!in_array($realCode, $offices, true)) {
                $offices[] = $realCode;
                $views[$realCode] = false;
            }
        } elseif (!empty($officeCode) && !in_array($officeCode, $offices, true)) {
            $offices[] = $officeCode;
            $views[$officeCode] = false;
        }

        $this->batchItems[$bIdx]['duplicate_offices'] = array_values($offices);
        $this->batchItems[$bIdx]['duplicate_file_view'] = $views;
        $this->batchItems[$bIdx]['duplicate_search'] = '';
        $this->batchItems[$bIdx]['showDuplicateDropdown'] = false;
    }

    public function removeBatchDuplicateOffice(int $bIdx, int $dupIdx): void
    {
        if (isset($this->batchItems[$bIdx]['duplicate_offices'][$dupIdx])) {
            $code = $this->batchItems[$bIdx]['duplicate_offices'][$dupIdx];
            unset($this->batchItems[$bIdx]['duplicate_offices'][$dupIdx]);
            if (isset($this->batchItems[$bIdx]['duplicate_file_view'][$code])) {
                unset($this->batchItems[$bIdx]['duplicate_file_view'][$code]);
            }
            $this->batchItems[$bIdx]['duplicate_offices'] = array_values($this->batchItems[$bIdx]['duplicate_offices']);
        }
    }

    public function clearBatchDuplicateOffices(int $bIdx): void
    {
        if (isset($this->batchItems[$bIdx])) {
            $this->batchItems[$bIdx]['duplicate_offices'] = [];
            $this->batchItems[$bIdx]['duplicate_file_view'] = [];
            $this->batchItems[$bIdx]['duplicate_search'] = '';
            $this->batchItems[$bIdx]['showDuplicateDropdown'] = false;
        }
    }

    public function updatedRecordsLocation(): void
    {
        $this->showLocationDropdown = true;
    }

    public function updatedRecordsMedium($value): void
    {
        if ($value === '' || $value === null) {
            $this->records_medium = null;

            return;
        }

        $this->records_medium = (int) $value;
    }

    public function selectLocationSuggestion(string $location): void
    {
        $this->records_location = $location;
        $this->showLocationDropdown = false;
    }

    public function getOfficePastLocations(): array
    {
        $suggestions = $this->getLocationSuggestionsWithTypes();
        return array_values(array_unique(array_map(fn($s) => $s['name'], $suggestions)));
    }

    public function getLocationSuggestionsWithTypes(): array
    {
        $user = Auth::user();
        $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;

        $results = [];
        $seen = [];

        // 1. Primary Source: rdp_location_suggestion table
        if (Schema::hasTable('rdp_location_suggestion')) {
            try {
                $query = DB::table('rdp_location_suggestion');

                if (!empty($userOfficeCode)) {
                    $query->where(function ($q) use ($userOfficeCode) {
                        $q->where('recorded_at_office', $userOfficeCode)
                          ->orWhereNull('recorded_at_office');
                    });
                }

                if (!empty($this->record_series_id)) {
                    $query->orderByRaw("CASE WHEN suggestion_from = ? THEN 0 ELSE 1 END", [$this->record_series_id]);
                }
                $query->orderBy('id', 'desc');

                $records = $query->get();
                foreach ($records as $row) {
                    $name = trim((string)$row->location_name);
                    if ($name === '') continue;
                    $key = mb_strtoupper($name);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $isSeriesMatch = (!empty($this->record_series_id) && (int)$row->suggestion_from === (int)$this->record_series_id);
                        $results[] = [
                            'id'              => (int) $row->id,
                            'name'            => $name,
                            'type'            => $row->location_type ?: 'Office Location',
                            'recorded_at'     => $row->recorded_at_office,
                            'is_series_match' => $isSeriesMatch,
                            'is_suggestion'   => true,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                logger()->warning("Error fetching rdp_location_suggestion: " . $e->getMessage());
            }
        }

        // 2. Secondary Source: Past distinct records from rdp_record table
        if (Schema::hasTable('rdp_record')) {
            try {
                $query = DB::table('rdp_record')
                    ->whereNotNull('records_location')
                    ->where('records_location', '<>', '');

                if (!empty($userOfficeCode)) {
                    $query->where('office_own', $userOfficeCode);
                }

                $raw = $query->distinct()->pluck('records_location')->all();
                foreach ($raw as $loc) {
                    $name = trim((string)$loc);
                    if ($name === '') continue;
                    $key = mb_strtoupper($name);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $lower = strtolower($name);
                        $type = 'Office Location';
                        if (str_contains($lower, 'cabinet')) {
                            $type = 'Cabinet';
                        } elseif (str_contains($lower, 'shelf')) {
                            $type = 'Shelf';
                        } elseif (str_contains($lower, 'room') || str_contains($lower, 'facility')) {
                            $type = 'Room / Facility';
                        } elseif (str_contains($lower, 'box') || str_contains($lower, 'drawer')) {
                            $type = 'Box / Container';
                        }

                        $results[] = [
                            'id'              => null,
                            'name'            => $name,
                            'type'            => $type,
                            'recorded_at'     => $userOfficeCode,
                            'is_series_match' => false,
                            'is_suggestion'   => false,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                logger()->warning("Error fetching past rdp_record locations: " . $e->getMessage());
            }
        }

        return $results;
    }

    public function getLocationSuggestions(?string $search = null): array
    {
        $all = $this->getLocationSuggestionsWithTypes();
        $term = trim((string)($search !== null ? $search : $this->records_location));

        if ($term === '') {
            return array_slice($all, 0, 10);
        }

        $termLower = strtolower($term);
        $matches = array_filter($all, function ($item) use ($termLower) {
            return str_contains(strtolower($item['name']), $termLower);
        });

        usort($matches, function ($a, $b) use ($termLower) {
            $aLower = strtolower($a['name']);
            $bLower = strtolower($b['name']);
            $aStarts = str_starts_with($aLower, $termLower);
            $bStarts = str_starts_with($bLower, $termLower);

            if ($aStarts && !$bStarts) return -1;
            if (!$aStarts && $bStarts) return 1;

            return strcmp($aLower, $bLower);
        });

        return array_values(array_slice($matches, 0, 10));
    }

    public function getBatchLocationSuggestions(int $bIdx): array
    {
        $term = $this->batchItems[$bIdx]['records_location'] ?? '';
        return $this->getLocationSuggestions($term);
    }

    public function selectBatchLocationSuggestion(int $bIdx, string $location): void
    {
        if (isset($this->batchItems[$bIdx])) {
            $this->batchItems[$bIdx]['records_location'] = $location;
        }
        $this->activeBatchLocationIndex = null;
    }

    public function rememberLocationSuggestion(?string $locationName, ?int $seriesId = null, ?string $officeCode = null, string $type = 'Office'): void
    {
        $cleanName = trim((string)$locationName);
        if ($cleanName === '') {
            return;
        }

        if (!Schema::hasTable('rdp_location_suggestion')) {
            return;
        }

        try {
            $officeCode = $officeCode ?: (Auth::user()?->details?->office?->office_code ?? Auth::user()?->details?->office_code);
            $cleanUpper = mb_strtoupper($cleanName);

            // Infer a friendly location type if default
            $detectedType = $type;
            $lower = strtolower($cleanName);
            if (str_contains($lower, 'cabinet')) {
                $detectedType = 'Cabinet';
            } elseif (str_contains($lower, 'shelf')) {
                $detectedType = 'Shelf';
            } elseif (str_contains($lower, 'room') || str_contains($lower, 'building') || str_contains($lower, 'bldg')) {
                $detectedType = 'Room / Facility';
            } elseif (str_contains($lower, 'box') || str_contains($lower, 'bin') || str_contains($lower, 'drawer') || str_contains($lower, 'folder')) {
                $detectedType = 'Box / Container';
            }

            // Check if already registered for this office
            $exists = DB::table('rdp_location_suggestion')
                ->whereRaw('LOWER(location_name) = ?', [strtolower($cleanName)])
                ->where(function ($q) use ($officeCode) {
                    if (!empty($officeCode)) {
                        $q->where('recorded_at_office', $officeCode)
                          ->orWhereNull('recorded_at_office');
                    }
                })
                ->exists();

            if (!$exists) {
                DB::table('rdp_location_suggestion')->insert([
                    'location_name'      => $cleanUpper,
                    'suggestion_from'    => $seriesId,
                    'location_type'      => $detectedType,
                    'recorded_at_office' => $officeCode,
                    'created_at'         => now(),
                ]);
            }
        } catch (\Throwable $e) {
            logger()->warning("Failed remembering location suggestion: " . $e->getMessage());
        }
    }

    public function addQuickLocationSuggestion(string $locationName, string $locationType = 'Office'): void
    {
        $user = Auth::user();
        $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
        $this->rememberLocationSuggestion($locationName, $this->record_series_id, $userOfficeCode, $locationType);
        $this->records_location = mb_strtoupper(trim($locationName));
    }

    public function updatedUploadedFile(): void
    {
        if (!$this->uploadedFile) return;

        $ext = strtolower($this->uploadedFile->getClientOriginalExtension());
        $allowedDocs = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'odt', 'ods'];

        if (!in_array($ext, $allowedDocs, true)) {
            $this->errorMessage = 'Invalid file type (.'.$ext.'). Only document files (.pdf, .docx, .doc, .xlsx, .pptx, .txt, .csv) are allowed.';
            $this->uploadedFile = null;
        } else {
            $this->errorMessage = null;
        }
    }

    public function computeTotalPeriod(?string $active, ?string $storage, bool $isPermanent): string
    {
        if ($isPermanent) {
            return 'Permanent';
        }

        $active = trim($active ?? '');
        $storage = trim($storage ?? '');

        if (empty($active) && empty($storage)) {
            return '';
        }

        if (empty($active)) {
            return $storage;
        }

        if (empty($storage)) {
            return $active;
        }

        $parseTime = function(string $str) {
            $years = 0;
            $months = 0;
            if (preg_match('/(\d+)\s*(?:year|yr|y)s?/i', $str, $m)) {
                $years = (int)$m[1];
            }
            if (preg_match('/(\d+)\s*(?:month|mo|m)s?/i', $str, $m)) {
                $months = (int)$m[1];
            }
            return [$years, $months];
        };

        [$aYears, $aMonths] = $parseTime($active);
        [$sYears, $sMonths] = $parseTime($storage);

        if (($aYears > 0 || $aMonths > 0) && ($sYears > 0 || $sMonths > 0)) {
            $totalMonths = ($aYears * 12 + $aMonths) + ($sYears * 12 + $sMonths);
            $tYears = intdiv($totalMonths, 12);
            $remMonths = $totalMonths % 12;

            $parts = [];
            if ($tYears > 0) {
                $parts[] = $tYears . ' ' . ($tYears === 1 ? 'Year' : 'Years');
            }
            if ($remMonths > 0) {
                $parts[] = $remMonths . ' ' . ($remMonths === 1 ? 'Month' : 'Months');
            }
            return implode(' ', $parts);
        }

        return $active . ' + ' . $storage;
    }

    public function syncArchivalAutoSelect(): void
    {
        $archivalId = DB::table('rdp_utility_medium')
            ->where('utility_name', 'like', '%Archival%')
            ->value('id');

        if ($archivalId && ($this->is_permanent || $this->time_value === 'P')) {
            if (!in_array($archivalId, $this->utility_values)) {
                $this->utility_values[] = $archivalId;
            }
        }
    }

    public function updatedTimeValue($val): void
    {
        if ($val === 'P') {
            $this->is_permanent = true;
            $this->active_period = '';
            $this->storage_period = '';
            $this->retention_period = 'Permanent';
            $this->syncArchivalAutoSelect();
        } elseif ($val === 'T') {
            $this->is_permanent = false;
            $this->retention_period = $this->computeTotalPeriod($this->active_period, $this->storage_period, false);
        }
    }

    public function updatedIsPermanent($val): void
    {
        if ($val) {
            $this->time_value = 'P';
            $this->active_period = '';
            $this->storage_period = '';
            $this->retention_period = 'Permanent';
            $this->syncArchivalAutoSelect();
        } else {
            $this->time_value = 'T';
            $this->retention_period = $this->computeTotalPeriod($this->active_period, $this->storage_period, false);
        }
    }

    // Intake / Prefill Properties from DTS or DCS
    public ?int $prefill_intake_id = null;
    public ?string $prefill_source = null;
    public ?string $prefill_code = null;
    public ?string $prefill_doc_id = null;

    public function mount(): void
    {
        $this->showValidationErrors = false;
        $this->time_value = $this->is_permanent ? 'P' : 'T';

        if ($this->records_medium === null) {
            $this->records_medium = $this->getDefaultMediumId();
        }
        if (empty($this->restriction)) {
            $this->restriction = 'Restricted';
        }
        if (empty($this->date_covered)) {
            $this->date_covered = Carbon::now()->format('Y-m-d');
        }
        if (empty($this->frequence_use)) {
            $this->frequence_use = 'Annually';
        }
        if (empty($this->utility_values)) {
            $this->utility_values = [1];
        }

        $user = Auth::user();
        $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;

        $this->prefill_intake_id = request()->query('prefill_intake_id') ? (int)request()->query('prefill_intake_id') : null;
        $this->prefill_source = request()->query('prefill_source');
        $this->prefill_code = request()->query('prefill_code');
        $this->prefill_doc_id = request()->query('prefill_doc_id');

        if ($this->prefill_intake_id) {
            $this->isAppraising = true;
            $intake = DB::table('rdp_received_documents')->where('id', $this->prefill_intake_id)->first();
            if ($intake) {
                if (($intake->status ?? '') === 'appraised' || !empty($intake->appraised_record_id)) {
                    $this->dcsOfficeAlreadyDone = true;
                    $this->errorMessage = 'This office already created the record for this document.';
                }
                $this->parentSeriesTitle = $intake->document_title ?? '';
                $this->selectedSeriesTitle = $intake->document_title ?? '';
                $this->description = $intake->description ?? '';
                if ($intake->date_received) {
                    $this->date_covered = Carbon::parse($intake->date_received)->format('Y-m-d');
                }
                if ($intake->origin_office) {
                    $this->duplicate_offices = array_values(array_unique(array_merge($this->duplicate_offices, [$intake->origin_office])));
                }
                if ($intake->document_id_handler) {
                    $this->prefill_doc_id = $intake->document_id_handler;
                }

                // Unpack metadata from DTS Handoff if present
                if (!empty($intake->metadata)) {
                    $meta = is_string($intake->metadata) ? json_decode($intake->metadata, true) : (array)$intake->metadata;
                    if (is_array($meta)) {
                        if (!empty($meta['record_series_id'])) {
                            $this->loadSeriesById((int)$meta['record_series_id']);
                        }
                        $medId = $meta['records_medium_id'] ?? $meta['records_medium'] ?? null;
                        if (!empty($medId)) {
                            $this->records_medium = (int)$medId;
                        }
                        $restName = $meta['restriction_name'] ?? $meta['restriction'] ?? null;
                        if (!empty($restName)) {
                            $this->restriction = $restName;
                        }

                        // Volume Amount & Unit extraction (strictly require numbers)
                        $rawVol = trim((string)($meta['volume'] ?? ''));
                        $rawAmt = trim((string)($meta['volume_amount'] ?? ''));
                        $rawUnit = trim((string)($meta['volume_unit'] ?? 'Folder'));

                        if (!empty($rawAmt) && preg_match('/\d/', $rawAmt)) {
                            $this->volume_amount = $rawAmt;
                            $this->volume_unit = $rawUnit ?: 'Folder';
                            $this->volume = $this->volume_amount . ' ' . $this->volume_unit;
                        } elseif (!empty($rawVol) && preg_match('/\d/', $rawVol)) {
                            $this->volume = $rawVol;
                            if (preg_match('/^(\d+)\s*(.*)$/i', $rawVol, $m)) {
                                $this->volume_amount = $m[1];
                                if (!empty(trim($m[2]))) {
                                    $this->volume_unit = ucfirst(strtolower(trim($m[2])));
                                }
                            }
                        } else {
                            $this->volume = '';
                            $this->volume_amount = '';
                            $this->volume_unit = 'Folder';
                        }

                        $forwarded = !empty($meta['dcs_forwarded']);
                        if ($forwarded && ($intake->source_subsystem ?? '') === 'DCS') {
                            $this->dcsOfficeLock = true;
                            $this->dcsRequestId = !empty($meta['dcs_request_id']) ? (int) $meta['dcs_request_id'] : null;
                            if (!$this->dcsOfficeHasReceived($this->dcsRequestId)) {
                                $this->dcsOfficeBlocked = true;
                                $this->errorMessage = 'Mark this document as received before entering Records Location.';
                            }
                        }
                        if (($intake->status ?? '') === 'appraised' || !empty($intake->appraised_record_id)) {
                            $this->dcsOfficeAlreadyDone = true;
                            $this->dcsOfficeBlocked = false;
                            $this->errorMessage = 'This office already created the record for this document.';
                        }
                        if (!empty($meta['records_location']) && !$forwarded) {
                            $this->records_location = $meta['records_location'];
                        }
                        $this->frequence_use = $meta['frequence_use'] ?? 'Annually';
                        if (!empty($meta['duplicate_offices']) && is_array($meta['duplicate_offices'])) {
                            $this->duplicate_offices = $forwarded
                                ? array_values(array_unique($meta['duplicate_offices']))
                                : array_values(array_unique(array_merge($this->duplicate_offices, $meta['duplicate_offices'])));
                        }
                        // Office appraisal keeps the full Duplicate list from the admin forward.
                        if (empty($meta['record_series_id']) && !empty($meta['selected_series_title'])) {
                            $this->selectedSeriesTitle = (string) $meta['selected_series_title'];
                            $this->parentSeriesTitle = (string) ($meta['parent_series_title'] ?? $meta['selected_series_title']);
                        }
                        if (array_key_exists('time_value', $meta) && $meta['time_value'] !== null && $meta['time_value'] !== '') {
                            $this->time_value = (string) $meta['time_value'];
                        }
                        if (array_key_exists('is_permanent', $meta)) {
                            $this->is_permanent = (bool) $meta['is_permanent'];
                        }
                        if (!empty($meta['active_period'])) {
                            $this->active_period = (string) $meta['active_period'];
                        }
                        if (!empty($meta['storage_period'])) {
                            $this->storage_period = (string) $meta['storage_period'];
                        }
                        if (!empty($meta['retention_period'])) {
                            $this->retention_period = (string) $meta['retention_period'];
                        }
                        if (!empty($meta['disposition_provision'])) {
                            $this->disposition_provision = (string) $meta['disposition_provision'];
                        }
                        // Use admin/DTS utility exactly — do not merge with the mount default (Administrative).
                        if (array_key_exists('utility_values', $meta) && is_array($meta['utility_values'])) {
                            $this->utility_values = array_values(array_unique(array_filter(
                                array_map('intval', $meta['utility_values']),
                                fn ($id) => $id > 0
                            )));
                        }
                        // Office appraisal: Period Covered is admin date / effectivity — locked, not cleared.
                        if (! empty($meta['date_covered'])) {
                            try {
                                $this->date_covered = Carbon::parse($meta['date_covered'])->format('Y-m-d');
                            } catch (\Throwable) {
                                // keep prior date_covered
                            }
                        } elseif ($forwarded && $this->dcsRequestId) {
                            $eff = DB::table('dcs_masterlist_registration')
                                ->where('request_id', $this->dcsRequestId)
                                ->value('effectivity_date');
                            if (! empty($eff)) {
                                try {
                                    $this->date_covered = Carbon::parse($eff)->format('Y-m-d');
                                } catch (\Throwable) {
                                    // keep prior date_covered
                                }
                            }
                        }
                    }
                }

                // Defaults for locked appraisal fields
                if (empty($this->restriction)) {
                    $this->restriction = 'Restricted';
                }
                if (empty($this->frequence_use)) {
                    $this->frequence_use = 'Annually';
                }
                if (empty($this->records_medium)) {
                    $this->records_medium = $this->getDefaultMediumId();
                }
                if ($this->dcsOfficeLock) {
                    // Offices set their own Records Location only; Period Covered stays from admin/effectivity.
                    $this->records_location = '';
                }
                if (empty($this->duplicate_offices)) {
                    $userOffice = Auth::user()?->details?->office?->office_code ?? Auth::user()?->details?->office_code ?? null;
                    if ($userOffice) {
                        $this->duplicate_offices = [$userOffice];
                    }
                }
            }
        } elseif ((int) request()->query('dcs_request_id') > 0) {
            $this->loadDcsAdminAppraisal((int) request()->query('dcs_request_id'));
        } elseif (request()->query('prefill_title')) {
            $this->parentSeriesTitle = request()->query('prefill_title');
            $this->selectedSeriesTitle = request()->query('prefill_title');
            if (request()->query('prefill_desc')) {
                $this->description = request()->query('prefill_desc');
            }
            if (request()->query('prefill_date')) {
                $this->date_covered = Carbon::parse(request()->query('prefill_date'))->format('Y-m-d');
            }
        }
    }

    public function loadDcsAdminAppraisal(int $requestId): void
    {
        $this->dcsAdminForward = true;
        $this->dcsRequestId = $requestId;
        $this->entryMode = 'single';
        $this->prefill_source = 'DCS';
        $this->isAppraising = false;
        $this->dcsOfficeLock = false;

        // Do not preselect Paper — controller must choose Records Medium.
        $this->records_medium = null;
        $this->restriction = '';
        $this->frequence_use = '';
        $this->utility_values = [];
        $this->date_covered = '';
        $this->time_value = '';
        $this->volume = '';
        $this->volume_amount = '';
        $this->records_location = '';
        $this->active_period = '';
        $this->storage_period = '';
        $this->retention_period = '';
        $this->disposition_provision = '';
        $this->is_permanent = false;
        $this->selectedSeriesTitle = '';
        $this->parentSeriesTitle = '';
        $this->record_series_id = null;

        $request = DB::table('dcs_document_requests')->where('id', $requestId)->first();
        $masterlist = DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first();
        if (!$request || !$masterlist || !empty($request->is_draft)) {
            $this->dcsAdminForward = false;
            $this->errorMessage = 'Appraisal is available after the document is registered.';
            return;
        }

        $title = trim((string) ($masterlist->doc_title ?? ''));
        $docNo = trim((string) ($masterlist->doc_no ?? ''));
        $this->description = $title;
        $this->prefill_code = $docNo !== '' ? $docNo : \App\Services\RdpIntakeService::documentCodeForRequest($requestId, $docNo);
        $this->prefill_doc_id = (string) $requestId;

        if (! empty($masterlist->effectivity_date)) {
            try {
                $this->date_covered = Carbon::parse($masterlist->effectivity_date)->format('Y-m-d');
            } catch (\Throwable $e) {
                $this->date_covered = '';
            }
        }

        $officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $codes = DB::table('dcs_distribution_offices as d')
            ->join('dcs_document_distribution as dist', 'dist.id', '=', 'd.distribution_id')
            ->leftJoin($officeTable . ' as o', 'o.id', '=', 'd.office_id')
            ->where('dist.request_id', $requestId)
            ->orderBy('d.id')
            ->get(['o.id as office_id', 'o.office_code']);

        $resolved = [];
        foreach ($codes as $row) {
            $exact = \App\Helpers\RegisterQueryHelper::resolveSysOfficeCode(
                $row->office_code ?? null,
                isset($row->office_id) ? (int) $row->office_id : null
            );
            if ($exact !== null) {
                $resolved[] = $exact;
            }
        }
        $this->duplicate_offices = \App\Helpers\RegisterQueryHelper::resolveSysOfficeCodes($resolved);

        if ($title === '') {
            $this->dcsAdminForward = false;
            $this->errorMessage = 'Appraisal needs a document title.';
            return;
        }

        if ($this->duplicate_offices === []) {
            $this->dcsAdminForward = false;
            $this->errorMessage = 'Add at least one distribution office before appraisal.';
            return;
        }

        if (\App\Services\RdpIntakeService::requestAlreadyForwarded($requestId)) {
            $this->dcsAdminAlreadySent = true;
            $this->successMessage = 'This document was already forwarded for appraisal.';
        }
    }

    /**
     * upload_doc_id_handler must be a sys_document_data.document_id.
     * A DCS request id stored on the intake is not that file.
     */
    private function existingUploadDocumentId(): ?string
    {
        $id = trim((string) ($this->prefill_doc_id ?? ''));
        if ($id === '' || !ctype_digit($id)) {
            return null;
        }

        $table = \Illuminate\Support\Facades\Schema::hasTable('sys_document_data')
            ? 'sys_document_data'
            : 'document_data';
        if (!\Illuminate\Support\Facades\Schema::hasTable($table) || !\Illuminate\Support\Facades\Schema::hasColumn($table, 'document_id')) {
            return null;
        }

        return DB::table($table)->where('document_id', $id)->exists() ? $id : null;
    }

    private function dcsOfficeHasReceived(?int $requestId): bool
    {
        $officeId = \App\Helpers\RegisterQueryHelper::currentOfficeId();
        if (! $requestId || ! $officeId) {
            return false;
        }
        if (! \Illuminate\Support\Facades\Schema::hasColumn('dcs_distribution_offices', 'office_received_at')) {
            return true;
        }

        return DB::table('dcs_distribution_offices as doff')
            ->join('dcs_document_distribution as dist', 'dist.id', '=', 'doff.distribution_id')
            ->where('dist.request_id', $requestId)
            ->where('doff.office_id', $officeId)
            ->whereNotNull('doff.office_received_at')
            ->exists();
    }

    public function forwardToOffices(): void
    {
        if (!$this->dcsAdminForward || !$this->dcsRequestId) {
            $this->errorMessage = 'This appraisal is not ready to forward.';
            $this->dispatch('scroll-to-top');
            return;
        }

        if (\App\Services\RdpIntakeService::requestAlreadyForwarded((int) $this->dcsRequestId)) {
            $this->dcsAdminAlreadySent = true;
            $this->errorMessage = 'This document was already forwarded for appraisal.';
            $this->dispatch('scroll-to-top');
            return;
        }

        if (!$this->validateRecordData(true)) {
            return;
        }

        if ($this->duplicate_offices === []) {
            $this->errorMessage = 'Add the distribution offices before forwarding.';
            $this->dispatch('scroll-to-top');
            return;
        }

        $origin = \App\Helpers\RegisterQueryHelper::rdpOfficeOwnCode();
        if (! is_string($origin) || trim($origin) === '') {
            $bad = \App\Helpers\RegisterQueryHelper::currentOfficeCode() ?: 'unknown';
            $this->errorMessage = 'Your account office ('.$bad.') is not linked to an active office in the system. Ask Admin to fix your office assignment before appraisal can be saved to NAP Form 1.';
            $this->dispatch('scroll-to-top');
            return;
        }
        $origin = trim($origin);

        $title = trim($this->description);
        if ($title === '') {
            $this->errorMessage = 'Document title is required before forwarding.';
            $this->dispatch('scroll-to-top');
            return;
        }

        $intakeCode = \App\Services\RdpIntakeService::documentCodeForRequest(
            (int) $this->dcsRequestId,
            trim((string) ($this->prefill_code ?? ''))
        );
        $this->prefill_code = $intakeCode;

        $masterlist = DB::table('dcs_masterlist_registration')->where('request_id', $this->dcsRequestId)->first();
        $filePath = trim((string) ($masterlist->scanned_masterlist ?? ''));
        if (strlen($filePath) > 255) {
            $filePath = '';
        }

        $this->syncMasterlistEffectivityFromPeriod((int) $this->dcsRequestId);

        $adminRecordId = $this->insertAdminAppraisalRecord($origin);
        if ($adminRecordId === null) {
            return;
        }

        $metadata = [
            'dcs_forwarded' => true,
            'dcs_request_id' => $this->dcsRequestId,
            'record_series_id' => $this->record_series_id,
            'parent_series_title' => $this->parentSeriesTitle,
            'selected_series_title' => $this->selectedSeriesTitle,
            'records_medium' => $this->records_medium,
            'restriction' => $this->restriction,
            'volume' => $this->volume,
            'volume_amount' => $this->volume_amount,
            'volume_unit' => $this->volume_unit,
            'frequence_use' => $this->frequence_use,
            'duplicate_offices' => array_values($this->duplicate_offices),
            'utility_values' => array_values($this->utility_values),
            'date_covered' => $this->date_covered,
            'time_value' => $this->time_value,
            'is_permanent' => $this->is_permanent,
            'active_period' => $this->active_period,
            'storage_period' => $this->storage_period,
            'retention_period' => $this->retention_period,
            'disposition_provision' => $this->disposition_provision,
            'records_location' => trim((string) $this->records_location),
        ];

        $sent = 0;
        $already = 0;
        $adminInDuplicate = false;

        foreach (\App\Helpers\RegisterQueryHelper::resolveSysOfficeCodes($this->duplicate_offices) as $officeCode) {
            if ($officeCode === '') {
                continue;
            }

            if (strcasecmp($officeCode, $origin) === 0) {
                $adminInDuplicate = true;
                $result = \App\Services\RdpIntakeService::accept([
                    'source_subsystem' => 'DCS',
                    'document_code' => $intakeCode,
                    'document_title' => $title,
                    'description' => $title,
                    'origin_office' => $origin,
                    'target_office' => $officeCode,
                    'date_received' => now()->toDateString(),
                    'file_path' => $filePath !== '' ? $filePath : null,
                    'file_name' => $filePath !== '' ? basename($filePath) : null,
                    'document_id_handler' => null,
                    'metadata' => $metadata,
                ]);
                if (! empty($result['id'])) {
                    DB::table('rdp_received_documents')->where('id', (int) $result['id'])->update([
                        'status' => 'appraised',
                        'appraised_record_id' => $adminRecordId,
                        'updated_at' => now(),
                    ]);
                    $forwardedRequestId = (int) ($metadata['dcs_request_id'] ?? 0);
                    if ($forwardedRequestId > 0) {
                        \App\Services\RdpIntakeService::afterDcsRequestAppraised($forwardedRequestId);
                    }
                }
                continue;
            }

            $result = \App\Services\RdpIntakeService::accept([
                'source_subsystem' => 'DCS',
                'document_code' => $intakeCode,
                'document_title' => $title,
                'description' => $title,
                'origin_office' => $origin,
                'target_office' => $officeCode,
                'date_received' => now()->toDateString(),
                'file_path' => $filePath !== '' ? $filePath : null,
                'file_name' => $filePath !== '' ? basename($filePath) : null,
                'document_id_handler' => null,
                'metadata' => $metadata,
            ]);

            if (empty($result['success'])) {
                $this->errorMessage = $result['error'] ?? 'Forwarding failed.';
                $this->dispatch('scroll-to-top');
                return;
            }

            if (!empty($result['already_sent'])) {
                $already++;
            } else {
                $sent++;
            }
        }

        $this->successMessage = $sent > 0
            ? 'Appraisal saved for your office and forwarded to ' . $sent . ' ' . ($sent === 1 ? 'office' : 'offices') . '.'
            : ($adminInDuplicate && $already === 0
                ? 'Appraisal saved for your office on NAP Form 1.'
                : 'Those offices already have this appraisal. Your office record is on NAP Form 1.');
        if ($already > 0 && $sent > 0) {
            $this->successMessage .= ' ' . $already . ' already had it, and the appraisal fields were updated.';
        }
        $this->successMessage .= ' Other offices enter their own Records Location and create their records.';
        $this->dcsAdminAlreadySent = true;
        $displayDocNo = trim((string) ($masterlist->doc_no ?? ''));
        $revNo = isset($masterlist->revise_no) ? (int) $masterlist->revise_no : null;
        foreach (\App\Helpers\RegisterQueryHelper::resolveSysOfficeCodes($this->duplicate_offices) as $officeCode) {
            if ($officeCode === '' || strcasecmp($officeCode, $origin) === 0) {
                continue;
            }
            \App\Services\DcsNotificationService::notifyDocumentDistributed(
                $officeCode,
                $displayDocNo !== '' ? $displayDocNo : $intakeCode,
                $title,
                $revNo
            );
        }
        $this->dispatch('scroll-to-top');
    }

    private function syncMasterlistEffectivityFromPeriod(int $requestId): void
    {
        $period = trim((string) $this->date_covered);
        if ($requestId < 1 || $period === '') {
            return;
        }
        if (! \Illuminate\Support\Facades\Schema::hasColumn('dcs_masterlist_registration', 'effectivity_date')) {
            return;
        }

        try {
            $date = Carbon::parse($period)->format('Y-m-d');
        } catch (\Throwable $e) {
            return;
        }

        DB::table('dcs_masterlist_registration')
            ->where('request_id', $requestId)
            ->update([
                'effectivity_date' => $date,
                'updated_at' => now(),
            ]);
    }

    /**
     * Save the Document Controller's appraisal as their own NAP Form 1 inventory row.
     */
    private function insertAdminAppraisalRecord(string $officeOwn): ?int
    {
        if (\App\Services\RdpIntakeService::adminRecordExistsForRequest((int) $this->dcsRequestId, $officeOwn)) {
            $existingId = \App\Services\RdpIntakeService::adminRecordIdForRequest((int) $this->dcsRequestId, $officeOwn);
            if ($existingId) {
                return $existingId;
            }
        }

        $user = Auth::user();
        $rawVol = trim($this->volume);
        $rawAmt = trim((string) $this->volume_amount);
        if (!empty($rawVol) && preg_match('/\d/', $rawVol)) {
            $formattedVolume = mb_strtoupper($rawVol);
        } elseif (!empty($rawAmt) && preg_match('/\d/', $rawAmt)) {
            $formattedVolume = mb_strtoupper(trim($rawAmt . ' ' . $this->volume_unit));
        } else {
            $this->errorMessage = 'Please provide a valid Volume Amount & Unit before forwarding.';
            $this->dispatch('scroll-to-top');
            return null;
        }

        try {
            $periodId = null;
            $recordId = DB::table('rdp_record')->insertGetId([
                'record_series_id' => $this->record_series_id,
                'description' => mb_strtoupper(trim($this->description)),
                'period_id' => $periodId,
                'volume' => $formattedVolume,
                'records_location' => mb_strtoupper(trim($this->records_location)),
                'restriction' => $this->restriction,
                'records_medium' => $this->records_medium,
                'time_value' => $this->time_value ?: 'T',
                'frequence_use' => $this->frequence_use,
                'user_own' => $user?->id,
                'office_own' => $officeOwn,
                'upload_doc_id_handler' => null,
                'is_draft' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->rememberLocationSuggestion($this->records_location, $this->record_series_id, $officeOwn);

            DB::table('rdp_record')->where('id', $recordId)->update([
                'utility_value' => $recordId,
                'duplication_id' => $recordId,
            ]);

            foreach (array_values($this->utility_values) as $uId) {
                DB::table('rdp_utility_manager')->insert([
                    'record_holder' => $recordId,
                    'utility_medium' => $uId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach (\App\Helpers\RegisterQueryHelper::resolveSysOfficeCodes($this->duplicate_offices) as $dupOffice) {
                DB::table('rdp_duplication_section')->insert([
                    'dup_id_manager' => $recordId,
                    'office_code' => $dupOffice,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (! empty($this->date_covered)) {
                $pRow = [
                    'period_owner' => $recordId,
                    'date_covered' => $this->date_covered,
                    'created_at' => now(),
                    'modified_at' => now(),
                ];
                DB::table('rdp_period_covered')->insert($pRow);
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('rdp_received_documents')
                && \Illuminate\Support\Facades\Schema::hasColumn('rdp_received_documents', 'metadata')) {
                // Tag for duplicate-forward guard via metadata on a stub is unnecessary —
                // adminRecordExistsForRequest looks up rdp_record by description + office + series.
            }

            \App\Services\RdpIntakeService::rememberAdminRecord((int) $this->dcsRequestId, $officeOwn, $recordId);

            return $recordId;
        } catch (\Throwable $e) {
            $this->errorMessage = 'Could not save your office appraisal record: ' . $e->getMessage();
            $this->dispatch('scroll-to-top');
            return null;
        }
    }

    private function claimOfficeIntake(): bool
    {
        if (!$this->prefill_intake_id) {
            return true;
        }

        $row = DB::table('rdp_received_documents')
            ->where('id', $this->prefill_intake_id)
            ->lockForUpdate()
            ->first();

        if (!$row || ($row->status ?? '') === 'appraised' || !empty($row->appraised_record_id)) {
            $this->dcsOfficeAlreadyDone = true;

            return false;
        }

        return true;
    }

    public function resolveEffectiveSeriesRetention(object $series): array
    {
        $current = $series;
        $visited = [];
        $foundRetention = null;
        $foundPermanent = false;
        $foundRemarks = null;
        $isInherited = false;

        while ($current) {
            if ($foundRetention === null) {
                if ($current->retention_period) {
                    $ret = DB::table('rdp_retention_period')->where('id', $current->retention_period)->first();
                    if ($ret && (!empty(trim($ret->active_period ?? '')) || !empty(trim($ret->storage_period ?? '')) || !empty(trim($ret->total_period ?? '')))) {
                        $foundRetention = $ret;
                        if ($current->id !== $series->id) {
                            $isInherited = true;
                        }
                    }
                }
            }

            if (!$foundPermanent) {
                if (!empty($current->is_retention_period_permanent)) {
                    $foundPermanent = true;
                    if ($current->id !== $series->id) {
                        $isInherited = true;
                    }
                }
            }

            if ($foundRemarks === null) {
                if (!empty(trim($current->remarks ?? ''))) {
                    $foundRemarks = trim($current->remarks);
                }
            }

            if (($foundRetention || $foundPermanent) && $foundRemarks !== null) {
                break;
            }

            if (in_array($current->id, $visited, true)) {
                break;
            }
            $visited[] = $current->id;

            $pId = $current->parent_id ?? null;
            $current = $pId ? DB::table('rdp_record_series')
                ->leftJoin('rdp_remarks_logic', 'rdp_record_series.remarks', '=', 'rdp_remarks_logic.id')
                ->select(['rdp_record_series.*', 'rdp_remarks_logic.remarks_value as remarks'])
                ->where('id', $pId)->first() : null;
        }

        return [
            'retention'     => $foundRetention,
            'is_permanent'  => $foundPermanent,
            'remarks'       => $foundRemarks,
            'is_inherited'  => $isInherited,
        ];
    }

    public function loadSeriesById(int $id): void
    {
        $series = DB::table('rdp_record_series')
            ->leftJoin('rdp_remarks_logic', 'rdp_record_series.remarks', '=', 'rdp_remarks_logic.id')
            ->select(['rdp_record_series.*', 'rdp_remarks_logic.remarks_value as remarks'])
            ->where('id', $id)->first();
        if (!$series) return;

        $this->record_series_id = $series->id;
        $chain = [];
        $curr = $series;
        while ($curr) {
            array_unshift($chain, $curr);
            $curr = $curr->parent_id ? DB::table('rdp_record_series')->where('id', $curr->parent_id)->first() : null;
        }

        $this->parentSeriesTitle = $chain[0]->series_title;
        $this->subsections = [];
        for ($i = 1; $i < count($chain); $i++) {
            $this->subsections[] = $chain[$i]->series_title;
        }

        $this->selectedSeriesHierarchy = array_map(function ($s) {
            return [
                'title' => $s->series_title,
                'is_predefined' => true,
            ];
        }, $chain);

        $this->selectedSeriesTitle = implode(' ➔ ', array_column($this->selectedSeriesHierarchy, 'title'));
        $this->isCustomSeries = false;

        $effective = $this->resolveEffectiveSeriesRetention($series);
        $retention = $effective['retention'];
        $effectiveRemarks = $effective['remarks'];

        $this->hasPredefinedRemarks = false;
        $this->hasPredefinedRetention = false;

        if (!empty($effectiveRemarks)) {
            $this->hasPredefinedRemarks = true;
            $this->disposition_provision = $effectiveRemarks;
        }

        $isPermFlag = $effective['is_permanent'];
        $isActivePermanent = $retention && strtolower(trim($retention->active_period ?? '')) === 'permanent';
        $isTotalPermanent  = $retention && strtolower(trim($retention->total_period ?? '')) === 'permanent';
        $isTitlePermanent  = str_contains(strtolower($series->series_title), 'permanent');
        $isPermanentSeries = $isPermFlag || $isActivePermanent || $isTotalPermanent || $isTitlePermanent;

        $hasActivePeriod = $retention && !empty(trim($retention->active_period ?? '')) && strtolower(trim($retention->active_period ?? '')) !== 'none';
        $hasStoragePeriod = $retention && !empty(trim($retention->storage_period ?? '')) && strtolower(trim($retention->storage_period ?? '')) !== 'none';
        $hasRemarksCondition = !empty(trim($effectiveRemarks ?? ''));

        if ($retention || $isPermanentSeries || $hasRemarksCondition) {
            $this->hasPredefinedRetention = true;
        }

        if ($isPermanentSeries) {
            $this->is_permanent = true;
            $this->active_period = '';
            $this->storage_period = '';
            $this->retention_period = 'Permanent';
            $this->time_value = 'P';
        } elseif ($hasActivePeriod || $hasStoragePeriod) {
            $this->is_permanent = false;
            $this->active_period = mb_strtoupper($retention->active_period ?? '');
            $this->storage_period = mb_strtoupper($retention->storage_period ?? '');
            $this->retention_period = mb_strtoupper($this->computeTotalPeriod($this->active_period, $this->storage_period, false));
            $this->time_value = 'T';
        } else {
            $this->is_permanent = false;
            $this->active_period = '';
            $this->storage_period = '';
            $this->retention_period = 'SEE REMARKS';
            $this->time_value = 'T';
        }

        $this->syncArchivalAutoSelect();
    }

    /** DCS Distribution / office appraisal Duplicate list is read-only. */
    private function duplicateOfficesLocked(): bool
    {
        return $this->dcsAdminForward || $this->dcsOfficeLock || $this->isAppraising;
    }

    public function addDuplicateOffice(?string $officeCode = null): void
    {
        if ($this->duplicateOfficesLocked()) {
            return;
        }

        $codeToAdd = strtoupper(trim($officeCode ?? $this->duplicate_search));
        if (empty($codeToAdd)) {
            return;
        }

        $officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $exists = DB::table($officeTable)
            ->where('is_active', true)
            ->where(function($q) use ($codeToAdd) {
                $q->where('office_code', $codeToAdd)
                  ->orWhere('office_name', 'ilike', $codeToAdd);
            })
            ->first();

        if ($exists) {
            $realCode = $exists->office_code;
            if (!in_array($realCode, $this->duplicate_offices, true)) {
                $this->duplicate_offices[] = $realCode;
                $this->duplicate_file_view[$realCode] = false;
            }
        } elseif (!empty($officeCode) && !in_array($officeCode, $this->duplicate_offices, true)) {
            $this->duplicate_offices[] = $officeCode;
            $this->duplicate_file_view[$officeCode] = false;
        }

        $this->duplicate_search = '';
        $this->showDuplicateDropdown = false;
    }

    public function removeDuplicateOffice(int $index): void
    {
        if ($this->duplicateOfficesLocked()) {
            return;
        }

        if (isset($this->duplicate_offices[$index])) {
            $code = $this->duplicate_offices[$index];
            unset($this->duplicate_offices[$index]);
            unset($this->duplicate_file_view[$code]);
            $this->duplicate_offices = array_values($this->duplicate_offices);
        }
    }

    public function clearDuplicateOffices(): void
    {
        if ($this->duplicateOfficesLocked()) {
            return;
        }

        $this->duplicate_offices = [];
        $this->duplicate_file_view = [];
        $this->duplicate_search = '';
        $this->showDuplicateDropdown = false;
    }

    // --- Record Series Modal Handlers ---
    public function openSeriesModal(): void
    {
        if ($this->isAppraising) {
            return;
        }

        $this->seriesModalError = null;
        $this->showSeriesModal = true;
    }

    public function closeSeriesModal(): void
    {
        $this->showSeriesModal = false;
        $this->showParentDropdown = false;
        $this->activeSubDropdownIndex = null;
        $this->seriesModalError = null;
    }

    public ?int $selectedParentId = null;
    public ?int $selectedParentTypeId = null;
    public ?string $selectedParentOffice = null;

    public function getSubSuggestions(int $index): \Illuminate\Support\Collection
    {
        $parentTitle = ($index === 0) ? trim($this->parentSeriesTitle) : trim($this->subsections[$index - 1] ?? '');
        if (empty($parentTitle)) {
            return collect();
        }

        $parentQuery = DB::table('rdp_record_series')->where('series_title', 'ilike', $parentTitle);

        if ($index === 0 && $this->selectedParentId) {
            $parentQuery->where('id', $this->selectedParentId);
        } elseif ($index === 0 && $this->selectedParentTypeId) {
            $parentQuery->where('series_type', $this->selectedParentTypeId);
            if ($this->selectedParentOffice) {
                $parentQuery->where('recorded_at_office', $this->selectedParentOffice);
            }
        }

        $parentRecord = $parentQuery->first();

        if (!$parentRecord) {
            return collect();
        }

        $query = DB::table('rdp_record_series')
            ->leftJoin('rdp_record_series_type', 'rdp_record_series.series_type', '=', 'rdp_record_series_type.id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
            ->select([
                'rdp_record_series.id',
                'rdp_record_series.series_title',
                'rdp_record_series.recorded_at_office',
                'rdp_record_series_type.shorted_type',
                'office.office_name as recorded_office_name',
            ])
            ->where('rdp_record_series.parent_id', $parentRecord->id);

        if ($this->selectedSeriesTypeFilter) {
            $query->where('rdp_record_series.series_type', $this->selectedSeriesTypeFilter);
        }

        $currentSubInput = trim($this->subsections[$index] ?? '');
        if (!empty($currentSubInput)) {
            $query->where('rdp_record_series.series_title', 'ilike', '%' . $currentSubInput . '%');
        }

        return $query->distinct()->limit(10)->get();
    }

    public function updatedParentSeriesTitle(): void
    {
        $this->selectedParentId = null;
        $this->selectedParentTypeId = null;
        $this->selectedParentOffice = null;
        $this->showParentDropdown = true;
        $this->seriesModalError = null;
    }

    public function selectParentSuggestion(string $title, ?int $id = null, ?int $typeId = null, ?string $office = null): void
    {
        $this->parentSeriesTitle = mb_strtoupper($title);
        $this->selectedParentId = $id;
        $this->selectedParentTypeId = $typeId;
        $this->selectedParentOffice = $office;
        $this->showParentDropdown = false;
        $this->seriesModalError = null;
    }

    public function selectSubSuggestion(int $index, string $title): void
    {
        if (isset($this->subsections[$index])) {
            $this->subsections[$index] = mb_strtoupper($title);
        }
        $this->activeSubDropdownIndex = null;
        $this->seriesModalError = null;
    }

    public function addSubsection(): void
    {
        $this->subsections[] = '';
        $this->seriesModalError = null;
    }

    public function removeSubsection(int $index): void
    {
        if (isset($this->subsections[$index])) {
            unset($this->subsections[$index]);
            $this->subsections = array_values($this->subsections);
        }
        $this->seriesModalError = null;
    }

    public function saveNewRecordSeries(): void
    {
        $this->seriesModalError = null;

        $this->validate([
            'parentSeriesTitle' => 'required|string|max:255',
        ]);

        $parentTitle = mb_strtoupper(trim($this->parentSeriesTitle));
        $fullPathTitles = [$parentTitle];

        foreach ($this->subsections as $subTitle) {
            $trimmed = mb_strtoupper(trim($subTitle));
            if (!empty($trimmed)) {
                $fullPathTitles[] = $trimmed;
            }
        }

        $this->selectedSeriesHierarchy = [];
        $currentParentId = null;
        $parentNode = null;
        $lastNode = null;

        foreach ($fullPathTitles as $idx => $t) {
            $query = DB::table('rdp_record_series')
                ->leftJoin('rdp_remarks_logic', 'rdp_record_series.remarks', '=', 'rdp_remarks_logic.id')
                ->select(['rdp_record_series.*', 'rdp_remarks_logic.remarks_value as remarks'])
                ->where('series_title', 'ilike', $t);
            if ($idx === 0) {
                if ($this->selectedParentId) {
                    $query->where('id', $this->selectedParentId);
                } else {
                    $query->whereNull('parent_id');
                    if ($this->selectedSeriesTypeFilter) {
                        $query->where('series_type', $this->selectedSeriesTypeFilter);
                    }
                }
            } elseif ($currentParentId) {
                $query->where('parent_id', $currentParentId);
            }
            $existsNode = $query->first();

            if ($idx === 0) {
                $parentNode = $existsNode;
            }

            if ($existsNode) {
                $currentParentId = $existsNode->id;
                $lastNode = $existsNode;
                $this->selectedSeriesHierarchy[] = [
                    'id' => $existsNode->id,
                    'title' => $t,
                    'is_predefined' => true,
                ];
            } else {
                $currentParentId = null;
                $lastNode = null;
                $this->selectedSeriesHierarchy[] = [
                    'id' => null,
                    'title' => $t,
                    'is_predefined' => false,
                ];
            }
        }

        $leafTitle = end($fullPathTitles);

        // 1. Condition: When the Parent record has a Subsection in the database,
        // the Apply Series will not continue without specifying a subsection.
        if ($parentNode) {
            $parentHasSubsections = DB::table('rdp_record_series')
                ->where('parent_id', $parentNode->id)
                ->exists();

            $validSubsections = array_filter(array_map('trim', $this->subsections), fn($s) => !empty($s));
            if ($parentHasSubsections && count($validSubsections) === 0) {
                $this->seriesModalError = "The record series '{$parentNode->series_title}' contains subsections. Please add and select a subsection to continue.";
                return;
            }
        } else {
            // Check if user entered a title that is actually a subsection in the database (parent_id is not null)
            $isActuallySubsection = DB::table('rdp_record_series')
                ->where('series_title', 'ilike', $parentTitle)
                ->whereNotNull('parent_id')
                ->first();

            if ($isActuallySubsection) {
                $actualParent = DB::table('rdp_record_series')->where('id', $isActuallySubsection->parent_id)->first();
                $actualParentName = $actualParent->series_title ?? 'its parent series';
                $this->seriesModalError = "The title '{$this->parentSeriesTitle}' is a subsection of '{$actualParentName}'. Please select the parent series first.";
                return;
            }
        }

        // Also if the selected leaf node itself has further child subsections
        if ($lastNode) {
            $leafHasSubsections = DB::table('rdp_record_series')
                ->where('parent_id', $lastNode->id)
                ->exists();

            if ($leafHasSubsections) {
                $this->seriesModalError = "The selected series '{$lastNode->series_title}' contains further subsections. Please select a specific subsection to continue.";
                return;
            }
        }

        // 2. Condition: If the user's selected record series has no Active Period/Storage Period,
        // Apply Series will not continue.
        $targetSeries = $lastNode;
        if (!$targetSeries) {
            $targetSeries = DB::table('rdp_record_series')
                ->leftJoin('rdp_remarks_logic', 'rdp_record_series.remarks', '=', 'rdp_remarks_logic.id')
                ->select(['rdp_record_series.*', 'rdp_remarks_logic.remarks_value as remarks'])
                ->where('series_title', 'ilike', $leafTitle)
                ->first();
        }

        if (!$targetSeries) {
            $this->seriesModalError = "The selected record series '{$leafTitle}' is not configured in the records disposition schedule.";
            return;
        }

        $effective = $this->resolveEffectiveSeriesRetention($targetSeries);
        $retention = $effective['retention'];
        $effectiveRemarks = $effective['remarks'];

        $isPermFlag = $effective['is_permanent'];
        $isActivePermanent = $retention && strtolower(trim($retention->active_period ?? '')) === 'permanent';
        $isTotalPermanent  = $retention && strtolower(trim($retention->total_period ?? '')) === 'permanent';
        $isTitlePermanent  = str_contains(strtolower($leafTitle), 'permanent');
        $isPermanentSeries = $isPermFlag || $isActivePermanent || $isTotalPermanent || $isTitlePermanent;

        $hasActivePeriod = $retention && !empty(trim($retention->active_period ?? '')) && strtolower(trim($retention->active_period ?? '')) !== 'none';
        $hasStoragePeriod = $retention && !empty(trim($retention->storage_period ?? '')) && strtolower(trim($retention->storage_period ?? '')) !== 'none';
        $hasRemarksCondition = !empty(trim($effectiveRemarks ?? ''));

        if (!$isPermanentSeries && !$hasActivePeriod && !$hasStoragePeriod && !$hasRemarksCondition) {
            $this->seriesModalError = "The selected record series '{$targetSeries->series_title}' has no Active Period, Storage Period, or Remarks condition configured and cannot be applied.";
            return;
        }

        // --- All checks passed: apply series to the form ---
        $this->selectedSeriesTitle = implode(' ➔ ', $fullPathTitles);
        $this->record_series_id = $targetSeries->id;
        $this->isCustomSeries = false;

        $this->hasPredefinedRemarks = false;
        $this->hasPredefinedRetention = false;

        if (!empty($effectiveRemarks)) {
            $this->hasPredefinedRemarks = true;
            $this->disposition_provision = $effectiveRemarks;
        }

        if ($retention || $isPermanentSeries || $hasRemarksCondition) {
            $this->hasPredefinedRetention = true;
        }

        if ($isPermanentSeries) {
            $this->is_permanent = true;
            $this->active_period = '';
            $this->storage_period = '';
            $this->retention_period = 'Permanent';
            $this->time_value = 'P';
        } elseif ($hasActivePeriod || $hasStoragePeriod) {
            $this->is_permanent = false;
            $this->active_period = mb_strtoupper($retention->active_period ?? '');
            $this->storage_period = mb_strtoupper($retention->storage_period ?? '');
            $this->retention_period = mb_strtoupper($this->computeTotalPeriod($this->active_period, $this->storage_period, false));
            $this->time_value = 'T';
        } else {
            // Governed by condition in Remarks
            $this->is_permanent = false;
            $this->active_period = '';
            $this->storage_period = '';
            $this->retention_period = 'SEE REMARKS';
            $this->time_value = 'T';
        }

        $this->syncArchivalAutoSelect();

        $this->showSeriesModal = false;
        $this->showParentDropdown = false;
        $this->activeSubDropdownIndex = null;
        $this->seriesModalError = null;
    }

    public function with(): array
    {
        $term = strtolower(trim($this->parentSeriesTitle));

        $parentSuggestionsQuery = DB::table('rdp_record_series')
            ->leftJoin('rdp_record_series_type', 'rdp_record_series.series_type', '=', 'rdp_record_series_type.id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
            ->select([
                'rdp_record_series.id',
                'rdp_record_series.series_title',
                'rdp_record_series.series_type',
                'rdp_record_series.recorded_at_office',
                'rdp_record_series_type.shorted_type',
                'office.office_name as recorded_office_name',
            ])
            ->whereNull('rdp_record_series.parent_id');

        if ($this->selectedSeriesTypeFilter) {
            $parentSuggestionsQuery->where('rdp_record_series.series_type', $this->selectedSeriesTypeFilter);
        }

        if (!empty($term)) {
            $searchTerm = '%' . $term . '%';
            $allMatching = $parentSuggestionsQuery->where('rdp_record_series.series_title', 'ilike', $searchTerm)
                ->limit(50)
                ->get();

            $sorted = $allMatching->sort(function ($a, $b) use ($term) {
                $titleA = strtolower($a->series_title ?? '');
                $titleB = strtolower($b->series_title ?? '');

                $getPriority = function ($title) use ($term) {
                    if ($title === $term) return 1;
                    if (str_starts_with($title, $term)) return 2;
                    if (str_contains($title, ' ' . $term)) return 3;
                    return 4;
                };

                $pA = $getPriority($titleA);
                $pB = $getPriority($titleB);

                if ($pA !== $pB) {
                    return $pA <=> $pB;
                }

                return strcmp($titleA, $titleB);
            })->values();

            $parentSuggestions = $sorted->slice(0, 10);
        } else {
            $parentSuggestions = $parentSuggestionsQuery->orderBy('rdp_record_series.series_title', 'asc')->limit(10)->get();
        }

        $allSeriesSuggestions = DB::table('rdp_record_series')
            ->select('series_title')
            ->when($this->selectedSeriesTypeFilter, fn($q) => $q->where('series_type', $this->selectedSeriesTypeFilter))
            ->when(!empty(trim($this->parentSeriesTitle)), fn($q) => $q->where('series_title', 'ilike', '%' . trim($this->parentSeriesTitle) . '%'))
            ->distinct()
            ->orderBy('series_title', 'asc')
            ->limit(10)
            ->get();

        $recordSeriesTypes = DB::table('rdp_record_series_type')
            ->where('is_active', true)
            ->orderBy('id', 'asc')
            ->get();

        $user = Auth::user();
        $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
        $officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $officesQuery = DB::table($officeTable)
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]']);
        $officesList = $officesQuery->orderBy('office_name', 'asc')->get();

        return [
            'userOfficeCode'       => $userOfficeCode,
            'parentSuggestions'    => $parentSuggestions,
            'allSeriesSuggestions' => $allSeriesSuggestions,
            'recordSeriesTypes'    => $recordSeriesTypes,
            'mediaList'            => DB::table('rdp_recorded_value')->orderBy('medium_name', 'asc')->get(),
            'restrictionsList'     => DB::table('rdp_restriction_type')->orderBy('restriction_value', 'asc')->get(),
            'frequenciesList'      => DB::table('rdp_frequence_use')->orderBy('freq_type', 'asc')->get(),
            'timeValuesList'       => DB::table('rdp_time_value')->orderBy('char_value', 'asc')->get(),
            'utilityValuesList'    => DB::table('rdp_utility_medium')->orderBy('utility_name', 'asc')->get(),
            'officesList'          => $officesList,
            'locationSuggestions'  => $this->getLocationSuggestions(),
        ];
    }

    public function validateRecordData(bool $requireLocation = true): bool
    {
        $fail = function(string $msg): bool {
            $this->showValidationErrors = true;
            $this->errorMessage = $msg;
            $this->dispatch('scroll-to-top');
            return false;
        };

        if (empty($this->selectedSeriesTitle)) {
            return $fail('Please select or configure a Record Series first.');
        }

        $requiredUpload = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings')->where('key', 'rdp_required_upload_file')->value('value') === 'true';
        if ($requiredUpload && !$this->uploadedFile && !$this->dcsAdminForward && !$this->dcsOfficeLock) {
            return $fail('Uploading a file is required to save a record according to system settings.');
        }

        if ($this->entryMode === 'single') {
            if (empty(trim($this->description))) {
                return $fail('Please provide a Record Subject / Description.');
            }

            if (empty(trim($this->date_covered))) {
                return $fail('Please provide the Period Covered (Date Covered).');
            }

            $vol = trim($this->volume ?: ($this->volume_amount ? ($this->volume_amount . ' ' . $this->volume_unit) : ''));
            if (empty($vol) || !preg_match('/\d/', $vol)) {
                return $fail('Please provide a valid Volume Amount & Unit (e.g. 1 box 10 papers).');
            }

            if ($requireLocation && empty(trim($this->records_location))) {
                return $fail('Please provide the Records Location (e.g. Cabinet 3, Shelf 2).');
            }

            if (empty($this->records_medium)) {
                return $fail('Please select a Records Medium.');
            }

            if (empty($this->restriction)) {
                return $fail('Please select a Restriction / Access type.');
            }

            if (empty($this->frequence_use)) {
                return $fail('Please select the Frequency of Use.');
            }

            if (empty($this->utility_values)) {
                return $fail('Please select at least one Utility Value (e.g. Administrative).');
            }
        } elseif ($this->entryMode === 'batch') {
            if (empty(trim($this->description))) {
                return $fail('Please provide a Record Subject / Description for the batch.');
            }

            if (empty($this->batch_start_date) || empty($this->batch_end_date)) {
                return $fail('Please provide both Start and End dates for the period covered.');
            }

            if (empty($this->batch_sub_periods)) {
                return $fail('Please generate the period breakdown ranges for the batch.');
            }

            foreach ($this->batch_sub_periods as $pIdx => $sub) {
                $sVol = trim((string)($sub['volume'] ?? ''));
                if (empty($sVol) || !preg_match('/\d/', $sVol)) {
                    $yearLabel = !empty($sub['start_date']) ? Carbon::parse($sub['start_date'])->format('Y') : ('#' . ($pIdx + 1));
                    return $fail("Please provide a valid volume for all period breakdowns (e.g. 1 box 10 papers). Missing volume for period {$yearLabel}.");
                }
            }

            if ($requireLocation && empty(trim($this->records_location))) {
                return $fail('Please provide the Records Location (e.g. Cabinet 3, Shelf 2).');
            }

            if (empty($this->records_medium)) {
                return $fail('Please select a Records Medium.');
            }

            if (empty($this->restriction)) {
                return $fail('Please select a Restriction / Access type.');
            }

            if (empty($this->frequence_use)) {
                return $fail('Please select the Frequency of Use.');
            }

            if (empty($this->utility_values)) {
                return $fail('Please select at least one Utility Value (e.g. Administrative).');
            }
        } elseif ($this->entryMode === 'multi') {
            if (empty($this->batchItems)) {
                return $fail('Please add at least one record item.');
            }

            foreach ($this->batchItems as $bIdx => $bItem) {
                $itemNum = $bIdx + 1;
                if (empty(trim($bItem['description'] ?? ''))) {
                    return $fail("Please provide a Subject / Title for Record #{$itemNum}.");
                }

                if (empty(trim($bItem['date_covered'] ?? ''))) {
                    return $fail("Please provide the Date Covered for Record #{$itemNum}.");
                }

                $iVol = trim((string)($bItem['volume'] ?? ''));
                if (empty($iVol) || !preg_match('/\d/', $iVol)) {
                    return $fail("Please provide a valid Volume Amount & Unit (e.g. 1 box 10 papers) for Record #{$itemNum}.");
                }

                if (empty(trim($bItem['records_location'] ?? ''))) {
                    return $fail("Please provide the Records Location for Record #{$itemNum}.");
                }

                if (empty($bItem['records_medium'])) {
                    return $fail("Please select a Records Medium for Record #{$itemNum}.");
                }

                if (empty($bItem['restriction'])) {
                    return $fail("Please select a Restriction type for Record #{$itemNum}.");
                }

                if (empty($bItem['frequence_use'])) {
                    return $fail("Please select the Frequency of Use for Record #{$itemNum}.");
                }

                if (empty($bItem['utility_values'])) {
                    return $fail("Please select at least one Utility Value for Record #{$itemNum}.");
                }
            }
        }

        $this->showValidationErrors = false;
        return true;
    }

    public function saveDraft(): void
    {
        if ($this->dcsAdminForward) {
            $this->errorMessage = 'Forward this appraisal to the offices. This step does not create a record.';
            $this->dispatch('scroll-to-top');
            return;
        }

        $rateCheck = \App\Services\RateLimiterService::check('rdp_create');
        if (!$rateCheck['allowed']) {
            $this->errorMessage = $rateCheck['message'];
            $this->dispatch('scroll-to-top');
            return;
        }

        if (!$this->validateRecordData()) {
            return;
        }

        $this->clearMessages();

        try {
            DB::beginTransaction();
            if (!$this->claimOfficeIntake()) {
                DB::rollBack();
                $this->errorMessage = 'This office already created the record for this document.';
                $this->dispatch('scroll-to-top');
                return;
            }

            $user = Auth::user();
            $userOfficeCode = \App\Helpers\RegisterQueryHelper::rdpOfficeOwnCode(
                $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null
            );
            if ($userOfficeCode === null || $userOfficeCode === '') {
                DB::rollBack();
                $this->errorMessage = 'Your account office is not linked to an active office in the system. Ask Admin to fix your office assignment.';
                $this->dispatch('scroll-to-top');
                return;
            }
            $rawVol = trim($this->volume);
            $rawAmt = trim((string)$this->volume_amount);
            if (!empty($rawVol) && preg_match('/\d/', $rawVol)) {
                $formattedVolume = mb_strtoupper($rawVol);
            } elseif (!empty($rawAmt) && preg_match('/\d/', $rawAmt)) {
                $formattedVolume = mb_strtoupper($rawAmt . ' ' . ($this->volume_unit ?: 'FOLDER'));
            } else {
                $formattedVolume = null;
            }

            $titles = explode(' ➔ ', $this->selectedSeriesTitle);
            $lastSeriesId = null;

            foreach ($titles as $idx => $title) {
                $trimmed = mb_strtoupper(trim($title));
                $existingQuery = DB::table('rdp_record_series')
                    ->where('series_title', 'ilike', $trimmed)
                    ->where('parent_id', $lastSeriesId);

                if ($idx === 0 && $this->selectedSeriesTypeFilter) {
                    $existingQuery->where('series_type', $this->selectedSeriesTypeFilter);
                }

                $existing = $existingQuery->first();

                if ($existing) {
                    $lastSeriesId = $existing->id;
                } else {
                    $lastSeriesId = DB::table('rdp_record_series')->insertGetId([
                        'series_title'       => $trimmed,
                        'parent_id'          => $lastSeriesId,
                        'series_type'        => ($idx === 0) ? $this->selectedSeriesTypeFilter : null,
                        'recorded_at_office' => $userOfficeCode,
                        'created_by'         => $user?->id,
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);
                }
            }

            $this->record_series_id = $lastSeriesId;

            $documentIdHandler = null;
            if ($this->uploadedFile) {
                $uploadResult = \App\Services\DocumentStorageService::storeUpload($this->uploadedFile, 'RDP', $user);
                $documentIdHandler = $uploadResult['document_id'];
            } else {
                $documentIdHandler = $this->existingUploadDocumentId();
            }

            $periodId = null;
            $computedTotal = $this->computeTotalPeriod($this->active_period, $this->storage_period, $this->is_permanent);
            $activePeriod = $this->is_permanent ? 'Permanent' : (trim($this->active_period) ?: null);
            $storagePeriod = $this->is_permanent ? 'Permanent' : (trim($this->storage_period) ?: null);
            $totalPeriod = $computedTotal ?: (trim($this->retention_period) ?: null);

            if ($this->is_permanent || !empty($activePeriod) || !empty($storagePeriod) || !empty($totalPeriod)) {
                $periodId = DB::table('rdp_retention_period')->insertGetId([
                    'active_period'  => $activePeriod,
                    'storage_period' => $storagePeriod,
                    'total_period'   => $totalPeriod,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            $itemsToProcess = [];
            if ($this->entryMode === 'multi' && !empty($this->batchItems)) {
                foreach ($this->batchItems as $bItem) {
                    $desc = trim($bItem['description'] ?? '');
                    if (empty($desc)) continue;
                    $itemsToProcess[] = [
                        'description'       => mb_strtoupper($desc),
                        'volume'            => mb_strtoupper(trim($bItem['volume'] ?? '')),
                        'records_location'  => mb_strtoupper(trim($bItem['records_location'] ?? '')),
                        'restriction'       => $bItem['restriction'] ?? null,
                        'records_medium'    => !empty($bItem['records_medium']) ? (int)$bItem['records_medium'] : null,
                        'time_value'        => $bItem['time_value'] ?? ($this->time_value ?: 'T'),
                        'frequence_use'     => $bItem['frequence_use'] ?? null,
                        'utility_values'    => $bItem['utility_values'] ?? [],
                        'date_covered'      => $bItem['date_covered'] ?? '',
                        'date_covered_end'  => null,
                        'duplicate_offices' => $bItem['duplicate_offices'] ?? [],
                        'duplicate_file_view' => $bItem['duplicate_file_view'] ?? $this->duplicate_file_view,
                        'sub_periods'       => [],
                    ];
                }
            } elseif ($this->entryMode === 'batch') {
                $desc = trim($this->description);
                $batchVol = $this->getBatchTotalVolumeFormatted();
                $itemsToProcess[] = [
                    'description'       => mb_strtoupper($desc),
                    'volume'            => mb_strtoupper($batchVol),
                    'records_location'  => mb_strtoupper(trim($this->records_location)),
                    'restriction'       => $this->restriction,
                    'records_medium'    => $this->records_medium,
                    'time_value'        => $this->time_value ?: 'T',
                    'frequence_use'     => $this->frequence_use,
                    'utility_values'    => $this->utility_values,
                    'date_covered'      => $this->batch_start_date ?: $this->date_covered,
                    'date_covered_end'  => $this->batch_end_date ?: null,
                    'duplicate_offices' => $this->duplicate_offices,
                    'duplicate_file_view' => $this->duplicate_file_view,
                    'sub_periods'       => $this->batch_sub_periods,
                ];
            } else {
                $itemsToProcess[] = [
                    'description'       => mb_strtoupper(trim($this->description)),
                    'volume'            => $formattedVolume,
                    'records_location'  => mb_strtoupper(trim($this->records_location)),
                    'restriction'       => $this->restriction,
                    'records_medium'    => $this->records_medium,
                    'time_value'        => $this->time_value ?: 'T',
                    'frequence_use'     => $this->frequence_use,
                    'utility_values'    => $this->utility_values,
                    'date_covered'      => $this->date_covered,
                    'date_covered_end'  => null,
                    'duplicate_offices' => $this->duplicate_offices,
                    'duplicate_file_view' => $this->duplicate_file_view,
                    'sub_periods'       => [],
                ];
            }

            if (empty($itemsToProcess)) {
                $this->errorMessage = 'Please provide at least one record subject/description to save draft.';
                $this->dispatch('scroll-to-top');
                return;
            }

            $firstRecordId = null;
            $hasEndCol = \Illuminate\Support\Facades\Schema::hasColumn('rdp_period_covered', 'date_covered_end');
            $hasVolCol = \Illuminate\Support\Facades\Schema::hasColumn('rdp_period_covered', 'volume');
            $hasDescCol = \Illuminate\Support\Facades\Schema::hasColumn('rdp_period_covered', 'description');
            $hasBatchCol = \Illuminate\Support\Facades\Schema::hasColumn('rdp_record', 'ispartof_batch');
            $hasBatchIdCol = \Illuminate\Support\Facades\Schema::hasColumn('rdp_record', 'batch_id');

            $batchId = null;
            if ($this->entryMode === 'batch' && \Illuminate\Support\Facades\Schema::hasTable('rdp_batch')) {
                $batchId = DB::table('rdp_batch')->insertGetId([
                    'batch_name' => !empty($this->selectedSeriesTitle) ? $this->selectedSeriesTitle : 'Batch Record',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($itemsToProcess as $rIdx => $rData) {
                $insertRow = [
                    'record_series_id'       => $this->record_series_id,
                    'description'            => $rData['description'],
                    'period_id'              => $periodId,
                    'volume'                 => $rData['volume'],
                    'records_location'       => $rData['records_location'],
                    'restriction'            => $rData['restriction'],
                    'records_medium'         => $rData['records_medium'],
                    'time_value'             => $rData['time_value'],
                    'frequence_use'          => $rData['frequence_use'],
                    'user_own'               => $user?->id,
                    'office_own'             => $userOfficeCode,
                    'upload_doc_id_handler'  => ($rIdx === 0) ? $documentIdHandler : null,
                    'is_draft'               => true,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ];
                if ($hasBatchCol) {
                    $insertRow['ispartof_batch'] = ($this->entryMode === 'batch');
                }
                if ($hasBatchIdCol && $batchId !== null) {
                    $insertRow['batch_id'] = $batchId;
                }

                $recordId = DB::table('rdp_record')->insertGetId($insertRow);
                $this->rememberLocationSuggestion($rData['records_location'] ?? null, $this->record_series_id, $userOfficeCode);

                if ($firstRecordId === null) {
                    $firstRecordId = $recordId;
                }

                DB::table('rdp_record')->where('id', $recordId)->update([
                    'utility_value'  => $recordId,
                    'duplication_id' => $recordId,
                ]);

                foreach ($rData['utility_values'] as $uId) {
                    DB::table('rdp_utility_manager')->insert([
                        'record_holder'  => $recordId,
                        'utility_medium' => $uId,
                        'is_active'      => true,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }

                $hasCanView = \Illuminate\Support\Facades\Schema::hasColumn('rdp_duplication_section', 'can_view_file');
                $dupFileViews = $rData['duplicate_file_view'] ?? [];
                foreach (\App\Helpers\RegisterQueryHelper::resolveSysOfficeCodes($rData['duplicate_offices'] ?? []) as $dupOffice) {
                    $insertDup = [
                        'dup_id_manager' => $recordId,
                        'office_code'    => $dupOffice,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ];
                    if ($hasCanView) {
                        $insertDup['can_view_file'] = (bool)($dupFileViews[$dupOffice] ?? false);
                    }
                    DB::table('rdp_duplication_section')->insert($insertDup);
                }

                if (!empty($rData['sub_periods'])) {
                    foreach ($rData['sub_periods'] as $sub) {
                        if (empty($sub['start_date']) && empty($sub['end_date'])) continue;
                        $pRow = [
                            'period_owner' => $recordId,
                            'date_covered' => $sub['start_date'] ?? $rData['date_covered'],
                            'created_at'   => now(),
                            'modified_at'  => now(),
                        ];
                        if ($hasEndCol && !empty($sub['end_date'])) {
                            $pRow['date_covered_end'] = $sub['end_date'];
                        }
                        if ($hasVolCol && !empty($sub['volume'])) {
                            $pRow['volume'] = trim($sub['volume']);
                        }
                        if ($hasDescCol) {
                            $subDesc = !empty(trim((string)($sub['description'] ?? '')))
                                ? trim($sub['description'])
                                : $this->formatSubPeriodDefaultDescription($sub['start_date'] ?? '', $sub['end_date'] ?? '', $rData['description']);
                            if (!empty($subDesc)) {
                                $pRow['description'] = $subDesc;
                            }
                        }
                        DB::table('rdp_period_covered')->insert($pRow);
                    }
                } elseif (!empty($rData['date_covered'])) {
                    $pRow = [
                        'period_owner' => $recordId,
                        'date_covered' => $rData['date_covered'],
                        'created_at'   => now(),
                        'modified_at'  => now(),
                    ];
                    if ($hasEndCol && !empty($rData['date_covered_end'])) {
                        $pRow['date_covered_end'] = $rData['date_covered_end'];
                    }
                    DB::table('rdp_period_covered')->insert($pRow);
                }
            }

            if ($this->prefill_intake_id && $firstRecordId) {
                DB::table('rdp_received_documents')->where('id', $this->prefill_intake_id)->update([
                    'status' => 'appraised',
                    'appraised_record_id' => $firstRecordId,
                    'updated_at' => now(),
                ]);
            }

            $draftAppraisedRequestId = (int) ($this->dcsRequestId ?? 0);
            if ($draftAppraisedRequestId < 1 && $this->prefill_intake_id) {
                $intakeMeta = DB::table('rdp_received_documents')->where('id', $this->prefill_intake_id)->value('metadata');
                $metaArr = is_string($intakeMeta) ? (json_decode($intakeMeta, true) ?: []) : (array) ($intakeMeta ?? []);
                $draftAppraisedRequestId = (int) ($metaArr['dcs_request_id'] ?? 0);
            }

            DB::commit();

            if ($draftAppraisedRequestId > 0 && $firstRecordId) {
                \App\Services\RdpIntakeService::afterDcsRequestAppraised($draftAppraisedRequestId);
            }

            $count = count($itemsToProcess);
            $this->successMessage = ($this->entryMode === 'multi' && $count > 1) 
                ? "Inventory and Appraisal draft for {$count} records saved successfully!" 
                : "Inventory and Appraisal draft saved successfully!";
            $this->dispatch('scroll-to-top');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to save draft: ' . $e->getMessage();
            $this->dispatch('scroll-to-top');
        }
    }

    public function createRecord(): void
    {
        if ($this->dcsAdminForward) {
            $this->errorMessage = 'Forward this appraisal to the offices. Each office creates its own record after it enters Records Location.';
            $this->dispatch('scroll-to-top');
            return;
        }

        if ($this->dcsOfficeBlocked) {
            $this->errorMessage = 'Mark this document as received before creating the record.';
            $this->dispatch('scroll-to-top');
            return;
        }

        $rateCheck = \App\Services\RateLimiterService::check('rdp_create');
        if (!$rateCheck['allowed']) {
            $this->errorMessage = $rateCheck['message'];
            $this->dispatch('scroll-to-top');
            return;
        }

        if (!$this->validateRecordData()) {
            return;
        }

        $this->clearMessages();

        try {
            DB::beginTransaction();
            if (!$this->claimOfficeIntake()) {
                DB::rollBack();
                $this->errorMessage = 'This office already created the record for this document.';
                $this->dispatch('scroll-to-top');
                return;
            }

            $user = Auth::user();
            $userOfficeCode = \App\Helpers\RegisterQueryHelper::rdpOfficeOwnCode(
                $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null
            );
            if ($userOfficeCode === null || $userOfficeCode === '') {
                DB::rollBack();
                $this->errorMessage = 'Your account office is not linked to an active office in the system. Ask Admin to fix your office assignment.';
                $this->dispatch('scroll-to-top');
                return;
            }
            $collectivePool = $this->spreadToDuplicateOffices ? $this->duplicate_offices : ($this->collectiveOffices ?? []);
            $validCollective = array_values(array_filter(array_unique($collectivePool), fn($code) => !empty($code) && strcasecmp((string) $code, $userOfficeCode) !== 0));
            $isSpread = !empty($validCollective);
            $requestGroupId = $isSpread ? ('SPREAD-' . date('Ymd') . '-' . strtoupper(Str::random(6))) : null;

            $rawVol = trim($this->volume);
            $rawAmt = trim((string)$this->volume_amount);
            if (!empty($rawVol) && preg_match('/\d/', $rawVol)) {
                $formattedVolume = mb_strtoupper($rawVol);
            } elseif (!empty($rawAmt) && preg_match('/\d/', $rawAmt)) {
                $formattedVolume = mb_strtoupper($rawAmt . ' ' . ($this->volume_unit ?: 'FOLDER'));
            } else {
                $formattedVolume = '';
            }

            $titles = explode(' ➔ ', $this->selectedSeriesTitle);
            $lastSeriesId = null;

            foreach ($titles as $idx => $title) {
                $trimmed = mb_strtoupper(trim($title));
                $existingQuery = DB::table('rdp_record_series')
                    ->where('series_title', 'ilike', $trimmed)
                    ->where('parent_id', $lastSeriesId);

                if ($idx === 0 && $this->selectedSeriesTypeFilter) {
                    $existingQuery->where('series_type', $this->selectedSeriesTypeFilter);
                }

                $existing = $existingQuery->first();

                if ($existing) {
                    $lastSeriesId = $existing->id;
                } else {
                    $lastSeriesId = DB::table('rdp_record_series')->insertGetId([
                        'series_title'       => $trimmed,
                        'parent_id'          => $lastSeriesId,
                        'series_type'        => ($idx === 0) ? $this->selectedSeriesTypeFilter : null,
                        'recorded_at_office' => $userOfficeCode,
                        'created_by'         => $user?->id,
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);
                }
            }

            $this->record_series_id = $lastSeriesId;

            $documentIdHandler = null;
            if ($this->uploadedFile) {
                $uploadResult = \App\Services\DocumentStorageService::storeUpload($this->uploadedFile, 'RDP', $user);
                $documentIdHandler = $uploadResult['document_id'];
            } else {
                $documentIdHandler = $this->existingUploadDocumentId();
            }

            $periodId = null;
            $computedTotal = $this->computeTotalPeriod($this->active_period, $this->storage_period, $this->is_permanent);
            $activePeriod = $this->is_permanent ? 'Permanent' : (trim($this->active_period) ?: null);
            $storagePeriod = $this->is_permanent ? 'Permanent' : (trim($this->storage_period) ?: null);
            $totalPeriod = $computedTotal ?: (trim($this->retention_period) ?: null);

            if ($this->is_permanent || !empty($activePeriod) || !empty($storagePeriod) || !empty($totalPeriod)) {
                $periodId = DB::table('rdp_retention_period')->insertGetId([
                    'active_period'  => $activePeriod,
                    'storage_period' => $storagePeriod,
                    'total_period'   => $totalPeriod,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            $itemsToProcess = [];
            if ($this->entryMode === 'multi' && !empty($this->batchItems)) {
                foreach ($this->batchItems as $bItem) {
                    $desc = trim($bItem['description'] ?? '');
                    if (empty($desc)) continue;
                    $itemsToProcess[] = [
                        'description'       => mb_strtoupper($desc),
                        'volume'            => mb_strtoupper(trim($bItem['volume'] ?? '')),
                        'records_location'  => mb_strtoupper(trim($bItem['records_location'] ?? '')),
                        'restriction'       => $bItem['restriction'] ?? null,
                        'records_medium'    => !empty($bItem['records_medium']) ? (int)$bItem['records_medium'] : null,
                        'time_value'        => $bItem['time_value'] ?? ($this->time_value ?: 'T'),
                        'frequence_use'     => $bItem['frequence_use'] ?? null,
                        'utility_values'    => $bItem['utility_values'] ?? [],
                        'date_covered'      => $bItem['date_covered'] ?? '',
                        'date_covered_end'  => null,
                        'duplicate_offices' => $bItem['duplicate_offices'] ?? [],
                        'duplicate_file_view' => $bItem['duplicate_file_view'] ?? $this->duplicate_file_view,
                        'sub_periods'       => [],
                    ];
                }
            } elseif ($this->entryMode === 'batch') {
                $desc = trim($this->description);
                $batchVol = $this->getBatchTotalVolumeFormatted();
                if ($this->isAppraising && empty($batchVol)) {
                    DB::rollBack();
                    $this->errorMessage = 'Please input volume for the periods covered.';
                    $this->dispatch('scroll-to-top');
                    return;
                }
                if (!empty($desc)) {
                    $itemsToProcess[] = [
                        'description'       => mb_strtoupper($desc),
                        'volume'            => mb_strtoupper($batchVol),
                        'records_location'  => mb_strtoupper(trim($this->records_location)),
                        'restriction'       => $this->restriction,
                        'records_medium'    => $this->records_medium,
                        'time_value'        => $this->time_value ?: 'T',
                        'frequence_use'     => $this->frequence_use,
                        'utility_values'    => $this->utility_values,
                        'date_covered'      => $this->batch_start_date ?: $this->date_covered,
                        'date_covered_end'  => $this->batch_end_date ?: null,
                        'duplicate_offices' => $this->duplicate_offices,
                        'duplicate_file_view' => $this->duplicate_file_view,
                        'sub_periods'       => $this->batch_sub_periods,
                    ];
                }
            } else {
                $desc = trim($this->description);
                if (!empty($desc)) {
                    $itemsToProcess[] = [
                        'description'       => mb_strtoupper($desc),
                        'volume'            => $formattedVolume,
                        'records_location'  => mb_strtoupper(trim($this->records_location)),
                        'restriction'       => $this->restriction,
                        'records_medium'    => $this->records_medium,
                        'time_value'        => $this->time_value ?: 'T',
                        'frequence_use'     => $this->frequence_use,
                        'utility_values'    => $this->utility_values,
                        'date_covered'      => $this->date_covered,
                        'date_covered_end'  => null,
                        'duplicate_offices' => $this->duplicate_offices,
                        'duplicate_file_view' => $this->duplicate_file_view,
                        'sub_periods'       => [],
                    ];
                }
            }

            if (empty($itemsToProcess)) {
                $this->errorMessage = 'Please provide at least one record subject/description.';
                $this->dispatch('scroll-to-top');
                return;
            }

            $firstRecordId = null;
            $hasEndCol = \Illuminate\Support\Facades\Schema::hasColumn('rdp_period_covered', 'date_covered_end');
            $hasVolCol = \Illuminate\Support\Facades\Schema::hasColumn('rdp_period_covered', 'volume');
            $hasDescCol = \Illuminate\Support\Facades\Schema::hasColumn('rdp_period_covered', 'description');
            $hasBatchCol = \Illuminate\Support\Facades\Schema::hasColumn('rdp_record', 'ispartof_batch');
            $hasBatchIdCol = \Illuminate\Support\Facades\Schema::hasColumn('rdp_record', 'batch_id');
            $hasRequestCols = \Illuminate\Support\Facades\Schema::hasColumn('rdp_record', 'is_request');

            $batchId = null;
            if ($this->entryMode === 'batch' && \Illuminate\Support\Facades\Schema::hasTable('rdp_batch')) {
                $batchId = DB::table('rdp_batch')->insertGetId([
                    'batch_name' => !empty($this->selectedSeriesTitle) ? $this->selectedSeriesTitle : 'Batch Record',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($itemsToProcess as $rIdx => $rData) {
                // If spreading, also register collective offices as duplicate holders
                $recordDuplicates = $rData['duplicate_offices'] ?? [];
                if ($isSpread) {
                    $recordDuplicates = array_values(array_unique(array_merge($recordDuplicates, $validCollective)));
                }

                $insertRow = [
                    'record_series_id'       => $this->record_series_id,
                    'description'            => $rData['description'],
                    'period_id'              => $periodId,
                    'volume'                 => $rData['volume'],
                    'records_location'       => $rData['records_location'],
                    'restriction'            => $rData['restriction'],
                    'records_medium'         => $rData['records_medium'],
                    'time_value'             => $rData['time_value'],
                    'frequence_use'          => $rData['frequence_use'],
                    'user_own'               => $user?->id,
                    'office_own'             => $userOfficeCode,
                    'upload_doc_id_handler'  => ($rIdx === 0) ? $documentIdHandler : null,
                    'is_draft'               => false,
                    'is_active'              => true,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ];
                if ($hasRequestCols) {
                    $insertRow['is_request'] = false;
                    $insertRow['request_group_id'] = $requestGroupId;
                    $insertRow['request_status'] = null;
                    $insertRow['request_origin_office'] = null;
                    $insertRow['request_target_office'] = null;
                }
                if ($hasBatchCol) {
                    $insertRow['ispartof_batch'] = ($this->entryMode === 'batch');
                }
                if ($hasBatchIdCol && $batchId !== null) {
                    $insertRow['batch_id'] = $batchId;
                }

                $recordId = DB::table('rdp_record')->insertGetId($insertRow);
                $this->rememberLocationSuggestion($rData['records_location'] ?? null, $this->record_series_id, $userOfficeCode);

                if ($firstRecordId === null) {
                    $firstRecordId = $recordId;
                }

                DB::table('rdp_record')->where('id', $recordId)->update([
                    'utility_value'  => $recordId,
                    'duplication_id' => $recordId,
                ]);

                foreach ($rData['utility_values'] as $uId) {
                    DB::table('rdp_utility_manager')->insert([
                        'record_holder'  => $recordId,
                        'utility_medium' => $uId,
                        'is_active'      => true,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }

                $hasCanView = \Illuminate\Support\Facades\Schema::hasColumn('rdp_duplication_section', 'can_view_file');
                $dupFileViews = $rData['duplicate_file_view'] ?? [];

                foreach (\App\Helpers\RegisterQueryHelper::resolveSysOfficeCodes($recordDuplicates) as $dupOffice) {
                    $insertDup = [
                        'dup_id_manager' => $recordId,
                        'office_code'    => $dupOffice,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ];
                    if ($hasCanView) {
                        $insertDup['can_view_file'] = (bool)($dupFileViews[$dupOffice] ?? false);
                    }
                    DB::table('rdp_duplication_section')->insert($insertDup);
                }

                if (!empty($rData['sub_periods'])) {
                    foreach ($rData['sub_periods'] as $sub) {
                        if (empty($sub['start_date']) && empty($sub['end_date'])) continue;
                        $pRow = [
                            'period_owner' => $recordId,
                            'date_covered' => $sub['start_date'] ?? $rData['date_covered'],
                            'created_at'   => now(),
                            'modified_at'  => now(),
                        ];
                        if ($hasEndCol && !empty($sub['end_date'])) {
                            $pRow['date_covered_end'] = $sub['end_date'];
                        }
                        if ($hasVolCol && !empty($sub['volume'])) {
                            $pRow['volume'] = trim($sub['volume']);
                        }
                        if ($hasDescCol) {
                            $subDesc = !empty(trim((string)($sub['description'] ?? '')))
                                ? trim($sub['description'])
                                : $this->formatSubPeriodDefaultDescription($sub['start_date'] ?? '', $sub['end_date'] ?? '', $rData['description']);
                            if (!empty($subDesc)) {
                                $pRow['description'] = $subDesc;
                            }
                        }
                        DB::table('rdp_period_covered')->insert($pRow);
                    }
                } elseif (!empty($rData['date_covered'])) {
                    $pRow = [
                        'period_owner' => $recordId,
                        'date_covered' => $rData['date_covered'],
                        'created_at'   => now(),
                        'modified_at'  => now(),
                    ];
                    if ($hasEndCol && !empty($rData['date_covered_end'])) {
                        $pRow['date_covered_end'] = $rData['date_covered_end'];
                    }
                    DB::table('rdp_period_covered')->insert($pRow);
                }

                // If spreading to collective offices, insert a pending request record for each collective office
                if ($isSpread && $hasRequestCols) {
                    foreach ($validCollective as $colOffice) {
                        $canViewColFile = (bool)($dupFileViews[$colOffice] ?? false);
                        $colInsertRow = [
                            'record_series_id'       => $this->record_series_id,
                            'description'            => $rData['description'],
                            'period_id'              => $periodId,
                            'volume'                 => $rData['volume'],
                            'records_location'       => $rData['records_location'],
                            'restriction'            => $rData['restriction'],
                            'records_medium'         => $rData['records_medium'],
                            'time_value'             => $rData['time_value'],
                            'frequence_use'          => $rData['frequence_use'],
                            'user_own'               => $user?->id,
                            'office_own'             => $colOffice,
                            'upload_doc_id_handler'  => ($rIdx === 0 && $canViewColFile) ? $documentIdHandler : null,
                            'is_draft'               => false,
                            'is_active'              => false,
                            'is_request'             => true,
                            'request_group_id'       => $requestGroupId,
                            'request_status'         => 'pending',
                            'request_origin_office'  => $userOfficeCode,
                            'request_target_office'  => $colOffice,
                            'created_at'             => now(),
                            'updated_at'             => now(),
                        ];
                        if ($hasBatchCol) {
                            $colInsertRow['ispartof_batch'] = ($this->entryMode === 'batch');
                        }
                        if ($hasBatchIdCol && $batchId !== null) {
                            $colInsertRow['batch_id'] = $batchId;
                        }

                        $colRecordId = DB::table('rdp_record')->insertGetId($colInsertRow);

                        DB::table('rdp_record')->where('id', $colRecordId)->update([
                            'utility_value'  => $colRecordId,
                            'duplication_id' => $colRecordId,
                        ]);

                        foreach ($rData['utility_values'] as $uId) {
                            DB::table('rdp_utility_manager')->insert([
                                'record_holder'  => $colRecordId,
                                'utility_medium' => $uId,
                                'is_active'      => true,
                                'created_at'     => now(),
                                'updated_at'     => now(),
                            ]);
                        }

                        foreach (\App\Helpers\RegisterQueryHelper::resolveSysOfficeCodes($recordDuplicates) as $dupOffice) {
                            $insertColDup = [
                                'dup_id_manager' => $colRecordId,
                                'office_code'    => $dupOffice,
                                'created_at'     => now(),
                                'updated_at'     => now(),
                            ];
                            if ($hasCanView) {
                                $insertColDup['can_view_file'] = (bool)($dupFileViews[$dupOffice] ?? false);
                            }
                            DB::table('rdp_duplication_section')->insert($insertColDup);
                        }

                        if (!empty($rData['sub_periods'])) {
                            foreach ($rData['sub_periods'] as $sub) {
                                if (empty($sub['start_date']) && empty($sub['end_date'])) continue;
                                $colPRow = [
                                    'period_owner' => $colRecordId,
                                    'date_covered' => $sub['start_date'] ?? $rData['date_covered'],
                                    'created_at'   => now(),
                                    'modified_at'  => now(),
                                ];
                                if ($hasEndCol && !empty($sub['end_date'])) {
                                    $colPRow['date_covered_end'] = $sub['end_date'];
                                }
                                if ($hasVolCol && !empty($sub['volume'])) {
                                    $colPRow['volume'] = trim($sub['volume']);
                                }
                                if ($hasDescCol) {
                                    $subDesc = !empty(trim((string)($sub['description'] ?? '')))
                                        ? trim($sub['description'])
                                        : $this->formatSubPeriodDefaultDescription($sub['start_date'] ?? '', $sub['end_date'] ?? '', $rData['description']);
                                    if (!empty($subDesc)) {
                                        $colPRow['description'] = $subDesc;
                                    }
                                }
                                DB::table('rdp_period_covered')->insert($colPRow);
                            }
                        } elseif (!empty($rData['date_covered'])) {
                            $colPRow = [
                                'period_owner' => $colRecordId,
                                'date_covered' => $rData['date_covered'],
                                'created_at'   => now(),
                                'modified_at'  => now(),
                            ];
                            if ($hasEndCol && !empty($rData['date_covered_end'])) {
                                $colPRow['date_covered_end'] = $rData['date_covered_end'];
                            }
                            DB::table('rdp_period_covered')->insert($colPRow);
                        }
                    }
                }
            }

            if ($this->prefill_intake_id && $firstRecordId) {
                DB::table('rdp_received_documents')->where('id', $this->prefill_intake_id)->update([
                    'status' => 'appraised',
                    'appraised_record_id' => $firstRecordId,
                    'updated_at' => now(),
                ]);
            }

            $appraisedDcsRequestId = 0;
            if ($this->dcsRequestId) {
                $this->syncMasterlistEffectivityFromPeriod((int) $this->dcsRequestId);
                $appraisedDcsRequestId = (int) $this->dcsRequestId;
            } elseif ($this->prefill_intake_id) {
                $intakeMeta = DB::table('rdp_received_documents')->where('id', $this->prefill_intake_id)->value('metadata');
                $metaArr = is_string($intakeMeta) ? (json_decode($intakeMeta, true) ?: []) : (array) ($intakeMeta ?? []);
                $linkedRequestId = (int) ($metaArr['dcs_request_id'] ?? 0);
                if ($linkedRequestId > 0) {
                    $this->syncMasterlistEffectivityFromPeriod($linkedRequestId);
                    $appraisedDcsRequestId = $linkedRequestId;
                }
            }

            DB::commit();

            if ($appraisedDcsRequestId > 0) {
                \App\Services\RdpIntakeService::afterDcsRequestAppraised($appraisedDcsRequestId);
            }

            if ($isSpread) {
                $seriesTitle = !empty($this->selectedSeriesTitle) ? $this->selectedSeriesTitle : 'Inventory Record';
                foreach ($validCollective as $colOffice) {
                    \App\Services\RdpNotificationService::notifyRecordRequestSubmitted(
                        $colOffice,
                        $userOfficeCode ?? 'Unknown Office',
                        $seriesTitle
                    );
                }
            }

            $count = count($itemsToProcess);
            if ($isSpread) {
                $officeCount = count($validCollective);
                $this->successMessage = ($this->entryMode === 'multi' && $count > 1) 
                    ? "Multi-entry of {$count} records created for {$userOfficeCode} and spread to {$officeCount} collective office(s)!" 
                    : "Record created successfully for {$userOfficeCode} and spread to {$officeCount} collective office(s)!";
            } else {
                $this->successMessage = ($this->entryMode === 'multi' && $count > 1) 
                    ? "Multi-entry of {$count} records created successfully under this series!" 
                    : ($this->entryMode === 'batch' 
                        ? "Batch record with duration breakdown created successfully!"
                        : "Inventory and Appraisal Record created successfully!");
            }
            $this->resetFormFields();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to create record: ' . $e->getMessage();
            $this->dispatch('scroll-to-top');
        }
    }

    public function resetFormFields(): void
    {
        $this->showVerificationModal = false;
        $this->showValidationErrors = false;
        $this->entryMode = 'single';
        $this->isBatchMode = false;
        $this->batchItems = [];
        $this->batch_start_date = '';
        $this->batch_end_date = '';
        $this->batch_volume_unit = 'papers';
        $this->batch_default_volume = '';
        $this->batch_dropdown_expanded = true;
        $this->batch_sub_periods = [];
        $this->showBatchModal = false;
        $this->selectedSeriesTitle = null;
        $this->record_series_id = null;
        $this->description = '';
        $this->volume = '';
        $this->volume_amount = '';
        $this->volume_unit = 'Folder';
        $this->isAppraising = false;
        $this->prefill_intake_id = null;
        $this->records_medium = $this->getDefaultMediumId();
        $this->restriction = 'Restricted';
        $this->records_location = '';
        $this->showLocationDropdown = false;
        $this->activeBatchLocationIndex = null;
        $this->frequence_use = 'Annually';
        $this->duplication = null;
        $this->time_value = 'T';
        $this->utility_values = [1];
        $this->retention_period = '';
        $this->is_permanent = false;
        $this->active_period = '';
        $this->storage_period = '';
        $this->disposition_provision = '';
        $this->uploadedFile = null;
        $this->date_covered = Carbon::now()->format('Y-m-d');
        $this->parentSeriesTitle = '';
        $this->subsections = [];
        $this->duplicate_offices = [];
        $this->duplicate_file_view = [];
        $this->spreadToDuplicateOffices = false;
        $this->duplicate_search = '';
        $this->showDuplicateDropdown = false;
        $this->collectiveOffices = [];
        $this->showCollectiveModal = false;
        $this->collectiveSearch = '';
        $this->tempCollectiveOffices = [];
        $this->dispatch('scroll-to-top');
    }

    public function clearMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }
}; ?>

<div class="inventory-appraisal-form" 
     x-data="{
        collectiveModalOpen: false,
        collectiveSearch: '',
        collectiveSelected: @entangle('collectiveOffices'),
        collectiveTempSelected: [],
        allOffices: {{ json_encode($officesList->filter(fn($o) => $o->office_code !== ($userOfficeCode ?? null))->map(fn($o) => ['code' => $o->office_code, 'name' => $o->office_name])->values()) }},
        get filteredCollectiveOffices() {
            if (!this.collectiveSearch || !this.collectiveSearch.trim()) return this.allOffices;
            const q = this.collectiveSearch.toLowerCase().trim();
            return this.allOffices.filter(o => 
                (o.code && o.code.toLowerCase().includes(q)) || 
                (o.name && o.name.toLowerCase().includes(q))
            );
        },
        openCollectiveModal() {
            this.collectiveTempSelected = Array.isArray(this.collectiveSelected) ? [...this.collectiveSelected] : [];
            this.collectiveSearch = '';
            this.collectiveModalOpen = true;
        },
        closeCollectiveModal() {
            this.collectiveModalOpen = false;
            this.collectiveSearch = '';
        },
        toggleCollectiveOffice(code) {
            if (this.collectiveTempSelected.includes(code)) {
                this.collectiveTempSelected = this.collectiveTempSelected.filter(c => c !== code);
            } else {
                this.collectiveTempSelected.push(code);
            }
        },
        selectAllCollectiveOffices() {
            this.collectiveTempSelected = this.allOffices.map(o => o.code);
        },
        clearAllCollectiveOffices() {
            this.collectiveTempSelected = [];
        },
        applyCollectiveModal() {
            this.collectiveSelected = [...this.collectiveTempSelected];
            if (this.$wire) {
                this.$wire.set('collectiveOffices', this.collectiveTempSelected, false);
            }
            this.collectiveModalOpen = false;
        }
     }"
     @scroll-to-top.window="rdpScrollToTop()" 
     style="padding: 24px; max-width: 1200px; margin: 0 auto;">
    @push('styles')
        @vite(['resources/css/rdp/inventory-and-appraisal.css'])
    @endpush

    <script>
        function rdpScrollToTop() {
            const article = document.getElementById('article-container') || document.querySelector('.article-container');
            if (article) {
                article.scrollTo({ top: 0, behavior: 'smooth' });
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        if (window.Livewire) {
            Livewire.on('scroll-to-top', () => {
                setTimeout(rdpScrollToTop, 50);
            });
        } else {
            document.addEventListener('livewire:init', () => {
                Livewire.on('scroll-to-top', () => {
                    setTimeout(rdpScrollToTop, 50);
                });
            });
        }
    </script>


    <!-- Received Document Intake Banner -->
    @if ($prefill_intake_id)
        <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 38px; height: 38px; border-radius: 8px; background: #dbeafe; display: flex; align-items: center; justify-content: center; color: #1d4ed8; flex-shrink: 0;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                </div>
                <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-weight: 700; color: #1e3a8a; font-size: 14px;">Appraising Received Document from {{ $prefill_source ?? 'Intake' }}</span>
                        @if($prefill_code)
                            <span style="background: #ffffff; color: #1d4ed8; font-family: ui-monospace, SFMono-Regular, monospace; font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 5px; border: 1px solid #bfdbfe;">{{ $prefill_code }}</span>
                        @endif
                    </div>
                    <div style="font-size: 12px; color: #475569; margin-top: 3px;">
                        Document fields have been pre-filled. Creating the record links this intake and marks it <strong>Appraised</strong>.
                    </div>
                </div>
            </div>
            <a href="{{ $prefill_source === 'DCS' ? route('rdp.received-documents.dcs') : route('rdp.received-documents.dts') }}" 
               style="font-size: 12px; font-weight: 600; color: #2563eb; text-decoration: none; padding: 6px 12px; border: 1px solid #bfdbfe; border-radius: 6px; background: #ffffff; white-space: nowrap;">
                &larr; Back to {{ $prefill_source === 'DCS' ? 'DCS' : 'DTS' }} Intake
            </a>
        </div>
    @endif

    <!-- Alert Messages -->
    @if ($successMessage)
        <div class="ia-alert ia-alert-success">
            <span>✅ {{ $successMessage }}</span>
            <button type="button" wire:click="clearMessages" class="ia-alert-close">✕</button>
        </div>
    @endif
    @if ($errorMessage)
        <div class="ia-alert ia-alert-error">
            <span>❌ {{ $errorMessage }}</span>
            <button type="button" wire:click="clearMessages" class="ia-alert-close">✕</button>
        </div>
    @endif

    <!-- Form Card Wrapper -->
    <div class="ia-form-card-wrapper">
        <!-- Main Form Card -->
        <div class="ia-form-card{{ $dcsOfficeLock ? ' ia-dcs-office-lock' : '' }}">
            <!-- Mode Header Bar (Always visible across all monitor resolutions) -->
            <div class="ia-mode-bar">
                <div class="ia-mode-info">
                    @if($dcsAdminForward)
                        <span class="ia-mode-pill" style="background: #2563eb; color: #ffffff;">
                            CREATE RECORD AND FORWARD
                        </span>
                        <span class="ia-mode-desc">
                            Save {{ $prefill_code ? '#' . $prefill_code : 'this document' }} to your office NAP Form 1, then forward appraisal to Distribution offices
                        </span>
                    @elseif($isAppraising)
                        <span class="ia-mode-pill" style="background: #2563eb; color: #ffffff;">
                            DOCUMENT APPRAISAL MODE
                        </span>
                        <span class="ia-mode-desc">
                            @if($dcsOfficeLock)
                                Enter Records Location for your office, then create the record
                            @else
                                Appraise incoming document {{ $prefill_code ? '#' . $prefill_code : '' }} directly into RDP
                            @endif
                        </span>
                    @elseif($entryMode === 'multi')
                        <span class="ia-mode-pill is-multi">
                            MULTI ENTRY MODE ({{ count($batchItems) }} RECORDS)
                        </span>
                        <span class="ia-mode-desc">
                            Add multiple distinct records simultaneously under the selected series
                        </span>
                    @elseif($entryMode === 'batch')
                        <span class="ia-mode-pill is-batch">
                            BATCH ENTRY MODE
                        </span>
                        <span class="ia-mode-desc">
                            Add record with combined period covered duration and volume breakdown
                        </span>
                    @else
                        <span class="ia-mode-pill is-single">
                            SINGLE ENTRY MODE
                        </span>
                        <span class="ia-mode-desc">
                            Add an individual inventory and appraisal record
                        </span>
                    @endif
                </div>
                @if(!$isAppraising && !$dcsAdminForward)
                    <div class="ia-mode-actions">

                        {{-- Entry Mode Split Dropdown Button --}}
                        <div x-data="{ modeOpen: false }" @click.outside="modeOpen = false" style="position: relative; display: inline-flex; vertical-align: middle; z-index: 50;">
                            @php
                                $isSingle = $entryMode === 'single';
                                $isBatch = $entryMode === 'batch';
                                $isMulti = $entryMode === 'multi';

                                if ($isBatch) {
                                    $btnBg = '#059669';
                                    $btnHoverBg = '#047857';
                                    $btnLabel = 'Batch Mode';
                                } elseif ($isMulti) {
                                    $btnBg = '#7c3aed';
                                    $btnHoverBg = '#6d28d9';
                                    $btnLabel = 'Multi Mode';
                                } else {
                                    $btnBg = '#2563eb';
                                    $btnHoverBg = '#1d4ed8';
                                    $btnLabel = 'Single Mode';
                                }
                            @endphp

                            <!-- Main Mode Action Button -->
                            <button type="button" 
                                    @click="modeOpen = !modeOpen"
                                    style="padding: 7px 14px; font-weight: 700; background: {{ $btnBg }}; color: #ffffff; border: none; border-top-left-radius: 6px; border-bottom-left-radius: 6px; border-top-right-radius: 0; border-bottom-right-radius: 0; cursor: pointer; font-size: 12.5px; border-right: 1px solid rgba(255,255,255,0.25); display: inline-flex; align-items: center; gap: 7px; box-shadow: 0 1px 3px rgba(0,0,0,0.12); transition: background-color 0.15s ease;"
                                    title="Current mode: {{ $btnLabel }} (Click to switch entry mode)">
                                @if($isBatch)
                                    <!-- Calendar / Timeline SVG -->
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                    </svg>
                                @elseif($isMulti)
                                    <!-- Multi-Items / Stack SVG -->
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                    </svg>
                                @else
                                    <!-- Single Document SVG -->
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <line x1="16" y1="13" x2="8" y2="13"></line>
                                        <line x1="16" y1="17" x2="8" y2="17"></line>
                                    </svg>
                                @endif
                                <span>{{ $btnLabel }}</span>
                            </button>

                            <!-- Right Chevron Toggle Button -->
                            <button type="button" 
                                    @click="modeOpen = !modeOpen"
                                    style="padding: 7px 10px; background: {{ $btnBg }}; color: #ffffff; border: none; border-top-right-radius: 6px; border-bottom-right-radius: 6px; border-top-left-radius: 0; border-bottom-left-radius: 0; cursor: pointer; font-size: 11px; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 1px 3px rgba(0,0,0,0.12); transition: background-color 0.15s ease;"
                                    title="Choose Entry Mode">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" :style="modeOpen ? 'transform: rotate(180deg);' : ''" style="transition: transform 0.15s ease;">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </button>

                            <!-- Mode Selector Dropdown Menu -->
                            <div class="ia-mode-menu" x-show="modeOpen" x-cloak>
                                <button type="button"
                                        class="ia-mode-option is-single {{ $isSingle ? 'is-active' : '' }}"
                                        wire:click="switchToSingleMode"
                                        @click="modeOpen = false">
                                    <span class="ia-mode-option-main">
                                        <span class="ia-mode-option-icon" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                <polyline points="14 2 14 8 20 8"></polyline>
                                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                            </svg>
                                        </span>
                                        <span class="ia-mode-option-copy">
                                            <span class="ia-mode-option-title">
                                                <span>Single Mode</span>
                                                @if($isSingle)
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="20 6 9 17 4 12"></polyline>
                                                    </svg>
                                                @endif
                                            </span>
                                            <span class="ia-mode-option-desc">Individual record (Default)</span>
                                        </span>
                                    </span>
                                    <span class="ia-mode-option-tag">.single</span>
                                </button>

                                <button type="button"
                                        class="ia-mode-option is-batch {{ $isBatch ? 'is-active' : '' }}"
                                        wire:click="switchToBatchMode"
                                        @click="modeOpen = false">
                                    <span class="ia-mode-option-main">
                                        <span class="ia-mode-option-icon" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                            </svg>
                                        </span>
                                        <span class="ia-mode-option-copy">
                                            <span class="ia-mode-option-title">
                                                <span>Batch Mode</span>
                                                @if($isBatch)
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="20 6 9 17 4 12"></polyline>
                                                    </svg>
                                                @endif
                                            </span>
                                            <span class="ia-mode-option-desc">Combined period & volume breakdown</span>
                                        </span>
                                    </span>
                                    <span class="ia-mode-option-tag">.batch</span>
                                </button>

                                <button type="button"
                                        class="ia-mode-option is-multi {{ $isMulti ? 'is-active' : '' }}"
                                        wire:click="switchToMultiMode"
                                        @click="modeOpen = false">
                                    <span class="ia-mode-option-main">
                                        <span class="ia-mode-option-icon" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                            </svg>
                                        </span>
                                        <span class="ia-mode-option-copy">
                                            <span class="ia-mode-option-title">
                                                <span>Multi Mode</span>
                                                @if($isMulti)
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="20 6 9 17 4 12"></polyline>
                                                    </svg>
                                                @endif
                                            </span>
                                            <span class="ia-mode-option-desc">Add multiple records under this series</span>
                                        </span>
                                    </span>
                                    <span class="ia-mode-option-tag">.multi</span>
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div style="padding: 28px 32px;">
                <!-- Record Series Title -->
                <div class="ia-form-row" wire:key="ia-row-series">
                    <span class="ia-label ia-label-required">Record Series Title</span>
                    <div style="flex: 1; display: flex; align-items: center;">
                        @if($isAppraising && $selectedSeriesTitle)
                            <div class="ia-badge ia-badge-green" style="display: flex; align-items: center; justify-content: space-between; width: 100%; gap: 12px; padding: 10px 16px;">
                                <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 6px;">
                                    @if(!empty($selectedSeriesHierarchy))
                                        @foreach($selectedSeriesHierarchy as $idx => $node)
                                            @if($idx > 0)
                                                <span style="margin: 0 4px; color: #059669; font-weight: 800;">➔</span>
                                            @endif
                                            <span style="font-weight: 800; color: #065f46;">{{ $node['title'] }}</span>
                                            @if($node['is_predefined'])
                                                <span style="font-size: 10px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px; text-transform: uppercase; display: inline-flex; align-items: center;">PREDEFINED</span>
                                            @else
                                                <span style="font-size: 10px; font-weight: 800; background: #faf5ff; color: #7e22ce; border: 1px solid #e9d5ff; padding: 2px 6px; border-radius: 4px; text-transform: uppercase; display: inline-flex; align-items: center;">USER</span>
                                            @endif
                                        @endforeach
                                    @else
                                        <span style="font-weight: 800; color: #065f46;">{{ $selectedSeriesTitle }}</span>
                                    @endif
                                </div>
                                <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; background: #fef3c7; color: #92400e; padding: 4px 10px; border-radius: 6px; border: 1px solid #fde68a; white-space: nowrap;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                    LOCKED (System Preconfigured)
                                </span>
                            </div>
                        @elseif($selectedSeriesTitle)
                            <div class="ia-badge ia-badge-green" style="display: flex; align-items: center; justify-content: space-between; width: 100%; gap: 12px; padding: 10px 16px;">
                                <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 6px;">
                                    @if(!empty($selectedSeriesHierarchy))
                                        @foreach($selectedSeriesHierarchy as $idx => $node)
                                            @if($idx > 0)
                                                <span style="margin: 0 4px; color: #059669; font-weight: 800;">➔</span>
                                            @endif
                                            <span style="font-weight: 800; color: #065f46;">{{ $node['title'] }}</span>
                                            @if($node['is_predefined'])
                                                <span style="font-size: 10px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px; text-transform: uppercase; display: inline-flex; align-items: center;">PREDEFINED</span>
                                            @else
                                                <span style="font-size: 10px; font-weight: 800; background: #faf5ff; color: #7e22ce; border: 1px solid #e9d5ff; padding: 2px 6px; border-radius: 4px; text-transform: uppercase; display: inline-flex; align-items: center;">USER</span>
                                            @endif
                                        @endforeach
                                    @else
                                        <span style="font-weight: 800; color: #065f46;">{{ $selectedSeriesTitle }}</span>
                                    @endif
                                </div>
                                <button type="button" wire:click="openSeriesModal" class="ia-btn ia-btn-change">Change Series</button>
                            </div>
                        @else
                            <button type="button" wire:click="openSeriesModal" class="ia-btn ia-btn-primary ia-btn-lg">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                                Select / Add Record Series
                            </button>
                        @endif
                    </div>
                </div>

                @if($entryMode === 'multi')
                    <!-- Multi Items List -->
                    <div class="ia-batch-list" style="display: flex; flex-direction: column; gap: 18px; margin-bottom: 26px;">
                        @foreach($batchItems as $bIdx => $bItem)
                            <div class="ia-batch-item-card" wire:key="batch-item-{{ $bIdx }}" style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 20px; transition: border-color 0.2s ease; position: relative;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span style="background: #4f46e5; color: #ffffff; font-size: 11px; font-weight: 800; padding: 3px 8px; border-radius: 6px; letter-spacing: 0.5px;">RECORD #{{ $bIdx + 1 }}</span>
                                        <span style="font-size: 13px; font-weight: 700; color: #1e293b;">
                                            {{ !empty($bItem['description']) ? Str::limit($bItem['description'], 45) : 'New Record' }}
                                        </span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <button type="button" wire:click="duplicateBatchItem({{ $bIdx }})" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 4px 10px; font-size: 11.5px; font-weight: 700; color: #475569; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" title="Duplicate this record">
                                            <span>Duplicate</span>
                                        </button>
                                        @if(count($batchItems) > 1)
                                            <button type="button" wire:click="removeBatchItem({{ $bIdx }})" style="background: #fee2e2; border: 1px solid #fca5a5; border-radius: 6px; padding: 4px 10px; font-size: 11.5px; font-weight: 700; color: #dc2626; cursor: pointer;" title="Remove this record">
                                                <span>✕ Remove</span>
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                <div style="margin-bottom: 12px;">
                                    <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Subject / Record Title *</label>
                                    <textarea class="ia-input" wire:model="batchItems.{{ $bIdx }}.description" rows="2" placeholder="ENTER RECORD DESCRIPTION OR SPECIFIC DETAILS..." style="width: 100%; box-sizing: border-box;"></textarea>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 12px; margin-bottom: 12px; position: relative; z-index: 20;">
                                    <div>
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Selected Date *</label>
                                        <input type="date" class="ia-input" wire:model.blur="batchItems.{{ $bIdx }}.date_covered" style="width: 100%; box-sizing: border-box;">
                                    </div>
                                    <div>
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Volume Amount & Unit *</label>
                                        <input type="text" class="ia-input" wire:model="batchItems.{{ $bIdx }}.volume" placeholder="E.G. 1 BOX 10 PAPERS..." style="width: 100%; box-sizing: border-box;">
                                    </div>
                                    <div style="position: relative; z-index: 25;" wire:click.outside="$set('activeBatchLocationIndex', null)">
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Records Location *</label>
                                        <input type="text"
                                            class="ia-input"
                                            wire:model.live.debounce.150ms="batchItems.{{ $bIdx }}.records_location"
                                            wire:focus="$set('activeBatchLocationIndex', {{ $bIdx }})"
                                            placeholder="E.G. CABINET 3, SHELF 2"
                                            style="width: 100%; box-sizing: border-box;">

                                        @if($activeBatchLocationIndex === $bIdx && count($this->getBatchLocationSuggestions($bIdx)) > 0)
                                            <div class="ia-autocomplete-dropdown" style="top: 100%; left: 0; right: 0; z-index: 1050; max-height: 180px; overflow-y: auto;">
                                                @foreach($this->getBatchLocationSuggestions($bIdx) as $bLocSugg)
                                                    @php
                                                        $bLocName = is_array($bLocSugg) ? ($bLocSugg['name'] ?? '') : $bLocSugg;
                                                        $bLocType = is_array($bLocSugg) ? ($bLocSugg['type'] ?? 'Office Location') : 'Office Location';
                                                    @endphp
                                                    <div wire:click="selectBatchLocationSuggestion({{ $bIdx }}, '{{ addslashes($bLocName) }}')"
                                                         class="ia-autocomplete-item"
                                                         style="display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 12px; cursor: pointer;">
                                                        <div style="display: flex; align-items: center; gap: 6px;">
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                                                <circle cx="12" cy="10" r="3"></circle>
                                                            </svg>
                                                            <span style="font-weight: 600;">{{ $bLocName }}</span>
                                                        </div>
                                                        <span style="font-size: 9.5px; font-weight: 700; color: #2563eb; background: #eff6ff; border: 1px solid #bfdbfe; padding: 2px 5px; border-radius: 4px; text-transform: uppercase;">
                                                            {{ $bLocType }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                                    <div>
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Records Medium *</label>
                                        <select class="ia-input" wire:model.live="batchItems.{{ $bIdx }}.records_medium" style="width: 100%; box-sizing: border-box; background: #ffffff;">
                                            <option value="" disabled {{ empty($bItem['records_medium']) ? 'selected' : '' }}>Select Medium...</option>
                                            @foreach($mediaList as $med)
                                                <option value="{{ $med->id }}" {{ (string)($bItem['records_medium'] ?? '') === (string)$med->id ? 'selected' : '' }}>
                                                    {{ $med->medium_name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Restriction / Access *</label>
                                        <select class="ia-input" wire:model.live="batchItems.{{ $bIdx }}.restriction" style="width: 100%; box-sizing: border-box; background: #ffffff;">
                                            <option value="" disabled {{ empty($bItem['restriction']) ? 'selected' : '' }}>Select Restriction...</option>
                                            @foreach($restrictionsList as $rest)
                                                <option value="{{ $rest->restriction_value }}" {{ ($bItem['restriction'] ?? '') === $rest->restriction_value ? 'selected' : '' }}>
                                                    {{ $rest->restriction_value }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Frequency of Use *</label>
                                        <select class="ia-input" wire:model.live="batchItems.{{ $bIdx }}.frequence_use" style="width: 100%; box-sizing: border-box; background: #ffffff;">
                                            <option value="" disabled {{ empty($bItem['frequence_use']) ? 'selected' : '' }}>Select Frequency...</option>
                                            @foreach($frequenciesList as $freq)
                                                <option value="{{ $freq->freq_type }}" {{ ($bItem['frequence_use'] ?? '') === $freq->freq_type ? 'selected' : '' }}>
                                                    {{ $freq->freq_type }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div style="margin-bottom: 12px;">
                                    <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Utility Values *</label>
                                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                        @foreach($utilityValuesList as $uv)
                                            @php
                                                $isItemChecked = in_array($uv->id, $bItem['utility_values'] ?? []);
                                            @endphp
                                            <label class="ia-chip {{ $isItemChecked ? 'ia-chip-active' : 'ia-chip-default' }}" style="padding: 4px 10px; font-size: 11px;">
                                                <input type="checkbox" wire:model.live="batchItems.{{ $bIdx }}.utility_values" value="{{ $uv->id }}">
                                                <span>{{ mb_strtoupper($uv->utility_name) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <!-- Duplicate Offices for this Batch Item -->
                                <div style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed #cbd5e1;" wire:click.outside="$set('batchItems.{{ $bIdx }}.showDuplicateDropdown', false)">
                                    <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Duplicate</label>
                                    @if(!empty($bItem['duplicate_offices']))
                                        <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px;">
                                            @foreach($bItem['duplicate_offices'] as $dIdx => $offCode)
                                                @php
                                                    $offObj = collect($officesList)->firstWhere('office_code', $offCode);
                                                    $offName = $offObj->office_name ?? $offCode;
                                                    $itemCanView = !empty($bItem['duplicate_file_view'][$offCode]);
                                                @endphp
                                                <span style="display: inline-flex; align-items: center; gap: 6px; background-color: #ffffff; color: #1e293b; border: 1.5px solid {{ $itemCanView ? '#86efac' : '#cbd5e1' }}; padding: 3px 8px; border-radius: 6px; font-size: 11.5px; font-weight: 600;">
                                                    <span><strong>{{ $offCode }}</strong> — {{ $offName }}</span>
                                                    <button type="button" 
                                                            wire:click="toggleBatchDuplicateFileView({{ $bIdx }}, '{{ $offCode }}')" 
                                                            title="{{ $itemCanView ? 'File viewing allowed for ' . $offCode . ' (Click to disable)' : 'File viewing blocked for ' . $offCode . ' (Click to allow)' }}"
                                                            style="display: inline-flex; align-items: center; gap: 3px; padding: 1px 6px; border-radius: 9999px; font-size: 10px; font-weight: 700; cursor: pointer; border: 1px solid {{ $itemCanView ? '#059669' : '#94a3b8' }}; background: {{ $itemCanView ? '#ecfdf5' : '#f8fafc' }}; color: {{ $itemCanView ? '#047857' : '#64748b' }};">
                                                        @if($itemCanView)
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                            <span>View: ON</span>
                                                        @else
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                                            <span>View: OFF</span>
                                                        @endif
                                                    </button>
                                                    <button type="button" wire:click="removeBatchDuplicateOffice({{ $bIdx }}, {{ $dIdx }})" style="border: none; background: none; color: #94a3b8; cursor: pointer; font-weight: 900; font-size: 13px; line-height: 1; padding: 0 2px;">&times;</button>
                                                </span>
                                            @endforeach
                                            <button type="button" wire:click="clearBatchDuplicateOffices({{ $bIdx }})" class="ia-btn ia-btn-secondary" style="padding: 2px 8px; font-size: 10.5px; height: 24px; align-self: center;">Clear</button>
                                        </div>
                                    @endif

                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <button type="button" 
                                                wire:click="openDuplicateModal({{ $bIdx }})" 
                                                class="ia-btn ia-btn-secondary" 
                                                style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; font-weight: 700; font-size: 11px; border-radius: 6px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #1e293b;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                            </svg>
                                            <span>{{ !empty($bItem['duplicate_offices']) ? 'Manage Duplicates (' . count($bItem['duplicate_offices']) . ')' : '+ Select Duplicate Offices' }}</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        <button type="button" wire:click="addBatchItem" style="border: 2px dashed #818cf8; background: #f5f3ff; color: #4338ca; border-radius: 12px; padding: 14px; font-weight: 800; font-size: 13px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 8px;">
                            <span>+ ADD ANOTHER RECORD ROW</span>
                        </button>
                    </div>
                @else
                    <!-- Description -->
                    <div class="ia-form-row" wire:key="ia-row-desc" style="align-items: flex-start;">
                        <div class="ia-label-stack" style="margin-top: 6px;">
                            <span class="{{ $isAppraising ? '' : 'ia-label-required' }}">Description</span>
                            @if($isAppraising)
                                <span class="ia-locked-badge">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                    @php
                                        $descLockSource = ($dcsOfficeLock || $dcsAdminForward || strtoupper((string) ($prefill_source ?? '')) === 'DCS')
                                            ? 'DCS'
                                            : 'DTS';
                                    @endphp
                                    LOCKED (From {{ $descLockSource }})
                                </span>
                            @endif
                        </div>
                        @if($isAppraising)
                            <textarea class="ia-input" wire:model="description" rows="3" readonly style="flex: 1; background: #f8fafc; cursor: not-allowed; color: #334155; font-weight: 500; font-family: inherit; border: 1px solid #cbd5e1;"></textarea>
                        @else
                            <textarea class="ia-input" wire:model.blur="description" rows="3" placeholder="ENTER RECORD DESCRIPTION OR SPECIFIC DETAILS..." style="flex: 1; font-family: inherit; {{ ($showValidationErrors && empty(trim($description))) ? 'border-color: #fca5a5; background: #fff5f5;' : '' }}"></textarea>
                        @endif
                    </div>

                    @if($entryMode === 'batch')
                        <!-- ═══════ COMBINED PERIOD COVERED & VOLUME (BATCH MODE) ═══════ -->
                        <div class="ia-form-row ia-batch-period-row" wire:key="ia-row-batch-period-volume" style="align-items: flex-start;">
                            <div class="ia-label-stack" style="margin-top: 6px;">
                                <span class="ia-label-required">Period Covered & Volume</span>
                                <span style="font-size: 11px; color: #64748b; font-weight: 600;">Combined Range & Breakdown</span>
                            </div>

                            <div style="flex: 1; display: flex; flex-direction: column; gap: 10px;">
                                @if(empty($batch_start_date) || empty($batch_end_date))
                                    <!-- Empty State: Modal Launch Button -->
                                    <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap; background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 10px; padding: 14px 18px;">
                                        <button type="button" wire:click="openBatchModal" class="ia-btn ia-btn-primary ia-btn-lg" style="font-size: 13px;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 5px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                            Configure Period Covered & Volume
                                        </button>
                                        <span style="font-size: 12px; color: #64748b; font-weight: 500;">
                                            Set inclusive dates, volume unit, and yearly breakdown in a modal.
                                        </span>
                                    </div>
                                @else
                                    <!-- Configured State Header with Edit in Modal Button -->
                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <span style="font-size: 12px; color: #64748b; font-weight: 600;">
                                                Duration Breakdown ({{ count($batch_sub_periods) }} {{ \Illuminate\Support\Str::plural('period', count($batch_sub_periods)) }}):
                                            </span>
                                            <div style="display: inline-flex; align-items: center; gap: 6px;">
                                                <input type="text" 
                                                       class="ia-input ia-input-sm" 
                                                       wire:model.live.debounce.300ms="batch_default_volume" 
                                                       placeholder="Default vol e.g. 1 box 10 papers" 
                                                       style="max-width: 200px; height: 28px; font-size: 11.5px;">
                                                <button type="button" 
                                                        wire:click="applyBatchDefaultVolume" 
                                                        class="ia-btn ia-btn-secondary" 
                                                        style="height: 28px; font-size: 11px; padding: 0 8px;" 
                                                        title="Set this volume for all breakdown periods">
                                                    Apply All
                                                </button>
                                            </div>
                                        </div>
                                        <button type="button" wire:click="openBatchModal" class="ia-btn ia-btn-change" style="font-size: 11.5px; padding: 4px 12px; display: inline-flex; align-items: center; gap: 5px;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                            Edit in Modal
                                        </button>
                                    </div>

                                    <!-- Master / Dropdown Widget -->
                                    <div class="ia-batch-accordion-card">
                                    <!-- Parent Header Row: [Arrow Toggle] | <Start period date> - <End period date> | <total volume> -->
                                    <div class="ia-batch-header-row" wire:click="toggleBatchDropdown">
                                        <div class="ia-batch-header-left">
                                            <button type="button" 
                                                    class="ia-batch-toggle-arrow-btn {{ $batch_dropdown_expanded ? 'is-expanded' : 'is-collapsed' }}" 
                                                    title="{{ $batch_dropdown_expanded ? 'Collapse duration breakdown (Down Arrow)' : 'Expand duration breakdown (Side Arrow)' }}">
                                                @if($batch_dropdown_expanded)
                                                    <!-- Down Arrow (Expanded) -->
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="6 9 12 15 18 9"></polyline>
                                                    </svg>
                                                @else
                                                    <!-- Side Arrow (Collapsed) -->
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="9 18 15 12 9 6"></polyline>
                                                    </svg>
                                                @endif
                                            </button>
                                            <div class="ia-batch-header-period">
                                                @if(!empty($batch_start_date) && !empty($batch_end_date))
                                                    <span class="ia-batch-range-text">
                                                        {{ \Carbon\Carbon::parse($batch_start_date)->translatedFormat('j F Y') }} — {{ \Carbon\Carbon::parse($batch_end_date)->translatedFormat('j F Y') }}
                                                    </span>
                                                @elseif(!empty($batch_start_date))
                                                    <span class="ia-batch-range-text">
                                                        From {{ \Carbon\Carbon::parse($batch_start_date)->translatedFormat('j F Y') }}
                                                    </span>
                                                @else
                                                    <span class="ia-batch-range-placeholder">
                                                        Enter Start and End Period Dates above to generate duration breakdown
                                                    </span>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="ia-batch-header-right">
                                            <div class="ia-batch-total-volume-pill">
                                                <span class="ia-sum-symbol">∑</span>
                                                <span class="ia-sum-label">TOTAL VOLUME:</span>
                                                <span class="ia-sum-value">
                                                    {{ $this->getBatchTotalVolumeFormatted() ?: '0 Papers' }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Sub-periods List (Tree Breakdown: Branch Connector <period date> - <end of period date> | <volume>) -->
                                    @if($batch_dropdown_expanded)
                                        <div class="ia-batch-children-container">
                                            @if(!empty($batch_sub_periods))
                                                @foreach($batch_sub_periods as $pIdx => $sub)
                                                    <div class="ia-batch-tree-item" wire:key="batch-sub-period-{{ $pIdx }}-{{ $sub['id'] ?? $pIdx }}">
                                                        <!-- Top Row: Sub-period Title / Subject Input (<subject> <year>) -->
                                                        <div class="ia-sub-desc-row">
                                                            <input type="text" 
                                                                   class="ia-input ia-input-sm ia-sub-desc-input" 
                                                                   wire:model.live.debounce.300ms="batch_sub_periods.{{ $pIdx }}.description" 
                                                                   placeholder="e.g. {{ $this->formatSubPeriodDefaultDescription($sub['start_date'] ?? '', $sub['end_date'] ?? '') ?: 'Subject Year' }}" 
                                                                   title="Subject / Title for this period (Auto-fills with Subject + Year)">
                                                        </div>

                                                        <!-- Bottom Row: Connector + Date Range + Volume + Remove -->
                                                        <div class="ia-sub-bottom-row">
                                                            <!-- Tree branch connector -->
                                                            <div class="ia-batch-tree-connector" aria-hidden="true" title="Yearly breakdown branch">
                                                                <svg width="22" height="24" viewBox="0 0 22 24" fill="none" class="ia-tree-branch-svg">
                                                                    <path d="M 7 0 V 13 H 20" stroke="#6366f1" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                                                                </svg>
                                                            </div>

                                                            <!-- Date Range Calendar Inputs: <start date> to <end date> -->
                                                            <div class="ia-batch-tree-date">
                                                                <div class="ia-sub-calendar-group">
                                                                    <input type="date" 
                                                                           class="ia-input ia-input-sm ia-sub-calendar-input" 
                                                                           wire:model.live="batch_sub_periods.{{ $pIdx }}.start_date" 
                                                                           title="Start Date">
                                                                    <span class="ia-sub-dmy-to">to</span>
                                                                    <input type="date" 
                                                                           class="ia-input ia-input-sm ia-sub-calendar-input" 
                                                                           wire:model.live="batch_sub_periods.{{ $pIdx }}.end_date" 
                                                                           title="End Date">
                                                                </div>
                                                            </div>

                                                            <!-- Sub Volume Input -->
                                                            <div class="ia-batch-tree-volume">
                                                                <div class="ia-sub-vol-input-group">
                                                                    <input type="text" 
                                                                           class="ia-input ia-input-sm ia-sub-vol-text" 
                                                                           wire:model.live.debounce.300ms="batch_sub_periods.{{ $pIdx }}.volume" 
                                                                           placeholder="e.g. 10 Boxes 2 Papers"
                                                                           style="{{ ($showValidationErrors && (empty($sub['volume']) || !preg_match('/\d/', $sub['volume']))) ? 'border-color: #fca5a5; background: #fff5f5;' : '' }}"
                                                                           title="Volume for this period (Required, e.g. 1 box 10 papers)">
                                                                </div>
                                                                <button type="button" 
                                                                        wire:click="removeBatchSubPeriod({{ $pIdx }})" 
                                                                        class="ia-btn-remove-sub" 
                                                                        title="Remove this period range">
                                                                    &times;
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            @else
                                                <div style="padding: 18px; text-align: center; color: #64748b; font-size: 12.5px; font-style: italic;">
                                                    No period ranges generated yet. Set Start and End dates above or click "+ Add Period Range".
                                                </div>
                                            @endif

                                            <div class="ia-batch-children-footer">
                                                <button type="button" wire:click="addBatchSubPeriod" class="ia-btn ia-btn-ghost ia-btn-sm" style="border-style: dashed; font-size: 12px;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                                    Add Period Range
                                                </button>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                    @else
                        <!-- Selected Date -->
                        <div class="ia-form-row" wire:key="ia-row-date">
                            <div class="ia-label-stack">
                                <span class="ia-label ia-label-required">Period Covered / Date Covered</span>
                                @if($dcsOfficeLock)
                                    <span class="ia-locked-badge">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        LOCKED (From Admin Appraisal)
                                    </span>
                                @endif
                            </div>
                            <div style="flex: 1; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                @if($dcsOfficeLock)
                                    <input type="date" class="ia-input" value="{{ $date_covered }}" disabled readonly style="max-width: 240px; background: #f8fafc; color: #64748b; cursor: not-allowed;">
                                    @if(!empty($date_covered))
                                        <span style="font-size: 12px; color: #64748b; font-weight: 600;">
                                            {{ \App\Helpers\RegisterQueryHelper::formatRdpPeriodDate($date_covered, '') }}
                                        </span>
                                    @endif
                                @else
                                    <input type="date" class="ia-input" wire:model.live="date_covered" style="max-width: 240px; {{ ($showValidationErrors && empty(trim($date_covered))) ? 'border-color: #fca5a5; background: #fff5f5;' : '' }}">
                                    @if(!empty($date_covered))
                                        <button type="button" wire:click="$set('date_covered', '')" class="ia-btn ia-btn-secondary" style="padding: 6px 14px; font-size: 12px;">Clear Date</button>
                                        <span style="font-size: 12px; color: #64748b; font-weight: 600;">
                                            {{ \App\Helpers\RegisterQueryHelper::formatRdpPeriodDate($date_covered, '') }}
                                        </span>
                                    @endif
                                @endif
                            </div>
                        </div>

                        <!-- Volume Amount & Unit -->
                        <div class="ia-form-row" wire:key="ia-row-volume">
                            <span class="ia-label ia-label-required">Volume Amount & Unit</span>
                            <div style="flex: 1; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <input type="text" 
                                       class="ia-input" 
                                       wire:model.live.debounce.300ms="volume" 
                                       placeholder="e.g. 1 box 10 papers, 2 boxes, 1 folder" 
                                       style="flex: 1; max-width: 420px; {{ ($showValidationErrors && (empty($volume) || !preg_match('/\d/', $volume))) ? 'border-color: #fca5a5; background: #fff5f5;' : '' }}">
                                @if(!empty($volume) && preg_match('/\d/', $volume))
                                    <span style="font-size: 12px; color: #16a34a; font-weight: 700; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 4px 10px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        Volume: {{ mb_strtoupper($volume) }}
                                    </span>
                                @elseif($showValidationErrors)
                                    <span style="font-size: 11.5px; color: #dc2626; font-style: italic;">
                                        * Required (e.g. 1 box 10 papers)
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endif

                    <!-- Records Medium -->
                    <div class="ia-form-row" wire:key="ia-row-medium">
                        <div class="ia-label-stack">
                            <span class="ia-label-required">Records Medium</span>
                            @if($isAppraising)
                                <span class="ia-locked-badge">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                    LOCKED (Default)
                                </span>
                            @endif
                        </div>
                        @if($isAppraising)
                            <select class="ia-input ia-input-disabled" disabled style="cursor: not-allowed; background: #f8fafc; color: #334155; border: 1px solid #cbd5e1; font-weight: 600;">
                                @foreach($mediaList as $med)
                                    <option value="{{ $med->id }}" {{ (string)$records_medium === (string)$med->id ? 'selected' : '' }}>
                                        {{ $med->medium_name }} ({{ $med->description }})
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <select class="ia-input" wire:model.live="records_medium">
                                <option value="" {{ $records_medium === null ? 'selected' : '' }}>Select Medium...</option>
                                @foreach($mediaList as $med)
                                    <option value="{{ $med->id }}" {{ (string)$records_medium === (string)$med->id ? 'selected' : '' }}>
                                        {{ $med->medium_name }} ({{ $med->description }})
                                    </option>
                                @endforeach
                            </select>
                        @endif
                    </div>

                    <!-- Restriction -->
                    <div class="ia-form-row" wire:key="ia-row-restriction">
                        <div class="ia-label-stack">
                            <span class="ia-label-required">Restriction / Access</span>
                            @if($isAppraising)
                                <span class="ia-locked-badge">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                    LOCKED (Default)
                                </span>
                            @endif
                        </div>
                        @if($isAppraising)
                            <select class="ia-input ia-input-disabled" disabled style="cursor: not-allowed; background: #f8fafc; color: #334155; border: 1px solid #cbd5e1; font-weight: 600;">
                                @foreach($restrictionsList as $rest)
                                    <option value="{{ $rest->restriction_value }}" {{ $restriction === $rest->restriction_value ? 'selected' : '' }}>
                                        {{ $rest->restriction_value }}
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <select class="ia-input" wire:model.live="restriction">
                                <option value="" disabled {{ empty($restriction) ? 'selected' : '' }}>Select Restriction Type...</option>
                                @foreach($restrictionsList as $rest)
                                    <option value="{{ $rest->restriction_value }}" {{ $restriction === $rest->restriction_value ? 'selected' : '' }}>
                                        {{ $rest->restriction_value }}
                                    </option>
                                @endforeach
                            </select>
                        @endif
                    </div>

                    <!-- Records Location -->
                    <div class="ia-form-row ia-location-row" wire:key="ia-row-location" style="position: relative; z-index: 25;">
                        <span class="ia-label ia-label-required">Records Location</span>
                        <div style="position: relative; flex: 1;">
                            @if($dcsOfficeBlocked || $dcsOfficeAlreadyDone)
                                <input type="text" class="ia-input" value="{{ $dcsOfficeAlreadyDone ? $records_location : '' }}" disabled readonly placeholder="{{ $dcsOfficeAlreadyDone ? 'Record already created' : 'Mark the document received first' }}" style="width: 100%; background: #f8fafc; color: #64748b; cursor: not-allowed;">
                            @else
                        <div style="position: relative; flex: 1;"
                             x-data="{
                                open: false,
                                locationValue: @entangle('records_location'),
                                allLocs: {{ json_encode($this->getLocationSuggestionsWithTypes()) }},
                                get suggestions() {
                                    if (!this.locationValue || !this.locationValue.trim()) return this.allLocs.slice(0, 10);
                                    const q = this.locationValue.toLowerCase().trim();
                                    return this.allLocs.filter(l => l.name.toLowerCase().includes(q)).slice(0, 10);
                                },
                                selectLoc(val) {
                                    this.locationValue = val;
                                    this.open = false;
                                }
                             }"
                             @click.outside="open = false">
                            <input type="text"
                                class="ia-input"
                                x-model="locationValue"
                                @focus="open = true"
                                placeholder="E.G. BUILDING A, CABINET 3, SHELF 2"
                                style="width: 100%; {{ ($showValidationErrors && empty(trim($records_location))) ? 'border-color: #fca5a5; background: #fff5f5;' : '' }}">
                            @if($dcsAdminForward)
                                <div style="font-size: 11.5px; color: #64748b; margin-top: 6px;">Saved as your office inventory on NAP Form 1. Other offices enter their own location after receive.</div>
                            @endif

                            @php
                                $topSuggestions = array_slice($this->getLocationSuggestionsWithTypes(), 0, 6);
                            @endphp
                            @if(!empty($topSuggestions))
                                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 6px;">
                                    <span style="font-size: 11px; font-weight: 700; color: #64748b;">Suggested Locations:</span>
                                    @foreach($topSuggestions as $sPill)
                                        <button type="button" 
                                                @click="selectLoc('{{ addslashes($sPill['name']) }}')"
                                                style="background: #f8fafc; border: 1px solid #cbd5e1; color: #1e293b; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all 0.15s;"
                                                title="{{ $sPill['type'] }}{{ $sPill['is_series_match'] ? ' (Series Match)' : '' }}">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                            <span>{{ $sPill['name'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                            <div x-show="open && suggestions.length > 0" 
                                 x-cloak
                                 class="ia-autocomplete-dropdown"
                                 style="top: 100%; left: 0; right: 0; z-index: 1050; max-height: 220px; overflow-y: auto;">
                                <template x-for="locSugg in suggestions" :key="locSugg.name">
                                    <div @click="selectLoc(locSugg.name)"
                                         class="ia-autocomplete-item"
                                         style="display: flex; align-items: center; justify-content: space-between; gap: 8px; cursor: pointer; padding: 8px 12px;">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                                <circle cx="12" cy="10" r="3"></circle>
                                            </svg>
                                            <span style="font-weight: 600;" x-text="locSugg.name"></span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <span style="font-size: 10px; font-weight: 700; color: #2563eb; background: #eff6ff; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;"
                                                  x-text="locSugg.type">
                                            </span>
                                            <template x-if="locSugg.is_series_match">
                                                <span style="font-size: 9.5px; font-weight: 800; color: #059669; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 2px 5px; border-radius: 4px; text-transform: uppercase;">
                                                    Series
                                                </span>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                            @endif
                        </div>
                    </div>

                    <!-- Frequency of Use -->
                    <div class="ia-form-row" wire:key="ia-row-frequency">
                        <div class="ia-label-stack">
                            <span class="ia-label-required">Frequency of Use</span>
                            @if($isAppraising)
                                <span class="ia-locked-badge">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                    LOCKED (Annually)
                                </span>
                            @endif
                        </div>
                        @if($isAppraising)
                            <select class="ia-input ia-input-disabled" disabled style="cursor: not-allowed; background: #f8fafc; color: #334155; border: 1px solid #cbd5e1; font-weight: 600;">
                                @foreach($frequenciesList as $freq)
                                    <option value="{{ $freq->freq_type }}" {{ ($frequence_use ?: 'Annually') === $freq->freq_type ? 'selected' : '' }}>
                                        {{ $freq->freq_type }}
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <select class="ia-input" wire:model.live="frequence_use">
                                <option value="" disabled {{ empty($frequence_use) ? 'selected' : '' }}>Select Frequency...</option>
                                @foreach($frequenciesList as $freq)
                                    <option value="{{ $freq->freq_type }}" {{ $frequence_use === $freq->freq_type ? 'selected' : '' }}>
                                        {{ $freq->freq_type }}
                                    </option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                @endif

            @if(!$isBatchMode)
            <!-- Duplicate -->
            <div class="ia-form-row" wire:key="ia-row-duplicate" style="align-items: flex-start;">
                <div class="ia-label-stack" style="margin-top: 6px;">
                    <span>Duplicate</span>
                    @if($dcsAdminForward || $dcsOfficeLock || $isAppraising)
                        <span class="ia-locked-badge">
                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            @if($dcsAdminForward || $dcsOfficeLock)
                                LOCKED (From Distribution)
                            @elseif(strtoupper((string) ($prefill_source ?? '')) === 'DCS')
                                LOCKED (From DCS)
                            @else
                                LOCKED (From DTS)
                            @endif
                        </span>
                    @endif
                </div>
                @if($dcsAdminForward || $dcsOfficeLock || $isAppraising)
                    <div style="flex: 1;">
                        @if(count($duplicate_offices) > 0)
                            @php
                                $viewerOffice = strtoupper(trim((string) ($userOfficeCode ?? '')));
                                $viewerInDuplicate = $viewerOffice !== '' && collect($duplicate_offices)->contains(
                                    fn ($c) => strcasecmp(trim((string) $c), $viewerOffice) === 0
                                );
                            @endphp
                            <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 4px;">
                                @foreach($duplicate_offices as $offCode)
                                    @php
                                        $offObj = collect($officesList)->firstWhere('office_code', $offCode);
                                        $offName = $offObj->office_name ?? $offCode;
                                        $isViewerOffice = $viewerOffice !== '' && strcasecmp(trim((string) $offCode), $viewerOffice) === 0;
                                    @endphp
                                    <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; {{ $isViewerOffice ? 'background-color: #ecfdf5; color: #047857; border: 1px solid #6ee7b7;' : 'background-color: #f1f5f9; color: #334155; border: 1px solid #cbd5e1;' }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        <span><strong>{{ $offCode }}</strong> — {{ $offName }}</span>
                                    </span>
                                @endforeach
                            </div>
                            <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">
                                @if($dcsAdminForward || $dcsOfficeLock)
                                    Taken from Distribution. Offices cannot be added or removed here.
                                    @if($dcsOfficeLock && $viewerInDuplicate)
                                        <span style="display: block; margin-top: 4px; color: #047857; font-weight: 600;">Your office is on this Duplicate list — enter your Records Location below to create your own NAP Form 1 row.</span>
                                    @elseif($dcsOfficeLock && ! $viewerInDuplicate)
                                        <span style="display: block; margin-top: 4px; color: #b45309; font-weight: 600;">Your office is not listed under Duplicate for this document.</span>
                                    @endif
                                @else
                                    Assigned from Copy Furnished offices in the DTS transaction.
                                @endif
                            </div>
                        @else
                            <span style="color: #64748b; font-size: 12px; font-style: italic;">No duplicate copies assigned.</span>
                        @endif
                    </div>
                @else
                    <div style="flex: 1; position: relative;" wire:click.outside="$set('showDuplicateDropdown', false)">
                        <!-- Spread to Duplicate Offices Toggle -->
                        <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 9px 12px; margin-bottom: 10px;">
                            <div style="display: flex; flex-direction: column;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 12.5px; font-weight: 700; color: #1e293b;">Spread to Duplicate Offices (Office Collective)</span>
                                    <span style="font-size: 10.5px; font-weight: 800; padding: 2px 7px; border-radius: 9999px; text-transform: uppercase; {{ $spreadToDuplicateOffices ? 'background: #dcfce7; color: #15803d; border: 1px solid #86efac;' : 'background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1;' }}">
                                        {{ $spreadToDuplicateOffices ? 'Spread: ON' : 'Spread: OFF' }}
                                    </span>
                                </div>
                                <span style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                    When enabled, creating this record will also spread and dispatch incoming Record Requests to each duplicate office.
                                </span>
                            </div>
                            <label style="position: relative; display: inline-block; width: 42px; height: 22px; margin: 0; cursor: pointer; flex-shrink: 0;">
                                <input type="checkbox" wire:model.live="spreadToDuplicateOffices" style="opacity: 0; width: 0; height: 0;">
                                <span style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: {{ $spreadToDuplicateOffices ? '#10b981' : '#cbd5e1' }}; transition: .25s; border-radius: 22px;"></span>
                                <span style="position: absolute; content: ''; height: 16px; width: 16px; left: {{ $spreadToDuplicateOffices ? '22px' : '4px' }}; bottom: 3px; background-color: white; transition: .25s; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.25);"></span>
                            </label>
                        </div>

                        @if(count($duplicate_offices) > 0)
                            <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px;">
                                @foreach($duplicate_offices as $index => $offCode)
                                    @php
                                        $offObj = collect($officesList)->firstWhere('office_code', $offCode);
                                        $offName = $offObj->office_name ?? $offCode;
                                        $canView = !empty($duplicate_file_view[$offCode]);
                                    @endphp
                                    <div style="display: inline-flex; align-items: center; gap: 8px; background-color: #ffffff; color: #1e293b; border: 1.5px solid {{ $canView ? '#86efac' : '#cbd5e1' }}; padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">
                                        <span><strong>{{ $offCode }}</strong> — {{ $offName }}</span>
                                        
                                        <!-- Per-office File Viewing Toggle (Off by default) -->
                                        <button type="button" 
                                                wire:click="toggleDuplicateFileView('{{ $offCode }}')" 
                                                title="{{ $canView ? 'File viewing allowed for ' . $offCode . ' (Click to disable)' : 'File viewing blocked for ' . $offCode . ' (Click to allow)' }}"
                                                style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; border-radius: 9999px; font-size: 10.5px; font-weight: 700; cursor: pointer; border: 1px solid {{ $canView ? '#059669' : '#94a3b8' }}; background: {{ $canView ? '#ecfdf5' : '#f8fafc' }}; color: {{ $canView ? '#047857' : '#64748b' }}; transition: all 0.15s ease;">
                                            @if($canView)
                                                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                <span>View File: ON</span>
                                            @else
                                                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                                <span>View File: OFF</span>
                                            @endif
                                        </button>

                                        <button type="button" wire:click="removeDuplicateOffice({{ $index }})" style="border: none; background: none; color: #94a3b8; cursor: pointer; font-weight: 900; font-size: 15px; line-height: 1; padding: 0 2px;" title="Remove office">&times;</button>
                                    </div>
                                @endforeach
                                <button type="button" wire:click="clearDuplicateOffices" class="ia-btn ia-btn-secondary" style="padding: 2px 10px; font-size: 11px; height: 26px; align-self: center;">Clear All</button>
                            </div>
                        @endif

                        <div style="display: flex; align-items: center; gap: 8px;">
                            <button type="button" 
                                    wire:click="openDuplicateModal" 
                                    class="ia-btn ia-btn-secondary" 
                                    style="display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; font-weight: 700; font-size: 12.5px; border-radius: 8px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #1e293b; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                                <span>{{ count($duplicate_offices) > 0 ? "Manage Duplicate Offices (" . count($duplicate_offices) . ")" : "+ Select Duplicate Offices" }}</span>
                            </button>
                        </div>
                        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">
                            Selected duplicate offices will have visibility to this record. Click the button above to add or configure offices and file viewing access.
                        </div>
                    </div>
                @endif
            </div>
            @endif

            <!-- Time Value -->
            <div class="ia-form-row" wire:key="ia-row-time">
                <span class="ia-label">Time Value</span>
                <div style="flex: 1; display: flex; align-items: center; gap: 8px;">
                    <select class="ia-input ia-input-disabled" disabled style="cursor: not-allowed; font-weight: 700;">
                        @foreach($timeValuesList as $tv)
                            <option value="{{ $tv->char_value }}" {{ $time_value === $tv->char_value ? 'selected' : '' }}>
                                {{ $tv->char_value }} — {{ mb_strtoupper($tv->description) }}
                            </option>
                        @endforeach
                    </select>
                    <span style="font-size: 12px; color: var(--ia-slate-500); font-weight: 500;">(System auto-set)</span>
                </div>
            </div>

            <!-- Utility Value (Multi-Choice Pills) -->
            @if(!$isBatchMode)
                <div class="ia-form-row" wire:key="ia-row-utility" style="align-items: flex-start;">
                    <div class="ia-label-stack" style="margin-top: 8px;">
                        <span class="ia-label ia-label-required">Utility Value</span>
                        @if($dcsOfficeLock)
                            <span class="ia-locked-badge">
                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                LOCKED (From Admin Appraisal)
                            </span>
                        @endif
                    </div>
                    <div style="flex: 1; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                        @foreach($utilityValuesList as $uv)
                            @php
                                $isChecked = in_array((int) $uv->id, array_map('intval', $utility_values), true);
                                $chipClass = $isChecked ? 'ia-chip-active' : 'ia-chip-default';
                            @endphp
                            <label class="ia-chip {{ $chipClass }}">
                                <input type="checkbox"
                                       wire:model.live="utility_values"
                                       value="{{ $uv->id }}"
                                       @if($dcsOfficeLock) disabled @endif>
                                <span>{{ mb_strtoupper($uv->utility_name) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Permanent Record Toggle -->
            <div class="ia-form-row" wire:key="ia-row-permanent">
                <span class="ia-label">
                    Permanent Record
                    @if($hasPredefinedRetention)
                        <span style="font-size: 11px; color: #64748b; font-weight: 500;">(Predefined — Read Only)</span>
                    @endif
                </span>
                <div style="flex: 1; display: flex; align-items: center; gap: 8px;">
                    <label class="ia-permanent-label">
                        <input type="checkbox" wire:model.live="is_permanent" {{ $hasPredefinedRetention ? 'disabled' : '' }}>
                        Permanent Record Series
                    </label>
                    <span class="ia-permanent-hint">(Disables period inputs and sets retention to Permanent)</span>
                </div>
            </div>

            <!-- Active Period -->
            <div class="ia-form-row {{ ($is_permanent || $hasPredefinedRetention) ? 'ia-row-disabled' : '' }}" wire:key="ia-row-active-period">
                <span class="ia-label">
                    Active Period
                    @if($hasPredefinedRetention)
                        <span style="font-size: 11px; color: #64748b; font-weight: 500;">(Predefined — Read Only)</span>
                    @endif
                </span>
                <input type="text" class="ia-input" wire:model.live.debounce.500ms="active_period" placeholder="{{ ($hasPredefinedRemarks && empty($active_period) && empty($storage_period)) ? 'Condition in Remarks' : 'E.G. 6 MONTHS, 1 YEAR' }}" {{ ($is_permanent || $hasPredefinedRetention) ? 'disabled' : '' }}>
            </div>

            <!-- Storage Period -->
            <div class="ia-form-row {{ ($is_permanent || $hasPredefinedRetention) ? 'ia-row-disabled' : '' }}" wire:key="ia-row-storage-period">
                <span class="ia-label">
                    Storage Period
                    @if($hasPredefinedRetention)
                        <span style="font-size: 11px; color: #64748b; font-weight: 500;">(Predefined — Read Only)</span>
                    @endif
                </span>
                <input type="text" class="ia-input" wire:model.live.debounce.500ms="storage_period" placeholder="{{ ($hasPredefinedRemarks && empty($active_period) && empty($storage_period)) ? 'Condition in Remarks' : 'E.G. 1 YEAR, 4 YEARS' }}" {{ ($is_permanent || $hasPredefinedRetention) ? 'disabled' : '' }}>
            </div>

            <!-- Total Period (Computed) -->
            <div class="ia-form-row {{ ($is_permanent || $hasPredefinedRetention) ? 'ia-row-disabled' : '' }}" wire:key="ia-row-total-period">
                <span class="ia-label">Total Period</span>
                <div class="ia-computed-box">
                    {{ $this->computeTotalPeriod($active_period, $storage_period, $is_permanent) ?: (($hasPredefinedRemarks && empty($active_period) && empty($storage_period)) ? 'SEE REMARKS' : '— (Auto-calculated)') }}
                </div>
            </div>

            <!-- Remarks -->
            <div class="ia-form-row" wire:key="ia-row-remarks" style="align-items: flex-start;">
                <span class="ia-label" style="margin-top: 10px;">
                    Remarks
                    @if($hasPredefinedRemarks)
                        <span style="font-size: 11px; color: #64748b; font-weight: 500; display: block;">(Predefined — Read Only)</span>
                    @endif
                </span>
                <textarea class="ia-input" wire:model="disposition_provision" rows="3" placeholder="Enter remarks or disposition instructions..." style="font-family: inherit; text-transform: none !important; {{ $dcsOfficeLock ? 'background: #f8fafc; color: #64748b; cursor: not-allowed;' : '' }}" {{ ($hasPredefinedRemarks || $dcsOfficeLock) ? 'disabled' : '' }}></textarea>
            </div>
        </div>

        <!-- ═══════ ACTIONS BAR ═══════ -->
        <div class="ia-actions-bar">
            @if(!$dcsAdminForward && !$dcsOfficeLock)
            @if($uploadedFile)
                <div class="ia-file-badge">
                    <span>📎 {{ $uploadedFile->getClientOriginalName() }}</span>
                    <button type="button" wire:click="$set('uploadedFile', null)" class="ia-file-remove">✕</button>
                </div>
            @else
                <label class="ia-btn ia-btn-secondary" style="cursor: pointer; margin: 0;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    UPLOAD FILE
                    <input type="file" wire:model.live="uploadedFile" style="display: none;">
                </label>
            @endif
            @endif

            @if($dcsAdminForward)
            @if($dcsAdminAlreadySent)
            <span style="font-size: 13px; color: #334155; font-weight: 600;">Already forwarded. Each office creates its record once.</span>
            @else
            <button type="button" wire:click="forwardToOffices" onclick="rdpScrollToTop()" class="ia-btn ia-btn-primary ia-btn-lg" title="Save your office inventory on NAP Form 1, then forward appraisal to the distributed offices.">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                Create Record and Forward
            </button>
            @endif
            @elseif($dcsOfficeAlreadyDone)
            <span style="font-size: 13px; color: #334155; font-weight: 600;">This record is already on NAP Form 1.</span>
            @elseif($dcsOfficeLock && !$dcsOfficeBlocked)
            <button type="button" wire:click="createRecord" onclick="rdpScrollToTop()" class="ia-btn ia-btn-primary ia-btn-lg">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                CREATE RECORD
            </button>
            @elseif($dcsOfficeLock)
            @else
            <button type="button" wire:click="resetFormFields" onclick="rdpScrollToTop()" class="ia-btn ia-btn-secondary">CLEAR FORM</button>
            <button type="button" wire:click="openVerificationModal" class="ia-btn ia-btn-primary ia-btn-lg">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                @if(count($collectiveOffices) > 0)
                    {{ $entryMode === 'multi' && count($batchItems) > 1 ? 'CREATE ' . count($batchItems) . ' RECORDS & SPREAD TO ' . count($collectiveOffices) . ' ' . \Illuminate\Support\Str::plural('OFFICE', count($collectiveOffices)) : 'CREATE RECORD & SPREAD TO ' . count($collectiveOffices) . ' ' . \Illuminate\Support\Str::plural('OFFICE', count($collectiveOffices)) }}
                @else
                    {{ $entryMode === 'multi' && count($batchItems) > 1 ? 'CREATE ' . count($batchItems) . ' RECORDS' : 'CREATE RECORD' }}
                @endif
            </button>
            @endif
        </div>
    </div>
    </div> <!-- /.ia-form-card-wrapper -->

    <!-- ═══════ Series Selection Modal ═══════ -->
    @if($showSeriesModal)
        <div class="ia-modal-overlay">
            <div class="ia-modal-card">
                <div class="ia-modal-header">
                    <h3>Configure Record Series</h3>
                    <button wire:click="closeSeriesModal" class="ia-modal-close">&times;</button>
                </div>
                <div class="ia-modal-body">
                    @if($seriesModalError)
                        <div class="ia-series-error-alert" style="background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 10px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                                <span>{{ $seriesModalError }}</span>
                            </div>
                            <button type="button" wire:click="$set('seriesModalError', null)" style="border: none; background: none; color: inherit; font-size: 18px; cursor: pointer; line-height: 1; padding: 0 4px;">&times;</button>
                        </div>
                    @endif

                    <!-- Record Series Type Dropdown -->
                    <div style="margin-bottom: 18px;">
                        <label class="ia-modal-label" style="display: block; margin-bottom: 8px;">Select Record Series Type:</label>
                        <select class="ia-input" wire:model.live="selectedSeriesTypeFilter" style="width: 100%;">
                            <option value="">All Types</option>
                            @foreach($recordSeriesTypes as $typeItem)
                                <option value="{{ $typeItem->id }}">
                                    {{ $typeItem->shorted_type }} ({{ $typeItem->type_name }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div style="margin-bottom: 16px; position: relative; z-index: 40;" wire:click.outside="$set('showParentDropdown', false)">
                        <label class="ia-modal-label">Parent Record Series Title *:</label>
                        <input type="text" class="ia-input" wire:model.live.debounce.150ms="parentSeriesTitle" wire:focus="$set('showParentDropdown', true)" placeholder="Search or type parent title..." style="width: 100%;">
                        @if($showParentDropdown && count($parentSuggestions) > 0)
                            <div class="ia-autocomplete-dropdown">
                                @foreach($parentSuggestions as $sugg)
                                    <div wire:click="selectParentSuggestion('{{ addslashes($sugg->series_title) }}', {{ $sugg->id ?? 'null' }}, {{ $sugg->series_type ?? 'null' }}, '{{ addslashes($sugg->recorded_at_office ?? '') }}')" class="ia-autocomplete-item" style="display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 12px;">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            @if(!empty($sugg->shorted_type))
                                                <span style="font-size: 10.5px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px; letter-spacing: 0.5px; text-transform: uppercase;">{{ $sugg->shorted_type }}</span>
                                            @endif
                                            <span style="font-weight: 700; color: #0f172a;">{{ $sugg->series_title }}</span>
                                        </div>
                                        @if(!empty($sugg->recorded_at_office))
                                            <span style="font-size: 10px; font-weight: 700; color: #64748b; background: #f1f5f9; border: 1px solid #cbd5e1; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">
                                                {{ $sugg->recorded_office_name ?? $sugg->recorded_at_office }}
                                            </span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @foreach($subsections as $idx => $sub)
                        <div style="margin-bottom: 12px; display: flex; gap: 8px; position: relative; z-index: {{ max(30 - $idx, 10) }};" wire:click.outside="$set('activeSubDropdownIndex', null)">
                            <input type="text" class="ia-input" wire:model.live="subsections.{{ $idx }}" wire:focus="$set('activeSubDropdownIndex', {{ $idx }})" placeholder="Subsection #{{ $idx + 1 }} title...">
                            <button type="button" wire:click="removeSubsection({{ $idx }})" class="ia-btn ia-btn-danger" style="padding: 0 12px;">&times;</button>

                            @if($activeSubDropdownIndex === $idx && count($this->getSubSuggestions($idx)) > 0)
                                <div class="ia-autocomplete-dropdown" style="top: 100%; left: 0; right: 40px; z-index: 1050;">
                                    @foreach($this->getSubSuggestions($idx) as $subSugg)
                                        <div wire:click="selectSubSuggestion({{ $idx }}, '{{ addslashes($subSugg->series_title) }}')" class="ia-autocomplete-item" style="display: flex; align-items: center; gap: 8px;">
                                            @if(!empty($subSugg->shorted_type))
                                                <span style="font-size: 10.5px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px; letter-spacing: 0.5px; text-transform: uppercase;">{{ $subSugg->shorted_type }}</span>
                                            @endif
                                            <span style="font-weight: 700; color: #0f172a;">{{ $subSugg->series_title }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach

                    <div style="margin-bottom: 8px;">
                        <button type="button" wire:click="addSubsection" class="ia-btn ia-btn-ghost" style="border-style: dashed;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                            Add Subsection
                        </button>
                    </div>
                </div>
                <div class="ia-modal-footer">
                    <button type="button" wire:click="closeSeriesModal" class="ia-btn ia-btn-secondary">Cancel</button>
                    <button type="button" wire:click="saveNewRecordSeries" class="ia-btn ia-btn-primary">Apply Series</button>
                </div>
            </div>
        </div>
    @endif

    <!-- ═══════ Duplicate Offices Modal ═══════ -->
    @if($showDuplicateModal)
        @php
            $isBatchItem = $duplicateModalBatchIndex !== null;
            $modalCurOffices = $isBatchItem 
                ? ($batchItems[$duplicateModalBatchIndex]['duplicate_offices'] ?? [])
                : $duplicate_offices;
            $modalCurFileViews = $isBatchItem 
                ? ($batchItems[$duplicateModalBatchIndex]['duplicate_file_view'] ?? [])
                : $duplicate_file_view;
            $mSearchLower = strtolower(trim($duplicateModalSearch));
            $modalOfficesList = collect($officesList)->filter(function($off) use ($mSearchLower) {
                if (empty($mSearchLower)) return true;
                return str_contains(strtolower($off->office_code), $mSearchLower) ||
                       str_contains(strtolower($off->office_name), $mSearchLower);
            });
            $selectedCount = count($modalCurOffices);
            $withViewCount = collect($modalCurOffices)->filter(fn($c) => !empty($modalCurFileViews[$c]))->count();
        @endphp
        <div class="ia-modal-overlay" wire:click.self="closeDuplicateModal" style="z-index: 10000;">
            <div class="ia-modal-card" style="width: 660px; max-width: 95vw; border-radius: 14px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.25);">
                <div class="ia-modal-header" style="padding: 18px 22px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0;">
                    <div>
                        <h3 class="ia-modal-title" style="margin: 0 0 4px 0; font-size: 17px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                            Duplicate Offices {{ $isBatchItem ? '(Row #' . ($duplicateModalBatchIndex + 1) . ')' : '' }}
                        </h3>
                        <p style="margin: 0; font-size: 12px; color: #64748b; font-weight: 500;">
                            Select duplicate copy holders and configure which offices can view/download attached files.
                        </p>
                    </div>
                    <button type="button" wire:click="closeDuplicateModal" class="ia-modal-close" style="font-size: 22px; cursor: pointer;">&times;</button>
                </div>

                <div class="ia-modal-body" style="padding: 18px 22px; display: flex; flex-direction: column; gap: 14px; max-height: 65vh; overflow-y: auto;">
                    @if(!$isBatchItem)
                        <!-- Master Spread Toggle -->
                        <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px;">
                            <div style="display: flex; flex-direction: column;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 13px; font-weight: 700; color: #1e293b;">Spread to Duplicate Offices (Office Collective)</span>
                                    <span style="font-size: 10.5px; font-weight: 800; padding: 2px 7px; border-radius: 9999px; text-transform: uppercase; {{ $spreadToDuplicateOffices ? 'background: #dcfce7; color: #15803d; border: 1px solid #86efac;' : 'background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1;' }}">
                                        {{ $spreadToDuplicateOffices ? 'Spread: ON' : 'Spread: OFF' }}
                                    </span>
                                </div>
                                <span style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                    Dispatches incoming Record Requests to duplicate offices upon record creation.
                                </span>
                            </div>
                            <label style="position: relative; display: inline-block; width: 42px; height: 22px; margin: 0; cursor: pointer; flex-shrink: 0;">
                                <input type="checkbox" wire:model.live="spreadToDuplicateOffices" style="opacity: 0; width: 0; height: 0;">
                                <span style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: {{ $spreadToDuplicateOffices ? '#10b981' : '#cbd5e1' }}; transition: .25s; border-radius: 22px;"></span>
                                <span style="position: absolute; content: ''; height: 16px; width: 16px; left: {{ $spreadToDuplicateOffices ? '22px' : '4px' }}; bottom: 3px; background-color: white; transition: .25s; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.25);"></span>
                            </label>
                        </div>
                    @endif

                    <!-- Search & Quick Selection Actions -->
                    <div style="display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap;">
                        <div style="position: relative; flex: 1; min-width: 220px;">
                            <input type="text" 
                                   class="ia-input" 
                                   wire:model.live.debounce.150ms="duplicateModalSearch" 
                                   placeholder="Search office by code or name..."
                                   style="width: 100%; font-size: 13px; padding-left: 12px; box-sizing: border-box;">
                        </div>
                        <div style="display: flex; gap: 6px;">
                            <button type="button" wire:click="selectAllModalDuplicateOffices" class="ia-btn ia-btn-secondary" style="font-size: 11px; height: 32px; padding: 0 10px;">Select All</button>
                            <button type="button" wire:click="clearAllModalDuplicateOffices" class="ia-btn ia-btn-secondary" style="font-size: 11px; height: 32px; padding: 0 10px;">Clear All</button>
                        </div>
                    </div>

                    <!-- Selected Count Badge -->
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 9px 14px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; font-size: 12.5px; color: #1e40af; font-weight: 600;">
                        <span>Selected Duplicates: <strong>{{ $selectedCount }}</strong> {{ \Illuminate\Support\Str::plural('office', $selectedCount) }}</span>
                        <span style="font-size: 11.5px; color: #2563eb; font-weight: 700;">
                            {{ $withViewCount }} with file viewing access
                        </span>
                    </div>

                    <!-- Offices List -->
                    <div style="display: flex; flex-direction: column; gap: 6px; border: 1px solid #e2e8f0; border-radius: 8px; max-height: 290px; overflow-y: auto; padding: 6px;">
                        @forelse($modalOfficesList as $off)
                            @php
                                $isOffSelected = in_array($off->office_code, $modalCurOffices, true);
                                $offCanView = $isOffSelected && !empty($modalCurFileViews[$off->office_code]);
                            @endphp
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; border-radius: 6px; border: 1px solid {{ $isOffSelected ? '#bfdbfe' : '#f1f5f9' }}; background: {{ $isOffSelected ? '#f8fafc' : '#ffffff' }}; transition: background 0.15s ease;">
                                <div style="display: flex; align-items: center; gap: 10px; cursor: pointer; flex: 1;"
                                     wire:click="toggleModalDuplicateOffice('{{ $off->office_code }}')">
                                    <input type="checkbox" 
                                           {{ $isOffSelected ? 'checked' : '' }} 
                                           style="cursor: pointer; pointer-events: none; width: 16px; height: 16px; accent-color: #2563eb;">
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <span style="font-size: 11px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 7px; border-radius: 4px;">
                                            {{ $off->office_code }}
                                        </span>
                                        <span style="font-size: 12.5px; font-weight: 600; color: #1e293b;">
                                            {{ $off->office_name }}
                                        </span>
                                    </div>
                                </div>

                                <div style="display: flex; align-items: center; gap: 8px;">
                                    @if($isOffSelected)
                                        <!-- Per-office File Viewing Toggle (Off by default) -->
                                        <button type="button" 
                                                wire:click="toggleModalFileView('{{ $off->office_code }}')" 
                                                title="{{ $offCanView ? 'File viewing allowed (Click to disable)' : 'File viewing blocked (Click to allow)' }}"
                                                style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700; cursor: pointer; border: 1px solid {{ $offCanView ? '#059669' : '#94a3b8' }}; background: {{ $offCanView ? '#ecfdf5' : '#ffffff' }}; color: {{ $offCanView ? '#047857' : '#64748b' }}; transition: all 0.15s ease;">
                                            @if($offCanView)
                                                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                <span>View File: ON</span>
                                            @else
                                                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                                <span>View File: OFF</span>
                                            @endif
                                        </button>
                                        <span style="font-size: 11.5px; font-weight: 700; color: #2563eb;">✓ Added</span>
                                    @else
                                        <button type="button" 
                                                wire:click="toggleModalDuplicateOffice('{{ $off->office_code }}')" 
                                                class="ia-btn ia-btn-ghost" 
                                                style="padding: 2px 8px; font-size: 11px; height: 26px; color: #64748b;">
                                            + Add
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div style="text-align: center; padding: 24px; font-size: 12.5px; color: #64748b;">
                                No matching active offices found.
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="ia-modal-footer" style="padding: 14px 22px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border-bottom-left-radius: 14px; border-bottom-right-radius: 14px;">
                    <span style="font-size: 12px; color: #64748b; font-weight: 500;">
                        <strong>{{ $selectedCount }}</strong> {{ \Illuminate\Support\Str::plural('office', $selectedCount) }} selected
                    </span>
                    <button type="button" wire:click="closeDuplicateModal" class="ia-btn ia-btn-primary" style="height: 36px; padding: 0 18px; font-size: 12.5px; background: #2563eb; border-color: #2563eb;">
                        Done
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- ═══════ Batch Period Covered & Volume Modal ═══════ -->
    @if($showBatchModal)
        <div class="ia-modal-overlay" style="z-index: 10000;">
            <div class="ia-modal-card is-batch-modal" style="width: 760px; max-width: 95vw;">
                <div class="ia-modal-header">
                    <div>
                        <h3 style="margin-bottom: 2px;">Configure Period Covered & Volume</h3>
                        <p style="margin: 0; font-size: 12px; color: #64748b; font-weight: 600;">
                            Set overall date range and volume distribution across yearly durations
                        </p>
                    </div>
                    <button type="button" wire:click="closeBatchModal" class="ia-modal-close" title="Close modal">&times;</button>
                </div>
                
                <div class="ia-modal-body" style="display: flex; flex-direction: column; gap: 18px; max-height: 75vh; overflow-y: auto;">
                    <!-- Configuration Bar inside Modal -->
                    <div class="ia-batch-config-bar" style="border-radius: 10px; padding: 14px 16px;">
                        <div class="ia-batch-date-range-bar">
                            <!-- Start Date DMY -->
                            <div class="ia-dmy-field-wrapper">
                                <label class="ia-dmy-label">Start Period Date</label>
                                <div class="ia-dmy-group">
                                    <input type="number" min="1" max="31" class="ia-input ia-input-sm ia-dmy-day" wire:model.live.debounce.300ms="batch_start_day" placeholder="DD" title="Start Day">
                                    <select class="ia-input ia-input-sm ia-dmy-month" wire:model.live="batch_start_month" title="Start Month">
                                        <option value="">Month</option>
                                        <option value="1">January</option>
                                        <option value="2">February</option>
                                        <option value="3">March</option>
                                        <option value="4">April</option>
                                        <option value="5">May</option>
                                        <option value="6">June</option>
                                        <option value="7">July</option>
                                        <option value="8">August</option>
                                        <option value="9">September</option>
                                        <option value="10">October</option>
                                        <option value="11">November</option>
                                        <option value="12">December</option>
                                    </select>
                                    <input type="number" min="1900" max="2100" class="ia-input ia-input-sm ia-dmy-year" wire:model.live.debounce.300ms="batch_start_year" placeholder="YYYY" title="Start Year (e.g. 1998)">
                                </div>
                            </div>

                            <span class="ia-dmy-separator">to</span>

                            <!-- End Date DMY -->
                            <div class="ia-dmy-field-wrapper">
                                <label class="ia-dmy-label">End Period Date</label>
                                <div class="ia-dmy-group">
                                    <input type="number" min="1" max="31" class="ia-input ia-input-sm ia-dmy-day" wire:model.live.debounce.300ms="batch_end_day" placeholder="DD" title="End Day">
                                    <select class="ia-input ia-input-sm ia-dmy-month" wire:model.live="batch_end_month" title="End Month">
                                        <option value="">Month</option>
                                        <option value="1">January</option>
                                        <option value="2">February</option>
                                        <option value="3">March</option>
                                        <option value="4">April</option>
                                        <option value="5">May</option>
                                        <option value="6">June</option>
                                        <option value="7">July</option>
                                        <option value="8">August</option>
                                        <option value="9">September</option>
                                        <option value="10">October</option>
                                        <option value="11">November</option>
                                        <option value="12">December</option>
                                    </select>
                                    <input type="number" min="1900" max="2100" class="ia-input ia-input-sm ia-dmy-year" wire:model.live.debounce.300ms="batch_end_year" placeholder="YYYY" title="End Year (e.g. 2025)">
                                </div>
                            </div>

                            <button type="button" wire:click="generateBatchSubPeriods" class="ia-btn ia-btn-secondary" style="height: 34px; font-size: 11.5px; padding: 0 12px; margin-bottom: 2px;" title="Refresh/regenerate yearly breakdown based on dates above">
                                ↻ Refresh Breakdown
                            </button>
                        </div>

                        <div style="display: flex; align-items: center; gap: 8px; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #cbd5e1; flex-wrap: wrap;">
                            <label style="font-size: 11.5px; font-weight: 700; color: #334155; margin: 0;">Default Volume per Period:</label>
                            <input type="text" class="ia-input ia-input-sm" wire:model.live.debounce.300ms="batch_default_volume" placeholder="e.g. 1 box 10 papers" style="max-width: 220px; font-size: 12px;">
                            <button type="button" wire:click="applyBatchDefaultVolume" class="ia-btn ia-btn-secondary" style="height: 30px; font-size: 11px; padding: 0 10px;" title="Apply this default volume to all generated breakdown periods">Apply to All Periods</button>
                        </div>
                    </div>

                    <!-- Breakdown & Volume Input Area inside Modal -->
                    <div>
                        <div class="ia-batch-breakdown-header">
                            <span class="ia-batch-breakdown-title">
                                Duration Breakdown & Volume
                            </span>
                            <span style="font-size: 11px; color: #64748b;">
                                Auto-updates with each date & volume input
                            </span>
                        </div>

                        <!-- Accordion Card inside Modal -->
                        <div class="ia-batch-accordion-card">
                            <!-- Parent Header Row -->
                            <div class="ia-batch-header-row" wire:click="toggleBatchDropdown">
                                <div class="ia-batch-header-left">
                                    <button type="button" 
                                            class="ia-batch-toggle-arrow-btn {{ $batch_dropdown_expanded ? 'is-expanded' : 'is-collapsed' }}" 
                                            title="{{ $batch_dropdown_expanded ? 'Collapse duration breakdown (Down Arrow)' : 'Expand duration breakdown (Side Arrow)' }}">
                                        @if($batch_dropdown_expanded)
                                            <!-- Down Arrow (Expanded) -->
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="6 9 12 15 18 9"></polyline>
                                            </svg>
                                        @else
                                            <!-- Side Arrow (Collapsed) -->
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="9 18 15 12 9 6"></polyline>
                                            </svg>
                                        @endif
                                    </button>
                                    <div class="ia-batch-header-period">
                                        @if(!empty($batch_start_date) && !empty($batch_end_date))
                                            <span class="ia-batch-range-text">
                                                {{ \Carbon\Carbon::parse($batch_start_date)->translatedFormat('j F Y') }} — {{ \Carbon\Carbon::parse($batch_end_date)->translatedFormat('j F Y') }}
                                            </span>
                                        @elseif(!empty($batch_start_date))
                                            <span class="ia-batch-range-text">
                                                From {{ \Carbon\Carbon::parse($batch_start_date)->translatedFormat('j F Y') }}
                                            </span>
                                        @else
                                            <span class="ia-batch-range-placeholder">
                                                Place Start and End Period Dates above to generate duration breakdown
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <div class="ia-batch-header-right">
                                    <div class="ia-batch-total-volume-pill">
                                        <span class="ia-sum-symbol">∑</span>
                                        <span class="ia-sum-label">TOTAL VOLUME:</span>
                                        <span class="ia-sum-value">
                                            {{ $this->getBatchTotalVolumeFormatted() ?: '0 Papers' }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Sub-periods List -->
                            @if($batch_dropdown_expanded)
                                <div class="ia-batch-children-container">
                                    @if(!empty($batch_sub_periods))
                                        @foreach($batch_sub_periods as $pIdx => $sub)
                                            <div class="ia-batch-tree-item" wire:key="modal-batch-sub-period-{{ $pIdx }}-{{ $sub['id'] ?? $pIdx }}">
                                                <!-- Top Row: Sub-period Title / Subject Input (<subject> <year>) -->
                                                <div class="ia-sub-desc-row">
                                                    <input type="text" 
                                                           class="ia-input ia-input-sm ia-sub-desc-input" 
                                                           wire:model.live.debounce.300ms="batch_sub_periods.{{ $pIdx }}.description" 
                                                           placeholder="e.g. {{ $this->formatSubPeriodDefaultDescription($sub['start_date'] ?? '', $sub['end_date'] ?? '') ?: 'Subject Year' }}" 
                                                           title="Subject / Title for this period (Auto-fills with Subject + Year)">
                                                </div>

                                                <!-- Bottom Row: Connector + Date Range + Volume + Remove -->
                                                <div class="ia-sub-bottom-row">
                                                    <!-- Tree branch connector -->
                                                    <div class="ia-batch-tree-connector" aria-hidden="true" title="Yearly breakdown branch">
                                                        <svg width="22" height="24" viewBox="0 0 22 24" fill="none" class="ia-tree-branch-svg">
                                                            <path d="M 7 0 V 13 H 20" stroke="#6366f1" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                                                        </svg>
                                                    </div>

                                                    <!-- Date Range Calendar Inputs: <start date> to <end date> -->
                                                    <div class="ia-batch-tree-date">
                                                        <div class="ia-sub-calendar-group">
                                                            <input type="date" 
                                                                   class="ia-input ia-input-sm ia-sub-calendar-input" 
                                                                   wire:model.live="batch_sub_periods.{{ $pIdx }}.start_date" 
                                                                   title="Start Date">
                                                            <span class="ia-sub-dmy-to">to</span>
                                                            <input type="date" 
                                                                   class="ia-input ia-input-sm ia-sub-calendar-input" 
                                                                   wire:model.live="batch_sub_periods.{{ $pIdx }}.end_date" 
                                                                   title="End Date">
                                                        </div>
                                                    </div>

                                                    <!-- Sub Volume Input -->
                                                    <div class="ia-batch-tree-volume">
                                                        <div class="ia-sub-vol-input-group">
                                                            <input type="text" 
                                                                   class="ia-input ia-input-sm ia-sub-vol-text" 
                                                                   wire:model.live.debounce.300ms="batch_sub_periods.{{ $pIdx }}.volume" 
                                                                   placeholder="e.g. 10 Boxes 2 Papers"
                                                                   style="{{ ($showValidationErrors && (empty($sub['volume']) || !preg_match('/\d/', $sub['volume']))) ? 'border-color: #fca5a5; background: #fff5f5;' : '' }}"
                                                                   title="Volume for this period (Required, e.g. 1 box 10 papers)">
                                                        </div>
                                                        <button type="button" 
                                                                wire:click="removeBatchSubPeriod({{ $pIdx }})" 
                                                                class="ia-btn-remove-sub" 
                                                                title="Remove this period range">
                                                            &times;
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    @else
                                        <div style="padding: 24px; text-align: center; color: #64748b; font-size: 13px; font-style: italic;">
                                            No period ranges generated yet. Set Start and End dates above or click "+ Add Period Range".
                                        </div>
                                    @endif

                                    <div class="ia-batch-children-footer">
                                        <button type="button" wire:click="addBatchSubPeriod" class="ia-btn ia-btn-ghost ia-btn-sm" style="border-style: dashed; font-size: 12px;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                            Add Period Range
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="ia-modal-footer" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                    <span style="font-size: 12px; color: #64748b; font-weight: 500;">
                        💡 Applies period breakdown to form. Remember to click <strong>Create Record</strong> below to save.
                    </span>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" wire:click="closeBatchModal" class="ia-btn ia-btn-secondary">Close</button>
                        <button type="button" wire:click="applyBatchModal" class="ia-btn ia-btn-primary" style="background: #059669; border-color: #059669;">
                            ✓ Apply Breakdown to Form
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- ═══════ Record Verification & Print Preview Modal ═══════ -->
    @if($showVerificationModal)
        <div class="ia-modal-overlay" wire:click.self="closeVerificationModal" style="z-index: 1050;">
            <div class="ia-modal-card is-verification-modal">
                <div class="ia-modal-header" style="background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%); border-bottom: 1px solid #e2e8f0; padding: 18px 24px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 38px; height: 38px; border-radius: 10px; background: #dcfce7; display: flex; align-items: center; justify-content: center; color: #15803d; flex-shrink: 0; box-shadow: 0 2px 6px rgba(21, 128, 61, 0.15);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                        </div>
                        <div>
                            <div style="font-size: 17px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                                Record Verification & Print Preview
                                <span style="font-size: 11px; font-weight: 700; background: #e0e7ff; color: #4338ca; padding: 2px 8px; border-radius: 9999px;">NAP Form 1 Layout</span>
                            </div>
                            <p style="margin: 3px 0 0 0; font-size: 12px; color: #64748b;">
                                Review the official National Archives of the Philippines layout before finalizing.
                            </p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeVerificationModal" class="ia-modal-close" title="Close Verification">&times;</button>
                </div>

                <div class="ia-modal-body" style="padding: 18px 24px; background: #f8fafc; overflow-y: auto; max-height: calc(88vh - 145px);">
                    @php
                        $effectiveSpreadList = $spreadToDuplicateOffices ? $duplicate_offices : ($collectiveOffices ?? []);
                        $viewerCode = strtoupper(trim((string) ($userOfficeCode ?? '')));
                        $effectiveSpreadList = array_values(array_filter($effectiveSpreadList, fn($c) => !empty($c) && strcasecmp(trim((string)$c), $viewerCode) !== 0));
                        $isSpreadNoticeActive = count($effectiveSpreadList) > 0;
                    @endphp
                    @if($isSpreadNoticeActive)
                        <div class="ia-verification-spread-notice" style="background: #eff6ff; border: 1.5px solid #93c5fd; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-3"/></svg>
                                <div>
                                    <div style="font-weight: 700; color: #1e3a8a; font-size: 12.5px;">
                                        Duplicate Offices Spread Activated
                                    </div>
                                    <div style="font-size: 11.5px; color: #1e40af; margin-top: 2px;">
                                        This record will be created under your office (<strong>{{ $userOfficeCode }}</strong>) and spread as a record request to <strong>{{ count($effectiveSpreadList) }}</strong> duplicate {{ \Illuminate\Support\Str::plural('office', count($effectiveSpreadList)) }}:
                                        <span style="font-weight: 600;">{{ implode(', ', $effectiveSpreadList) }}</span>.
                                    </div>
                                </div>
                            </div>
                            <span style="background: #dbeafe; color: #1d4ed8; font-weight: 700; font-size: 11px; padding: 4px 8px; border-radius: 6px; white-space: nowrap;">
                                {{ count($effectiveSpreadList) }} {{ \Illuminate\Support\Str::plural('Office', count($effectiveSpreadList)) }}
                            </span>
                        </div>
                    @endif

                    <!-- Print Sheet Document Container -->
                    <div class="ia-verification-sheet" style="background: #ffffff; border: 1.5px solid #000; padding: 18px 20px; font-family: Arial, sans-serif; box-shadow: 0 4px 14px rgba(0,0,0,0.06); color: #000;">
                        <!-- Top Identifier -->
                        <div style="font-size: 8px; font-weight: normal; margin-bottom: 4px; line-height: 1.2;">
                            NAP Records Inventory and Appraisal Form<br>2024
                        </div>

                        <!-- Top Header Grid Box (Fields 1 to 8) -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; border-bottom: none; font-size: 8px; text-align: left; table-layout: fixed;">
                            <tr>
                                <td rowspan="3" style="width: 28%; border: 1px solid #000; text-align: center; vertical-align: middle; padding: 4px;">
                                    <div style="border: 1.5px solid #000; padding: 6px 4px; margin: 2px;">
                                        <div style="font-weight: bold; font-size: 9.5px; text-align: center;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                                        <div style="font-style: italic; font-size: 8px; margin: 2px 0 6px 0; text-align: center;">Pambansang Sinupan ng Pilipinas</div>
                                        <div style="font-weight: bold; font-size: 9.5px; text-align: center;">RECORDS INVENTORY AND APPRAISAL</div>
                                    </div>
                                </td>
                                <td rowspan="2" style="width: 27%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>1. NAME OF OFFICE:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ Auth::user()?->details?->office?->office_name ?? Auth::user()?->details?->office_code ?? 'CAMARINES SUR POLYTECHNIC COLLEGES' }}</div>
                                </td>
                                <td style="width: 18%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>2. DEPARTMENT/DIVISION:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ Auth::user()?->details?->office?->office_name ?? Auth::user()?->details?->office_code ?? 'CSPC' }}</div>
                                </td>
                                <td style="width: 27%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>4. TELEPHONE NO.:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">—</div>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>3. SECTION/UNIT:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ Auth::user()?->details?->office_code ?? 'MAIN' }}</div>
                                </td>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>5. EMAIL ADDRESS:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ Auth::user()?->email ?? '—' }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>6. ADDRESS:</strong>
                                    <div style="font-size: 8.5px; font-weight: bold; margin-top: 2px;">San Miguel, Nabua, Camarines Sur</div>
                                </td>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>7. PERSON-IN-CHARGE OF FILES:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ Auth::user()?->name ?? 'Records Officer' }}</div>
                                </td>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>8. DATE PREPARED:</strong>
                                    <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ now()->format('F d, Y') }}</div>
                                </td>
                            </tr>
                        </table>

                        <!-- Main Columns 9 to 20 Data Table -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 7.5px; text-align: center; table-layout: fixed;">
                            <thead>
                                <tr style="font-weight: bold; background: #fafafa;">
                                    <th rowspan="2" style="border: 1px solid #000; width: 18%; padding: 4px 2px;">9. RECORDS SERIES TITLE AND DESCRIPTION</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 8.5%; padding: 4px 2px;">10. PERIOD COVERED / INCLUSIVE DATES</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 6%; padding: 4px 2px;">11. VOLUME</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px 2px;">12. RECORDS MEDIUM</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 6%; padding: 4px 2px;">13. RESTRICTION/S</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 7.5%; padding: 4px 2px;">14. LOCATION OF RECORDS</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 6%; padding: 4px 2px;">15. FREQUENCY OF USE</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 5.5%; padding: 4px 2px;">16. DUPLICATION</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 5%; padding: 4px 2px;">17. TIME VALUE (T/P)</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px 2px;">18. UTILITY VALUE Adm/F/L/Arc</th>
                                    <th colspan="3" style="border: 1px solid #000; width: 11%; padding: 4px 2px;">19. RETENTION PERIOD</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 13.5%; padding: 4px 2px;">20. DISPOSITION PROVISION</th>
                                </tr>
                                <tr style="font-weight: bold; background: #fafafa;">
                                    <th style="border: 1px solid #000; width: 3.6%; padding: 3px 2px;">Active</th>
                                    <th style="border: 1px solid #000; width: 3.6%; padding: 3px 2px;">Storage</th>
                                    <th style="border: 1px solid #000; width: 3.8%; padding: 3px 2px;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $cellBorder = "border: 1px solid #000;";
                                @endphp

                                @if($entryMode === 'single')
                                    <tr style="vertical-align: top;">
                                        <td style="{{ $cellBorder }} text-align: left; padding: 4px 6px;">
                                            <div style="font-weight: bold; font-size: 8px;">{{ strtoupper($selectedSeriesTitle ?: '—') }}</div>
                                            @if(!empty(trim($description)))
                                                <div style="font-size: 7.5px; margin-top: 2px; color: #1e293b;">{{ $description }}</div>
                                            @endif
                                        </td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $this->formatPreviewDate($date_covered) }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $volume ?: ($volume_amount ? ($volume_amount . ' ' . $volume_unit) : '—') }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $this->getMediumNameById($records_medium) }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $restriction ?: '—' }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $records_location ?: '—' }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $frequence_use ?: '—' }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px;">{{ !empty($duplicate_offices) ? implode(', ', $duplicate_offices) : 'None' }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px; font-weight: bold;">{{ $time_value ?: 'T' }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px; font-weight: bold;">{{ $this->formatUtilityValuesForPreview($utility_values) }}</td>
                                        @if($is_permanent)
                                            <td colspan="3" style="{{ $cellBorder }} padding: 4px 2px; font-weight: bold;">PERMANENT</td>
                                        @else
                                            <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $active_period ?: '—' }}</td>
                                            <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $storage_period ?: '—' }}</td>
                                            <td style="{{ $cellBorder }} padding: 4px 2px; font-weight: bold;">{{ $retention_period ?: '—' }}</td>
                                        @endif
                                        <td style="{{ $cellBorder }} text-align: left; padding: 4px 6px;">{{ $disposition_provision ?: '—' }}</td>
                                    </tr>

                                @elseif($entryMode === 'batch')
                                    <!-- Parent Batch Row -->
                                    <tr style="vertical-align: top; background: #fdfdfd;">
                                        <td style="{{ $cellBorder }} text-align: left; padding: 4px 6px;">
                                            <div style="font-weight: bold; font-size: 8px;">{{ strtoupper($selectedSeriesTitle ?: '—') }}</div>
                                            @if(!empty(trim($description)))
                                                <div style="font-size: 7.5px; margin-top: 2px; color: #1e293b;">{{ $description }}</div>
                                            @endif
                                        </td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px; font-weight: bold;">
                                            {{ $this->formatBatchDateRange($batch_start_date, $batch_end_date, false) ?: '—' }}
                                        </td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px; font-weight: bold;">
                                            {{ $this->getBatchTotalVolumeFormatted() ?: '—' }}
                                        </td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $this->getMediumNameById($records_medium) }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $restriction ?: '—' }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $records_location ?: '—' }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $frequence_use ?: '—' }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px;">{{ !empty($duplicate_offices) ? implode(', ', $duplicate_offices) : 'None' }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px; font-weight: bold;">{{ $time_value ?: 'T' }}</td>
                                        <td style="{{ $cellBorder }} padding: 4px 2px; font-weight: bold;">{{ $this->formatUtilityValuesForPreview($utility_values) }}</td>
                                        @if($is_permanent)
                                            <td colspan="3" style="{{ $cellBorder }} padding: 4px 2px; font-weight: bold;">PERMANENT</td>
                                        @else
                                            <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $active_period ?: '—' }}</td>
                                            <td style="{{ $cellBorder }} padding: 4px 2px;">{{ $storage_period ?: '—' }}</td>
                                            <td style="{{ $cellBorder }} padding: 4px 2px; font-weight: bold;">{{ $retention_period ?: '—' }}</td>
                                        @endif
                                        <td style="{{ $cellBorder }} text-align: left; padding: 4px 6px;">{{ $disposition_provision ?: '—' }}</td>
                                    </tr>
                                    <!-- Sub-period Rows -->
                                    @foreach($batch_sub_periods as $subIdx => $sub)
                                        <tr style="vertical-align: top; background: #ffffff;">
                                            <td style="{{ $cellBorder }} text-align: left; padding: 3px 6px 3px 18px; color: #334155;">
                                                ↳ {{ $sub['description'] ?: $this->formatSubPeriodDefaultDescription($sub['start_date'] ?? '', $sub['end_date'] ?? '', $description) }}
                                            </td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; color: #334155;">
                                                {{ $this->formatBatchDateRange($sub['start_date'] ?? '', $sub['end_date'] ?? '', false) ?: '—' }}
                                            </td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; color: #334155;">
                                                {{ $sub['volume'] ?: '—' }}
                                            </td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; color: #94a3b8;">—</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; color: #94a3b8;">—</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; color: #94a3b8;">—</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; color: #94a3b8;">—</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; color: #94a3b8;">—</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; color: #94a3b8;">—</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; color: #94a3b8;">—</td>
                                            <td colspan="3" style="{{ $cellBorder }} padding: 3px 2px; color: #94a3b8;">—</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; color: #94a3b8;">—</td>
                                        </tr>
                                    @endforeach

                                @elseif($entryMode === 'multi')
                                    <!-- Series Title Header Row -->
                                    <tr style="vertical-align: top; background: #f8fafc;">
                                        <td colspan="14" style="{{ $cellBorder }} text-align: left; padding: 5px 8px; font-weight: bold; font-size: 8px;">
                                            {{ strtoupper($selectedSeriesTitle ?: 'RECORD SERIES') }}
                                            <span style="font-size: 7.5px; font-weight: normal; color: #64748b; margin-left: 6px;">
                                                ({{ count($batchItems) }} {{ \Illuminate\Support\Str::plural('Record', count($batchItems)) }})
                                            </span>
                                        </td>
                                    </tr>
                                    <!-- Individual Multi Item Rows -->
                                    @foreach($batchItems as $bIdx => $bItem)
                                        <tr style="vertical-align: top;">
                                            <td style="{{ $cellBorder }} text-align: left; padding: 3px 6px;">
                                                <span style="font-weight: bold; color: #2563eb;">#{{ $bIdx + 1 }}:</span>
                                                {{ $bItem['description'] ?: '—' }}
                                            </td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px;">{{ $this->formatPreviewDate($bItem['date_covered'] ?? '') }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px;">{{ $bItem['volume'] ?? '—' }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px;">{{ $this->getMediumNameById(!empty($bItem['records_medium']) ? (int)$bItem['records_medium'] : null) }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px;">{{ $bItem['restriction'] ?? '—' }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px;">{{ $bItem['records_location'] ?? '—' }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px;">{{ $bItem['frequence_use'] ?? '—' }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px;">{{ !empty($bItem['duplicate_offices']) ? implode(', ', $bItem['duplicate_offices']) : 'None' }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; font-weight: bold;">{{ $bItem['time_value'] ?? 'T' }}</td>
                                            <td style="{{ $cellBorder }} padding: 3px 2px; font-weight: bold;">{{ $this->formatUtilityValuesForPreview($bItem['utility_values'] ?? []) }}</td>
                                            @if($is_permanent)
                                                <td colspan="3" style="{{ $cellBorder }} padding: 3px 2px; font-weight: bold;">PERMANENT</td>
                                            @else
                                                <td style="{{ $cellBorder }} padding: 3px 2px;">{{ $active_period ?: '—' }}</td>
                                                <td style="{{ $cellBorder }} padding: 3px 2px;">{{ $storage_period ?: '—' }}</td>
                                                <td style="{{ $cellBorder }} padding: 3px 2px; font-weight: bold;">{{ $retention_period ?: '—' }}</td>
                                            @endif
                                            <td style="{{ $cellBorder }} text-align: left; padding: 3px 6px;">{{ $disposition_provision ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 3 Action Buttons Footer -->
                <div class="ia-modal-footer is-verification-footer" style="padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; background: #ffffff; border-top: 1px solid #e2e8f0; border-bottom-left-radius: 16px; border-bottom-right-radius: 16px;">
                    <div style="font-size: 12px; color: #64748b; display: flex; align-items: center; gap: 6px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <span>Please verify the record specifications before finalizing.</span>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button type="button" wire:click="closeVerificationModal" class="ia-btn ia-btn-secondary" style="height: 38px; padding: 0 16px; font-size: 13px; font-weight: 600;">
                            Cancel
                        </button>
                        <button type="button" wire:click="confirmSaveDraft" class="ia-btn" style="height: 38px; padding: 0 16px; font-size: 13px; font-weight: 700; background: #f8fafc; color: #334155; border: 1.5px solid #cbd5e1; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all 0.2s;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            {{ $entryMode === 'multi' && count($batchItems) > 1 ? 'Submit as Draft (' . count($batchItems) . ' Records)' : 'Submit as Draft' }}
                        </button>
                        <button type="button" wire:click="confirmCreateRecord" class="ia-btn ia-btn-primary" style="height: 38px; padding: 0 20px; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            @if($isSpreadNoticeActive)
                                {{ $entryMode === 'multi' && count($batchItems) > 1 ? 'Submit ' . count($batchItems) . ' Records & Spread' : 'Submit & Spread to Duplicate Offices' }}
                            @else
                                {{ $entryMode === 'multi' && count($batchItems) > 1 ? 'Submit ' . count($batchItems) . ' Records' : 'Submit' }}
                            @endif
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>