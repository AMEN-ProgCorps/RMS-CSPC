<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Reference Section')] class extends Component {
    public string $type = '';
    public string $search = '';
    public ?object $typeRecord = null;

    public function mount(string $type): void
    {
        $perms = Auth::user()?->permissions;
        if (!$perms || (!(bool)($perms->is_sadm ?? false) && !(bool)($perms->can_access_rdp ?? true))) {
            redirect()->route('rdp')->send();
            return;
        }

        $this->type = $type;
        $typeRec = DB::table('rdp_record_series_type')
            ->whereRaw('LOWER(shorted_type) = ?', [strtolower($type)])
            ->first();

        if (!$typeRec) {
            abort(404, "Record Series Type '{$type}' not found.");
        }

        $this->typeRecord = $typeRec;
    }

    public function clearSearch(): void
    {
        $this->search = '';
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
                    'active_period'                 => $current->active_period,
                    'storage_period'                => $current->storage_period,
                    'total_period'                  => $current->total_period,
                    'is_retention_period_permanent' => $isPerm,
                    'inherited'                     => $current->id !== $record->id,
                ];
            }

            if (in_array($current->id, $visited, true)) {
                break;
            }

            $visited[] = $current->id;
            $parentId = $current->parent_id ?? null;
            $current = ($parentId && isset($allSeriesMap[$parentId])) ? $allSeriesMap[$parentId] : null;
        }

        return (object)[
            'active_period'                 => null,
            'storage_period'                => null,
            'total_period'                  => null,
            'is_retention_period_permanent' => false,
            'inherited'                     => false,
        ];
    }

    private function buildGroupedTreeHierarchy(array $records): array
    {
        $byBracketAndOffice = [];
        foreach ($records as $r) {
            $bKey = $r->bracket_id ? ('b_' . $r->bracket_id) : 'no_bracket';
            $oKey = $r->recorded_at_office ? ('o_' . $r->recorded_at_office) : 'no_office';
            $byBracketAndOffice[$bKey][$oKey][] = $r;
        }

        $finalOrdered = [];

        foreach ($byBracketAndOffice as $bKey => $officeGroups) {
            foreach ($officeGroups as $oKey => $items) {
                $itemIdsInGroup = array_column($items, 'id');
                $byParent = [];
                foreach ($items as $item) {
                    $pId = $item->parent_id ?? 0;
                    if ($pId > 0 && !in_array($pId, $itemIdsInGroup, true)) {
                        $pId = 0;
                    }
                    $byParent[$pId][] = $item;
                }

                $groupOrdered = [];
                $flatten = function ($parentId, $depth) use (&$flatten, &$groupOrdered, $byParent) {
                    if (!isset($byParent[$parentId])) {
                        return;
                    }
                    foreach ($byParent[$parentId] as $item) {
                        $item->depth = $depth;
                        $groupOrdered[] = $item;
                        $flatten($item->id, $depth + 1);
                    }
                };

                $flatten(0, 0);

                $addedIds = array_column($groupOrdered, 'id');
                foreach ($items as $item) {
                    if (!in_array($item->id, $addedIds, true)) {
                        $item->depth = 0;
                        $groupOrdered[] = $item;
                    }
                }

                foreach ($groupOrdered as $go) {
                    $finalOrdered[] = $go;
                }
            }
        }

        return $finalOrdered;
    }

    public function with(): array
    {
        if (!$this->typeRecord) {
            return [
                'seriesList' => [],
                'typeRecord' => null,
            ];
        }

        $typeId = $this->typeRecord->id;

        $allFetchedMap = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->select(['rdp_record_series.*', 'rdp_retention_period.active_period', 'rdp_retention_period.storage_period', 'rdp_retention_period.total_period'])
            ->get()
            ->keyBy('id')
            ->all();

        $baseQuery = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
            ->leftJoin('rdp_record_series_brackets', 'rdp_record_series.bracket_id', '=', 'rdp_record_series_brackets.id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office'), 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
            ->where('rdp_record_series.series_type', $typeId)
            ->where('rdp_record_series.is_verified', true)
            ->where('rdp_record_series.is_active', true)
            ->select([
                'rdp_record_series.*',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
                'parent.series_title as parent_title',
                'rdp_record_series_brackets.bracket_name',
                'office.office_name as recorded_office_name',
            ]);

        if (!empty(trim($this->search))) {
            $searchTerm = '%' . trim($this->search) . '%';

            // 1. Find direct matching record IDs
            $matchedIds = DB::table('rdp_record_series')
                ->leftJoin('rdp_record_series_brackets', 'rdp_record_series.bracket_id', '=', 'rdp_record_series_brackets.id')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office'), 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
                ->where('rdp_record_series.series_type', $typeId)
                ->where('rdp_record_series.is_verified', true)
                ->where('rdp_record_series.is_active', true)
                ->where(function ($q) use ($searchTerm) {
                    $q->where('rdp_record_series.series_title', 'ILIKE', $searchTerm)
                      ->orWhere('rdp_record_series.remarks', 'ILIKE', $searchTerm)
                      ->orWhere('rdp_record_series_brackets.bracket_name', 'ILIKE', $searchTerm)
                      ->orWhere('office.office_name', 'ILIKE', $searchTerm)
                      ->orWhere('rdp_record_series.recorded_at_office', 'ILIKE', $searchTerm)
                      ->orWhere(DB::raw("CAST(rdp_record_series.item_number AS TEXT)"), 'ILIKE', $searchTerm);
                })
                ->pluck('rdp_record_series.id')
                ->toArray();

            // 2. Fetch all series in this type to traverse hierarchy
            $allTypeSeries = DB::table('rdp_record_series')
                ->where('series_type', $typeId)
                ->where('is_verified', true)
                ->where('is_active', true)
                ->get();

            $includedIds = [];
            foreach ($matchedIds as $mId) {
                $includedIds[$mId] = true;

                // Collect all child & grandchild subsections of mId
                $collectChildren = function($pId) use (&$collectChildren, &$includedIds, $allTypeSeries) {
                    foreach ($allTypeSeries as $s) {
                        if ($s->parent_id == $pId) {
                            $includedIds[$s->id] = true;
                            $collectChildren($s->id);
                        }
                    }
                };
                $collectChildren($mId);

                // Collect all ancestors of mId so parent structure remains intact
                $currId = $mId;
                while ($currId) {
                    $item = $allTypeSeries->firstWhere('id', $currId);
                    if ($item && $item->parent_id) {
                        $includedIds[$item->parent_id] = true;
                        $currId = $item->parent_id;
                    } else {
                        break;
                    }
                }
            }

            $baseQuery->whereIn('rdp_record_series.id', array_keys($includedIds));
        }

        $allFetched = $baseQuery->orderByRaw('
            rdp_record_series_brackets.bracket_name ASC NULLS LAST,
            office.office_name ASC NULLS LAST,
            rdp_record_series.item_number ASC NULLS LAST,
            rdp_record_series.series_title ASC
        ')->get();

        $treeOrdered = $this->buildGroupedTreeHierarchy($allFetched->all());

        foreach ($treeOrdered as $rec) {
            $eff = $this->resolveEffectiveRetention($allFetchedMap, $rec);
            $rec->effective_active = $eff->active_period;
            $rec->effective_storage = $eff->storage_period;
            $rec->effective_total = $eff->total_period;
            $rec->effective_is_permanent = $eff->is_retention_period_permanent;
            $rec->is_inherited = $eff->inherited;
        }

        return [
            'seriesList' => $treeOrdered,
            'typeRecord' => $this->typeRecord,
        ];
    }
}; ?>

