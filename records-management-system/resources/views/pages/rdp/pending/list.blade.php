<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Pending List')] class extends Component {
    public string $search = '';
    public string $activeTab = 'all'; // 'all', 'nap1', 'nap2', 'nap3'
    public string $statusFilter = '';
    public string $layoutMode = 'table'; // 'table' or 'box'

    // Detail Modal Properties
    public bool $showDetailModal = false;
    public ?object $selectedCluster = null;
    public array $clusterItems = [];

    // Print Modal & Fill-up Properties
    public bool $showPrintModal = false;
    public ?object $printCluster = null;
    public array $printItems = [];
    public string $agencyName = 'Camarines Sur Polytechnic Colleges';
    public string $departmentDivision = 'Office of the President';
    public string $sectionUnit = 'Records Management Unit';
    public string $telephoneNumber = '(054) 288-1534 loc. 113';
    public string $emailAddress = 'records@cspc.edu.ph';
    public string $agencyAddress = 'San Miguel, Nabua, Camarines Sur';
    public string $personInCharge = 'Gennica Aprille S. Penetrante';
    public string $datePrepared = '';

    // Signature Block Fields
    public string $preparedBy = 'Gennica Aprille S. Penetrante';
    public string $preparedPosition = 'Administrative Officer V / Records Officer';
    public string $assistedBy = '';
    public string $assistedPosition = 'NAP Records Management Analyst';
    public string $approvedBy = 'Dr. Luningning Q. Bregala';
    public string $approvedPosition = 'Chief of Division / Department Head';

    public function mount(): void
    {
        $perms = Auth::user()?->permissions;
        if (!$perms || (!(bool)($perms->is_sadm ?? false) && !(bool)($perms->can_access_rdp ?? true))) {
            redirect()->route('rdp')->send();
            return;
        }

        $details = Auth::user()?->details;
        if ($details) {
            $fullName = trim(($details->first_name ?? '') . ' ' . ($details->last_name ?? ''));
            $this->preparedBy = $fullName ?: (Auth::user()->username ?? 'Records Officer');
        }
    }

    public function toggleLayout(): void
    {
        $this->layoutMode = $this->layoutMode === 'table' ? 'box' : 'table';
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->search = '';
        $this->statusFilter = '';
    }

    public function openDetailModal(int $clusterId, string $formType): void
    {
        $cluster = null;
        $items = [];

        if ($formType === 'nap2' || $formType === 'NAP Form 2') {
            $cluster = DB::table('rdp_pending_record_series')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record_series.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin('office', 'rdp_pending_record_series.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record_series.created_by', '=', 'account_details.account_id')
                ->where('rdp_pending_record_series.cluster_id', $clusterId)
                ->select([
                    'rdp_pending_record_series.cluster_id',
                    'rdp_pending_record_series.cluster_name',
                    'rdp_pending_record_series.status_id',
                    'rdp_pending_record_series.office',
                    'rdp_pending_record_series.created_at',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("'NAP Form 2' as form_label"),
                    DB::raw("'nap2' as form_code")
                ])
                ->first();

            if ($cluster) {
                $items = DB::table('rdp_grouped_record_series')
                    ->join('rdp_record_series', 'rdp_grouped_record_series.record_series_id', '=', 'rdp_record_series.id')
                    ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
                    ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
                    ->where('rdp_grouped_record_series.group_head', $clusterId)
                    ->select([
                        'rdp_record_series.*',
                        'rdp_retention_period.active_period',
                        'rdp_retention_period.storage_period',
                        'rdp_retention_period.total_period',
                        'parent.series_title as parent_title'
                    ])
                    ->get()
                    ->toArray();
            }
        } else {
            $cluster = DB::table('rdp_pending_record')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin('office', 'rdp_pending_record.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record.created_by', '=', 'account_details.account_id')
                ->where('rdp_pending_record.cluster_id', $clusterId)
                ->select([
                    'rdp_pending_record.cluster_id',
                    'rdp_pending_record.cluster_name',
                    'rdp_pending_record.status_id',
                    'rdp_pending_record.office',
                    'rdp_pending_record.is_for_nap_one',
                    'rdp_pending_record.is_for_nap_three',
                    'rdp_pending_record.created_at',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'NAP Form 3' ELSE 'NAP Form 1' END as form_label"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'nap3' ELSE 'nap1' END as form_code")
                ])
                ->first();

            if ($cluster) {
                $items = DB::table('rdp_grouped_record')
                    ->join('rdp_record', 'rdp_grouped_record.record_id', '=', 'rdp_record.id')
                    ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
                    ->leftJoin('rdp_period_covered', 'rdp_record.id', '=', 'rdp_period_covered.period_owner')
                    ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
                    ->leftJoin('rdp_recorded_value', 'rdp_record.records_medium', '=', 'rdp_recorded_value.id')
                    ->leftJoin('rdp_utility_medium', 'rdp_record.utility_value', '=', 'rdp_utility_medium.id')
                    ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
                    ->where('rdp_grouped_record.group_head', $clusterId)
                    ->select([
                        'rdp_record.*',
                        'rdp_record_series.series_title',
                        'rdp_record_series.item_number',
                        'rdp_retention_period.active_period',
                        'rdp_retention_period.storage_period',
                        'rdp_retention_period.total_period',
                        'rdp_period_covered.start_at',
                        'rdp_period_covered.ends_at',
                        'rdp_recorded_value.medium_name',
                        'rdp_utility_medium.utility_name',
                        'parent.series_title as parent_title'
                    ])
                    ->get()
                    ->toArray();
            }
        }

        if ($cluster) {
            $this->selectedCluster = $cluster;
            $this->clusterItems = $items;
            $this->showDetailModal = true;
        }
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedCluster = null;
        $this->clusterItems = [];
    }

    public function openPrintModal(int $clusterId, string $formType): void
    {
        $this->openDetailModal($clusterId, $formType);
        $this->printCluster = $this->selectedCluster;
        $this->printItems = $this->clusterItems;

        if ($this->printCluster) {
            if (!empty($this->printCluster->office_name)) {
                $this->agencyName = $this->printCluster->office_name;
            }
            if (!empty($this->printCluster->submitter_name)) {
                $this->personInCharge = $this->printCluster->submitter_name;
                $this->preparedBy = $this->printCluster->submitter_name;
            }
            $this->datePrepared = \Carbon\Carbon::parse($this->printCluster->created_at ?? now())->format('m/d/Y');
        }

        $this->showPrintModal = true;
    }

    public function closePrintModal(): void
    {
        $this->showPrintModal = false;
        $this->printCluster = null;
        $this->printItems = [];
    }

    public function with(): array
    {
        $statuses = DB::table('rdp_pending_status')->where('is_active', true)->get();
        $clustersCollection = collect();

        $user = Auth::user();
        $perms = $user?->permissions;
        $canViewAll = (bool)($perms?->is_sadm ?? false)
            || (bool)($perms?->is_rdp_view_all_pending_list ?? false)
            || (bool)($perms?->can_access_rdp_admin ?? false)
            || (bool)($perms?->rdp_view_all_files ?? false);
        $userOffice = $user?->details?->office_code ?? null;

        // 1. Fetch NAP Form 2 Series Clusters if tab is 'all' or 'nap2'
        if ($this->activeTab === 'all' || $this->activeTab === 'nap2') {
            $qSeries = DB::table('main_pending_id')
                ->join('rdp_pending_record_series', 'main_pending_id.id', '=', 'rdp_pending_record_series.cluster_id')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record_series.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin('office', 'rdp_pending_record_series.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record_series.created_by', '=', 'account_details.account_id')
                ->select([
                    'main_pending_id.id as main_id',
                    'rdp_pending_record_series.cluster_id',
                    'rdp_pending_record_series.cluster_name',
                    'rdp_pending_record_series.status_id',
                    'rdp_pending_record_series.office',
                    'rdp_pending_record_series.created_at',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("'NAP Form 2' as form_label"),
                    DB::raw("'nap2' as form_code"),
                    DB::raw("(SELECT COUNT(*) FROM rdp_grouped_record_series WHERE group_head = rdp_pending_record_series.cluster_id) as total_items")
                ]);

            if (!$canViewAll && $userOffice) {
                $qSeries->where('rdp_pending_record_series.office', $userOffice);
            }

            if (!empty($this->statusFilter)) {
                $qSeries->where('rdp_pending_record_series.status_id', $this->statusFilter);
            }

            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $qSeries->where(function($q) use ($term) {
                    $q->where('rdp_pending_record_series.cluster_name', 'ILIKE', $term)
                      ->orWhere('office.office_name', 'ILIKE', $term);
                });
            }

            $clustersCollection = $clustersCollection->concat($qSeries->get());
        }

        // 2. Fetch Pending Record Clusters (NAP Form 1 or 3) if tab is 'all', 'nap1', or 'nap3'
        if ($this->activeTab === 'all' || $this->activeTab === 'nap1' || $this->activeTab === 'nap3') {
            $qRec = DB::table('main_pending_id')
                ->join('rdp_pending_record', 'main_pending_id.id', '=', 'rdp_pending_record.cluster_id')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin('office', 'rdp_pending_record.office', '=', 'office.office_code')
                ->leftJoin('account_details', 'rdp_pending_record.created_by', '=', 'account_details.account_id')
                ->select([
                    'main_pending_id.id as main_id',
                    'rdp_pending_record.cluster_id',
                    'rdp_pending_record.cluster_name',
                    'rdp_pending_record.status_id',
                    'rdp_pending_record.office',
                    'rdp_pending_record.is_for_nap_one',
                    'rdp_pending_record.is_for_nap_three',
                    'rdp_pending_record.created_at',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'NAP Form 3' ELSE 'NAP Form 1' END as form_label"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'nap3' ELSE 'nap1' END as form_code"),
                    DB::raw("(SELECT COUNT(*) FROM rdp_grouped_record WHERE group_head = rdp_pending_record.cluster_id) as total_items")
                ]);

            if (!$canViewAll && $userOffice) {
                $qRec->where('rdp_pending_record.office', $userOffice);
            }

            if ($this->activeTab === 'nap1') {
                $qRec->where('rdp_pending_record.is_for_nap_one', true);
            } elseif ($this->activeTab === 'nap3') {
                $qRec->where('rdp_pending_record.is_for_nap_three', true);
            }

            if (!empty($this->statusFilter)) {
                $qRec->where('rdp_pending_record.status_id', $this->statusFilter);
            }

            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $qRec->where(function($q) use ($term) {
                    $q->where('rdp_pending_record.cluster_name', 'ILIKE', $term)
                      ->orWhere('office.office_name', 'ILIKE', $term);
                });
            }

            $clustersCollection = $clustersCollection->concat($qRec->get());
        }

        // Sort by main_id descending
        $sortedClusters = $clustersCollection->sortByDesc('main_id')->values();

        return [
            'clusters' => $sortedClusters,
            'statuses' => $statuses,
        ];
    }
}; ?>

