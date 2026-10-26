<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.admin')] #[Title('Admin Console - File Upload Activity Logs')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $officeFilter = '';
    public string $dateFilter = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_activity_logs)) {
            $this->redirect(route('portal'));
            return;
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingOfficeFilter(): void
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
        $this->officeFilter = '';
        $this->dateFilter = '';
        $this->resetPage();
    }

    /**
     * Stream Excel-compatible CSV export of file upload activity logs with active filters applied.
     */
    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_activity_logs)) {
            abort(403, 'Unauthorized access.');
        }

        $docDataTable = \Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data';
        $accountTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $accountDetailsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        $query = DB::table($docDataTable . ' as document_data')
            ->leftJoin($accountTable . ' as account', 'document_data.uploaded_by', '=', 'account.id')
            ->leftJoin($accountDetailsTable . ' as account_details', 'account.id', '=', 'account_details.account_id')
            ->leftJoin($officeTable . ' as office', 'document_data.user_office', '=', 'office.office_code')
            ->select([
                'document_data.*',
                'account.username',
                'account_details.email',
                'account_details.first_name',
                'account_details.last_name',
                'office.office_name',
            ]);

        if (!empty($this->search)) {
            $searchVal = '%' . $this->search . '%';
            $query->where(function ($q) use ($searchVal) {
                $q->where('document_data.document_name', 'ilike', $searchVal)
                  ->orWhere('document_data.document_id', 'ilike', $searchVal)
                  ->orWhere('document_data.document_path', 'ilike', $searchVal)
                  ->orWhere('account.username', 'ilike', $searchVal)
                  ->orWhere('account_details.first_name', 'ilike', $searchVal)
                  ->orWhere('account_details.last_name', 'ilike', $searchVal)
                  ->orWhere('office.office_name', 'ilike', $searchVal);
            });
        }

        if (!empty($this->officeFilter)) {
            $query->where('document_data.user_office', $this->officeFilter);
        }

        if (!empty($this->dateFilter)) {
            $query->whereDate('document_data.date_added', $this->dateFilter);
        }

        $records = $query->orderBy('document_data.date_added', 'desc')->get();

        // Audit log export action
        DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
            'changes' => "Exported File Upload Activity Logs to CSV (" . count($records) . " records)",
            'admin_id' => auth()->id(),
            'what_system' => 3, // Admin Console
            'when_changes' => now(),
        ]);

        $filename = 'file_upload_activity_logs_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ];

        return new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($records) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM

            fputcsv($handle, [
                'Log ID',
                'Document Tracking ID',
                'Document Name',
                'Origin Office Code',
                'Office Name',
                'Uploaded By (Full Name)',
                'Username',
                'Email',
                'Storage Path',
                'Date Uploaded',
            ]);

            foreach ($records as $item) {
                $fullName = trim(($item->first_name ?? '') . ' ' . ($item->last_name ?? ''));
                if (empty($fullName)) {
                    $fullName = $item->username ?: 'User #' . ($item->uploaded_by ?? 'N/A');
                }

                fputcsv($handle, [
                    $item->id ?? '',
                    $item->document_id ?? 'N/A',
                    $item->document_name ?? 'Unnamed',
                    $item->user_office ?? 'N/A',
                    $item->office_name ?? 'N/A',
                    $fullName,
                    $item->username ?? 'N/A',
                    $item->email ?? 'N/A',
                    $item->document_path ?? '',
                    $item->date_added ? \Carbon\Carbon::parse($item->date_added)->format('Y-m-d H:i:s') : 'N/A',
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    public function with(): array
    {
        $docDataTable = \Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data';
        $accountTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $accountDetailsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        $query = DB::table($docDataTable . ' as document_data')
            ->leftJoin($accountTable . ' as account', 'document_data.uploaded_by', '=', 'account.id')
            ->leftJoin($accountDetailsTable . ' as account_details', 'account.id', '=', 'account_details.account_id')
            ->leftJoin($officeTable . ' as office', 'document_data.user_office', '=', 'office.office_code')
            ->select([
                'document_data.*',
                'account.username',
                'account_details.email',
                'account_details.first_name',
                'account_details.last_name',
                'office.office_name',
            ]);

        if (!empty($this->search)) {
            $searchVal = '%' . $this->search . '%';
            $query->where(function ($q) use ($searchVal) {
                $q->where('document_data.document_name', 'ilike', $searchVal)
                  ->orWhere('document_data.document_id', 'ilike', $searchVal)
                  ->orWhere('document_data.document_path', 'ilike', $searchVal)
                  ->orWhere('account.username', 'ilike', $searchVal)
                  ->orWhere('account_details.first_name', 'ilike', $searchVal)
                  ->orWhere('account_details.last_name', 'ilike', $searchVal)
                  ->orWhere('office.office_name', 'ilike', $searchVal);
            });
        }

        if (!empty($this->officeFilter)) {
            $query->where('document_data.user_office', $this->officeFilter);
        }

        if (!empty($this->dateFilter)) {
            $query->whereDate('document_data.date_added', $this->dateFilter);
        }

        $totalUploads = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data')->count();
        $rdpUploads   = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data')->where('document_path', 'like', '%rdp%')->count();
        $dtsUploads   = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data')->where('document_path', 'like', '%dts%')->count();
        $recent24h    = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data')->where('date_added', '>=', now()->subHours(24))->count();

        $officesList = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->orderBy('office_name', 'asc')->get();

        return [
            'uploads'      => $query->orderBy('document_data.date_added', 'desc')->paginate(15),
            'totalUploads' => $totalUploads,
            'rdpUploads'   => $rdpUploads,
            'dtsUploads'   => $dtsUploads,
            'recent24h'    => $recent24h,
            'officesList'  => $officesList,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/console.css', 'resources/css/admin/activity_logs.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="activity-logs-container">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
        <div>
            <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0;">File Upload Activity Logs</h1>
            <p style="font-size: 14px; color: #64748b; margin: 4px 0 0 0;">Audit trail of all document and file uploads across DTS, RDP, and system modules.</p>
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
                📄
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($totalUploads) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Total File Uploads</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #f0fdf4; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                📁
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($rdpUploads) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">RDP File Uploads</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #faf5ff; color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                📨
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($dtsUploads) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">DTS Document Uploads</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #fff7ed; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                ⚡
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($recent24h) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Recent (Last 24 Hrs)</div>
            </div>
        </div>
    </div>

    <!-- Filters & Table Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02);">
        <div style="display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 20px;">
            <div style="display: flex; gap: 12px; flex-wrap: wrap; flex: 1;">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search file name, ID, uploader..." style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 260px;">
                
                <select wire:model.live="officeFilter" style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff;">
                    <option value="">All Offices</option>
                    @foreach($officesList as $off)
                        <option value="{{ $off->office_code }}">{{ $off->office_name }} ({{ $off->office_code }})</option>
                    @endforeach
                </select>

                <input type="date" wire:model.live="dateFilter" style="padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff;">

                @if($search || $officeFilter || $dateFilter)
                    <button type="button" wire:click="clearFilters" style="padding: 9px 16px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px;">
                        Reset Filters
                    </button>
                @endif
            </div>

            <div>
                <button type="button" class="btn-export" wire:click="exportCsv" wire:loading.attr="disabled" title="Export filtered file upload logs as CSV">
                    <i class="fa-solid fa-file-csv" wire:loading.remove wire:target="exportCsv"></i>
                    <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="exportCsv"></i>
                    <span wire:loading.remove wire:target="exportCsv">Export CSV</span>
                    <span wire:loading wire:target="exportCsv">Generating...</span>
                </button>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; color: #475569;">
                        <th style="padding: 12px 16px;">UPLOADER</th>
                        <th style="padding: 12px 16px;">OFFICE</th>
                        <th style="padding: 12px 16px;">DOCUMENT NAME & ID</th>
                        <th style="padding: 12px 16px;">SUBSYSTEM</th>
                        <th style="padding: 12px 16px; text-align: right;">DATE UPLOADED</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($uploads as $item)
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px 16px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    @php
                                        $uploaderName = trim(($item->first_name ?? '') . ' ' . ($item->last_name ?? ''));
                                        if (empty($uploaderName)) {
                                            $uploaderName = $item->username ?? 'User #' . ($item->uploaded_by ?? 'N/A');
                                        }
                                        $initials = strtoupper(substr($uploaderName, 0, 2));
                                    @endphp
                                    <div style="width: 34px; height: 34px; border-radius: 50%; background: #2563eb; color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px;">
                                        {{ $initials }}
                                    </div>
                                    <div>
                                        <div style="font-weight: 700; color: #0f172a;">{{ $uploaderName }}</div>
                                        <div style="font-size: 11.5px; color: #64748b;">{{ $item->email ?? 'Account User' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td style="padding: 12px 16px;">
                                <span style="display: inline-block; padding: 2px 8px; background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 700; font-size: 11.5px;">
                                    {{ $item->office_name ?: ($item->user_office ?: 'System') }}
                                </span>
                            </td>
                            <td style="padding: 12px 16px;">
                                <div style="font-weight: 700; color: #0f172a; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    {{ $item->document_name ?? 'Attachment File' }}
                                </div>
                                <div style="font-size: 11px; color: #2563eb; font-weight: 700; font-family: monospace;">
                                    ID: {{ $item->document_id }}
                                </div>
                            </td>
                            <td style="padding: 12px 16px;">
                                @php
                                    $isRDP = str_contains(strtolower($item->document_path ?? ''), 'rdp');
                                @endphp
                                @if($isRDP)
                                    <span style="display: inline-block; padding: 3px 10px; background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 12px; font-weight: 700; font-size: 11.5px;">
                                        📁 RDP
                                    </span>
                                @else
                                    <span style="display: inline-block; padding: 3px 10px; background: #faf5ff; color: #9333ea; border: 1px solid #e9d5ff; border-radius: 12px; font-weight: 700; font-size: 11.5px;">
                                        📨 DTS
                                    </span>
                                @endif
                            </td>
                            <td style="padding: 12px 16px; text-align: right; color: #64748b; font-size: 12px; font-weight: 600;">
                                {{ \Carbon\Carbon::parse($item->date_added)->format('M d, Y h:i:s A') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="padding: 32px; text-align: center; color: #64748b;">
                                No file upload logs found matching criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px;">
            {{ $uploads->links() }}
        </div>
    </div>
</div>
