<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.admin')] #[Title('Admin Console - Record Series Audit Logs')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $dateFilter = '';

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

    public function updatingDateFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->dateFilter = '';
        $this->resetPage();
    }

    public function with(): array
    {
        $query = DB::table('admin_logs')
            ->leftJoin('account', 'admin_logs.admin_id', '=', 'account.id')
            ->leftJoin('account_details', 'account.id', '=', 'account_details.account_id')
            ->where('admin_logs.what_system', 2) // RDP Subsystem
            ->where(function ($q) {
                $q->where('admin_logs.changes', 'like', '%Series%')
                  ->orWhere('admin_logs.changes', 'like', '%Record Series%');
            })
            ->select([
                'admin_logs.*',
                'account.username',
                'account_details.email',
                'account_details.first_name',
                'account_details.last_name',
            ]);

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('admin_logs.changes', 'like', '%' . $this->search . '%')
                  ->orWhere('account.username', 'like', '%' . $this->search . '%')
                  ->orWhere('account_details.first_name', 'like', '%' . $this->search . '%')
                  ->orWhere('account_details.last_name', 'like', '%' . $this->search . '%');
            });
        }

        if (!empty($this->dateFilter)) {
            $query->whereDate('admin_logs.when_changes', $this->dateFilter);
        }

        $totalSeriesLogs = DB::table('admin_logs')->where('what_system', 2)->where('changes', 'like', '%Series%')->count();
        $addedSeriesLogs = DB::table('admin_logs')->where('what_system', 2)->where('changes', 'like', '%Added Record Series%')->count();
        $updatedSeriesLogs = DB::table('admin_logs')->where('what_system', 2)->where('changes', 'like', '%Updated Record Series%')->count();
        $recent24hLogs = DB::table('admin_logs')->where('what_system', 2)->where('changes', 'like', '%Series%')->where('when_changes', '>=', now()->subHours(24))->count();

        return [
            'logs'              => $query->orderBy('admin_logs.when_changes', 'desc')->paginate(15),
            'totalSeriesLogs'   => $totalSeriesLogs,
            'addedSeriesLogs'   => $addedSeriesLogs,
            'updatedSeriesLogs' => $updatedSeriesLogs,
            'recent24hLogs'     => $recent24hLogs,
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
            <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0;">Record Series Audit Logs</h1>
            <p style="font-size: 14px; color: #64748b; margin: 4px 0 0 0;">Audit log of record series additions, updates, hierarchy modifications, and schedule deletions.</p>
        </div>
        <div style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: #16a34a; background: #f0fdf4; padding: 6px 14px; border-radius: 20px; border: 1px solid #bbf7d0;">
            <span style="width: 8px; height: 8px; border-radius: 50%; background: #16a34a; display: inline-block;"></span>
            LIVE AUDIT FEED
        </div>
    </div>

    <!-- Stat Overview Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                📁
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($totalSeriesLogs) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Total Record Series Logs</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #f0fdf4; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                ➕
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($addedSeriesLogs) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Added Series</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #faf5ff; color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                ✏️
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($updatedSeriesLogs) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Updated Series</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #fff7ed; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                ⚡
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($recent24hLogs) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Recent (Last 24 Hrs)</div>
            </div>
        </div>
    </div>

    <!-- Filters & Table Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02);">
        <div style="display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 20px;">
            <div style="display: flex; gap: 12px; flex-wrap: wrap; flex: 1;">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search log changes, admin name..." style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 280px;">
                
                <input type="date" wire:model.live="dateFilter" style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff;">

                @if($search || $dateFilter)
                    <button type="button" wire:click="clearFilters" style="padding: 9px 16px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px;">
                        Reset Filters
                    </button>
                @endif
            </div>
        </div>

        <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: left;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; color: #475569;">
                    <th style="padding: 12px 16px;">ADMINISTRATOR</th>
                    <th style="padding: 12px 16px;">CHANGE DESCRIPTION</th>
                    <th style="padding: 12px 16px;">SUBSYSTEM</th>
                    <th style="padding: 12px 16px; text-align: right;">TIMESTAMP</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 12px 16px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                @php
                                    $adminName = trim(($log->first_name ?? '') . ' ' . ($log->last_name ?? ''));
                                    if (empty($adminName)) {
                                        $adminName = $log->username ?? 'Admin #' . $log->admin_id;
                                    }
                                    $initials = strtoupper(substr($adminName, 0, 2));
                                @endphp
                                <div style="width: 34px; height: 34px; border-radius: 50%; background: #2563eb; color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px;">
                                    {{ $initials }}
                                </div>
                                <div>
                                    <div style="font-weight: 700; color: #0f172a;">{{ $adminName }}</div>
                                    <div style="font-size: 11.5px; color: #64748b;">{{ $log->email ?? 'System Admin' }}</div>
                                </div>
                            </div>
                        </td>
                        <td style="padding: 12px 16px; color: #1e293b; font-weight: 600; font-size: 13px;">
                            {{ $log->changes }}
                        </td>
                        <td style="padding: 12px 16px;">
                            <span style="display: inline-block; padding: 3px 10px; background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 12px; font-weight: 700; font-size: 11.5px;">RDP</span>
                        </td>
                        <td style="padding: 12px 16px; text-align: right; color: #64748b; font-size: 12px; font-weight: 600;">
                            {{ \Carbon\Carbon::parse($log->when_changes)->format('M d, Y h:i:s A') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="padding: 32px; text-align: center; color: #64748b;">
                            No record series audit logs found matching criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top: 16px;">
            {{ $logs->links() }}
        </div>
    </div>
</div>
