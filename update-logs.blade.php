<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.admin')] #[Title('Admin Console - Volume Conversion Logs')] class extends Component {
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

        $totalLogs = DB::table('admin_logs')->where('what_system', 2)->count();
        $conversionLogs = DB::table('admin_logs')->where('what_system', 2)->where('changes', 'like', '%Conversion%')->count();
        $unitLogs = DB::table('admin_logs')->where('what_system', 2)->where('changes', 'like', '%Unit%')->count();
        $recent24hLogs = DB::table('admin_logs')->where('what_system', 2)->where('when_changes', '>=', now()->subHours(24))->count();

        return [
            'logs'           => $query->orderBy('admin_logs.when_changes', 'desc')->paginate(15),
            'totalLogs'      => $totalLogs,
            'conversionLogs' => $conversionLogs,
            'unitLogs'       => $unitLogs,
            'recent24hLogs'  => $recent24hLogs,
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
            <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0;">Volume Conversion Logs</h1>
            <p style="font-size: 14px; color: #64748b; margin: 4px 0 0 0;">Audit log of administrative volume conversion rule updates, ratio edits, and volume unit standard modifications.</p>
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
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 46 46" fill="none">
<path d="M40.25 21.2941V9.58329C40.25 8.56663 39.8461 7.59161 39.1272 6.87272C38.4084 6.15383 37.4333 5.74996 36.4167 5.74996H28.405C27.6 3.52663 25.4917 1.91663 23 1.91663C20.5083 1.91663 18.4 3.52663 17.595 5.74996H9.58333C7.475 5.74996 5.75 7.47496 5.75 9.58329V36.4166C5.75 37.4333 6.15387 38.4083 6.87276 39.1272C7.59165 39.8461 8.56667 40.25 9.58333 40.25H21.2942C23.7092 42.6266 27.0058 44.0833 30.6667 44.0833C38.0842 44.0833 44.0833 38.0841 44.0833 30.6666C44.0833 27.0058 42.6267 23.7091 40.25 21.2941ZM23 5.74996C24.0542 5.74996 24.9167 6.61246 24.9167 7.66663C24.9167 8.72079 24.0542 9.58329 23 9.58329C21.9458 9.58329 21.0833 8.72079 21.0833 7.66663C21.0833 6.61246 21.9458 5.74996 23 5.74996ZM9.58333 36.4166V9.58329H13.4167V13.4166H32.5833V9.58329H36.4167V18.5533C34.6725 17.7291 32.7367 17.25 30.6667 17.25H13.4167V21.0833H21.275C20.125 22.1758 19.2433 23.4791 18.5533 24.9166H13.4167V28.75H17.4033C17.3075 29.3825 17.25 30.015 17.25 30.6666C17.25 32.7366 17.7292 34.6725 18.5533 36.4166H9.58333ZM30.6667 40.25C25.3767 40.25 21.0833 35.9566 21.0833 30.6666C21.0833 25.3766 25.3767 21.0833 30.6667 21.0833C35.9567 21.0833 40.25 25.3766 40.25 30.6666C40.25 35.9566 35.9567 40.25 30.6667 40.25ZM31.625 31.1458L37.1067 34.385L35.6692 36.7233L28.75 32.5833V23H31.625V31.1458Z" fill="#0059FF"/>
</svg>
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($totalLogs) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Total RDP System Logs</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #f0fdf4; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 52 52" fill="none">
<path d="M26.0002 4.33337C14.0402 4.33337 4.3335 14.04 4.3335 26C4.3335 37.96 14.0402 47.6667 26.0002 47.6667C37.9602 47.6667 47.6668 37.96 47.6668 26C47.6668 14.04 37.9602 4.33337 26.0002 4.33337ZM26.1302 41.1667V36.8117H26.0002C23.2268 36.8117 20.4535 35.75 18.3302 33.6484C16.5666 31.8829 15.4657 29.5634 15.2131 27.0808C14.9605 24.5982 15.5716 22.1045 16.9435 20.02L19.3268 22.4034C17.7885 25.285 18.1785 28.925 20.6052 31.3517C22.1218 32.8684 24.1152 33.5834 26.1085 33.54V28.9034L32.2402 35.035L26.1302 41.1667ZM35.0352 31.98L32.6518 29.5967C34.1902 26.715 33.8002 23.075 31.3735 20.6484C30.6703 19.939 29.8332 19.3765 28.9108 18.9934C27.9883 18.6103 26.999 18.4142 26.0002 18.4167H25.8702V23.075L19.7385 16.965L25.8702 10.8334V15.21C28.6868 15.1667 31.5252 16.185 33.6702 18.3517C37.3535 22.035 37.8085 27.7767 35.0352 31.98Z" fill="#00B6C0"/>
</svg>
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($conversionLogs) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Conversion Rule Changes</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #faf5ff; color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 46 46" fill="none">
<path d="M25.3216 3.66557C23.8272 3.09097 22.1728 3.09097 20.6784 3.66557L16.2438 5.37044L34.9312 12.5579L41.3784 10.0783C41.0014 9.77708 40.5807 9.53519 40.1307 9.36094L25.3216 3.66557ZM30.9278 14.0989L12.2389 6.91144L5.87075 9.36094C5.40979 9.53919 4.99292 9.77877 4.62012 10.0797L23 17.1464L30.9278 14.0989ZM2.875 13.7209C2.875 13.3165 2.92531 12.9245 3.02594 12.545L21.5625 19.675V42.6031C21.2618 42.5347 20.9663 42.4449 20.6784 42.3343L5.86931 36.6389C4.9881 36.2999 4.23029 35.7018 3.69576 34.9235C3.16123 34.1452 2.87507 33.2232 2.875 32.279V13.7209ZM25.3216 42.3343C25.0321 42.4464 24.7375 42.5365 24.4375 42.6046V19.675L42.9741 12.545C43.0737 12.9245 43.124 13.3165 43.125 13.7209V32.279C43.1249 33.2232 42.8388 34.1452 42.3042 34.9235C41.7697 35.7018 41.0119 36.2999 40.1307 36.6389L25.3216 42.3343Z" fill="#FF01AF"/>
</svg>
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($unitLogs) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Volume Unit Updates</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #fff7ed; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 49 49" fill="none">
<path d="M27.5623 16.3333H24.4998V26.5417L33.2382 31.7275L34.7082 29.2571L27.5623 25.0104V16.3333ZM26.5415 6.125C21.6682 6.125 16.9944 8.06093 13.5484 11.5069C10.1024 14.9529 8.1665 19.6266 8.1665 24.5H2.0415L10.1265 32.7279L18.3748 24.5H12.2498C12.2498 20.7096 13.7556 17.0745 16.4358 14.3943C19.116 11.7141 22.7511 10.2083 26.5415 10.2083C30.3319 10.2083 33.967 11.7141 36.6472 14.3943C39.3274 17.0745 40.8332 20.7096 40.8332 24.5C40.8332 28.2904 39.3274 31.9255 36.6472 34.6057C33.967 37.2859 30.3319 38.7917 26.5415 38.7917C22.6011 38.7917 19.0282 37.1788 16.4557 34.5858L13.5565 37.485C15.2544 39.2009 17.2771 40.5613 19.5066 41.4867C21.7361 42.4122 24.1276 42.8841 26.5415 42.875C31.4149 42.875 36.0886 40.9391 39.5346 37.4931C42.9806 34.0471 44.9165 29.3734 44.9165 24.5C44.9165 19.6266 42.9806 14.9529 39.5346 11.5069C36.0886 8.06093 31.4149 6.125 26.5415 6.125Z" fill="#FF7800"/>
</svg>
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
                            No update logs found matching criteria.
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