<div class="pending-list-page">
    <style>
        .pending-list-page {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .header-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .header-title h1 {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 4px 0;
        }
        .header-title p {
            font-size: 14px;
            color: #64748b;
            margin: 0;
        }
        .controls-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        /* DTS-Styled Tabs Bar */
        .dts-tab-bar {
            display: flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 10px;
            gap: 4px;
            border: 1px solid #e2e8f0;
        }
        .dts-tab-btn {
            border: none;
            background: transparent;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .dts-tab-btn.active {
            background: #ffffff;
            color: #2563eb;
            box-shadow: 0 2px 4px rgba(37,99,235,0.1);
        }

        .layout-toggle-btn {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
        }
        .layout-toggle-btn:hover { background: #f8fafc; }

        .search-input, .select-filter {
            padding: 9px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }
        .search-input { width: 240px; }

        /* Table & Card Grid Views */
        .pending-table-wrapper {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .pending-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }
        .pending-table th {
            background: #1e293b;
            color: #ffffff;
            font-weight: 600;
            padding: 14px 16px;
            border-right: 1px solid #334155;
        }
        .pending-table td {
            padding: 14px 16px;
            color: #334155;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #f1f5f9;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-null, .status-1 { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; } /* Pending */
        .status-2 { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; } /* Approved */
        .status-3 { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; } /* Rejected */
        .status-4 { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; } /* Returned */

        .form-type-pill {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 6px;
        }

        .btn-view {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 4px;
        }
        .btn-view:hover { background: #dbeafe; }

        .btn-print {
            background: #1e293b;
            color: #ffffff;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-print:hover { background: #0f172a; }

        /* Box / Card Grid Layout */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        .cluster-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .cluster-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px -2px rgba(0,0,0,0.08);
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .card-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 4px 0 0 0;
        }
        .card-meta {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 16px;
            line-height: 1.5;
        }
        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
        }

        /* Modal Layout */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 999;
        }
        .modal-card {
            background: #ffffff;
            border-radius: 12px;
            width: 800px;
            max-width: 94vw;
            max-height: 88vh;
            overflow-y: auto;
            padding: 26px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
        }

        /* ==========================================================================
           Pending List - Dark Mode Overrides
           ========================================================================== */
        [data-theme="dark"] .header-card {
            background: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
        }

        [data-theme="dark"] .header-title h1 {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .header-title p {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .dts-tab-bar {
            background: #0f172a !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .dts-tab-btn {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .dts-tab-btn.active {
            background: #131c2e !important;
            color: #60a5fa !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.4) !important;
        }

        [data-theme="dark"] .layout-toggle-btn {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .search-input,
        [data-theme="dark"] .select-filter {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .pending-table-wrapper {
            background: #131c2e !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .pending-table th {
            background: #0f172a !important;
            color: #94a3b8 !important;
            border-right-color: #1e293b !important;
            border-bottom: 2px solid #1e293b !important;
        }

        [data-theme="dark"] .pending-table td {
            color: #cbd5e1 !important;
            border-bottom-color: #1e293b !important;
            border-right-color: #1e293b !important;
        }

        [data-theme="dark"] .pending-table tr:hover td {
            background-color: #1a253c !important;
        }

        [data-theme="dark"] .cluster-card {
            background: #131c2e !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .card-title {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .card-meta {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .card-footer {
            border-top-color: #1e293b !important;
        }

        [data-theme="dark"] .btn-view {
            background: #0f172a !important;
            color: #60a5fa !important;
            border-color: #3b82f6 !important;
        }

        [data-theme="dark"] .btn-view:hover {
            background: #1e293b !important;
        }

        [data-theme="dark"] .modal-card {
            background: #131c2e !important;
            border: 1px solid #1e293b !important;
            color: #cbd5e1 !important;
        }

        /* Print Modal & Media Query Defaults */
        @media print {
            @page {
                size: portrait;
                margin: 10px;
            }

            :root {
                zoom: 1 !important;
            }

            html, body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                overflow: visible !important;
                position: static !important;
                font-family: Arial, sans-serif !important;
                font-size: 12px !important;
            }

            /* Hide web application layout components (header, sidebar, chatify, etc.) */
            header,
            nav,
            .navigation,
            #navigation,
            .chatify-floating-widget,
            .header-card,
            .controls-group,
            .pending-table-wrapper,
            .card-grid,
            .no-print,
            .modal-card > *:not(.printable-report-area) {
                display: none !important;
            }

            section,
            .article-container,
            #article-container,
            .pending-list-page {
                display: block !important;
                position: static !important;
                float: none !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                overflow: visible !important;
            }

            .modal-overlay {
                position: static !important;
                display: block !important;
                float: none !important;
                width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
            }

            .modal-card {
                position: static !important;
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
                max-height: none !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                border: none !important;
                box-shadow: none !important;
            }

            .printable-report-area {
                display: block !important;
                visibility: visible !important;
                position: static !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 10px !important;
                box-sizing: border-box !important;
                border: none !important;
                background: #ffffff !important;
                box-shadow: none !important;
                font-family: Arial, sans-serif !important;
                font-size: 12px !important;
            }

            .printable-report-area * {
                visibility: visible !important;
            }

            .printable-report-area table {
                font-size: 12px !important;
                width: 100% !important;
            }

            .printable-report-area thead {
                display: table-header-group !important;
            }

            .printable-report-area tr {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            .nap-form-2-print,
            .nap-form-2-print *,
            .nap-form-3-print,
            .nap-form-3-print * {
                font-family: Arial, sans-serif !important;
                font-size: 12px !important;
            }

            .nap-form-2-print table,
            .nap-form-3-print table {
                font-size: 12px !important;
            }

            .nap-form-2-print th, .nap-form-2-print td,
            .nap-form-3-print th, .nap-form-3-print td {
                font-size: 12px !important;
                padding: 6px 8px !important;
            }

            .print-signatures-block {
                page-break-before: always !important;
                break-before: page !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                display: block !important;
            }
        }
    </style>

    <div class="header-card">
        <div class="header-title">
            <h1>Pending Records & Series List</h1>
            <p>Track office submissions awaiting evaluation and approval for the Records Disposition Program</p>
        </div>
        <div class="controls-group">
            <div class="dts-tab-bar">
                <button wire:click="setTab('all')" class="dts-tab-btn {{ $activeTab === 'all' ? 'active' : '' }}">ALL</button>
                <button wire:click="setTab('nap1')" class="dts-tab-btn {{ $activeTab === 'nap1' ? 'active' : '' }}">NAP Form 1</button>
                <button wire:click="setTab('nap2')" class="dts-tab-btn {{ $activeTab === 'nap2' ? 'active' : '' }}">NAP Form 2</button>
                <button wire:click="setTab('nap3')" class="dts-tab-btn {{ $activeTab === 'nap3' ? 'active' : '' }}">NAP Form 3</button>
            </div>

            <button wire:click="toggleLayout" class="layout-toggle-btn">
                @if($layoutMode === 'table')
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    Box View
                @else
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                    Table View
                @endif
            </button>

            <input type="text" wire:model.live.debounce.250ms="search" class="search-input" placeholder="Search cluster or office...">
        </div>
    </div>

    @if($layoutMode === 'table')
        <div class="pending-table-wrapper">
            <table class="pending-table">
                <thead>
                    <tr>
                        <th style="width: 90px;">Main ID</th>
                        <th>Cluster Name</th>
                        <th>Submitting Office</th>
                        <th>Submitted By</th>
                        <th style="width: 110px; text-align: center;">Total Items</th>
                        <th style="width: 140px;">Date Submitted</th>
                        <th style="width: 160px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clusters as $c)
                        <tr>
                            <td><strong>#{{ $c->main_id }}</strong></td>
                            <td>
                                <span class="form-type-pill">{{ $c->form_label }}</span>
                                <strong>{{ $c->cluster_name }}</strong>
                            </td>
                            <td>{{ $c->office_name ?? $c->office ?? 'N/A' }}</td>
                            <td>{{ $c->submitter_name ?: 'System User' }}</td>
                            <td style="text-align: center;"><strong>{{ $c->total_items }}</strong></td>
                            <td>{{ \Carbon\Carbon::parse($c->created_at)->format('M d, Y g:i A') }}</td>
                            <td style="text-align: center;">
                                <button wire:click="openDetailModal({{ $c->cluster_id }}, '{{ $c->form_code }}')" class="btn-view">View</button>
                                <button wire:click="openPrintModal({{ $c->cluster_id }}, '{{ $c->form_code }}')" class="btn-print">Print</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 48px; color: #64748b;">
                                No pending clusters found matching your query.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="card-grid">
            @forelse($clusters as $c)
                <div class="cluster-card">
                    <div>
                        <div class="card-header">
                            <div>
                                <span class="form-type-pill">{{ $c->form_label }}</span>
                                <span style="font-size: 12px; font-weight: 700; color: #64748b;">#{{ $c->main_id }}</span>
                            </div>
                        </div>
                        <h3 class="card-title">{{ $c->cluster_name }}</h3>
                        <div class="card-meta">
                            <div><strong>Office:</strong> {{ $c->office_name ?? $c->office ?? 'N/A' }}</div>
                            <div><strong>Submitted by:</strong> {{ $c->submitter_name ?: 'System User' }}</div>
                            <div><strong>Items:</strong> {{ $c->total_items }} records</div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <span style="font-size: 12px; color: #94a3b8;">{{ \Carbon\Carbon::parse($c->created_at)->format('M d, Y') }}</span>
                        <div>
                            <button wire:click="openDetailModal({{ $c->cluster_id }}, '{{ $c->form_code }}')" class="btn-view">View</button>
                            <button wire:click="openPrintModal({{ $c->cluster_id }}, '{{ $c->form_code }}')" class="btn-print">Print</button>
                        </div>
                    </div>
                </div>
            @empty
                <div style="grid-column: 1 / -1; text-align: center; padding: 48px; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; color: #64748b;">
                    No pending clusters found matching your query.
                </div>
            @endforelse
        </div>
    @endif

    {{-- Detail Modal --}}
    @if($showDetailModal && $selectedCluster)
        <div class="modal-overlay">
            <div class="modal-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <div>
                        <h3 style="font-size: 18px; font-weight: 700; color: #0f172a; margin: 0;">
                            [{{ $selectedCluster->form_label }}] {{ $selectedCluster->cluster_name }}
                        </h3>
                        <span style="font-size: 13px; color: #64748b;">Submitted by {{ $selectedCluster->office_name ?? $selectedCluster->office }}</span>
                    </div>
                    <button wire:click="closeDetailModal" style="background: none; border: none; font-size: 20px; color: #64748b; cursor: pointer;">&times;</button>
                </div>
                <div style="margin-bottom: 16px;">
                    <span class="status-badge status-{{ $selectedCluster->status_id ?? 'null' }}">
                        Status: {{ $selectedCluster->status_name ?? 'Draft Group' }}
                    </span>
                </div>
                <table class="pending-table" style="font-size: 13px;">
                    <thead>
                        <tr>
                            <th>Item / Title</th>
                            <th>Details</th>
                            <th>Retention / Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($clusterItems as $item)
                            <tr>
                                <td>
                                    <strong>{{ $item->series_title ?? $item->doc_name ?? 'Untitled Item' }}</strong>
                                    @if(!empty($item->parent_title))
                                        <div style="font-size: 11px; color: #64748b;">Sub-series of: {{ $item->parent_title }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($item->item_number))
                                        <div>Item No: <strong>{{ sprintf('%03d', $item->item_number) }}</strong></div>
                                    @endif
                                    @if(isset($item->volume))
                                        <div>Volume: {{ $item->volume }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($item->total_period))
                                        <div>Retention: <strong>{{ $item->total_period }}</strong></div>
                                    @endif
                                    <div style="color: #64748b;">{{ $item->remarks ?? $item->description ?? '—' }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Printable Modal --}}
    @if($showPrintModal && $printCluster)
        @php
            $formCode = strtolower($printCluster->form_code ?? '');
            $formLabel = strtolower($printCluster->form_label ?? '');
            $isNap1 = $formCode === 'nap1' || str_contains($formLabel, 'form 1');
        @endphp
        <style>
            @media print {
                @page {
                    size: {{ $isNap1 ? '13in 8.5in landscape' : '8.5in 13in portrait' }};
                    margin: 10px !important;
                }
            }
        </style>
        <div class="modal-overlay" style="overflow-y: auto;">
            <div class="modal-card" style="width: 1050px; max-width: 95%; max-height: 90vh; overflow-y: auto; padding: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;" class="no-print">
                    <div>
                        <h3 style="font-size: 18px; font-weight: 700; color: #0f172a; margin: 0;">Print Preview — {{ $printCluster->cluster_name }}</h3>
                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #64748b;">Configure header details (Fields 1-8) and signatures before printing.</p>
                    </div>
                    <div>
                        <button onclick="window.print()" class="btn-print" style="padding: 8px 18px; margin-right: 8px; font-weight: 700; background: #16a34a; color: #fff; border: none; border-radius: 6px; cursor: pointer;">🖨️ Print Now</button>
                        <button wire:click="closePrintModal" class="btn-view" style="padding: 8px 16px;">Close</button>
                    </div>
                </div>

                @if(strtolower($printCluster->form_code ?? '') === 'nap1' || str_contains(strtolower($printCluster->form_label ?? ''), 'form 1'))
                    <!-- FILL-UP FORM PANEL (Fields 1 to 8 + Signatures) - NO-PRINT -->
                    <div class="no-print" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 18px; margin-bottom: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.04);">
                        <h4 style="margin: 0 0 14px 0; font-size: 13px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                            📋 Top Header Configuration (Fields 1 – 8)
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 12px;">
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">1. NAME OF OFFICE</label>
                                <input type="text" wire:model.live="agencyName" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">2. DEPARTMENT / DIVISION</label>
                                <input type="text" wire:model.live="departmentDivision" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">3. SECTION / UNIT</label>
                                <input type="text" wire:model.live="sectionUnit" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">4. TELEPHONE NO.</label>
                                <input type="text" wire:model.live="telephoneNumber" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px;">
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">5. EMAIL ADDRESS</label>
                                <input type="text" wire:model.live="emailAddress" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">6. ADDRESS</label>
                                <input type="text" wire:model.live="agencyAddress" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">7. PERSON-IN-CHARGE OF FILES</label>
                                <input type="text" wire:model.live="personInCharge" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">8. DATE PREPARED</label>
                                <input type="text" wire:model.live="datePrepared" class="form-control" placeholder="e.g. 08/03/2026" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                        </div>

                        <h4 style="margin: 14px 0 12px 0; font-size: 13px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                            ✍️ Official Signatures Block
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">PREPARED BY (Name & Position)</label>
                                <input type="text" wire:model.live="preparedBy" class="form-control" placeholder="Name" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px;">
                                <input type="text" wire:model.live="preparedPosition" class="form-control" placeholder="Position" style="width: 100%; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px; background: #ffffff;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">ASSISTED BY (NAP Analyst)</label>
                                <input type="text" wire:model.live="assistedBy" class="form-control" placeholder="Name" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px;">
                                <input type="text" wire:model.live="assistedPosition" class="form-control" placeholder="Position" style="width: 100%; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px; background: #ffffff;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">APPROVED BY (Chief/Department Head)</label>
                                <input type="text" wire:model.live="approvedBy" class="form-control" placeholder="Name" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px;">
                                <input type="text" wire:model.live="approvedPosition" class="form-control" placeholder="Position" style="width: 100%; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px; background: #ffffff;">
                            </div>
                        </div>
                    </div>
                @endif

                <div class="printable-report-area" style="background: #ffffff; padding: 10px; font-family: Arial, sans-serif; font-size: 12px; color: #000000;">
                    @if(strtolower($printCluster->form_code ?? '') === 'nap1' || str_contains(strtolower($printCluster->form_label ?? ''), 'form 1'))
                        <!-- OFFICIAL 2024 NAP FORM 1: RECORDS INVENTORY AND APPRAISAL -->
                        <div style="font-size: 9px; margin-bottom: 2px; font-weight: bold;">NAP Records Inventory and Appraisal Form</div>
                        <div style="font-size: 9px; margin-bottom: 6px; font-weight: bold;">2024</div>

                        <!-- TOP HEADER GRID BOX (Fields 1 to 8) -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; border-bottom: none; font-size: 8.5px; text-align: left; table-layout: fixed;">
                            <tr>
                                <td rowspan="3" style="width: 27.5%; border: 1px solid #000; text-align: center; vertical-align: middle; padding: 4px;">
                                    <div style="border: 1.5px solid #000; padding: 10px 6px; margin: 2px;">
                                        <div style="font-weight: bold; font-size: 10.5px; font-family: Arial, sans-serif; text-align: center;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                                        <div style="font-style: italic; font-size: 9px; margin: 3px 0 10px 0; font-family: Arial, sans-serif; text-align: center;">Pambansang Sinupan ng Pilipinas</div>
                                        <div style="font-weight: bold; font-size: 10.5px; font-family: Arial, sans-serif; text-align: center;">RECORDS INVENTORY AND APPRAISAL</div>
                                    </div>
                                </td>
                                <td rowspan="2" style="width: 27%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>1. NAME OF OFFICE:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $agencyName }}</div>
                                </td>
                                <td style="width: 17.5%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>2. DEPARTMENT/DIVISION:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $departmentDivision }}</div>
                                </td>
                                <td style="width: 28%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>4. TELEPHONE NO.:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $telephoneNumber }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>3. SECTION/UNIT:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $sectionUnit }}</div>
                                </td>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>5. EMAIL ADDRESS.:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $emailAddress }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>6. ADDRESS:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $agencyAddress }}</div>
                                </td>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>7. PERSON-IN-CHARGE OF FILES:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $personInCharge }}</div>
                                </td>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>8. DATE PREPARED:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $datePrepared ?: \Carbon\Carbon::parse($printCluster->created_at)->format('m/d/Y') }}</div>
                                </td>
                            </tr>
                        </table>

                        <!-- MAIN DATA TABLE (Columns 9 to 20) -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 8px; text-align: center; table-layout: fixed;">
                            <thead>
                                <tr style="font-weight: bold;">
                                    <th rowspan="2" style="border: 1px solid #000; width: 15%; padding: 4px; text-align: left;">9. RECORDS SERIES TITLE AND DESCRIPTION</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 8%; padding: 4px;">10. PERIOD COVERED / INCLUSIVE DATES</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 4.5%; padding: 4px;">11. VOLUME</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px;">12. RECORDS MEDIUM</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px;">13. RESTRICTION/S</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 7.5%; padding: 4px;">14. LOCATION OF RECORDS</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px;">15. FREQUENCY OF USE</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 5.5%; padding: 4px;">16. DUPLICATION</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 5%; padding: 4px;">17. TIME VALUE (T/P)</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px;">18. UTILITY VALUE Adm/F/L/Arc</th>
                                    <th colspan="3" style="border: 1px solid #000; width: 11%; padding: 4px;">19. RETENTION PERIOD</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 11.5%; padding: 4px;">20. DISPOSITION PROVISION</th>
                                </tr>
                                <tr style="font-weight: bold;">
                                    <th style="border: 1px solid #000; width: 3.6%; padding: 3px;">Active</th>
                                    <th style="border: 1px solid #000; width: 3.6%; padding: 3px;">Storage</th>
                                    <th style="border: 1px solid #000; width: 3.8%; padding: 3px;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($printItems as $idx => $pi)
                                    @php
                                        $isPerm = (bool)($pi->effective_is_permanent ?? false) || strtolower(trim($pi->total_period ?? '')) === 'permanent';
                                        $borderBottom = $loop->last ? 'border-bottom: 2px solid #000;' : 'border-bottom: none;';
                                        $cellStyle = "border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; {$borderBottom}";
                                    @endphp
                                    <tr>
                                        <td style="{{ $cellStyle }} text-align: left; padding: 4px 5px; vertical-align: top;">
                                            <div style="font-weight: bold;">{{ $pi->series_title ?? $pi->doc_name ?? 'Untitled' }}</div>
                                            @if(!empty($pi->description))
                                                <div style="font-style: italic; font-size: 7.5px; color: #333; margin-top: 1px;">{{ $pi->description }}</div>
                                            @endif
                                        </td>
                                        <td style="{{ $cellStyle }} padding: 4px; vertical-align: top;">
                                            {{ $pi->period_covered ?? (!empty($pi->start_at ?? null) ? (\Carbon\Carbon::parse($pi->start_at)->format('Y') . (!empty($pi->ends_at ?? null) ? ' - ' . \Carbon\Carbon::parse($pi->ends_at)->format('Y') : '')) : '') }}
                                        </td>
                                        <td style="{{ $cellStyle }} padding: 4px; vertical-align: top;">{{ $pi->volume ?? '' }}</td>
                                        <td style="{{ $cellStyle }} padding: 4px; vertical-align: top;">{{ $pi->records_medium ?? $pi->medium_name ?? '' }}</td>
                                        <td style="{{ $cellStyle }} padding: 4px; vertical-align: top;">{{ $pi->restriction ?? '' }}</td>
                                        <td style="{{ $cellStyle }} padding: 4px; vertical-align: top;">{{ $pi->records_location ?? '' }}</td>
                                        <td style="{{ $cellStyle }} padding: 4px; vertical-align: top;">{{ $pi->frequence_use ?? '' }}</td>
                                        <td style="{{ $cellStyle }} padding: 4px; vertical-align: top;">{{ $pi->duplication ?? '' }}</td>
                                        <td style="{{ $cellStyle }} padding: 4px; vertical-align: top; font-weight: bold;">{{ $pi->time_value ?? '' }}</td>
                                        <td style="{{ $cellStyle }} padding: 4px; vertical-align: top; font-weight: bold;">{{ $pi->utility_value ?? $pi->utility_name ?? '' }}</td>
                                        
                                        @if($isPerm)
                                            <td colspan="3" style="{{ $cellStyle }} padding: 4px; vertical-align: top; font-weight: bold; color: #dc2626;">PERMANENT</td>
                                        @else
                                            <td style="{{ $cellStyle }} padding: 4px; vertical-align: top;">{{ $pi->active_period ?? '' }}</td>
                                            <td style="{{ $cellStyle }} padding: 4px; vertical-align: top;">{{ $pi->storage_period ?? '' }}</td>
                                            <td style="{{ $cellStyle }} padding: 4px; vertical-align: top; font-weight: bold;">{{ $pi->total_period ?? '' }}</td>
                                        @endif

                                        <td style="{{ $cellStyle }} padding: 4px; vertical-align: top;">{{ $pi->disposition_provision ?? $pi->remarks ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        <!-- FOOTER LEGEND SECTION -->
                        <div style="font-size: 8.5px; font-weight: bold; margin-top: 10px; margin-bottom: 16px;">
                            <div>LEGEND:</div>
                            <div style="display: flex; gap: 60px; margin-top: 4px; font-size: 8px;">
                                <div>
                                    <span style="display: inline-block; width: 90px;">TIME VALUE:</span>
                                    <strong>T</strong> - Temporary &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <strong>P</strong> - Permanent
                                </div>
                                <div>
                                    <span style="display: inline-block; width: 90px;">UTILITY VALUE:</span>
                                    <strong>Adm</strong> - Administrative &nbsp;&nbsp;&nbsp; <strong>F</strong> - Fiscal &nbsp;&nbsp;&nbsp; <strong>L</strong> - Legal &nbsp;&nbsp;&nbsp; <strong>Arc</strong> - Archival
                                </div>
                            </div>
                        </div>

                        <!-- SIGNATURES BLOCK -->
                        <div style="display: flex; justify-content: space-between; margin-top: 30px; font-size: 8.5px; text-transform: uppercase;">
                            <div style="width: 30%;">
                                <div style="font-weight: bold; margin-bottom: 35px;">PREPARED BY:</div>
                                <div style="border-bottom: 1.5px solid #000; width: 90%; text-align: center; font-weight: bold; font-size: 9.5px; padding-bottom: 2px;">
                                    {{ $preparedBy }}
                                </div>
                                <div style="text-align: center; font-size: 8px; margin-top: 3px; width: 90%; text-transform: none;">{{ $preparedPosition ?: 'Name and Position' }}</div>
                            </div>

                            <div style="width: 35%;">
                                <div style="font-weight: bold; margin-bottom: 35px;">ASSISTED BY:</div>
                                <div style="border-bottom: 1.5px solid #000; width: 90%; text-align: center; font-weight: bold; font-size: 9.5px; padding-bottom: 2px;">
                                    {{ $assistedBy ?: ' ' }}
                                </div>
                                <div style="text-align: center; font-size: 8px; margin-top: 3px; width: 90%; text-transform: none;">{{ $assistedPosition ?: 'NAP Records Management Analyst' }}</div>
                            </div>

                            <div style="width: 30%;">
                                <div style="font-weight: bold; margin-bottom: 35px;">APPROVED BY:</div>
                                <div style="border-bottom: 1.5px solid #000; width: 90%; text-align: center; font-weight: bold; font-size: 9.5px; padding-bottom: 2px;">
                                    {{ $approvedBy }}
                                </div>
                                <div style="text-align: center; font-size: 8px; margin-top: 3px; width: 90%; text-transform: none;">{{ $approvedPosition ?: 'Chief of the Division/Department' }}</div>
                            </div>
                        </div>

                        <!-- BOTTOM PAGE NUMBER -->
                        <div style="text-align: right; font-size: 8px; margin-top: 20px;">
                            Page ___ of ___ Pages
                        </div>
                    @elseif(strtolower($printCluster->form_code ?? '') === 'nap2' || str_contains(strtolower($printCluster->form_label ?? ''), 'form 2'))
                        <div class="nap-form-2-print" style="font-family: Arial, sans-serif; font-size: 12px; padding: 10px; color: #000000;">
                        <!-- OFFICIAL NAP FORM 2: RECORDS DISPOSITION SCHEDULE -->
                        <div style="font-size: 9px; margin-bottom: 2px; font-weight: bold;">NAP Form 2</div>
                        <div style="font-size: 9px; margin-bottom: 6px; font-weight: bold;">2008</div>

                        <!-- PAGE 1 TOP HEADER GRID BOX -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 8.5px; text-align: left; table-layout: fixed;">
                            <tr>
                                <td rowspan="2" style="width: 48%; border: 1px solid #000; text-align: center; vertical-align: middle; padding: 4px;">
                                    <div style="border: 1.5px solid #000; padding: 8px 6px; margin: 2px;">
                                        <div style="font-weight: bold; font-size: 10.5px;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                                        <div style="font-style: italic; font-size: 9px; margin: 2px 0 6px 0;">Pambansang Sinupan ng Pilipinas</div>
                                        <div style="font-weight: bold; font-size: 10.5px;">RECORDS DISPOSITION SCHEDULE</div>
                                    </div>
                                </td>
                                <td style="width: 52%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>1. AGENCY NAME:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $agencyName }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>2. ADDRESS:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $agencyAddress }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>3. SCHEDULE NO.:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $printCluster->cluster_id ?? '' }}</div>
                                </td>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>4. DATE PREPARED:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $datePrepared ?: \Carbon\Carbon::parse($printCluster->created_at)->format('m/d/Y') }}</div>
                                </td>
                            </tr>
                        </table>

                        <!-- PAGE 1 MAIN DATA TABLE -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; border-top: none; font-size: 8.5px; text-align: center; table-layout: fixed;">
                            <thead>
                                <tr style="font-weight: bold;">
                                    <th rowspan="2" style="border: 1px solid #000; width: 11%; padding: 4px;">5. ITEM NO.:</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 39%; padding: 4px; text-align: left;">6. RECORD SERIES TITLE AND DESCRIPTION</th>
                                    <th colspan="3" style="border: 1px solid #000; width: 35%; padding: 4px;">7. RETENTION PERIOD</th>
                                    <th rowspan="2" style="border: 1px solid #000; width: 15%; padding: 4px;">8. REMARKS</th>
                                </tr>
                                <tr style="font-weight: bold;">
                                    <th style="border: 1px solid #000; width: 11.5%; padding: 3px;">Active</th>
                                    <th style="border: 1px solid #000; width: 11.5%; padding: 3px;">Storage</th>
                                    <th style="border: 1px solid #000; width: 12%; padding: 3px;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($printItems as $idx => $pi)
                                    @php
                                        $isPerm = (bool)($pi->effective_is_permanent ?? false) || strtolower(trim($pi->total_period ?? '')) === 'permanent';
                                        $borderBottom = $loop->last ? 'border-bottom: 2px solid #000;' : 'border-bottom: none;';
                                        $cellStyle = "border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; {$borderBottom}";
                                    @endphp
                                    <tr>
                                        <td style="{{ $cellStyle }} padding: 4px;">{{ $pi->item_number ?? ($idx + 1) }}</td>
                                        <td style="{{ $cellStyle }} text-align: left; padding: 4px 6px;">
                                            <div style="font-weight: bold;">{{ $pi->series_title ?? $pi->doc_name ?? 'Untitled' }}</div>
                                            @if(!empty($pi->description))
                                                <div style="font-style: italic; font-size: 8px; color: #333; margin-top: 1px;">{{ $pi->description }}</div>
                                            @endif
                                        </td>
                                        @if($isPerm)
                                            <td colspan="3" style="{{ $cellStyle }} padding: 4px; font-weight: bold; color: #dc2626;">PERMANENT</td>
                                        @else
                                            <td style="{{ $cellStyle }} padding: 4px;">{{ $pi->active_period ?? '' }}</td>
                                            <td style="{{ $cellStyle }} padding: 4px;">{{ $pi->storage_period ?? '' }}</td>
                                            <td style="{{ $cellStyle }} padding: 4px; font-weight: bold;">{{ $pi->total_period ?? '' }}</td>
                                        @endif
                                        <td style="{{ $cellStyle }} padding: 4px;">{{ $pi->remarks ?? $pi->disposition_provision ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        <!-- PAGE 1 BOTTOM NOTICE -->
                        <div style="font-size: 8px; margin-top: 12px; margin-bottom: 12px; line-height: 1.4;">
                            <strong>IMPORTANT:</strong> Pursuant to Section 18, Article III, RA 9470 s. 2007, "No government department, bureau, agency and instrumentality shall dispose of, destroy or authorize the disposal or destruction of any public records, which are in the custody or under its control except with the prior written authority of the executive director."
                        </div>
                        <!-- PAGE 2 SIGNATURES & APPROVAL SECTION (Always clean final page) -->
                        <div class="print-signatures-block" style="page-break-before: always; break-before: page; page-break-inside: avoid; break-inside: avoid; padding-top: 12px;">
                            <div style="font-size: 9px; margin-bottom: 4px; font-weight: bold;">NAP Form 2</div>
                            <div style="font-size: 9px; margin-bottom: 8px; font-weight: bold;">2008</div>

                            <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 8.5px;">
                                <tr>
                                    <td style="width: 50%; border: 1px solid #000; padding: 12px; vertical-align: top;">
                                        <strong>9. Prepared by:</strong>
                                        <div style="margin-top: 35px; text-align: center; border-bottom: 1px solid #000; width: 80%; margin-left: auto; margin-right: auto; font-weight: bold; font-size: 9.5px;">
                                            {{ $preparedBy }}
                                        </div>
                                        <div style="text-align: center; font-size: 8px; margin-top: 2px;">Name</div>
                                        <div style="margin-top: 15px; text-align: center; border-bottom: 1px solid #000; width: 80%; margin-left: auto; margin-right: auto; font-size: 8.5px;">
                                            {{ $preparedPosition }}
                                        </div>
                                        <div style="text-align: center; font-size: 8px; margin-top: 2px;">Position</div>
                                    </td>
                                    <td style="width: 50%; border: 1px solid #000; padding: 12px; vertical-align: top;">
                                        <strong>11. Recommending Approval:</strong>
                                        <div style="margin-top: 35px; text-align: center; border-bottom: 1px solid #000; width: 80%; margin-left: auto; margin-right: auto; font-weight: bold; font-size: 9.5px;">
                                            {{ $assistedBy }}
                                        </div>
                                        <div style="text-align: center; font-size: 8px; margin-top: 2px;">Name</div>
                                        <div style="margin-top: 15px; text-align: center; border-bottom: 1px solid #000; width: 80%; margin-left: auto; margin-right: auto; font-size: 8.5px;">
                                            {{ $assistedPosition }}
                                        </div>
                                        <div style="text-align: center; font-size: 8px; margin-top: 2px;">Position</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #000; padding: 12px; vertical-align: top;">
                                        <strong>10. Assisted by:</strong>
                                        <div style="margin-top: 35px; text-align: center; border-bottom: 1px solid #000; width: 80%; margin-left: auto; margin-right: auto; font-weight: bold; font-size: 9.5px;">
                                            
                                        </div>
                                        <div style="text-align: center; font-size: 8px; margin-top: 2px;">Name</div>
                                        <div style="margin-top: 15px; text-align: center; border-bottom: 1px solid #000; width: 80%; margin-left: auto; margin-right: auto; font-size: 8.5px;">
                                            
                                        </div>
                                        <div style="text-align: center; font-size: 8px; margin-top: 2px;">Position</div>
                                    </td>
                                    <td style="border: 1px solid #000; padding: 12px; vertical-align: top;">
                                        <strong>12. Approved:</strong>
                                        <div style="margin-top: 35px; text-align: center; border-bottom: 1px solid #000; width: 80%; margin-left: auto; margin-right: auto; font-weight: bold; font-size: 9.5px;">
                                            {{ $approvedBy }}
                                        </div>
                                        <div style="text-align: center; font-size: 8px; margin-top: 2px;">Name</div>
                                        <div style="margin-top: 15px; text-align: center; border-bottom: 1px solid #000; width: 80%; margin-left: auto; margin-right: auto; font-size: 8.5px;">
                                            {{ $approvedPosition }}
                                        </div>
                                        <div style="text-align: center; font-size: 8px; margin-top: 2px;">Position</div>
                                    </td>
                                </tr>
                            </table>

                            <div style="border: 2px solid #000; border-top: none; padding: 12px;">
                                <div style="text-align: center; font-weight: bold; font-size: 9px; text-transform: uppercase; border-bottom: 1.5px solid #000; padding-bottom: 4px; margin-bottom: 14px;">
                                    TO BE ACCOMPLISHED BY THE NATIONAL ARCHIVES OF THE PHILIPPINES
                                </div>
                                <div style="font-size: 8.5px; margin-bottom: 12px;">This Records Disposition Schedule</div>
                                <div style="font-size: 8.5px; margin-left: 20px; margin-bottom: 6px;">[ &nbsp; ] is being returned for improvement / correction</div>
                                <div style="font-size: 8.5px; margin-left: 20px; margin-bottom: 25px;">[ &nbsp; ] is being recommended for approval</div>

                                <div style="display: flex; justify-content: space-between; font-size: 8.5px;">
                                    <div style="width: 40%; text-align: center;">
                                        <div style="border-bottom: 1px solid #000; font-weight: bold; padding-bottom: 2px;">Chairman</div>
                                        <div style="font-size: 8px; margin-top: 2px;">Records Management Evaluation Committee</div>
                                        <div style="margin-top: 15px; border-bottom: 1px solid #000; width: 60%; margin-left: auto; margin-right: auto;"></div>
                                        <div style="font-size: 8px; margin-top: 2px;">Date</div>
                                    </div>

                                    <div style="width: 40%; text-align: center;">
                                        <div style="font-weight: bold; margin-bottom: 10px;">APPROVED:</div>
                                        <div style="border-bottom: 1px solid #000; font-weight: bold; padding-bottom: 2px; margin-top: 15px;">Executive Director</div>
                                        <div style="font-size: 8px; margin-top: 2px;">Executive Director</div>
                                        <div style="margin-top: 15px; border-bottom: 1px solid #000; width: 60%; margin-left: auto; margin-right: auto;"></div>
                                        <div style="font-size: 8px; margin-top: 2px;">Date</div>
                                    </div>
                                </div>
                            </div>
                            <div style="text-align: right; font-size: 8px; font-weight: bold; margin-top: 16px;">Page 2 of 2 Pages</div>
                        </div>

                    @else
                        <div class="nap-form-3-print" style="font-family: Arial, sans-serif; font-size: 12px; padding: 10px; color: #000000;">
                        <!-- OFFICIAL NAP FORM 3: REQUEST FOR AUTHORITY TO DISPOSE OF RECORDS -->
                        <div style="display: flex; justify-content: space-between; font-size: 9px; font-weight: bold; margin-bottom: 6px;">
                            <div>
                                <div>NAP Form No. 3</div>
                                <div>Revised 2012</div>
                            </div>
                            <div>Accomplish in 3 copies</div>
                        </div>

                        <!-- TOP HEADER GRID BOX -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 8.5px; text-align: left; table-layout: fixed;">
                            <tr>
                                <td rowspan="2" style="width: 48%; border: 1px solid #000; text-align: center; vertical-align: middle; padding: 4px;">
                                    <div style="border: 1.5px solid #000; padding: 8px 6px; margin: 2px;">
                                        <div style="font-weight: bold; font-size: 10.5px;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                                        <div style="font-style: italic; font-size: 9px; margin: 2px 0 6px 0;">Pambansang Sinupan ng Pilipinas</div>
                                        <div style="font-weight: bold; font-size: 10.5px;">REQUEST FOR AUTHORITY TO DISPOSE OF RECORDS</div>
                                    </div>
                                </td>
                                <td style="width: 52%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>AGENCY NAME:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $agencyName }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>ADDRESS:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $agencyAddress }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>DATE:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $datePrepared ?: \Carbon\Carbon::parse($printCluster->created_at)->format('m/d/Y') }}</div>
                                </td>
                                <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                    <strong>TELEPHONE NUMBER:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $telephoneNumber }}</div>
                                </td>
                            </tr>
                        </table>

                        <!-- MAIN DATA TABLE -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; border-top: none; font-size: 8.5px; text-align: center; table-layout: fixed;">
                            <thead>
                                <tr style="font-weight: bold;">
                                    <th style="border: 1px solid #000; width: 14%; padding: 6px 4px;">GRDS / RDS ITEM NO.</th>
                                    <th style="border: 1px solid #000; width: 44%; padding: 6px 4px; text-align: left;">RECORD SERIES TITLE AND DESCRIPTION</th>
                                    <th style="border: 1px solid #000; width: 17%; padding: 6px 4px;">PERIOD COVERED</th>
                                    <th style="border: 1px solid #000; width: 25%; padding: 6px 4px;">RETENTION PERIOD AND PROVISION/S COMPLIED (If Any)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($printItems as $idx => $pi)
                                    @php
                                        $borderBottom = $loop->last ? 'border-bottom: 2px solid #000;' : 'border-bottom: none;';
                                        $cellStyle = "border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; {$borderBottom}";
                                    @endphp
                                    <tr>
                                        <td style="{{ $cellStyle }} padding: 4px;">{{ $pi->item_number ?? ($idx + 1) }}</td>
                                        <td style="{{ $cellStyle }} text-align: left; padding: 4px 6px;">
                                            <div style="font-weight: bold;">{{ $pi->series_title ?? $pi->doc_name ?? 'Untitled' }}</div>
                                            @if(!empty($pi->description))
                                                <div style="font-style: italic; font-size: 8px; color: #333; margin-top: 1px;">{{ $pi->description }}</div>
                                            @endif
                                        </td>
                                        <td style="{{ $cellStyle }} padding: 4px;">
                                            {{ $pi->period_covered ?? (!empty($pi->start_at ?? null) ? (\Carbon\Carbon::parse($pi->start_at)->format('Y') . (!empty($pi->ends_at ?? null) ? ' - ' . \Carbon\Carbon::parse($pi->ends_at)->format('Y') : '')) : '') }}
                                        </td>
                                        <td style="{{ $cellStyle }} padding: 4px;">{{ $pi->retention_period ?? $pi->total_period ?? $pi->disposition_provision ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        <!-- FOOTER INFO GRID -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; border-top: none; font-size: 8.5px; text-align: left; table-layout: fixed;">
                            <tr>
                                <td style="width: 55%; border: 1px solid #000; padding: 6px;">
                                    <strong>LOCATION OF RECORDS:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $printItems->first()->records_location ?? '' }}</div>
                                </td>
                                <td style="width: 45%; border: 1px solid #000; padding: 6px;">
                                    <strong>VOLUME IN CUBIC METER:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $printItems->first()->volume ?? '' }}</div>
                                </td>
                            </tr>
                            <tr>
                                <td style="border: 1px solid #000; padding: 6px;">
                                    <strong>PREPARED BY:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $preparedBy }}</div>
                                </td>
                                <td style="border: 1px solid #000; padding: 6px;">
                                    <strong>POSITION:</strong>
                                    <div style="font-size: 9.5px; font-weight: bold; margin-top: 2px;">{{ $preparedPosition }}</div>
                                </td>
                            </tr>
                        </table>

                        <!-- CERTIFICATION AND APPROVAL BOX -->
                        <div style="border: 2px solid #000; border-top: none; padding: 14px; font-size: 8.5px;">
                            <div style="font-weight: bold; text-transform: uppercase; margin-bottom: 8px;">CERTIFIED AND APPROVED BY:</div>
                            <div style="text-align: center; margin-top: 10px; margin-bottom: 30px; line-height: 1.4;">
                                This is to certify that the above mentioned records are no longer needed and<br>
                                not involved nor connected in any administrative or judicial cases.
                            </div>
                            <div style="text-align: right;">
                                <div style="border-bottom: 1.5px solid #000; width: 45%; margin-left: auto; text-align: center; font-weight: bold; font-size: 9.5px; padding-bottom: 2px;">
                                    {{ $approvedBy }}
                                </div>
                                <div style="text-align: center; width: 45%; margin-left: auto; font-size: 8px; margin-top: 3px;">
                                    Name and Signature of Agency Head<br>or Duly Authorized Representative
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
