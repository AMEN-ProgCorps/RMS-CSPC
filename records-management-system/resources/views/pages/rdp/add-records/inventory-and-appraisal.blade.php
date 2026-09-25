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

    public $uploadedFile = null;
    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    // Batch Record Mode State
    public bool $isBatchMode = false;
    public array $batchItems = [];

    public function addBatchItem(): void
    {
        $this->clearMessages();

        if (!$this->isBatchMode) {
            $this->isBatchMode = true;
            $this->batchItems = [
                [
                    'description'      => $this->description,
                    'date_covered'     => $this->date_covered,
                    'volume'           => $this->volume,
                    'records_medium'   => $this->records_medium,
                    'restriction'      => $this->restriction,
                    'records_location' => $this->records_location,
                    'frequence_use'    => $this->frequence_use,
                    'utility_values'   => $this->utility_values,
                    'time_value'       => $this->time_value ?: 'T',
                ],
                [
                    'description'      => '',
                    'date_covered'     => $this->date_covered,
                    'volume'           => '',
                    'records_medium'   => $this->records_medium,
                    'restriction'      => $this->restriction,
                    'records_location' => $this->records_location,
                    'frequence_use'    => $this->frequence_use,
                    'utility_values'   => $this->utility_values,
                    'time_value'       => $this->time_value ?: 'T',
                ],
            ];
        } else {
            $last = end($this->batchItems) ?: [];
            $this->batchItems[] = [
                'description'      => '',
                'date_covered'     => $last['date_covered'] ?? $this->date_covered,
                'volume'           => '',
                'records_medium'   => $last['records_medium'] ?? $this->records_medium,
                'restriction'      => $last['restriction'] ?? $this->restriction,
                'records_location' => $last['records_location'] ?? $this->records_location,
                'frequence_use'    => $last['frequence_use'] ?? $this->frequence_use,
                'utility_values'   => $last['utility_values'] ?? $this->utility_values,
                'time_value'       => $last['time_value'] ?? ($this->time_value ?: 'T'),
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
            array_splice($this->batchItems, $index + 1, 0, [$clone]);
            $this->batchItems = array_values($this->batchItems);
        }
    }

    public function switchToSingleMode(): void
    {
        if (!empty($this->batchItems)) {
            $first = $this->batchItems[0];
            $this->description      = $first['description'] ?? $this->description;
            $this->date_covered     = $first['date_covered'] ?? $this->date_covered;
            $this->volume           = $first['volume'] ?? $this->volume;
            $this->records_medium   = $first['records_medium'] ?? $this->records_medium;
            $this->restriction      = $first['restriction'] ?? $this->restriction;
            $this->records_location = $first['records_location'] ?? $this->records_location;
            $this->frequence_use    = $first['frequence_use'] ?? $this->frequence_use;
            $this->utility_values   = $first['utility_values'] ?? $this->utility_values;
        }
        $this->batchItems = [];
        $this->isBatchMode = false;
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
        $this->time_value = $this->is_permanent ? 'P' : 'T';

        $this->prefill_intake_id = request()->query('prefill_intake_id') ? (int)request()->query('prefill_intake_id') : null;
        $this->prefill_source = request()->query('prefill_source');
        $this->prefill_code = request()->query('prefill_code');
        $this->prefill_doc_id = request()->query('prefill_doc_id');

        if ($this->prefill_intake_id) {
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

    public function addDuplicateOffice(?string $officeCode = null): void
    {
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
        if (isset($this->duplicate_offices[$index])) {
            unset($this->duplicate_offices[$index]);
            $this->duplicate_offices = array_values($this->duplicate_offices);
        }
    }

    public function clearDuplicateOffices(): void
    {
        $this->duplicate_offices = [];
        $this->duplicate_search = '';
        $this->showDuplicateDropdown = false;
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

    public function saveDraft(): void
    {
        $rateCheck = \App\Services\RateLimiterService::check('rdp_create');
        if (!$rateCheck['allowed']) {
            $this->errorMessage = $rateCheck['message'];
            $this->dispatch('scroll-to-top');
            return;
        }

        if (empty($this->selectedSeriesTitle)) {
            $this->errorMessage = 'Please select or stage a Record Series first to save draft.';
            $this->dispatch('scroll-to-top');
            return;
        }

        $this->clearMessages();

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
            $documentIdHandler = null;

            $formattedVolume = mb_strtoupper(trim($this->volume));

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

            $itemsToProcess = [];
            if ($this->isBatchMode && !empty($this->batchItems)) {
                foreach ($this->batchItems as $bItem) {
                    $desc = trim($bItem['description'] ?? '');
                    if (empty($desc)) continue;
                    $itemsToProcess[] = [
                        'description'      => mb_strtoupper($desc),
                        'volume'           => mb_strtoupper(trim($bItem['volume'] ?? '')),
                        'records_location' => mb_strtoupper(trim($bItem['records_location'] ?? '')),
                        'restriction'      => $bItem['restriction'] ?? null,
                        'records_medium'   => !empty($bItem['records_medium']) ? (int)$bItem['records_medium'] : null,
                        'time_value'       => $bItem['time_value'] ?? ($this->time_value ?: 'T'),
                        'frequence_use'    => $bItem['frequence_use'] ?? null,
                        'utility_values'   => $bItem['utility_values'] ?? [],
                        'date_covered'     => $bItem['date_covered'] ?? '',
                    ];
                }
            } else {
                $itemsToProcess[] = [
                    'description'      => mb_strtoupper(trim($this->description)),
                    'volume'           => $formattedVolume,
                    'records_location' => mb_strtoupper(trim($this->records_location)),
                    'restriction'      => $this->restriction,
                    'records_medium'   => $this->records_medium,
                    'time_value'       => $this->time_value ?: 'T',
                    'frequence_use'    => $this->frequence_use,
                    'utility_values'   => $this->utility_values,
                    'date_covered'     => $this->date_covered,
                ];
            }

            if (empty($itemsToProcess)) {
                $this->errorMessage = 'Please provide at least one record subject/description to save draft.';
                $this->dispatch('scroll-to-top');
                return;
            }

            $firstRecordId = null;
            foreach ($itemsToProcess as $rIdx => $rData) {
                $recordId = DB::table('rdp_record')->insertGetId([
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
                ]);

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

                foreach ($this->duplicate_offices as $dupOffice) {
                    DB::table('rdp_duplication_section')->insert([
                        'dup_id_manager' => $recordId,
                        'office_code'    => $dupOffice,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }

                if (!empty($rData['date_covered'])) {
                    DB::table('rdp_period_covered')->insert([
                        'period_owner' => $recordId,
                        'date_covered' => $rData['date_covered'],
                        'created_at'   => now(),
                        'modified_at'  => now(),
                    ]);
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
            $this->successMessage = $count > 1 
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

        if (empty($this->selectedSeriesTitle)) {
            $this->errorMessage = 'Please select or configure a Record Series first.';
            $this->dispatch('scroll-to-top');
            return;
        }

        $requiredUpload = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings')->where('key', 'rdp_required_upload_file')->value('value') === 'true';
        if ($requiredUpload && !$this->uploadedFile) {
            $this->errorMessage = 'Uploading a file is required to create a record according to system settings.';
            $this->dispatch('scroll-to-top');
            return;
        }

        $this->clearMessages();

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
            $documentIdHandler = null;

            $formattedVolume = mb_strtoupper(trim($this->volume));

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
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);
                }
            }

            $this->record_series_id = $lastSeriesId;

            if ($this->uploadedFile) {
                $uploadResult = \App\Services\DocumentStorageService::storeUpload($this->uploadedFile, 'RDP', $user);
                $documentIdHandler = $uploadResult['document_id'];
            } elseif ($this->prefill_doc_id) {
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
            if ($this->isBatchMode && !empty($this->batchItems)) {
                foreach ($this->batchItems as $bItem) {
                    $desc = trim($bItem['description'] ?? '');
                    if (empty($desc)) continue;
                    $itemsToProcess[] = [
                        'description'      => mb_strtoupper($desc),
                        'volume'           => mb_strtoupper(trim($bItem['volume'] ?? '')),
                        'records_location' => mb_strtoupper(trim($bItem['records_location'] ?? '')),
                        'restriction'      => $bItem['restriction'] ?? null,
                        'records_medium'   => !empty($bItem['records_medium']) ? (int)$bItem['records_medium'] : null,
                        'time_value'       => $bItem['time_value'] ?? ($this->time_value ?: 'T'),
                        'frequence_use'    => $bItem['frequence_use'] ?? null,
                        'utility_values'   => $bItem['utility_values'] ?? [],
                        'date_covered'     => $bItem['date_covered'] ?? '',
                    ];
                }
            } else {
                $desc = trim($this->description);
                if (!empty($desc)) {
                    $itemsToProcess[] = [
                        'description'      => mb_strtoupper($desc),
                        'volume'           => $formattedVolume,
                        'records_location' => mb_strtoupper(trim($this->records_location)),
                        'restriction'      => $this->restriction,
                        'records_medium'   => $this->records_medium,
                        'time_value'       => $this->time_value ?: 'T',
                        'frequence_use'    => $this->frequence_use,
                        'utility_values'   => $this->utility_values,
                        'date_covered'     => $this->date_covered,
                    ];
                }
            }

            if (empty($itemsToProcess)) {
                $this->errorMessage = 'Please provide at least one record subject/description.';
                $this->dispatch('scroll-to-top');
                return;
            }

            $firstRecordId = null;
            foreach ($itemsToProcess as $rIdx => $rData) {
                $recordId = DB::table('rdp_record')->insertGetId([
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
                ]);

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

                foreach ($this->duplicate_offices as $dupOffice) {
                    DB::table('rdp_duplication_section')->insert([
                        'dup_id_manager' => $recordId,
                        'office_code'    => $dupOffice,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }

                if (!empty($rData['date_covered'])) {
                    DB::table('rdp_period_covered')->insert([
                        'period_owner' => $recordId,
                        'date_covered' => $rData['date_covered'],
                        'created_at'   => now(),
                        'modified_at'  => now(),
                    ]);
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
            $this->successMessage = $count > 1 
                ? "Batch of {$count} records created successfully under this series!" 
                : "Inventory and Appraisal Record created successfully!";
            $this->resetFormFields();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to create record: ' . $e->getMessage();
            $this->dispatch('scroll-to-top');
        }
    }

    public function resetFormFields(): void
    {
        $this->isBatchMode = false;
        $this->batchItems = [];
        $this->selectedSeriesTitle = null;
        $this->record_series_id = null;
        $this->description = '';
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
        $this->date_covered = '';
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

    <!-- Form Card Wrapper (Anchors the Side (+) Action Button) -->
    <div class="ia-form-card-wrapper">
        <!-- Side Action: Add More / Cancel Batch Mode Button -->
        <div class="ia-side-action-rail">
            @if($isBatchMode)
                <button type="button" 
                        wire:click="switchToSingleMode" 
                        class="ia-side-add-btn is-cancel" 
                        aria-label="Cancel mode"
                        title="Cancel mode">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                    <span class="ia-side-tooltip">Cancel mode</span>
                </button>
            @else
                <button type="button" 
                        wire:click="addBatchItem" 
                        class="ia-side-add-btn" 
                        aria-label="Add more"
                        title="Add more">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    <span class="ia-side-tooltip">Add more</span>
                </button>
            @endif
        </div>

        <!-- Main Form Card -->
        <div class="ia-form-card">
            <div style="padding: 28px 32px;">
                <!-- Record Series Title -->
                <div class="ia-form-row" wire:key="ia-row-series">
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

                @if($isBatchMode)
                    <!-- Batch Items List -->
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
                                            <span>📋 Duplicate</span>
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
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Selected Date</label>
                                        <input type="date" class="ia-input" wire:model.blur="batchItems.{{ $bIdx }}.date_covered" style="width: 100%; box-sizing: border-box;">
                                    </div>
                                    <div>
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Volume Amount & Unit</label>
                                        <input type="text" class="ia-input" wire:model="batchItems.{{ $bIdx }}.volume" placeholder="E.G. 1 BOX 20 PAPERS..." style="width: 100%; box-sizing: border-box;">
                                    </div>
                                    <div>
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Records Location</label>
                                        <input type="text" class="ia-input" wire:model="batchItems.{{ $bIdx }}.records_location" placeholder="E.G. CABINET 3, SHELF 2" style="width: 100%; box-sizing: border-box;">
                                    </div>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                                    <div>
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Records Medium</label>
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
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Restriction / Access</label>
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
                                        <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Frequency of Use</label>
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

                                <div>
                                    <label style="font-size: 12px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Utility Values</label>
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
                            </div>
                        @endforeach

                        <button type="button" wire:click="addBatchItem" style="border: 2px dashed #818cf8; background: #f5f3ff; color: #4338ca; border-radius: 12px; padding: 14px; font-weight: 800; font-size: 13px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 8px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            <span>+ ADD ANOTHER RECORD ROW</span>
                        </button>
                    </div>
                @else
                    <!-- Description -->
                    <div class="ia-form-row" wire:key="ia-row-desc" style="align-items: flex-start;">
                        <span class="ia-label" style="margin-top: 10px;">Description</span>
                        <textarea class="ia-input" wire:model="description" rows="3" placeholder="ENTER RECORD DESCRIPTION OR SPECIFIC DETAILS..." style="font-family: inherit;"></textarea>
                    </div>

                    <!-- Selected Date -->
                    <div class="ia-form-row" wire:key="ia-row-date">
                        <span class="ia-label">Selected Date</span>
                        <div style="flex: 1; display: flex; align-items: center; gap: 10px;">
                            <input type="date" class="ia-input" wire:model.live="date_covered" style="max-width: 240px;">
                            @if(!empty($date_covered))
                                <button type="button" wire:click="$set('date_covered', '')" class="ia-btn ia-btn-secondary" style="padding: 6px 14px; font-size: 12px;">Clear Date</button>
                            @endif
                        </div>
                    </div>

                    <!-- Volume Amount & Unit -->
                    <div class="ia-form-row" wire:key="ia-row-volume">
                        <span class="ia-label">Volume Amount & Unit</span>
                        <input type="text" class="ia-input" wire:model="volume" placeholder="E.G. 1 BOX 20 PAPERS, 2 BUNDLES..." style="flex: 1;">
                    </div>

                    <!-- Records Medium -->
                    <div class="ia-form-row" wire:key="ia-row-medium">
                        <span class="ia-label">Records Medium</span>
                        <select class="ia-input" wire:model.live="records_medium">
                            <option value="" disabled {{ empty($records_medium) ? 'selected' : '' }}>Select Medium...</option>
                            @foreach($mediaList as $med)
                                <option value="{{ $med->id }}" {{ (string)$records_medium === (string)$med->id ? 'selected' : '' }}>
                                    {{ $med->medium_name }} ({{ $med->description }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Restriction -->
                    <div class="ia-form-row" wire:key="ia-row-restriction">
                        <span class="ia-label">Restriction / Access</span>
                        <select class="ia-input" wire:model.live="restriction">
                            <option value="" disabled {{ empty($restriction) ? 'selected' : '' }}>Select Restriction Type...</option>
                            @foreach($restrictionsList as $rest)
                                <option value="{{ $rest->restriction_value }}" {{ $restriction === $rest->restriction_value ? 'selected' : '' }}>
                                    {{ $rest->restriction_value }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Records Location -->
                    <div class="ia-form-row" wire:key="ia-row-location">
                        <span class="ia-label">Records Location</span>
                        <input type="text" class="ia-input" wire:model="records_location" placeholder="E.G. BUILDING A, CABINET 3, SHELF 2">
                    </div>

                    <!-- Frequency of Use -->
                    <div class="ia-form-row" wire:key="ia-row-frequency">
                        <span class="ia-label">Frequency of Use</span>
                        <select class="ia-input" wire:model.live="frequence_use">
                            <option value="" disabled {{ empty($frequence_use) ? 'selected' : '' }}>Select Frequency...</option>
                            @foreach($frequenciesList as $freq)
                                <option value="{{ $freq->freq_type }}" {{ $frequence_use === $freq->freq_type ? 'selected' : '' }}>
                                    {{ $freq->freq_type }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

            <!-- Duplicate -->
            <div class="ia-form-row" wire:key="ia-row-duplicate" style="align-items: flex-start;">
                <span class="ia-label" style="margin-top: 10px;">Duplicate</span>
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
            </div>

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
                    <span class="ia-label" style="margin-top: 8px;">Utility Value</span>
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
                {{ $isBatchMode && count($batchItems) > 1 ? 'SAVE DRAFT (' . count($batchItems) . ' RECORDS)' : 'SAVE DRAFT' }}
            </button>
            <button type="button" wire:click="createRecord" onclick="rdpScrollToTop()" class="ia-btn ia-btn-primary ia-btn-lg">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                {{ $isBatchMode && count($batchItems) > 1 ? 'CREATE ' . count($batchItems) . ' RECORDS' : 'CREATE RECORD' }}
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
</div>