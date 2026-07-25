<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.rdp')] #[Title('Records and Disposition Schedule')] class extends Component {
    use WithPagination;

    // Filter & Search State for Schedule Table
    public string $search = '';
    public string $statusFilter = '';

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
        $this->series_title = $title;
        $this->showParentDropdown = false;
    }

    public function selectSubSuggestion(int $index, string $title): void
    {
        if (isset($this->subsections[$index])) {
            $this->subsections[$index] = $title;
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

    public function updatedIsPermanent($value): void
    {
        // Keep inputs clean, styling handles opacity & disabled state
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    public function clearForm(): void
    {
        $this->clearMessages();
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
        $this->showParentDropdown = false;
        $this->activeSubDropdownIndex = null;
    }

    public function selectSeriesForEdit(int $seriesId): void
    {
        $this->clearMessages();
        $this->editingSeriesId = $seriesId;

        $series = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->where('rdp_record_series.id', $seriesId)
            ->select([
                'rdp_record_series.*',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
            ])
            ->first();

        if ($series) {
            $this->item_number = $series->item_number ? (string)$series->item_number : null;
            $this->series_title = $series->series_title ?? '';
            $this->parent_id = $series->parent_id ? (int)$series->parent_id : null;
            $this->active_period = $series->active_period ?? '';
            $this->storage_period = $series->storage_period ?? '';
            $this->total_period = $series->total_period ?? '';
            $this->remarks = $series->remarks ?? '';
            $this->is_permanent = (bool)($series->is_retention_period_permanent ?? (strtolower($series->active_period ?? '') === 'permanent'));
            $this->subsections = [];
        }
    }

    public function saveRecordSeries(): void
    {
        $this->clearMessages();

        $parentTitle = trim($this->series_title);
        if (empty($parentTitle)) {
            $this->errorMessage = 'Record Series Title is required.';
            return;
        }

        $allTitles = [$parentTitle];
        foreach ($this->subsections as $sub) {
            if (!empty(trim($sub))) {
                $allTitles[] = trim($sub);
            }
        }

        if (!$this->is_permanent && empty(trim($this->active_period)) && empty(trim($this->storage_period))) {
            $this->errorMessage = 'Please enter at least an Active Period or a Storage Period (or check Permanent Record Series).';
            return;
        }

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOfficeCode = $user?->details?->office?->office_code;

            // Handle Retention Period for the leaf series
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
                        ->where('series_title', $t)
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

    private function resolveEffectiveRetention(array $allSeriesMap, object $record): object
    {
        $current = $record;
        $visited = [];

        while ($current) {
            $hasActive = !empty(trim($current->active_period ?? ''));
            $hasStorage = !empty(trim($current->storage_period ?? ''));
            $hasTotal = !empty(trim($current->total_period ?? ''));
            $isPerm = (bool)($current->is_retention_period_permanent ?? false);

            if ($isPerm || $hasActive || $hasStorage || $hasTotal) {
                return (object)[
                    'active_period'                  => $current->active_period,
                    'storage_period'                 => $current->storage_period,
                    'total_period'                   => $current->total_period,
                    'is_retention_period_permanent' => $isPerm,
                    'inherited'                      => $current->id !== $record->id,
                ];
            }

            if (in_array($current->id, $visited, true)) {
                break;
            }
            $visited[] = $current->id;

            $pId = $current->parent_id ?? null;
            $current = ($pId && isset($allSeriesMap[$pId])) ? $allSeriesMap[$pId] : null;
        }

        return (object)[
            'active_period'                  => null,
            'storage_period'                 => null,
            'total_period'                   => null,
            'is_retention_period_permanent' => false,
            'inherited'                      => false,
        ];
    }

    private function buildTreeHierarchy(array $records): array
    {
        $byParent = [];
        foreach ($records as $r) {
            $pId = $r->parent_id ?? 0;
            $byParent[$pId][] = $r;
        }

        $ordered = [];
        $flatten = function ($parentId, $depth) use (&$flatten, &$ordered, $byParent) {
            if (!isset($byParent[$parentId])) {
                return;
            }
            foreach ($byParent[$parentId] as $item) {
                $item->depth = $depth;
                $ordered[] = $item;
                $flatten($item->id, $depth + 1);
            }
        };

        $flatten(0, 0);

        $addedIds = array_column($ordered, 'id');
        foreach ($records as $r) {
            if (!in_array($r->id, $addedIds, true)) {
                $r->depth = 0;
                $ordered[] = $r;
            }
        }

        return $ordered;
    }

    public function with(): array
    {
        $query = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
            ->select([
                'rdp_record_series.*',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
                'parent.series_title as parent_title',
            ]);

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('rdp_record_series.series_title', 'ilike', '%' . $this->search . '%')
                  ->orWhere('rdp_record_series.remarks', 'ilike', '%' . $this->search . '%')
                  ->orWhere('parent.series_title', 'ilike', '%' . $this->search . '%')
                  ->orWhereCast('rdp_record_series.item_number', 'text', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->statusFilter === 'verified') {
            $query->whereNotNull('rdp_record_series.item_number');
        } elseif ($this->statusFilter === 'user_added') {
            $query->whereNull('rdp_record_series.item_number');
        }

        $allFetched = $query->orderByRaw('rdp_record_series.item_number ASC NULLS LAST, rdp_record_series.series_title ASC')->get();
        
        $allFetchedMap = [];
        foreach ($allFetched as $item) {
            $allFetchedMap[$item->id] = $item;
        }

        $treeOrdered = $this->buildTreeHierarchy($allFetched->all());

        foreach ($treeOrdered as $item) {
            $eff = $this->resolveEffectiveRetention($allFetchedMap, $item);
            $item->effective_active = $eff->active_period;
            $item->effective_storage = $eff->storage_period;
            $item->effective_total = $eff->total_period;
            $item->effective_is_permanent = $eff->is_retention_period_permanent;
            $item->is_inherited = $eff->inherited;
        }

        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage() ?: 1;
        $perPage = 15;
        $paginatedItems = array_slice($treeOrdered, ($page - 1) * $perPage, $perPage);
        $scheduleRecords = new \Illuminate\Pagination\LengthAwarePaginator(
            $paginatedItems,
            count($treeOrdered),
            $perPage,
            $page,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
        );

        $parentSuggestions = DB::table('rdp_record_series')
            ->select('series_title')
            ->whereNull('parent_id')
            ->when(!empty($this->series_title), function ($q) {
                $q->where('series_title', 'ilike', '%' . $this->series_title . '%');
            })
            ->distinct()
            ->orderBy('series_title', 'asc')
            ->limit(8)
            ->get();

        $allSeriesSuggestions = DB::table('rdp_record_series')
            ->select('series_title')
            ->distinct()
            ->orderBy('series_title', 'asc')
            ->limit(10)
            ->get();

        return [
            'scheduleRecords'       => $scheduleRecords,
            'parentSuggestions'     => $parentSuggestions,
            'allSeriesSuggestions' => $allSeriesSuggestions,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/rdp/inventory-and-appraisal.css', 'resources/css/admin/activity_logs.css'])
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
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #ffffff;
            box-sizing: border-box;
        }
        .form-input-custom:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .btn-primary-custom {
            background: #2563eb;
            color: #ffffff;
            padding: 10px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
        }
        .btn-primary-custom:hover {
            background: #1d4ed8;
        }
    </style>

    <!-- Header Section -->
    <div class="rms-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
        <div>
            <h2 style="font-size: 1.25rem; color: #1e40af; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Records and Disposition Schedule — Record Series Form</h2>
            <p style="font-size: 13px; color: #64748b; margin: 4px 0 0 0;">Define official verified series and custom office Record Series retention schedules.</p>
        </div>
        @if($editingSeriesId)
            <button type="button" wire:click="clearForm" style="padding: 8px 16px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer;">
                + Add New Record Series Instead
            </button>
        @endif
    </div>

    <!-- Alert Notifications -->
    @if ($successMessage)
        <div style="background-color: #ecfdf5; border-left: 4px solid #10b981; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; font-weight: 600; display: flex; justify-content: space-between; align-items: center;">
            <span>✅ {{ $successMessage }}</span>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #065f46; cursor: pointer; font-weight: bold;">✕</button>
        </div>
    @endif

    @if ($errorMessage)
        <div style="background-color: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; font-weight: 600; display: flex; justify-content: space-between; align-items: center;">
            <span>❌ {{ $errorMessage }}</span>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #991b1b; cursor: pointer; font-weight: bold;">✕</button>
        </div>
    @endif

    <!-- RECORD SERIES FORM CARD -->
    <div class="form-card">
        <!-- Parent Record Series Title with Autocomplete Dropdown -->
        <div class="form-row-flex" style="position: relative;" wire:click.outside="$set('showParentDropdown', false)">
            <span class="form-label-bold">Record Series Title *:</span>
            <div style="flex: 1; position: relative;">
                <input type="text" 
                       class="form-input-custom" 
                       wire:model.live="series_title" 
                       wire:focus="$set('showParentDropdown', true)" 
                       placeholder="e.g. Gate Passes, Receipts, Financial Statements" 
                       style="width: 100%; font-weight: 700;"
                       autocomplete="off">

                @if($showParentDropdown && count($parentSuggestions) > 0)
                    <div style="position: absolute; left: 0; right: 0; top: 100%; margin-top: 4px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); z-index: 100; max-height: 180px; overflow-y: auto;">
                        @foreach($parentSuggestions as $suggestion)
                            <div wire:click="selectParentSuggestion('{{ addslashes($suggestion->series_title) }}')" style="padding: 8px 12px; font-size: 13px; color: #1e293b; cursor: pointer; border-bottom: 1px solid #f1f5f9;" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='transparent'">
                                {{ $suggestion->series_title }}
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <!-- Dynamic Subsections (Child & Sub-child Hierarchy) -->
        @foreach($subsections as $index => $sub)
            <div class="form-row-flex" 
                 wire:click.outside="$set('activeSubDropdownIndex', null)"
                 style="margin-left: {{ min(($index + 1) * 20, 80) }}px; border-left: 3px solid #2563eb; padding-left: 12px; position: relative; margin-bottom: 16px;">
                <span class="form-label-bold" style="font-size: 12.5px; color: #2563eb; min-width: 150px;">
                    Subsection #{{ $index + 1 }} (Child of {{ $index === 0 ? ($series_title ?: 'Parent') : 'Subsection #' . $index }}):
                </span>
                <div style="flex: 1; display: flex; gap: 8px; position: relative;">
                    <input type="text" 
                           class="form-input-custom" 
                           wire:model.live="subsections.{{ $index }}" 
                           wire:focus="$set('activeSubDropdownIndex', {{ $index }})" 
                           placeholder="e.g. {{ $index === 0 ? 'Official Receipts' : 'Tax Receipts' }}" 
                           style="flex: 1;"
                           autocomplete="off">
                    <button type="button" wire:click="removeSubsection({{ $index }})" title="Remove Subsection" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 6px; padding: 0 12px; cursor: pointer; font-weight: 700; font-size: 14px;">&times;</button>
                </div>

                @php
                    $currentSubSuggestions = $this->getSubSuggestions($index);
                @endphp

                @if($activeSubDropdownIndex === $index && count($currentSubSuggestions) > 0)
                    <div style="position: absolute; left: 170px; right: 0; top: 100%; margin-top: 4px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); z-index: 100; max-height: 180px; overflow-y: auto;">
                        @foreach($currentSubSuggestions as $suggestion)
                            <div wire:click="selectSubSuggestion({{ $index }}, '{{ addslashes($suggestion->series_title) }}')" style="padding: 8px 12px; font-size: 13px; color: #1e293b; cursor: pointer; border-bottom: 1px solid #f1f5f9;" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='transparent'">
                                {{ $suggestion->series_title }}
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

        <!-- Add Subsection Button -->
        <div style="margin-bottom: 20px; margin-left: {{ count($subsections) > 0 ? min(count($subsections) * 20, 80) : 0 }}px;">
            <button type="button" wire:click="addSubsection" style="background: #eff6ff; color: #2563eb; border: 1px dashed #bfdbfe; border-radius: 8px; padding: 8px 16px; font-size: 12.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                + Add Subsection (Child)
            </button>
        </div>


        <!-- Permanent Retention Option -->
        <div class="form-row-flex">
            <span class="form-label-bold">Permanent Record:</span>
            <div style="flex: 1; display: flex; align-items: center; gap: 8px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: #1e40af; cursor: pointer;">
                    <input type="checkbox" wire:model.live="is_permanent" style="width: 18px; height: 18px; cursor: pointer; accent-color: #2563eb;">
                    Permanent Record Series
                </label>
                <span style="font-size: 12.5px; color: #64748b; margin-left: 8px;">(Disables period inputs and sets retention to Permanent)</span>
            </div>
        </div>

        <!-- Active Period -->
        <div class="form-row-flex" style="{{ $is_permanent ? 'opacity: 0.4; pointer-events: none; user-select: none; transition: all 0.2s;' : 'transition: all 0.2s;' }}">
            <span class="form-label-bold">Active Period:</span>
            <input type="text" class="form-input-custom" wire:model.live.debounce.200ms="active_period" placeholder="e.g. 6 Months, 1 Year, 3 Years" {{ $is_permanent ? 'disabled' : '' }}>
        </div>

        <!-- Storage Period -->
        <div class="form-row-flex" style="{{ $is_permanent ? 'opacity: 0.4; pointer-events: none; user-select: none; transition: all 0.2s;' : 'transition: all 0.2s;' }}">
            <span class="form-label-bold">Storage Period:</span>
            <input type="text" class="form-input-custom" wire:model.live.debounce.200ms="storage_period" placeholder="e.g. 1 Year, 4 Years, 2 Years" {{ $is_permanent ? 'disabled' : '' }}>
        </div>

        <!-- Total Period (Auto-Calculated Display) -->
        <div class="form-row-flex" style="{{ $is_permanent ? 'opacity: 0.4; pointer-events: none; user-select: none;' : '' }}">
            <span class="form-label-bold">Total Period:</span>
            <div style="flex: 1; padding: 10px 14px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 700; font-size: 14px; color: #1e40af;">
                {{ $this->computeTotalPeriod($active_period, $storage_period, $is_permanent) ?: '— (Auto-calculated from Active & Storage)' }}
            </div>
        </div>

        <!-- Remarks / Disposition Provision -->
        <div class="form-row-flex" style="align-items: flex-start;">
            <span class="form-label-bold" style="margin-top: 10px;">Remarks:</span>
            <textarea class="form-input-custom" wire:model="remarks" rows="3" placeholder="Enter disposition instructions or remarks (e.g. Dispose after retention, Audit required before disposal)..." style="resize: vertical; font-family: inherit;"></textarea>
        </div>

        <!-- Form Actions Bar -->
        <div style="display: flex; gap: 12px; align-items: center; justify-content: flex-end; margin-top: 24px; padding-top: 16px; border-top: 1px solid #f1f5f9;">
            <button type="button" wire:click="clearForm" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer;">
                CLEAR FORM
            </button>

            <button type="button" class="btn-primary-custom" wire:click="saveRecordSeries">
                {{ $editingSeriesId ? 'UPDATE RECORD SERIES' : '+ SAVE RECORD SERIES' }}
            </button>
        </div>
    </div>

    <!-- SCHEDULE TABLE CARD -->
    <div class="form-card">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0;">Records Disposition Schedule Table</h3>
                <p style="font-size: 13px; color: #64748b; margin: 4px 0 0 0;">Browse official verified series and user-defined record series schedules.</p>
            </div>

            <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search record series title, remarks..." style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 260px; outline: none;">

                <select wire:model.live="statusFilter" style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff; outline: none;">
                    <option value="">All Series</option>
                    <option value="verified">✅ Verified Series (With Item No.)</option>
                    <option value="user_added">📁 User-Added / Custom Series</option>
                </select>
            </div>
        </div>

        <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 8px;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; color: #475569;">
                        <th style="padding: 12px 14px; width: 110px; text-align: center;">ITEM NO.</th>
                        <th style="padding: 12px 14px;">RECORD SERIES TITLE</th>
                        <th style="padding: 12px 14px;">ACTIVE</th>
                        <th style="padding: 12px 14px;">STORAGE</th>
                        <th style="padding: 12px 14px;">TOTAL</th>
                        <th style="padding: 12px 14px;">REMARKS / DISPOSITION PROVISION</th>
                        <th style="padding: 12px 14px; text-align: center; width: 100px;">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($scheduleRecords as $record)
                        <tr style="border-bottom: 1px solid #f1f5f9; {{ $editingSeriesId === $record->id ? 'background: #fffbeb;' : '' }}">
                            <td style="padding: 12px 14px; text-align: center; font-weight: 700;">
                                @if(!empty($record->item_number))
                                    <span style="display: inline-block; padding: 2px 8px; background: #dcfce7; color: #15803d; border-radius: 12px; font-weight: 800; font-size: 11px;">
                                        Item #{{ $record->item_number }}
                                    </span>
                                @else
                                    <span style="color: #94a3b8; font-size: 12px;">—</span>
                                @endif
                            </td>
                            <td style="padding: 12px 14px; padding-left: {{ (($record->depth ?? 0) * 26) + 14 }}px; font-weight: 700; color: #0f172a;">
                                @if(($record->depth ?? 0) > 0)
                                    <span style="color: #2563eb; font-weight: 800; font-family: monospace; margin-right: 6px;">└─</span>
                                @endif
                                {{ $record->series_title }}
                                @if(empty($record->item_number))
                                    <span style="display: inline-block; padding: 1px 6px; background: #fef3c7; color: #b45309; border-radius: 8px; font-size: 10px; font-weight: 700; margin-left: 6px;">User Added</span>
                                @endif
                            </td>
                            @php
                                $isPermSeries = (bool)($record->effective_is_permanent) || 
                                                (strtolower(trim($record->effective_total ?? '')) === 'permanent') ||
                                                (strtolower(trim($record->effective_active ?? '')) === 'permanent' && strtolower(trim($record->effective_storage ?? '')) === 'permanent');
                            @endphp

                            @if($isPermSeries)
                                <td colspan="3" style="padding: 12px 14px; text-align: center; font-weight: 800; font-size: 15px; color: #1e40af; letter-spacing: 0.5px; background: #f8fafc;">
                                    Permanent
                                    @if(!empty($record->is_inherited))
                                        <span style="font-size: 10px; font-weight: 600; color: #64748b; margin-left: 4px;">(Inherited)</span>
                                    @endif
                                </td>
                            @else
                                <td style="padding: 12px 14px; color: #1e293b;">
                                    {{ $record->effective_active ?? '—' }}
                                </td>
                                <td style="padding: 12px 14px; color: #1e293b;">
                                    {{ $record->effective_storage ?? '—' }}
                                </td>
                                <td style="padding: 12px 14px; color: #1e293b;">
                                    {{ $record->effective_total ?? '—' }}
                                    @if(!empty($record->is_inherited) && (!empty($record->effective_active) || !empty($record->effective_storage)))
                                        <span style="font-size: 10px; font-weight: 600; color: #64748b; margin-left: 4px;">(Inherited)</span>
                                    @endif
                                </td>
                            @endif
                            <td style="padding: 12px 14px; color: #475569; font-size: 12.5px;">
                                {{ $record->remarks ?? '—' }}
                            </td>
                            <td style="padding: 12px 14px; text-align: center;">
                                <button type="button" wire:click="selectSeriesForEdit({{ $record->id }})" style="padding: 5px 12px; background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer;">
                                    Edit
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="padding: 24px; text-align: center; color: #64748b; font-style: italic;">
                                No record series found matching the search criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px;">
            {{ $scheduleRecords->links() }}
        </div>
    </div>
</div>