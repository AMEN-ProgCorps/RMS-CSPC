<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.admin')] #[Title('Admin Console - Record Series')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';

    // Add Form Fields
    public string $newSeriesTitle = '';
    public ?string $newParentId = null;
    public string $newActivePeriod = '';
    public string $newStoragePeriod = '';
    public string $newTotalPeriod = '';
    public string $newRemarks = '';
    public bool $newIsPermanent = false;
    public bool $showAddForm = false;

    // Edit State
    public ?int $editingId = null;
    public string $editSeriesTitle = '';
    public ?string $editParentId = null;
    public string $editActivePeriod = '';
    public string $editStoragePeriod = '';
    public string $editTotalPeriod = '';
    public string $editRemarks = '';
    public bool $editIsActive = false;
    public bool $editIsPermanent = false;

    public string $successMessage = '';
    public string $errorMessage = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_rdp_admin)) {
            $this->redirect(route('portal'));
            return;
        }
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

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    public function toggleAddForm(): void
    {
        $this->showAddForm = !$this->showAddForm;
        if ($this->showAddForm) {
            $this->resetAddForm();
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

    public function resetAddForm(): void
    {
        $this->newSeriesTitle = '';
        $this->newParentId = null;
        $this->newActivePeriod = '';
        $this->newStoragePeriod = '';
        $this->newTotalPeriod = '';
        $this->newRemarks = '';
        $this->newIsPermanent = false;
    }

    public function addSeries(): void
    {
        $this->clearMessages();

        $title = trim($this->newSeriesTitle);
        if (empty($title)) {
            $this->errorMessage = 'Series title is required.';
            return;
        }

        // Check for duplicates
        $exists = DB::table('rdp_record_series')
            ->where('series_title', $title)
            ->exists();

        if ($exists) {
            $this->errorMessage = "A record series with title \"{$title}\" already exists.";
            return;
        }

        // Create retention period if any period fields are filled or permanent checked
        $retentionId = null;
        $computedTotal = $this->computeTotalPeriod($this->newActivePeriod, $this->newStoragePeriod, $this->newIsPermanent);
        $activePeriod = $this->newIsPermanent ? 'Permanent' : (trim($this->newActivePeriod) ?: null);
        $storagePeriod = $this->newIsPermanent ? 'Permanent' : (trim($this->newStoragePeriod) ?: null);
        $totalPeriod = $computedTotal ?: null;

        if ($this->newIsPermanent || !empty($activePeriod) || !empty($storagePeriod) || !empty($totalPeriod)) {
            $retentionId = DB::table('rdp_retention_period')->insertGetId([
                'active_period'  => $activePeriod,
                'storage_period' => $storagePeriod,
                'total_period'   => $totalPeriod,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $parentId = !empty($this->newParentId) ? (int) $this->newParentId : null;

        DB::table('rdp_record_series')->insert([
            'series_title'                 => $title,
            'parent_id'                    => $parentId,
            'retention_period'             => $retentionId,
            'is_retention_period_permanent' => $this->newIsPermanent,
            'is_verified'                  => true,
            'is_active'                    => true,
            'remarks'                      => trim($this->newRemarks) ?: null,
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]);

        // Log admin action
        DB::table('admin_logs')->insert([
            'changes'      => "Added Record Series: \"{$title}\"",
            'admin_id'     => auth()->id(),
            'what_system'  => 2,
            'when_changes' => now(),
        ]);

        $this->successMessage = "Record Series \"{$title}\" has been created successfully.";
        $this->resetAddForm();
        $this->showAddForm = false;
    }

    public function startEdit(int $seriesId): void
    {
        $this->clearMessages();
        $this->editingId = $seriesId;

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
            $this->editSeriesTitle = $series->series_title ?? '';
            $this->editParentId = $series->parent_id ? (string) $series->parent_id : null;
            $this->editActivePeriod = $series->active_period ?? '';
            $this->editStoragePeriod = $series->storage_period ?? '';
            $this->editTotalPeriod = $series->total_period ?? '';
            $this->editRemarks = $series->remarks ?? '';
            $this->editIsActive = (bool) $series->is_active;
            $this->editIsPermanent = (bool)($series->is_retention_period_permanent ?? (strtolower($series->active_period ?? '') === 'permanent'));
        }
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
    }

    public function updateSeries(): void
    {
        $this->clearMessages();

        $title = trim($this->editSeriesTitle);
        if (empty($title)) {
            $this->errorMessage = 'Series title is required.';
            return;
        }

        // Check duplicate (exclude self)
        $exists = DB::table('rdp_record_series')
            ->where('series_title', $title)
            ->where('id', '!=', $this->editingId)
            ->exists();

        if ($exists) {
            $this->errorMessage = "A record series with title \"{$title}\" already exists.";
            return;
        }

        $series = DB::table('rdp_record_series')->where('id', $this->editingId)->first();
        if (!$series) {
            $this->errorMessage = 'Record series not found.';
            return;
        }

        // Update or create retention period
        $computedTotal = $this->computeTotalPeriod($this->editActivePeriod, $this->editStoragePeriod, $this->editIsPermanent);
        $activePeriod = $this->editIsPermanent ? 'Permanent' : (trim($this->editActivePeriod) ?: null);
        $storagePeriod = $this->editIsPermanent ? 'Permanent' : (trim($this->editStoragePeriod) ?: null);
        $totalPeriod = $computedTotal ?: null;
        $retentionId = $series->retention_period;

        if ($this->editIsPermanent || !empty($activePeriod) || !empty($storagePeriod) || !empty($totalPeriod)) {
            $retentionData = [
                'active_period'  => $activePeriod,
                'storage_period' => $storagePeriod,
                'total_period'   => $totalPeriod,
                'updated_at'     => now(),
            ];

            if ($retentionId) {
                DB::table('rdp_retention_period')->where('id', $retentionId)->update($retentionData);
            } else {
                $retentionData['created_at'] = now();
                $retentionId = DB::table('rdp_retention_period')->insertGetId($retentionData);
            }
        }

        $parentId = !empty($this->editParentId) ? (int) $this->editParentId : null;

        DB::table('rdp_record_series')->where('id', $this->editingId)->update([
            'series_title'                 => $title,
            'parent_id'                    => $parentId,
            'retention_period'             => $retentionId,
            'is_retention_period_permanent' => $this->editIsPermanent,
            'is_active'                    => $this->editIsActive,
            'remarks'                      => trim($this->editRemarks) ?: null,
            'updated_at'                   => now(),
        ]);

        // Log admin action
        DB::table('admin_logs')->insert([
            'changes'      => "Updated Record Series: \"{$title}\"",
            'admin_id'     => auth()->id(),
            'what_system'  => 2,
            'when_changes' => now(),
        ]);

        $this->successMessage = "Record Series \"{$title}\" has been updated successfully.";
        $this->editingId = null;
    }

    public function toggleActive(int $seriesId): void
    {
        $this->clearMessages();

        $series = DB::table('rdp_record_series')->where('id', $seriesId)->first();
        if (!$series) return;

        $newStatus = !$series->is_active;
        DB::table('rdp_record_series')->where('id', $seriesId)->update([
            'is_active'  => $newStatus,
            'updated_at' => now(),
        ]);

        $statusText = $newStatus ? 'Activated' : 'Deactivated';

        DB::table('admin_logs')->insert([
            'changes'      => "{$statusText} Record Series: \"{$series->series_title}\"",
            'admin_id'     => auth()->id(),
            'what_system'  => 2,
            'when_changes' => now(),
        ]);

        $this->successMessage = "Record Series \"{$series->series_title}\" has been {$statusText}.";
    }

    public function deleteSeries(int $seriesId): void
    {
        $this->clearMessages();

        $series = DB::table('rdp_record_series')->where('id', $seriesId)->first();
        if (!$series) return;

        // Check if series is used in any records
        $usageCount = DB::table('rdp_record')->where('record_series_id', $seriesId)->count();
        if ($usageCount > 0) {
            $this->errorMessage = "Cannot delete \"{$series->series_title}\": it is referenced by {$usageCount} record(s). Deactivate it instead.";
            return;
        }

        // Check if series has children
        $childCount = DB::table('rdp_record_series')->where('parent_id', $seriesId)->count();
        if ($childCount > 0) {
            $this->errorMessage = "Cannot delete \"{$series->series_title}\": it has {$childCount} child series. Remove them first.";
            return;
        }

        DB::table('rdp_record_series')->where('id', $seriesId)->delete();

        // Clean up orphaned retention period
        if ($series->retention_period) {
            $otherUsage = DB::table('rdp_record_series')
                ->where('retention_period', $series->retention_period)
                ->exists();
            if (!$otherUsage) {
                DB::table('rdp_retention_period')->where('id', $series->retention_period)->delete();
            }
        }

        DB::table('admin_logs')->insert([
            'changes'      => "Deleted Record Series: \"{$series->series_title}\"",
            'admin_id'     => auth()->id(),
            'what_system'  => 2,
            'when_changes' => now(),
        ]);

        $this->successMessage = "Record Series \"{$series->series_title}\" has been deleted.";
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
                  ->orWhere('parent.series_title', 'ilike', '%' . $this->search . '%');
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('rdp_record_series.is_active', $this->statusFilter === '1');
        }

        $totalSeries = DB::table('rdp_record_series')->count();
        $activeSeries = DB::table('rdp_record_series')->where('is_active', true)->count();
        $inactiveSeries = DB::table('rdp_record_series')->where('is_active', false)->count();
        $withRetention = DB::table('rdp_record_series')->where('is_retention_period_permanent', true)->count();

        // Get all series for parent dropdown
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
        $paginatedRecords = new \Illuminate\Pagination\LengthAwarePaginator(
            $paginatedItems,
            count($treeOrdered),
            $perPage,
            $page,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
        );

        $allSeries = DB::table('rdp_record_series')
            ->select('id', 'series_title')
            ->orderBy('series_title', 'asc')
            ->get();

        return [
            'records'        => $paginatedRecords,
            'allSeries'      => $allSeries,
            'totalSeries'    => $totalSeries,
            'activeSeries'   => $activeSeries,
            'inactiveSeries' => $inactiveSeries,
            'withRetention'  => $withRetention,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/console.css', 'resources/css/admin/activity_logs.css'])
@endpush

<div class="activity-logs-container">
    <style>
        .form-row-flex {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .form-label-bold {
            font-size: 13.5px;
            font-weight: 700;
            color: #0f172a;
            min-width: 160px;
        }
        .form-input-custom {
            flex: 1;
            padding: 9px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13.5px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #ffffff;
            box-sizing: border-box;
        }
        .form-input-custom:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
    </style>

    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
        <div>
            <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0;">Record Series Management</h1>
            <p style="font-size: 14px; color: #64748b; margin: 4px 0 0 0;">Manage predefined and custom record series with retention periods for the Records Disposition Program.</p>
        </div>
        <button type="button" wire:click="toggleAddForm" style="display: flex; align-items: center; gap: 6px; padding: 10px 20px; background: {{ $showAddForm ? '#ef4444' : '#2563eb' }}; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; transition: all 0.2s;">
            @if($showAddForm)
                <span style="font-size: 16px;">✕</span> Cancel
            @else
                <span style="font-size: 16px;">+</span> Add Record Series
            @endif
        </button>
    </div>

    {{-- Messages --}}
    @if($successMessage)
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 8px;">
            ✅ {{ $successMessage }}
            <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: #15803d; cursor: pointer; font-weight: 800; font-size: 15px;">✕</button>
        </div>
    @endif
    @if($errorMessage)
        <div style="background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 8px;">
            ❌ {{ $errorMessage }}
            <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: #dc2626; cursor: pointer; font-weight: 800; font-size: 15px;">✕</button>
        </div>
    @endif

    {{-- Stat Overview Cards --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                📋
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($totalSeries) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Total Record Series</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #f0fdf4; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                ✅
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($activeSeries) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Active Series</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #fef2f2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                ⏸
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($inactiveSeries) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Inactive Series</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #fff7ed; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                🕐
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($withRetention) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">With Retention Rules</div>
            </div>
        </div>
    </div>

    {{-- Add Series Form --}}
    @if($showAddForm)
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); margin-bottom: 20px;">
            <h3 style="margin: 0 0 20px; font-size: 16px; font-weight: 700; color: #0f172a;">➕ Add New Record Series</h3>

            <!-- Series Title -->
            <div class="form-row-flex">
                <span class="form-label-bold">Series Title *:</span>
                <input type="text" class="form-input-custom" wire:model="newSeriesTitle" placeholder="e.g. Gate Passes, Receipts" style="font-weight: 700;">
            </div>

            <!-- Parent Series -->
            <div class="form-row-flex">
                <span class="form-label-bold">Parent Series:</span>
                <select class="form-input-custom" wire:model="newParentId">
                    <option value="">None (Top Level Series)</option>
                    @foreach($allSeries as $s)
                        <option value="{{ $s->id }}">{{ $s->series_title }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Permanent Retention Option -->
            <div class="form-row-flex">
                <span class="form-label-bold">Permanent Record:</span>
                <div style="flex: 1; display: flex; align-items: center; gap: 8px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: #1e40af; cursor: pointer;">
                        <input type="checkbox" wire:model.live="newIsPermanent" style="width: 18px; height: 18px; cursor: pointer; accent-color: #2563eb;">
                        Permanent Record Series
                    </label>
                    <span style="font-size: 12.5px; color: #64748b; margin-left: 8px;">(Disables period inputs and sets retention to Permanent)</span>
                </div>
            </div>

            <!-- Active Period -->
            <div class="form-row-flex" style="{{ $newIsPermanent ? 'opacity: 0.4; pointer-events: none; user-select: none; transition: all 0.2s;' : 'transition: all 0.2s;' }}">
                <span class="form-label-bold">Active Period:</span>
                <input type="text" class="form-input-custom" wire:model.live.debounce.200ms="newActivePeriod" placeholder="e.g. 6 Months, 1 Year, 3 Years" {{ $newIsPermanent ? 'disabled' : '' }}>
            </div>

            <!-- Storage Period -->
            <div class="form-row-flex" style="{{ $newIsPermanent ? 'opacity: 0.4; pointer-events: none; user-select: none; transition: all 0.2s;' : 'transition: all 0.2s;' }}">
                <span class="form-label-bold">Storage Period:</span>
                <input type="text" class="form-input-custom" wire:model.live.debounce.200ms="newStoragePeriod" placeholder="e.g. 1 Year, 4 Years, 2 Years" {{ $newIsPermanent ? 'disabled' : '' }}>
            </div>

            <!-- Total Period (Auto-Calculated Display) -->
            <div class="form-row-flex" style="{{ $newIsPermanent ? 'opacity: 0.4; pointer-events: none; user-select: none;' : '' }}">
                <span class="form-label-bold">Total Period:</span>
                <div style="flex: 1; padding: 10px 14px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 700; font-size: 14px; color: #1e40af;">
                    {{ $this->computeTotalPeriod($newActivePeriod, $newStoragePeriod, $newIsPermanent) ?: '— (Auto-calculated from Active & Storage)' }}
                </div>
            </div>

            <!-- Remarks -->
            <div class="form-row-flex" style="align-items: flex-start;">
                <span class="form-label-bold" style="margin-top: 8px;">Remarks:</span>
                <input type="text" class="form-input-custom" wire:model="newRemarks" placeholder="e.g. Dispose after retention, Audit required before disposal">
            </div>

            <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" wire:click="toggleAddForm" style="padding: 9px 18px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px;">Cancel</button>
                <button type="button" wire:click="addSeries" style="padding: 9px 24px; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 13px;">Save Record Series</button>
            </div>
        </div>
    @endif

    {{-- Filters & Table Card --}}
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02);">
        <div style="display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 20px;">
            <div style="display: flex; gap: 12px; flex-wrap: wrap; flex: 1;">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search series title, remarks..." style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 280px;">

                <select wire:model.live="statusFilter" style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff;">
                    <option value="">All Status</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>

                @if($search || $statusFilter !== '')
                    <button type="button" wire:click="clearFilters" style="padding: 9px 16px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px;">
                        Reset Filters
                    </button>
                @endif
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; color: #475569;">
                        <th style="padding: 12px 16px;">SERIES TITLE</th>
                        <th style="padding: 12px 16px;">PARENT</th>
                        <th style="padding: 12px 16px;">ACTIVE PERIOD</th>
                        <th style="padding: 12px 16px;">STORAGE PERIOD</th>
                        <th style="padding: 12px 16px;">TOTAL PERIOD</th>
                        <th style="padding: 12px 16px;">REMARKS</th>
                        <th style="padding: 12px 16px;">STATUS</th>
                        <th style="padding: 12px 16px; text-align: right;">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $record)
                        @if($editingId === $record->id)
                            {{-- Inline Edit Row --}}
                            <tr style="border-bottom: 1px solid #f1f5f9; background: #fffbeb;">
                                <td style="padding: 10px 16px;">
                                    <input type="text" wire:model="editSeriesTitle" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                </td>
                                <td style="padding: 10px 16px;">
                                    <select wire:model="editParentId" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; background: #fff; box-sizing: border-box;">
                                        <option value="">None</option>
                                        @foreach($allSeries as $s)
                                            @if($s->id !== $record->id)
                                                <option value="{{ $s->id }}">{{ $s->series_title }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </td>
                                <td style="padding: 10px 16px;">
                                    <label style="display: flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: #1e40af; margin-bottom: 4px;">
                                        <input type="checkbox" wire:model.live="editIsPermanent" style="width: 14px; height: 14px; accent-color: #2563eb;"> Permanent
                                    </label>
                                    <input type="text" wire:model.live.debounce.200ms="editActivePeriod" placeholder="Active (e.g. 6 Mos)" {{ $editIsPermanent ? 'disabled style=opacity:0.4;' : '' }} style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                </td>
                                <td style="padding: 10px 16px;">
                                    <input type="text" wire:model.live.debounce.200ms="editStoragePeriod" placeholder="Storage (e.g. 1 Yr)" {{ $editIsPermanent ? 'disabled style=opacity:0.4;' : '' }} style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                </td>
                                <td style="padding: 10px 16px; font-weight: 700; font-size: 12.5px; color: #1e40af;">
                                    {{ $this->computeTotalPeriod($editActivePeriod, $editStoragePeriod, $editIsPermanent) ?: '—' }}
                                </td>
                                <td style="padding: 10px 16px;">
                                    <input type="text" wire:model="editRemarks" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                                </td>
                                <td style="padding: 10px 16px;">
                                    <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; cursor: pointer;">
                                        <input type="checkbox" wire:model="editIsActive" style="width: 16px; height: 16px;">
                                        Active
                                    </label>
                                </td>
                                <td style="padding: 10px 16px; text-align: right; white-space: nowrap;">
                                    <button type="button" wire:click="updateSeries" style="padding: 6px 14px; background: #16a34a; color: #fff; border: none; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 12px; margin-right: 4px;">Save</button>
                                    <button type="button" wire:click="cancelEdit" style="padding: 6px 14px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 12px;">Cancel</button>
                                </td>
                            </tr>
                        @else
                             {{-- Display Row --}}
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 12px 16px; padding-left: {{ (($record->depth ?? 0) * 26) + 16 }}px; font-weight: 700; color: #0f172a;">
                                    @if(($record->depth ?? 0) > 0)
                                        <span style="color: #2563eb; font-weight: 800; font-family: monospace; margin-right: 6px;">└─</span>
                                    @endif
                                    {{ $record->series_title ?? '—' }}
                                </td>
                                <td style="padding: 12px 16px; color: #64748b; font-size: 13px;">
                                    {{ $record->parent_title ?? '—' }}
                                </td>

                                @php
                                    $isPermSeries = (bool)($record->effective_is_permanent) || 
                                                    (strtolower(trim($record->effective_total ?? '')) === 'permanent') ||
                                                    (strtolower(trim($record->effective_active ?? '')) === 'permanent' && strtolower(trim($record->effective_storage ?? '')) === 'permanent');
                                @endphp

                                @if($isPermSeries)
                                    <td colspan="3" style="padding: 12px 16px; text-align: center; font-weight: 800; font-size: 14px; color: #1e40af; background: #f8fafc;">
                                        Permanent
                                        @if(!empty($record->is_inherited))
                                            <span style="font-size: 10px; font-weight: 600; color: #64748b; margin-left: 4px;">(Inherited)</span>
                                        @endif
                                    </td>
                                @else
                                    <td style="padding: 12px 16px; color: #1e293b; font-size: 13px;">
                                        {{ $record->effective_active ?? '—' }}
                                    </td>
                                    <td style="padding: 12px 16px; color: #1e293b; font-size: 13px;">
                                        {{ $record->effective_storage ?? '—' }}
                                    </td>
                                    <td style="padding: 12px 16px; color: #1e293b; font-size: 13px;">
                                        {{ $record->effective_total ?? '—' }}
                                        @if(!empty($record->is_inherited) && (!empty($record->effective_active) || !empty($record->effective_storage)))
                                            <span style="font-size: 10px; font-weight: 600; color: #64748b; margin-left: 4px;">(Inherited)</span>
                                        @endif
                                    </td>
                                @endif

                                <td style="padding: 12px 16px; color: #64748b; font-size: 13px; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    {{ $record->remarks ?? '—' }}
                                </td>
                                <td style="padding: 12px 16px;">
                                    @if($record->is_active)
                                        <span style="display: inline-block; padding: 3px 10px; background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; border-radius: 12px; font-weight: 700; font-size: 11.5px;">Active</span>
                                    @else
                                        <span style="display: inline-block; padding: 3px 10px; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 12px; font-weight: 700; font-size: 11.5px;">Inactive</span>
                                    @endif
                                </td>
                                <td style="padding: 12px 16px; text-align: right; white-space: nowrap;">
                                    <button type="button" wire:click="startEdit({{ $record->id }})" style="padding: 5px 12px; background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 12px; margin-right: 4px;">Edit</button>
                                    <button type="button" wire:click="toggleActive({{ $record->id }})" style="padding: 5px 12px; background: {{ $record->is_active ? '#fff7ed' : '#f0fdf4' }}; color: {{ $record->is_active ? '#ea580c' : '#16a34a' }}; border: 1px solid {{ $record->is_active ? '#fed7aa' : '#bbf7d0' }}; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 12px; margin-right: 4px;">{{ $record->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    <button type="button" wire:click="deleteSeries({{ $record->id }})" wire:confirm="Are you sure you want to delete '{{ $record->series_title }}'?" style="padding: 5px 12px; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 12px;">Delete</button>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="8" style="padding: 32px; text-align: center; color: #64748b;">
                                No record series found matching criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px;">
            {{ $records->links() }}
        </div>
    </div>
</div>
