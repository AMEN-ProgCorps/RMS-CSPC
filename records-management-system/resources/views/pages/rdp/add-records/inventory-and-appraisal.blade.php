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
    public ?string $time_value = 'T';
    public array $utility_values = []; // Multi-choice array of selected utility IDs
    public string $retention_period = '';
    public bool $is_permanent = false;
    public string $active_period = '';
    public string $storage_period = '';
    public string $disposition_provision = '';

    public $uploadedFile = null;
    public ?string $successMessage = null;
    public ?string $errorMessage = null;

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

    public function syncArchivalLocking(): void
    {
        $archivalId = DB::table('rdp_utility_medium')
            ->where('utility_name', 'like', '%Archival%')
            ->value('id');

        if ($archivalId) {
            if ($this->is_permanent || $this->time_value === 'P') {
                if (!in_array($archivalId, $this->utility_values)) {
                    $this->utility_values[] = $archivalId;
                }
            } else {
                $this->utility_values = array_values(array_filter($this->utility_values, fn($id) => (int)$id !== (int)$archivalId));
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
        } elseif ($val === 'T') {
            $this->is_permanent = false;
            $this->retention_period = $this->computeTotalPeriod($this->active_period, $this->storage_period, false);
        }
        $this->syncArchivalLocking();
    }

    public function updatedIsPermanent($val): void
    {
        if ($val) {
            $this->time_value = 'P';
            $this->active_period = '';
            $this->storage_period = '';
            $this->retention_period = 'Permanent';
        } else {
            $this->time_value = 'T';
            $this->retention_period = $this->computeTotalPeriod($this->active_period, $this->storage_period, false);
        }
        $this->syncArchivalLocking();
    }

    public function mount(): void
    {
        $this->time_value = $this->is_permanent ? 'P' : 'T';

        // Default volume_unit to standard unit (e.g. Pages)
        $defaultUnit = DB::table('rdp_volume_value')
            ->where('cur_used_standard', true)
            ->where('is_active', true)
            ->value('volume_id');

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
            ->leftJoin('office', 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
            ->select([
                'rdp_record_series.id',
                'rdp_record_series.series_title',
                'rdp_record_series.recorded_at_office',
                'rdp_record_series_type.shorted_type',
                'office.office_name as recorded_office_name',
            ])
            ->where('rdp_record_series.parent_id', $parentRecord->id);

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

        $this->syncArchivalLocking();

        $this->showSeriesModal = false;
        $this->showParentDropdown = false;
        $this->activeSubDropdownIndex = null;
    }

    // --- Period Covered Handlers ---
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
                $formatted[] = mb_strtoupper($startStr . ' - ' . $endStr);
            } elseif ($startStr) {
                $formatted[] = mb_strtoupper('FROM ' . $startStr);
            } elseif ($endStr) {
                $formatted[] = mb_strtoupper('UNTIL ' . $endStr);
            }
        }
        return $formatted;
    }

    public function calculateFormattedVolume(): string
    {
        if (empty($this->volume_amount) || $this->volume_amount <= 0) {
            return '';
        }

        $standardUnitRecord = null;
        if ($this->volume_unit) {
            $standardUnitRecord = DB::table('rdp_volume_value')
                ->where('volume_id', $this->volume_unit)
                ->first();
        }

        $unitName = $standardUnitRecord ? $standardUnitRecord->value_standard : 'Pages';
        $standardPart = $this->volume_amount . ' ' . $unitName;

        $rule = DB::table('rdp_volume_conversion')
            ->where('value_standard', $this->volume_unit)
            ->where('is_active', true)
            ->first();

        if ($rule && $rule->amount_standard > 0) {
            $convValueRecord = DB::table('rdp_volume_value')
                ->where('volume_id', $rule->value_converted)
                ->first();

            $convUnitName = $convValueRecord ? $convValueRecord->value_standard : 'Folder';
            $ratio = $rule->amount_converted / $rule->amount_standard;
            $convertedAmount = round($this->volume_amount * $ratio, 2);

            return mb_strtoupper($standardPart . ' (' . $convertedAmount . ' ' . $convUnitName . ')');
        }

        return mb_strtoupper($standardPart);
    }

    public function with(): array
    {
        $term = strtolower(trim($this->parentSeriesTitle));

        $parentSuggestionsQuery = DB::table('rdp_record_series')
            ->leftJoin('rdp_record_series_type', 'rdp_record_series.series_type', '=', 'rdp_record_series_type.id')
            ->leftJoin('office', 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
            ->select([
                'rdp_record_series.id',
                'rdp_record_series.series_title',
                'rdp_record_series.series_type',
                'rdp_record_series.recorded_at_office',
                'rdp_record_series_type.shorted_type',
                'office.office_name as recorded_office_name',
            ])
            ->whereNull('rdp_record_series.parent_id');

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
            ->when(!empty(trim($this->parentSeriesTitle)), fn($q) => $q->where('series_title', 'ilike', '%' . trim($this->parentSeriesTitle) . '%'))
            ->distinct()
            ->orderBy('series_title', 'asc')
            ->limit(10)
            ->get();

        $availableUnits = DB::table('rdp_volume_value')
            ->where('cur_used_standard', true)
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

    public function saveDraft(): void
    {
        if (empty($this->selectedSeriesTitle)) {
            $this->errorMessage = 'Please select or stage a Record Series first to save draft.';
            return;
        }

        $this->clearMessages();

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
            $documentIdHandler = null;

            $this->volume = $this->calculateFormattedVolume();

            $titles = explode(' ➔ ', $this->selectedSeriesTitle);
            $lastSeriesId = null;

            foreach ($titles as $idx => $title) {
                $trimmed = mb_strtoupper(trim($title));
                $existing = DB::table('rdp_record_series')
                    ->where('series_title', 'ilike', $trimmed)
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

            if ($this->uploadedFile) {
                $uploadResult = \App\Services\DocumentStorageService::storeUpload($this->uploadedFile, 'RDP', $user);
                $documentIdHandler = $uploadResult['document_id'];
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

            $recordId = DB::table('rdp_record')->insertGetId([
                'record_series_id'       => $this->record_series_id,
                'description'            => mb_strtoupper($this->description),
                'period_id'              => $periodId,
                'volume'                 => mb_strtoupper($this->volume),
                'records_location'       => mb_strtoupper($this->records_location),
                'restriction'            => $this->restriction,
                'records_medium'         => $this->records_medium,
                'time_value'             => $this->time_value,
                'frequence_use'          => $this->frequence_use,
                'user_own'               => $user?->id,
                'office_own'             => $userOfficeCode,
                'upload_doc_id_handler'  => $documentIdHandler,
                'is_draft'               => true,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            foreach ($this->utility_values as $uId) {
                DB::table('rdp_utility_manager')->insert([
                    'record_holder'  => $recordId,
                    'utility_medium' => $uId,
                    'is_active'      => true,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            DB::commit();

            $this->successMessage = 'Inventory and Appraisal draft saved successfully!';
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to save draft: ' . $e->getMessage();
        }
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

        $this->clearMessages();

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
            $documentIdHandler = null;

            $this->volume = $this->calculateFormattedVolume();

            $titles = explode(' ➔ ', $this->selectedSeriesTitle);
            $lastSeriesId = null;

            foreach ($titles as $idx => $title) {
                $trimmed = mb_strtoupper(trim($title));
                $existing = DB::table('rdp_record_series')
                    ->where('series_title', 'ilike', $trimmed)
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

            if ($this->uploadedFile) {
                $uploadResult = \App\Services\DocumentStorageService::storeUpload($this->uploadedFile, 'RDP', $user);
                $documentIdHandler = $uploadResult['document_id'];
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

            $recordId = DB::table('rdp_record')->insertGetId([
                'record_series_id'       => $this->record_series_id,
                'description'            => mb_strtoupper($this->description),
                'period_id'              => $periodId,
                'volume'                 => mb_strtoupper($this->volume),
                'records_location'       => mb_strtoupper($this->records_location),
                'restriction'            => $this->restriction,
                'records_medium'         => $this->records_medium,
                'time_value'             => $this->time_value,
                'frequence_use'          => $this->frequence_use,
                'user_own'               => $user?->id,
                'office_own'             => $userOfficeCode,
                'upload_doc_id_handler'  => $documentIdHandler,
                'is_draft'               => false,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            foreach ($this->utility_values as $uId) {
                DB::table('rdp_utility_manager')->insert([
                    'record_holder'  => $recordId,
                    'utility_medium' => $uId,
                    'is_active'      => true,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

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
                        'record_holder' => $recordId,
                        'start_at'      => $startAt,
                        'ends_at'       => $endsAt,
                        'is_active'     => true,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }
            }

            DB::commit();

            $this->successMessage = 'Inventory and Appraisal Record created successfully!';
            $this->resetFormFields();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to create record: ' . $e->getMessage();
        }
    }

    public function resetFormFields(): void
    {
        $this->selectedSeriesTitle = null;
        $this->record_series_id = null;
        $this->description = '';
        $this->volume_amount = null;
        $this->volume = '';
        $this->records_medium = null;
        $this->restriction = null;
        $this->records_location = '';
        $this->frequence_use = null;
        $this->duplication = null;
        $this->time_value = 'T';
        $this->utility_values = [];
        $this->retention_period = '';
        $this->is_permanent = false;
        $this->active_period = '';
        $this->storage_period = '';
        $this->disposition_provision = '';
        $this->uploadedFile = null;
        $this->periodsCovered = [];
        $this->parentSeriesTitle = '';
        $this->subsections = [];
    }

    public function clearMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }
}; ?>

<div class="inventory-appraisal-form" style="padding: 24px; max-width: 1200px; margin: 0 auto;">
    @push('styles')
        @vite(['resources/css/rdp/inventory-and-appraisal.css'])
    @endpush


    <!-- Premium Header -->
    <div class="ia-page-header">
        <div>
            <h2>Inventory and Appraisal — Add Record</h2>
            <p>Create an official record entry linked to an approved or custom Record Series</p>
        </div>
    </div>

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

    <!-- Main Form Card -->
    <div class="ia-form-card">
        <div style="padding: 28px 32px;">
            <!-- Record Series Title -->
            <div class="ia-form-row">
                <span class="ia-label ia-label-required">Record Series Title</span>
                <div style="flex: 1; display: flex; align-items: center;">
                    @if($selectedSeriesTitle)
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

            <!-- Description -->
            <div class="ia-form-row" style="align-items: flex-start;">
                <span class="ia-label" style="margin-top: 10px;">Description</span>
                <textarea class="ia-input" wire:model="description" rows="3" placeholder="ENTER RECORD DESCRIPTION OR SPECIFIC DETAILS..." style="font-family: inherit;"></textarea>
            </div>

            <!-- Period Covered -->
            <div class="ia-form-row">
                <span class="ia-label">Period Covered</span>
                <div style="flex: 1; display: flex; align-items: center;">
                    @if(!empty($this->formattedPeriods))
                        <div class="ia-badge ia-badge-blue">
                            <div class="ia-badge-icon">
                                <span class="ia-badge-icon-circle">📅</span>
                                <span>{{ implode('; ', $this->formattedPeriods) }}</span>
                            </div>
                            <button type="button" wire:click="openPeriodModal" class="ia-btn ia-btn-change">Modify Dates</button>
                        </div>
                    @else
                        <button type="button" wire:click="openPeriodModal" class="ia-btn ia-btn-ghost">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            Configure Dates
                        </button>
                    @endif
                </div>
            </div>

            <!-- Volume Amount & Unit -->
            <div class="ia-form-row">
                <span class="ia-label">Volume Amount & Unit</span>
                <div style="flex: 1; display: flex; gap: 10px; align-items: center;">
                    <input type="number" step="0.01" min="0" class="ia-input" wire:model.live.debounce.200ms="volume_amount" placeholder="E.G. 500" style="width: 140px; flex: 0 0 140px;">
                    <select class="ia-input" wire:model.live="volume_unit" style="max-width: 240px;">
                        @foreach($availableUnits as $unit)
                            <option value="{{ $unit->volume_id }}">{{ mb_strtoupper($unit->value_standard) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <!-- Computed Formatted Volume -->
            @if(!empty($volume_amount) && $volume_amount > 0)
                <div class="ia-form-row">
                    <span class="ia-label">Formatted Volume</span>
                    <div class="ia-computed-box ia-computed-box-green">
                        {{ $this->calculateFormattedVolume() }}
                    </div>
                </div>
            @endif

            <!-- Records Medium -->
            <div class="ia-form-row">
                <span class="ia-label">Records Medium</span>
                <select class="ia-input" wire:model="records_medium">
                    <option value="" selected disabled>Select Medium...</option>
                    @foreach($mediaList as $med)
                        <option value="{{ $med->id }}">{{ $med->medium_name }} ({{ $med->description }})</option>
                    @endforeach
                </select>
            </div>

            <!-- Restriction -->
            <div class="ia-form-row">
                <span class="ia-label">Restriction / Access</span>
                <select class="ia-input" wire:model="restriction">
                    <option value="" selected disabled>Select Restriction Type...</option>
                    @foreach($restrictionsList as $rest)
                        <option value="{{ $rest->restriction_value }}">{{ $rest->restriction_value }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Records Location -->
            <div class="ia-form-row">
                <span class="ia-label">Records Location</span>
                <input type="text" class="ia-input" wire:model="records_location" placeholder="E.G. BUILDING A, CABINET 3, SHELF 2">
            </div>

            <!-- Frequency of Use -->
            <div class="ia-form-row">
                <span class="ia-label">Frequency of Use</span>
                <select class="ia-input" wire:model="frequence_use">
                    <option value="" selected disabled>Select Frequency...</option>
                    @foreach($frequenciesList as $freq)
                        <option value="{{ $freq->freq_type }}">{{ $freq->freq_type }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Time Value -->
            <div class="ia-form-row">
                <span class="ia-label">Time Value</span>
                <div style="flex: 1; display: flex; align-items: center; gap: 8px;">
                    <select class="ia-input" disabled style="cursor: not-allowed; background: var(--ia-slate-100); font-weight: 700; color: var(--ia-blue-800);">
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
            <div class="ia-form-row" style="align-items: flex-start;">
                <span class="ia-label" style="margin-top: 8px;">Utility Value</span>
                <div style="flex: 1; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                    @foreach($utilityValuesList as $uv)
                        @php
                            $isArchival = str_contains(strtolower($uv->utility_name ?? ''), 'archival');
                            $isPermanentActive = ($is_permanent || $time_value === 'P');
                            $isLockedChecked = $isArchival && $isPermanentActive;
                            $isDisabledUnchecked = $isArchival && !$isPermanentActive;
                            $isChecked = in_array($uv->id, $utility_values) || $isLockedChecked;
                            $chipClass = $isLockedChecked ? 'ia-chip-locked' : ($isDisabledUnchecked ? 'ia-chip-disabled' : ($isChecked ? 'ia-chip-active' : 'ia-chip-default'));
                        @endphp
                        <label class="ia-chip {{ $chipClass }}">
                            <input type="checkbox"
                                   wire:model.live="utility_values"
                                   value="{{ $uv->id }}"
                                   {{ $isLockedChecked ? 'checked disabled' : '' }}
                                   {{ $isDisabledUnchecked ? 'disabled' : '' }}>
                            <span>{{ mb_strtoupper($uv->utility_name) }}</span>
                            @if($isLockedChecked)
                                <span class="ia-chip-lock-badge">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    LOCKED
                                </span>
                            @endif
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Permanent Record Toggle -->
            <div class="ia-form-row">
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
            <div class="ia-form-row {{ ($is_permanent || $hasPredefinedRetention) ? 'ia-row-disabled' : '' }}">
                <span class="ia-label">
                    Active Period
                    @if($hasPredefinedRetention)
                        <span style="font-size: 11px; color: #64748b; font-weight: 500;">(Predefined — Read Only)</span>
                    @endif
                </span>
                <input type="text" class="ia-input" wire:model.live.debounce.200ms="active_period" placeholder="E.G. 6 MONTHS, 1 YEAR" {{ ($is_permanent || $hasPredefinedRetention) ? 'disabled' : '' }}>
            </div>

            <!-- Storage Period -->
            <div class="ia-form-row {{ ($is_permanent || $hasPredefinedRetention) ? 'ia-row-disabled' : '' }}">
                <span class="ia-label">
                    Storage Period
                    @if($hasPredefinedRetention)
                        <span style="font-size: 11px; color: #64748b; font-weight: 500;">(Predefined — Read Only)</span>
                    @endif
                </span>
                <input type="text" class="ia-input" wire:model.live.debounce.200ms="storage_period" placeholder="E.G. 1 YEAR, 4 YEARS" {{ ($is_permanent || $hasPredefinedRetention) ? 'disabled' : '' }}>
            </div>

            <!-- Total Period (Computed) -->
            <div class="ia-form-row {{ ($is_permanent || $hasPredefinedRetention) ? 'ia-row-disabled' : '' }}">
                <span class="ia-label">Total Period</span>
                <div class="ia-computed-box">
                    {{ $this->computeTotalPeriod($active_period, $storage_period, $is_permanent) ?: '— (Auto-calculated)' }}
                </div>
            </div>

            <!-- Remarks -->
            <div class="ia-form-row" style="align-items: flex-start;">
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

            <button type="button" wire:click="resetFormFields" class="ia-btn ia-btn-secondary">CLEAR FORM</button>
            <button type="button" wire:click="saveDraft" class="ia-btn ia-btn-secondary">SAVE DRAFT</button>
            <button type="button" wire:click="createRecord" class="ia-btn ia-btn-primary ia-btn-lg">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                CREATE RECORD
            </button>
        </div>
    </div>

    <!-- ═══════ Series Selection Modal ═══════ -->
    @if($showSeriesModal)
        <div class="ia-modal-overlay">
            <div class="ia-modal-card">
                <div class="ia-modal-header">
                    <h3>Configure Record Series</h3>
                    <button wire:click="closeSeriesModal" class="ia-modal-close">&times;</button>
                </div>
                <div class="ia-modal-body">
                    <div style="margin-bottom: 16px; position: relative;" wire:click.outside="$set('showParentDropdown', false)">
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
                        <div style="margin-bottom: 12px; display: flex; gap: 8px; position: relative;" wire:click.outside="$set('activeSubDropdownIndex', null)">
                            <input type="text" class="ia-input" wire:model.live="subsections.{{ $idx }}" wire:focus="$set('activeSubDropdownIndex', {{ $idx }})" placeholder="Subsection #{{ $idx + 1 }} title...">
                            <button type="button" wire:click="removeSubsection({{ $idx }})" class="ia-btn ia-btn-danger" style="padding: 0 12px;">&times;</button>

                            @if($activeSubDropdownIndex === $idx && count($this->getSubSuggestions($idx)) > 0)
                                <div class="ia-autocomplete-dropdown" style="top: 100%; left: 0; right: 40px; z-index: 10;">
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

    <!-- ═══════ Period Dates Modal ═══════ -->
    @if($showPeriodModal)
        <div class="ia-modal-overlay">
            <div class="ia-modal-card">
                <div class="ia-modal-header">
                    <h3>Configure Period Covered Dates</h3>
                    <button wire:click="closePeriodModal" class="ia-modal-close">&times;</button>
                </div>
                <div class="ia-modal-body">
                    @foreach($periodsCovered as $idx => $p)
                        <div style="display: flex; gap: 10px; margin-bottom: 12px; align-items: center;">
                            <input type="number" min="1" max="31" class="ia-input" wire:model="periodsCovered.{{ $idx }}.start_day" placeholder="Day" style="width: 75px; flex: 0 0 75px;">
                            <input type="month" class="ia-input" wire:model="periodsCovered.{{ $idx }}.start_month">
                            <span style="font-weight: 700; color: var(--ia-slate-500); font-size: 12px;">TO</span>
                            <input type="number" min="1" max="31" class="ia-input" wire:model="periodsCovered.{{ $idx }}.end_day" placeholder="Day" style="width: 75px; flex: 0 0 75px;">
                            <input type="month" class="ia-input" wire:model="periodsCovered.{{ $idx }}.end_month">
                            <button type="button" wire:click="removePeriodRange({{ $idx }})" class="ia-btn ia-btn-danger" style="padding: 0 10px;">&times;</button>
                        </div>
                    @endforeach
                    <div style="margin-bottom: 8px;">
                        <button type="button" wire:click="addPeriodRange" class="ia-btn ia-btn-ghost" style="border-style: dashed;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                            Add Date Range
                        </button>
                    </div>
                </div>
                <div class="ia-modal-footer">
                    <button type="button" wire:click="closePeriodModal" class="ia-btn ia-btn-secondary">Cancel</button>
                    <button type="button" wire:click="savePeriods" class="ia-btn ia-btn-primary">Apply Dates</button>
                </div>
            </div>
        </div>
    @endif
</div>