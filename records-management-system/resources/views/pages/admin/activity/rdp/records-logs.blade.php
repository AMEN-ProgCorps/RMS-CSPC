<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.admin')] #[Title('Admin Console - RDP Records Logs')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $timeValueFilter = '';
    public string $modeFilter = ''; // '', 'single', 'batch'
    public string $officeFilter = '';

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

    public function updatingTimeValueFilter(): void
    {
        $this->resetPage();
    }

    public function updatingModeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingOfficeFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->timeValueFilter = '';
        $this->modeFilter = '';
        $this->officeFilter = '';
        $this->resetPage();
    }

    public function with(): array
    {
        $query = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->leftJoin('rdp_time_value', 'rdp_record.time_value', '=', 'rdp_time_value.char_value')
            ->select([
                'rdp_record.*',
                'rdp_record_series.series_title',
                'rdp_record_series.remarks as series_remarks',
                'rdp_time_value.description as time_description',
            ]);

        if (!empty($this->search)) {
            $term = '%' . trim($this->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('rdp_record.description', 'ilike', $term)
                  ->orWhere('rdp_record_series.series_title', 'ilike', $term)
                  ->orWhere('rdp_record.office_own', 'ilike', $term)
                  ->orWhere('rdp_record.volume', 'ilike', $term)
                  ->orWhere('rdp_record.records_location', 'ilike', $term)
                  ->orWhere('rdp_record_series.remarks', 'ilike', $term);
            });
        }

        if (!empty($this->timeValueFilter)) {
            $query->where('rdp_record.time_value', $this->timeValueFilter);
        }

        if ($this->modeFilter === 'batch') {
            $query->where('rdp_record.ispartof_batch', true);
        } elseif ($this->modeFilter === 'single') {
            $query->where(function ($q) {
                $q->where('rdp_record.ispartof_batch', false)
                  ->orWhereNull('rdp_record.ispartof_batch');
            });
        }

        if (!empty($this->officeFilter)) {
            $query->where('rdp_record.office_own', $this->officeFilter);
        }

        $totalRecords = DB::table('rdp_record')->count();
        $permanentRecords = DB::table('rdp_record')->where('time_value', 'P')->count();
        $temporaryRecords = DB::table('rdp_record')->where('time_value', 'T')->count();
        $batchRecords = DB::table('rdp_record')->where('ispartof_batch', true)->count();
        $totalSeriesCount = DB::table('rdp_record_series')->count();

        $officesList = DB::table('rdp_record')
            ->whereNotNull('office_own')
            ->where('office_own', '!=', '')
            ->distinct()
            ->pluck('office_own')
            ->sort()
            ->values()
            ->all();

        $records = $query->orderBy('rdp_record.created_at', 'desc')->paginate(15);

        $recordIds = collect($records->items())->pluck('id')->all();

        // Utilities
        $recordHolders = collect($records->items())->pluck('utility_value')->filter()->all();
        $utilities = empty($recordHolders) ? collect() : DB::table('rdp_utility_manager')
            ->join('rdp_utility_medium', 'rdp_utility_manager.utility_medium', '=', 'rdp_utility_medium.id')
            ->whereIn('rdp_utility_manager.record_holder', $recordHolders)
            ->where('rdp_utility_manager.is_active', true)
            ->select('rdp_utility_manager.record_holder', 'rdp_utility_medium.utility_name')
            ->get()
            ->groupBy('record_holder');

        // Periods covered & sub-period summaries
        $periods = empty($recordIds) ? collect() : DB::table('rdp_period_covered')
            ->whereIn('period_owner', $recordIds)
            ->orderBy('date_covered', 'asc')
            ->get()
            ->groupBy('period_owner');

        foreach ($records->items() as $rec) {
            if (!empty($rec->utility_value) && isset($utilities[$rec->utility_value])) {
                $rec->utility_name = $utilities[$rec->utility_value]->pluck('utility_name')->implode(', ');
            } else {
                $rec->utility_name = '—';
            }

            $recPeriods = $periods[$rec->id] ?? collect();
            $rec->periods_count = $recPeriods->count();

            if ($recPeriods->isNotEmpty()) {
                $firstStart = $recPeriods->first()->date_covered;
                $lastEnd = $recPeriods->last()->date_covered_end ?: $recPeriods->last()->date_covered;

                $startFmt = !empty($firstStart) ? \Carbon\Carbon::parse($firstStart)->format('Y') : '';
                $endFmt = !empty($lastEnd) ? \Carbon\Carbon::parse($lastEnd)->format('Y') : '';

                if (!empty($startFmt) && !empty($endFmt) && $startFmt !== $endFmt) {
                    $rec->period_display = "{$startFmt} – {$endFmt}";
                } elseif (!empty($startFmt)) {
                    $rec->period_display = $startFmt;
                } else {
                    $rec->period_display = '—';
                }

                // If volume is empty on the record header but sub-periods have volumes, summarize them
                if (empty($rec->volume)) {
                    $subVols = $recPeriods->pluck('volume')->filter()->all();
                    $rec->sub_vols_display = !empty($subVols) ? implode(', ', array_unique($subVols)) : null;
                } else {
                    $rec->sub_vols_display = null;
                }
            } else {
                $rec->period_display = '—';
                $rec->sub_vols_display = null;
            }
        }

        return [
            'records'          => $records,
            'totalRecords'     => $totalRecords,
            'permanentRecords' => $permanentRecords,
            'temporaryRecords' => $temporaryRecords,
            'batchRecords'     => $batchRecords,
            'totalSeriesCount' => $totalSeriesCount,
            'officesList'      => $officesList,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/console.css', 'resources/css/admin/activity_logs.css'])
@endpush

<div class="activity-logs-container">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
        <div>
            <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0;">RDP Records Audit Logs</h1>
            <p style="font-size: 14px; color: #64748b; margin: 4px 0 0 0;">View staged records, batch entries, series classifications, volumes, and retention values across the Records Disposition Program.</p>
        </div>
    </div>

    <!-- Stat Overview Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                📁
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($totalRecords) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Total Records</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #ecfdf5; color: #059669; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                📦
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($batchRecords) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Batch Mode Records</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #f0fdf4; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                ♾️
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($permanentRecords) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Permanent Series</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #fff7ed; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                ⏳
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($temporaryRecords) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Temporary Series</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #f8fafc; color: #475569; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                📑
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($totalSeriesCount) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Total Record Series</div>
            </div>
        </div>
    </div>

    <!-- Filters & Table Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02);">
        <div style="display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 20px;">
            <div style="display: flex; gap: 12px; flex-wrap: wrap; flex: 1; align-items: center;">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search record subject, series title, office, volume..." style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 280px; flex: 1;">
                
                <select wire:model.live="modeFilter" style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff;">
                    <option value="">All Entry Modes</option>
                    <option value="batch">Batch Mode Only</option>
                    <option value="single">Single Mode Only</option>
                </select>

                <select wire:model.live="timeValueFilter" style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff;">
                    <option value="">All Retention Types</option>
                    <option value="P">Permanent (P)</option>
                    <option value="T">Temporary (T)</option>
                </select>

                @if(!empty($officesList))
                    <select wire:model.live="officeFilter" style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff;">
                        <option value="">All Offices</option>
                        @foreach($officesList as $offCode)
                            <option value="{{ $offCode }}">{{ $offCode }}</option>
                        @endforeach
                    </select>
                @endif

                @if($search || $timeValueFilter || $modeFilter || $officeFilter)
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
                        <th style="padding: 12px 16px;">RECORD SUBJECT / DESCRIPTION</th>
                        <th style="padding: 12px 16px;">SERIES TITLE</th>
                        <th style="padding: 12px 16px;">OFFICE</th>
                        <th style="padding: 12px 16px;">PERIOD COVERED</th>
                        <th style="padding: 12px 16px;">VOLUME</th>
                        <th style="padding: 12px 16px;">RETENTION</th>
                        <th style="padding: 12px 16px;">UTILITY VALUE</th>
                        <th style="padding: 12px 16px; text-align: right;">CREATED AT</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $rec)
                        <tr style="border-bottom: 1px solid #f1f5f9; transition: background-color 0.15s ease;" onmouseover="this.style.backgroundColor='#f8fafc'" onmouseout="this.style.backgroundColor='transparent'">
                            <!-- Subject / Description + Mode Badges -->
                            <td style="padding: 12px 16px;">
                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <span style="font-weight: 700; color: #0f172a; font-size: 13.5px;">
                                            {{ $rec->description ?: '—' }}
                                        </span>
                                        @if(!empty($rec->ispartof_batch))
                                            <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; border-radius: 9999px; font-size: 10.5px; font-weight: 800; letter-spacing: 0.3px;">
                                                📦 BATCH {{ $rec->periods_count > 0 ? "({$rec->periods_count} Periods)" : '' }}
                                            </span>
                                        @endif
                                        @if($rec->is_draft)
                                            <span style="display: inline-flex; align-items: center; padding: 2px 6px; background: #fef3c7; color: #b45309; border: 1px solid #fde68a; border-radius: 9999px; font-size: 10px; font-weight: 700;">
                                                DRAFT
                                            </span>
                                        @endif
                                    </div>
                                    @if(!empty($rec->records_location))
                                        <span style="font-size: 11.5px; color: #64748b;">
                                            📍 {{ $rec->records_location }}
                                        </span>
                                    @endif
                                </div>
                            </td>

                            <!-- Series Title -->
                            <td style="padding: 12px 16px; color: #334155; font-weight: 600;">
                                <div>{{ $rec->series_title ?? 'N/A' }}</div>
                                @if(!empty($rec->series_remarks))
                                    <div style="font-size: 11px; color: #94a3b8; font-weight: 400;">
                                        {{ Str::limit($rec->series_remarks, 35) }}
                                    </div>
                                @endif
                            </td>

                            <!-- Office -->
                            <td style="padding: 12px 16px;">
                                <span style="display: inline-block; padding: 3px 8px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; border-radius: 6px; font-weight: 700; font-size: 11.5px;">
                                    {{ $rec->office_own ?: '—' }}
                                </span>
                            </td>

                            <!-- Period Covered -->
                            <td style="padding: 12px 16px; color: #475569; font-size: 12.5px; white-space: nowrap;">
                                {{ $rec->period_display }}
                            </td>

                            <!-- Volume -->
                            <td style="padding: 12px 16px; color: #2563eb; font-weight: 700; font-size: 12.5px;">
                                @if(!empty($rec->volume))
                                    {{ $rec->volume }}
                                @elseif(!empty($rec->sub_vols_display))
                                    <span title="Volume from sub-periods">{{ $rec->sub_vols_display }}</span>
                                @elseif(!empty($rec->ispartof_batch))
                                    <span style="color: #64748b; font-weight: 500; font-size: 11.5px; font-style: italic;">Breakdown</span>
                                @else
                                    <span style="color: #94a3b8;">—</span>
                                @endif
                            </td>

                            <!-- Retention Value -->
                            <td style="padding: 12px 16px; white-space: nowrap;">
                                @if($rec->time_value === 'P')
                                    <span style="display: inline-block; padding: 3px 10px; background: #f0fdf4; color: #16a34a; border-radius: 12px; font-weight: 700; font-size: 11px;">PERMANENT</span>
                                @else
                                    <span style="display: inline-block; padding: 3px 10px; background: #fff7ed; color: #ea580c; border-radius: 12px; font-weight: 700; font-size: 11px;">TEMPORARY</span>
                                @endif
                            </td>

                            <!-- Utility Value -->
                            <td style="padding: 12px 16px; font-weight: 600; color: #475569; font-size: 12px;">
                                {{ $rec->utility_name ?? '—' }}
                            </td>

                            <!-- Created At -->
                            <td style="padding: 12px 16px; text-align: right; color: #64748b; font-size: 12px; white-space: nowrap;">
                                {{ \Carbon\Carbon::parse($rec->created_at)->format('M d, Y h:i A') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="padding: 36px; text-align: center; color: #64748b;">
                                <div style="font-size: 16px; font-weight: 600; margin-bottom: 4px;">No record logs found</div>
                                <div style="font-size: 13px; color: #94a3b8;">Try clearing or adjusting your search filters.</div>
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
