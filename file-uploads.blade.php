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

    public function with(): array
    {
        $query = DB::table('document_data')
            ->leftJoin('account', 'document_data.uploaded_by', '=', 'account.id')
            ->leftJoin('account_details', 'account.id', '=', 'account_details.account_id')
            ->leftJoin('office', 'document_data.user_office', '=', 'office.office_code')
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
                $q->where('document_data.document_name', 'like', $searchVal)
                  ->orWhere('document_data.document_id', 'like', $searchVal)
                  ->orWhere('document_data.document_path', 'like', $searchVal)
                  ->orWhere('account.username', 'like', $searchVal)
                  ->orWhere('account_details.first_name', 'like', $searchVal)
                  ->orWhere('account_details.last_name', 'like', $searchVal)
                  ->orWhere('office.office_name', 'like', $searchVal);
            });
        }

        if (!empty($this->officeFilter)) {
            $query->where('document_data.user_office', $this->officeFilter);
        }

        if (!empty($this->dateFilter)) {
            $query->whereDate('document_data.date_added', $this->dateFilter);
        }

        $totalUploads = DB::table('document_data')->count();
        $rdpUploads   = DB::table('document_data')->where('document_path', 'like', '%rdp%')->count();
        $dtsUploads   = DB::table('document_data')->where('document_path', 'like', '%dts%')->count();
        $recent24h    = DB::table('document_data')->where('date_added', '>=', now()->subHours(24))->count();

        $officesList = DB::table('office')->orderBy('office_name', 'asc')->get();

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
                <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 143 143" fill="none">
