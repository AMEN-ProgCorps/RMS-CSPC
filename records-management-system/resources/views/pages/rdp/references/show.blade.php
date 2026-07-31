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

    public function with(): array
    {
        if (!$this->typeRecord) {
            return [
                'seriesList' => [],
                'typeRecord' => null,
            ];
        }

        $allFetchedMap = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->select(['rdp_record_series.*', 'rdp_retention_period.active_period', 'rdp_retention_period.storage_period', 'rdp_retention_period.total_period'])
            ->get()
            ->keyBy('id')
            ->all();

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

        // Filter by series_type id or fallback to CSPC default if series_type is unassigned
        $typeId = $this->typeRecord->id;
        if (strtoupper($this->typeRecord->shorted_type) === 'CSPC') {
            $query->where(function($q) use ($typeId) {
                $q->where('rdp_record_series.series_type', $typeId)
                  ->orWhereNull('rdp_record_series.series_type');
            });
        } else {
            $query->where('rdp_record_series.series_type', $typeId);
        }

        if (!empty(trim($this->search))) {
            $searchTerm = '%' . trim($this->search) . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('rdp_record_series.series_title', 'ILIKE', $searchTerm)
                  ->orWhere('rdp_record_series.remarks', 'ILIKE', $searchTerm)
                  ->orWhere(DB::raw("CAST(rdp_record_series.item_number AS TEXT)"), 'ILIKE', $searchTerm);
            });
        }

        $records = $query->orderByRaw('item_number ASC NULLS LAST')
            ->orderBy('rdp_record_series.id', 'asc')
            ->get();

        foreach ($records as $rec) {
            $eff = $this->resolveEffectiveRetention($allFetchedMap, $rec);
            $rec->effective_active = $eff->active_period;
            $rec->effective_storage = $eff->storage_period;
            $rec->effective_total = $eff->total_period;
            $rec->effective_is_permanent = $eff->is_retention_period_permanent;
            $rec->is_inherited = $eff->inherited;
        }

        return [
            'seriesList' => $records,
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

        /* NAP Form 2 Styled Table */
        .nap2-table-wrapper {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .nap2-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }
        .nap2-table thead tr:first-child th {
            background: #1e293b;
            color: #ffffff;
            font-weight: 600;
            padding: 12px 14px;
            border-right: 1px solid #334155;
            text-align: center;
        }
        .nap2-table thead tr:nth-child(2) th {
            background: #334155;
            color: #f8fafc;
            font-weight: 500;
            font-size: 12px;
            padding: 8px 10px;
            border-right: 1px solid #475569;
            text-align: center;
        }
        .nap2-table tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: background 0.15s;
        }
        .nap2-table tbody tr:hover {
            background: #f8fafc;
        }
        .nap2-table td {
            padding: 12px 14px;
            color: #334155;
            vertical-align: top;
            border-right: 1px solid #e2e8f0;
        }
        .nap2-table td:last-child {
            border-right: none;
        }
        .col-item-no {
            width: 100px;
            text-align: center;
            font-weight: 600;
            color: #0f172a;
        }
        .col-title {
            min-width: 320px;
        }
        .col-retention {
            width: 110px;
            text-align: center;
        }
        .col-remarks {
            min-width: 220px;
            color: #64748b;
            font-size: 13px;
        }
        .root-series-title {
            font-weight: 700;
            color: #0f172a;
            font-size: 15px;
        }
        .child-series-title {
            padding-left: 20px;
            color: #334155;
            position: relative;
        }
        .child-series-title::before {
            content: "↳ ";
            color: #94a3b8;
            font-weight: 600;
        }
        .parent-tag {
            font-size: 11px;
            color: #64748b;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            margin-bottom: 4px;
            display: inline-block;
        }
        .perm-badge {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .inherited-note {
            font-size: 11px;
            color: #94a3b8;
            font-style: italic;
            margin-top: 2px;
        }
        .empty-state {
            padding: 48px;
            text-align: center;
            color: #64748b;
        }
        .empty-state svg {
            margin-bottom: 12px;
            opacity: 0.5;
        }
    </style>

    <div class="ref-header-card">
        <div class="ref-title-group">
            <h1>
                Reference Section
                <span class="ref-badge">{{ $typeRecord->shorted_type ?? $type }}</span>
            </h1>
            <p class="ref-subtitle">
                Official Record Series Reference Matrix for <strong>{{ $typeRecord->type_name ?? $type }}</strong> (NAP Form 2 Format)
            </p>
        </div>
        <div class="ref-search-box">
            <input type="text" wire:model.live.debounce.250ms="search" class="ref-search-input" placeholder="Search item number or series title...">
            @if(!empty($search))
                <button wire:click="clearSearch" class="ref-clear-btn">Clear</button>
            @endif
        </div>
    </div>

    <div class="nap2-table-wrapper">
        <table class="nap2-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width: 100px;">Item No.</th>
                    <th rowspan="2">Record Series Title & Description</th>
                    <th colspan="3">Retention Period</th>
                    <th rowspan="2">Remarks</th>
                </tr>
                <tr>
                    <th style="width: 110px;">Active</th>
                    <th style="width: 110px;">Storage</th>
                    <th style="width: 130px;">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse($seriesList as $series)
                    <tr>
                        <td class="col-item-no">
                            {{ $series->item_number ? sprintf('%03d', $series->item_number) : '—' }}
                        </td>
                        <td class="col-title">
                            @if(!empty($series->parent_title))
                                <div class="parent-tag">Sub-series of: {{ $series->parent_title }}</div>
                                <div class="child-series-title">{{ $series->series_title }}</div>
                            @else
                                <div class="root-series-title">{{ $series->series_title }}</div>
                            @endif
                        </td>
                        <td class="col-retention">
                            {{ !empty(trim($series->effective_active ?? '')) ? $series->effective_active : '—' }}
                        </td>
                        <td class="col-retention">
                            {{ !empty(trim($series->effective_storage ?? '')) ? $series->effective_storage : '—' }}
                        </td>
                        <td class="col-retention">
                            @if($series->effective_is_permanent)
                                <span class="perm-badge">Permanent</span>
                            @elseif(!empty(trim($series->effective_total ?? '')))
                                <strong>{{ $series->effective_total }}</strong>
                            @else
                                —
                            @endif
                            @if($series->is_inherited)
                                <div class="inherited-note">(Inherited)</div>
                            @endif
                        </td>
                        <td class="col-remarks">
                            {{ $series->remarks ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                                <h3>No Record Series Found</h3>
                                <p>No record series match your query under {{ $typeRecord->type_name ?? $type }}.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
