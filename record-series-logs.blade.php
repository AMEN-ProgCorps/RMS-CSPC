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
                <svg xmlns="http://www.w3.org/2000/svg" width="29" height="17" viewBox="0 0 30 23" fill="none">
<path d="M25.3125 7.5C25.5611 7.5 25.7996 7.40123 25.9754 7.22541C26.1512 7.0496 26.25 6.81114 26.25 6.5625C26.25 5.81658 25.9537 5.10121 25.4262 4.57376C24.8988 4.04632 24.1834 3.75 23.4375 3.75H14.0625C13.7714 3.75 13.4843 3.68223 13.224 3.55205C12.9636 3.42187 12.7372 3.23287 12.5625 3L10.875 0.75C10.7004 0.517133 10.4739 0.328126 10.2135 0.197949C9.95317 0.0677719 9.66609 0 9.375 0H2.8125C2.06658 0 1.35121 0.296316 0.823762 0.823762C0.296316 1.35121 0 2.06658 0 2.8125L0 19.6875C0 20.4334 0.296316 21.1488 0.823762 21.6762C1.35121 22.2037 2.06658 22.5 2.8125 22.5H23.625C24.216 22.5011 24.7922 22.3147 25.2706 21.9677C25.749 21.6207 26.105 21.1309 26.2875 20.5688L29.6063 10.5938C29.6532 10.4529 29.666 10.3029 29.6436 10.1561C29.6212 10.0094 29.5643 9.87 29.4775 9.74953C29.3907 9.62906 29.2765 9.53093 29.1444 9.46321C29.0123 9.39549 28.866 9.36011 28.7175 9.36H8.4675C8.17216 9.35983 7.88426 9.45265 7.64464 9.6253C7.40501 9.79795 7.22583 10.0417 7.1325 10.3219L3.70125 20.6156H2.76375C2.51511 20.6156 2.27665 20.5169 2.10084 20.341C1.92502 20.1652 1.82625 19.9268 1.82625 19.6781V2.80312C1.82625 2.55448 1.92502 2.31603 2.10084 2.14021C2.27665 1.9644 2.51511 1.86562 2.76375 1.86562H9.32625L11.0138 4.11563C11.7225 5.06063 12.8325 5.61562 14.0138 5.61562H23.3888C23.9063 5.61562 24.3263 6.03562 24.3263 6.55312C24.3263 7.07062 24.7463 7.49062 25.2638 7.49062L25.3125 7.5Z" fill="#FFB300"/>
</svg>
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($totalSeriesLogs) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Total Record Series Logs</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #f0fdf4; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 66 66" fill="none">
<path fill-rule="evenodd" clip-rule="evenodd" d="M33 9.625C34.094 9.625 35.1432 10.0596 35.9168 10.8332C36.6904 11.6068 37.125 12.656 37.125 13.75V28.875H52.25C53.344 28.875 54.3932 29.3096 55.1668 30.0832C55.9404 30.8568 56.375 31.906 56.375 33C56.375 34.094 55.9404 35.1432 55.1668 35.9168C54.3932 36.6904 53.344 37.125 52.25 37.125H37.125V52.25C37.125 53.344 36.6904 54.3932 35.9168 55.1668C35.1432 55.9404 34.094 56.375 33 56.375C31.906 56.375 30.8568 55.9404 30.0832 55.1668C29.3096 54.3932 28.875 53.344 28.875 52.25V37.125H13.75C12.656 37.125 11.6068 36.6904 10.8332 35.9168C10.0596 35.1432 9.625 34.094 9.625 33C9.625 31.906 10.0596 30.8568 10.8332 30.0832C11.6068 29.3096 12.656 28.875 13.75 28.875H28.875V13.75C28.875 12.656 29.3096 11.6068 30.0832 10.8332C30.8568 10.0596 31.906 9.625 33 9.625Z" fill="#00992B"/>
</svg>
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($addedSeriesLogs) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Added Series</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #faf5ff; color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 54 54" fill="none">
<path d="M25.6498 40.8622L42.2908 24.2212C39.4913 23.0517 36.9487 21.3439 34.8073 19.1947C32.6571 17.0528 30.9485 14.5094 29.7785 11.709L13.1375 28.35C11.8393 29.6482 11.189 30.2985 10.631 31.014C9.97268 31.8588 9.40764 32.7724 8.9458 33.7387C8.55655 34.5577 8.2663 35.4307 7.6858 37.1722L4.6213 46.359C4.48025 46.7796 4.45933 47.2312 4.56087 47.6631C4.66242 48.095 4.8824 48.49 5.19611 48.8037C5.50981 49.1174 5.9048 49.3374 6.33667 49.4389C6.76854 49.5405 7.22017 49.5195 7.6408 49.3785L16.8275 46.314C18.5713 45.7335 19.442 45.4432 20.261 45.054C21.2315 44.592 22.1398 44.0302 22.9858 43.3687C23.7013 42.8107 24.3515 42.1605 25.6498 40.8622ZM46.9078 19.6042C48.567 17.945 49.4992 15.6946 49.4992 13.3481C49.4992 11.0016 48.567 8.75121 46.9078 7.09198C45.2486 5.43275 42.9982 4.50061 40.6517 4.50061C38.3052 4.50061 36.0548 5.43275 34.3955 7.09198L32.3998 9.08773L32.4853 9.33748C33.4685 12.1517 35.078 14.706 37.1923 16.8075C39.3567 18.9851 42.0003 20.6263 44.912 21.6L46.9078 19.6042Z" fill="#0059FF"/>
</svg>
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($updatedSeriesLogs) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Updated Series</div>
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
