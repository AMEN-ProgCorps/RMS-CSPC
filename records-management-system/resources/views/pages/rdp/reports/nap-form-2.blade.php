<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - NAP Form 2')] class extends Component {
    public string $search = '';
    public string $retentionFilter = ''; // 'all', 'permanent', 'temporary'
    
    // Checkbox selections for printing
    public array $selectedIds = [];
    public bool $selectAll = false;
    public bool $showPrintModal = false;

    // View Modal Properties
    public bool $showViewModal = false;
    public ?object $viewSeriesData = null;

    // Edit Modal Properties
    public bool $showEditModal = false;
    public ?int $editingSeriesId = null;
    public string $editSeriesTitle = '';
    public ?string $editItemNumber = '';
    public string $editRemarks = '';
    public bool $isRootParentForEdit = false;

    // Printable Custom Header & Signature Fields
    public string $agencyName = 'Camarines Sur Polytechnic Colleges';
    public string $agencyAddress = 'San Miguel, Nabua, Camarines Sur';
    public string $scheduleNo = 'RDS-2026-001';
    public string $datePrepared = '';

    // Bottom Signature Block Fields
    public string $preparedBy = '';
    public string $preparedPosition = 'Records Officer';
    public string $assistedBy = '';
    public string $assistedPosition = 'Records Management Analyst';
    public string $recommendingBy = '';
    public string $recommendingPosition = 'Vice President for Administration';
    public string $approvedBy = '';
    public string $approvedPosition = 'College President / Head of Agency';
    public string $committeeChairmanName = '';
    public string $committeeChairmanTitle = 'Chairman, Records Management Evaluation Committee';
    public string $executiveDirectorName = '';
    public string $executiveDirectorTitle = 'Executive Director, National Archives of the Philippines';

    public function mount(): void
    {
        $user = Auth::user();
        $details = $user?->details;
        
        $this->datePrepared = Carbon::now()->format('F d, Y');
        
        if ($details) {
            $fullName = trim(($details->first_name ?? '') . ' ' . ($details->last_name ?? ''));
            $this->preparedBy = $fullName ?: ($user->username ?? 'Records Officer');
            $this->preparedPosition = $details->designation ?? 'Records Officer';
        }
    }

    public function updatedSelectAll($value): void
    {
        if ($value) {
            $allIds = DB::table('rdp_record_series')->pluck('id')->toArray();
            $this->selectedIds = array_map('strval', $allIds);
        } else {
            $this->selectedIds = [];
        }
    }

    public function openPrintModal(array $specificIds = []): void
    {
        if (!empty($specificIds)) {
            $this->selectedIds = array_map('strval', $specificIds);
        }
        $this->showPrintModal = true;
    }

    public function closePrintModal(): void
    {
        $this->showPrintModal = false;
    }

    public function openViewModal(int $id): void
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

        $record = $query->where('rdp_record_series.id', $id)->first();
        if ($record) {
            $allFetchedMap = DB::table('rdp_record_series')
                ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
                ->select(['rdp_record_series.*', 'rdp_retention_period.active_period', 'rdp_retention_period.storage_period', 'rdp_retention_period.total_period'])
                ->get()
                ->keyBy('id')
                ->all();

            $eff = $this->resolveEffectiveRetention($allFetchedMap, $record);
            $record->effective_active = $eff->active_period;
            $record->effective_storage = $eff->storage_period;
            $record->effective_total = $eff->total_period;
            $record->effective_is_permanent = $eff->is_retention_period_permanent;
            $record->is_inherited = $eff->inherited;
            $record->is_root_parent = empty($record->parent_id);

            $this->viewSeriesData = $record;
            $this->showViewModal = true;
        }
    }

    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewSeriesData = null;
    }

    public function openEditModal(int $id): void
    {
        $record = DB::table('rdp_record_series')->where('id', $id)->first();
        if ($record) {
            $this->editingSeriesId = $record->id;
            $this->editSeriesTitle = $record->series_title ?? '';
            $this->editItemNumber = $record->item_number !== null ? (string)$record->item_number : '';
            $this->editRemarks = $record->remarks ?? '';
            $this->isRootParentForEdit = empty($record->parent_id);

            $this->showEditModal = true;
        }
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editingSeriesId = null;
    }

    public function saveEditSeries(): void
    {
        if (!$this->editingSeriesId) return;

        $series = DB::table('rdp_record_series')->where('id', $this->editingSeriesId)->first();
        if (!$series) return;

        $updateData = [
            'series_title' => trim($this->editSeriesTitle),
            'remarks'      => trim($this->editRemarks) ?: null,
        ];

        // ONLY root parent record series (parent_id IS NULL) can have an assigned Item No.
        if (empty($series->parent_id)) {
            $itemNumStr = trim((string)$this->editItemNumber);
            if ($itemNumStr !== '') {
                $updateData['item_number'] = (int)$itemNumStr;
                $updateData['is_verified'] = true;
            } else {
                $updateData['item_number'] = null;
                $updateData['is_verified'] = false;
            }
        } else {
            // Subsections CANNOT have an item number
            $updateData['item_number'] = null;
        }

        DB::table('rdp_record_series')->where('id', $this->editingSeriesId)->update($updateData);

        // Audit Log
        $adminId = auth()->id() ?? 1;
        DB::table('admin_logs')->insert([
            'admin_id'    => $adminId,
            'changes'     => 'Updated Record Series via NAP Form 2: "' . $this->editSeriesTitle . '"',
            'what_system' => 2,
            'when_changes'=> now(),
        ]);

        $this->showEditModal = false;
        $this->editingSeriesId = null;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->retentionFilter = '';
        $this->selectedIds = [];
        $this->selectAll = false;
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

        $allFetched = $query->orderByRaw('rdp_record_series.item_number ASC NULLS LAST, rdp_record_series.series_title ASC')->get();

        $allFetchedMap = [];
        foreach ($allFetched as $item) {
            $allFetchedMap[$item->id] = $item;
        }

        $treeOrdered = $this->buildTreeHierarchy($allFetched->all());

        $rootCounter = 1;
        foreach ($treeOrdered as $item) {
            $eff = $this->resolveEffectiveRetention($allFetchedMap, $item);
            $item->effective_active = $eff->active_period;
            $item->effective_storage = $eff->storage_period;
            $item->effective_total = $eff->total_period;
            $item->effective_is_permanent = $eff->is_retention_period_permanent;
            $item->is_inherited = $eff->inherited;

            // Strictly identify Root Parent Series (top-level, no parent_id)
            $isRoot = empty($item->parent_id) || ($item->depth ?? 0) === 0;
            $item->is_root_parent = $isRoot;

            if ($isRoot) {
                if (!empty($item->item_number)) {
                    $item->display_item_no = (string)$item->item_number;
                } else {
                    $item->display_item_no = (string)$rootCounter;
                }
                $rootCounter++;
            } else {
                // Subsections / Children do NOT have an assigned item number
                $item->display_item_no = '';
            }
        }

        // Apply retention filter if selected
        if ($this->retentionFilter === 'permanent') {
            $treeOrdered = array_filter($treeOrdered, function ($item) {
                return (bool)($item->effective_is_permanent) || strtolower(trim($item->effective_total ?? '')) === 'permanent';
            });
        } elseif ($this->retentionFilter === 'temporary') {
            $treeOrdered = array_filter($treeOrdered, function ($item) {
                return !(bool)($item->effective_is_permanent) && strtolower(trim($item->effective_total ?? '')) !== 'permanent';
            });
        }

        // Selected items for print document
        $printItems = [];
        if (!empty($this->selectedIds)) {
            $selectedInts = array_map('intval', $this->selectedIds);
            foreach ($treeOrdered as $item) {
                if (in_array((int)$item->id, $selectedInts, true)) {
                    $printItems[] = $item;
                }
            }
        } else {
            $printItems = array_values($treeOrdered);
        }

        $totalCount     = count($treeOrdered);
        $permanentCount = count(array_filter($treeOrdered, fn($i) => $i->effective_is_permanent || strtolower(trim($i->effective_total ?? '')) === 'permanent'));
        $temporaryCount = $totalCount - $permanentCount;

        return [
            'recordSeriesList' => array_values($treeOrdered),
            'printItems'       => $printItems,
            'totalCount'       => $totalCount,
            'permanentCount'   => $permanentCount,
            'temporaryCount'   => $temporaryCount,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/console.css'])
@endpush

<div style="padding: 24px; background: #f8fafc; min-height: 100vh; font-family: 'Inter', system-ui, -apple-system, sans-serif;">
    <style>
        .nap-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); margin-bottom: 24px; }
        .nap-table { width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: left; }
        .nap-table th { background: #f1f5f9; padding: 12px 14px; font-weight: 700; color: #334155; border-bottom: 2px solid #cbd5e1; }
        .nap-table td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; color: #0f172a; }
        .nap-btn { padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; border: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .nap-btn-primary { background: #2563eb; color: #ffffff; }
        .nap-btn-primary:hover { background: #1d4ed8; }
        .nap-btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .nap-btn-secondary:hover { background: #e2e8f0; }

        /* Modal Overlay & Card Styling */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .modal-content { background: #cbd5e1; width: 100%; max-width: 960px; max-height: 92vh; border-radius: 14px; overflow-y: auto; box-shadow: 0 20px 40px rgba(0,0,0,0.3); padding: 24px; display: flex; flex-direction: column; gap: 20px; }
        
        .modal-dialog { background: #ffffff; width: 100%; max-width: 580px; border-radius: 14px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); padding: 24px; }

        /* Printable Document Styling with BOLDER Outer Borders */
        .print-page { 
            width: 800px; 
            min-height: 1050px; 
            background: #ffffff; 
            border: none; 
            margin: 0 auto; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.12); 
            padding: 40px 35px; 
            box-sizing: border-box;
            color: #000000;
            font-family: Arial, Helvetica, sans-serif;
            position: relative;
        }

        .doc-table { width: 100%; border-collapse: collapse; border: 2.5px solid #000000; font-size: 10px; }
        .doc-table th, .doc-table td { border: 1px solid #000000; padding: 9px 8px; vertical-align: middle; line-height: 1.45; }
        
        /* Remove horizontal row borders exclusively inside data table body (<tbody>) */
        .doc-data-table tbody td { border-left: 1px solid #000000; border-right: 1px solid #000000; border-top: none; border-bottom: none; }

        .header-cell { text-align: center; width: 45%; vertical-align: middle !important; padding: 10px !important; }
        .header-main-text { font-weight: bold; font-size: 11.5px; margin-top: 2px; }
        .header-sub-text { font-style: italic; font-size: 9.5px; margin-bottom: 6px; }
        .header-doc-title { font-weight: bold; font-size: 12.5px; }
        .field-label { font-weight: bold; font-size: 9px; display: block; margin-bottom: 2px; color: #000; }
        .field-value { font-weight: bold; font-size: 11px; color: #000; }
        
        .sub-header th { font-weight: bold; font-size: 8.5px; padding: 6px 6px; }
        .important-note { font-size: 9.5px; margin-top: 14px; text-align: justify; line-height: 1.45; border: none; padding: 6px 0; }

        .signatures-table { width: 100%; border-collapse: collapse; border: 2.5px solid #000000; font-size: 9.5px; margin-bottom: 20px; }
        .signatures-table td { border: 1px solid #000000; padding: 14px 12px; width: 50%; vertical-align: top; height: 115px; }
        .sig-block { display: flex; flex-direction: column; align-items: center; margin-top: 35px; }
        .sig-line { border-bottom: 1px solid #000; width: 80%; text-align: center; margin-bottom: 4px; font-size: 10.5px; font-weight: bold; padding-bottom: 1px; }
        .sig-label { font-size: 9px; text-align: center; color: #1e293b; }

        .nap-accomplish-section { border: 2.5px solid #000000; padding: 14px; font-size: 9.5px; min-height: 200px; }
        .section-header { text-align: center; font-weight: bold; font-size: 9.5px; border-bottom: 1.5px solid #000000; margin: -14px -14px 12px -14px; padding: 7px; background: #f1f5f9; }
        .checkbox-item { margin-bottom: 8px; display: flex; align-items: center; font-size: 9.5px; }
        .checkbox { display: inline-block; width: 11px; height: 11px; border: 1px solid #000; margin-right: 7px; }
        .approval-area { display: flex; justify-content: space-between; margin-top: 50px; padding: 0 30px; text-align: center; }
        .approval-text { font-size: 9.5px; font-weight: bold; }

        @media print {
            body { background: #ffffff !important; margin: 0 !important; padding: 0 !important; }
            .no-print, .toolbar, .nap-card, header, nav, sidebar, footer, .modal-header-actions { display: none !important; }
            .modal-overlay { position: static !important; background: none !important; padding: 0 !important; }
            .modal-content { background: none !important; max-width: 100% !important; padding: 0 !important; box-shadow: none !important; }
            .print-page { 
                box-shadow: none !important; 
                border: none !important; 
                width: 100% !important; 
                margin: 0 !important; 
                padding: 8mm 12mm !important; 
                page-break-after: always;
            }
            .print-page:last-child { page-break-after: auto; }
        }
    </style>

    <!-- Header Section -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
        <div>
            <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0;">NAP Form 2: Records Disposition Schedule</h1>
            <p style="font-size: 14px; color: #64748b; margin: 4px 0 0 0;">Audit report & schedule management for predefined and custom record series.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" wire:click="openPrintModal" class="nap-btn nap-btn-primary">
                🖨️ Print Selected NAP Form 2 ({{ count($selectedIds) ?: 'All' }})
            </button>
        </div>
    </div>

    <!-- Stat Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                📁
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($totalCount) }}</div>
                <div style="font-size: 12.5px; font-weight: 600; color: #64748b;">Total Record Series</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: #f0fdf4; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                ♾️
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($permanentCount) }}</div>
                <div style="font-size: 12.5px; font-weight: 600; color: #64748b;">Permanent Series</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: #fff7ed; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                ⏳
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($temporaryCount) }}</div>
                <div style="font-size: 12.5px; font-weight: 600; color: #64748b;">Temporary Series</div>
            </div>
        </div>
    </div>

    <!-- Filters & Table Card -->
    <div class="nap-card">
        <div style="display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 20px;">
            <div style="display: flex; gap: 12px; flex-wrap: wrap; flex: 1;">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search item no, series title, remarks..." style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 280px; outline: none;">
                
                <select wire:model.live="retentionFilter" style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff; outline: none;">
                    <option value="">All Retention Types</option>
                    <option value="permanent">Permanent Retention</option>
                    <option value="temporary">Temporary Retention</option>
                </select>

                @if($search || $retentionFilter || count($selectedIds) > 0)
                    <button type="button" wire:click="clearFilters" class="nap-btn nap-btn-secondary">
                        Reset Filters
                    </button>
                @endif
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="nap-table">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;">
                            <input type="checkbox" wire:model.live="selectAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #2563eb;">
                        </th>
                        <th style="width: 90px; text-align: center;">ITEM NO.</th>
                        <th>RECORD SERIES TITLE</th>
                        <th style="width: 120px; text-align: center;">ACTIVE</th>
                        <th style="width: 120px; text-align: center;">STORAGE</th>
                        <th style="width: 140px; text-align: center;">TOTAL RETENTION</th>
                        <th>REMARKS</th>
                        <th style="width: 140px; text-align: right;">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recordSeriesList as $idx => $item)
                        @php
                            $isPermSeries = (bool)($item->effective_is_permanent) || 
                                            (strtolower(trim($item->effective_total ?? '')) === 'permanent') ||
                                            (strtolower(trim($item->effective_active ?? '')) === 'permanent' && strtolower(trim($item->effective_storage ?? '')) === 'permanent');
                            $itemIdStr = (string)$item->id;
                        @endphp
                        <tr style="{{ in_array($itemIdStr, $selectedIds) ? 'background: #eff6ff;' : '' }}">
                            <td style="text-align: center;">
                                <input type="checkbox" wire:model.live="selectedIds" value="{{ $item->id }}" style="width: 16px; height: 16px; cursor: pointer; accent-color: #2563eb;">
                            </td>
                            <td style="text-align: center; font-weight: 700; color: #475569;">
                                @if(!empty($item->is_root_parent) && !empty($item->display_item_no))
                                    {{ $item->display_item_no }}
                                @endif
                            </td>
                            <td style="padding-left: {{ (($item->depth ?? 0) * 16) + 14 }}px; font-weight: 700;">
                                @if(($item->depth ?? 0) > 0)
                                    <span style="font-family: monospace; font-weight: 800; color: #2563eb;">└─</span> 
                                @endif
                                {{ $item->series_title }}
                                @if(!empty($item->is_inherited))
                                    <span style="font-size: 11px; color: #64748b; font-weight: 500; margin-left: 6px;">(Inherited)</span>
                                @endif
                            </td>
                            @if($isPermSeries)
                                <td colspan="3" style="text-align: center;">
                                    <span style="display: inline-block; padding: 4px 14px; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 12px; font-weight: 800; font-size: 12px;">
                                        PERMANENT
                                    </span>
                                </td>
                            @else
                                <td style="text-align: center; font-weight: 600; color: #475569;">{{ $item->effective_active ?: '' }}</td>
                                <td style="text-align: center; font-weight: 600; color: #475569;">{{ $item->effective_storage ?: '' }}</td>
                                <td style="text-align: center;">
                                    @if(!empty($item->effective_total))
                                        <span style="display: inline-block; padding: 4px 10px; background: #f8fafc; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 700; font-size: 12px;">
                                            {{ $item->effective_total }}
                                        </span>
                                    @endif
                                </td>
                            @endif
                            <td style="font-size: 12.5px; color: #64748b;">{{ $item->remarks ?: '' }}</td>
                            <td style="text-align: right; white-space: nowrap;">
                                <button type="button" wire:click="openViewModal({{ $item->id }})" class="nap-btn nap-btn-secondary" style="padding: 5px 10px; font-size: 12px; margin-right: 4px;">
                                    👁️ View
                                </button>
                                <button type="button" wire:click="openEditModal({{ $item->id }})" class="nap-btn nap-btn-primary" style="padding: 5px 10px; font-size: 12px;">
                                    ✏️ Edit
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="padding: 32px; text-align: center; color: #64748b;">
                                No record series found matching filter criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- VIEW SERIES DETAIL MODAL -->
    @if($showViewModal && $viewSeriesData)
        <div class="modal-overlay" wire:click.self="closeViewModal">
            <div class="modal-dialog">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;">Record Series Details</h3>
                    <button type="button" wire:click="closeViewModal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <div style="display: flex; flex-direction: column; gap: 14px; font-size: 14px;">
                    <div>
                        <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Series Title</span>
                        <div style="font-size: 16px; font-weight: 800; color: #0f172a; margin-top: 2px;">{{ $viewSeriesData->series_title }}</div>
                    </div>

                    @if(!empty($viewSeriesData->parent_title))
                        <div>
                            <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Parent Series Title</span>
                            <div style="font-weight: 700; color: #2563eb; margin-top: 2px;">{{ $viewSeriesData->parent_title }}</div>
                        </div>
                    @endif

                    <div>
                        <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Item Number</span>
                        <div style="font-weight: 700; color: #0f172a; margin-top: 2px;">
                            @if(!empty($viewSeriesData->is_root_parent))
                                @if(!empty($viewSeriesData->item_number))
                                    {{ $viewSeriesData->item_number }} <span style="font-size: 11.5px; color: #16a34a;">(Root Parent Series)</span>
                                @else
                                    <span style="color: #2563eb;">Auto-indexed Root Parent Series</span>
                                @endif
                            @else
                                <span style="color: #64748b; font-style: italic;">None (Subsections/Children do not have item numbers)</span>
                            @endif
                        </div>
                    </div>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px;">
                        <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 8px;">Retention Period Schedule</span>
                        @if($viewSeriesData->effective_is_permanent || strtolower(trim($viewSeriesData->effective_total ?? '')) === 'permanent')
                            <div style="display: inline-block; padding: 4px 12px; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 8px; font-weight: 800; font-size: 13px;">
                                PERMANENT RETENTION
                            </div>
                        @else
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; text-align: center;">
                                <div style="background: #fff; padding: 8px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                    <div style="font-size: 11px; color: #64748b;">Active</div>
                                    <div style="font-weight: 700; color: #0f172a;">{{ $viewSeriesData->effective_active ?: '' }}</div>
                                </div>
                                <div style="background: #fff; padding: 8px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                    <div style="font-size: 11px; color: #64748b;">Storage</div>
                                    <div style="font-weight: 700; color: #0f172a;">{{ $viewSeriesData->effective_storage ?: '' }}</div>
                                </div>
                                <div style="background: #fff; padding: 8px; border-radius: 6px; border: 1px solid #bfdbfe; background: #eff6ff;">
                                    <div style="font-size: 11px; color: #1e40af;">Total</div>
                                    <div style="font-weight: 800; color: #1e40af;">{{ $viewSeriesData->effective_total ?: '' }}</div>
                                </div>
                            </div>
                        @endif
                        @if(!empty($viewSeriesData->is_inherited))
                            <div style="font-size: 11.5px; color: #64748b; margin-top: 8px;">ℹ️ Inherited from parent series hierarchy.</div>
                        @endif
                    </div>

                    <div>
                        <span style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Remarks & Provisions</span>
                        <div style="color: #334155; margin-top: 2px; line-height: 1.5;">{{ $viewSeriesData->remarks ?: 'No remarks provided.' }}</div>
                    </div>
                </div>

                <div style="margin-top: 20px; text-align: right;">
                    <button type="button" wire:click="closeViewModal" class="nap-btn nap-btn-secondary">Close</button>
                </div>
            </div>
        </div>
    @endif

    <!-- EDIT SERIES MODAL -->
    @if($showEditModal)
        <div class="modal-overlay" wire:click.self="closeEditModal">
            <div class="modal-dialog">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;">Edit Record Series</h3>
                    <button type="button" wire:click="closeEditModal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <form wire:submit.prevent="saveEditSeries" style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Series Title</label>
                        <input type="text" wire:model="editSeriesTitle" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;" required>
                    </div>

                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">
                            Item Number
                            @if(!$isRootParentForEdit)
                                <span style="font-size: 11.5px; font-weight: 500; color: #ea580c; margin-left: 6px;">(Restricted to Root Parent Series Only)</span>
                            @endif
                        </label>
                        @if($isRootParentForEdit)
                            <input type="number" wire:model="editItemNumber" placeholder="e.g. 1, 2, 15" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;">
                            <span style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">Item numbers are assigned exclusively to top-level root series.</span>
                        @else
                            <input type="text" value="— (Subsections/Children cannot have Item No.)" disabled style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; background: #f1f5f9; color: #64748b; cursor: not-allowed; box-sizing: border-box;">
                        @endif
                    </div>

                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Remarks</label>
                        <textarea wire:model="editRemarks" rows="3" placeholder="Additional disposition notes, remarks..." style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;"></textarea>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                        <button type="button" wire:click="closeEditModal" class="nap-btn nap-btn-secondary">Cancel</button>
                        <button type="submit" class="nap-btn nap-btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- PRINT MODAL (NAP FORM 2 OFFICIAL LAYOUT WITH CUSTOMIZABLE METADATA & SIGNATURE INPUTS) -->
    @if($showPrintModal)
        <div class="modal-overlay" wire:click.self="closePrintModal">
            <div class="modal-content">
                
                <!-- Action Header -->
                <div class="modal-header-actions" style="display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 16px 20px; border-radius: 12px; border: 1px solid #cbd5e1; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                    <div>
                        <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;">NAP Form 2 Document Customization & Print</h3>
                        <span style="font-size: 13px; color: #64748b;">Customize header metadata and signature blocks before printing ({{ count($printItems) }} Record Series selected)</span>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="nap-btn nap-btn-primary" onclick="window.print()">🖨️ Print Official Document</button>
                        <button type="button" class="nap-btn nap-btn-secondary" wire:click="closePrintModal">Close</button>
                    </div>
                </div>

                <!-- Customization Form Panel (hidden during window.print via .no-print) -->
                <div class="no-print" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 20px; display: flex; flex-direction: column; gap: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                    <div style="font-weight: 800; font-size: 14px; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px;">
                        📝 TOP HEADER METADATA
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                        <div>
                            <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">1. AGENCY NAME:</label>
                            <input type="text" wire:model.live="agencyName" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12.5px; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">2. ADDRESS:</label>
                            <input type="text" wire:model.live="agencyAddress" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12.5px; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">3. SCHEDULE NO.:</label>
                            <input type="text" wire:model.live="scheduleNo" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12.5px; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">4. DATE PREPARED:</label>
                            <input type="text" wire:model.live="datePrepared" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12.5px; box-sizing: border-box;">
                        </div>
                    </div>

                    <div style="font-weight: 800; font-size: 14px; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; margin-top: 8px;">
                        ✍️ BOTTOM SIGNATURES & APPROVALS
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                        <div>
                            <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">9. PREPARED BY (Name & Position):</label>
                            <input type="text" wire:model.live="preparedBy" placeholder="Name" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px; box-sizing: border-box;">
                            <input type="text" wire:model.live="preparedPosition" placeholder="Position" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">10. ASSISTED BY (Name & Position):</label>
                            <input type="text" wire:model.live="assistedBy" placeholder="Name" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px; box-sizing: border-box;">
                            <input type="text" wire:model.live="assistedPosition" placeholder="Position" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">11. RECOMMENDING APPROVAL:</label>
                            <input type="text" wire:model.live="recommendingBy" placeholder="Name" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px; box-sizing: border-box;">
                            <input type="text" wire:model.live="recommendingPosition" placeholder="Position" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">12. APPROVED (Head of Agency):</label>
                            <input type="text" wire:model.live="approvedBy" placeholder="Name" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px; box-sizing: border-box;">
                            <input type="text" wire:model.live="approvedPosition" placeholder="Position" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; box-sizing: border-box;">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-top: 4px;">
                        <div>
                            <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">NATIONAL ARCHIVES CHAIRMAN:</label>
                            <input type="text" wire:model.live="committeeChairmanName" placeholder="Chairman Name (e.g. HON. JUAN DELA CRUZ)" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px; box-sizing: border-box;">
                            <input type="text" wire:model.live="committeeChairmanTitle" placeholder="Title/Position" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; box-sizing: border-box;">
                        </div>
                        <div>
                            <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">NATIONAL ARCHIVES EXECUTIVE DIRECTOR:</label>
                            <input type="text" wire:model.live="executiveDirectorName" placeholder="Executive Director Name (e.g. VICTORINO MAPA MANALO)" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px; box-sizing: border-box;">
                            <input type="text" wire:model.live="executiveDirectorTitle" placeholder="Title/Position" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; box-sizing: border-box;">
                        </div>
                    </div>
                </div>

                <!-- PAGE 1: RECORD DISPOSITION SCHEDULE -->
                <div class="print-page">
                    <div style="margin-top: 4px; width: 100%;">
                        <table class="doc-table">
                            <tr>
                                <td rowspan="2" class="header-cell">
                                    <div class="header-main-text">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                                    <div class="header-sub-text">Pambansang Sinupan ng Pilipinas</div>
                                    <div class="header-doc-title">RECORDS DISPOSITION SCHEDULE</div>
                                </td>
                                <td>
                                    <span class="field-label">1. AGENCY NAME:</span>
                                    <div class="field-value">{{ $agencyName }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <span class="field-label">2. ADDRESS:</span>
                                    <div class="field-value">{{ $agencyAddress }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <span class="field-label">3. SCHEDULE NO.:</span>
                                    <div class="field-value">{{ $scheduleNo }}</div>
                                </td>
                                <td>
                                    <span class="field-label">4. DATE PREPARED:</span>
                                    <div class="field-value">{{ $datePrepared }}</div>
                                </td>
                            </tr>
                        </table>

                        <table class="doc-table doc-data-table" style="border-top: none;">
                            <thead>
                                <tr>
                                    <th rowspan="2" style="width: 10%;">5. ITEM NO.</th>
                                    <th rowspan="2" style="width: 44%;">6. RECORD SERIES TITLE AND DESCRIPTION</th>
                                    <th colspan="3">7. RETENTION PERIOD</th>
                                    <th rowspan="2" style="width: 22%;">8. REMARKS</th>
                                </tr>
                                <tr class="sub-header">
                                    <th style="width: 8%;">Active</th>
                                    <th style="width: 8%;">Storage</th>
                                    <th style="width: 8%;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($printItems as $pIdx => $pItem)
                                    @php
                                        $isPermSeries = (bool)($pItem->effective_is_permanent) || 
                                                        (strtolower(trim($pItem->effective_total ?? '')) === 'permanent') ||
                                                        (strtolower(trim($pItem->effective_active ?? '')) === 'permanent' && strtolower(trim($pItem->effective_storage ?? '')) === 'permanent');
                                        $isChild = (($pItem->depth ?? 0) > 0);
                                    @endphp
                                    <tr>
                                        <td style="text-align: center; font-weight: bold;">
                                            @if(!empty($pItem->is_root_parent) && !empty($pItem->display_item_no))
                                                {{ $pItem->display_item_no }}
                                            @endif
                                        </td>
                                        <td style="padding-left: {{ (($pItem->depth ?? 0) * 12) + 4 }}px; font-weight: bold; color: #000;">
                                            @if(($pItem->depth ?? 0) > 0)
                                                └─ 
                                            @endif
                                            {{ $pItem->series_title }}
                                        </td>
                                        @if($isPermSeries)
                                            <td colspan="3" style="text-align: center; font-weight: bold; font-size: 9px;">
                                                PERMANENT
                                            </td>
                                        @else
                                            <td style="text-align: center;">{{ $pItem->effective_active ?: '' }}</td>
                                            <td style="text-align: center;">{{ $pItem->effective_storage ?: '' }}</td>
                                            <td style="text-align: center; font-weight: bold;">{{ $pItem->effective_total ?: '' }}</td>
                                        @endif
                                        <td style="font-size: 8.5px; color: #000;">{{ $pItem->remarks ?: '' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 20px; font-style: italic;">
                                            No record series items selected for NAP Form 2.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>

                        <div class="important-note">
                            <strong>IMPORTANT:</strong> Pursuant to Section 18, Article III, RA 9470 s. 2007, "No government department, bureau, agency and instrumentality shall dispose of, destroy or authorize the disposal or destruction of any public records, which are in the custody or under its control except with the prior written authority of the executive director."
                        </div>
                    </div>

                    <div style="position: absolute; bottom: 15px; right: 35px; font-size: 9px; color: #333;">Page 1 of 2 Pages</div>
                </div>

                <!-- PAGE 2: SIGNATURES & NATIONAL ARCHIVES APPROVAL -->
                <div class="print-page">
                    <div style="margin-top: 4px; width: 100%;">
                        <table class="signatures-table">
                            <tr>
                                <td>
                                    <span class="field-label">9. Prepared by:</span>
                                    <div class="sig-block">
                                        <div class="sig-line">{{ $preparedBy ?: '___________________' }}</div>
                                        <div class="sig-label">{{ $preparedPosition }}</div>
                                    </div>
                                </td>
                                <td>
                                    <span class="field-label">11. Recommending Approval:</span>
                                    <div class="sig-block">
                                        <div class="sig-line">{{ $recommendingBy ?: '___________________' }}</div>
                                        <div class="sig-label">{{ $recommendingPosition ?: 'Vice President for Administration' }}</div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <span class="field-label">10. Assisted by:</span>
                                    <div class="sig-block">
                                        <div class="sig-line">{{ $assistedBy ?: '___________________' }}</div>
                                        <div class="sig-label">{{ $assistedPosition ?: 'Records Management Analyst' }}</div>
                                    </div>
                                </td>
                                <td>
                                    <span class="field-label">12. Approved:</span>
                                    <div class="sig-block">
                                        <div class="sig-line">{{ $approvedBy ?: '___________________' }}</div>
                                        <div class="sig-label">{{ $approvedPosition }}</div>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <div class="nap-accomplish-section">
                            <div class="section-header">TO BE ACCOMPLISHED BY THE NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                            
                            <div style="margin-bottom: 10px; font-weight: bold;">This Records Disposition Schedule:</div>
                            <div class="checkbox-item"><span class="checkbox"></span> is being returned for improvement / correction</div>
                            <div class="checkbox-item"><span class="checkbox"></span> is being recommended for approval</div>

                            <div class="approval-area">
                                <div class="approval-text">
                                    <br>
                                    @if(!empty($committeeChairmanName))
                                        <div style="font-weight: bold; font-size: 10px; text-transform: uppercase; margin-bottom: 2px;">{{ $committeeChairmanName }}</div>
                                    @endif
                                    <div style="border-top: 1px solid #000; padding-top: 4px; width: 200px; margin: 0 auto;">{{ $committeeChairmanTitle ?: 'Chairman, Records Management Evaluation Committee' }}</div>
                                    <div style="margin-top: 8px;">Date: __________________</div>
                                </div>

                                <div class="approval-text">
                                    <div style="text-align: left; margin-bottom: 8px;">APPROVED:</div>
                                    @if(!empty($executiveDirectorName))
                                        <div style="font-weight: bold; font-size: 10px; text-transform: uppercase; margin-bottom: 2px;">{{ $executiveDirectorName }}</div>
                                    @endif
                                    <div style="border-top: 1px solid #000; padding-top: 4px; width: 200px; margin: 0 auto;">{{ $executiveDirectorTitle ?: 'Executive Director, National Archives of the Philippines' }}</div>
                                    <div style="margin-top: 8px;">Date: __________________</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="position: absolute; bottom: 15px; right: 35px; font-size: 9px; color: #333;">Page 2 of 2 Pages</div>
                </div>

            </div>
        </div>
    @endif
</div>