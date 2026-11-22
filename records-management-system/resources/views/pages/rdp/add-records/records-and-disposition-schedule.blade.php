<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.rdp')] #[Title('Records and Disposition Schedule')] class extends Component {
    // Record Series Form Fields
    public ?int $editingSeriesId = null;
    public ?string $item_number = null;
    public string $series_title = '';
    public ?int $parent_id = null;
    public string $active_period = '';
    public string $storage_period = '';
    public string $total_period = '';
    public string $remarks = '';
    public bool $is_permanent = false;
    public bool $is_superseding = false;

    public string $successMessage = '';
    public string $errorMessage = '';

    // Subsections & Hierarchy State
    public array $subsections = [];
    public bool $showParentDropdown = false;
    public ?int $activeSubDropdownIndex = null;

    /**
     * Resolves the target leaf record based on the current series_title and subsections.
     * Returns an array with:
     * - 'exists': bool, true if record exists in the database
     * - 'is_verified': bool, true if matched record has is_verified == true
     * - 'leaf': the database record if matched, or null
     */
    public function resolveCurrentSeriesStatus(): array
    {
        $parentTitle = mb_strtoupper(trim($this->series_title));
        if (empty($parentTitle)) {
            return [
                'exists' => false,
                'is_verified' => false,
                'leaf' => null,
            ];
        }

        $allTitles = [$parentTitle];
        foreach ($this->subsections as $sub) {
            $t = mb_strtoupper(trim((string)$sub));
            if (!empty($t)) {
                $allTitles[] = $t;
            }
        }

        $currentParentId = null;
        $matchedRecord = null;

        foreach ($allTitles as $idx => $t) {
            $q = DB::table('rdp_record_series')
                ->where('series_title', 'ilike', $t)
                ->where('is_active', true);

            if ($idx === 0) {
                $q->whereNull('parent_id');
            } else {
                if (!$currentParentId) {
                    return [
                        'exists' => false,
                        'is_verified' => false,
                        'leaf' => null,
                    ];
                }
                $q->where('parent_id', $currentParentId);
            }

            $matchedRecord = $q->first();
            if (!$matchedRecord) {
                return [
                    'exists' => false,
                    'is_verified' => false,
                    'leaf' => null,
                ];
            }
            $currentParentId = $matchedRecord->id;
        }

        return [
            'exists' => true,
            'is_verified' => (bool) ($matchedRecord->is_verified ?? false),
            'leaf' => $matchedRecord,
        ];
    }

    /**
     * Synchronizes form inputs when an existing verified record series is selected.
     */
    public function syncSeriesStatus(): void
    {
        $this->clearMessages();
        $status = $this->resolveCurrentSeriesStatus();
        
        if ($status['is_verified'] && $status['leaf']) {
            $leaf = $status['leaf'];
            if ($leaf->retention_period) {
                $ret = DB::table('rdp_retention_period')->where('id', $leaf->retention_period)->first();
                $this->active_period = $ret->active_period ?? '';
                $this->storage_period = $ret->storage_period ?? '';
                $this->total_period = $ret->total_period ?? '';
                $this->is_permanent = (bool)($leaf->is_retention_period_permanent ?? false) || strtolower($this->total_period) === 'permanent';
            } else {
                $this->active_period = '';
                $this->storage_period = '';
                $this->total_period = '';
                $this->is_permanent = (bool)($leaf->is_retention_period_permanent ?? false);
            }
            $this->remarks = \App\Services\RdpRemarksService::textFor($leaf->remarks) ?? '';
            $this->is_superseding = (bool)($leaf->is_superseding ?? false);
        }
    }

    /**
     * Selects parent suggestion and populates the details panel.
     *
     * @param string $title
     *
     * @return void
     */
    public function selectParentSuggestion(string $title): void
    {
        $this->series_title = trim($title);
        $this->showParentDropdown = false;
        $this->syncSeriesStatus();
    }

    /**
     * Selects sub suggestion and populates the details panel.
     *
     * @param int $index
     * @param string $title
     *
     * @return void
     */
    public function selectSubSuggestion(int $index, string $title): void
    {
        if (isset($this->subsections[$index])) {
            $this->subsections[$index] = trim($title);
        }
        $this->activeSubDropdownIndex = null;
        $this->syncSeriesStatus();
    }

    /**
     * Retrieves and populates sub suggestions data based on hierarchical ancestry.
     *
     * @param int $index
     *
     * @return \Illuminate\Support\Collection
     */
    public function getSubSuggestions(int $index): \Illuminate\Support\Collection
    {
        $parentTitle = mb_strtoupper(trim($this->series_title));
        if (empty($parentTitle)) {
            return collect();
        }

        $allTitles = [$parentTitle];
        for ($i = 0; $i < $index; $i++) {
            $t = mb_strtoupper(trim((string)($this->subsections[$i] ?? '')));
            if (empty($t)) {
                return collect();
            }
            $allTitles[] = $t;
        }

        $currentParentId = null;
        foreach ($allTitles as $idx => $t) {
            $q = DB::table('rdp_record_series')
                ->where('series_title', 'ilike', $t)
                ->where('is_active', true);

            if ($idx === 0) {
                $q->whereNull('parent_id');
            } else {
                if (!$currentParentId) {
                    return collect();
                }
                $q->where('parent_id', $currentParentId);
            }

            $rec = $q->first();
            if (!$rec) {
                return collect();
            }
            $currentParentId = $rec->id;
        }

        if (!$currentParentId) {
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
            ->where('rdp_record_series.parent_id', $currentParentId)
            ->where('rdp_record_series.is_active', true);

        $currentSubInput = trim((string)($this->subsections[$index] ?? ''));
        if (!empty($currentSubInput)) {
            $query->where('rdp_record_series.series_title', 'ilike', '%' . $currentSubInput . '%');
        }

        return $query->distinct()->orderBy('rdp_record_series.series_title', 'asc')->limit(10)->get();
    }

    /**
     * Creates a new subsection record.
     * @return void
     */
    public function addSubsection(): void
    {
        if (empty(trim($this->series_title))) {
            $this->errorMessage = 'Please enter a Record Series Title first before adding a subsection.';
            return;
        }

        if (!empty($this->subsections)) {
            $lastSub = end($this->subsections);
            if (empty(trim((string)$lastSub))) {
                $this->errorMessage = 'Please fill in the current subsection before adding another one.';
                return;
            }
        }

        $this->subsections[] = '';
        $this->activeSubDropdownIndex = count($this->subsections) - 1;
        $this->syncSeriesStatus();
    }

    /**
     * Deletes or deactivates the specified subsection.
     *
     * @param int $index
     *
     * @return void
     */
    public function removeSubsection(int $index): void
    {
        if (isset($this->subsections[$index])) {
            unset($this->subsections[$index]);
            $this->subsections = array_values($this->subsections);
        }
        $this->syncSeriesStatus();
    }

    /**
     * Computes total period.
     *
     * @param ?string $active
     * @param ?string $storage
     * @param bool $isPermanent
     *
     * @return string
     */
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

    /**
     * Lifecycle hook executed after updating the is permanent property.
     *
     * @param mixed $val
     *
     * @return void
     */
    public function updatedIsPermanent($val): void
    {
        if ($val) {
            $this->active_period = '';
            $this->storage_period = '';
            $this->total_period = 'Permanent';
        } else {
            $this->total_period = $this->computeTotalPeriod($this->active_period, $this->storage_period, false);
        }
    }

    /**
     * Lifecycle hook executed after updating the series title property.
     * @return void
     */
    public function updatedSeriesTitle(): void
    {
        $this->showParentDropdown = true;
        $this->syncSeriesStatus();
    }

    /**
     * Lifecycle hook executed after updating the subsections property.
     */
    public function updatedSubsections(): void
    {
        $this->syncSeriesStatus();
    }

    /**
     * Resets messages and clears active selections.
     * @return void
     */
    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * Resets form and clears active selections.
     * @return void
     */
    public function clearForm(): void
    {
        $this->editingSeriesId = null;
        $this->item_number = null;
        $this->series_title = '';
        $this->parent_id = null;
        $this->active_period = '';
        $this->storage_period = '';
        $this->total_period = '';
        $this->remarks = '';
        $this->is_permanent = false;
        $this->is_superseding = false;
        $this->subsections = [];
    }

    /**
     * Persists draft changes to the database.
     * @return void
     */
    public function saveDraft(): void
    {
        $rateCheck = \App\Services\RateLimiterService::check('rdp_create');
        if (!$rateCheck['allowed']) {
            $this->errorMessage = $rateCheck['message'];
            return;
        }

        $this->clearMessages();

        $parentTitle = mb_strtoupper(trim($this->series_title));
        if (empty($parentTitle)) {
            $this->errorMessage = 'Record Series Title is required to save draft.';
            return;
        }

        foreach ($this->subsections as $idx => $sub) {
            if (empty(trim((string)$sub))) {
                $level = ($idx === 0) ? 'Subsection' : 'Sub-subsection';
                $this->errorMessage = "Please enter a title for {$level} or remove it before saving.";
                return;
            }
        }

        $status = $this->resolveCurrentSeriesStatus();
        if ($status['is_verified']) {
            $this->errorMessage = "Cannot save draft: '{$status['leaf']->series_title}' is already an officially verified record series.";
            return;
        }

        $allTitles = [$parentTitle];
        foreach ($this->subsections as $sub) {
            $trimmed = mb_strtoupper(trim($sub));
            if (!empty($trimmed)) {
                $allTitles[] = $trimmed;
            }
        }

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;

            $retentionId = null;
            $computedTotal = $this->computeTotalPeriod($this->active_period, $this->storage_period, $this->is_permanent);
            $activePeriod = $this->is_permanent ? 'Permanent' : (trim($this->active_period) ?: null);
            $storagePeriod = $this->is_permanent ? 'Permanent' : (trim($this->storage_period) ?: null);
            $totalPeriod = $computedTotal ?: null;

            if (!empty($activePeriod) || !empty($storagePeriod) || !empty($totalPeriod)) {
                $retentionId = DB::table('rdp_retention_period')->insertGetId([
                    'active_period'  => $activePeriod ?: null,
                    'storage_period' => $storagePeriod ?: null,
                    'total_period'   => $totalPeriod ?: null,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            $currentParentId = null;

            foreach ($allTitles as $idx => $t) {
                $isLeaf = ($idx === count($allTitles) - 1);

                $existing = DB::table('rdp_record_series')
                    ->where('series_title', 'ilike', $t)
                    ->where('parent_id', $currentParentId)
                    ->where('is_active', true)
                    ->first();

                // If ancestor node is officially verified, protect it and reuse its id
                if ($existing && (bool)($existing->is_verified ?? false)) {
                    $currentParentId = $existing->id;
                    continue;
                }

                $seriesData = [
                    'series_title'                  => $t,
                    'parent_id'                     => $currentParentId,
                    'retention_period'              => $isLeaf ? $retentionId : null,
                    'is_retention_period_permanent'  => $isLeaf ? $this->is_permanent : false,
                    'is_superseding'                => $isLeaf ? $this->is_superseding : false,
                    'recorded_at_office'            => $userOfficeCode,
                    'created_by'                    => $user?->id,
                    'is_verified'                   => false,
                    'is_active'                     => true,
                    'updated_at'                    => now(),
                ];

                if ($existing) {
                    DB::table('rdp_record_series')->where('id', $existing->id)->update($seriesData);
                    $currentParentId = $existing->id;
                } else {
                    $seriesData['created_at'] = now();
                    $currentParentId = DB::table('rdp_record_series')->insertGetId($seriesData);
                }

                if ($isLeaf) {
                    \App\Services\RdpRemarksService::saveForSeries($currentParentId, $this->remarks);
                }
            }

            DB::commit();

            $this->successMessage = "Record Series schedule saved as draft successfully! You can view and manage this in Draft Records & Disposition Schedule.";
            $this->clearForm();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Error saving draft: ' . $e->getMessage();
        }
    }

    /**
     * Submits and stages the proposed Record Series schedule into NAP Form 2.
     * @return void
     */
    public function submitToNapForm2(): void
    {
        $rateCheck = \App\Services\RateLimiterService::check('rdp_create');
        if (!$rateCheck['allowed']) {
            $this->errorMessage = $rateCheck['message'];
            return;
        }

        $this->clearMessages();

        $parentTitle = mb_strtoupper(trim($this->series_title));
        if (empty($parentTitle)) {
            $this->errorMessage = 'Record Series Title is required.';
            return;
        }

        foreach ($this->subsections as $idx => $sub) {
            if (empty(trim((string)$sub))) {
                $level = ($idx === 0) ? 'Subsection' : 'Sub-subsection';
                $this->errorMessage = "Please enter a title for {$level} or remove it before submitting.";
                return;
            }
        }

        $status = $this->resolveCurrentSeriesStatus();
        if ($status['is_verified']) {
            $this->errorMessage = "Cannot submit: '{$status['leaf']->series_title}' is already an officially verified record series in the catalog.";
            return;
        }

        if (!$this->is_permanent && empty(trim($this->active_period)) && empty(trim($this->storage_period))) {
            $this->errorMessage = 'Please enter at least an Active Period or a Storage Period (or check Permanent Record Series).';
            return;
        }

        $allTitles = [$parentTitle];
        foreach ($this->subsections as $sub) {
            $trimmed = mb_strtoupper(trim($sub));
            if (!empty($trimmed)) {
                $allTitles[] = $trimmed;
            }
        }

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;

            $retentionId = null;
            $computedTotal = $this->computeTotalPeriod($this->active_period, $this->storage_period, $this->is_permanent);
            $activePeriod = $this->is_permanent ? 'Permanent' : (trim($this->active_period) ?: null);
            $storagePeriod = $this->is_permanent ? 'Permanent' : (trim($this->storage_period) ?: null);
            $totalPeriod = $computedTotal ?: null;

            if ($this->editingSeriesId && count($allTitles) === 1) {
                $existingSeries = DB::table('rdp_record_series')->where('id', $this->editingSeriesId)->first();
                if ($existingSeries && !(bool)($existingSeries->is_verified ?? false)) {
                    $retentionId = $existingSeries->retention_period;
                }
            }

            if (!empty($activePeriod) || !empty($storagePeriod) || !empty($totalPeriod)) {
                $retentionData = [
                    'active_period'  => $activePeriod ?: null,
                    'storage_period' => $storagePeriod ?: null,
                    'total_period'   => $totalPeriod ?: null,
                    'updated_at'     => now(),
                ];

                if ($retentionId) {
                    DB::table('rdp_retention_period')->where('id', $retentionId)->update($retentionData);
                } else {
                    $retentionData['created_at'] = now();
                    $retentionId = DB::table('rdp_retention_period')->insertGetId($retentionData);
                }
            }

            $currentParentId = null;

            foreach ($allTitles as $idx => $t) {
                $isLeaf = ($idx === count($allTitles) - 1);

                if ($idx === 0 && $this->editingSeriesId) {
                    $existing = DB::table('rdp_record_series')->where('id', $this->editingSeriesId)->first();
                } else {
                    $existing = DB::table('rdp_record_series')
                        ->where('series_title', 'ilike', $t)
                        ->where('parent_id', $currentParentId)
                        ->where('is_active', true)
                        ->first();
                }

                // If ancestor is officially verified, protect it!
                if ($existing && (bool)($existing->is_verified ?? false)) {
                    $currentParentId = $existing->id;
                    continue;
                }

                $seriesData = [
                    'series_title'                  => $t,
                    'parent_id'                     => $currentParentId,
                    'retention_period'              => $isLeaf ? $retentionId : null,
                    'is_retention_period_permanent'  => $isLeaf ? $this->is_permanent : false,
                    'is_superseding'                => $isLeaf ? $this->is_superseding : false,
                    'recorded_at_office'            => $userOfficeCode,
                    'created_by'                    => $user?->id,
                    'is_verified'                   => false, // Always false for new proposals staged for NAP Form 2!
                    'is_active'                     => true,
                    'updated_at'                    => now(),
                ];

                if ($existing) {
                    DB::table('rdp_record_series')->where('id', $existing->id)->update($seriesData);
                    $currentParentId = $existing->id;
                } else {
                    $seriesData['created_at'] = now();
                    $currentParentId = DB::table('rdp_record_series')->insertGetId($seriesData);
                }

                if ($isLeaf) {
                    \App\Services\RdpRemarksService::saveForSeries($currentParentId, $this->remarks);
                }
            }

            DB::commit();

            $this->successMessage = "Record Series schedule successfully saved and recorded in NAP Form 2! It will be reviewed in the evaluation & approval pipeline before joining the official Record Series catalog.";
            $this->clearForm();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Error submitting Record Series: ' . $e->getMessage();
        }
    }

    /**
     * Persists record series changes to the database (routes to submitToNapForm2).
     * @return void
     */
    public function saveRecordSeries(): void
    {
        $this->submitToNapForm2();
    }

    /**
     * Supplies view data, query collections, and metrics to the Blade template.
     * @return array
     */
    public function with(): array
    {
        $term = strtolower(trim($this->series_title));

        $parentSuggestionsQuery = DB::table('rdp_record_series')
            ->leftJoin('rdp_record_series_type', 'rdp_record_series.series_type', '=', 'rdp_record_series_type.id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
            ->select([
                'rdp_record_series.id',
                'rdp_record_series.series_title',
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

        return [
            'parentSuggestions' => $parentSuggestions,
        ];
    }
}; ?>

<div class="schedule-form-page" style="padding: 24px; max-width: 1200px; margin: 0 auto;">
    @push('styles')
        @vite(['resources/css/rdp/records-and-disposition-schedule.css'])
    @endpush

    <!-- Premium Header -->
    <div class="rds-page-header">
        <div>
            <h2>Records and Disposition Schedule — Add Series</h2>
            <p>Define and configure Record Series retention schedules</p>
        </div>
        @if($editingSeriesId)
            <button type="button" wire:click="clearForm" class="rds-header-action">
                + Add New Record Series Instead
            </button>
        @endif
    </div>

    <!-- Alert Messages -->
    @if ($successMessage)
        <div class="rds-alert rds-alert-success">
            <span>✅ {{ $successMessage }}</span>
            <button type="button" wire:click="clearMessages" class="rds-alert-close">✕</button>
        </div>
    @endif
    @if ($errorMessage)
        <div class="rds-alert rds-alert-error">
            <span>❌ {{ $errorMessage }}</span>
            <button type="button" wire:click="clearMessages" class="rds-alert-close">✕</button>
        </div>
    @endif

    @php
        $seriesStatus = $this->resolveCurrentSeriesStatus();
        $isLeafVerified = (bool)($seriesStatus['is_verified'] ?? false);
        $hasTitle = !empty(trim($series_title));
        $lastSubVal = !empty($subsections) ? end($subsections) : null;
        $canAddSub = $hasTitle && (empty($subsections) || !empty(trim((string)$lastSubVal)));
    @endphp

    <!-- Main Form Card -->
    <div class="rds-form-card">
        <div style="padding: 28px 32px;">
            <!-- Parent Record Series Title -->
            <div class="rds-form-row" style="position: relative;" wire:click.outside="$set('showParentDropdown', false)">
                <span class="rds-label rds-label-required">Record Series Title</span>
                <div style="flex: 1; position: relative;">
                    <input type="text"
                           class="rds-input"
                           wire:model.live.debounce.150ms="series_title"
                           wire:focus="$set('showParentDropdown', true)"
                           placeholder="E.G. GATE PASSES, RECEIPTS, FINANCIAL STATEMENTS"
                           style="width: 100%; font-weight: 700;"
                           autocomplete="off">

                    @if($showParentDropdown && count($parentSuggestions) > 0)
                        <div class="rds-autocomplete-dropdown" style="top: 100%; left: 0; right: 0; z-index: 1050;">
                            @foreach($parentSuggestions as $suggestion)
                                <div wire:click="selectParentSuggestion('{{ addslashes($suggestion->series_title) }}')" class="rds-autocomplete-item" style="display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 12px;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        @if(!empty($suggestion->shorted_type))
                                            <span style="font-size: 10.5px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px; letter-spacing: 0.5px; text-transform: uppercase;">{{ $suggestion->shorted_type }}</span>
                                        @endif
                                        <span style="font-weight: 700; color: #0f172a;">{{ $suggestion->series_title }}</span>
                                    </div>
                                    @if(!empty($suggestion->recorded_at_office))
                                        <span style="font-size: 10px; font-weight: 700; color: #64748b; background: #f1f5f9; border: 1px solid #cbd5e1; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">
                                            {{ $suggestion->recorded_office_name ?? $suggestion->recorded_at_office }}
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <!-- Subsections with Tree Hierarchy -->
            @foreach($subsections as $index => $sub)
                @php
                    $parentLabel = ($index === 0) 
                        ? ($series_title ? trim($series_title) : 'Parent Series') 
                        : (!empty($subsections[$index - 1]) ? trim($subsections[$index - 1]) : ('Subsection #' . $index));
                @endphp
                <div class="rds-subsection-block"
                     wire:click.outside="$set('activeSubDropdownIndex', null)"
                     style="margin-left: {{ 220 + min($index * 24, 72) }}px; margin-bottom: 14px; position: relative; z-index: {{ max(40 - $index, 10) }};">
                    <!-- Hierarchy context label -->
                    <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 5px; padding-left: 28px; font-size: 11.5px;">
                        <span style="font-weight: 700; color: {{ $index === 0 ? '#2563eb' : '#7c3aed' }};">
                            {{ $index === 0 ? 'Subsection' : 'Sub-subsection #' . ($index + 1) }}
                        </span>
                        <span style="color: #64748b; font-weight: 500;">
                            under <strong style="font-weight: 700; color: #1e293b;">{{ $parentLabel }}</strong>
                        </span>
                    </div>

                    <!-- Row with L-tree connector, input, and remove button -->
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="display: flex; align-items: center; justify-content: center; width: 20px; height: 38px; flex-shrink: 0;" title="{{ $index === 0 ? 'Subsection' : 'Sub-subsection' }}">
                            <svg width="20" height="38" viewBox="0 0 20 38" fill="none">
                                <path d="M 5 0 L 5 19 L 18 19" stroke="{{ $index === 0 ? '#3b82f6' : '#8b5cf6' }}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                            </svg>
                        </div>

                        <div style="flex: 1; position: relative;">
                            <input type="text"
                                   class="rds-input"
                                   wire:model.live="subsections.{{ $index }}"
                                   wire:focus="$set('activeSubDropdownIndex', {{ $index }})"
                                   placeholder="{{ $index === 0 ? 'SUBSECTION TITLE...' : 'SUB-SUBSECTION TITLE...' }}"
                                   style="width: 100%; font-weight: 600;"
                                   autocomplete="off">

                            @php
                                $currentSubSuggestions = $this->getSubSuggestions($index);
                            @endphp

                            @if($activeSubDropdownIndex === $index && count($currentSubSuggestions) > 0)
                                <div class="rds-autocomplete-dropdown" style="top: 100%; left: 0; right: 0; z-index: 1050;">
                                    @foreach($currentSubSuggestions as $suggestion)
                                        <div wire:click="selectSubSuggestion({{ $index }}, '{{ addslashes($suggestion->series_title) }}')" class="rds-autocomplete-item" style="display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 12px;">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                @if(!empty($suggestion->shorted_type))
                                                    <span style="font-size: 10.5px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px; letter-spacing: 0.5px; text-transform: uppercase;">{{ $suggestion->shorted_type }}</span>
                                                @endif
                                                <span style="font-weight: 700; color: #0f172a;">{{ $suggestion->series_title }}</span>
                                            </div>
                                            @if(!empty($suggestion->recorded_at_office))
                                                <span style="font-size: 10px; font-weight: 700; color: #64748b; background: #f1f5f9; border: 1px solid #cbd5e1; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">
                                                    {{ $suggestion->recorded_office_name ?? $suggestion->recorded_at_office }}
                                                </span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <button type="button" wire:click="removeSubsection({{ $index }})" class="rds-btn rds-btn-danger" style="padding: 0 12px; height: 38px; line-height: 1;" title="Remove this subsection">&times;</button>
                    </div>
                </div>
            @endforeach

            <!-- Add Subsection Button with Restriction Check -->
            <div style="margin-left: {{ 220 + min(count($subsections) * 24, 72) }}px; margin-top: 6px; margin-bottom: 22px;">
                <button type="button" wire:click="addSubsection" class="rds-btn rds-btn-ghost"
                        @if(!$canAddSub) disabled style="opacity: 0.55; cursor: not-allowed;" title="Please enter a title for the current subsection before adding another." @endif>
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                    {{ count($subsections) === 0 ? '+ Add Subsection (Child)' : '+ Add Sub-subsection (Level ' . (count($subsections) + 2) . ')' }}
                </button>
            </div>

            <!-- Hierarchy Status Notice: Officially Verified (Locked) vs. Proposed for NAP Form 2 -->
            @if($hasTitle && $isLeafVerified)
                <div style="margin-left: 220px; margin-bottom: 24px; background: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #2563eb; border-radius: 8px; padding: 12px 16px; display: flex; align-items: flex-start; gap: 12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 2px;">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                    <div>
                        <div style="font-size: 13px; font-weight: 700; color: #1e40af; margin-bottom: 3px; display: flex; align-items: center; gap: 8px;">
                            <span>Officially Verified Record Series</span>
                            <span style="font-size: 10px; font-weight: 800; background: #2563eb; color: #ffffff; padding: 2px 7px; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.5px;">LOCKED</span>
                        </div>
                        <div style="font-size: 12px; color: #1e3a8a; line-height: 1.5;">
                            This Record Series is already officially verified in the catalog. Its retention schedule is strictly enforced and cannot be modified or overwritten from this page.
                        </div>
                    </div>
                </div>
            @elseif($hasTitle && !$isLeafVerified)
                <div style="margin-left: 220px; margin-bottom: 24px; background: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #f59e0b; border-radius: 8px; padding: 12px 16px; display: flex; align-items: flex-start; gap: 12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 2px;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <div>
                        <div style="font-size: 13px; font-weight: 700; color: #92400e; margin-bottom: 3px;">
                            Recorded in NAP Form 2
                        </div>
                        <div style="font-size: 12px; color: #b45309; line-height: 1.5;">
                            This Record Series you chose will be recorded in <strong>NAP Form 2 (Records Disposition Schedule)</strong> as a proposed schedule awaiting evaluation and verification before joining the official catalog.
                        </div>
                    </div>
                </div>
            @endif

            <!-- Permanent Record Toggle -->
            <div class="rds-form-row">
                <span class="rds-label">Permanent Record</span>
                <div style="flex: 1; display: flex; align-items: center; gap: 8px;">
                    <label class="rds-permanent-label" @if($isLeafVerified) style="opacity: 0.7; cursor: not-allowed;" @endif>
                        <input type="checkbox" wire:model.live="is_permanent" {{ $isLeafVerified ? 'disabled' : '' }}>
                        Permanent Record Series
                    </label>
                    <span class="rds-permanent-hint">(Disables period inputs and sets retention to Permanent)</span>
                </div>
            </div>

            <!-- Active Period -->
            <div class="rds-form-row {{ ($is_permanent || $isLeafVerified) ? 'rds-row-disabled' : '' }}">
                <span class="rds-label">Active Period</span>
                <input type="text" class="rds-input" wire:model.live.debounce.200ms="active_period" placeholder="E.G. 6 MONTHS, 1 YEAR" {{ ($is_permanent || $isLeafVerified) ? 'disabled' : '' }}>
            </div>

            <!-- Storage Period -->
            <div class="rds-form-row {{ ($is_permanent || $isLeafVerified) ? 'rds-row-disabled' : '' }}">
                <span class="rds-label">Storage Period</span>
                <input type="text" class="rds-input" wire:model.live.debounce.200ms="storage_period" placeholder="E.G. 1 YEAR, 4 YEARS" {{ ($is_permanent || $isLeafVerified) ? 'disabled' : '' }}>
            </div>

            <!-- Total Period (Computed) -->
            <div class="rds-form-row {{ $is_permanent ? 'rds-row-disabled' : '' }}">
                <span class="rds-label">Total Period</span>
                <div class="rds-computed-box">
                    {{ $this->computeTotalPeriod($active_period, $storage_period, $is_permanent) ?: '— (Auto-calculated)' }}
                </div>
            </div>

            <!-- Remarks -->
            <div class="rds-form-row" style="align-items: flex-start;">
                <span class="rds-label" style="margin-top: 10px;">Remarks</span>
                <textarea class="rds-input" wire:model="remarks" rows="3" placeholder="Enter disposition instructions or remarks..." style="font-family: inherit; text-transform: none !important;" {{ $isLeafVerified ? 'disabled' : '' }}></textarea>
            </div>

            <!-- Auto-Supersede Toggle -->
            <div class="rds-form-row" style="align-items: center;">
                <span class="rds-label">Supersede Policy</span>
                <div style="flex: 1; display: flex; align-items: center; gap: 8px;">
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: #1e3a8a; cursor: {{ $isLeafVerified ? 'not-allowed' : 'pointer' }}; opacity: {{ $isLeafVerified ? '0.7' : '1' }};">
                        <input type="checkbox" wire:model="is_superseding" style="width: 16px; height: 16px; accent-color: #2563eb;" {{ $isLeafVerified ? 'disabled' : '' }}>
                        Auto-Supersede Prior Edition (revises older active records when a newer edition is added)
                    </label>
                </div>
            </div>
        </div>

        <!-- ═══════ ACTIONS BAR ═══════ -->
        <div class="rds-actions-bar">
            <button type="button" wire:click="clearForm" class="rds-btn rds-btn-secondary">CLEAR FORM</button>

            @if($isLeafVerified)
                <button type="button" disabled class="rds-btn rds-btn-secondary" style="opacity: 0.65; cursor: not-allowed; display: inline-flex; align-items: center; gap: 8px; background: #f1f5f9; color: #475569; border: 1.5px solid #cbd5e1;" title="Officially verified record series cannot be modified here.">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                    RECORD SERIES LOCKED (VERIFIED)
                </button>
            @else
                <button type="button" wire:click="saveDraft" class="rds-btn rds-btn-secondary">SAVE DRAFT</button>
                <button type="button" wire:click="submitToNapForm2" class="rds-btn rds-btn-primary rds-btn-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                    SUBMIT TO NAP FORM 2
                </button>
            @endif
        </div>
    </div>
</div>