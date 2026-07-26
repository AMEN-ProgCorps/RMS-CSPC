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

    // Predefined vs Custom Series Flag
    public bool $isCustomSeries = false;

    // Period Covered Modal & Staged State (Deferred DB save)
    public bool $showPeriodModal = false;
    public array $periodsCovered = [];

    // Form Input Properties
    public string $description = '';
    public ?float $volume_amount = null;
    public mixed $volume_unit = null;
    public string $volume = '';
    public ?int $records_medium = null;
    public ?string $restriction = null;
    public string $records_location = '';
    public ?string $frequence_use = null;
    public ?string $duplication = null;
    public ?string $time_value = null;
    public ?int $utility_value = null;
    public string $retention_period = '';
    public bool $is_permanent = false;
    public string $active_period = '';
    public string $storage_period = '';
    public string $disposition_provision = '';
    
    public $uploadedFile = null;
    public ?string $successMessage = null;
    public ?string $errorMessage = null;

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

    public function updatedTimeValue($val): void
    {
        if ($val === 'P') {
            $this->is_permanent = true;
            $this->active_period = '';
            $this->storage_period = '';
            $this->retention_period = 'Permanent';

            $archivalId = DB::table('rdp_utility_medium')
                ->where('utility_name', 'like', '%Archival%')
                ->value('id');

            if ($archivalId) {
                $this->utility_value = $archivalId;
            }
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

            $archivalId = DB::table('rdp_utility_medium')
                ->where('utility_name', 'like', '%Archival%')
                ->value('id');

            if ($archivalId) {
                $this->utility_value = $archivalId;
            }
        } else {
            $this->time_value = 'T';
            $this->retention_period = $this->computeTotalPeriod($this->active_period, $this->storage_period, false);
        }
    }

    public function mount(): void
    {
        // Default volume_unit to Pages if available
        $defaultUnit = DB::table('rdp_volume_value')->whereRaw('LOWER(value_standard) = ?', ['pages'])->value('volume_id');
        if ($defaultUnit) {
            $this->volume_unit = $defaultUnit;
        }
    }

    // --- Record Series Modal Handlers ---
    public function openSeriesModal(): void
    {
        $this->showSeriesModal = true;
    }

    public function closeSeriesModal(): void
    {
        $this->showSeriesModal = false;
        $this->showParentDropdown = false;
        $this->activeSubDropdownIndex = null;
    }

    public function getSubSuggestions(int $index): \Illuminate\Support\Collection
    {
        $parentTitle = ($index === 0) ? trim($this->parentSeriesTitle) : trim($this->subsections[$index - 1] ?? '');
        if (empty($parentTitle)) {
            return collect();
        }

        $query = DB::table('rdp_record_series');

        $parentRecord = DB::table('rdp_record_series')
            ->where('series_title', $parentTitle)
            ->first();

        if ($parentRecord) {
            $query->where('parent_id', $parentRecord->id);
        } else {
            return collect();
        }

        $currentSubInput = trim($this->subsections[$index] ?? '');
        if (!empty($currentSubInput)) {
            $query->where('series_title', 'ilike', '%' . $currentSubInput . '%');
        }

        return $query->select('series_title')->distinct()->limit(10)->get();
    }

    public function selectParentSuggestion(string $title): void
    {
        $this->parentSeriesTitle = $title;
        $this->showParentDropdown = false;
    }

    public function selectSubSuggestion(int $index, string $title): void
    {
        if (isset($this->subsections[$index])) {
            $this->subsections[$index] = $title;
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

    /**
     * Stage Record Series in memory without direct DB write.
     * Evaluates whether selected series is predefined or custom, binding retention parameters.
     */
    public function saveNewRecordSeries(): void
    {
        $this->validate([
            'parentSeriesTitle' => 'required|string|max:255',
        ]);

        $fullPathTitles = [trim($this->parentSeriesTitle)];

        foreach ($this->subsections as $subTitle) {
            $trimmed = trim($subTitle);
            if (!empty($trimmed)) {
                $fullPathTitles[] = $trimmed;
            }
        }

        $this->selectedSeriesTitle = implode(' ➔ ', $fullPathTitles);
        $leafTitle = end($fullPathTitles);

        // Check if the selected series is predefined in database
        $foundSeries = DB::table('rdp_record_series')
            ->where('series_title', $leafTitle)
            ->first();

        if ($foundSeries) {
            $this->isCustomSeries = false;

            $retention = null;
            if ($foundSeries->retention_period) {
                $retention = DB::table('rdp_retention_period')
                    ->where('id', $foundSeries->retention_period)
                    ->first();
            }

            $isPermFlag = (bool)($foundSeries->is_retention_period_permanent ?? false);
            $isActivePermanent = $retention && strtolower($retention->active_period ?? '') === 'permanent';
            $isTotalPermanent  = $retention && strtolower($retention->total_period ?? '') === 'permanent';
            $isTitlePermanent  = str_contains(strtolower($leafTitle), 'permanent');

            if ($isPermFlag || $isActivePermanent || $isTotalPermanent || $isTitlePermanent) {
                // Permanent retention binding: Auto-set Retention, Time Value (Permanent), and Utility Value (Archival)
                $this->is_permanent = true;
                $this->active_period = '';
                $this->storage_period = '';
                $this->retention_period = 'Permanent';
                $this->time_value = 'P';

                $archivalId = DB::table('rdp_utility_medium')
                    ->where('utility_name', 'like', '%Archival%')
                    ->value('id');

                if ($archivalId) {
                    $this->utility_value = $archivalId;
                }

                if ($foundSeries->remarks) {
                    $this->disposition_provision = $foundSeries->remarks;
                }
            } else {
                // Predefined non-permanent retention binding: Auto-set active/storage period & Temporary Time Value
                $this->is_permanent = false;
                $this->active_period = $retention->active_period ?? '';
                $this->storage_period = $retention->storage_period ?? '';
                $this->retention_period = $this->computeTotalPeriod($this->active_period, $this->storage_period, false);
                $this->time_value = 'T';

                if ($foundSeries->remarks) {
                    $this->disposition_provision = $foundSeries->remarks;
                }
            }
        } else {
            // Non-predefined / Custom Record Series
            $this->isCustomSeries = true;
        }

        $this->showSeriesModal = false;
        $this->showParentDropdown = false;
        $this->activeSubDropdownIndex = null;
    }

    // --- Period Covered Modal Handlers ---
    public function openPeriodModal(): void
    {
        if (empty($this->periodsCovered)) {
            $this->periodsCovered = [
                ['start_day' => '', 'start_month' => date('Y-01'), 'end_day' => '', 'end_month' => date('Y-12')]
            ];
        }
        $this->showPeriodModal = true;
    }

    public function closePeriodModal(): void
    {
        $this->showPeriodModal = false;
    }

    public function addPeriodRange(): void
    {
        $this->periodsCovered[] = [
            'start_day'   => '',
            'start_month' => '',
            'end_day'     => '',
            'end_month'   => ''
        ];
    }

    public function removePeriodRange(int $index): void
    {
        if (isset($this->periodsCovered[$index])) {
            unset($this->periodsCovered[$index]);
            $this->periodsCovered = array_values($this->periodsCovered);
        }
    }

    /**
     * Stage Period Covered in memory without direct DB write.
     */
    public function savePeriods(): void
    {
        $this->periodsCovered = array_values(array_filter($this->periodsCovered, function ($p) {
            return !empty($p['start_month']) || !empty($p['end_month']);
        }));

        $this->showPeriodModal = false;
    }

    public function getFormattedPeriodsProperty(): array
    {
        $formatted = [];
        foreach ($this->periodsCovered as $p) {
            $startStr = '';
            $endStr = '';

            $startDay = !empty($p['start_day']) ? (int)$p['start_day'] : null;
            $endDay = !empty($p['end_day']) ? (int)$p['end_day'] : null;

            if (!empty($p['start_month'])) {
                try {
                    $dt = Carbon::createFromFormat('Y-m', $p['start_month']);
                    if ($startDay && $startDay >= 1 && $startDay <= $dt->daysInMonth) {
                        $startStr = $dt->format('M') . ' ' . $startDay . ', ' . $dt->format('Y');
                    } else {
                        $startStr = $dt->format('M Y');
                    }
                } catch (\Exception $e) {
                    $startStr = $p['start_month'];
                }
            }

            if (!empty($p['end_month'])) {
                try {
                    $dt = Carbon::createFromFormat('Y-m', $p['end_month']);
                    if ($endDay && $endDay >= 1 && $endDay <= $dt->daysInMonth) {
                        $endStr = $dt->format('M') . ' ' . $endDay . ', ' . $dt->format('Y');
                    } else {
                        $endStr = $dt->format('M Y');
                    }
                } catch (\Exception $e) {
                    $endStr = $p['end_month'];
                }
            }

            if ($startStr && $endStr) {
                $formatted[] = $startStr . ' - ' . $endStr;
            } elseif ($startStr) {
                $formatted[] = $startStr;
            } elseif ($endStr) {
                $formatted[] = $endStr;
            }
        }
        return $formatted;
    }

    /**
     * Volume conversion calculator consuming rdp_volume_conversion & rdp_volume_value tables.
     */
    public function calculateFormattedVolume(): string
    {
        if (!$this->volume_amount || $this->volume_amount <= 0 || !$this->volume_unit) {
            return '';
        }

        $amount  = floatval($this->volume_amount);
        $unitVal = $this->volume_unit;

        // Query active conversion rule where value_standard matches volume_unit
        $rule = DB::table('rdp_volume_conversion')
            ->join('rdp_volume_value as std', 'rdp_volume_conversion.value_standard', '=', 'std.volume_id')
            ->join('rdp_volume_value as conv', 'rdp_volume_conversion.value_converted', '=', 'conv.volume_id')
            ->select([
                'rdp_volume_conversion.amount_standard',
                'rdp_volume_conversion.amount_converted',
                'std.value_standard as std_name',
                'conv.value_standard as conv_name',
            ])
            ->where('rdp_volume_conversion.is_active', true)
            ->where(function ($q) use ($unitVal) {
                if (is_numeric($unitVal)) {
                    $q->where('std.volume_id', $unitVal);
                } else {
                    $q->whereRaw('LOWER(std.value_standard) = ?', [strtolower(trim($unitVal))]);
                }
            })
            ->first();

        if ($rule && $rule->amount_standard > 0) {
            $fromAmount = floatval($rule->amount_standard);
            $toAmount   = floatval($rule->amount_converted ?: 1);

            $fullUnits = floor($amount / $fromAmount) * $toAmount;
            $remainder = fmod($amount, $fromAmount);

            $targetUnitStr = $rule->conv_name;
            $sourceUnitStr = $rule->std_name;

            if ($fullUnits > 0 && $remainder > 0) {
                return "{$fullUnits} " . Str::plural($targetUnitStr, $fullUnits) . ", {$remainder} " . Str::plural($sourceUnitStr, $remainder);
            } elseif ($fullUnits > 0 && $remainder == 0) {
                return "{$fullUnits} " . Str::plural($targetUnitStr, $fullUnits);
            }
        }

        // Fallback if no conversion rule found
        $sourceUnitName = is_numeric($unitVal) 
            ? (DB::table('rdp_volume_value')->where('volume_id', $unitVal)->value('value_standard') ?: 'Unit') 
            : $unitVal;

        return "{$amount} " . Str::plural($sourceUnitName, $amount);
    }

    public function with(): array
    {
        $parentQuery = DB::table('rdp_record_series')->whereNull('parent_id');
        if (!empty(trim($this->parentSeriesTitle))) {
            $parentQuery->where('series_title', 'ilike', '%' . trim($this->parentSeriesTitle) . '%');
        }
        $parentSuggestions = $parentQuery->select('series_title')->distinct()->orderBy('series_title', 'asc')->limit(10)->get();

        $allSeriesSuggestions = DB::table('rdp_record_series')
            ->select('series_title')
            ->distinct()
            ->orderBy('series_title', 'asc')
            ->limit(10)
            ->get();

        $availableUnits = DB::table('rdp_volume_value')
            ->where('is_active', true)
            ->orderBy('value_standard', 'asc')
            ->get();

        return [
            'parentSuggestions'    => $parentSuggestions,
            'allSeriesSuggestions' => $allSeriesSuggestions,
            'availableUnits'       => $availableUnits,
            'mediaList'            => DB::table('rdp_recorded_value')->orderBy('medium_name', 'asc')->get(),
            'restrictionsList'     => DB::table('rdp_restriction_type')->orderBy('restriction_value', 'asc')->get(),
            'frequenciesList'      => DB::table('rdp_frequence_use')->orderBy('freq_type', 'asc')->get(),
            'timeValuesList'       => DB::table('rdp_time_value')->orderBy('char_value', 'asc')->get(),
            'utilityValuesList'    => DB::table('rdp_utility_medium')->orderBy('utility_name', 'asc')->get(),
        ];
    }

    public function createRecord(): void
    {
        if (empty($this->selectedSeriesTitle)) {
            $this->errorMessage = 'Please select or configure a Record Series first.';
            return;
        }

        $requiredUpload = DB::table('system_settings')->where('key', 'rdp_required_upload_file')->value('value') === 'true';
        if ($requiredUpload && !$this->uploadedFile) {
            $this->errorMessage = 'Uploading a file is required to create a record according to system settings.';
            return;
        }

        $this->validate([
            'description'      => 'nullable|string',
            'records_medium'   => 'nullable|integer',
            'restriction'      => 'nullable|string',
            'records_location' => 'nullable|string',
            'frequence_use'    => 'nullable|string',
            'time_value'       => 'nullable|string',
            'utility_value'    => 'nullable|integer',
            'uploadedFile'     => 'nullable|file|max:20480',
        ]);

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOfficeCode = $user?->details?->office?->office_code;
            $documentIdHandler = null;

            // Compute converted volume
            $this->volume = $this->calculateFormattedVolume();

            // 1. Save Staged Record Series to DB only on form submission
            $titles = explode(' ➔ ', $this->selectedSeriesTitle);
            $lastSeriesId = null;

            foreach ($titles as $idx => $title) {
                $trimmed = trim($title);
                $existing = DB::table('rdp_record_series')
                    ->where('series_title', $trimmed)
                    ->where('parent_id', $lastSeriesId)
                    ->first();

                if ($existing) {
                    $lastSeriesId = $existing->id;
                } else {
                    $lastSeriesId = DB::table('rdp_record_series')->insertGetId([
                        'series_title'       => $trimmed,
                        'parent_id'          => $lastSeriesId,
                        'recorded_at_office' => $userOfficeCode,
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);
                }
            }

            $this->record_series_id = $lastSeriesId;

            // 2. File Upload Handling via DocumentStorageService (Organized Google Drive + Local Cache)
            if ($this->uploadedFile) {
                $uploadResult = \App\Services\DocumentStorageService::storeUpload(
                    $this->uploadedFile,
                    'RDP',
                    $user
                );
                $documentIdHandler = $uploadResult['document_id'];
            }

            // 3. Retention Period
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

            // 4. Create Main Record
            $recordId = DB::table('rdp_record')->insertGetId([
                'record_series_id'       => $this->record_series_id,
                'description'            => $this->description,
                'period_id'              => $periodId,
                'volume'                 => $this->volume,
                'records_location'       => $this->records_location,
                'restriction'            => $this->restriction,
                'records_medium'         => $this->records_medium,
                'time_value'             => $this->time_value,
                'frequence_use'          => $this->frequence_use,
                'utility_value'          => $this->utility_value,
                'user_own'               => $user?->id,
                'office_own'             => $userOfficeCode,
                'upload_doc_id_handler'  => $documentIdHandler,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            // 5. Period Covered - Saved to DB only on form submission
            foreach ($this->periodsCovered as $p) {
                $startAt = null;
                $endsAt = null;

                $startDay = !empty($p['start_day']) ? (int)$p['start_day'] : null;
                $endDay = !empty($p['end_day']) ? (int)$p['end_day'] : null;

                if (!empty($p['start_month'])) {
                    try {
                        $dt = Carbon::createFromFormat('Y-m', $p['start_month']);
                        if ($startDay && $startDay >= 1 && $startDay <= $dt->daysInMonth) {
                            $dt->day($startDay);
                        } else {
                            $dt->startOfMonth();
                        }
                        $startAt = $dt->startOfDay()->toDateTimeString();
                    } catch (\Exception $e) {
                        $startAt = null;
                    }
                }

                if (!empty($p['end_month'])) {
                    try {
                        $dt = Carbon::createFromFormat('Y-m', $p['end_month']);
                        if ($endDay && $endDay >= 1 && $endDay <= $dt->daysInMonth) {
                            $dt->day($endDay);
                        } else {
                            $dt->endOfMonth();
                        }
                        $endsAt = $dt->endOfDay()->toDateTimeString();
                    } catch (\Exception $e) {
                        $endsAt = null;
                    }
                }

                if ($startAt || $endsAt) {
                    DB::table('rdp_period_covered')->insert([
                        'period_owner' => $recordId,
                        'start_at'     => $startAt,
                        'ends_at'      => $endsAt,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }
            }

            DB::commit();

            $this->successMessage = 'Record created successfully!';
            $this->reset(['description', 'volume', 'volume_amount', 'uploadedFile', 'retention_period', 'active_period', 'storage_period', 'is_permanent', 'disposition_provision', 'record_series_id', 'selectedSeriesTitle', 'parentSeriesTitle', 'subsections', 'periodsCovered', 'isCustomSeries']);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Error creating record: ' . $e->getMessage();
        }
    }

    public function saveDraft(): void
    {
        $requiredUpload = DB::table('system_settings')->where('key', 'rdp_required_upload_file')->value('value') === 'true';
        if ($requiredUpload && !$this->uploadedFile) {
            $this->errorMessage = 'Uploading a file is required to save a draft according to system settings.';
            return;
        }

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOfficeCode = $user?->details?->office?->office_code;

            // Compute converted volume
            $this->volume = $this->calculateFormattedVolume();

            // Resolve staged Record Series if present
            $seriesId = 1;
            if (!empty($this->selectedSeriesTitle)) {
                $titles = explode(' ➔ ', $this->selectedSeriesTitle);
                $lastSeriesId = null;

                foreach ($titles as $title) {
                    $trimmed = trim($title);
                    $existing = DB::table('rdp_record_series')
                        ->where('series_title', $trimmed)
                        ->where('parent_id', $lastSeriesId)
                        ->first();

                    if ($existing) {
                        $lastSeriesId = $existing->id;
                    } else {
                        $lastSeriesId = DB::table('rdp_record_series')->insertGetId([
                            'series_title'       => $trimmed,
                            'parent_id'          => $lastSeriesId,
                            'recorded_at_office' => $userOfficeCode,
                            'created_at'         => now(),
                            'updated_at'         => now(),
                        ]);
                    }
                }
                $seriesId = $lastSeriesId;
            }

            $recordId = DB::table('rdp_record')->insertGetId([
                'record_series_id'       => $seriesId,
                'description'            => '[DRAFT] ' . $this->description,
                'volume'                 => $this->volume,
                'records_location'       => $this->records_location,
                'restriction'            => $this->restriction,
                'records_medium'         => $this->records_medium,
                'time_value'             => $this->time_value,
                'frequence_use'          => $this->frequence_use,
                'utility_value'          => $this->utility_value,
                'user_own'               => $user?->id,
                'office_own'             => $userOfficeCode,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            // Save Period Covered for draft
            foreach ($this->periodsCovered as $p) {
                $startAt = null;
                $endsAt = null;

                $startDay = !empty($p['start_day']) ? (int)$p['start_day'] : null;
                $endDay = !empty($p['end_day']) ? (int)$p['end_day'] : null;

                if (!empty($p['start_month'])) {
                    try {
                        $dt = Carbon::createFromFormat('Y-m', $p['start_month']);
                        if ($startDay && $startDay >= 1 && $startDay <= $dt->daysInMonth) {
                            $dt->day($startDay);
                        } else {
                            $dt->startOfMonth();
                        }
                        $startAt = $dt->startOfDay()->toDateTimeString();
                    } catch (\Exception $e) {
                        $startAt = null;
                    }
                }

                if (!empty($p['end_month'])) {
                    try {
                        $dt = Carbon::createFromFormat('Y-m', $p['end_month']);
                        if ($endDay && $endDay >= 1 && $endDay <= $dt->daysInMonth) {
                            $dt->day($endDay);
                        } else {
                            $dt->endOfMonth();
                        }
                        $endsAt = $dt->endOfDay()->toDateTimeString();
                    } catch (\Exception $e) {
                        $endsAt = null;
                    }
                }

                if ($startAt || $endsAt) {
                    DB::table('rdp_period_covered')->insert([
                        'period_owner' => $recordId,
                        'start_at'     => $startAt,
                        'ends_at'      => $endsAt,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }
            }

            DB::commit();

            $this->successMessage = 'Draft saved successfully!';
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Error saving draft: ' . $e->getMessage();
        }
    }
};
?>

@push('styles')
    @vite(['resources/css/rdp/inventory-and-appraisal.css'])
@endpush

<div class="rms-container">
    <style>
        .form-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
        }
        .form-row-flex {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .form-label-bold {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            min-width: 160px;
        }
        .form-input-custom {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
            background: #ffffff;
        }
        .form-input-custom:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .btn-set-inline {
            padding: 8px 22px !important;
            font-weight: 700 !important;
            letter-spacing: 0.5px !important;
            border-radius: 8px !important;
        }
        
        /* White Edit Button with Pencil Icon */
        .btn-edit-series {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 16px;
            background: #ffffff;
            color: #2563eb;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            transition: all 0.2s ease;
        }
        .btn-edit-series:hover {
            border-color: #2563eb;
            background: #f8fafc;
            color: #1d4ed8;
            box-shadow: 0 4px 8px rgba(37, 99, 235, 0.1);
        }
        .btn-edit-series svg {
            width: 15px;
            height: 15px;
            fill: #2563eb;
        }

        .selected-series-badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            background-color: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        /* Series & Period Modal Overlay */
        .series-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(4px);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .series-modal-container {
            background: #ffffff;
            width: 100%;
            max-width: 640px;
            border-radius: 14px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            overflow: visible;
            border: 1px solid #e2e8f0;
        }
        .series-modal-header {
            padding: 18px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .series-modal-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .close-modal-btn {
            background: transparent;
            border: none;
            font-size: 20px;
            color: #64748b;
            cursor: pointer;
        }
        .series-modal-body {
            padding: 24px;
        }
        .form-group-modal {
            margin-bottom: 16px;
            position: relative;
        }
        .form-group-modal label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }
        .modal-input-style {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            background: #fff;
        }
        .modal-input-style:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .dts-autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            max-height: 180px;
            overflow-y: auto;
            z-index: 100;
            margin-top: 4px;
        }
        .dts-autocomplete-item {
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
        }
        .dts-autocomplete-item:last-child {
            border-bottom: none;
        }
        .dts-autocomplete-item:hover {
            background-color: #eff6ff;
            color: #2563eb;
        }

        .btn-add-subsection {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-add-subsection:hover {
            background: #dbeafe;
        }
        .btn-remove-sub {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
            border-radius: 8px;
            width: 36px;
            height: 38px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-remove-sub:hover {
            background: #fca5a5;
        }
        .modal-actions-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid #f1f5f9;
        }
        .btn-secondary-modal {
            padding: 10px 20px;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-secondary-modal:hover {
            background: #e2e8f0;
        }
        .actions-bar {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        .period-range-row {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 12px;
        }
    </style>

    <!-- Header Section -->
    <div class="rms-header">
        <h2>Add Record (Inventory & Appraisal)</h2>
    </div>

    @if($successMessage)
        <div style="padding: 12px 16px; background-color: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; border-radius: 8px; margin-bottom: 16px; font-weight: 600;">
            {{ $successMessage }}
        </div>
    @endif

    @if($errorMessage)
        <div style="padding: 12px 16px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; border-radius: 8px; margin-bottom: 16px; font-weight: 600;">
            {{ $errorMessage }}
        </div>
    @endif

    @if($isCustomSeries)
        <div style="padding: 14px 18px; background-color: #fffbeb; color: #b45309; border: 1px solid #fde68a; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; font-weight: 600; font-size: 14px; box-shadow: 0 2px 6px rgba(180,83,9,0.05);">
            <svg style="width: 22px; height: 22px; fill: #b45309; flex-shrink: 0;" viewBox="0 0 24 24"><path d="M12 2L1 21h22L12 2zm1 14h-2v-2h2v2zm0-4h-2V10h2v2z"/></svg>
            <span>Notice: You inputted a non-predefined Record Series. Please set the Retention Period and its Remarks.</span>
        </div>
    @endif

    <div class="form-card">
        <!-- ===== Record Series Row ===== -->
        <div class="form-row-flex" style="align-items: center;">
            <span class="form-label-bold">Record Series:</span>
            
            @if($selectedSeriesTitle)
                <span class="selected-series-badge">
                    {{ $selectedSeriesTitle }}
                </span>
                <button type="button" class="btn-edit-series" wire:click="openSeriesModal">
                    <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                    <span>EDIT</span>
                </button>
            @else
                <button type="button" class="btn btn-primary btn-set-inline" wire:click="openSeriesModal">
                    SET
                </button>
            @endif
        </div>

        <!-- ===== Description ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Description:</span>
            <input type="text" class="form-input-custom" wire:model="description" placeholder="Enter detailed description...">
        </div>

        <!-- ===== Period Covered / Inclusive Dates ===== -->
        <div class="form-row-flex" style="align-items: center;">
            <span class="form-label-bold">Period Covered:</span>
            
            @if(count($this->formattedPeriods) > 0)
                <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                    @foreach($this->formattedPeriods as $periodLabel)
                        <span class="selected-series-badge">
                            {{ $periodLabel }}
                        </span>
                    @endforeach
                    <button type="button" class="btn-edit-series" wire:click="openPeriodModal">
                        <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                        <span>EDIT</span>
                    </button>
                </div>
            @else
                <button type="button" class="btn btn-primary btn-set-inline" wire:click="openPeriodModal">
                    ADD
                </button>
            @endif
        </div>

        <!-- ===== Volume (Amount + Unit with Live Conversion Preview) ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Volume:</span>
            <div style="display:flex; gap:10px; align-items:center; flex:1; flex-wrap:wrap;">
                <input type="number" step="any" min="0" class="form-input-custom" wire:model.live="volume_amount" placeholder="Amount (e.g. 270)" style="max-width:180px;">
                
                <select class="form-input-custom" wire:model.live="volume_unit" style="max-width:180px;">
                    <option value="" disabled>Select Unit...</option>
                    @foreach($availableUnits as $unitItem)
                        <option value="{{ $unitItem->volume_id }}">{{ $unitItem->value_standard }}</option>
                    @endforeach
                </select>

                @if($volume_amount && $this->calculateFormattedVolume())
                    <span style="font-size:13px; font-weight:700; color:#2563eb; background:#eff6ff; padding:6px 14px; border-radius:8px; border:1px solid #bfdbfe; display:inline-flex; align-items:center; gap:6px;">
                        <span>Converted:</span>
                        <strong style="color:#1d4ed8;">{{ $this->calculateFormattedVolume() }}</strong>
                    </span>
                @endif
            </div>
        </div>

        <!-- ===== Records Medium ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Records Medium:</span>
            <select class="form-input-custom" wire:model="records_medium">
                <option value="" selected disabled>Select Records Medium...</option>
                @foreach($mediaList as $media)
                    <option value="{{ $media->id }}">{{ $media->medium_name }}</option>
                @endforeach
            </select>
        </div>

        <!-- ===== Restrictions ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Restriction/s:</span>
            <select class="form-input-custom" wire:model="restriction">
                <option value="" selected disabled>Select Restriction...</option>
                @foreach($restrictionsList as $res)
                    <option value="{{ $res->restriction_value }}">{{ $res->restriction_value }}</option>
                @endforeach
            </select>
        </div>

        <!-- ===== Location of Records ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Location of Records:</span>
            <input type="text" class="form-input-custom" wire:model="records_location" placeholder="e.g. On-site Storage Room 3B, Shelf 12">
        </div>

        <!-- ===== Frequency of Use ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Frequency of Use:</span>
            <select class="form-input-custom" wire:model="frequence_use">
                <option value="" selected disabled>Select Frequency of Use...</option>
                @foreach($frequenciesList as $freq)
                    <option value="{{ $freq->freq_type }}">{{ $freq->freq_type }}</option>
                @endforeach
            </select>
        </div>

        @php
            $isPredefinedSelected = (!$isCustomSeries && !empty($selectedSeriesTitle));
            $disableTimeValue     = $isPredefinedSelected || $is_permanent;
            $disableUtilityValue  = ($isPredefinedSelected && $is_permanent) || $is_permanent || $time_value === 'P';
            $disablePermanentCb   = $isPredefinedSelected || ($isCustomSeries && $time_value === 'T');
            $disablePeriods       = $isPredefinedSelected || $is_permanent || $time_value === 'P';
        @endphp

        <!-- ===== Time Value ===== -->
        <div class="form-row-flex" style="{{ $disableTimeValue ? 'opacity: 0.4; pointer-events: none; user-select: none; transition: all 0.2s;' : 'transition: all 0.2s;' }}">
            <span class="form-label-bold">Time Value:</span>
            <select class="form-input-custom" wire:model.live="time_value" {{ $disableTimeValue ? 'disabled' : '' }}>
                <option value="" selected disabled>Select Time Value...</option>
                @foreach($timeValuesList as $tv)
                    <option value="{{ $tv->char_value }}">{{ $tv->char_value }} - {{ $tv->description }}</option>
                @endforeach
            </select>
        </div>

        <!-- ===== Utility Value ===== -->
        <div class="form-row-flex" style="{{ $disableUtilityValue ? 'opacity: 0.4; pointer-events: none; user-select: none; transition: all 0.2s;' : 'transition: all 0.2s;' }}">
            <span class="form-label-bold">Utility Value:</span>
            <select class="form-input-custom" wire:model.live="utility_value" {{ $disableUtilityValue ? 'disabled' : '' }}>
                <option value="" selected disabled>Select Utility Value...</option>
                @foreach($utilityValuesList as $uv)
                    <option value="{{ $uv->id }}">{{ $uv->utility_name }} ({{ $uv->description }})</option>
                @endforeach
            </select>
        </div>

        <!-- ===== Retention Period ===== -->
        <div class="form-row-flex" style="{{ $disablePermanentCb ? 'opacity: 0.4; pointer-events: none; user-select: none; transition: all 0.2s;' : 'transition: all 0.2s;' }}">
            <span class="form-label-bold">Permanent Record:</span>
            <div style="flex: 1; display: flex; align-items: center; gap: 8px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: #1e40af; cursor: {{ $disablePermanentCb ? 'not-allowed' : 'pointer' }};">
                    <input type="checkbox" wire:model.live="is_permanent" {{ $disablePermanentCb ? 'disabled' : '' }} style="width: 18px; height: 18px; cursor: pointer; accent-color: #2563eb;">
                    Permanent Record Series
                </label>
                <span style="font-size: 12.5px; color: #64748b; margin-left: 8px;">(Disables period inputs and sets retention to Permanent)</span>
            </div>
        </div>

        <div class="form-row-flex" style="{{ $disablePeriods ? 'opacity: 0.4; pointer-events: none; user-select: none; transition: all 0.2s;' : 'transition: all 0.2s;' }}">
            <span class="form-label-bold">Active Period:</span>
            <input type="text" class="form-input-custom" wire:model.live.debounce.200ms="active_period" placeholder="e.g. 6 Months, 1 Year, 3 Years" {{ $disablePeriods ? 'disabled' : '' }}>
        </div>

        <div class="form-row-flex" style="{{ $disablePeriods ? 'opacity: 0.4; pointer-events: none; user-select: none; transition: all 0.2s;' : 'transition: all 0.2s;' }}">
            <span class="form-label-bold">Storage Period:</span>
            <input type="text" class="form-input-custom" wire:model.live.debounce.200ms="storage_period" placeholder="e.g. 1 Year, 4 Years, 2 Years" {{ $disablePeriods ? 'disabled' : '' }}>
        </div>

        <div class="form-row-flex" style="{{ $disablePeriods ? 'opacity: 0.4; pointer-events: none; user-select: none;' : '' }}">
            <span class="form-label-bold">Total Period:</span>
            <div style="flex: 1; padding: 10px 14px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 700; font-size: 14px; color: #1e40af;">
                {{ $this->computeTotalPeriod($active_period, $storage_period, $is_permanent) ?: '— (Auto-calculated from Active & Storage)' }}
            </div>
        </div>

        <!-- ===== Remarks / Disposition Provision ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Remarks:</span>
            <input type="text" class="form-input-custom" wire:model="disposition_provision" placeholder="Enter disposition instructions or remarks...">
        </div>

        <!-- Action Buttons Bar -->
        <div class="actions-bar" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <input type="file" id="rdp-file-input" wire:model="uploadedFile" style="display: none;">
            
            <div style="display: flex; align-items: center; gap: 6px;">
                <label for="rdp-file-input" class="btn" style="cursor: pointer; display: inline-flex; align-items: center; gap: 8px; margin: 0; padding: 10px 18px; background: {{ $uploadedFile ? '#16a34a' : '#475569' }}; color: #ffffff; border-radius: 6px; font-weight: 700; font-size: 13px; transition: all 0.2s;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    @if($uploadedFile)
                        <span>File: {{ $uploadedFile->getClientOriginalName() }}</span>
                    @else
                        <span>UPLOAD FILE</span>
                    @endif
                </label>

                @if($uploadedFile)
                    <button type="button" wire:click="$set('uploadedFile', null)" title="Remove attached file" style="background: #ef4444; color: white; border: none; border-radius: 6px; padding: 10px 12px; cursor: pointer; font-size: 13px; font-weight: 700;">
                        ✕
                    </button>
                @endif
            </div>

            <button type="button" class="btn btn-primary" wire:click="saveDraft">
                SAVE DRAFT
            </button>

            <button type="button" class="btn btn-primary" wire:click="createRecord">
                CREATE RECORD
            </button>

            <div wire:loading wire:target="uploadedFile" style="font-size: 12px; color: #2563eb; font-weight: 600; width: 100%;">
                Uploading file...
            </div>
        </div>
    </div>

    <!-- SELECT RECORD SERIES HIERARCHY MODAL CONTAINER -->
    @if($showSeriesModal)
        <div class="series-modal-overlay">
            <div class="series-modal-container">
                <div class="series-modal-header">
                    <h3>Select Record Series</h3>
                    <button type="button" class="close-modal-btn" wire:click="closeSeriesModal">&times;</button>
                </div>
                <div class="series-modal-body">
                    <!-- Main Parent Input with DTS-style Dropdown -->
                    <div class="form-group-modal" wire:click.outside="$set('showParentDropdown', false)">
                        <label for="parent">Parent Series Title:</label>
                        <input type="text" 
                               id="parent" 
                               class="modal-input-style" 
                               wire:model.live="parentSeriesTitle" 
                               wire:focus="$set('showParentDropdown', true)" 
                               placeholder="e.g. Receipts" 
                               autocomplete="off">

                        @if($showParentDropdown && count($parentSuggestions) > 0)
                            <div class="dts-autocomplete-dropdown">
                                @foreach($parentSuggestions as $suggestion)
                                    <div class="dts-autocomplete-item" wire:click="selectParentSuggestion('{{ addslashes($suggestion->series_title) }}')">
                                        {{ $suggestion->series_title }}
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <!-- Dynamic Subsections with DTS-style Cascading Autocomplete Dropdowns -->
                    @foreach($subsections as $index => $sub)
                        <div class="form-group-modal" 
                             wire:click.outside="$set('activeSubDropdownIndex', null)"
                             style="margin-left: {{ min(($index + 1) * 16, 64) }}px; border-left: 3px solid #2563eb; padding-left: 12px; margin-bottom: 14px; position: relative;">
                            <label style="font-size: 11px; color: #2563eb;">
                                Subsection #{{ $index + 1 }} (Child of {{ $index === 0 ? ($parentSeriesTitle ?: 'Parent') : 'Subsection #' . $index }}):
                            </label>
                            <div style="display: flex; gap: 8px; position: relative;">
                                <input type="text" 
                                       id="subsection_{{ $index }}" 
                                       class="modal-input-style" 
                                       wire:model.live="subsections.{{ $index }}" 
                                       wire:focus="$set('activeSubDropdownIndex', {{ $index }})" 
                                       placeholder="e.g. {{ $index === 0 ? 'Official Receipts' : 'Tax Receipts' }}" 
                                       autocomplete="off">
                                <button type="button" class="btn-remove-sub" wire:click="removeSubsection({{ $index }})" title="Remove Subsection">&times;</button>
                            </div>

                            @php
                                $currentSubSuggestions = $this->getSubSuggestions($index);
                            @endphp

                            @if($activeSubDropdownIndex === $index && count($currentSubSuggestions) > 0)
                                <div class="dts-autocomplete-dropdown">
                                    @foreach($currentSubSuggestions as $suggestion)
                                        <div class="dts-autocomplete-item" wire:click="selectSubSuggestion({{ $index }}, '{{ addslashes($suggestion->series_title) }}')">
                                            {{ $suggestion->series_title }}
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach

                    <!-- Add Subsection Button -->
                    <div style="margin-top: 10px; margin-bottom: 20px;">
                        <button id="add" type="button" class="btn-add-subsection" wire:click="addSubsection">
                            + add subsection
                        </button>
                    </div>

                    <!-- Modal Actions Footer: Add Record | Cancel -->
                    <div class="modal-actions-footer">
                        <button type="button" class="btn btn-primary" wire:click="saveNewRecordSeries">
                            Add Record
                        </button>
                        <button type="button" class="btn-secondary-modal" wire:click="closeSeriesModal">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- PERIOD COVERED MODAL CONTAINER -->
    @if($showPeriodModal)
        <div class="series-modal-overlay">
            <div class="series-modal-container">
                <div class="series-modal-header">
                    <h3>Configure Period Covered</h3>
                    <button type="button" class="close-modal-btn" wire:click="closePeriodModal">&times;</button>
                </div>
                <div class="series-modal-body">
                    <div style="margin-bottom: 16px; font-size: 13px; color: #64748b;">
                        Add one or multiple period ranges covered by this record. Day input is optional.
                    </div>

                    @foreach($periodsCovered as $index => $period)
                        <div class="period-range-row">
                            <!-- Start Period with Optional Day -->
                            <div style="flex: 1.2;">
                                <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Start Month & Year:</label>
                                <div style="display: flex; gap: 6px;">
                                    <input type="number" min="1" max="31" class="modal-input-style" style="width: 72px;" wire:model="periodsCovered.{{ $index }}.start_day" placeholder="Day">
                                    <input type="month" class="modal-input-style" style="flex: 1;" wire:model="periodsCovered.{{ $index }}.start_month">
                                </div>
                            </div>

                            <span style="font-weight: 700; color: #64748b; margin-top: 18px;">to</span>

                            <!-- End Period with Optional Day -->
                            <div style="flex: 1.2;">
                                <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">End Month & Year:</label>
                                <div style="display: flex; gap: 6px;">
                                    <input type="number" min="1" max="31" class="modal-input-style" style="width: 72px;" wire:model="periodsCovered.{{ $index }}.end_day" placeholder="Day">
                                    <input type="month" class="modal-input-style" style="flex: 1;" wire:model="periodsCovered.{{ $index }}.end_month">
                                </div>
                            </div>

                            <button type="button" class="btn-remove-sub" style="margin-top: 18px;" wire:click="removePeriodRange({{ $index }})" title="Remove Period">&times;</button>
                        </div>
                    @endforeach

                    <!-- Add Period Button -->
                    <div style="margin-top: 12px; margin-bottom: 20px;">
                        <button type="button" class="btn-add-subsection" wire:click="addPeriodRange">
                            + add period range
                        </button>
                    </div>

                    <!-- Modal Actions Footer: Add Period | Cancel -->
                    <div class="modal-actions-footer">
                        <button type="button" class="btn btn-primary" wire:click="savePeriods">
                            Add Period
                        </button>
                        <button type="button" class="btn-secondary-modal" wire:click="closePeriodModal">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>