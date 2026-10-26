<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Cockpit File Manager')] class extends Component {
    use WithPagination;

    #[Url]
    public string $activeTab = 'all'; // 'all', 'dts', 'rdp', 'shared'
    public string $viewMode = 'grid'; // 'grid' or 'list'
    public int $perPage = 12; // Dynamic pagination size
    public string $search = '';
    public string $selectedOffice = '';
    public string $sortBy = 'date_added'; // 'date_added', 'date_modified', 'document_name', 'office'
    public string $sortDirection = 'desc'; // 'asc' or 'desc'
    public ?string $selectedFileId = null;
    public bool $showMetadataModal = false;
    public ?string $metadataDocId = null;

    public function openMetadataModal(?string $docId = null): void
    {
        $this->metadataDocId = $docId ?: $this->selectedFileId;
        if ($this->metadataDocId) {
            $this->showMetadataModal = true;
        }
    }

    public function closeMetadataModal(): void
    {
        $this->showMetadataModal = false;
        $this->metadataDocId = null;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->selectedFileId = null;
        $this->resetPage();
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = $mode;
        $this->perPage = $mode === 'grid' ? 12 : 15;
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void    { $this->resetPage(); }
    public function updatedSelectedOffice(): void { $this->resetPage(); }
    public function updatedSortBy(): void    { $this->resetPage(); }

    public function toggleDirection(): void
    {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
    }

    public function selectFile(?string $fileId): void
    {
        if ($this->selectedFileId === $fileId) {
            $this->selectedFileId = null;
        } else {
            $this->selectedFileId = $fileId;
        }
    }

    public function closeInspector(): void
    {
        $this->selectedFileId = null;
    }

    public function with(): array
    {
        $user = Auth::user();
        $perms = $user?->permissions;
        
        $isSuperAdmin = $perms && !empty($perms->is_sadm);
        $isAdmin = $user && ($user->account_role == 1 || $isSuperAdmin);
        $canViewAll = $isSuperAdmin || $isAdmin || ($perms && !empty($perms->rdp_view_all_files));
        $userOfficeCode = $user?->details?->office?->office_code;
        $hasOfficeAccess = !empty($userOfficeCode) || $canViewAll;

        if (!$hasOfficeAccess) {
            return [
                'files' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage),
                'offices' => [],
                'canViewAll' => false,
                'userOfficeCode' => null,
                'selectedFile' => null,
                'hasOfficeAccess' => false,
                'stats' => [
                    'total' => 0,
                    'dts' => 0,
                    'rdp' => 0,
                    'shared' => 0,
                ]
            ];
        }

        $offices = [];
        if ($canViewAll) {
            $offices = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')
                ->select('office_code', 'office_name')
                ->where('is_active', true)
                ->whereNotIn('office_code', ['ORIGIN', '[H]'])
                ->orderBy('office_name', 'asc')
                ->get();
        }

        $totalFilesCount = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data')->count();
        
        $dtsFilesCount = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data')
            ->where(function ($q) {
                $q->whereIn('document_id', function ($sub) {
                    $sub->select('document_id')->from('dts_sequence_list');
                })->orWhere('document_path', 'like', '%dts%');
            })->count();

        $rdpFilesCount = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data')
            ->where(function ($q) {
                $q->whereIn('document_id', function ($sub) {
                    $sub->select('upload_doc_id_handler')->from('rdp_record')->whereNotNull('upload_doc_id_handler');
                })->orWhereIn('document_id', function ($sub) {
                    $sub->select('parent_id')->from('rdp_document_record');
                })->orWhere('document_path', 'like', '%rdp%');
            })->count();

        $hasCanView = \Illuminate\Support\Facades\Schema::hasColumn('rdp_duplication_section', 'can_view_file');

        $sharedFilesCount = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data')
            ->where(function ($q) use ($hasCanView) {
                $q->whereIn('document_id', function ($sub) use ($hasCanView) {
                    $sub->select('upload_doc_id_handler')
                        ->from('rdp_record')
                        ->whereIn('duplication_id', function ($dupSub) use ($hasCanView) {
                            $dupSub->select('dup_id_manager')->from('rdp_duplication_section');
                            if ($hasCanView) {
                                $dupSub->where('can_view_file', true);
                            }
                        });
                });
            })->count();

        $query = DB::table((\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data') . ' as document_data')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'document_data.user_office', '=', 'office.office_code')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as account_details', 'document_data.uploaded_by', '=', 'account_details.account_id')
            ->select(
                'document_data.*',
                'office.office_name',
                'account_details.first_name',
                'account_details.last_name'
            );

        if (!$canViewAll) {
            $query->where(function ($q) use ($userOfficeCode, $hasCanView) {
                $q->where('document_data.user_office', $userOfficeCode ?? '---');
                if ($hasCanView && !empty($userOfficeCode)) {
                    $q->orWhereIn('document_data.document_id', function ($sub) use ($userOfficeCode) {
                        $sub->select('r.upload_doc_id_handler')
                            ->from('rdp_record as r')
                            ->join('rdp_duplication_section as d', 'r.duplication_id', '=', 'd.dup_id_manager')
                            ->where('d.office_code', $userOfficeCode)
                            ->where('d.can_view_file', true)
                            ->whereNotNull('r.upload_doc_id_handler');
                    });
                }
            });
        } elseif (!empty($this->selectedOffice)) {
            $query->where('document_data.user_office', $this->selectedOffice);
        }

        if ($this->activeTab === 'dts') {
            $query->where(function ($q) {
                $q->whereIn('document_data.document_id', function ($sub) {
                    $sub->select('document_id')->from('dts_sequence_list');
                })
                ->orWhere('document_data.document_id', 'like', 'DTS%')
                ->orWhere('document_data.document_path', 'like', '%dts%')
                ->orWhere('document_data.document_path', 'like', '%DTS%');
            });
        } elseif ($this->activeTab === 'rdp') {
            $query->where(function ($q) {
                $q->whereIn('document_data.document_id', function ($sub) {
                    $sub->select('upload_doc_id_handler')->from('rdp_record')->whereNotNull('upload_doc_id_handler');
                })
                ->orWhereIn('document_data.document_id', function ($sub) {
                    $sub->select('parent_id')->from('rdp_document_record');
                })
                ->orWhere('document_data.document_id', 'like', 'RDP%')
                ->orWhere('document_data.document_path', 'like', '%rdp%')
                ->orWhere('document_data.document_path', 'like', '%RDP%')
                ->orWhere('document_data.document_path', 'like', '%records%');
            });
        } elseif ($this->activeTab === 'shared') {
            $query->where(function ($q) use ($hasCanView) {
                $q->whereIn('document_data.document_id', function ($sub) use ($hasCanView) {
                    $sub->select('upload_doc_id_handler')
                        ->from('rdp_record')
                        ->whereIn('duplication_id', function ($dupSub) use ($hasCanView) {
                            $dupSub->select('dup_id_manager')->from('rdp_duplication_section');
                            if ($hasCanView) {
                                $dupSub->where('can_view_file', true);
                            }
                        });
                });
            });
        }

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('document_data.document_id', 'like', '%' . $this->search . '%')
                  ->orWhere('document_data.document_name', 'like', '%' . $this->search . '%')
                  ->orWhere('document_data.document_path', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->sortBy === 'office' && $canViewAll) {
            $query->orderBy('office.office_name', $this->sortDirection);
        } elseif ($this->sortBy === 'document_name') {
            $query->orderBy('document_data.document_name', $this->sortDirection);
        } elseif ($this->sortBy === 'date_modified') {
            $query->orderBy('document_data.date_modified', $this->sortDirection);
        } else {
            $query->orderBy('document_data.date_added', $this->sortDirection);
        }

        // Server-Side Database Pagination
        $files = $query->paginate($this->perPage);

        $selectedFile = null;
        if ($this->selectedFileId) {
            $selectedFile = DB::table((\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data') . ' as document_data')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'document_data.user_office', '=', 'office.office_code')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as account_details', 'document_data.uploaded_by', '=', 'account_details.account_id')
                ->select(
                    'document_data.*',
                    'office.office_name',
                    'account_details.first_name',
                    'account_details.last_name'
                )
                ->where('document_data.document_id', $this->selectedFileId)
                ->first();
        }

        $metadataDetails = null;
        if ($this->showMetadataModal && $this->metadataDocId) {
            $docTbl = Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data';
            $offTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
            $accTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

            $doc = DB::table("{$docTbl} as d")
                ->leftJoin("{$offTbl} as o", 'd.user_office', '=', 'o.office_code')
                ->leftJoin("{$accTbl} as a", 'd.uploaded_by', '=', 'a.account_id')
                ->select(
                    'd.*',
                    'o.office_name',
                    'a.first_name',
                    'a.last_name'
                )
                ->where('d.document_id', $this->metadataDocId)
                ->first();

            if ($doc) {
                // RDP Record linkage
                $rdpRecord = null;
                $periodCovered = null;
                $duplicateOffices = [];

                if (Schema::hasTable('rdp_record')) {
                    $rdpRecord = DB::table('rdp_record as r')
                        ->leftJoin('rdp_record_series as s', 'r.record_series_id', '=', 's.id')
                        ->leftJoin('rdp_retention_period as p', 'r.period_id', '=', 'p.id')
                        ->leftJoin('rdp_recorded_value as med', 'r.records_medium', '=', 'med.id')
                        ->leftJoin("{$offTbl} as o_own", 'r.office_own', '=', 'o_own.office_code')
                        ->select(
                            'r.*',
                            's.series_title',
                            'p.active_period',
                            'p.storage_period',
                            'p.total_period',
                            's.remarks as disposition_provision',
                            'med.medium_name',
                            'o_own.office_name as owner_office_name'
                        )
                        ->where(function($q) use ($doc) {
                            $q->where('r.upload_doc_id_handler', $doc->document_id);
                            if (Schema::hasTable('rdp_document_record')) {
                                $q->orWhereIn('r.upload_doc_id_handler', function($sub) use ($doc) {
                                    $sub->select('parent_id')->from('rdp_document_record')->where('parent_id', $doc->document_id);
                                });
                            }
                        })
                        ->first();

                    if ($rdpRecord) {
                        if (Schema::hasTable('rdp_period_covered')) {
                            $periodCovered = DB::table('rdp_period_covered')
                                ->where('period_owner', $rdpRecord->id)
                                ->first();
                        }

                        if (!empty($rdpRecord->duplication_id) && Schema::hasTable('rdp_duplication_section')) {
                            $duplicateOffices = DB::table('rdp_duplication_section as dup')
                                ->leftJoin("{$offTbl} as o", 'dup.office_code', '=', 'o.office_code')
                                ->select('dup.*', 'o.office_name')
                                ->where('dup.dup_id_manager', $rdpRecord->duplication_id)
                                ->get();
                        }
                    }
                }

                // DTS Transaction linkage
                $dtsTransaction = null;
                if (Schema::hasTable('dts_transactions') && Schema::hasTable('dts_transaction_details')) {
                    $dtsQuery = DB::table('dts_transactions as dt')
                        ->leftJoin('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                        ->leftJoin("{$offTbl} as orig_off", 'dtd.originated_from', '=', 'orig_off.office_code')
                        ->leftJoin("{$offTbl} as curr_off", 'dt.current_office', '=', 'curr_off.office_code')
                        ->select(
                            'dt.transaction_id',
                            'dt.status',
                            'dt.doc_dir',
                            'dt.current_office',
                            'dtd.control_number',
                            'dtd.subject',
                            'dtd.originated_from',
                            'orig_off.office_name as originated_office_name',
                            'curr_off.office_name as current_office_name'
                        );

                    if (!empty($doc->document_path)) {
                        $dtsTransaction = (clone $dtsQuery)->where('dt.doc_dir', $doc->document_path)->first();
                        if (!$dtsTransaction) {
                            $dtsTransaction = (clone $dtsQuery)->where('dt.doc_dir', 'like', '%' . basename($doc->document_path))->first();
                        }
                    }
                    if (!$dtsTransaction && Schema::hasTable('dts_document_trans')) {
                        $transId = DB::table('dts_document_trans')->where('document_id', $doc->document_id)->value('dt_name');
                        if ($transId) {
                            $dtsTransaction = (clone $dtsQuery)->where('dt.transaction_id', $transId)->first();
                        }
                    }
                    if (!$dtsTransaction) {
                        $dtsTransaction = (clone $dtsQuery)->where('dt.transaction_id', $doc->document_id)->first();
                    }
                }

                // Formatted file size
                $formattedSize = 'Unknown';
                if (!empty($doc->file_size) && is_numeric($doc->file_size)) {
                    $bytes = (float) $doc->file_size;
                    if ($bytes >= 1048576) {
                        $formattedSize = number_format($bytes / 1048576, 2) . ' MB';
                    } elseif ($bytes >= 1024) {
                        $formattedSize = number_format($bytes / 1024, 2) . ' KB';
                    } else {
                        $formattedSize = $bytes . ' B';
                    }
                }

                $metadataDetails = [
                    'doc' => $doc,
                    'formattedSize' => $formattedSize,
                    'rdpRecord' => $rdpRecord,
                    'periodCovered' => $periodCovered,
                    'duplicateOffices' => $duplicateOffices,
                    'dtsTransaction' => $dtsTransaction,
                    'viewUrl' => !empty($doc->document_path) ? route('rdp.view-document', ['path' => $doc->document_path, 'id' => $doc->document_id]) : null,
                ];
            }
        }

        return [
            'files' => $files,
            'metadataDetails' => $metadataDetails,
            'offices' => $offices,
            'canViewAll' => $canViewAll,
            'userOfficeCode' => $userOfficeCode,
            'selectedFile' => $selectedFile,
            'hasOfficeAccess' => true,
            'stats' => [
                'total' => $totalFilesCount,
                'dts' => $dtsFilesCount,
                'rdp' => $rdpFilesCount,
                'shared' => $sharedFilesCount,
            ]
        ];
    }
};
?>

<div>
@if(isset($hasOfficeAccess) && !$hasOfficeAccess)
<div class="rms-container cockpit-wrapper">
    <div style="background: #ffffff; border: 2px dashed #cbd5e1; border-radius: 16px; padding: 48px 32px; text-align: center; margin: 40px auto; max-width: 620px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);">
        <div style="width: 64px; height: 64px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>
        <h3 style="font-size: 22px; font-weight: 800; color: #0f172a; margin: 0 0 10px 0;">Manage Files Access Restricted</h3>
        <p style="font-size: 14px; color: #64748b; margin: 0 0 24px 0; line-height: 1.6;">
            Your user account is currently not assigned to any office. Access to Manage Files is restricted until an administrator assigns your account to an office.
        </p>
        <div style="display: flex; gap: 12px; justify-content: center;">
            <a href="{{ route('rdp') }}" style="display: inline-flex; align-items: center; gap: 8px; background: #2563eb; color: #ffffff; padding: 12px 22px; border-radius: 10px; font-weight: 700; text-decoration: none; font-size: 14px; transition: all 0.2s;">
                Return to RDP Dashboard
            </a>
        </div>
    </div>
</div>
@else
<div class="rms-container cockpit-wrapper">
    <style>
        .cockpit-wrapper {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #1e293b;
            padding: 24px;
            box-sizing: border-box;
            max-width: 1400px;
            margin: 0 auto;
        }
        .cockpit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
        }
        .cockpit-header h2 {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .cockpit-header h2 svg {
            width: 28px;
            height: 28px;
            fill: #2563eb;
        }

        /* Cockpit Stat Cards Bar */
        .cockpit-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            display: flex;
            align-items: center;
            justify-content: flex-start !important;
            gap: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
        }
        .stat-info .stat-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
        }
        .stat-info .stat-value {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            margin-top: 4px;
        }
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stat-icon.total { background: #eff6ff; color: #2563eb; }
        .stat-icon.dts { background: #f0fdf4; color: #16a34a; }
        .stat-icon.rdp { background: #fff7ed; color: #ea580c; }
        .stat-icon.shared { background: #faf5ff; color: #9333ea; }
        .stat-icon svg { width: 24px; height: 24px; fill: currentColor; }

        /* Top Subsystem Bar */
        .subsystem-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #cbd5e1;
            padding-bottom: 4px;
        }
        .subsystem-tabs {
            display: flex;
            gap: 10px;
        }
        .subsystem-tab-btn {
            padding: 10px 24px;
            font-size: 14px;
            font-weight: 700;
            border-radius: 8px 8px 0 0;
            border: 1px solid transparent;
            border-bottom: none;
            background: #f1f5f9;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .subsystem-tab-btn:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
        .subsystem-tab-btn.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
        }
        .top-office-select {
            min-width: 220px;
            padding: 8px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            background-color: #fff;
            color: #1e293b;
            font-weight: 600;
            outline: none;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        /* Toolbar Controls */
        .files-toolbar {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .files-search {
            flex: 1;
            min-width: 260px;
            position: relative;
        }
        .files-search input {
            width: 100%;
            padding: 10px 14px 10px 36px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
            background: #fff;
        }
        .files-search input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .files-search svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            fill: #94a3b8;
        }
        .sort-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-select {
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            background-color: #fff;
            outline: none;
            cursor: pointer;
        }
        .direction-toggle-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 14px;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            transition: all 0.2s ease;
        }
        .direction-toggle-btn:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }
        .direction-toggle-btn svg {
            width: 16px;
            height: 16px;
            fill: #2563eb;
            transition: transform 0.2s ease;
        }
        .direction-toggle-btn.desc svg {
            transform: rotate(180deg);
        }
        .view-switcher {
            display: flex;
            background: #e2e8f0;
            border-radius: 8px;
            padding: 2px;
        }
        .view-btn {
            padding: 8px 12px;
            border: none;
            background: transparent;
            border-radius: 6px;
            cursor: pointer;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .view-btn.active {
            background: #fff;
            color: #2563eb;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }
        .view-btn svg { width: 18px; height: 18px; fill: currentColor; }

        /* Main Cockpit Layout */
        .cockpit-main-layout {
            display: flex;
            gap: 20px;
            align-items: flex-start;
        }
        .cockpit-content {
            flex: 1;
            min-width: 0;
        }

        /* GRID VIEW STYLING */
        .file-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
        }
        .file-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.02);
        }
        .file-card:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.12);
        }
        .file-card.selected {
            border-color: #2563eb;
            background-color: #f0f6ff;
            box-shadow: 0 0 0 2px #2563eb;
        }
        .file-card-preview {
            height: 100px;
            background: #f8fafc;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            border: 1px dashed #cbd5e1;
        }
        .file-card-preview svg { width: 42px; height: 42px; fill: #64748b; }
        .file-card-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .file-card-meta {
            font-size: 12px;
            color: #64748b;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .office-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            background-color: #eff6ff;
            color: #1e40af;
            border: 1px solid #dbeafe;
        }
        .user-office-banner {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background-color: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        /* LIST VIEW STYLING */
        .files-table-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            overflow: hidden;
        }
        .files-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }
        .files-table th {
            background: #f8fafc;
            padding: 14px 16px;
            font-weight: 600;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
            user-select: none;
        }
        .files-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            vertical-align: middle;
        }
        .files-table tr { cursor: pointer; }
        .files-table tr:hover td { background-color: #f8fafc; }
        .files-table tr.selected td { background-color: #eff6ff; }

        /* SIDE INSPECTOR PANEL */
        .cockpit-inspector {
            width: 320px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.06);
            position: sticky;
            top: 20px;
        }
        .inspector-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f1f5f9;
        }
        .inspector-header h3 {
            font-size: 16px;
            font-weight: 700;
            margin: 0;
            color: #0f172a;
        }
        .close-inspector-btn {
            background: transparent;
            border: none;
            font-size: 18px;
            color: #94a3b8;
            cursor: pointer;
        }
        .close-inspector-btn:hover { color: #0f172a; }
        .inspector-icon-large {
            height: 120px;
            background: #f8fafc;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            border: 1px solid #e2e8f0;
        }
        .inspector-icon-large svg { width: 56px; height: 56px; fill: #2563eb; }
        .inspector-field {
            margin-bottom: 14px;
        }
        .inspector-field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }
        .inspector-field span {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            word-break: break-all;
        }
        .btn-action-block {
            width: 100%;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            background: #2563eb;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            margin-top: 10px;
            transition: background 0.2s;
        }
        .btn-action-block:hover { background: #1d4ed8; }

        /* COCKPIT METADATA MODAL */
        .cockpit-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
        }
        .cockpit-modal-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            width: 740px;
            max-width: 95vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: cockpitModalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes cockpitModalIn {
            from { opacity: 0; transform: scale(0.96) translateY(8px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .cockpit-modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        }
        .cockpit-modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .cockpit-modal-header p {
            margin: 4px 0 0 0;
            font-size: 12.5px;
            color: #64748b;
        }
        .cockpit-modal-close {
            background: transparent;
            border: none;
            font-size: 26px;
            font-weight: 700;
            color: #94a3b8;
            cursor: pointer;
            line-height: 1;
            padding: 4px;
            border-radius: 6px;
            transition: all 0.15s;
        }
        .cockpit-modal-close:hover {
            color: #0f172a;
            background: #f1f5f9;
        }
        .cockpit-modal-body {
            padding: 22px 24px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 18px;
            background: #f8fafc;
        }
        .cockpit-modal-footer {
            padding: 14px 24px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
        }

        /* CUSTOM PAGINATION BAR */
        .cockpit-pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding: 14px 20px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
            flex-wrap: wrap;
            gap: 12px;
        }
        .pagination-summary {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
        }
        .pagination-summary strong {
            color: #0f172a;
        }
        .pagination-controls-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .per-page-select {
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            background: #fff;
            color: #334155;
            outline: none;
            cursor: pointer;
        }
        .empty-state { text-align: center; padding: 48px 24px; color: #64748b; }

        .stat-card.active-all { border-color: #2563eb; background: #f8fafc; }
        .stat-card.active-dts { border-color: #16a34a; background: #f0fdf4; }
        .stat-card.active-rdp { border-color: #ea580c; background: #fff7ed; }
        .stat-card.active-shared { border-color: #9333ea; background: #faf5ff; }

        /* ==========================================================================
           Manage Files (Cockpit) - Dark Mode Overrides
           ========================================================================== */
        [data-theme="dark"] .cockpit-wrapper {
            color: #cbd5e1;
        }

        [data-theme="dark"] .cockpit-header {
            border-bottom-color: #1e293b;
        }

        [data-theme="dark"] .cockpit-header h2 {
            color: #f8fafc;
        }

        [data-theme="dark"] .stat-card {
            background: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
        }

        [data-theme="dark"] .stat-card:hover {
            border-color: #3b82f6 !important;
        }

        [data-theme="dark"] .stat-card.active-all {
            border-color: #3b82f6 !important;
            background: #172554 !important;
            box-shadow: 0 0 0 1px #3b82f6 !important;
        }

        [data-theme="dark"] .stat-card.active-dts {
            border-color: #22c55e !important;
            background: #14532d !important;
            box-shadow: 0 0 0 1px #22c55e !important;
        }

        [data-theme="dark"] .stat-card.active-rdp {
            border-color: #f97316 !important;
            background: #431407 !important;
            box-shadow: 0 0 0 1px #f97316 !important;
        }

        [data-theme="dark"] .stat-card.active-shared {
            border-color: #a855f7 !important;
            background: #3b0764 !important;
            box-shadow: 0 0 0 1px #a855f7 !important;
        }

        [data-theme="dark"] .stat-icon.total {
            background: rgba(37, 99, 235, 0.2) !important;
            color: #60a5fa !important;
        }

        [data-theme="dark"] .stat-icon.dts {
            background: rgba(22, 163, 74, 0.2) !important;
            color: #4ade80 !important;
        }

        [data-theme="dark"] .stat-icon.rdp {
            background: rgba(234, 88, 12, 0.2) !important;
            color: #fb923c !important;
        }

        [data-theme="dark"] .stat-icon.shared {
            background: rgba(147, 51, 234, 0.2) !important;
            color: #c084fc !important;
        }

        [data-theme="dark"] .stat-info .stat-value {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .stat-info .stat-label {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .subsystem-bar {
            border-bottom-color: #1e293b;
        }

        [data-theme="dark"] .subsystem-tab-btn {
            background: #0f172a;
            color: #94a3b8;
        }

        [data-theme="dark"] .subsystem-tab-btn:hover {
            background: #1e293b;
            color: #f8fafc;
        }

        [data-theme="dark"] .subsystem-tab-btn.active {
            background: #2563eb;
            color: #ffffff;
        }

        [data-theme="dark"] .direction-toggle-btn {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .direction-toggle-btn:hover {
            background: #1e293b !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .direction-toggle-btn svg {
            fill: #60a5fa !important;
        }

        [data-theme="dark"] .view-switcher {
            background: #0f172a !important;
            border: 1px solid #1e293b !important;
        }

        [data-theme="dark"] .view-btn {
            color: #64748b !important;
        }

        [data-theme="dark"] .view-btn:hover {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .view-btn.active {
            background: #1e293b !important;
            color: #60a5fa !important;
            box-shadow: 0 2px 6px rgba(0,0,0,0.4) !important;
        }

        [data-theme="dark"] .empty-state {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .empty-state strong {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .files-search input {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .files-search input::placeholder {
            color: #64748b !important;
        }

        [data-theme="dark"] .files-search svg {
            fill: #64748b !important;
        }

        [data-theme="dark"] .top-office-select,
        [data-theme="dark"] .filter-select {
            background-color: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .office-badge {
            background-color: rgba(37, 99, 235, 0.2) !important;
            color: #93c5fd !important;
            border-color: rgba(37, 99, 235, 0.4) !important;
        }

        [data-theme="dark"] .user-office-banner {
            background-color: rgba(22, 163, 74, 0.15) !important;
            color: #86efac !important;
            border-color: rgba(22, 163, 74, 0.3) !important;
        }

        [data-theme="dark"] .file-card {
            background: #131c2e !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .file-card:hover {
            border-color: #3b82f6 !important;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4) !important;
        }

        [data-theme="dark"] .file-card.selected {
            background-color: #1e293b !important;
            border-color: #3b82f6 !important;
        }

        [data-theme="dark"] .file-card-preview {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        [data-theme="dark"] .file-card-title {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .file-card-meta {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .files-table-card {
            background: #131c2e !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .files-table th {
            background: #0f172a !important;
            color: #94a3b8 !important;
            border-bottom-color: #1e293b !important;
        }

        [data-theme="dark"] .files-table td {
            color: #cbd5e1 !important;
            border-bottom-color: #1e293b !important;
        }

        [data-theme="dark"] .files-table tr:hover td {
            background-color: #1a253c !important;
        }

        [data-theme="dark"] .files-table tr.selected td {
            background-color: #1e293b !important;
        }

        [data-theme="dark"] .cockpit-inspector {
            background: #131c2e !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .inspector-header {
            border-bottom-color: #1e293b !important;
        }

        [data-theme="dark"] .inspector-header h3 {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .inspector-icon-large {
            background: #0f172a !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .inspector-field label {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .inspector-field span {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .cockpit-modal-card {
            background: #1e293b;
            border: 1px solid #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .cockpit-modal-header {
            background: #0f172a;
            border-bottom: 1px solid #334155;
        }
        [data-theme="dark"] .cockpit-modal-header h3 {
            color: #f1f5f9;
        }
        [data-theme="dark"] .cockpit-modal-header p {
            color: #94a3b8;
        }
        [data-theme="dark"] .cockpit-modal-close {
            color: #94a3b8;
        }
        [data-theme="dark"] .cockpit-modal-close:hover {
            color: #f1f5f9;
            background: #334155;
        }
        [data-theme="dark"] .cockpit-modal-body {
            background: #0f172a;
        }
        [data-theme="dark"] .cockpit-modal-footer {
            background: #1e293b;
            border-top: 1px solid #334155;
        }
        [data-theme="dark"] .meta-card-section {
            background: #1e293b !important;
            border-color: #334155 !important;
        }
        [data-theme="dark"] .meta-card-section h4 {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .meta-val-text {
            color: #f1f5f9 !important;
        }
        [data-theme="dark"] .meta-path-box {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .cockpit-pagination-bar {
            background: #131c2e !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .pagination-summary {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .pagination-summary strong {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .per-page-select,
        [data-theme="dark"] select,
        [data-theme="dark"] input[type="text"] {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
    </style>

    <!-- Cockpit Title Header -->
    <div class="cockpit-header">
        <h2>
            <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zM7 10h2v7H7zm4-3h2v10h-2zm4 6h2v4h-2z"/></svg>
            RMS File Manager
        </h2>
        @if(!$canViewAll && $userOfficeCode)
            <div class="user-office-banner">
                <span>Restricted to your office: <strong>{{ $userOfficeCode }}</strong></span>
            </div>
        @endif
    </div>

    <!-- Cockpit Quick Metrics Bar -->
    <div class="cockpit-stats-grid">
        <div class="stat-card {{ $activeTab === 'all' ? 'active-all' : '' }}" wire:click="setTab('all')" style="cursor: pointer;">
            <div class="stat-info">
                <div class="stat-label">Total Registry Files</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
            <div class="stat-icon total">
                <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
            </div>
        </div>

        <div class="stat-card {{ $activeTab === 'dts' ? 'active-dts' : '' }}" wire:click="setTab('dts')" style="cursor: pointer;">
            <div class="stat-info">
                <div class="stat-label">DTS Tracking Files</div>
                <div class="stat-value">{{ number_format($stats['dts']) }}</div>
            </div>
            <div class="stat-icon dts">
                <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg>
            </div>
        </div>

        <div class="stat-card {{ $activeTab === 'rdp' ? 'active-rdp' : '' }}" wire:click="setTab('rdp')" style="cursor: pointer;">
            <div class="stat-info">
                <div class="stat-label">RDP Inventory Files</div>
                <div class="stat-value">{{ number_format($stats['rdp']) }}</div>
            </div>
            <div class="stat-icon rdp">
                <svg viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg>
            </div>
        </div>

        <div class="stat-card {{ $activeTab === 'shared' ? 'active-shared' : '' }}" wire:click="setTab('shared')" style="cursor: pointer;">
            <div class="stat-info">
                <div class="stat-label">Shared & Duplicated</div>
                <div class="stat-value">{{ number_format($stats['shared']) }}</div>
            </div>
            <div class="stat-icon shared">
                <svg viewBox="0 0 24 24"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"/></svg>
            </div>
        </div>
    </div>

    <!-- Subsystem Navigation Tabs & Top Office Selector -->
    <div class="subsystem-bar">
        <div class="subsystem-tabs">
            <button type="button" 
                    class="subsystem-tab-btn {{ $activeTab === 'all' ? 'active' : '' }}" 
                    wire:click="setTab('all')">
                All Files
            </button>

            <button type="button" 
                    class="subsystem-tab-btn {{ $activeTab === 'dts' ? 'active' : '' }}" 
                    wire:click="setTab('dts')">
                DTS
            </button>

            <button type="button" 
                    class="subsystem-tab-btn {{ $activeTab === 'rdp' ? 'active' : '' }}" 
                    wire:click="setTab('rdp')">
                RDP
            </button>

            <button type="button" 
                    class="subsystem-tab-btn {{ $activeTab === 'shared' ? 'active' : '' }}" 
                    wire:click="setTab('shared')">
                Shared
            </button>
        </div>

        @if($canViewAll)
            <div>
                <select class="top-office-select" wire:model.live="selectedOffice">
                    <option value="">All Offices</option>
                    @foreach($offices as $office)
                        <option value="{{ $office->office_code }}">{{ $office->office_name }} ({{ $office->office_code }})</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    <!-- Toolbar Controls (Search + Attribute Sorting + Direction + View Switcher) -->
    <div class="files-toolbar">
        <div class="files-search">
            <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search by Document ID or Name...">
        </div>

        <div class="sort-controls">
            <!-- Sort Attribute Selector -->
            <select class="filter-select" wire:model.live="sortBy">
                <option value="date_added">Sort by Date Added</option>
                <option value="date_modified">Sort by Date Modified</option>
                <option value="document_name">Sort Alphabetically (A-Z)</option>
                @if($canViewAll)
                    <option value="office">Sort by Office Name</option>
                @endif
            </select>

            <!-- Direction Toggle Button -->
            <button type="button" 
                    class="direction-toggle-btn {{ $sortDirection === 'desc' ? 'desc' : 'asc' }}" 
                    wire:click="toggleDirection" 
                    title="Toggle Direction ({{ strtoupper($sortDirection) }})">
                <svg viewBox="0 0 24 24">
                    <path d="M12 4l-8 8h6v8h4v-8h6z"/>
                </svg>
                <span>{{ strtoupper($sortDirection) }}</span>
            </button>

            <!-- Grid vs List View Switcher -->
            <div class="view-switcher">
                <button type="button" 
                        class="view-btn {{ $viewMode === 'grid' ? 'active' : '' }}" 
                        wire:click="setViewMode('grid')"
                        title="Grid View">
                    <svg viewBox="0 0 24 24"><path d="M4 11h5V5H4v6zm0 7h5v-6H4v6zm6 0h5v-6h-5v6zm6 0h5v-6h-5v6zm-6-7h5V5h-5v6zm6-6v6h5V5h-5z"/></svg>
                </button>
                <button type="button" 
                        class="view-btn {{ $viewMode === 'list' ? 'active' : '' }}" 
                        wire:click="setViewMode('list')"
                        title="List View">
                    <svg viewBox="0 0 24 24"><path d="M4 14h4v-4H4v4zm0 5h4v-4H4v4zM4 9h4V5H4v4zm5 5h12v-4H9v4zm0 5h12v-4H9v4zM9 5v4h12V5H9z"/></svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Main Content & Cockpit Inspector Drawer Layout -->
    <div class="cockpit-main-layout">
        <div class="cockpit-content">
            @if($viewMode === 'grid')
                <!-- GRID VIEW -->
                <div class="file-cards-grid">
                    @forelse($files as $file)
                        <div class="file-card {{ $selectedFileId === $file->document_id ? 'selected' : '' }}"
                             wire:click="selectFile('{{ $file->document_id }}')">
                            <div class="file-card-preview">
                                <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                            </div>
                            <div class="file-card-title" title="{{ $file->document_name }}">
                                {{ $file->document_name }}
                            </div>
                            <div class="file-card-meta">
                                <span>{{ \App\Helpers\RegisterQueryHelper::formatSmartDate($file->date_added) }}</span>
                                @if($file->office_name)
                                    <span class="office-badge">{{ $file->office_name }}</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div style="grid-column: 1 / -1;" class="empty-state">
                            No files found matching the criteria in <strong>{{ strtoupper($activeTab) }}</strong>.
                        </div>
                    @endforelse
                </div>
            @else
                <!-- LIST VIEW -->
                <div class="files-table-card">
                    <table class="files-table">
                        <thead>
                            <tr>
                                <th>Document ID</th>
                                <th>Document Name</th>
                                <th>Office Files</th>
                                <th>Uploaded By</th>
                                <th>Date Added</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($files as $file)
                                <tr class="{{ $selectedFileId === $file->document_id ? 'selected' : '' }}"
                                    wire:click="selectFile('{{ $file->document_id }}')">
                                    <td style="font-weight: 600; font-family: monospace;">{{ $file->document_id }}</td>
                                    <td>{{ $file->document_name }}</td>
                                    <td>
                                        @if($file->office_name)
                                            <span class="office-badge">{{ $file->office_name }}</span>
                                        @else
                                            <span style="color: #94a3b8; font-style: italic;">Unassigned</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($file->first_name || $file->last_name)
                                            {{ $file->first_name }} {{ $file->last_name }}
                                        @else
                                            <span style="color: #94a3b8; font-style: italic;">System</span>
                                        @endif
                                    </td>
                                    <td>{{ \Carbon\Carbon::parse($file->date_added)->format('M d, Y h:i A') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            No files found in <strong>{{ strtoupper($activeTab) }}</strong>.
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif

            <!-- FAST SERVER-SIDE PAGINATION BAR -->
            @if($files->hasPages() || $files->total() > 0)
                <div class="cockpit-pagination-bar">
                    <div class="pagination-summary">
                        Showing <strong>{{ $files->firstItem() ?? 0 }}</strong> to <strong>{{ $files->lastItem() ?? 0 }}</strong> of <strong>{{ number_format($files->total()) }}</strong> items
                    </div>

                    <div class="pagination-controls-right">
                        <select class="per-page-select" wire:model.live="perPage">
                            <option value="12">12 per page</option>
                            <option value="24">24 per page</option>
                            <option value="48">48 per page</option>
                            <option value="96">96 per page</option>
                        </select>

                        <div>
                            {{ $files->links() }}
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <!-- COCKPIT FILE INSPECTOR (SIDE DRAWER) -->
        @if($selectedFile)
            <div class="cockpit-inspector">
                <div class="inspector-header">
                    <h3>File Details</h3>
                    <button type="button" class="close-inspector-btn" wire:click="closeInspector">&times;</button>
                </div>

                <div class="inspector-icon-large">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                </div>

                <div class="inspector-field">
                    <label>Document ID</label>
                    <span style="font-family: monospace;">{{ $selectedFile->document_id }}</span>
                </div>

                <div class="inspector-field">
                    <label>Document Name</label>
                    <span>{{ $selectedFile->document_name }}</span>
                </div>

                <div class="inspector-field">
                    <label>Subsystem Classification</label>
                    <span class="office-badge" style="background: #eff6ff; color: #2563eb;">{{ strtoupper($activeTab) }}</span>
                </div>

                <div class="inspector-field">
                    <label>Office Assignment</label>
                    <span>{{ $selectedFile->office_name ?? 'Unassigned' }}</span>
                </div>

                <div class="inspector-field">
                    <label>Uploaded By</label>
                    <span>
                        @if($selectedFile->first_name || $selectedFile->last_name)
                            {{ $selectedFile->first_name }} {{ $selectedFile->last_name }}
                        @else
                            System Admin
                        @endif
                    </span>
                </div>

                <div class="inspector-field">
                    <label>Date Added</label>
                    <span>{{ \Carbon\Carbon::parse($selectedFile->date_added)->format('M d, Y h:i:s A') }}</span>
                </div>

                <div class="inspector-field">
                    <label>Server Path</label>
                    <span style="font-size: 11px; color: #64748b;">{{ $selectedFile->document_path ?? 'Local Registry' }}</span>
                </div>

                <button type="button" 
                        class="btn-action-block" 
                        wire:click="openMetadataModal('{{ $selectedFile->document_id }}')"
                        style="display: flex; align-items: center; justify-content: center; gap: 6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span>View Metadata</span>
                </button>

                @if(!empty($selectedFile->document_path))
                    <a href="{{ route('rdp.view-document', ['path' => $selectedFile->document_path, 'id' => $selectedFile->document_id]) }}" 
                       target="_blank" 
                       class="btn-action-block" 
                       style="display: flex; align-items: center; justify-content: center; gap: 6px; background: #059669; text-decoration: none; margin-top: 8px;"
                       onmouseover="this.style.background='#047857'"
                       onmouseout="this.style.background='#059669'">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <span>Open Document</span>
                    </a>
                @endif
            </div>
        @endif
    </div>

    <!-- ═══════ Cockpit Document Metadata Modal ═══════ -->
    @if($showMetadataModal && $metadataDetails)
        @php
            $mDoc = $metadataDetails['doc'];
            $mRdp = $metadataDetails['rdpRecord'];
            $mPeriod = $metadataDetails['periodCovered'];
            $mDups = $metadataDetails['duplicateOffices'] ?? [];
            $mDts = $metadataDetails['dtsTransaction'];
            $fileExt = strtolower(pathinfo($mDoc->document_name ?? '', PATHINFO_EXTENSION));
        @endphp
        <div class="cockpit-modal-overlay" wire:click.self="closeMetadataModal">
            <div class="cockpit-modal-card">
                <!-- Modal Header -->
                <div class="cockpit-modal-header">
                    <div>
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                <polyline points="10 9 9 9 8 9"></polyline>
                            </svg>
                            Document Metadata & System Linkage
                        </h3>
                        <p>Detailed technical attributes, storage paths, and associated subsystem records.</p>
                    </div>
                    <button type="button" wire:click="closeMetadataModal" class="cockpit-modal-close" title="Close Metadata">&times;</button>
                </div>

                <!-- Modal Body -->
                <div class="cockpit-modal-body">
                    <!-- Top File Summary Card -->
                    <div class="meta-card-section" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 14px;">
                            <div style="width: 46px; height: 46px; border-radius: 10px; background: #eff6ff; border: 1px solid #bfdbfe; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <span style="font-size: 12.5px; font-weight: 800; color: #1d4ed8; text-transform: uppercase;">{{ $fileExt ?: 'FILE' }}</span>
                            </div>
                            <div>
                                <div class="meta-val-text" style="font-size: 15px; font-weight: 700; color: #0f172a; word-break: break-all;">
                                    {{ $mDoc->document_name }}
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap;">
                                    <span style="font-family: monospace; font-size: 11.5px; font-weight: 600; background: #f1f5f9; color: #334155; padding: 2px 7px; border-radius: 4px; border: 1px solid #cbd5e1;">
                                        {{ $mDoc->document_id }}
                                    </span>
                                    <span class="office-badge" style="font-size: 11px;">
                                        {{ $mDoc->office_name ?? ($mDoc->user_office ?? 'Unassigned Office') }}
                                    </span>
                                    @if($mDoc->is_active)
                                        <span style="font-size: 11px; font-weight: 700; color: #15803d; background: #dcfce7; border: 1px solid #86efac; padding: 2px 7px; border-radius: 4px;">
                                            ACTIVE
                                        </span>
                                    @else
                                        <span style="font-size: 11px; font-weight: 700; color: #64748b; background: #f1f5f9; border: 1px solid #cbd5e1; padding: 2px 7px; border-radius: 4px;">
                                            ARCHIVED
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if(!empty($metadataDetails['viewUrl']))
                            <a href="{{ $metadataDetails['viewUrl'] }}" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; background: #2563eb; color: #ffffff; text-decoration: none; padding: 8px 16px; border-radius: 8px; font-size: 12.5px; font-weight: 700; transition: background 0.15s; white-space: nowrap;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <span>Open Document</span>
                            </a>
                        @endif
                    </div>

                    <!-- Section 1: Storage & Technical Attributes Grid -->
                    <div class="meta-card-section" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px;">
                        <h4 style="margin: 0 0 14px 0; font-size: 12.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; display: flex; align-items: center; gap: 6px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line></svg>
                            File Storage Attributes
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
                            <div>
                                <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">File Size</div>
                                <div class="meta-val-text" style="font-size: 13.5px; font-weight: 600; color: #0f172a;">{{ $metadataDetails['formattedSize'] }}</div>
                            </div>
                            <div>
                                <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">MIME / File Type</div>
                                <div class="meta-val-text" style="font-size: 13.5px; font-weight: 600; color: #0f172a;">{{ $mDoc->file_type ?? ($fileExt ? strtoupper($fileExt) : 'Unknown') }}</div>
                            </div>
                            <div>
                                <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Uploaded By</div>
                                <div class="meta-val-text" style="font-size: 13.5px; font-weight: 600; color: #0f172a;">
                                    {{ ($mDoc->first_name || $mDoc->last_name) ? trim($mDoc->first_name . ' ' . $mDoc->last_name) : 'System Administrator' }}
                                </div>
                            </div>
                            <div>
                                <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Date Added</div>
                                <div class="meta-val-text" style="font-size: 13.5px; font-weight: 600; color: #0f172a;">
                                    {{ \Carbon\Carbon::parse($mDoc->date_added)->format('M d, Y h:i:s A') }}
                                </div>
                            </div>
                            <div>
                                <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Date Modified</div>
                                <div class="meta-val-text" style="font-size: 13.5px; font-weight: 600; color: #0f172a;">
                                    {{ !empty($mDoc->date_modified) ? \Carbon\Carbon::parse($mDoc->date_modified)->format('M d, Y h:i:s A') : 'None' }}
                                </div>
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Server Storage Path</div>
                                <div class="meta-path-box" style="font-family: monospace; font-size: 12px; font-weight: 600; color: #334155; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 10px; word-break: break-all;">
                                    {{ $mDoc->document_path ?? 'Local Registry System' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Subsystem Linkages -->
                    @if($mRdp)
                        <!-- RDP Record Details -->
                        <div class="meta-card-section" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;">
                                <h4 style="margin: 0; font-size: 12.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #059669; display: flex; align-items: center; gap: 6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                                    Linked RDP Record Details
                                </h4>
                                <span style="font-size: 11px; font-weight: 700; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 2px 8px; border-radius: 9999px;">
                                    {{ $mRdp->is_verified ? 'Verified Record' : ($mRdp->is_drafted ? 'Draft Record' : 'Active Record') }}
                                </span>
                            </div>

                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
                                <div style="grid-column: 1 / -1;">
                                    <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Record Series Title</div>
                                    <div class="meta-val-text" style="font-size: 14px; font-weight: 700; color: #0f172a;">{{ $mRdp->series_title ?? 'Untitled Series' }}</div>
                                </div>
                                @if(!empty($mRdp->description))
                                    <div style="grid-column: 1 / -1;">
                                        <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Record Description / Subject</div>
                                        <div class="meta-path-box" style="font-size: 13px; color: #334155; line-height: 1.5; background: #f8fafc; border: 1px solid #f1f5f9; padding: 8px 12px; border-radius: 6px;">{{ $mRdp->description }}</div>
                                    </div>
                                @endif
                                <div>
                                    <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Period Covered</div>
                                    <div class="meta-val-text" style="font-size: 13px; font-weight: 600; color: #0f172a;">
                                        @if($mPeriod)
                                            {{ $mPeriod->date_covered ? \Carbon\Carbon::parse($mPeriod->date_covered)->format('M d, Y') : 'N/A' }}
                                            @if(!empty($mPeriod->date_covered_end))
                                                — {{ \Carbon\Carbon::parse($mPeriod->date_covered_end)->format('M d, Y') }}
                                            @endif
                                        @else
                                            N/A
                                        @endif
                                    </div>
                                </div>
                                <div>
                                    <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Volume</div>
                                    <div class="meta-val-text" style="font-size: 13px; font-weight: 600; color: #0f172a;">{{ $mRdp->volume ?: ($mPeriod->volume ?? 'N/A') }}</div>
                                </div>
                                <div>
                                    <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Records Medium</div>
                                    <div class="meta-val-text" style="font-size: 13px; font-weight: 600; color: #0f172a;">{{ $mRdp->medium_name ?? 'Hardcopy' }}</div>
                                </div>
                                <div>
                                    <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Physical Location</div>
                                    <div class="meta-val-text" style="font-size: 13px; font-weight: 600; color: #0f172a;">{{ $mRdp->records_location ?? 'Unspecified' }}</div>
                                </div>
                                <div>
                                    <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Restriction</div>
                                    <div class="meta-val-text" style="font-size: 13px; font-weight: 600; color: #0f172a;">{{ $mRdp->restriction ?? 'Restricted' }}</div>
                                </div>
                                <div>
                                    <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Frequency of Use</div>
                                    <div class="meta-val-text" style="font-size: 13px; font-weight: 600; color: #0f172a;">{{ $mRdp->frequence_use ?? 'N/A' }}</div>
                                </div>
                                <div>
                                    <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Retention (Active / Storage)</div>
                                    <div class="meta-val-text" style="font-size: 13px; font-weight: 600; color: #0f172a;">
                                        {{ $mRdp->active_period ?: '0' }} Active / {{ $mRdp->storage_period ?: '0' }} Storage
                                    </div>
                                </div>
                                @if(!empty($mRdp->disposition_provision))
                                    <div style="grid-column: 1 / -1;">
                                        <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Disposition Provision / Remarks</div>
                                        <div style="font-size: 12.5px; color: #475569; font-style: italic;">{{ $mRdp->disposition_provision }}</div>
                                    </div>
                                @endif

                                <!-- Duplicate Offices & File Visibility -->
                                @if(count($mDups) > 0)
                                    <div style="grid-column: 1 / -1; margin-top: 6px; padding-top: 10px; border-top: 1px dashed #e2e8f0;">
                                        <div class="meta-field-label" style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 8px;">
                                            Duplicate Copy Holders & File Viewing Access ({{ count($mDups) }})
                                        </div>
                                        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                            @foreach($mDups as $dup)
                                                @php
                                                    $canView = !empty($dup->can_view_file);
                                                @endphp
                                                <div style="display: inline-flex; align-items: center; gap: 8px; background: #ffffff; border: 1.5px solid {{ $canView ? '#86efac' : '#cbd5e1' }}; padding: 4px 10px; border-radius: 8px; font-size: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                                                    <span><strong>{{ $dup->office_code }}</strong> — {{ $dup->office_name ?? $dup->office_code }}</span>
                                                    <span style="font-size: 10.5px; font-weight: 700; padding: 2px 6px; border-radius: 9999px; {{ $canView ? 'background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;' : 'background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1;' }}">
                                                        {{ $canView ? '✓ View File: ON' : '✕ View File: OFF' }}
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if($mDts)
                        <!-- DTS Transaction Details -->
                        <div class="meta-card-section" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;">
                                <h4 style="margin: 0; font-size: 12.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #2563eb; display: flex; align-items: center; gap: 6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                                    Linked Document Tracking System (DTS)
                                </h4>
                                <span style="font-size: 11px; font-weight: 700; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px;">
                                    {{ strtoupper($mDts->status ?? 'ACTIVE') }}
                                </span>
                            </div>

                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
                                <div>
                                    <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Transaction ID</div>
                                    <div class="meta-val-text" style="font-family: monospace; font-size: 13.5px; font-weight: 700; color: #2563eb;">{{ $mDts->transaction_id }}</div>
                                </div>
                                <div>
                                    <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Control Number</div>
                                    <div class="meta-val-text" style="font-size: 13px; font-weight: 600; color: #0f172a;">{{ $mDts->control_number ?? 'N/A' }}</div>
                                </div>
                                <div>
                                    <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Originated From</div>
                                    <div class="meta-val-text" style="font-size: 13px; font-weight: 600; color: #0f172a;">{{ $mDts->originated_office_name ?? ($mDts->originated_from ?? 'N/A') }}</div>
                                </div>
                                <div>
                                    <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Current Custody</div>
                                    <div class="meta-val-text" style="font-size: 13px; font-weight: 600; color: #0f172a;">{{ $mDts->current_office_name ?? ($mDts->current_office ?? 'N/A') }}</div>
                                </div>
                                @if(!empty($mDts->subject))
                                    <div style="grid-column: 1 / -1;">
                                        <div class="meta-field-label" style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 3px;">Particulars / Subject</div>
                                        <div class="meta-path-box" style="font-size: 13px; color: #334155; line-height: 1.5; background: #f8fafc; border: 1px solid #f1f5f9; padding: 8px 12px; border-radius: 6px;">{{ $mDts->subject }}</div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if(!$mRdp && !$mDts)
                        <div style="background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 18px 20px; text-align: center; color: #64748b; font-size: 13px;">
                            This document is stored as a direct system upload with no associated RDP appraisal schedule or active DTS transaction.
                        </div>
                    @endif
                </div>

                <!-- Modal Footer -->
                <div class="cockpit-modal-footer">
                    <div>
                        @if(!empty($metadataDetails['viewUrl']))
                            <a href="{{ $metadataDetails['viewUrl'] }}" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; background: #f8fafc; color: #1e293b; border: 1.5px solid #cbd5e1; text-decoration: none; padding: 8px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 700; transition: all 0.15s;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <span>Open in New Tab</span>
                            </a>
                        @endif
                    </div>
                    <button type="button" wire:click="closeMetadataModal" class="btn-action-block" style="width: auto; padding: 8px 20px; margin: 0; font-size: 13px;">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>
@endif
</div>
