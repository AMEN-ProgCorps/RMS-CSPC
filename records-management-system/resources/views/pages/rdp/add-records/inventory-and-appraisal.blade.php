<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
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
    public ?string $frequence_use = null;
    public ?string $duplication = null;
    public array $duplicate_offices = [];
    public string $duplicate_search = '';
    public bool $showDuplicateDropdown = false;
    public ?string $time_value = 'T';
    public array $utility_values = []; // Multi-choice array of selected utility IDs
    public string $retention_period = '';
    public bool $is_permanent = false;
    public string $active_period = '';
    public string $storage_period = '';
    public string $disposition_provision = '';

    public bool $isAppraising = false;

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
            for ($y = $startYear; $y <= $endYear; $y++) {
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
        if (!empty($this->batch_sub_periods)) {
            $last = end($this->batch_sub_periods);
            try {
                $lastEnd = Carbon::parse($last['end_date']);
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
        } elseif (!empty($this->batch_end_date)) {
            try {
                $lastEnd = Carbon::parse($this->batch_end_date);
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

        if ($exists) {
            $realCode = $exists->office_code;
            if (!in_array($realCode, $offices, true)) {
                $offices[] = $realCode;
            }
        } elseif (!empty($officeCode) && !in_array($officeCode, $offices, true)) {
            $offices[] = $officeCode;
        }

        $this->batchItems[$bIdx]['duplicate_offices'] = array_values($offices);
        $this->batchItems[$bIdx]['duplicate_search'] = '';
        $this->batchItems[$bIdx]['showDuplicateDropdown'] = false;
    }

    public function removeBatchDuplicateOffice(int $bIdx, int $dupIdx): void
    {
        if (isset($this->batchItems[$bIdx]['duplicate_offices'][$dupIdx])) {
            unset($this->batchItems[$bIdx]['duplicate_offices'][$dupIdx]);
            $this->batchItems[$bIdx]['duplicate_offices'] = array_values($this->batchItems[$bIdx]['duplicate_offices']);
        }
    }

    public function clearBatchDuplicateOffices(int $bIdx): void
    {
        if (isset($this->batchItems[$bIdx])) {
            $this->batchItems[$bIdx]['duplicate_offices'] = [];
            $this->batchItems[$bIdx]['duplicate_search'] = '';
            $this->batchItems[$bIdx]['showDuplicateDropdown'] = false;
        }
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

        $this->prefill_intake_id = request()->query('prefill_intake_id') ? (int)request()->query('prefill_intake_id') : null;
        $this->prefill_source = request()->query('prefill_source');
        $this->prefill_code = request()->query('prefill_code');
        $this->prefill_doc_id = request()->query('prefill_doc_id');

        if ($this->prefill_intake_id) {
            $this->isAppraising = true;
            $intake = DB::table('rdp_received_documents')->where('id', $this->prefill_intake_id)->first();
            if ($intake) {
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

                        if (!empty($meta['records_location'])) {
                            $this->records_location = $meta['records_location'];
                        }
                        $this->frequence_use = $meta['frequence_use'] ?? 'Annually';
                        if (!empty($meta['duplicate_offices']) && is_array($meta['duplicate_offices'])) {
                            $this->duplicate_offices = array_values(array_unique(array_merge($this->duplicate_offices, $meta['duplicate_offices'])));
                        }
                        if (!empty($meta['utility_values']) && is_array($meta['utility_values'])) {
                            $this->utility_values = array_values(array_unique(array_merge($this->utility_values, $meta['utility_values'])));
                        }
                        if (!empty($meta['date_covered'])) {
                            $this->date_covered = Carbon::parse($meta['date_covered'])->format('Y-m-d');
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
                if (empty($this->duplicate_offices)) {
                    $userOffice = Auth::user()?->details?->office?->office_code ?? Auth::user()?->details?->office_code ?? null;
                    if ($userOffice) {
                        $this->duplicate_offices = [$userOffice];
                    }
                }
            }
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

    public function loadSeriesById(int $id): void
    {
        $series = DB::table('rdp_record_series')->where('id', $id)->first();
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

        $retention = null;
        if ($series->retention_period) {
            $retention = DB::table('rdp_retention_period')->where('id', $series->retention_period)->first();
        }

        if (!empty($series->remarks)) {
            $this->hasPredefinedRemarks = true;
            $this->disposition_provision = $series->remarks;
        }

        $isPermFlag = (bool)($series->is_retention_period_permanent ?? false);
        $isActivePermanent = $retention && strtolower($retention->active_period ?? '') === 'permanent';
        $isTotalPermanent  = $retention && strtolower($retention->total_period ?? '') === 'permanent';
        $isTitlePermanent  = str_contains(strtolower($series->series_title), 'permanent');

        if ($series->retention_period || $isPermFlag || $retention) {
            $this->hasPredefinedRetention = true;
        }

        if ($isPermFlag || $isActivePermanent || $isTotalPermanent || $isTitlePermanent) {
            $this->is_permanent = true;
            $this->active_period = '';
            $this->storage_period = '';
            $this->retention_period = 'Permanent';
            $this->time_value = 'P';
        } else {
            $this->is_permanent = false;
            $this->active_period = mb_strtoupper($retention->active_period ?? '');
            $this->storage_period = mb_strtoupper($retention->storage_period ?? '');
            $this->retention_period = mb_strtoupper($this->computeTotalPeriod($this->active_period, $this->storage_period, false));
            $this->time_value = 'T';
        }

        $this->syncArchivalAutoSelect();
    }

    public function addDuplicateOffice(?string $officeCode = null): void
    {
        if ($this->isAppraising) {
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
            }
        } elseif (!empty($officeCode) && !in_array($officeCode, $this->duplicate_offices, true)) {
            $this->duplicate_offices[] = $officeCode;
        }

        $this->duplicate_search = '';
        $this->showDuplicateDropdown = false;
    }

    public function removeDuplicateOffice(int $index): void
    {
        if ($this->isAppraising) {
            return;
        }

        if (isset($this->duplicate_offices[$index])) {
            unset($this->duplicate_offices[$index]);
            $this->duplicate_offices = array_values($this->duplicate_offices);
        }
    }

    public function clearDuplicateOffices(): void
    {
        if ($this->isAppraising) {
            return;
        }

        $this->duplicate_offices = [];
        $this->duplicate_search = '';
        $this->showDuplicateDropdown = false;
    }

    // --- Record Series Modal Handlers ---
    public function openSeriesModal(): void
    {
        if ($this->isAppraising) {
            return;
        }

        $this->showSeriesModal = true;
    }

    public function closeSeriesModal(): void
    {
        $this->showSeriesModal = false;
        $this->showParentDropdown = false;
        $this->activeSubDropdownIndex = null;
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
    }

    public function selectParentSuggestion(string $title, ?int $id = null, ?int $typeId = null, ?string $office = null): void
    {
        $this->parentSeriesTitle = mb_strtoupper($title);
        $this->selectedParentId = $id;
        $this->selectedParentTypeId = $typeId;
        $this->selectedParentOffice = $office;
        $this->showParentDropdown = false;
    }

    public function selectSubSuggestion(int $index, string $title): void
    {
        if (isset($this->subsections[$index])) {
            $this->subsections[$index] = mb_strtoupper($title);
        }
        $this->activeSubDropdownIndex = null;
    }

    public function addSubsection(): void
    {
        $this->subsections[] = '';
    }

    public function removeSubsection(int $index): void
    {
        if (isset($this->subsections[$index])) {
            unset($this->subsections[$index]);
            $this->subsections = array_values($this->subsections);
        }
    }

    public function saveNewRecordSeries(): void
    {
        $this->validate([
            'parentSeriesTitle' => 'required|string|max:255',
        ]);

        $fullPathTitles = [mb_strtoupper(trim($this->parentSeriesTitle))];

        foreach ($this->subsections as $subTitle) {
            $trimmed = mb_strtoupper(trim($subTitle));
            if (!empty($trimmed)) {
                $fullPathTitles[] = $trimmed;
            }
        }

        $this->selectedSeriesHierarchy = [];
        $currentParentId = null;

        foreach ($fullPathTitles as $idx => $t) {
            $query = DB::table('rdp_record_series')->where('series_title', 'ilike', $t);
            if ($idx === 0) {
                $query->whereNull('parent_id');
                if ($this->selectedSeriesTypeFilter) {
                    $query->where('series_type', $this->selectedSeriesTypeFilter);
                }
            } elseif ($currentParentId) {
                $query->where('parent_id', $currentParentId);
            }
            $existsNode = $query->first();

            if ($existsNode) {
                $currentParentId = $existsNode->id;
                $this->selectedSeriesHierarchy[] = [
                    'title' => $t,
                    'is_predefined' => true,
                ];
            } else {
                $currentParentId = null;
                $this->selectedSeriesHierarchy[] = [
                    'title' => $t,
                    'is_predefined' => false,
                ];
            }
        }

        $this->selectedSeriesTitle = implode(' ➔ ', $fullPathTitles);
        $leafTitle = end($fullPathTitles);

        $this->hasPredefinedRemarks = false;
        $this->hasPredefinedRetention = false;

        $foundSeries = DB::table('rdp_record_series')
            ->where('series_title', 'ilike', $leafTitle)
            ->first();

        if ($foundSeries) {
            $this->isCustomSeries = false;
            $retention = null;
            if ($foundSeries->retention_period) {
                $retention = DB::table('rdp_retention_period')
                    ->where('id', $foundSeries->retention_period)
                    ->first();
            }

            if (!empty($foundSeries->remarks)) {
                $this->hasPredefinedRemarks = true;
                $this->disposition_provision = $foundSeries->remarks;
            }

            $isPermFlag = (bool)($foundSeries->is_retention_period_permanent ?? false);
            $isActivePermanent = $retention && strtolower($retention->active_period ?? '') === 'permanent';
            $isTotalPermanent  = $retention && strtolower($retention->total_period ?? '') === 'permanent';
            $isTitlePermanent  = str_contains(strtolower($leafTitle), 'permanent');

            if ($foundSeries->retention_period || $isPermFlag || $retention) {
                $this->hasPredefinedRetention = true;
            }

            if ($isPermFlag || $isActivePermanent || $isTotalPermanent || $isTitlePermanent) {
                $this->is_permanent = true;
                $this->active_period = '';
                $this->storage_period = '';
                $this->retention_period = 'Permanent';
                $this->time_value = 'P';
            } else {
                $this->is_permanent = false;
                $this->active_period = mb_strtoupper($retention->active_period ?? '');
                $this->storage_period = mb_strtoupper($retention->storage_period ?? '');
                $this->retention_period = mb_strtoupper($this->computeTotalPeriod($this->active_period, $this->storage_period, false));
                $this->time_value = 'T';
            }
        } else {
            $this->isCustomSeries = true;
        }

        $this->syncArchivalAutoSelect();

        $this->showSeriesModal = false;
        $this->showParentDropdown = false;
        $this->activeSubDropdownIndex = null;
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
        ];
    }

    public function validateRecordData(): bool
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
        if ($requiredUpload && !$this->uploadedFile) {
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

            if (empty(trim($this->records_location))) {
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

            if (empty(trim($this->records_location))) {
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

            $user = Auth::user();
            $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
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
            } elseif (!empty($this->prefill_doc_id)) {
                $documentIdHandler = $this->prefill_doc_id;
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

                foreach (($rData['duplicate_offices'] ?? []) as $dupOffice) {
                    DB::table('rdp_duplication_section')->insert([
                        'dup_id_manager' => $recordId,
                        'office_code'    => $dupOffice,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
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
                        if ($hasDescCol && !empty($sub['description'])) {
                            $pRow['description'] = trim($sub['description']);
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

            DB::commit();

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

            $user = Auth::user();
            $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
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
            } elseif (!empty($this->prefill_doc_id)) {
                $documentIdHandler = $this->prefill_doc_id;
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
                    'is_draft'               => false,
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

                foreach (($rData['duplicate_offices'] ?? []) as $dupOffice) {
                    DB::table('rdp_duplication_section')->insert([
                        'dup_id_manager' => $recordId,
                        'office_code'    => $dupOffice,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
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
                        if ($hasDescCol && !empty($sub['description'])) {
                            $pRow['description'] = trim($sub['description']);
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

            DB::commit();

            $count = count($itemsToProcess);
            $this->successMessage = ($this->entryMode === 'multi' && $count > 1) 
                ? "Multi-entry of {$count} records created successfully under this series!" 
                : ($this->entryMode === 'batch' 
                    ? "Batch record with duration breakdown created successfully!"
                    : "Inventory and Appraisal Record created successfully!");
            $this->resetFormFields();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to create record: ' . $e->getMessage();
            $this->dispatch('scroll-to-top');
        }
    }

    public function resetFormFields(): void
    {
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
        $this->duplicate_search = '';
        $this->showDuplicateDropdown = false;
        $this->dispatch('scroll-to-top');
    }

    public function clearMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }
}; ?>

<div class="inventory-appraisal-form" x-data @scroll-to-top.window="rdpScrollToTop()" style="padding: 24px; max-width: 1200px; margin: 0 auto;">
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
                        Document fields have been pre-filled. Finalizing or saving as draft will automatically link and mark the intake document as <strong>Appraised</strong>.
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
        <div class="ia-form-card">
            <!-- Mode Header Bar (Always visible across all monitor resolutions) -->
            <div class="ia-mode-bar">
                <div class="ia-mode-info">
                    @if($isAppraising)
                        <span class="ia-mode-pill" style="background: #2563eb; color: #ffffff;">
                            DOCUMENT APPRAISAL MODE
                        </span>
                        <span class="ia-mode-desc">
                            Appraise incoming document {{ $prefill_code ? '#' . $prefill_code : '' }} directly into RDP
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
                @if(!$isAppraising)
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
                            <div x-show="modeOpen" x-cloak
                                 style="position: absolute; top: calc(100% + 5px); right: 0; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); min-width: 275px; z-index: 1000; padding: 4px 0; overflow: hidden;">
                                
                                <!-- Single Mode Option (Default) -->
                                <button type="button" 
                                        wire:click="switchToSingleMode" 
                                        @click="modeOpen = false"
                                        style="width: 100%; text-align: left; padding: 9px 14px; font-size: 12px; font-weight: 600; color: #1e293b; background: {{ $isSingle ? '#eff6ff' : 'transparent' }}; border: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 10px; transition: background 0.1s ease;"
                                        onmouseover="this.style.background='#f1f5f9'" 
                                        onmouseout="this.style.background='{{ $isSingle ? '#eff6ff' : 'transparent' }}'">
                                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                                        <span style="color: #2563eb; margin-top: 2px; display: inline-flex;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                <polyline points="14 2 14 8 20 8"></polyline>
                                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                            </svg>
                                        </span>
                                        <div>
                                            <div style="font-size: 12.5px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                                                <span>Single Mode</span>
                                                @if($isSingle)
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="20 6 9 17 4 12"></polyline>
                                                    </svg>
                                                @endif
                                            </div>
                                            <div style="font-size: 11px; color: #64748b; font-weight: 400; margin-top: 1px;">Individual record (Default)</div>
                                        </div>
                                    </div>
                                    <span style="font-size: 10px; color: #2563eb; background: #dbeafe; padding: 2px 6px; border-radius: 4px; font-weight: 700;">.single</span>
                                </button>

                                <!-- Batch Mode Option -->
                                <button type="button" 
                                        wire:click="switchToBatchMode" 
                                        @click="modeOpen = false"
                                        style="width: 100%; text-align: left; padding: 9px 14px; font-size: 12px; font-weight: 600; color: #1e293b; background: {{ $isBatch ? '#ecfdf5' : 'transparent' }}; border: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 10px; border-top: 1px solid #f1f5f9; transition: background 0.1s ease;"
                                        onmouseover="this.style.background='#f1f5f9'" 
                                        onmouseout="this.style.background='{{ $isBatch ? '#ecfdf5' : 'transparent' }}'">
                                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                                        <span style="color: #059669; margin-top: 2px; display: inline-flex;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                            </svg>
                                        </span>
                                        <div>
                                            <div style="font-size: 12.5px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                                                <span>Batch Mode</span>
                                                @if($isBatch)
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="20 6 9 17 4 12"></polyline>
                                                    </svg>
                                                @endif
                                            </div>
                                            <div style="font-size: 11px; color: #64748b; font-weight: 400; margin-top: 1px;">Combined period & volume breakdown</div>
                                        </div>
                                    </div>
                                    <span style="font-size: 10px; color: #059669; background: #d1fae5; padding: 2px 6px; border-radius: 4px; font-weight: 700;">.batch</span>
                                </button>

                                <!-- Multi Mode Option -->
                                <button type="button" 
                                        wire:click="switchToMultiMode" 
                                        @click="modeOpen = false"
                                        style="width: 100%; text-align: left; padding: 9px 14px; font-size: 12px; font-weight: 600; color: #1e293b; background: {{ $isMulti ? '#f5f3ff' : 'transparent' }}; border: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 10px; border-top: 1px solid #f1f5f9; transition: background 0.1s ease;"
                                        onmouseover="this.style.background='#f1f5f9'" 
                                        onmouseout="this.style.background='{{ $isMulti ? '#f5f3ff' : 'transparent' }}'">
                                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                                        <span style="color: #7c3aed; margin-top: 2px; display: inline-flex;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                            </svg>
                                        </span>
                                        <div>
                                            <div style="font-size: 12.5px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                                                <span>Multi Mode</span>
                                                @if($isMulti)
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="20 6 9 17 4 12"></polyline>
                                                    </svg>
                                                @endif
                                            </div>
                                            <div style="font-size: 11px; color: #64748b; font-weight: 400; margin-top: 1px;">Add multiple records under this series</div>
                                        </div>
                                    </div>
                                    <span style="font-size: 10px; color: #7c3aed; background: #ede9fe; padding: 2px 6px; border-radius: 4px; font-weight: 700;">.multi</span>
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

                                <div style="display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 12px; margin-bottom: 12px;">
                                    <div>
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Selected Date *</label>
                                        <input type="date" class="ia-input" wire:model.blur="batchItems.{{ $bIdx }}.date_covered" style="width: 100%; box-sizing: border-box;">
                                    </div>
                                    <div>
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Volume Amount & Unit *</label>
                                        <input type="text" class="ia-input" wire:model="batchItems.{{ $bIdx }}.volume" placeholder="E.G. 1 BOX 10 PAPERS..." style="width: 100%; box-sizing: border-box;">
                                    </div>
                                    <div>
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Records Location *</label>
                                        <input type="text" class="ia-input" wire:model="batchItems.{{ $bIdx }}.records_location" placeholder="E.G. CABINET 3, SHELF 2" style="width: 100%; box-sizing: border-box;">
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
                                                @endphp
                                                <span style="display: inline-flex; align-items: center; gap: 6px; background-color: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 3px 8px; border-radius: 6px; font-size: 11.5px; font-weight: 600;">
                                                    <span><strong>{{ $offCode }}</strong> — {{ $offName }}</span>
                                                    <button type="button" wire:click="removeBatchDuplicateOffice({{ $bIdx }}, {{ $dIdx }})" style="border: none; background: none; color: #1e40af; cursor: pointer; font-weight: 900; font-size: 13px; line-height: 1; padding: 0 2px;">&times;</button>
                                                </span>
                                            @endforeach
                                            <button type="button" wire:click="clearBatchDuplicateOffices({{ $bIdx }})" class="ia-btn ia-btn-secondary" style="padding: 2px 8px; font-size: 10.5px; height: 24px; align-self: center;">Clear</button>
                                        </div>
                                    @endif

                                    <div style="position: relative;">
                                        <input type="text"
                                            class="ia-input"
                                            wire:model.live.debounce.150ms="batchItems.{{ $bIdx }}.duplicate_search"
                                            wire:focus="$set('batchItems.{{ $bIdx }}.showDuplicateDropdown', true)"
                                            wire:keydown.enter.prevent="addBatchDuplicateOffice({{ $bIdx }})"
                                            placeholder="SEARCH OFFICE CODE OR NAME..."
                                            style="width: 100%; box-sizing: border-box; background: #ffffff;">

                                        @if(!empty($bItem['showDuplicateDropdown']) && !empty(trim($bItem['duplicate_search'] ?? '')))
                                            @php
                                                $bSearchLower = strtolower(trim($bItem['duplicate_search']));
                                                $bCurDups = $bItem['duplicate_offices'] ?? [];
                                                $bFilteredOffices = collect($officesList)->filter(function($off) use ($bSearchLower, $bCurDups) {
                                                    return !in_array($off->office_code, $bCurDups, true) &&
                                                           (str_contains(strtolower($off->office_code), $bSearchLower) ||
                                                            str_contains(strtolower($off->office_name), $bSearchLower));
                                                })->take(8);
                                            @endphp

                                            <div class="ia-autocomplete-dropdown" style="top: 100%; left: 0; right: 0; z-index: 1050; max-height: 200px; overflow-y: auto; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); margin-top: 4px; position: absolute;">
                                                @if($bFilteredOffices->isNotEmpty())
                                                    @foreach($bFilteredOffices as $off)
                                                        <div wire:click="addBatchDuplicateOffice({{ $bIdx }}, '{{ $off->office_code }}')"
                                                             class="ia-autocomplete-item"
                                                             style="padding: 7px 12px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #f1f5f9;">
                                                             <div style="display: flex; align-items: center; gap: 8px;">
                                                                <span style="font-size: 10.5px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px;">
                                                                    {{ $off->office_code }}
                                                                </span>
                                                                <span style="font-size: 12.5px; font-weight: 600; color: #1e293b;">
                                                                    {{ $off->office_name }}
                                                                </span>
                                                             </div>
                                                             <span style="font-size: 11px; color: #2563eb; font-weight: 700;">+ Add</span>
                                                        </div>
                                                    @endforeach
                                                @else
                                                    <div style="padding: 10px 12px; color: #64748b; font-size: 12px;">No matching office found</div>
                                                @endif
                                            </div>
                                        @endif
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
                                    LOCKED (From DTS)
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
                            <span class="ia-label ia-label-required">Period Covered / Date Covered</span>
                            <div style="flex: 1; display: flex; align-items: center; gap: 10px;">
                                <input type="date" class="ia-input" wire:model.live="date_covered" style="max-width: 240px; {{ ($showValidationErrors && empty(trim($date_covered))) ? 'border-color: #fca5a5; background: #fff5f5;' : '' }}">
                                @if(!empty($date_covered))
                                    <button type="button" wire:click="$set('date_covered', '')" class="ia-btn ia-btn-secondary" style="padding: 6px 14px; font-size: 12px;">Clear Date</button>
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
                                <option value="" disabled {{ empty($records_medium) ? 'selected' : '' }}>Select Medium...</option>
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
                    <div class="ia-form-row" wire:key="ia-row-location">
                        <span class="ia-label ia-label-required">Records Location</span>
                        <input type="text" class="ia-input" wire:model="records_location" placeholder="E.G. BUILDING A, CABINET 3, SHELF 2" style="{{ ($showValidationErrors && empty(trim($records_location))) ? 'border-color: #fca5a5; background: #fff5f5;' : '' }}">
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
                    @if($isAppraising)
                        <span class="ia-locked-badge">
                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            LOCKED (From DTS)
                        </span>
                    @endif
                </div>
                @if($isAppraising)
                    <div style="flex: 1;">
                        @if(count($duplicate_offices) > 0)
                            <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 4px;">
                                @foreach($duplicate_offices as $offCode)
                                    @php
                                        $offObj = collect($officesList)->firstWhere('office_code', $offCode);
                                        $offName = $offObj->office_name ?? $offCode;
                                    @endphp
                                    <span style="display: inline-flex; align-items: center; gap: 6px; background-color: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        <span><strong>{{ $offCode }}</strong> — {{ $offName }}</span>
                                    </span>
                                @endforeach
                            </div>
                            <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">
                                Assigned from Copy Furnished offices in the DTS transaction.
                            </div>
                        @else
                            <span style="color: #64748b; font-size: 12px; font-style: italic;">No duplicate copies assigned.</span>
                        @endif
                    </div>
                @else
                    <div style="flex: 1; position: relative;" wire:click.outside="$set('showDuplicateDropdown', false)">
                        @if(count($duplicate_offices) > 0)
                            <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px;">
                                @foreach($duplicate_offices as $index => $offCode)
                                    @php
                                        $offObj = collect($officesList)->firstWhere('office_code', $offCode);
                                        $offName = $offObj->office_name ?? $offCode;
                                    @endphp
                                    <span style="display: inline-flex; align-items: center; gap: 6px; background-color: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600;">
                                        <span><strong>{{ $offCode }}</strong> — {{ $offName }}</span>
                                        <button type="button" wire:click="removeDuplicateOffice({{ $index }})" style="border: none; background: none; color: #1e40af; cursor: pointer; font-weight: 900; font-size: 14px; line-height: 1; padding: 0 2px;">&times;</button>
                                    </span>
                                @endforeach
                                <button type="button" wire:click="clearDuplicateOffices" class="ia-btn ia-btn-secondary" style="padding: 2px 10px; font-size: 11px; height: 26px; align-self: center;">Clear</button>
                            </div>
                        @endif

                        <div style="position: relative;">
                            <input type="text"
                                class="ia-input"
                                wire:model.live.debounce.150ms="duplicate_search"
                                wire:focus="$set('showDuplicateDropdown', true)"
                                wire:keydown.enter.prevent="addDuplicateOffice"
                                placeholder="SEARCH OFFICE CODE OR NAME..."
                                style="width: 100%;">

                            @if($showDuplicateDropdown && !empty(trim($duplicate_search)))
                                @php
                                    $searchLower = strtolower(trim($duplicate_search));
                                    $filteredOffices = collect($officesList)->filter(function($off) use ($searchLower, $duplicate_offices) {
                                        return !in_array($off->office_code, $duplicate_offices, true) &&
                                               (str_contains(strtolower($off->office_code), $searchLower) ||
                                                str_contains(strtolower($off->office_name), $searchLower));
                                    })->take(8);
                                @endphp

                                <div class="ia-autocomplete-dropdown" style="top: 100%; left: 0; right: 0; z-index: 1050; max-height: 220px; overflow-y: auto; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); margin-top: 4px;">
                                    @if($filteredOffices->isNotEmpty())
                                        @foreach($filteredOffices as $off)
                                            <div wire:click="addDuplicateOffice('{{ $off->office_code }}')"
                                                 class="ia-autocomplete-item"
                                                 style="padding: 8px 12px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #f1f5f9;">
                                                 <div style="display: flex; align-items: center; gap: 8px;">
                                                    <span style="font-size: 10.5px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px;">
                                                        {{ $off->office_code }}
                                                    </span>
                                                    <span style="font-size: 13px; font-weight: 600; color: #1e293b;">
                                                        {{ $off->office_name }}
                                                    </span>
                                                 </div>
                                                 <span style="font-size: 11px; color: #2563eb; font-weight: 700;">+ Add</span>
                                            </div>
                                        @endforeach
                                    @else
                                        <div style="padding: 10px 14px; font-size: 12px; color: #64748b; font-style: italic;">
                                            No matching active offices found.
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">
                            Selected offices will have visibility to this record.
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
                    <span class="ia-label ia-label-required" style="margin-top: 8px;">Utility Value</span>
                    <div style="flex: 1; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                        @foreach($utilityValuesList as $uv)
                            @php
                                $isChecked = in_array($uv->id, $utility_values);
                                $chipClass = $isChecked ? 'ia-chip-active' : 'ia-chip-default';
                            @endphp
                            <label class="ia-chip {{ $chipClass }}">
                                <input type="checkbox"
                                       wire:model.live="utility_values"
                                       value="{{ $uv->id }}">
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
                <input type="text" class="ia-input" wire:model.live.debounce.500ms="active_period" placeholder="E.G. 6 MONTHS, 1 YEAR" {{ ($is_permanent || $hasPredefinedRetention) ? 'disabled' : '' }}>
            </div>

            <!-- Storage Period -->
            <div class="ia-form-row {{ ($is_permanent || $hasPredefinedRetention) ? 'ia-row-disabled' : '' }}" wire:key="ia-row-storage-period">
                <span class="ia-label">
                    Storage Period
                    @if($hasPredefinedRetention)
                        <span style="font-size: 11px; color: #64748b; font-weight: 500;">(Predefined — Read Only)</span>
                    @endif
                </span>
                <input type="text" class="ia-input" wire:model.live.debounce.500ms="storage_period" placeholder="E.G. 1 YEAR, 4 YEARS" {{ ($is_permanent || $hasPredefinedRetention) ? 'disabled' : '' }}>
            </div>

            <!-- Total Period (Computed) -->
            <div class="ia-form-row {{ ($is_permanent || $hasPredefinedRetention) ? 'ia-row-disabled' : '' }}" wire:key="ia-row-total-period">
                <span class="ia-label">Total Period</span>
                <div class="ia-computed-box">
                    {{ $this->computeTotalPeriod($active_period, $storage_period, $is_permanent) ?: '— (Auto-calculated)' }}
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
                <textarea class="ia-input" wire:model="disposition_provision" rows="3" placeholder="Enter remarks or disposition instructions..." style="font-family: inherit; text-transform: none !important;" {{ $hasPredefinedRemarks ? 'disabled' : '' }}></textarea>
            </div>
        </div>

        <!-- ═══════ ACTIONS BAR ═══════ -->
        <div class="ia-actions-bar">
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

            <button type="button" wire:click="resetFormFields" onclick="rdpScrollToTop()" class="ia-btn ia-btn-secondary">CLEAR FORM</button>
            <button type="button" wire:click="saveDraft" onclick="rdpScrollToTop()" class="ia-btn ia-btn-secondary">
                {{ $entryMode === 'multi' && count($batchItems) > 1 ? 'SAVE DRAFT (' . count($batchItems) . ' RECORDS)' : 'SAVE DRAFT' }}
            </button>
            <button type="button" wire:click="createRecord" onclick="rdpScrollToTop()" class="ia-btn ia-btn-primary ia-btn-lg">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                {{ $entryMode === 'multi' && count($batchItems) > 1 ? 'CREATE ' . count($batchItems) . ' RECORDS' : 'CREATE RECORD' }}
            </button>
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

                <div class="ia-modal-footer">
                    <button type="button" wire:click="closeBatchModal" class="ia-btn ia-btn-secondary">Close</button>
                    <button type="button" wire:click="applyBatchModal" class="ia-btn ia-btn-primary">
                        Apply & Save Breakdown
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>