<div class="reference-section-page">
    <style>
        .reference-section-page {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .ref-header-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .ref-title-group h1 {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .ref-badge {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        .ref-subtitle {
            font-size: 14px;
            color: #64748b;
            margin: 0;
        }
        .ref-search-box {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .ref-search-input {
            width: 320px;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        .ref-search-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .ref-clear-btn {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }
        .ref-clear-btn:hover {
            background: #e2e8f0;
        }

        /* ==========================================================================
           Reference Section - Dark Mode Overrides
           ========================================================================== */
        [data-theme="dark"] .ref-header-card {
            background: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
        }

        [data-theme="dark"] .ref-title-group h1 {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .ref-subtitle {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .ref-search-input {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .ref-clear-btn {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .ref-badge {
            background: rgba(37, 99, 235, 0.2) !important;
            color: #60a5fa !important;
            border-color: #3b82f6 !important;
        }

        [data-theme="dark"] [x-data*="collapsedBrackets"] {
            background: #131c2e !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] [x-data*="collapsedBrackets"] table thead tr {
            background: #0f172a !important;
        }

        [data-theme="dark"] [x-data*="collapsedBrackets"] table th {
            color: #94a3b8 !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] [x-data*="collapsedBrackets"] table td {
            color: #cbd5e1 !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] [x-data*="collapsedBrackets"] table tr:hover td {
            background-color: #1a253c !important;
        }
    </style>

    <div class="ref-header-card">
        <div class="ref-title-group">
            <h1>
                Reference Section
                <span class="ref-badge">{{ $typeRecord->shorted_type ?? $type }}</span>
            </h1>
            <p class="ref-subtitle">
                Official Record Series Reference Matrix for <strong>{{ $typeRecord->type_name ?? $type }}</strong>
            </p>
        </div>
        <div class="ref-search-box">
            <input type="text" wire:model.live.debounce.250ms="search" class="ref-search-input" placeholder="Search item number, title, bracket, office...">
            @if(!empty($search))
                <button wire:click="clearSearch" class="ref-clear-btn">Clear</button>
            @endif
        </div>
    </div>

    <!-- Alpine.js Collapsible Table Container -->
    <div x-data="{ 
        collapsedBrackets: {}, 
        collapsedOffices: {}, 
        search: @entangle('search').live,
        toggleBracket(id) { 
            this.collapsedBrackets[id] = !this.collapsedBrackets[id]; 
        }, 
        toggleOffice(id) { 
            this.collapsedOffices[id] = !this.collapsedOffices[id]; 
        },
        isBracketCollapsed(bId) { 
            return !this.search && !!this.collapsedBrackets[bId]; 
        },
        isRowCollapsed(bId, oId) { 
            return !this.search && (!!this.collapsedBrackets[bId] || !!this.collapsedOffices[oId]); 
        }
    }" style="overflow-x: auto; width: 100%; background: #ffffff; border-radius: 12px; border: 1.5px solid #cbd5e1; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
        <table style="width: 100%; min-width: 950px; border-collapse: collapse;">
            <thead>
                <tr style="background: #f1f5f9;">
                    <th rowspan="2" style="width: 80px; text-align: center; border: 1px solid #cbd5e1; padding: 10px; font-weight: 700; color: #0f172a; font-size: 13px;">ITEM NO.</th>
                    <th rowspan="2" style="text-align: center; width: 40%; border: 1px solid #cbd5e1; padding: 10px; font-weight: 700; color: #0f172a; font-size: 13px;">RECORD SERIES TITLE & DESCRIPTION</th>
                    <th colspan="3" style="text-align: center; border: 1px solid #cbd5e1; padding: 8px; font-weight: 700; color: #0f172a; font-size: 13px; width: 225px;">RETENTION PERIOD</th>
                    <th rowspan="2" style="text-align: center; width: 30%; border: 1px solid #cbd5e1; padding: 10px; font-weight: 700; color: #0f172a; font-size: 13px;">REMARKS</th>
                </tr>
                <tr style="background: #f1f5f9;">
                    <th style="text-align: center; width: 75px; border: 1px solid #cbd5e1; padding: 8px; font-weight: 600; color: #334155; font-size: 12px;">ACTIVE</th>
                    <th style="text-align: center; width: 75px; border: 1px solid #cbd5e1; padding: 8px; font-weight: 600; color: #334155; font-size: 12px;">STORAGE</th>
                    <th style="text-align: center; width: 75px; border: 1px solid #cbd5e1; padding: 8px; font-weight: 600; color: #334155; font-size: 12px;">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                @forelse($seriesList as $index => $record)
                    @php
                        $prevRecord = $index > 0 ? $seriesList[$index - 1] : null;
                        $bracketChanged = !$prevRecord || ($prevRecord->bracket_id !== $record->bracket_id);
                        $officeChanged = $bracketChanged || ($prevRecord->recorded_at_office !== $record->recorded_at_office);
                        $bKey = 'b_' . ($record->bracket_id ?? 'none');
                        $oKey = 'o_' . ($record->bracket_id ?? 'none') . '_' . ($record->recorded_at_office ?? 'none');
                    @endphp

                    @if($bracketChanged && !empty($record->bracket_name))
                        <!-- Bracket Section Header Banner (Collapsible) -->
                        <tr x-on:click="toggleBracket('{{ $bKey }}')" style="background: #cbd5e1; font-weight: 800; font-size: 13.5px; text-align: center; color: #0f172a; letter-spacing: 0.8px; cursor: pointer; user-select: none;">
                            <td colspan="6" style="padding: 10px 16px; border: 1.5px solid #94a3b8; text-transform: uppercase;">
                                <span x-text="isBracketCollapsed('{{ $bKey }}') ? '►' : '▼'" style="font-size: 11px; margin-right: 6px; color: #475569;"></span>
                                {{ $record->bracket_name }}
                            </td>
                        </tr>
                    @endif

                    @if($officeChanged && !empty($record->recorded_at_office))
                        <!-- Office / Section Header Banner (Collapsible) -->
                        <tr x-show="!isBracketCollapsed('{{ $bKey }}')" x-on:click="toggleOffice('{{ $oKey }}')" style="background: #e2e8f0; font-weight: 700; font-size: 12.5px; color: #1e293b; letter-spacing: 0.5px; cursor: pointer; user-select: none;">
                            <td colspan="6" style="padding: 7px 20px; border: 1px solid #cbd5e1; text-align: left; padding-left: 45px; text-transform: uppercase;">
                                <span x-text="collapsedOffices['{{ $oKey }}'] ? '►' : '▼'" style="font-size: 10px; margin-right: 6px; color: #64748b;"></span>
                                {{ $record->recorded_office_name ?? $record->recorded_at_office }}
                            </td>
                        </tr>
                    @endif

                    <!-- Display Row -->
                    <tr x-show="!isRowCollapsed('{{ $bKey }}', '{{ $oKey }}')" style="border-bottom: 1px solid #cbd5e1;">
                        <td style="text-align: center; font-weight: 800; color: #1e3a8a; border: 1px solid #cbd5e1; padding: 10px;">
                            @if(($record->depth ?? 0) === 0)
                                {{ $record->item_number ?? '—' }}
                            @else
                                {{ $record->item_number ?? '' }}
                            @endif
                        </td>
                        <td style="text-align: left; padding-left: {{ (($record->depth ?? 0) * 28) + 16 }}px; border: 1px solid #cbd5e1; padding-top: 10px; padding-bottom: 10px; padding-right: 16px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                <div>
                                    @if(($record->depth ?? 0) > 0)
                                        <span style="color: #2563eb; font-weight: 800; font-family: monospace; margin-right: 6px;">└─</span>
                                    @endif
                                    <span style="{{ ($record->depth ?? 0) === 0 ? 'font-weight: 800; font-size: 13.5px; color: #0f172a;' : (($record->depth ?? 0) === 1 ? 'font-weight: 700; font-size: 13px; color: #1e293b;' : 'font-weight: 600; font-size: 12.5px; color: #334155;') }}">
                                        {{ $record->series_title ?? '—' }}
                                    </span>
                                </div>
                            </div>
                        </td>

                        @php
                            $isPermSeries = (bool)($record->effective_is_permanent) || 
                                            (strtolower(trim($record->effective_total ?? '')) === 'permanent') ||
                                            (strtolower(trim($record->effective_active ?? '')) === 'permanent' && strtolower(trim($record->effective_storage ?? '')) === 'permanent');
                        @endphp

                        @if($isPermSeries)
                            <td colspan="3" style="text-align: center; font-weight: 800; letter-spacing: 3px; color: #1e3a8a; background: #eff6ff; font-size: 12.5px; border: 1px solid #cbd5e1; padding: 10px;">
                                P E R M A N E N T
                                @if(!empty($record->is_inherited))
                                    <span style="font-size: 10px; font-weight: 600; opacity: 0.75; letter-spacing: normal; margin-left: 4px;">(Inherited)</span>
                                @endif
                            </td>
                        @else
                            <td style="color: #334155; font-size: 13px; text-align: center; border: 1px solid #cbd5e1; padding: 10px;">
                                {{ $record->effective_active ?? '' }}
                            </td>
                            <td style="color: #334155; font-size: 13px; text-align: center; border: 1px solid #cbd5e1; padding: 10px;">
                                {{ $record->effective_storage ?? '' }}
                            </td>
                            <td style="color: #334155; font-size: 13px; text-align: center; border: 1px solid #cbd5e1; padding: 10px;">
                                {{ $record->effective_total ?? '' }}
                                @if(!empty($record->is_inherited) && (!empty($record->effective_active) || !empty($record->effective_storage)))
                                    <span style="font-size: 10px; font-weight: 600; color: #64748b; margin-left: 4px;">(Inherited)</span>
                                @endif
                            </td>
                        @endif

                        <td style="color: #334155; font-size: 13px; text-align: left; padding-left: 12px; word-break: break-word; border: 1px solid #cbd5e1; padding-top: 10px; padding-bottom: 10px; padding-right: 12px;">
                            {{ $record->remarks ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="padding: 48px; text-align: center; color: #64748b;">
                            No record series found matching your query under {{ $typeRecord->type_name ?? $type }}.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
