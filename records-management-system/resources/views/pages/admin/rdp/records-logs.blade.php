<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.admin')] #[Title('Admin Console - RDP Records Logs')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $timeValueFilter = '';

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

    public function clearFilters(): void
    {
        $this->search = '';
        $this->timeValueFilter = '';
        $this->resetPage();
    }

    public function with(): array
    {
        $query = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->leftJoin('rdp_volume_value', DB::raw('"rdp_record"."volume"::bigint'), '=', 'rdp_volume_value.volume_id')
            ->leftJoin('rdp_time_value', 'rdp_record.time_value', '=', 'rdp_time_value.char_value')
            ->leftJoin('rdp_utility_medium', 'rdp_record.utility_value', '=', 'rdp_utility_medium.id')
            ->select([
                'rdp_record.*',
                'rdp_record_series.series_title',
                'rdp_record_series.remarks',
                'rdp_volume_value.value_standard as unit_name',
                'rdp_time_value.value_time_description',
                'rdp_utility_medium.value_description as utility_name',
            ]);

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('rdp_record_series.series_title', 'like', '%' . $this->search . '%')
                  ->orWhere('rdp_record_series.remarks', 'like', '%' . $this->search . '%')
                  ->orWhere('rdp_volume_value.value_standard', 'like', '%' . $this->search . '%');
            });
        }

        if (!empty($this->timeValueFilter)) {
            $query->where('rdp_record.time_value', $this->timeValueFilter);
        }

        $totalRecords = DB::table('rdp_record')->count();
        $permanentRecords = DB::table('rdp_record')->where('time_value', 'P')->count();
        $temporaryRecords = DB::table('rdp_record')->where('time_value', 'T')->count();
        $totalSeriesCount = DB::table('rdp_record_series')->count();

        return [
            'records'          => $query->orderBy('rdp_record.created_at', 'desc')->paginate(15),
            'totalRecords'     => $totalRecords,
            'permanentRecords' => $permanentRecords,
            'temporaryRecords' => $temporaryRecords,
            'totalSeriesCount' => $totalSeriesCount,
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
            <p style="font-size: 14px; color: #64748b; margin: 4px 0 0 0;">View staged records, series classifications, volumes, and retention values across the Records Disposition Program.</p>
        </div>
    </div>

    <!-- Stat Overview Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                📁
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($totalRecords) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Total Staged Records</div>
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
            <div style="display: flex; gap: 12px; flex-wrap: wrap; flex: 1;">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search series title, remarks..." style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 260px;">
                
                <select wire:model.live="timeValueFilter" style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff;">
                    <option value="">All Retention Types</option>
                    <option value="P">Permanent (P)</option>
                    <option value="T">Temporary (T)</option>
                </select>

                @if($search || $timeValueFilter)
                    <button type="button" wire:click="clearFilters" style="padding: 9px 16px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px;">
                        Reset Filters
                    </button>
                @endif
            </div>
        </div>

        <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: left;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; color: #475569;">
                    <th style="padding: 12px 16px;">SERIES TITLE</th>
                    <th style="padding: 12px 16px;">VOLUME</th>
                    <th style="padding: 12px 16px;">RETENTION VALUE</th>
                    <th style="padding: 12px 16px;">UTILITY VALUE</th>
                    <th style="padding: 12px 16px;">REMARKS / PROVISION</th>
                    <th style="padding: 12px 16px; text-align: right;">CREATED AT</th>
                </tr>
            </thead>
            <tbody>
                @forelse($records as $rec)
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 12px 16px; font-weight: 700; color: #0f172a;">
                            {{ $rec->series_title ?? 'N/A' }}
                        </td>
                        <td style="padding: 12px 16px; color: #2563eb; font-weight: 600;">
                            {{ $rec->val_amount }} {{ $rec->unit_name ?? 'Units' }}
                        </td>
                        <td style="padding: 12px 16px;">
                            @if($rec->time_value === 'P')
                                <span style="display: inline-block; padding: 3px 10px; background: #f0fdf4; color: #16a34a; border-radius: 12px; font-weight: 700; font-size: 11.5px;">PERMANENT</span>
                            @else
                                <span style="display: inline-block; padding: 3px 10px; background: #fff7ed; color: #ea580c; border-radius: 12px; font-weight: 700; font-size: 11.5px;">TEMPORARY</span>
                            @endif
                        </td>
                        <td style="padding: 12px 16px; font-weight: 600; color: #475569;">
                            {{ $rec->utility_name ?? 'Archival' }}
                        </td>
                        <td style="padding: 12px 16px; color: #64748b;">
                            {{ Str::limit($rec->remarks ?? '--', 40) }}
                        </td>
                        <td style="padding: 12px 16px; text-align: right; color: #64748b; font-size: 12px;">
                            {{ \Carbon\Carbon::parse($rec->created_at)->format('M d, Y h:i A') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="padding: 32px; text-align: center; color: #64748b;">
                            No record logs found matching criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top: 16px;">
            {{ $records->links() }}
        </div>
    </div>
</div>