<path d="M107.25 131.083C110.411 131.083 113.442 129.828 115.677 127.593C117.911 125.358 119.167 122.327 119.167 119.167V47.6666L83.4168 11.9166H35.7502C32.5897 11.9166 29.5586 13.1721 27.3238 15.4069C25.089 17.6417 23.8335 20.6728 23.8335 23.8333V119.167C23.8335 122.327 25.089 125.358 27.3238 127.593C29.5586 129.828 32.5897 131.083 35.7502 131.083H107.25ZM77.4585 23.8333L107.25 53.625H77.4585V23.8333ZM41.7085 47.6666H59.5835V59.5833H41.7085V47.6666ZM41.7085 71.5H101.292V83.4166H41.7085V71.5ZM41.7085 95.3333H101.292V107.25H41.7085V95.3333Z" fill="#A2A2A2"/>
</svg>
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($totalUploads) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">Total File Uploads</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #f0fdf4; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 175 175" fill="none">
<path d="M143.062 19.8698H32.0103C27.4395 19.8308 23.0394 21.6044 19.7733 24.8022C16.5071 28.0001 14.6409 32.3616 14.5832 36.9323V52.3906C14.5399 56.0842 15.6763 59.6953 17.827 62.6985C19.9778 65.7017 23.0307 67.9403 26.5416 69.0885V126.401C26.5464 130.194 27.3101 133.949 28.7877 137.442C30.2654 140.936 32.4272 144.099 35.1457 146.745C40.644 152.16 48.0642 155.175 55.7811 155.13H119.292C127.009 155.175 134.429 152.16 139.927 146.745C142.646 144.099 144.807 140.936 146.285 137.442C147.763 133.949 148.526 130.194 148.531 126.401V69.0885C151.969 67.9471 154.964 65.7584 157.095 62.8293C159.227 59.9002 160.388 56.3776 160.417 52.7552V37.2969C160.446 35.0064 160.017 32.7331 159.156 30.6103C158.296 28.4876 157.02 26.558 155.403 24.9349C153.787 23.3117 151.863 22.0276 149.744 21.1579C147.624 20.2881 145.353 19.8502 143.062 19.8698ZM108.135 106.495C107.63 107.006 107.028 107.411 106.365 107.687C105.701 107.962 104.989 108.102 104.271 108.099C103.55 108.12 102.833 107.988 102.167 107.711C101.5 107.435 100.9 107.02 100.406 106.495L93.1145 99.2031V123.849C93.1145 125.299 92.5383 126.69 91.5127 127.716C90.4871 128.742 89.0961 129.318 87.6457 129.318C86.1953 129.318 84.8043 128.742 83.7787 127.716C82.7531 126.69 82.177 125.299 82.177 123.849V99.2031L74.8853 106.495C73.8486 107.461 72.4774 107.987 71.0607 107.962C69.6439 107.937 68.2921 107.363 67.2902 106.361C66.2882 105.359 65.7142 104.007 65.6892 102.59C65.6642 101.173 66.1901 99.8023 67.1561 98.7656L81.7395 84.1823C82.5416 83.3656 83.5186 82.7239 84.5832 82.2864C85.2812 81.9895 86.0176 81.7931 86.7707 81.7031C87.5423 81.5637 88.3325 81.5637 89.1041 81.7031C89.8867 81.7857 90.6402 81.9802 91.3645 82.2864C92.4291 82.7434 93.377 83.3753 94.2082 84.1823L108.792 98.7656C109.258 99.3153 109.611 99.9517 109.831 100.638C110.05 101.325 110.132 102.048 110.071 102.767C110.01 103.485 109.808 104.184 109.475 104.824C109.143 105.464 108.688 106.032 108.135 106.495ZM149.479 52.3906C149.47 53.2204 149.296 54.04 148.967 54.802C148.639 55.564 148.162 56.2533 147.565 56.8298C146.968 57.4063 146.263 57.8586 145.49 58.1605C144.717 58.4624 143.892 58.6079 143.062 58.5885H32.0103C31.1772 58.6079 30.3484 58.4629 29.5713 58.1619C28.7942 57.8609 28.0841 57.4097 27.4814 56.8341C26.8787 56.2585 26.3954 55.5699 26.059 54.8074C25.7226 54.045 25.5397 53.2237 25.5207 52.3906V36.9323C25.5395 36.1024 25.7229 35.2845 26.0602 34.526C26.3975 33.7675 26.8821 33.0836 27.4858 32.5138C28.0894 31.9441 28.8002 31.4998 29.5769 31.2069C30.3536 30.9139 31.1807 30.7781 32.0103 30.8073H143.062C143.888 30.778 144.712 30.9143 145.485 31.2081C146.257 31.5019 146.963 31.9473 147.561 32.518C148.159 33.0887 148.637 33.7733 148.966 34.5314C149.295 35.2894 149.47 36.1058 149.479 36.9323V52.3906Z" fill="#FFB300"/>
</svg>
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($rdpUploads) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">RDP File Uploads</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #faf5ff; color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 143 143" fill="none">
<path d="M77.4583 119.167H35.75C34.1698 119.167 32.6542 118.539 31.5368 117.421C30.4194 116.304 29.7917 114.789 29.7917 113.208V29.7916C29.7917 28.2114 30.4194 26.6959 31.5368 25.5784C32.6542 24.461 34.1698 23.8333 35.75 23.8333H65.5417V41.7083C65.5417 46.449 67.4249 50.9956 70.7771 54.3478C74.1293 57.7 78.6759 59.5833 83.4167 59.5833H101.292V71.5C101.292 73.0802 101.919 74.5957 103.037 75.7131C104.154 76.8305 105.67 77.4583 107.25 77.4583C108.83 77.4583 110.346 76.8305 111.463 75.7131C112.581 74.5957 113.208 73.0802 113.208 71.5V53.2675C113.147 52.72 113.027 52.1807 112.851 51.6587V51.1225C112.571 50.5061 112.188 49.9419 111.719 49.4541L75.9687 13.7041C75.481 13.2347 74.9168 12.8519 74.3004 12.572C74.1227 12.5451 73.9419 12.5451 73.7642 12.572C73.1576 12.2278 72.4897 12.0052 71.7979 11.9166H35.75C31.0093 11.9166 26.4627 13.7999 23.1105 17.1521C19.7583 20.5043 17.875 25.0509 17.875 29.7916V113.208C17.875 117.949 19.7583 122.496 23.1105 125.848C26.4627 129.2 31.0093 131.083 35.75 131.083H77.4583C79.0386 131.083 80.5541 130.456 81.6715 129.338C82.7889 128.221 83.4167 126.705 83.4167 125.125C83.4167 123.545 82.7889 122.029 81.6715 120.912C80.5541 119.794 79.0386 119.167 77.4583 119.167ZM77.4583 32.2345L92.8904 47.6666H83.4167C81.8364 47.6666 80.3209 47.0389 79.2035 45.9215C78.0861 44.8041 77.4583 43.2885 77.4583 41.7083V32.2345ZM47.6667 47.6666C46.0864 47.6666 44.5709 48.2944 43.4535 49.4118C42.3361 50.5292 41.7083 52.0447 41.7083 53.625C41.7083 55.2052 42.3361 56.7207 43.4535 57.8381C44.5709 58.9555 46.0864 59.5833 47.6667 59.5833H53.625C55.2052 59.5833 56.7208 58.9555 57.8382 57.8381C58.9556 56.7207 59.5833 55.2052 59.5833 53.625C59.5833 52.0447 58.9556 50.5292 57.8382 49.4118C56.7208 48.2944 55.2052 47.6666 53.625 47.6666H47.6667ZM83.4167 71.5H47.6667C46.0864 71.5 44.5709 72.1277 43.4535 73.2451C42.3361 74.3625 41.7083 75.878 41.7083 77.4583C41.7083 79.0385 42.3361 80.5541 43.4535 81.6715C44.5709 82.7889 46.0864 83.4166 47.6667 83.4166H83.4167C84.9969 83.4166 86.5124 82.7889 87.6298 81.6715C88.7472 80.5541 89.375 79.0385 89.375 77.4583C89.375 75.878 88.7472 74.3625 87.6298 73.2451C86.5124 72.1277 84.9969 71.5 83.4167 71.5ZM123.397 103.02L111.48 91.1029C110.914 90.5604 110.246 90.1352 109.514 89.8516C108.064 89.2557 106.436 89.2557 104.986 89.8516C104.254 90.1352 103.586 90.5604 103.02 91.1029L91.1029 103.02C89.9809 104.142 89.3506 105.663 89.3506 107.25C89.3506 108.837 89.9809 110.358 91.1029 111.48C92.2249 112.602 93.7466 113.233 95.3333 113.233C96.92 113.233 98.4418 112.602 99.5637 111.48L101.292 109.693V125.125C101.292 126.705 101.919 128.221 103.037 129.338C104.154 130.456 105.67 131.083 107.25 131.083C108.83 131.083 110.346 130.456 111.463 129.338C112.581 128.221 113.208 126.705 113.208 125.125V109.693L114.936 111.48C115.49 112.039 116.149 112.482 116.875 112.785C117.601 113.087 118.38 113.243 119.167 113.243C119.953 113.243 120.732 113.087 121.458 112.785C122.184 112.482 122.843 112.039 123.397 111.48C123.956 110.926 124.399 110.267 124.701 109.541C125.004 108.815 125.16 108.037 125.16 107.25C125.16 106.463 125.004 105.685 124.701 104.959C124.399 104.232 123.956 103.573 123.397 103.02ZM71.5 107.25C73.0802 107.25 74.5958 106.622 75.7132 105.505C76.8306 104.387 77.4583 102.872 77.4583 101.292C77.4583 99.7114 76.8306 98.1958 75.7132 97.0784C74.5958 95.961 73.0802 95.3333 71.5 95.3333H47.6667C46.0864 95.3333 44.5709 95.961 43.4535 97.0784C42.3361 98.1958 41.7083 99.7114 41.7083 101.292C41.7083 102.872 42.3361 104.387 43.4535 105.505C44.5709 106.622 46.0864 107.25 47.6667 107.25H71.5Z" fill="#043899"/>
</svg>
            </div>
            <div>
                <div style="font-size: 22px; font-weight: 800; color: #0f172a;">{{ number_format($dtsUploads) }}</div>
                <div style="font-size: 13px; font-weight: 600; color: #64748b;">DTS Document Uploads</div>
            </div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #fff7ed; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800;">
                <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" viewBox="0 0 204 204" fill="none">
<path d="M114.75 68H102V110.5L138.38 132.09L144.5 121.805L114.75 104.125V68ZM110.5 25.5C90.2109 25.5 70.7529 33.5598 56.4063 47.9063C42.0598 62.2529 34 81.7109 34 102H8.5L42.16 136.255L76.5 102H51C51 86.2196 57.2687 71.0856 68.4271 59.9271C79.5856 48.7687 94.7196 42.5 110.5 42.5C126.28 42.5 141.414 48.7687 152.573 59.9271C163.731 71.0856 170 86.2196 170 102C170 117.78 163.731 132.914 152.573 144.073C141.414 155.231 126.28 161.5 110.5 161.5C94.095 161.5 79.22 154.785 68.51 143.99L56.44 156.06C63.5087 163.204 71.9299 168.867 81.2118 172.72C90.4937 176.573 100.45 178.538 110.5 178.5C130.789 178.5 150.247 170.44 164.594 156.094C178.94 141.747 187 122.289 187 102C187 81.7109 178.94 62.2529 164.594 47.9063C150.247 33.5598 130.789 25.5 110.5 25.5Z" fill="#E16A00"/>
</svg>
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
