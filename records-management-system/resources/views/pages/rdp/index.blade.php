<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use App\Services\RdpRetentionService;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Dashboard')] class extends Component {
    // Top Tabs & Filters (DTS as default on Received Document Tab)
    public string $receivedTab = 'DTS'; // 'DTS' default as requested
    public string $clusterFilter = 'all'; // 'all', 'nap1', 'nap2', 'nap3'
    public string $clusterSearch = '';
    public string $receivedSearch = '';
    public string $nap3Search = '';

    // Pagination for Dashboard Boxes (fits current height, max 5 items per page / on stack)
    public int $clusterPage = 1;
    public int $clusterPerPage = 5;
    public int $receivedPage = 1;
    public int $receivedPerPage = 5;

    // Add New Series Modal
    public bool $showAddSeriesModal = false;
    public string $newSeriesTitle = '';
    public array $newSubsections = [];
    public string $newActivePeriod = '';
    public string $newStoragePeriod = '';
    public bool $newIsPermanent = false;
    public string $newRemarks = '';

    // Cluster Quick Inspect Modal
    public bool $showClusterModal = false;
    public ?object $selectedCluster = null;
    public array $selectedClusterItems = [];

    public function mount(): void
    {
        // Keep transferred retention records in sync
        try {
            RdpRetentionService::syncTransferredRecords();
        } catch (\Throwable $e) {
            // non-blocking
        }
    }

    public function updatedClusterSearch(): void
    {
        $this->clusterPage = 1;
    }

    public function updatedReceivedSearch(): void
    {
        $this->receivedPage = 1;
    }

    public function setReceivedTab(string $tab): void
    {
        $this->receivedTab = strtoupper($tab) === 'DCS' ? 'DCS' : 'DTS';
        $this->receivedSearch = '';
        $this->receivedPage = 1;
    }

    public function setClusterFilter(string $filter): void
    {
        $this->clusterFilter = in_array($filter, ['all', 'nap1', 'nap2', 'nap3']) ? $filter : 'all';
        $this->clusterPage = 1;
    }

    public function previousClusterPage(): void
    {
        if ($this->clusterPage > 1) {
            $this->clusterPage--;
        }
    }

    public function nextClusterPage(int $maxPage): void
    {
        if ($this->clusterPage < $maxPage) {
            $this->clusterPage++;
        }
    }

    public function gotoClusterPage(int $page): void
    {
        $this->clusterPage = max(1, $page);
    }

    public function previousReceivedPage(): void
    {
        if ($this->receivedPage > 1) {
            $this->receivedPage--;
        }
    }

    public function nextReceivedPage(int $maxPage): void
    {
        if ($this->receivedPage < $maxPage) {
            $this->receivedPage++;
        }
    }

    public function gotoReceivedPage(int $page): void
    {
        $this->receivedPage = max(1, $page);
    }

    public function openAddSeriesModal(): void
    {
        $this->showAddSeriesModal = true;
    }

    public function closeAddSeriesModal(): void
    {
        $this->showAddSeriesModal = false;
        $this->newSeriesTitle = '';
        $this->newSubsections = [];
        $this->newActivePeriod = '';
        $this->newStoragePeriod = '';
        $this->newIsPermanent = false;
        $this->newRemarks = '';
    }

    public function addSubsection(): void
    {
        $this->newSubsections[] = '';
    }

    public function removeSubsection(int $index): void
    {
        if (isset($this->newSubsections[$index])) {
            unset($this->newSubsections[$index]);
            $this->newSubsections = array_values($this->newSubsections);
        }
    }

    public function computeTotalPeriod(?string $active, ?string $storage, bool $isPermanent): string
    {
        if ($isPermanent) {
            return 'Permanent';
        }

        $active = trim($active ?? '');
        $storage = trim($storage ?? '');

        if (empty($active) && empty($storage)) return '';
        if (empty($active)) return $storage;
        if (empty($storage)) return $active;

        $parseTime = function(string $str) {
            $years = 0;
            $months = 0;
            if (preg_match('/(\d+)\s*(?:year|yr|y)s?/i', $str, $m)) {
                $years = (int)$m[1];
            }
            if (preg_match('/(\d+)\s*(?:month|mo|m)s?/i', $str, $m)) {
                $months = (int)$m[1];
            }
            return [$years, $months];
        };

        [$aYears, $aMonths] = $parseTime($active);
        [$sYears, $sMonths] = $parseTime($storage);

        if (($aYears > 0 || $aMonths > 0) && ($sYears > 0 || $sMonths > 0)) {
            $totalMonths = ($aYears * 12 + $aMonths) + ($sYears * 12 + $sMonths);
            $tYears = intdiv($totalMonths, 12);
            $remMonths = $totalMonths % 12;

            $parts = [];
            if ($tYears > 0) {
                $parts[] = $tYears . ' ' . ($tYears === 1 ? 'Year' : 'Years');
            }
            if ($remMonths > 0) {
                $parts[] = $remMonths . ' ' . ($remMonths === 1 ? 'Month' : 'Months');
            }
            return implode(' ', $parts);
        }

        return $active . ' + ' . $storage;
    }

    public function saveNewSeries(): void
    {
        $parentTitle = trim($this->newSeriesTitle);
        if (empty($parentTitle)) return;

        $allTitles = [$parentTitle];
        foreach ($this->newSubsections as $sub) {
            if (!empty(trim($sub))) {
                $allTitles[] = trim($sub);
            }
        }

        $retentionId = null;
        if ($this->newIsPermanent || !empty(trim($this->newActivePeriod)) || !empty(trim($this->newStoragePeriod))) {
            $active = $this->newIsPermanent ? 'Permanent' : (trim($this->newActivePeriod) ?: null);
            $storage = $this->newIsPermanent ? 'Permanent' : (trim($this->newStoragePeriod) ?: null);
            $computedTotal = $this->computeTotalPeriod($this->newActivePeriod, $this->newStoragePeriod, $this->newIsPermanent);

            $retentionId = DB::table('rdp_retention_period')->insertGetId([
                'active_period'  => $active,
                'storage_period' => $storage,
                'total_period'   => $computedTotal ?: null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code;
        $currentParentId = null;

        foreach ($allTitles as $idx => $t) {
            $isLeaf = ($idx === count($allTitles) - 1);

            $existing = DB::table('rdp_record_series')
                ->where('series_title', $t)
                ->where(function($q) use ($currentParentId) {
                    if (is_null($currentParentId)) {
                        $q->whereNull('parent_id');
                    } else {
                        $q->where('parent_id', $currentParentId);
                    }
                })
                ->first();

            if ($existing) {
                $currentParentId = $existing->id;
                if ($isLeaf) {
                    $updateData = ['updated_at' => now()];
                    if ($retentionId) {
                        $updateData['retention_period'] = $retentionId;
                        $updateData['is_retention_period_permanent'] = $this->newIsPermanent;
                    }
                    if (!empty(trim($this->newRemarks))) {
                        $updateData['remarks'] = trim($this->newRemarks);
                    }
                    DB::table('rdp_record_series')->where('id', $existing->id)->update($updateData);
                }
            } else {
                $currentParentId = DB::table('rdp_record_series')->insertGetId([
                    'series_title'                  => $t,
                    'parent_id'                     => $currentParentId,
                    'retention_period'              => $isLeaf ? $retentionId : null,
                    'is_retention_period_permanent' => $isLeaf ? $this->newIsPermanent : false,
                    'recorded_at_office'            => $userOfficeCode,
                    'created_by'                    => auth()->id(),
                    'is_verified'                   => false,
                    'remarks'                       => $isLeaf ? (trim($this->newRemarks) ?: null) : null,
                    'created_at'                    => now(),
                    'updated_at'                    => now(),
                ]);
            }
        }

        if (Schema::hasTable('sys_admin_logs')) {
            DB::table('sys_admin_logs')->insert([
                'admin_id'    => auth()->id() ?? 1,
                'changes'     => 'Added new Record Series via RDP Dashboard: "' . $parentTitle . '"',
                'what_system' => 2,
                'when_changes'=> now(),
            ]);
        }

        $this->closeAddSeriesModal();
    }

    public function inspectCluster(int $clusterId, string $formCode): void
    {
        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

        if ($formCode === 'nap2' || str_contains(strtolower($formCode), 'form 2')) {
            $cluster = DB::table('rdp_pending_record_series')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record_series.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin($officeTbl . ' as office', 'rdp_pending_record_series.office', '=', 'office.office_code')
                ->leftJoin($accDetailsTbl . ' as account_details', 'rdp_pending_record_series.created_by', '=', 'account_details.account_id')
                ->leftJoin($officeTbl . ' as submitter_office', 'account_details.office_id', '=', 'submitter_office.id')
                ->where('rdp_pending_record_series.cluster_id', $clusterId)
                ->select([
                    'rdp_pending_record_series.cluster_id',
                    'rdp_pending_record_series.cluster_name',
                    'rdp_pending_record_series.status_id',
                    DB::raw("COALESCE(rdp_pending_record_series.office, submitter_office.office_code) as office"),
                    'rdp_pending_record_series.created_at',
                    'rdp_pending_status.status_name',
                    DB::raw("COALESCE(office.office_name, submitter_office.office_name) as office_name"),
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("'NAP Form 2' as form_label"),
                    DB::raw("'nap2' as form_code")
                ])
                ->first();

            $items = [];
            if ($cluster) {
                $items = DB::table('rdp_grouped_record_series')
                    ->join('rdp_record_series', 'rdp_grouped_record_series.record_series_id', '=', 'rdp_record_series.id')
                    ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
                    ->where('rdp_grouped_record_series.group_head', $clusterId)
                    ->select([
                        'rdp_record_series.item_number',
                        'rdp_record_series.series_title',
                        'rdp_retention_period.total_period',
                        'rdp_record_series.remarks',
                    ])
                    ->get()
                    ->toArray();
            }
        } else {
            $cluster = DB::table('rdp_pending_record')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin($officeTbl . ' as office', 'rdp_pending_record.office', '=', 'office.office_code')
                ->leftJoin($accDetailsTbl . ' as account_details', 'rdp_pending_record.created_by', '=', 'account_details.account_id')
                ->leftJoin($officeTbl . ' as submitter_office', 'account_details.office_id', '=', 'submitter_office.id')
                ->where('rdp_pending_record.cluster_id', $clusterId)
                ->select([
                    'rdp_pending_record.cluster_id',
                    'rdp_pending_record.cluster_name',
                    'rdp_pending_record.status_id',
                    DB::raw("COALESCE(rdp_pending_record.office, submitter_office.office_code) as office"),
                    'rdp_pending_record.is_for_nap_one',
                    'rdp_pending_record.is_for_nap_three',
                    'rdp_pending_record.created_at',
                    'rdp_pending_status.status_name',
                    DB::raw("COALESCE(office.office_name, submitter_office.office_name) as office_name"),
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'NAP Form 3' ELSE 'NAP Form 1' END as form_label"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'nap3' ELSE 'nap1' END as form_code")
                ])
                ->first();

            $items = [];
            if ($cluster) {
                $items = DB::table('rdp_grouped_record')
                    ->join('rdp_record', 'rdp_grouped_record.record_id', '=', 'rdp_record.id')
                    ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
                    ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
                    ->where('rdp_grouped_record.group_head', $clusterId)
                    ->select([
                        'rdp_record_series.item_number',
                        'rdp_record_series.series_title',
                        'rdp_record.description',
                        'rdp_record.volume',
                        'rdp_record.records_location',
                        'rdp_retention_period.total_period',
                    ])
                    ->get()
                    ->toArray();
            }
        }

        if ($cluster) {
            $this->selectedCluster = $cluster;
            $this->selectedClusterItems = $items;
            $this->showClusterModal = true;
        }
    }

    public function closeClusterModal(): void
    {
        $this->showClusterModal = false;
        $this->selectedCluster = null;
        $this->selectedClusterItems = [];
    }

    public function with(): array
    {
        $user = Auth::user();
        $perms = $user?->permissions;
        $isSadm = (bool)($perms?->is_sadm ?? false)
            || (bool)($perms?->is_rdp_view_all_pending_list ?? false)
            || (bool)($perms?->can_access_rdp_admin ?? false)
            || (bool)($perms?->rdp_view_all_files ?? false);

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? null;
        if (empty($userOfficeCode) && !empty($user?->details?->office_id)) {
            $userOfficeCode = DB::table($officeTbl)->where('id', $user->details->office_id)->value('office_code');
        }
        $userOfficeName = $user?->details?->office?->office_name ?? null;
        if (empty($userOfficeName) && !empty($user?->details?->office_id)) {
            $userOfficeName = DB::table($officeTbl)->where('id', $user->details->office_id)->value('office_name');
        }
        $userFirstName = trim($user?->details?->first_name ?? '') ?: ($user?->username ?? 'Officer');
        $userOffice = $user?->details?->office?->office_name ?: 'Records and Freedom of Information Office';

        $headerTime = now('Asia/Manila')->format('h:i:s A');
        $headerDate = now('Asia/Manila')->format('F d, Y');

        $mainPendingTbl = Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';
        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

        // -------------------------------------------------------------
        // 1. TOTAL NAP FORM STATS
        // -------------------------------------------------------------
        // NAP Form 1: Records in active inventory schedule
        $nap1Count = DB::table('rdp_record')
            ->where('is_draft', false)
            ->where('is_active', true)
            ->where('transferred_to_nap3', false)
            ->when($userOfficeCode, fn($q) => $q->where('office_own', $userOfficeCode))
            ->count();

        // NAP Form 2: Unverified record series created by the user / office (per user request)
        $nap2Count = DB::table('rdp_record_series')
            ->where('is_verified', false)
            ->where('is_active', true)
            ->when($userOfficeCode, fn($q) => $q->where('recorded_at_office', $userOfficeCode))
            ->count();

        // NAP Form 3: Records transferred for disposal authority
        $nap3Count = DB::table('rdp_record')
            ->where('transferred_to_nap3', true)
            ->where('is_draft', false)
            ->where('is_active', true)
            ->when($userOfficeCode, fn($q) => $q->where('office_own', $userOfficeCode))
            ->count();

        // -------------------------------------------------------------
        // 2. LIST OF CREATED CLUSTERS
        // -------------------------------------------------------------
        $clustersCollection = collect();

        // Series Clusters (NAP Form 2)
        if ($this->clusterFilter === 'all' || $this->clusterFilter === 'nap2') {
            $qSeries = DB::table($mainPendingTbl)
                ->join('rdp_pending_record_series', "{$mainPendingTbl}.id", '=', 'rdp_pending_record_series.cluster_id')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record_series.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin($officeTbl, 'rdp_pending_record_series.office', '=', "{$officeTbl}.office_code")
                ->leftJoin($accDetailsTbl, 'rdp_pending_record_series.created_by', '=', "{$accDetailsTbl}.account_id")
                ->leftJoin($officeTbl . ' as submitter_office', "{$accDetailsTbl}.office_id", '=', "submitter_office.id")
                ->select([
                    "{$mainPendingTbl}.id as main_id",
                    'rdp_pending_record_series.cluster_id',
                    'rdp_pending_record_series.cluster_name',
                    'rdp_pending_record_series.status_id',
                    DB::raw("COALESCE(rdp_pending_record_series.office, submitter_office.office_code) as office"),
                    'rdp_pending_record_series.created_at',
                    'rdp_pending_status.status_name',
                    DB::raw("COALESCE({$officeTbl}.office_name, submitter_office.office_name) as office_name"),
                    DB::raw("CONCAT({$accDetailsTbl}.first_name, ' ', {$accDetailsTbl}.last_name) as submitter_name"),
                    DB::raw("'NAP Form 2' as form_label"),
                    DB::raw("'nap2' as form_code"),
                    DB::raw("(SELECT COUNT(*) FROM rdp_grouped_record_series WHERE group_head = rdp_pending_record_series.cluster_id) as total_items")
                ]);

            if ($userOfficeCode) {
                $qSeries->where(function($sub) use ($userOfficeCode) {
                    $sub->where('rdp_pending_record_series.office', $userOfficeCode)
                        ->orWhere('submitter_office.office_code', $userOfficeCode);
                });
            }

            if (!empty(trim($this->clusterSearch))) {
                $term = '%' . trim($this->clusterSearch) . '%';
                $qSeries->where(function($q) use ($term, $officeTbl) {
                    $q->where('rdp_pending_record_series.cluster_name', 'ILIKE', $term)
                      ->orWhere("{$officeTbl}.office_name", 'ILIKE', $term)
                      ->orWhere("submitter_office.office_name", 'ILIKE', $term);
                });
            }

            $clustersCollection = $clustersCollection->concat($qSeries->get());
        }

        // Record Clusters (NAP Form 1 & NAP Form 3)
        if ($this->clusterFilter === 'all' || $this->clusterFilter === 'nap1' || $this->clusterFilter === 'nap3') {
            $qRec = DB::table($mainPendingTbl)
                ->join('rdp_pending_record', "{$mainPendingTbl}.id", '=', 'rdp_pending_record.cluster_id')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin($officeTbl, 'rdp_pending_record.office', '=', "{$officeTbl}.office_code")
                ->leftJoin($accDetailsTbl, 'rdp_pending_record.created_by', '=', "{$accDetailsTbl}.account_id")
                ->leftJoin($officeTbl . ' as submitter_office', "{$accDetailsTbl}.office_id", '=', "submitter_office.id")
                ->select([
                    "{$mainPendingTbl}.id as main_id",
                    'rdp_pending_record.cluster_id',
                    'rdp_pending_record.cluster_name',
                    'rdp_pending_record.status_id',
                    DB::raw("COALESCE(rdp_pending_record.office, submitter_office.office_code) as office"),
                    'rdp_pending_record.is_for_nap_one',
                    'rdp_pending_record.is_for_nap_three',
                    'rdp_pending_record.created_at',
                    'rdp_pending_status.status_name',
                    DB::raw("COALESCE({$officeTbl}.office_name, submitter_office.office_name) as office_name"),
                    DB::raw("CONCAT({$accDetailsTbl}.first_name, ' ', {$accDetailsTbl}.last_name) as submitter_name"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'NAP Form 3' ELSE 'NAP Form 1' END as form_label"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'nap3' ELSE 'nap1' END as form_code"),
                    DB::raw("(SELECT COUNT(*) FROM rdp_grouped_record WHERE group_head = rdp_pending_record.cluster_id) as total_items")
                ]);

            if ($userOfficeCode) {
                $qRec->where(function($sub) use ($userOfficeCode) {
                    $sub->where('rdp_pending_record.office', $userOfficeCode)
                        ->orWhere('submitter_office.office_code', $userOfficeCode);
                });
            }

            if ($this->clusterFilter === 'nap1') {
                $qRec->where('rdp_pending_record.is_for_nap_one', true);
            } elseif ($this->clusterFilter === 'nap3') {
                $qRec->where('rdp_pending_record.is_for_nap_three', true);
            }

            if (!empty(trim($this->clusterSearch))) {
                $term = '%' . trim($this->clusterSearch) . '%';
                $qRec->where(function($q) use ($term, $officeTbl) {
                    $q->where('rdp_pending_record.cluster_name', 'ILIKE', $term)
                      ->orWhere("{$officeTbl}.office_name", 'ILIKE', $term)
                      ->orWhere("submitter_office.office_name", 'ILIKE', $term);
                });
            }

            $clustersCollection = $clustersCollection->concat($qRec->get());
        }

        $totalClustersCount = $clustersCollection->count();
        $clusterMaxPage = max(1, (int) ceil($totalClustersCount / $this->clusterPerPage));
        if ($this->clusterPage > $clusterMaxPage) {
            $this->clusterPage = $clusterMaxPage;
        }
        $createdClusters = $clustersCollection->sortByDesc('main_id')->values()->forPage($this->clusterPage, $this->clusterPerPage);

        // -------------------------------------------------------------
        // 3. RECEIVED DOCUMENTS (DTS / DCS) - DTS is Default
        // -------------------------------------------------------------
        $recDocsQuery = DB::table('rdp_received_documents')
            ->where('source_subsystem', $this->receivedTab);

        if ($userOfficeCode) {
            $recDocsQuery->where(function($q) use ($userOfficeCode, $userOfficeName) {
                $q->where('origin_office', 'ILIKE', "%{$userOfficeCode}%")
                  ->orWhere('target_office', 'ILIKE', "%{$userOfficeCode}%");
                if (!empty($userOfficeName) && $userOfficeName !== $userOfficeCode) {
                    $q->orWhere('origin_office', 'ILIKE', "%{$userOfficeName}%")
                      ->orWhere('target_office', 'ILIKE', "%{$userOfficeName}%");
                }
            });
        }

        if (!empty(trim($this->receivedSearch))) {
            $term = '%' . trim($this->receivedSearch) . '%';
            $recDocsQuery->where(function($q) use ($term) {
                $q->where('document_code', 'ILIKE', $term)
                  ->orWhere('document_title', 'ILIKE', $term)
                  ->orWhere('description', 'ILIKE', $term)
                  ->orWhere('origin_office', 'ILIKE', $term);
            });
        }

        $totalReceivedDocs = (clone $recDocsQuery)->count();
        $receivedMaxPage = max(1, (int) ceil($totalReceivedDocs / $this->receivedPerPage));
        if ($this->receivedPage > $receivedMaxPage) {
            $this->receivedPage = $receivedMaxPage;
        }
        $receivedDocs = $recDocsQuery->orderBy('id', 'desc')
            ->offset(($this->receivedPage - 1) * $this->receivedPerPage)
            ->limit($this->receivedPerPage)
            ->get();

        $dcsCountQuery = DB::table('rdp_received_documents')->where('source_subsystem', 'DCS');
        $dtsCountQuery = DB::table('rdp_received_documents')->where('source_subsystem', 'DTS');
        if ($userOfficeCode) {
            $dcsCountQuery->where(function($q) use ($userOfficeCode, $userOfficeName) {
                $q->where('origin_office', 'ILIKE', "%{$userOfficeCode}%")
                  ->orWhere('target_office', 'ILIKE', "%{$userOfficeCode}%");
                if (!empty($userOfficeName) && $userOfficeName !== $userOfficeCode) {
                    $q->orWhere('origin_office', 'ILIKE', "%{$userOfficeName}%")
                      ->orWhere('target_office', 'ILIKE', "%{$userOfficeName}%");
                }
            });
            $dtsCountQuery->where(function($q) use ($userOfficeCode, $userOfficeName) {
                $q->where('origin_office', 'ILIKE', "%{$userOfficeCode}%")
                  ->orWhere('target_office', 'ILIKE', "%{$userOfficeCode}%");
                if (!empty($userOfficeName) && $userOfficeName !== $userOfficeCode) {
                    $q->orWhere('origin_office', 'ILIKE', "%{$userOfficeName}%")
                      ->orWhere('target_office', 'ILIKE', "%{$userOfficeName}%");
                }
            });
        }
        $dcsCount = $dcsCountQuery->count();
        $dtsCount = $dtsCountQuery->count();

        // -------------------------------------------------------------
        // 4. LIST NAP FORM 3 (FOR THE NEWLY - HIERARCHICAL LAYOUT)
        // -------------------------------------------------------------
        $recordsQuery = DB::table('rdp_record')
            ->select([
                'rdp_record.id',
                'rdp_record.record_series_id',
                'rdp_record.description',
                'rdp_record.volume',
                'rdp_record.records_location',
                'rdp_record.office_own',
                'rdp_record.time_value',
                'rdp_record.updated_at',
                'rdp_record.created_at',
            ])
            ->where('rdp_record.transferred_to_nap3', true)
            ->where('rdp_record.is_draft', false)
            ->where('rdp_record.is_active', true);

        if ($userOfficeCode) {
            $recordsQuery->where(function($q) use ($userOfficeCode) {
                $q->where('rdp_record.office_own', $userOfficeCode)
                  ->orWhereExists(function($sub) use ($userOfficeCode) {
                      $sub->select(DB::raw(1))
                          ->from('rdp_duplication_section')
                          ->whereColumn('rdp_duplication_section.dup_id_manager', 'rdp_record.duplication_id')
                          ->where('rdp_duplication_section.office_code', $userOfficeCode);
                  });
            });
        }

        if (!empty(trim($this->nap3Search))) {
            $term = '%' . trim($this->nap3Search) . '%';
            $recordsQuery->where(function($q) use ($term) {
                $q->where('rdp_record.description', 'ILIKE', $term)
                  ->orWhere('rdp_record.volume', 'ILIKE', $term)
                  ->orWhere('rdp_record.records_location', 'ILIKE', $term)
                  ->orWhereExists(function($sq) use ($term) {
                      $sq->select(DB::raw(1))
                         ->from('rdp_record_series')
                         ->whereColumn('rdp_record_series.id', 'rdp_record.record_series_id')
                         ->where('rdp_record_series.series_title', 'ILIKE', $term);
                  });
            });
        }

        $allNap3Records = $recordsQuery->orderBy('rdp_record.id', 'asc')->get();
        $nap3RecordIds = $allNap3Records->pluck('id')->all();

        $periods = empty($nap3RecordIds) ? collect() : DB::table('rdp_period_covered')
            ->whereIn('period_owner', $nap3RecordIds)
            ->orderBy('id', 'asc')
            ->get()
            ->groupBy('period_owner');

        $usedSeriesIds = $allNap3Records->pluck('record_series_id')->unique()->all();

        $directSeries = empty($usedSeriesIds) ? collect() : DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series_type', 'rdp_record_series.series_type', '=', 'rdp_record_series_type.id')
            ->select([
                'rdp_record_series.*',
                'rdp_record_series_type.shorted_type',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
            ])
            ->whereIn('rdp_record_series.id', $usedSeriesIds)
            ->where('rdp_record_series.is_active', true)
            ->get();

        $neededParentIds = $directSeries->pluck('parent_id')->filter()->unique()->all();

        $parentSeries = empty($neededParentIds) ? collect() : DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series_type', 'rdp_record_series.series_type', '=', 'rdp_record_series_type.id')
            ->leftJoin($officeTbl . ' as office', 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
            ->select([
                'rdp_record_series.*',
                'rdp_record_series_type.shorted_type',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
                'office.office_name as recorded_office_name',
            ])
            ->whereIn('rdp_record_series.id', $neededParentIds)
            ->where('rdp_record_series.is_active', true)
            ->get();

        $allRoots = $parentSeries->concat($directSeries->whereNull('parent_id'))->unique('id');
        $subSeriesByParent = $directSeries->whereNotNull('parent_id')->groupBy('parent_id');
        $recordsBySeries = $allNap3Records->groupBy('record_series_id');

        $sortedRoots = $allRoots->sortBy(function($root) use ($subSeriesByParent, $recordsBySeries) {
            $minId = PHP_INT_MAX;
            if (isset($recordsBySeries[$root->id])) {
                $minId = min($minId, $recordsBySeries[$root->id]->min('id'));
            }
            if (isset($subSeriesByParent[$root->id])) {
                foreach ($subSeriesByParent[$root->id] as $sub) {
                    if (isset($recordsBySeries[$sub->id])) {
                        $minId = min($minId, $recordsBySeries[$sub->id]->min('id'));
                    }
                }
            }
            return $minId;
        })->values();

        $compilePeriodCovered = function(array $dates): string {
            $years = [];
            $rawList = [];
            foreach ($dates as $d) {
                $d = trim((string)$d);
                if (empty($d) || $d === '—') continue;
                if (preg_match('/^(\d{4})/', $d, $m)) {
                    $years[] = (int)$m[1];
                } else {
                    $rawList[] = $d;
                }
            }
            $years = array_unique($years);
            rsort($years);

            if (empty($years)) {
                return !empty($rawList) ? implode(', ', array_unique($rawList)) : '—';
            }

            $groups = [];
            $currentGroup = [];
            foreach ($years as $y) {
                if (empty($currentGroup)) {
                    $currentGroup[] = $y;
                } else {
                    $last = end($currentGroup);
                    if ($last - 1 === $y) {
                        $currentGroup[] = $y;
                    } else {
                        $groups[] = $currentGroup;
                        $currentGroup = [$y];
                    }
                }
            }
            if (!empty($currentGroup)) {
                $groups[] = $currentGroup;
            }

            $formattedGroups = [];
            foreach ($groups as $grp) {
                if (count($grp) >= 2) {
                    $formattedGroups[] = $grp[0] . '-' . end($grp);
                } else {
                    $formattedGroups[] = (string)$grp[0];
                }
            }

            $res = implode(', ', $formattedGroups);
            if (!empty($rawList)) {
                $res .= ', ' . implode(', ', array_unique($rawList));
            }
            return $res;
        };

        $nap3Tree = [];
        $totalItemsCount = 0;

        foreach ($sortedRoots as $root) {
            $rootNode = (object)[
                'id'            => $root->id,
                'item_number'   => $root->item_number ?: '—',
                'series_title'  => $root->series_title,
                'shorted_type'  => $root->shorted_type,
                'remarks'       => $root->remarks ?? '',
                'total_period'  => $root->total_period ?? '—',
                'sub_series'    => [],
                'direct_records'=> [],
                'has_children'  => false,
                'total_records' => 0,
            ];

            $subs = $subSeriesByParent[$root->id] ?? collect();

            if ($subs->isNotEmpty()) {
                $rootNode->has_children = true;

                $sortedSubs = $subs->sortBy(function($sub) use ($recordsBySeries) {
                    return isset($recordsBySeries[$sub->id]) ? $recordsBySeries[$sub->id]->min('id') : PHP_INT_MAX;
                })->values();

                $rootDates = [];
                foreach ($sortedSubs as $sub) {
                    $subRecs = $recordsBySeries[$sub->id] ?? collect();
                    if ($subRecs->isEmpty()) continue;

                    $subDates = [];
                    $subChildItems = [];

                    foreach ($subRecs as $rec) {
                        $pRow = $periods[$rec->id]->first() ?? null;
                        $rawDate = $pRow->date_covered ?? '';
                        $subDates[] = $rawDate;
                        $rootDates[] = $rawDate;

                        $subChildItems[] = (object)[
                            'id'           => $rec->id,
                            'description'  => $rec->description,
                            'date_covered' => $rawDate ?: '—',
                            'volume'       => $rec->volume ?: '',
                            'location'     => $rec->records_location ?: '',
                        ];
                    }

                    $rootNode->sub_series[] = (object)[
                        'id'              => $sub->id,
                        'series_title'    => $sub->series_title,
                        'total_period'    => $sub->total_period ?? $root->total_period ?? '—',
                        'remarks'         => $sub->remarks ?? $root->remarks ?? '',
                        'compiled_period' => $compilePeriodCovered($subDates),
                        'records'         => $subChildItems,
                        'records_count'   => count($subChildItems),
                    ];
                    $rootNode->total_records += count($subChildItems);
                }

                $rootNode->compiled_period = $compilePeriodCovered($rootDates);
            } else {
                $directRecs = $recordsBySeries[$root->id] ?? collect();
                $rootDates = [];
                $childItems = [];

                foreach ($directRecs as $rec) {
                    $pRow = $periods[$rec->id]->first() ?? null;
                    $rawDate = $pRow->date_covered ?? '';
                    $rootDates[] = $rawDate;

                    $childItems[] = (object)[
                        'id'           => $rec->id,
                        'description'  => $rec->description,
                        'date_covered' => $rawDate ?: '—',
                        'volume'       => $rec->volume ?: '',
                        'location'     => $rec->records_location ?: '',
                    ];
                }

                $rootNode->direct_records = $childItems;
                $rootNode->compiled_period = $compilePeriodCovered($rootDates);
                $rootNode->total_records = count($childItems);
            }

            if ($rootNode->total_records > 0) {
                $nap3Tree[] = $rootNode;
                $totalItemsCount += $rootNode->total_records;
            }
        }

        return [
            'userFirstName'      => $userFirstName,
            'headerTime'         => $headerTime,
            'headerDate'         => $headerDate,
            'userOffice'         => $userOffice,
            'nap1Count'          => $nap1Count,
            'nap2Count'          => $nap2Count,
            'nap3Count'          => $nap3Count,
            'createdClusters'    => $createdClusters,
            'totalClustersCount' => $totalClustersCount,
            'clusterPage'        => $this->clusterPage,
            'clusterMaxPage'     => $clusterMaxPage,
            'clusterPerPage'     => $this->clusterPerPage,
            'receivedDocs'       => $receivedDocs,
            'totalReceivedDocs'  => $totalReceivedDocs,
            'receivedPage'       => $this->receivedPage,
            'receivedMaxPage'    => $receivedMaxPage,
            'receivedPerPage'    => $this->receivedPerPage,
            'dcsCount'           => $dcsCount,
            'dtsCount'           => $dtsCount,
            'nap3Tree'           => $nap3Tree,
            'totalNewlyNap3'     => $totalItemsCount,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/console.css'])
@endpush

<div class="rdp-dashboard-page">
    <script>
        function rdpDashboardClock() {
            return {
                nowClock: '{{ $headerTime }}',
                init() {
                    setInterval(() => {
                        const now = new Date();
                        this.nowClock = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
                    }, 1000);
                }
            }
        }
    </script>
    <style>
        .rdp-dashboard-page {
            padding: 20px 22px;
            background: #f8fafc;
            min-height: 100vh;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: #0f172a;
            container-type: inline-size;
            container-name: rdp-dash;
        }

        /* -------------------------------------------------------------
           1. TOP BLUE HERO BANNER (MATCHING REFERENCE IMAGE)
           ------------------------------------------------------------- */
        .rdp-hero-banner {
            background: #0052cc;
            background: linear-gradient(135deg, #0045c7 0%, #0052cc 50%, #1a6dff 100%);
            border-radius: 14px;
            padding: 26px 32px;
            color: #ffffff;
            box-shadow: 0 10px 24px -6px rgba(0, 82, 204, 0.4);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Decorative floating circles */
        .rdp-hero-banner::before {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
            top: -80px;
            right: 180px;
            pointer-events: none;
        }

        .rdp-hero-banner::after {
            content: '';
            position: absolute;
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            bottom: -50px;
            right: 350px;
            pointer-events: none;
        }

        .rdp-hero-decor {
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .rdp-hero-decor-1 {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.07);
            top: 10px;
            right: 520px;
        }

        .rdp-hero-decor-2 {
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.03);
            bottom: -160px;
            left: 40%;
        }

        .rdp-hero-decor-3 {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.08);
            bottom: 12px;
            right: 280px;
        }

        .rdp-hero-kicker {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.85);
            margin-bottom: 4px;
        }

        .rdp-hero-title {
            font-size: 27px;
            font-weight: 800;
            color: #ffffff;
            margin: 0;
            line-height: 1.25;
            letter-spacing: -0.4px;
        }

        .rdp-hero-subtext {
            font-size: 13.5px;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.92);
            margin: 6px 0 0 0;
            line-height: 1.4;
            max-width: 540px;
        }

        .rdp-hero-clock-box {
            text-align: right;
            color: #ffffff;
        }

        .rdp-hero-clock {
            font-size: 34px;
            font-weight: 800;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }

        .rdp-hero-date {
            font-size: 13px;
            font-weight: 500;
            opacity: 0.9;
            margin-top: 4px;
        }

        /* -------------------------------------------------------------
           2. TWO-COLUMN LAYOUT (MATCHING USER REFERENCE DIAGRAM)
           ------------------------------------------------------------- */
        .rdp-grid-split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 20px;
            align-items: stretch;
        }

        .rdp-grid-col {
            display: flex;
            flex-direction: column;
            min-height: 0;
            min-width: 0;
            height: 100%;
        }

        /* Switch to stacked layout when content area is narrow (sidebar open on small monitor) */
        /* Note: CSS zoom (0.72 on 1024px monitor) inflates effective width to ~1122px with sidebar open */
        @container rdp-dash (max-width: 1150px) {
            .rdp-grid-split {
                grid-template-columns: 1fr;
                gap: 14px;
            }
        }

        /* Extra compact for very small containers */
        @container rdp-dash (max-width: 500px) {
            .rdp-hero-banner {
                padding: 18px 20px;
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .rdp-hero-title {
                font-size: 20px;
            }
            .rdp-hero-subtext {
                font-size: 12px;
            }
            .rdp-hero-clock {
                font-size: 24px;
            }
            .rdp-hero-clock-box {
                text-align: left;
            }
            .rdp-bottom-box {
                padding: 16px 14px;
            }
        }

        /* TOTAL NAP FORM STATS BAR (COMPACT 3-PILL ROW ON TOP OF CREATED CLUSTERS) */
        .nap-stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 10px;
            height: 38px;
            align-items: stretch;
        }

        .nap-stat-pill {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 6px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            min-width: 0;
            overflow: hidden;
        }

        .nap-stat-pill:hover {
            border-color: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.12);
        }

        .nap-stat-pill-name {
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .nap-stat-pill-val {
            font-size: 15px;
            font-weight: 800;
            line-height: 1;
            margin-left: 4px;
        }

        .pill-nap1 { border-left: 3px solid #2563eb; }
        .pill-nap1 .nap-stat-pill-name { color: #1e40af; }
        .pill-nap1 .nap-stat-pill-val { color: #1d4ed8; }

        .pill-nap2 { border-left: 3px solid #4f46e5; }
        .pill-nap2 .nap-stat-pill-name { color: #4338ca; }
        .pill-nap2 .nap-stat-pill-val { color: #3730a3; }

        .pill-nap3 { border-left: 3px solid #059669; }
        .pill-nap3 .nap-stat-pill-name { color: #047857; }
        .pill-nap3 .nap-stat-pill-val { color: #065f46; }

        /* CARDS / BOXES */
        .rdp-card-box {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.03);
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 380px;
        }

        .rdp-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .rdp-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-top: auto;
            padding-top: 10px;
            border-top: 1px solid #f1f5f9;
            min-height: 36px;
            flex-wrap: wrap;
        }

        .rdp-pagination-bar {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .rdp-page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 26px;
            height: 24px;
            padding: 0 6px;
            font-size: 11px;
            font-weight: 700;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .rdp-page-btn:hover:not(.disabled):not(.active) {
            background: #f1f5f9;
            border-color: #94a3b8;
            color: #0f172a;
        }

        .rdp-page-btn.active {
            background: #2563eb;
            border-color: #2563eb;
            color: #ffffff;
            cursor: default;
        }

        .rdp-page-btn.disabled {
            opacity: 0.35;
            cursor: not-allowed;
            background: #f8fafc;
        }

        .rdp-card-title {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            letter-spacing: -0.2px;
        }

        /* FOLDER TABS ON TOP OF RECEIVED DOCUMENT */
        .rdp-tabs-header-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            height: 49px;
            margin-bottom: -1px;
            position: relative;
            z-index: 2;
        }

        .rdp-folder-tabs {
            display: flex;
            gap: 4px;
            margin-left: auto;
        }

        .rdp-tab-btn {
            padding: 8px 18px;
            font-size: 12px;
            font-weight: 800;
            border-top-left-radius: 10px;
            border-top-right-radius: 10px;
            cursor: pointer;
            border: 1px solid #cbd5e1;
            border-bottom: none;
            background: #e2e8f0;
            color: #475569;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .rdp-tab-btn:hover {
            background: #f1f5f9;
            color: #1e293b;
        }

        .rdp-tab-btn.active {
            background: #ffffff;
            color: #1d4ed8;
            border-color: #cbd5e1;
            border-bottom: 2px solid #ffffff;
            font-weight: 800;
            padding-bottom: 9px;
            box-shadow: 0 -2px 6px rgba(0, 0, 0, 0.04);
        }

        /* TABLES */
        .rdp-compact-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12.5px;
            text-align: left;
        }

        .rdp-compact-table th {
            background: #f8fafc;
            padding: 8px 10px;
            font-weight: 700;
            color: #475569;
            border-bottom: 1.5px solid #e2e8f0;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .rdp-compact-table td {
            padding: 9px 10px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #1e293b;
        }

        .rdp-compact-table tr:hover td {
            background: #f8fafc;
        }

        .rdp-compact-table tr:last-child td {
            border-bottom: none;
        }

        /* BADGES */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge-pending { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-approved { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .badge-appraised { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
        .badge-dismissed { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

        .form-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: 5px;
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .form-badge-nap1 { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .form-badge-nap2 { background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; }
        .form-badge-nap3 { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }

        /* SEARCH INPUT */
        .rdp-search-input {
            padding: 7px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 12px;
            outline: none;
            background: #ffffff;
            color: #0f172a;
            transition: border-color 0.2s;
        }
        .rdp-search-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
        }

        /* FILTER CHIPS */
        .chip-filter {
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 700;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .chip-filter:hover {
            background: #f1f5f9;
        }
        .chip-filter.active {
            background: #1e3a8a;
            color: #ffffff;
            border-color: #1e3a8a;
        }

        /* BOTTOM FULL-WIDTH NAP 3 BOX */
        .rdp-bottom-box {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            padding: 22px 24px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
        }

        .nap-action-link {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 700;
            text-decoration: none;
            color: #2563eb;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .nap-action-link:hover {
            background: #2563eb;
            color: #ffffff;
        }

        /* NAP FORM 3 HIERARCHICAL ROWS (MATCHING REPORT NAP FORM 3) */
        .root-series-row {
            background: #f8fafc;
            font-weight: 800;
            border-top: 2px solid #cbd5e1 !important;
            border-bottom: 2px solid #cbd5e1 !important;
        }
        .sub-series-row {
            background: #ffffff;
            font-weight: 700;
            border-bottom: 1px solid #cbd5e1;
        }
        .record-item-row {
            background: #fafafa;
            font-size: 12.5px;
            transition: background 0.15s ease;
        }
        .record-item-row:hover {
            background: #f1f5f9;
        }
        .corner-symbol {
            font-family: ui-monospace, SFMono-Regular, monospace;
            font-size: 14px;
            color: #dc2626;
            font-weight: 900;
            margin-right: 6px;
        }
        .sub-branch-line {
            font-family: ui-monospace, SFMono-Regular, monospace;
            color: #94a3b8;
            margin-right: 8px;
            font-weight: 700;
        }
        .nap-item-pill {
            display: inline-block;
            background: #e2e8f0;
            color: #0f172a;
            padding: 2px 8px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12.5px;
            font-weight: 800;
        }
        .nap-chevron-btn {
            background: transparent;
            border: 1px solid transparent;
            cursor: pointer;
            padding: 2px 4px;
            border-radius: 4px;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease-in-out;
            line-height: 1;
        }
        .nap-chevron-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        /* MODAL */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-dialog {
            background: #ffffff;
            width: 100%;
            max-width: 620px;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            padding: 24px;
        }


        /* -------------------------------------------------------------
           DARK MODE OVERRIDES
           ------------------------------------------------------------- */
        [data-theme="dark"] .rdp-dashboard-page {
            background: transparent !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .rdp-card-box,
        [data-theme="dark"] .rdp-bottom-box,
        [data-theme="dark"] .nap-stat-pill {
            background: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 4px 16px rgba(0,0,0,0.3) !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .rdp-card-title,
        [data-theme="dark"] .rdp-compact-table td {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .rdp-compact-table th {
            background: #0f172a !important;
            color: #94a3b8 !important;
            border-bottom-color: #1e293b !important;
        }

        [data-theme="dark"] .rdp-compact-table tr:hover td {
            background: #1a253c !important;
        }

        [data-theme="dark"] .rdp-compact-table td {
            border-bottom-color: #1e293b !important;
        }

        [data-theme="dark"] .rdp-tab-btn {
            background: #0f172a !important;
            border-color: #1e293b !important;
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .rdp-tab-btn.active {
            background: #131c2e !important;
            color: #60a5fa !important;
            border-bottom-color: #131c2e !important;
        }

        [data-theme="dark"] .rdp-search-input {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .chip-filter {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .chip-filter.active {
            background: #2563eb !important;
            color: #ffffff !important;
        }

        [data-theme="dark"] .rdp-card-footer {
            border-top-color: #1e293b !important;
        }

        [data-theme="dark"] .rdp-page-btn {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .rdp-page-btn:hover:not(.disabled):not(.active) {
            background: #1e293b !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .rdp-page-btn.active {
            background: #2563eb !important;
            border-color: #2563eb !important;
            color: #ffffff !important;
        }

        [data-theme="dark"] .rdp-page-btn.disabled {
            opacity: 0.3 !important;
            background: #0f172a !important;
        }

        [data-theme="dark"] .modal-dialog {
            background: #131c2e !important;
            border: 1px solid #1e293b !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .modal-dialog label {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .modal-dialog input,
        [data-theme="dark"] .modal-dialog textarea {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .root-series-row {
            background: #1a253c !important;
            border-top-color: #334155 !important;
            border-bottom-color: #334155 !important;
        }
        [data-theme="dark"] .sub-series-row {
            background: #131c2e !important;
            border-bottom-color: #334155 !important;
        }
        [data-theme="dark"] .record-item-row {
            background: #0f172a !important;
        }
        [data-theme="dark"] .record-item-row:hover {
            background: #1e293b !important;
        }
        [data-theme="dark"] .nap-item-pill {
            background: #1e293b !important;
            color: #f8fafc !important;
        }
        [data-theme="dark"] .nap-chevron-btn {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .nap-chevron-btn:hover {
            background: #334155 !important;
            color: #f8fafc !important;
        }
    </style>

    <!-- 1. TOP BLUE HERO BANNER (MATCHING REFERENCE IMAGE) -->
    <div class="rdp-hero-banner" x-data="rdpDashboardClock()">
        <div class="rdp-hero-decor rdp-hero-decor-1"></div>
        <div class="rdp-hero-decor rdp-hero-decor-2"></div>
        <div class="rdp-hero-decor rdp-hero-decor-3"></div>
        <div style="position: relative; z-index: 1;">
            <div class="rdp-hero-kicker">RECORDS DISPOSITION PROGRAM</div>
            <h1 class="rdp-hero-title">
                Welcome back, {{ ucfirst($userFirstName) }}
            </h1>
            <p class="rdp-hero-subtext">
                Monitor and process pending NAP forms and cluster verifications.
            </p>
        </div>

        <div class="rdp-hero-clock-box" style="position: relative; z-index: 1;">
            <div class="rdp-hero-clock" x-text="nowClock">{{ $headerTime }}</div>
            <div class="rdp-hero-date">{{ $headerDate }}</div>
        </div>
    </div>

    <!-- 2. MIDDLE TWO-COLUMN SECTION (EXACTLY MATCHING USER REFERENCE DIAGRAM) -->
    <div class="rdp-grid-split">

        <!-- LEFT COLUMN: TOTAL NAP FORM STATS + LIST OF CREATED CLUSTERS -->
        <div class="rdp-grid-col">
            <!-- 3 COMPACT STATS PILLS (NEVER WRAP, EXACTLY FIT ONE ROW) -->
            <div class="nap-stats-row">
                <a href="{{ route('rdp.reports.nap-form-1') }}" class="nap-stat-pill pill-nap1" title="NAP Form 1: Records Inventory & Appraisal">
                    <div>
                        <div class="nap-stat-pill-name">NAP FORM 1</div>
                        <div style="font-size: 9.5px; color: #64748b; font-weight: 600;">Inventory</div>
                    </div>
                    <div class="nap-stat-pill-val">{{ number_format($nap1Count) }}</div>
                </a>

                <a href="{{ route('rdp.add-records.records-and-disposition-schedule') }}" class="nap-stat-pill pill-nap2" title="NAP Form 2: Unverified Record Series Created by Office">
                    <div>
                        <div class="nap-stat-pill-name">NAP FORM 2</div>
                        <div style="font-size: 9.5px; color: #64748b; font-weight: 600;">Unverified</div>
                    </div>
                    <div class="nap-stat-pill-val">{{ number_format($nap2Count) }}</div>
                </a>

                <a href="{{ route('rdp.reports.nap-form-3') }}" class="nap-stat-pill pill-nap3" title="NAP Form 3: Request for Authority to Dispose of Records">
                    <div>
                        <div class="nap-stat-pill-name">NAP FORM 3</div>
                        <div style="font-size: 9.5px; color: #64748b; font-weight: 600;">Disposal</div>
                    </div>
                    <div class="nap-stat-pill-val">{{ number_format($nap3Count) }}</div>
                </a>
            </div>

            <!-- LIST OF CREATED FORMS BOX -->
            <div class="rdp-card-box">
                <div class="rdp-card-header">
                    <div>
                        <h2 class="rdp-card-title">
                            <i class="fa-solid fa-boxes-stacked" style="color: #2563eb;"></i>
                            List of Created Forms
                            <span style="font-size: 11px; font-weight: 700; color: #64748b; background: #f1f5f9; padding: 2px 7px; border-radius: 10px; margin-left: 4px;">
                                {{ number_format($totalClustersCount) }}
                            </span>
                        </h2>
                        <span style="font-size: 11.5px; color: #64748b;">Forms queued for verification</span>
                    </div>

                    <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                        <input type="text"
                               wire:model.live.debounce.300ms="clusterSearch"
                               placeholder="Search form..."
                               class="rdp-search-input"
                               style="width: 120px; padding: 5px 8px; font-size: 11.5px;">
                    </div>
                </div>

                <!-- Form Filter Chips -->
                <div style="display: flex; gap: 4px; margin-bottom: 10px; align-items: center;">
                    <span style="font-size: 10px; font-weight: 700; color: #64748b;">FILTER:</span>
                    <button type="button" wire:click="setClusterFilter('all')" class="chip-filter {{ $clusterFilter === 'all' ? 'active' : '' }}" style="padding: 2px 7px; font-size: 10.5px;">All</button>
                    <button type="button" wire:click="setClusterFilter('nap1')" class="chip-filter {{ $clusterFilter === 'nap1' ? 'active' : '' }}" style="padding: 2px 7px; font-size: 10.5px;">Form 1</button>
                    <button type="button" wire:click="setClusterFilter('nap2')" class="chip-filter {{ $clusterFilter === 'nap2' ? 'active' : '' }}" style="padding: 2px 7px; font-size: 10.5px;">Form 2</button>
                    <button type="button" wire:click="setClusterFilter('nap3')" class="chip-filter {{ $clusterFilter === 'nap3' ? 'active' : '' }}" style="padding: 2px 7px; font-size: 10.5px;">Form 3</button>
                </div>

                <!-- Forms Table -->
                <div style="overflow-x: auto; flex: 1;">
                    <table class="rdp-compact-table">
                        <thead>
                            <tr>
                                <th style="min-width: 130px;">Form Title</th>
                                <th style="width: 50px; text-align: center;">Form</th>
                                <th style="width: 55px; text-align: center;">Office</th>
                                <th style="width: 75px; text-align: center;">Status</th>
                                <th style="width: 50px; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($createdClusters as $cluster)
                                @php
                                    $formCode = strtolower($cluster->form_code ?? 'nap1');
                                    $statusName = $cluster->status_name ?? 'Pending Verification';
                                    $isPending = str_contains(strtolower($statusName), 'pending');
                                    $isApproved = str_contains(strtolower($statusName), 'approved');
                                    $isRejected = str_contains(strtolower($statusName), 'reject') || str_contains(strtolower($statusName), 'return');
                                @endphp
                                <tr>
                                    <td>
                                        <div style="font-weight: 700; color: #0f172a; line-height: 1.25; font-size: 12px;">
                                            {{ $cluster->cluster_name }}
                                        </div>
                                        <div style="font-size: 10.5px; color: #64748b; margin-top: 2px;">
                                            <span>{{ (int)($cluster->total_items ?? 0) }} item(s)</span>
                                            @if(!empty($cluster->created_at))
                                                &bull; {{ Carbon::parse($cluster->created_at)->format('M d, Y') }}
                                            @endif
                                        </div>
                                    </td>
                                    <td style="text-align: center;">
                                        @if($formCode === 'nap2')
                                            <span class="form-badge form-badge-nap2" title="Form 2: Unverified Record Series">NAP 2</span>
                                        @elseif($formCode === 'nap3')
                                            <span class="form-badge form-badge-nap3" title="Form 3: Disposal Authority">NAP 3</span>
                                        @else
                                            <span class="form-badge form-badge-nap1" title="Form 1: Inventory Schedule">NAP 1</span>
                                        @endif
                                    </td>
                                    <td style="text-align: center;">
                                        <div style="font-size: 11.5px; font-weight: 600; color: #334155;">
                                            {{ $cluster->office ?: ($cluster->office_name ?: 'Office') }}
                                        </div>
                                    </td>
                                    <td style="text-align: center;">
                                        @if($isApproved)
                                            <span class="status-badge badge-approved">Approved</span>
                                        @elseif($isRejected)
                                            <span class="status-badge badge-rejected">{{ $statusName }}</span>
                                        @else
                                            <span class="status-badge badge-pending">Pending</span>
                                        @endif
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button type="button"
                                                wire:click="inspectCluster({{ (int)$cluster->cluster_id }}, '{{ $formCode }}')"
                                                class="nap-action-link"
                                                title="Quick inspect form"
                                                style="padding: 3px 8px; font-size: 11px;">
                                            <i class="fa-regular fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 40px 16px; color: #64748b;">
                                        <div style="font-size: 28px; margin-bottom: 8px;">📦</div>
                                        <div style="font-weight: 700; font-size: 13.5px; color: #334155;">No Created Forms Found</div>
                                        <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">Forms created for NAP Forms 1, 2, or 3 will appear here.</div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="rdp-card-footer">
                    <div style="font-size: 11.5px; color: #64748b; white-space: nowrap;">
                        @if($totalClustersCount > 0)
                            @php
                                $cFrom = ($clusterPage - 1) * $clusterPerPage + 1;
                                $cTo = min($totalClustersCount, $clusterPage * $clusterPerPage);
                            @endphp
                            <span>Showing {{ $cFrom }}–{{ $cTo }} of {{ $totalClustersCount }} forms</span>
                        @else
                            <span>Showing 0 forms</span>
                        @endif
                    </div>

                    @if($clusterMaxPage > 1)
                        <div class="rdp-pagination-bar">
                            <button type="button"
                                    wire:click="previousClusterPage"
                                    @if($clusterPage <= 1) disabled @endif
                                    class="rdp-page-btn {{ $clusterPage <= 1 ? 'disabled' : '' }}"
                                    title="Previous page">
                                <i class="fa-solid fa-chevron-left"></i>
                            </button>

                            @for($p = 1; $p <= $clusterMaxPage; $p++)
                                @if($clusterMaxPage <= 5 || abs($p - $clusterPage) <= 1 || $p == 1 || $p == $clusterMaxPage)
                                    @if($clusterMaxPage > 5 && $p == $clusterMaxPage && $clusterPage < $clusterMaxPage - 2)
                                        <span style="font-size: 10px; color: #94a3b8; padding: 0 1px;">...</span>
                                    @endif
                                    <button type="button"
                                            wire:click="gotoClusterPage({{ $p }})"
                                            class="rdp-page-btn {{ $clusterPage === $p ? 'active' : '' }}">
                                        {{ $p }}
                                    </button>
                                    @if($clusterMaxPage > 5 && $p == 1 && $clusterPage > 3)
                                        <span style="font-size: 10px; color: #94a3b8; padding: 0 1px;">...</span>
                                    @endif
                                @endif
                            @endfor

                            <button type="button"
                                    wire:click="nextClusterPage({{ $clusterMaxPage }})"
                                    @if($clusterPage >= $clusterMaxPage) disabled @endif
                                    class="rdp-page-btn {{ $clusterPage >= $clusterMaxPage ? 'disabled' : '' }}"
                                    title="Next page">
                                <i class="fa-solid fa-chevron-right"></i>
                            </button>
                        </div>
                    @endif

                    <a href="{{ route('rdp.pending.list') }}" style="font-size: 12px; font-weight: 700; color: #2563eb; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;">
                        Open Hub <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: RECEIVED DOCUMENT WITH DTS / DCS TABS (DTS DEFAULT) -->
        <div class="rdp-grid-col">
            <!-- TABS MOUNTED ABOVE THE BOX (MATCHES STATS PILL HEIGHT EXACTLY) -->
            <div class="rdp-tabs-header-wrapper">
                <div class="rdp-folder-tabs">
                    <button type="button"
                            wire:click="setReceivedTab('DCS')"
                            class="rdp-tab-btn {{ $receivedTab === 'DCS' ? 'active' : '' }}">
                        <i class="fa-solid fa-file-shield"></i>
                        <span>DCS</span>
                        <span style="background: rgba(37,99,235,0.12); padding: 1px 5px; border-radius: 8px; font-size: 9.5px;">
                            {{ $dcsCount }}
                        </span>
                    </button>
                    <button type="button"
                            wire:click="setReceivedTab('DTS')"
                            class="rdp-tab-btn {{ $receivedTab === 'DTS' ? 'active' : '' }}">
                        <i class="fa-solid fa-route"></i>
                        <span>DTS</span>
                        <span style="background: rgba(37,99,235,0.12); padding: 1px 5px; border-radius: 8px; font-size: 9.5px;">
                            {{ $dtsCount }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- RECEIVED DOCUMENT BOX -->
            <div class="rdp-card-box" style="border-top-right-radius: 0;">
                <div class="rdp-card-header">
                    <div>
                        <h2 class="rdp-card-title">
                            <i class="fa-solid fa-inbox" style="color: #0284c7;"></i>
                            Received Document
                            <span style="font-size: 10.5px; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 2px 7px; border-radius: 10px; margin-left: 4px;">
                                {{ $receivedTab === 'DTS' ? 'DTS' : 'DCS' }}
                            </span>
                        </h2>
                        <span style="font-size: 11.5px; color: #64748b;">
                            From {{ $receivedTab === 'DTS' ? 'Document Tracking System' : 'Document Control System' }}
                        </span>
                    </div>

                    <div style="display: flex; gap: 6px; align-items: center;">
                        <input type="text"
                               wire:model.live.debounce.300ms="receivedSearch"
                               placeholder="Search doc..."
                               class="rdp-search-input"
                               style="width: 120px; padding: 5px 8px; font-size: 11.5px;">
                    </div>
                </div>

                <!-- Received Documents Table -->
                <div style="overflow-x: auto; flex: 1;">
                    <table class="rdp-compact-table">
                        <thead>
                            <tr>
                                <th style="width: 70px;">Doc Code</th>
                                <th style="min-width: 120px;">TITLE</th>
                                <th style="width: 45px; text-align: center;">Origin</th>
                                <th style="width: 68px; text-align: center;">Date</th>
                                <th style="width: 58px; text-align: center;">Status</th>
                                <th style="width: 48px; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($receivedDocs as $doc)
                                @php
                                    $st = strtolower($doc->status ?? 'pending');
                                @endphp
                                <tr>
                                    <td>
                                        <span style="font-family: monospace; font-size: 10.5px; font-weight: 800; color: #1e40af; background: #eff6ff; padding: 1px 4px; border-radius: 3px; border: 1px solid #bfdbfe; white-space: nowrap;">
                                            {{ $doc->document_code }}
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: #0f172a; line-height: 1.3; font-size: 12px; word-break: break-word;" title="{{ $doc->document_title }}">
                                            {{ $doc->document_title }}
                                        </div>
                                        @if(!empty($doc->description))
                                            <div style="font-size: 10.5px; color: #64748b; line-height: 1.3; margin-top: 2px; word-break: break-word;">
                                                {{ $doc->description }}
                                            </div>
                                        @endif
                                    </td>
                                    <td style="text-align: center;">
                                        <span style="font-size: 11px; font-weight: 600; color: #475569;">
                                            {{ $doc->origin_office ?: '—' }}
                                        </span>
                                    </td>
                                    <td style="font-size: 11px; color: #475569; white-space: nowrap; text-align: center;">
                                        {{ $doc->date_received ? Carbon::parse($doc->date_received)->format('M d, \'y') : '—' }}
                                    </td>
                                    <td style="text-align: center;">
                                        @if($st === 'appraised')
                                            <span class="status-badge badge-appraised">Appraised</span>
                                        @elseif($st === 'dismissed')
                                            <span class="status-badge badge-dismissed">Dismissed</span>
                                        @else
                                            <span class="status-badge badge-pending">Pending</span>
                                        @endif
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <a href="{{ $receivedTab === 'DTS' ? route('rdp.received-documents.dts') : route('rdp.received-documents.dcs') }}"
                                           class="nap-action-link"
                                           style="padding: 3px 7px; font-size: 10.5px;"
                                           title="Appraise or inspect in Received Documents Hub">
                                            Process ➔
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 40px 16px; color: #64748b;">
                                        <div style="font-size: 28px; margin-bottom: 8px;">📥</div>
                                        <div style="font-weight: 700; font-size: 13.5px; color: #334155;">No Received {{ $receivedTab }} Documents</div>
                                        <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">
                                            Documents submitted from {{ $receivedTab === 'DTS' ? 'Document Tracking System' : 'Document Control System' }} will be listed here.
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="rdp-card-footer">
                    <div style="font-size: 11.5px; color: #64748b; white-space: nowrap;">
                        @if($totalReceivedDocs > 0)
                            @php
                                $rFrom = ($receivedPage - 1) * $receivedPerPage + 1;
                                $rTo = min($totalReceivedDocs, $receivedPage * $receivedPerPage);
                            @endphp
                            <span>Showing {{ $rFrom }}–{{ $rTo }} of {{ $totalReceivedDocs }} documents</span>
                        @else
                            <span>Showing 0 documents</span>
                        @endif
                    </div>

                    @if($receivedMaxPage > 1)
                        <div class="rdp-pagination-bar">
                            <button type="button"
                                    wire:click="previousReceivedPage"
                                    @if($receivedPage <= 1) disabled @endif
                                    class="rdp-page-btn {{ $receivedPage <= 1 ? 'disabled' : '' }}"
                                    title="Previous page">
                                <i class="fa-solid fa-chevron-left"></i>
                            </button>

                            @for($p = 1; $p <= $receivedMaxPage; $p++)
                                @if($receivedMaxPage <= 5 || abs($p - $receivedPage) <= 1 || $p == 1 || $p == $receivedMaxPage)
                                    @if($receivedMaxPage > 5 && $p == $receivedMaxPage && $receivedPage < $receivedMaxPage - 2)
                                        <span style="font-size: 10px; color: #94a3b8; padding: 0 1px;">...</span>
                                    @endif
                                    <button type="button"
                                            wire:click="gotoReceivedPage({{ $p }})"
                                            class="rdp-page-btn {{ $receivedPage === $p ? 'active' : '' }}">
                                        {{ $p }}
                                    </button>
                                    @if($receivedMaxPage > 5 && $p == 1 && $receivedPage > 3)
                                        <span style="font-size: 10px; color: #94a3b8; padding: 0 1px;">...</span>
                                    @endif
                                @endif
                            @endfor

                            <button type="button"
                                    wire:click="nextReceivedPage({{ $receivedMaxPage }})"
                                    @if($receivedPage >= $receivedMaxPage) disabled @endif
                                    class="rdp-page-btn {{ $receivedPage >= $receivedMaxPage ? 'disabled' : '' }}"
                                    title="Next page">
                                <i class="fa-solid fa-chevron-right"></i>
                            </button>
                        </div>
                    @endif

                    <a href="{{ $receivedTab === 'DTS' ? route('rdp.received-documents.dts') : route('rdp.received-documents.dcs') }}"
                       style="font-size: 12px; font-weight: 700; color: #0284c7; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;">
                        Open {{ $receivedTab }} Intake Hub <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>

    </div>

    <!-- 3. BOTTOM FULL-WIDTH: LIST NAP FORM 3 (LAYOUT MATCHING REPORT NAP FORM 3) -->
    <div class="rdp-bottom-box" x-data="{
        collapsedRoots: {},
        collapsedSubjects: {},
        isRootCollapsed(id) { return !!this.collapsedRoots[id]; },
        toggleRoot(id) { this.collapsedRoots[id] = !this.collapsedRoots[id]; },
        isSubjectsCollapsed(id) { return !!this.collapsedSubjects[id]; },
        toggleSubjects(id) { this.collapsedSubjects[id] = !this.collapsedSubjects[id]; },
        expandAll() { this.collapsedRoots = {}; this.collapsedSubjects = {}; },
        collapseAll() {
            @foreach($nap3Tree as $root)
                this.collapsedRoots['root-{{ $root->id }}'] = true;
                @foreach($root->sub_series as $sub)
                    this.collapsedSubjects['sub-{{ $sub->id }}'] = true;
                @endforeach
                this.collapsedSubjects['root-{{ $root->id }}'] = true;
            @endforeach
        }
    }">
        <div class="rdp-card-header">
            <div>
                <h2 class="rdp-card-title">
                    <i class="fa-solid fa-file-circle-check" style="color: #059669;"></i>
                    List NAP Form 3
                    <span style="font-size: 11.5px; font-weight: 800; color: #065f46; background: #d1fae5; padding: 2px 9px; border-radius: 12px; margin-left: 6px; border: 1px solid #a7f3d0;">
                        Newly Transferred &amp; Disposal Authorization Queue
                    </span>
                </h2>
                <span style="font-size: 12.5px; color: #64748b;">
                    Official hierarchical matrix of records eligible for National Archives of the Philippines disposition clearance.
                </span>
            </div>

            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <div style="display: inline-flex; gap: 4px; align-items: center;">
                    <button type="button" @click="expandAll()" class="chip-filter" style="font-size: 11px; padding: 4px 8px; display: inline-flex; align-items: center; gap: 4px;" title="Expand all series to show subjects">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        Expand All
                    </button>
                    <button type="button" @click="collapseAll()" class="chip-filter" style="font-size: 11px; padding: 4px 8px; display: inline-flex; align-items: center; gap: 4px;" title="Collapse all series to show only compilation totals">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transform: rotate(-90deg);"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        Collapse All
                    </button>
                </div>

                <input type="text"
                       wire:model.live.debounce.300ms="nap3Search"
                       placeholder="Search NAP 3 series, subject, location..."
                       class="rdp-search-input"
                       style="width: 230px;">

                <a href="{{ route('rdp.reports.nap-form-3') }}" class="nap-action-link" style="background: #059669; color: #ffffff; border: none; padding: 7px 14px; font-size: 12px;">
                    <i class="fa-solid fa-print"></i> Open NAP Form 3 Hub ➔
                </a>
            </div>
        </div>

        <!-- MAIN HIERARCHICAL NAP FORM 3 TABLE (MATCHING REPORT NAP FORM 3 5-COLUMN MATRIX) -->
        <div style="overflow-x: auto;">
            <table class="rdp-compact-table" style="border: 1px solid #cbd5e1; border-radius: 8px;">
                <thead>
                    <tr style="background: #f8fafc;">
                        <th style="width: 100px; text-align: center; border-right: 1px solid #e2e8f0;">GRDS/ RDS ITEM NO.</th>
                        <th style="min-width: 320px; border-right: 1px solid #e2e8f0;">RECORD SERIES TITLE AND DESCRIPTION</th>
                        <th style="width: 160px; text-align: center; border-right: 1px solid #e2e8f0;">PERIOD COVERED</th>
                        <th style="width: 240px; text-align: center; border-right: 1px solid #e2e8f0;">RETENTION PERIOD AND PROVISION/S COMPLIED (If Any)</th>
                        <th style="width: 90px; text-align: right;">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($nap3Tree as $root)
                        <!-- ROOT SERIES ROW -->
                        <tr class="root-series-row">
                            <td style="text-align: center; font-weight: 800; font-size: 13.5px; color: #1e293b; border-right: 1px solid #e2e8f0;">
                                <span class="nap-item-pill">
                                    {{ $root->item_number ?: '—' }}
                                </span>
                            </td>
                            <td style="font-weight: 800; font-size: 13.5px; color: #0f172a; letter-spacing: 0.3px; border-right: 1px solid #e2e8f0;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    @if($root->has_children)
                                        <button type="button" @click.stop="toggleRoot('root-{{ $root->id }}')" class="nap-chevron-btn" :style="isRootCollapsed('root-{{ $root->id }}') ? 'transform: rotate(-90deg);' : 'transform: rotate(0deg);'">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                        </button>
                                        <span @click="toggleRoot('root-{{ $root->id }}')" style="cursor: pointer;" title="Click to collapse/expand group">{{ $root->series_title }}</span>
                                        <span style="font-size: 11px; font-weight: 600; color: #64748b;">({{ count($root->sub_series) }} sub, {{ $root->total_records }} subjects)</span>
                                    @else
                                        <button type="button" @click.stop="toggleSubjects('root-{{ $root->id }}')" class="nap-chevron-btn" :style="isSubjectsCollapsed('root-{{ $root->id }}') ? 'transform: rotate(-90deg);' : 'transform: rotate(0deg);'">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                        </button>
                                        <span @click="toggleSubjects('root-{{ $root->id }}')" style="cursor: pointer;" title="Click to collapse/expand subjects">{{ $root->series_title }}</span>
                                        <span style="font-size: 11px; font-weight: 600; color: #64748b;">({{ $root->total_records }} {{ $root->total_records === 1 ? 'subject' : 'subjects' }})</span>
                                    @endif
                                    @if($root->shorted_type)
                                        <span style="font-size: 11px; padding: 1px 6px; border-radius: 4px; background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; font-weight: 700;">
                                            {{ $root->shorted_type }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            @if(!$root->has_children)
                                <td style="text-align: center; font-weight: 600; color: #334155; font-size: 12px; border-right: 1px solid #e2e8f0;">{{ $root->compiled_period }}</td>
                                <td style="text-align: center; font-size: 12px; font-weight: 700; color: #0f172a; border-right: 1px solid #e2e8f0;">
                                    {{ $root->total_period }}
                                    @if($root->remarks)
                                        <span style="font-weight: normal; color: #64748b; font-size: 11px; display: block;">{{ $root->remarks }}</span>
                                    @endif
                                </td>
                            @else
                                <td style="text-align: center; color: #94a3b8; font-size: 12px; border-right: 1px solid #e2e8f0;">—</td>
                                <td style="text-align: center; color: #94a3b8; font-size: 12px; border-right: 1px solid #e2e8f0;">—</td>
                            @endif
                            <td style="text-align: right; white-space: nowrap; padding-right: 14px;">
                                <a href="{{ route('rdp.reports.nap-form-3') }}" class="nap-action-link" style="color: #059669; background: #ecfdf5; border-color: #a7f3d0; font-size: 11px; padding: 3px 8px;">
                                    Inspect in NAP 3 ➔
                                </a>
                            </td>
                        </tr>

                        <!-- SUB-SERIES ROWS (IF ANY) -->
                        @if($root->has_children)
                            @foreach($root->sub_series as $sub)
                                <tr class="sub-series-row" x-show="!isRootCollapsed('root-{{ $root->id }}')">
                                    <td style="text-align: center; color: #94a3b8; font-size: 12px; border-right: 1px solid #e2e8f0;">—</td>
                                    <td style="padding-left: 20px; font-weight: 700; color: #0f172a; border-right: 1px solid #e2e8f0;">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <button type="button" @click.stop="toggleSubjects('sub-{{ $sub->id }}')" class="nap-chevron-btn" :style="isSubjectsCollapsed('sub-{{ $sub->id }}') ? 'transform: rotate(-90deg);' : 'transform: rotate(0deg);'">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                            </button>
                                            <span class="corner-symbol">└</span>
                                            <span @click="toggleSubjects('sub-{{ $sub->id }}')" style="cursor: pointer;" title="Click to collapse/expand subjects">{{ $sub->series_title }}</span>
                                            <span style="font-size: 11px; font-weight: 600; color: #64748b;">({{ $sub->records_count }})</span>
                                        </div>
                                    </td>
                                    <td style="text-align: center; font-weight: 600; color: #1e293b; font-size: 12px; border-right: 1px solid #e2e8f0;">{{ $sub->compiled_period }}</td>
                                    <td style="text-align: center; font-size: 12px; font-weight: 700; color: #0f172a; border-right: 1px solid #e2e8f0;">
                                        {{ $sub->total_period }}
                                        @php $subRem = $sub->remarks ?: $root->remarks; @endphp
                                        @if($subRem)
                                            <span style="font-weight: normal; color: #64748b; font-size: 11px; display: block;">{{ $subRem }}</span>
                                        @endif
                                    </td>
                                    <td style="text-align: right; white-space: nowrap; padding-right: 14px;">
                                        <span style="color: #94a3b8; font-size: 11px;">—</span>
                                    </td>
                                </tr>

                                <!-- CHILD RECORDS (SUBJECTS) UNDER THIS SUB-SERIES -->
                                @foreach($sub->records as $rec)
                                    <tr class="record-item-row" x-show="!isRootCollapsed('root-{{ $root->id }}') && !isSubjectsCollapsed('sub-{{ $sub->id }}')">
                                        <td style="text-align: center; color: #94a3b8; font-size: 11px; border-right: 1px solid #f1f5f9;">—</td>
                                        <td style="padding-left: 48px; border-right: 1px solid #f1f5f9;">
                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                                <div style="display: flex; align-items: center; gap: 6px;">
                                                    <span class="sub-branch-line">│</span>
                                                    <span style="font-weight: 600; color: #1e293b; font-size: 12.5px;">{{ $rec->description }}</span>
                                                </div>
                                                @if($rec->volume || $rec->location)
                                                    <div style="display: inline-flex; gap: 6px; font-size: 10.5px; color: #64748b; background: #f1f5f9; padding: 2px 7px; border-radius: 4px; white-space: nowrap;">
                                                        @if($rec->volume)<span><i class="fa-solid fa-boxes-stacked" style="font-size: 9.5px; margin-right: 2px;"></i>{{ $rec->volume }}</span>@endif
                                                        @if($rec->volume && $rec->location)<span style="color: #cbd5e1;">•</span>@endif
                                                        @if($rec->location)<span><i class="fa-solid fa-location-dot" style="font-size: 9.5px; margin-right: 2px;"></i>{{ $rec->location }}</span>@endif
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                        <td style="text-align: center; color: #475569; font-size: 12px; white-space: nowrap; border-right: 1px solid #f1f5f9;">{{ $rec->date_covered }}</td>
                                        <td style="text-align: center; color: #cbd5e1; border-right: 1px solid #f1f5f9;">—</td>
                                        <td style="text-align: right; white-space: nowrap; padding-right: 14px;">
                                            <a href="{{ route('rdp.reports.nap-form-3') }}" class="nap-action-link" style="padding: 3px 8px; font-size: 11px;">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        @else
                            <!-- DIRECT CHILD RECORDS (SUBJECTS) UNDER ROOT SERIES -->
                            @foreach($root->direct_records as $rec)
                                <tr class="record-item-row" x-show="!isSubjectsCollapsed('root-{{ $root->id }}')">
                                    <td style="text-align: center; color: #94a3b8; font-size: 11px; border-right: 1px solid #f1f5f9;">—</td>
                                    <td style="padding-left: 28px; border-right: 1px solid #f1f5f9;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <span class="sub-branch-line">│</span>
                                                <span style="font-weight: 600; color: #1e293b; font-size: 12.5px;">{{ $rec->description }}</span>
                                            </div>
                                            @if($rec->volume || $rec->location)
                                                <div style="display: inline-flex; gap: 6px; font-size: 10.5px; color: #64748b; background: #f1f5f9; padding: 2px 7px; border-radius: 4px; white-space: nowrap;">
                                                    @if($rec->volume)<span><i class="fa-solid fa-boxes-stacked" style="font-size: 9.5px; margin-right: 2px;"></i>{{ $rec->volume }}</span>@endif
                                                    @if($rec->volume && $rec->location)<span style="color: #cbd5e1;">•</span>@endif
                                                    @if($rec->location)<span><i class="fa-solid fa-location-dot" style="font-size: 9.5px; margin-right: 2px;"></i>{{ $rec->location }}</span>@endif
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                    <td style="text-align: center; color: #475569; font-size: 12px; white-space: nowrap; border-right: 1px solid #f1f5f9;">{{ $rec->date_covered }}</td>
                                    <td style="text-align: center; color: #cbd5e1; border-right: 1px solid #f1f5f9;">—</td>
                                    <td style="text-align: right; white-space: nowrap; padding-right: 14px;">
                                        <a href="{{ route('rdp.reports.nap-form-3') }}" class="nap-action-link" style="padding: 3px 8px; font-size: 11px;">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    @empty
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 48px 16px; color: #64748b;">
                                <div style="font-size: 32px; margin-bottom: 8px;">📋</div>
                                <div style="font-weight: 700; font-size: 14px; color: #334155;">No Records Transferred to NAP Form 3 Yet</div>
                                <div style="font-size: 12.5px; color: #94a3b8; margin-top: 4px; max-width: 480px; margin-left: auto; margin-right: auto;">
                                    When records exceed their retention period or are marked for disposition authority in Inventory &amp; Appraisal, they will be grouped here matching the official NAP Form 3 hierarchy.
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; padding-top: 14px; border-top: 1px solid #f1f5f9;">
            <span style="font-size: 12px; color: #64748b;">
                Showing {{ count($nap3Tree) }} record series with {{ number_format($totalNewlyNap3) }} items in NAP Form 3 Queue
            </span>
            <a href="{{ route('rdp.reports.nap-form-3') }}" style="font-size: 12.5px; font-weight: 700; color: #059669; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                Manage All Disposal Records in NAP Form 3 Hub <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </div>

    <!-- QUICK ADD NEW RECORD SERIES MODAL -->
    @if($showAddSeriesModal)
        <div class="modal-overlay" wire:click.self="closeAddSeriesModal">
            <div class="modal-dialog">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;">Add New Record Series</h3>
                    <button type="button" wire:click="closeAddSeriesModal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <form wire:submit.prevent="saveNewSeries" style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Series Title</label>
                        <input type="text" wire:model="newSeriesTitle" placeholder="e.g. Budget Estimates, Travel Orders" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;" required>
                    </div>

                    <!-- Dynamic Subsections (Hierarchy) -->
                    @foreach($newSubsections as $index => $sub)
                        <div style="margin-left: {{ min(($index + 1) * 16, 64) }}px; border-left: 3px solid #2563eb; padding-left: 10px; display: flex; gap: 8px; align-items: center;">
                            <input type="text"
                                   wire:model.live="newSubsections.{{ $index }}"
                                   placeholder="Subsection #{{ $index + 1 }}"
                                   style="flex: 1; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                            <button type="button" wire:click="removeSubsection({{ $index }})" style="background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; border-radius: 6px; padding: 6px 10px; font-weight: 700; cursor: pointer; font-size: 12px;">✕</button>
                        </div>
                    @endforeach

                    <button type="button" wire:click="addSubsection" style="align-self: flex-start; background: #eff6ff; color: #2563eb; border: 1px dashed #93c5fd; border-radius: 8px; padding: 6px 14px; font-size: 12.5px; font-weight: 700; cursor: pointer;">
                        + Add Subsection (Child Series)
                    </button>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Active Period</label>
                            <input type="text" wire:model="newActivePeriod" placeholder="e.g. 2 Years" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;" @if($newIsPermanent) disabled @endif>
                        </div>
                        <div>
                            <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Storage Period</label>
                            <input type="text" wire:model="newStoragePeriod" placeholder="e.g. 3 Years" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;" @if($newIsPermanent) disabled @endif>
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="newIsPermanent" wire:model.live="newIsPermanent" style="width: 16px; height: 16px; accent-color: #2563eb; cursor: pointer;">
                        <label for="newIsPermanent" style="font-size: 13px; font-weight: 700; color: #dc2626; cursor: pointer;">
                            Permanent Record Series (No destruction allowed)
                        </label>
                    </div>

                    <div>
                        <label style="font-size: 13px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">Remarks / Notes</label>
                        <textarea wire:model="newRemarks" rows="2" placeholder="Optional disposition provisions, remarks..." style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;"></textarea>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px;">
                        <button type="button" wire:click="closeAddSeriesModal" style="padding: 8px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #ffffff; color: #334155; font-weight: 700; font-size: 12.5px; cursor: pointer;">
                            Cancel
                        </button>
                        <button type="submit" style="padding: 8px 18px; border-radius: 8px; border: none; background: #2563eb; color: #ffffff; font-weight: 700; font-size: 12.5px; cursor: pointer;">
                            Create Series
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- QUICK CLUSTER INSPECT MODAL -->
    @if($showClusterModal && $selectedCluster)
        <div class="modal-overlay" wire:click.self="closeClusterModal">
            <div class="modal-dialog">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <div>
                        <h3 style="margin: 0 0 4px 0; font-size: 18px; font-weight: 800; color: #0f172a;">
                            {{ $selectedCluster->cluster_name }}
                        </h3>
                        <div style="font-size: 12px; color: #64748b;">
                            {{ $selectedCluster->form_label ?? 'NAP Form' }} &bull; {{ $selectedCluster->office_name ?: ($selectedCluster->office ?: 'Office') }}
                        </div>
                    </div>
                    <button type="button" wire:click="closeClusterModal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">✕</button>
                </div>

                <div style="margin-bottom: 16px;">
                    <h4 style="font-size: 13px; font-weight: 800; color: #334155; margin: 0 0 8px 0;">Included Records / Series ({{ count($selectedClusterItems) }})</h4>
                    <div style="max-height: 280px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px;">
                        <table class="rdp-compact-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Title / Description</th>
                                    <th>Volume / Location</th>
                                    <th>Retention</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($selectedClusterItems as $it)
                                    <tr>
                                        <td style="font-weight: 800; text-align: center;">{{ $it->item_number ?? '—' }}</td>
                                        <td>
                                            <div style="font-weight: 700; color: #0f172a;">{{ $it->series_title }}</div>
                                            @if(!empty($it->description))
                                                <div style="font-size: 11px; color: #64748b;">{{ $it->description }}</div>
                                            @endif
                                        </td>
                                        <td style="font-size: 11.5px; color: #475569;">
                                            {{ $it->volume ?? '—' }}
                                            @if(!empty($it->records_location))
                                                <br><span style="color: #94a3b8;">{{ $it->records_location }}</span>
                                            @endif
                                        </td>
                                        <td style="font-size: 11.5px; font-weight: 700; color: #1e3a8a;">
                                            {{ $it->total_period ?? '—' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" style="text-align: center; padding: 20px; color: #64748b;">
                                            No item details found for this form.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                    <a href="{{ route('rdp.pending.list') }}" style="font-size: 12.5px; font-weight: 700; color: #2563eb; text-decoration: none;">
                        Open in Full Pending List ➔
                    </a>
                    <button type="button" wire:click="closeClusterModal" style="padding: 8px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #ffffff; color: #334155; font-weight: 700; font-size: 12.5px; cursor: pointer;">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
