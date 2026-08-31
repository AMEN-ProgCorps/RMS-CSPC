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

    public string $successMessage = '';
    public string $errorMessage = '';

    // Subsections & Hierarchy State
    public array $subsections = [];
    public bool $showParentDropdown = false;
    public ?int $activeSubDropdownIndex = null;

    public function selectParentSuggestion(string $title): void
    {
        $this->series_title = mb_strtoupper($title);
        $this->showParentDropdown = false;
    }

    public function selectSubSuggestion(int $index, string $title): void
    {
        if (isset($this->subsections[$index])) {
            $this->subsections[$index] = mb_strtoupper($title);
        }
        $this->activeSubDropdownIndex = null;
    }

    public function getSubSuggestions(int $index): \Illuminate\Support\Collection
    {
        $parentTitleAbove = ($index === 0)
            ? trim($this->series_title)
            : trim($this->subsections[$index - 1] ?? '');

        if (empty($parentTitleAbove)) {
            return collect();
        }

        $parentRecord = DB::table('rdp_record_series')
            ->where('series_title', 'ilike', $parentTitleAbove)
            ->first();

        if (!$parentRecord) {
            return collect();
        }

        $query = DB::table('rdp_record_series')
            ->select('series_title')
            ->where('parent_id', $parentRecord->id);

        $currentSubInput = trim($this->subsections[$index] ?? '');
        if (!empty($currentSubInput)) {
            $query->where('series_title', 'ilike', '%' . $currentSubInput . '%');
        }

        return $query->distinct('series_title')->orderBy('series_title', 'asc')->limit(8)->get();
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
            return mb_strtoupper($storage);
        }

        if (empty($storage)) {
            return mb_strtoupper($active);
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
            return mb_strtoupper(implode(' ', $parts));
        }

        return mb_strtoupper($active . ' + ' . $storage);
    }

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

    public function updatedSeriesTitle(): void
    {
        $this->showParentDropdown = true;
    }

    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

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
        $this->subsections = [];
    }

    public function saveDraft(): void
    {
        $this->clearMessages();

        $parentTitle = mb_strtoupper(trim($this->series_title));
        if (empty($parentTitle)) {
            $this->errorMessage = 'Record Series Title is required to save draft.';
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
            $lastCreatedSeriesId = null;

            foreach ($allTitles as $idx => $t) {
                $isLeaf = ($idx === count($allTitles) - 1);

                $existing = DB::table('rdp_record_series')
                    ->where('series_title', 'ilike', $t)
                    ->where('parent_id', $currentParentId)
                    ->first();

                $seriesData = [
                    'series_title'                 => $t,
                    'parent_id'                    => $currentParentId,
                    'retention_period'             => $isLeaf ? $retentionId : null,
                    'is_retention_period_permanent' => $isLeaf ? $this->is_permanent : false,
                    'remarks'                      => $isLeaf ? (trim($this->remarks) ?: null) : null,
                    'recorded_at_office'           => $userOfficeCode,
                    'is_verified'                  => false,
                    'is_active'                    => true,
                    'updated_at'                   => now(),
                ];

                if ($existing) {
                    DB::table('rdp_record_series')->where('id', $existing->id)->update($seriesData);
                    $currentParentId = $existing->id;
                } else {
                    $seriesData['created_at'] = now();
                    $currentParentId = DB::table('rdp_record_series')->insertGetId($seriesData);
                }

                if ($isLeaf) {
                    $lastCreatedSeriesId = $currentParentId;
                }
            }

            // Create centralized rdp_main_pending_id & cluster entry
            $mainPendingTbl = \Illuminate\Support\Facades\Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';
            $mainPendingId = DB::table($mainPendingTbl)->insertGetId([
                'status'     => 'UNUSED',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('rdp_pending_record_series')->insert([
                'cluster_id'   => $mainPendingId,
                'cluster_name' => 'Schedule Draft Submission — ' . $parentTitle,
                'status_id'    => 1, // Pending Verification
                'office'       => $userOfficeCode,
                'created_by'   => $user->id,
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            if ($lastCreatedSeriesId) {
                DB::table('rdp_grouped_record_series')->insert([
                    'group_head'       => $mainPendingId,
                    'record_series_id' => $lastCreatedSeriesId,
                    'is_active'        => true,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            DB::commit();

            $this->successMessage = "Record Series schedule saved as draft successfully!";
            $this->clearForm();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Error saving draft: ' . $e->getMessage();
        }
    }

    public function saveRecordSeries(): void
    {
        $this->clearMessages();

        $parentTitle = mb_strtoupper(trim($this->series_title));
        if (empty($parentTitle)) {
            $this->errorMessage = 'Record Series Title is required.';
            return;
        }

        $allTitles = [$parentTitle];
        foreach ($this->subsections as $sub) {
            $trimmed = mb_strtoupper(trim($sub));
            if (!empty($trimmed)) {
                $allTitles[] = $trimmed;
            }
        }

        if (!$this->is_permanent && empty(trim($this->active_period)) && empty(trim($this->storage_period))) {
            $this->errorMessage = 'Please enter at least an Active Period or a Storage Period (or check Permanent Record Series).';
            return;
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
                $retentionId = $existingSeries?->retention_period;
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
                        ->first();
                }

                $seriesData = [
                    'series_title'                 => $t,
                    'parent_id'                    => $currentParentId,
                    'retention_period'             => $isLeaf ? $retentionId : null,
                    'is_retention_period_permanent' => $isLeaf ? $this->is_permanent : false,
                    'remarks'                      => $isLeaf ? (trim($this->remarks) ?: null) : null,
                    'recorded_at_office'           => $userOfficeCode,
                    'is_verified'                  => $existing ? (bool)$existing->is_verified : false,
                    'is_active'                    => true,
                    'updated_at'                   => now(),
                ];

                if ($existing) {
                    DB::table('rdp_record_series')->where('id', $existing->id)->update($seriesData);
                    $currentParentId = $existing->id;
                } else {
                    $seriesData['created_at'] = now();
                    $currentParentId = DB::table('rdp_record_series')->insertGetId($seriesData);
                }
            }

            DB::commit();

            $this->successMessage = "Record Series hierarchy saved successfully!";
            $this->clearForm();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Error saving Record Series: ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        $term = strtolower(trim($this->series_title));

        $parentSuggestionsQuery = DB::table('rdp_record_series')
            ->leftJoin('rdp_record_series_type', 'rdp_record_series.series_type', '=', 'rdp_record_series_type.id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office'), 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
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
                        <div class="rds-autocomplete-dropdown">
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

            <!-- Subsections -->
            @foreach($subsections as $index => $sub)
                <div class="rds-subsection-row"
                     wire:click.outside="$set('activeSubDropdownIndex', null)"
                     style="margin-left: {{ min(($index + 1) * 20, 80) }}px; position: relative;">
                    <span class="rds-subsection-label">
                        Subsection #{{ $index + 1 }} (Child of {{ $index === 0 ? ($series_title ?: 'Parent') : 'Subsection #' . $index }}):
                    </span>
                    <div style="flex: 1; display: flex; gap: 8px; position: relative;">
                        <input type="text"
                               class="rds-input"
                               wire:model.live="subsections.{{ $index }}"
                               wire:focus="$set('activeSubDropdownIndex', {{ $index }})"
                               placeholder="E.G. {{ $index === 0 ? 'OFFICIAL RECEIPTS' : 'TAX RECEIPTS' }}"
                               style="flex: 1;"
                               autocomplete="off">
                        <button type="button" wire:click="removeSubsection({{ $index }})" class="rds-btn rds-btn-danger" style="padding: 0 12px; font-size: 14px;">&times;</button>
                    </div>

                    @php
                        $currentSubSuggestions = $this->getSubSuggestions($index);
                    @endphp

                    @if($activeSubDropdownIndex === $index && count($currentSubSuggestions) > 0)
                        <div class="rds-autocomplete-dropdown" style="left: 200px; right: 0; top: 100%;">
                            @foreach($currentSubSuggestions as $suggestion)
                                <div wire:click="selectSubSuggestion({{ $index }}, '{{ addslashes($suggestion->series_title) }}')" class="rds-autocomplete-item">
                                    {{ $suggestion->series_title }}
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach

            <!-- Add Subsection Button (Aligned directly under input) -->
            <div class="rds-form-row" style="margin-top: -4px; margin-bottom: 22px;">
                <span class="rds-label"></span>
                <div style="flex: 1; margin-left: {{ count($subsections) > 0 ? min(count($subsections) * 20, 80) : 0 }}px;">
                    <button type="button" wire:click="addSubsection" class="rds-btn rds-btn-ghost">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                        Add Subsection (Child)
                    </button>
                </div>
            </div>

            <!-- Permanent Record Toggle -->
            <div class="rds-form-row">
                <span class="rds-label">Permanent Record</span>
                <div style="flex: 1; display: flex; align-items: center; gap: 8px;">
                    <label class="rds-permanent-label">
                        <input type="checkbox" wire:model.live="is_permanent">
                        Permanent Record Series
                    </label>
                    <span class="rds-permanent-hint">(Disables period inputs and sets retention to Permanent)</span>
                </div>
            </div>

            <!-- Active Period -->
            <div class="rds-form-row {{ $is_permanent ? 'rds-row-disabled' : '' }}">
                <span class="rds-label">Active Period</span>
                <input type="text" class="rds-input" wire:model.live.debounce.200ms="active_period" placeholder="E.G. 6 MONTHS, 1 YEAR" {{ $is_permanent ? 'disabled' : '' }}>
            </div>

            <!-- Storage Period -->
            <div class="rds-form-row {{ $is_permanent ? 'rds-row-disabled' : '' }}">
                <span class="rds-label">Storage Period</span>
                <input type="text" class="rds-input" wire:model.live.debounce.200ms="storage_period" placeholder="E.G. 1 YEAR, 4 YEARS" {{ $is_permanent ? 'disabled' : '' }}>
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
                <textarea class="rds-input" wire:model="remarks" rows="3" placeholder="Enter disposition instructions or remarks..." style="font-family: inherit; text-transform: none !important;"></textarea>
            </div>
        </div>

        <!-- ═══════ ACTIONS BAR ═══════ -->
        <div class="rds-actions-bar">
            <button type="button" wire:click="clearForm" class="rds-btn rds-btn-secondary">CLEAR FORM</button>
            <button type="button" wire:click="saveDraft" class="rds-btn rds-btn-secondary">SAVE DRAFT</button>
            <button type="button" wire:click="saveRecordSeries" class="rds-btn rds-btn-primary rds-btn-lg">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                {{ $editingSeriesId ? 'UPDATE RECORD SERIES' : 'SAVE RECORD SERIES' }}
            </button>
        </div>
    </div>
</div>