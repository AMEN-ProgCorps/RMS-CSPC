<?php
/**
 * Admin Console - Accounts Management Offices & Clusters Volt Component
 * 
 * ESXi File Manager / Miller Columns Inspired Organizational Browser.
 * Features:
 *  - 4-Column Miller Columns browser (Clusters -> Offices Directory -> Operations & Personnel -> Inspector)
 *  - Integrated mode switcher: Internal College Offices vs. External Other Offices (DTS Source Agencies)
 *  - Live personnel directory, cloud storage metrics, and RDP retention brackets per office
 *  - External agency incoming transaction logs and top receiving internal offices
 *  - TXT batch file import and export with syntax verification
 *  - Soft-deactivation and audit logging for system transparency
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Str;

new #[Layout('layouts.admin')] #[Title('Admin Console - Offices & Organizational Units')] class extends Component {
    use \Livewire\WithPagination;

    /** @var string View layout mode: 'default' (Classic) or 'horizontal' (ESXi Miller Columns) */
    public string $layoutMode = 'horizontal';

    /** @var string Classic layout directory view mode ('table' or 'grid') */
    public string $officeViewMode = 'table';

    /** @var string Classic layout sorting column */
    public string $officeSortBy = 'name';

    /** @var string Classic layout sorting direction ('asc' or 'desc') */
    public string $officeSortDir = 'asc';

    public string $otherOfficeSortBy = 'name';
    public string $otherOfficeSortDir = 'asc';

    public array $selectedOfficeIds = [];
    public bool $selectAllOffices = false;

    use WithFileUploads;

    /** @var string Active scope: 'offices' (Internal College Units) or 'other_offices' (External Source Agencies) */
    public string $activeTab = 'offices';

    // ---- CLUSTER FILTERING & SELECTION ----
    public ?string $selectedClusterCode = null; // null = All Clusters
    public string $clusterSearch = '';
    public ?int $selectedClusterId = null;

    // Cluster Form fields
    public string $clusterName = '';
    public string $clusterCode = '';
    public string $clusterHead = '';
    public bool $clusterIsActive = true;
    public $clusterFile;

    // ---- INTERNAL OFFICES PROPERTIES ----
    public string $search = '';
    public ?int $selectedOfficeId = null;
    public string $officeOperationsTab = 'personnel'; // 'personnel' | 'storage' | 'brackets'
    public $officeFile;

    // Office Form fields
    public string $officeName = '';
    public string $officeCode = '';
    public string $officeCluster = '';
    public bool $isActive = true;

    // ---- EXTERNAL OTHER OFFICES PROPERTIES ----
    public string $otherOfficeSearch = '';
    public string $otherOfficeFilter = 'all'; // 'all' | 'active' | 'inactive'
    public ?int $selectedOtherOfficeId = null;

    // Other Office Form fields
    public string $otherOfficeName = '';
    public string $otherOfficeCode = '';
    public bool $otherOfficeIsActive = true;
    public string $otherOfficeCreatedBy = '';

    // Searchable dropdown / helper states
    public string $clusterHeadSearch = '';
    public bool $showClusterHeadDropdown = false;
    public string $officeClusterSearch = '';
    public bool $showOfficeClusterDropdown = false;

    // Modal States
    public bool $showImportModal = false;
    public string $importTarget = 'office'; // 'office' | 'cluster'

    // Toast notifications
    public string $successMessage = '';
    public string $errorMessage = '';

    /**
     * Component mount hook - initializes authorization.
     */
    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_sadm_modify_accountlist)) {
            $this->redirect(route('portal'));
            return;
        }
        $this->layoutMode = session('admin_offices_layout_mode', session('admin_management_layout_mode', 'horizontal'));
        $this->cancelSelection();
    }

    /**
     * Sets the active layout mode.
     *
     * @param string $mode
     *
     * @return void
     */
    public function setLayoutMode(string $mode): void
    {
        if (in_array($mode, ['default', 'horizontal'])) {
            $this->layoutMode = $mode;
            session(['admin_offices_layout_mode' => $mode]);
            session(['admin_management_layout_mode' => $mode]);
        }
    }

    /**
     * Toggles the office sort dir setting or state.
     * @return void
     */
    public function toggleOfficeSortDir(): void
    {
        $this->officeSortDir = $this->officeSortDir === 'asc' ? 'desc' : 'asc';
    }

    /**
     * Toggles the other office sort dir setting or state.
     * @return void
     */
    public function toggleOtherOfficeSortDir(): void
    {
        $this->otherOfficeSortDir = $this->otherOfficeSortDir === 'asc' ? 'desc' : 'asc';
    }

    /**
     * Lifecycle hook executed before updating the search property.
     * @return void
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Lifecycle hook executed before updating the other office search property.
     * @return void
     */
    public function updatingOtherOfficeSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Initializes create office mode and resets form inputs.
     * @return void
     */
    public function startCreateOffice(): void
    {
        $this->startCreate();
    }

    /**
     * Toggles the office selection setting or state.
     *
     * @param int $id
     *
     * @return void
     */
    public function toggleOfficeSelection(int $id): void
    {
        if (in_array($id, $this->selectedOfficeIds)) {
            $this->selectedOfficeIds = array_diff($this->selectedOfficeIds, [$id]);
        } else {
            $this->selectedOfficeIds[] = $id;
        }
    }

    /**
     * Toggles the select all offices setting or state.
     * @return void
     */
    public function toggleSelectAllOffices(): void
    {
        if ($this->selectAllOffices) {
            $this->selectedOfficeIds = [];
            $this->selectAllOffices = false;
        } else {
            $this->selectedOfficeIds = \App\Models\office::where('is_active', true)
                ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]', 'HUB'])
                ->pluck('id')
                ->toArray();
            $this->selectAllOffices = true;
        }
    }

    /**
     * Deletes or deactivates the specified selected offices.
     * @return void
     */
    public function deleteSelectedOffices(): void
    {
        if (empty($this->selectedOfficeIds)) {
            return;
        }
        \App\Models\office::whereIn('id', $this->selectedOfficeIds)->update(['is_active' => false]);
        $this->selectedOfficeIds = [];
        $this->selectAllOffices = false;
        $this->successMessage = 'Selected offices have been deactivated.';
    }

    /**
     * Resets messages and clears active selections.
     * @return void
     */
    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * Sets the active tab.
     *
     * @param string $tab
     *
     * @return void
     */
    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->cancelSelection();
    }

    /**
     * Resets selection and clears active selections.
     * @return void
     */
    public function cancelSelection(): void
    {
        $this->selectedOfficeId = null;
        $this->officeName = '';
        $this->officeCode = '';
        $this->officeCluster = '';
        $this->isActive = true;
        $this->officeFile = null;
        $this->officeClusterSearch = '';
        $this->showOfficeClusterDropdown = false;
        $this->clusterHeadSearch = '';
        $this->showClusterHeadDropdown = false;
        $this->officeOperationsTab = 'personnel';

        $this->selectedClusterId = null;
        $this->clusterName = '';
        $this->clusterCode = '';
        $this->clusterHead = '';
        $this->clusterIsActive = true;
        $this->clusterFile = null;

        $this->selectedOtherOfficeId = null;
        $this->otherOfficeName = '';
        $this->otherOfficeCode = '';
        $this->otherOfficeIsActive = true;
        $this->otherOfficeCreatedBy = '';

        $this->showImportModal = false;
        $this->clearMessages();
    }

    // ==========================================
    // CLUSTER ACTIONS
    // ==========================================
    public function filterByCluster(?string $code): void
    {
        $this->selectedClusterCode = $code;
        $this->selectedOfficeId = null;
        $this->selectedClusterId = null;
        $this->clearMessages();
    }

    /**
     * Initializes create cluster mode and resets form inputs.
     * @return void
     */
    public function startCreateCluster(): void
    {
        $this->cancelSelection();
        $this->selectedClusterId = -1;
        $this->clusterName = 'New Cluster Division';
        $this->clusterCode = '';
        $this->clusterHead = '';
        $this->clusterIsActive = true;
    }

    /**
     * Selects cluster for edit and populates the details panel.
     *
     * @param int $id
     *
     * @return void
     */
    public function selectClusterForEdit(int $id): void
    {
        $this->cancelSelection();
        $this->selectedClusterId = $id;

        $cluster = \App\Models\Cluster::find($id);
        if ($cluster) {
            $this->clusterName = $cluster->cluster_name;
            $this->clusterCode = $cluster->cluster_code;
            $this->clusterHead = $cluster->cluster_head ?? '';
            $this->clusterIsActive = (bool) $cluster->is_active;
            if ($this->clusterHead) {
                $headOffice = \App\Models\office::where('office_code', $this->clusterHead)->first();
                $this->clusterHeadSearch = $headOffice ? $headOffice->office_name . ' (' . $headOffice->office_code . ')' : $this->clusterHead;
            } else {
                $this->clusterHeadSearch = '';
            }
        }
    }

    /**
     * Selects cluster head and populates the details panel.
     *
     * @param string $code
     * @param string $name
     *
     * @return void
     */
    public function selectClusterHead(string $code, string $name): void
    {
        $this->clusterHead = $code;
        $this->clusterHeadSearch = $code ? "$name ($code)" : '';
        $this->showClusterHeadDropdown = false;
    }

    /**
     * Lifecycle hook executed after updating the cluster head search property.
     * @return void
     */
    public function updatedClusterHeadSearch(): void
    {
        $this->showClusterHeadDropdown = true;
    }

    /**
     * Lifecycle hook executed after updating the office cluster search property.
     * @return void
     */
    public function updatedOfficeClusterSearch(): void
    {
        $this->showOfficeClusterDropdown = true;
    }

    /**
     * Persists cluster changes to the database.
     * @return void
     */
    public function saveClusterChanges(): void
    {
        if ($this->selectedClusterId === null) return;

        $this->clearMessages();

        $nameExcludeId = null;
        $codeExcludeId = null;

        if ($this->selectedClusterId === -1) {
            $deactivatedByName = \App\Models\Cluster::where('cluster_name', trim($this->clusterName))->where('is_active', false)->first();
            $deactivatedByCode = \App\Models\Cluster::where('cluster_code', trim($this->clusterCode))->where('is_active', false)->first();
            $nameExcludeId = $deactivatedByName ? $deactivatedByName->id : null;
            $codeExcludeId = $deactivatedByCode ? $deactivatedByCode->id : null;
        }

        $clusterTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_cluster') ? 'sys_cluster' : 'cluster';
        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        $uniqueNameRule = $this->selectedClusterId > 0 
            ? "unique:{$clusterTbl},cluster_name," . $this->selectedClusterId . ',id'
            : ($nameExcludeId ? "unique:{$clusterTbl},cluster_name," . $nameExcludeId . ',id' : "unique:{$clusterTbl},cluster_name");

        $uniqueCodeRule = $this->selectedClusterId > 0 
            ? "unique:{$clusterTbl},cluster_code," . $this->selectedClusterId . ',id'
            : ($codeExcludeId ? "unique:{$clusterTbl},cluster_code," . $codeExcludeId . ',id' : "unique:{$clusterTbl},cluster_code");

        $this->validate([
            'clusterName' => 'required|string|max:255|' . $uniqueNameRule,
            'clusterCode' => 'required|string|max:50|' . $uniqueCodeRule,
            'clusterHead' => "nullable|string|exists:{$officeTbl},office_code",
        ]);

        try {
            \DB::transaction(function () {
                if ($this->selectedClusterId === -1) {
                    $existingCluster = \App\Models\Cluster::where('cluster_code', trim($this->clusterCode))->where('is_active', false)->first();

                    if ($existingCluster) {
                        $existingCluster->update([
                            'cluster_name' => $this->clusterName,
                            'cluster_code' => $this->clusterCode,
                            'cluster_head' => $this->clusterHead ?: null,
                            'is_active' => true,
                        ]);
                        \DB::table('sys_admin_logs')->insert([
                            'changes' => "Reactivated cluster: {$this->clusterName} ({$this->clusterCode})",
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now(),
                        ]);
                        $this->selectedClusterId = $existingCluster->id;
                    } else {
                        $cluster = new \App\Models\Cluster();
                        $cluster->cluster_name = $this->clusterName;
                        $cluster->cluster_code = $this->clusterCode;
                        $cluster->cluster_head = $this->clusterHead ?: null;
                        $cluster->is_active = true;
                        $cluster->save();

                        \DB::table('sys_admin_logs')->insert([
                            'changes' => "Created cluster: {$this->clusterName} ({$this->clusterCode})",
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now()
                        ]);
                        $this->selectedClusterId = $cluster->id;
                    }
                    $this->successMessage = 'Cluster created successfully!';
                } else {
                    $cluster = \App\Models\Cluster::findOrFail($this->selectedClusterId);
                    $oldCode = $cluster->cluster_code;

                    $cluster->update([
                        'cluster_name' => $this->clusterName,
                        'cluster_code' => $this->clusterCode,
                        'cluster_head' => $this->clusterHead ?: null,
                        'is_active' => $this->clusterIsActive,
                    ]);

                    if ($oldCode !== $this->clusterCode) {
                        \DB::table('sys_office')->where('cluster', $oldCode)->update(['cluster' => $this->clusterCode]);
                    }

                    \DB::table('sys_admin_logs')->insert([
                        'changes' => "Updated cluster: {$this->clusterName} ({$this->clusterCode})",
                        'admin_id' => auth()->id(),
                        'what_system' => 3,
                        'when_changes' => now()
                    ]);
                    $this->successMessage = 'Cluster details updated successfully!';
                }
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save cluster: ' . $e->getMessage();
        }
    }

    /**
     * Deletes or deactivates the specified cluster.
     * @return void
     */
    public function deleteCluster(): void
    {
        if ($this->selectedClusterId === null || $this->selectedClusterId <= 0) return;

        $this->clearMessages();

        try {
            \DB::transaction(function () {
                $cluster = \App\Models\Cluster::findOrFail($this->selectedClusterId);

                \DB::table('sys_office')->where('cluster', $cluster->cluster_code)->update(['cluster' => null]);
                $cluster->update(['is_active' => false]);

                \DB::table('sys_admin_logs')->insert([
                    'changes' => "Soft-deleted cluster: {$cluster->cluster_name} ({$cluster->cluster_code})",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now()
                ]);

                $this->cancelSelection();
                $this->successMessage = 'Cluster deactivated successfully!';
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete cluster: ' . $e->getMessage();
        }
    }

    // ==========================================
    // INTERNAL OFFICE ACTIONS
    // ==========================================
    public function startCreate(): void
    {
        $this->cancelSelection();
        $this->selectedOfficeId = -1;
        $this->officeName = 'New Office Unit';
        $this->officeCode = '';
        $this->officeCluster = $this->selectedClusterCode ?: '';
        $this->isActive = true;

        if ($this->officeCluster) {
            $cluster = \App\Models\Cluster::where('cluster_code', $this->officeCluster)->first();
            $this->officeClusterSearch = $cluster ? $cluster->cluster_name . ' (' . $cluster->cluster_code . ')' : $this->officeCluster;
        }
    }

    /**
     * Selects office and populates the details panel.
     *
     * @param int $id
     *
     * @return void
     */
    public function selectOffice(int $id): void
    {
        $this->cancelSelection();
        $this->selectedOfficeId = $id;

        $office = \App\Models\office::find($id);
        if ($office) {
            $this->officeName = $office->office_name;
            $this->officeCode = $office->office_code;
            $this->isActive = (bool) $office->is_active;
            $this->officeCluster = $office->cluster ?? '';
            if ($this->officeCluster) {
                $cluster = \App\Models\Cluster::where('cluster_code', $this->officeCluster)->first();
                $this->officeClusterSearch = $cluster ? $cluster->cluster_name . ' (' . $cluster->cluster_code . ')' : $this->officeCluster;
            } else {
                $this->officeClusterSearch = '';
            }
        }
    }

    /**
     * Selects office cluster and populates the details panel.
     *
     * @param string $code
     * @param string $name
     *
     * @return void
     */
    public function selectOfficeCluster(string $code, string $name): void
    {
        $this->officeCluster = $code;
        $this->officeClusterSearch = $code ? "$name ($code)" : '';
        $this->showOfficeClusterDropdown = false;
    }

    /**
     * Persists office changes to the database.
     * @return void
     */
    public function saveOfficeChanges(): void
    {
        if ($this->selectedOfficeId === null) return;

        $this->clearMessages();

        $nameExcludeId = null;
        $codeExcludeId = null;

        if ($this->selectedOfficeId === -1) {
            $deactivatedByName = \App\Models\office::where('office_name', trim($this->officeName))->where('is_active', false)->first();
            $deactivatedByCode = \App\Models\office::where('office_code', trim($this->officeCode))->where('is_active', false)->first();
            $nameExcludeId = $deactivatedByName ? $deactivatedByName->id : null;
            $codeExcludeId = $deactivatedByCode ? $deactivatedByCode->id : null;
        }

        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $clusterTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_cluster') ? 'sys_cluster' : 'cluster';

        $uniqueNameRule = $this->selectedOfficeId > 0
            ? "unique:{$officeTbl},office_name," . $this->selectedOfficeId . ',id'
            : ($nameExcludeId ? "unique:{$officeTbl},office_name," . $nameExcludeId . ',id' : "unique:{$officeTbl},office_name");

        $uniqueCodeRule = $this->selectedOfficeId > 0
            ? "unique:{$officeTbl},office_code," . $this->selectedOfficeId . ',id'
            : ($codeExcludeId ? "unique:{$officeTbl},office_code," . $codeExcludeId . ',id' : "unique:{$officeTbl},office_code");

        $this->validate([
            'officeName' => 'required|string|max:255|' . $uniqueNameRule,
            'officeCode' => 'required|string|max:50|' . $uniqueCodeRule,
            'officeCluster' => "nullable|string|exists:{$clusterTbl},cluster_code",
        ]);

        try {
            \DB::transaction(function () {
                if ($this->selectedOfficeId === -1) {
                    $existingOffice = \App\Models\office::where('office_code', trim($this->officeCode))->where('is_active', false)->first();

                    if ($existingOffice) {
                        $existingOffice->update([
                            'office_name' => $this->officeName,
                            'office_code' => $this->officeCode,
                            'cluster' => $this->officeCluster ?: null,
                            'is_active' => true,
                        ]);
                        \DB::table('sys_admin_logs')->insert([
                            'changes' => "Reactivated office: {$this->officeName} ({$this->officeCode})",
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now(),
                        ]);
                        $this->selectedOfficeId = $existingOffice->id;
                    } else {
                        $office = new \App\Models\office();
                        $office->office_name = $this->officeName;
                        $office->office_code = $this->officeCode;
                        $office->cluster = $this->officeCluster ?: null;
                        $office->is_active = true;
                        $office->save();

                        \DB::table('sys_admin_logs')->insert([
                            'changes' => "Created office: {$this->officeName} ({$this->officeCode})",
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now()
                        ]);
                        $this->selectedOfficeId = $office->id;
                    }
                    $this->successMessage = 'Office created successfully!';
                } else {
                    $office = \App\Models\office::findOrFail($this->selectedOfficeId);
                    
                    if (in_array($office->office_code, ['ORIGIN', '[H]', '[HUB]', 'HUB'])) {
                        $this->isActive = true;
                        $this->officeCode = $office->office_code;
                    }

                    $office->update([
                        'office_name' => $this->officeName,
                        'office_code' => $this->officeCode,
                        'cluster' => $this->officeCluster ?: null,
                        'is_active' => $this->isActive,
                    ]);

                    \DB::table('sys_admin_logs')->insert([
                        'changes' => "Updated office details for: {$this->officeName}",
                        'admin_id' => auth()->id(),
                        'what_system' => 3,
                        'when_changes' => now()
                    ]);

                    $this->successMessage = 'Office details updated successfully!';
                }
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save office: ' . $e->getMessage();
        }
    }

    /**
     * Deletes or deactivates the specified office.
     * @return void
     */
    public function deleteOffice(): void
    {
        if ($this->selectedOfficeId === null || $this->selectedOfficeId === -1) return;

        $this->clearMessages();

        try {
            \DB::transaction(function () {
                $office = \App\Models\office::findOrFail($this->selectedOfficeId);
                
                if (in_array($office->office_code, ['ORIGIN', '[H]', '[HUB]', 'HUB'])) {
                    throw new \Exception("System placeholder office '{$office->office_name}' cannot be deleted.");
                }

                $office->update(['is_active' => false]);

                \DB::table('sys_admin_logs')->insert([
                    'changes' => "Soft-deleted office: {$office->office_name} ({$office->office_code})",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now()
                ]);

                $this->cancelSelection();
                $this->successMessage = 'Office deactivated successfully!';
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete office: ' . $e->getMessage();
        }
    }

    // ==========================================
    // EXTERNAL OTHER OFFICES ACTIONS
    // ==========================================
    public function startCreateOtherOffice(): void
    {
        $this->cancelSelection();
        $this->selectedOtherOfficeId = -1;
        $this->otherOfficeName = 'New External Agency';
        $this->otherOfficeCode = '';
        $this->otherOfficeIsActive = true;
    }

    /**
     * Selects other office and populates the details panel.
     *
     * @param int $id
     *
     * @return void
     */
    public function selectOtherOffice(int $id): void
    {
        $this->cancelSelection();
        $sourceOffice = \DB::table('dts_source_office')->where('id', $id)->first();
        if ($sourceOffice) {
            $this->selectedOtherOfficeId = $sourceOffice->id;
            $this->otherOfficeName = $sourceOffice->s_office_name;
            $this->otherOfficeCode = $sourceOffice->s_office_code;
            $this->otherOfficeIsActive = (bool) $sourceOffice->is_active;
            $this->otherOfficeCreatedBy = $sourceOffice->created_by_office ?? '';
        }
    }

    /**
     * Persists other office changes to the database.
     * @return void
     */
    public function saveOtherOfficeChanges(): void
    {
        $this->clearMessages();

        $this->validate([
            'otherOfficeName' => 'required|string|max:255',
            'otherOfficeCode' => 'required|string|max:100',
        ]);

        $code = strtoupper(trim($this->otherOfficeCode));
        $name = trim($this->otherOfficeName);

        try {
            if ($this->selectedOtherOfficeId === -1) {
                $exists = \DB::table('dts_source_office')->where('s_office_code', $code)->exists();
                if ($exists) {
                    $this->errorMessage = "The External Office Code '{$code}' already exists.";
                    return;
                }

                $userOfficeCode = auth()->user()?->details?->office?->office_code;
                if (!$userOfficeCode || !\DB::table('sys_office')->where('office_code', $userOfficeCode)->exists()) {
                    $userOfficeCode = \DB::table('sys_office')->where('is_active', true)->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]', 'HUB'])->value('office_code') ?: 'ORIGIN';
                }

                $newId = \DB::table('dts_source_office')->insertGetId([
                    's_office_name' => $name,
                    's_office_code' => $code,
                    'created_by_office' => $userOfficeCode,
                    'is_active' => $this->otherOfficeIsActive,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                \DB::table('sys_admin_logs')->insert([
                    'changes' => "Created external source office: {$name} ({$code})",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now()
                ]);

                $this->selectedOtherOfficeId = $newId;
                $this->successMessage = 'External source office created successfully!';
            } else {
                $exists = \DB::table('dts_source_office')
                    ->where('s_office_code', $code)
                    ->where('id', '!=', $this->selectedOtherOfficeId)
                    ->exists();
                if ($exists) {
                    $this->errorMessage = "The External Office Code '{$code}' is already used by another agency.";
                    return;
                }

                \DB::table('dts_source_office')
                    ->where('id', $this->selectedOtherOfficeId)
                    ->update([
                        's_office_name' => $name,
                        's_office_code' => $code,
                        'is_active' => $this->otherOfficeIsActive,
                        'updated_at' => now(),
                    ]);

                \DB::table('sys_admin_logs')->insert([
                    'changes' => "Updated external source office: {$name} ({$code})",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now()
                ]);

                $this->successMessage = 'External agency updated successfully!';
            }
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save agency: ' . $e->getMessage();
        }
    }

    /**
     * Deletes or deactivates the specified other office.
     * @return void
     */
    public function deleteOtherOffice(): void
    {
        if (!$this->selectedOtherOfficeId) return;

        \DB::table('dts_source_office')
            ->where('id', $this->selectedOtherOfficeId)
            ->update(['is_active' => false]);

        $this->cancelSelection();
        $this->successMessage = 'External agency deactivated successfully!';
    }

    /**
     * Displays the import modal.
     *
     * @param string $target
     *
     * @return void
     */
    public function openImportModal(string $target = 'office'): void
    {
        $this->importTarget = $target;
        $this->officeFile = null;
        $this->clusterFile = null;
        $this->showImportModal = true;
    }

    /**
     * Supplies view data, query collections, and metrics to the Blade template.
     * @return array
     */
    public function with(): array
    {
        // 1. Clusters Collection
        $clusterQuery = \App\Models\Cluster::query()->where('is_active', true);
        if ($this->clusterSearch !== '') {
            $cSearch = '%' . $this->clusterSearch . '%';
            $clusterQuery->where(function($q) use ($cSearch) {
                $q->where('cluster_name', 'ilike', $cSearch)->orWhere('cluster_code', 'ilike', $cSearch);
            });
        }
        $clusters = $clusterQuery->orderBy('cluster_name', 'asc')->get();

        // Count offices per cluster
        $clusterOfficeCounts = \App\Models\office::where('is_active', true)
            ->whereNotNull('cluster')
            ->groupBy('cluster')
            ->selectRaw('cluster, count(*) as count')
            ->pluck('count', 'cluster')
            ->toArray();

        $totalOfficesCount = \App\Models\office::where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]', 'HUB'])
            ->count();

        // 2. Offices Query
        $officeQuery = \App\Models\office::query()
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]', 'HUB']);

        if ($this->selectedClusterCode !== null) {
            $officeQuery->where('cluster', $this->selectedClusterCode);
        }

        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $officeQuery->where(function($q) use ($searchVal) {
                $q->where('office_name', 'ilike', $searchVal)->orWhere('office_code', 'ilike', $searchVal);
            });
        }

        if ($this->layoutMode === 'default') {
            $offices = $officeQuery->orderBy('office_name', $this->officeSortDir)->paginate(20);
        } else {
            $offices = $officeQuery->orderBy('office_name', 'asc')->get();
        }

        // Inspector pickers — filter independently of miller column search.
        $clusterPickerTerm = trim($this->officeClusterSearch);
        $clusterPickerQuery = \App\Models\Cluster::query()->where('is_active', true);
        if ($clusterPickerTerm !== '') {
            $clusterPickerQuery->where(function ($q) use ($clusterPickerTerm) {
                $like = '%' . $clusterPickerTerm . '%';
                $q->where('cluster_name', 'ilike', $like)->orWhere('cluster_code', 'ilike', $like);
                if (preg_match('/\(([^)]+)\)\s*$/', $clusterPickerTerm, $m)) {
                    $codeHint = trim($m[1]);
                    $nameHint = trim(preg_replace('/\s*\([^)]+\)\s*$/', '', $clusterPickerTerm) ?? '');
                    if ($codeHint !== '') {
                        $q->orWhere('cluster_code', 'ilike', '%' . $codeHint . '%');
                    }
                    if ($nameHint !== '') {
                        $q->orWhere('cluster_name', 'ilike', '%' . $nameHint . '%');
                    }
                }
            });
        }
        $clusterPickerOptions = $clusterPickerQuery->orderBy('cluster_name', 'asc')->limit(150)->get();

        $headPickerTerm = trim($this->clusterHeadSearch);
        $clusterHeadOfficeQuery = \App\Models\office::query()
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]', 'HUB']);
        if ($headPickerTerm !== '') {
            $clusterHeadOfficeQuery->where(function ($q) use ($headPickerTerm) {
                $like = '%' . $headPickerTerm . '%';
                $q->where('office_name', 'ilike', $like)->orWhere('office_code', 'ilike', $like);
                if (preg_match('/\(([^)]+)\)\s*$/', $headPickerTerm, $m)) {
                    $codeHint = trim($m[1]);
                    $nameHint = trim(preg_replace('/\s*\([^)]+\)\s*$/', '', $headPickerTerm) ?? '');
                    if ($codeHint !== '') {
                        $q->orWhere('office_code', 'ilike', '%' . $codeHint . '%');
                    }
                    if ($nameHint !== '') {
                        $q->orWhere('office_name', 'ilike', '%' . $nameHint . '%');
                    }
                }
            });
        }
        $clusterHeadOfficeOptions = $clusterHeadOfficeQuery->orderBy('office_name', 'asc')->limit(150)->get();

        $acctTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $acctDetailsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $roleTable = \Illuminate\Support\Facades\Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';

        // Staff counts per office
        $personnelCounts = [];
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable($acctDetailsTable) && \Illuminate\Support\Facades\Schema::hasTable($acctTable)) {
                $personnelCounts = \DB::table($acctDetailsTable . ' as ad')
                    ->join($acctTable . ' as a', 'a.id', '=', 'ad.account_id')
                    ->where('a.account_active', true)
                    ->groupBy('ad.office_id')
                    ->selectRaw('ad.office_id, count(*) as count')
                    ->pluck('count', 'office_id')
                    ->toArray();
            }
        } catch (\Throwable $e) {}

        // Operational data for selected office
        $assignedStaff = collect();
        $storageData = null;
        $rdpBrackets = collect();

        if ($this->selectedOfficeId && $this->selectedOfficeId > 0) {
            $selectedOfficeObj = \App\Models\office::find($this->selectedOfficeId);

            if ($selectedOfficeObj) {
                try {
                    if (\Illuminate\Support\Facades\Schema::hasTable($acctDetailsTable) && \Illuminate\Support\Facades\Schema::hasTable($acctTable)) {
                        $staffQ = \DB::table($acctDetailsTable . ' as ad')
                            ->join($acctTable . ' as a', 'a.id', '=', 'ad.account_id');

                        if (\Illuminate\Support\Facades\Schema::hasTable($roleTable)) {
                            $staffQ->leftJoin($roleTable . ' as r', 'r.id', '=', 'a.account_role')
                                   ->select('a.id', 'a.username', 'ad.first_name', 'ad.last_name', 'ad.email', 'r.key_name as role_name');
                        } else {
                            $staffQ->select('a.id', 'a.username', 'ad.first_name', 'ad.last_name', 'ad.email', \DB::raw("'' as role_name"));
                        }

                        $assignedStaff = $staffQ->where('ad.office_id', $this->selectedOfficeId)
                            ->where('a.account_active', true)
                            ->orderBy('ad.first_name', 'asc')
                            ->get();
                    }
                } catch (\Throwable $e) {}

                try {
                    if (\Illuminate\Support\Facades\Schema::hasTable('sys_folder_data')) {
                        $storageData = \DB::table('sys_folder_data')->where('office_name', $selectedOfficeObj->office_name)->first();
                    }
                } catch (\Throwable $e) {}

                try {
                    if (\Illuminate\Support\Facades\Schema::hasTable('rdp_office_bracket')) {
                        $rdpBrackets = \DB::table('rdp_office_bracket as ob')
                            ->leftJoin('rdp_record_series_brackets as mb', 'mb.id', '=', 'ob.main_bracket_id')
                            ->leftJoin('rdp_record_series_sub_brackets as sb', 'sb.id', '=', 'ob.sub_bracket_id')
                            ->where('ob.office_code', $selectedOfficeObj->office_code)
                            ->select('mb.bracket_name', 'sb.sub_name')
                            ->get();
                    }
                } catch (\Throwable $e) {}
            }
        }

        // 3. Other Offices Query (External Agencies)
        $otherOfficeQuery = \DB::table('dts_source_office as so')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as creator', 'creator.office_code', '=', 'so.created_by_office')
            ->select('so.*', 'creator.office_name as creator_office_name');

        if ($this->otherOfficeFilter === 'active') {
            $otherOfficeQuery->where('so.is_active', true);
        } elseif ($this->otherOfficeFilter === 'inactive') {
            $otherOfficeQuery->where('so.is_active', false);
        }

        if ($this->otherOfficeSearch !== '') {
            $oSearch = '%' . $this->otherOfficeSearch . '%';
            $otherOfficeQuery->where(function($q) use ($oSearch) {
                $q->where('so.s_office_name', 'ilike', $oSearch)->orWhere('so.s_office_code', 'ilike', $oSearch);
            });
        }

        if ($this->layoutMode === 'default') {
            $otherOffices = $otherOfficeQuery->orderBy('so.s_office_name', $this->otherOfficeSortDir)->paginate(20);
        } else {
            $otherOffices = $otherOfficeQuery->orderBy('so.s_office_name', 'asc')->get();
        }

        // Incoming transactions for selected other office
        $incomingTransactions = collect();
        $incomingCount = 0;
        $topReceivingOffices = collect();

        if ($this->selectedOtherOfficeId && $this->selectedOtherOfficeId > 0) {
            try {
                $currOther = \DB::table('dts_source_office')->where('id', $this->selectedOtherOfficeId)->first();
                if ($currOther && \Illuminate\Support\Facades\Schema::hasTable('dts_transaction_details') && \Illuminate\Support\Facades\Schema::hasColumn('dts_transaction_details', 'source_office')) {
                    $orderCol = \Illuminate\Support\Facades\Schema::hasColumn('dts_transaction_details', 'date_created') 
                        ? 'date_created' 
                        : (\Illuminate\Support\Facades\Schema::hasColumn('dts_transaction_details', 'created_at') ? 'created_at' : 'id');

                    $incomingTransactions = \DB::table('dts_transaction_details')
                        ->where('source_office', $currOther->s_office_code)
                        ->orderBy($orderCol, 'desc')
                        ->limit(10)
                        ->get();

                    $incomingCount = \DB::table('dts_transaction_details')
                        ->where('source_office', $currOther->s_office_code)
                        ->count();

                    if (\Illuminate\Support\Facades\Schema::hasColumn('dts_transaction_details', 'current_office_hold')) {
                        $topReceivingOffices = \DB::table('dts_transaction_details')
                            ->where('source_office', $currOther->s_office_code)
                            ->whereNotNull('current_office_hold')
                            ->groupBy('current_office_hold')
                            ->selectRaw('current_office_hold, count(*) as count')
                            ->orderByDesc('count')
                            ->limit(5)
                            ->get();
                    }
                }
            } catch (\Throwable $e) {
                // Graceful fallback
            }
        }

        return [
            'clusters'                   => $clusters,
            'clusterPickerOptions'       => $clusterPickerOptions,
            'clusterOfficeCounts'        => $clusterOfficeCounts,
            'totalOfficesCount'          => $totalOfficesCount,
            'offices'                    => $offices,
            'clusterHeadOfficeOptions'   => $clusterHeadOfficeOptions,
            'personnelCounts'            => $personnelCounts,
            'assignedStaff'              => $assignedStaff,
            'storageData'                => $storageData,
            'rdpBrackets'                => $rdpBrackets,
            'otherOffices'               => $otherOffices,
            'incomingTransactions'       => $incomingTransactions,
            'incomingCount'              => $incomingCount,
            'topReceivingOffices'        => $topReceivingOffices,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/activity_logs.css', 'resources/css/admin/accounts_offices.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .miller-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 16px 20px;
            height: calc(100vh - 100px);
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            user-select: none;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        /* 4-Column Miller Browser Layout */
        .miller-browser {
            flex: 1 1 auto;
            display: grid;
            grid-template-columns: 270px 270px 1fr 340px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px 10px 0 0;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            min-height: 540px;
        }

        .miller-column {
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            min-height: 0;
            position: relative;
        }
        .miller-column:nth-child(even) {
            background: #fafbfc;
        }
        .miller-column:last-child {
            border-right: none;
        }

        .m-col-header {
            padding: 11px 14px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            font-weight: 800;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .m-col-header span {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .m-col-search {
            padding: 8px 10px;
            border-bottom: 1px solid #f1f5f9;
            background: #ffffff;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .m-col-search input {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
            color: #0f172a;
        }
        .m-col-search input:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
        }

        .m-col-list {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 4px 0;
        }

        .m-item {
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
            color: #334155;
            cursor: pointer;
            user-select: none;
            transition: all 0.1s ease;
            border-left: 3px solid transparent;
            gap: 8px;
        }
        .m-item:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .m-item.active {
            background: #0284c7;
            color: #ffffff;
            border-left-color: #0369a1;
            font-weight: 600;
        }
        .m-item.active .m-item-icon,
        .m-item.active .m-item-arrow,
        .m-item.active .m-item-sub {
            color: #ffffff !important;
            opacity: 0.9;
        }
        .m-item.active .m-item-badge {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.35);
        }

        .m-item-left {
            display: flex;
            align-items: center;
            gap: 9px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1 1 auto;
        }
        .m-item-avatar {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            background: #e0f2fe;
            color: #0369a1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10.5px;
            font-weight: 800;
            flex-shrink: 0;
        }
        .m-item.active .m-item-avatar {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        .m-item-text {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .m-item-title {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 12.5px;
            font-weight: 600;
        }
        .m-item-sub {
            font-size: 10.5px;
            color: #64748b;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .m-item-right {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .m-item-badge {
            font-size: 10.5px;
            padding: 2px 7px;
            border-radius: 9999px;
            background: #e2e8f0;
            color: #475569;
            font-weight: 700;
            border: 1px solid #cbd5e1;
        }
        .m-item-arrow {
            font-size: 11px;
            color: #94a3b8;
        }

        /* Scope Pills Toolbar */
        .scope-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }
        .scope-pill.active {
            background: #0284c7;
            color: #ffffff;
            box-shadow: 0 2px 4px rgba(2, 132, 199, 0.2);
        }
        .scope-pill:not(.active) {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
        }
        .scope-pill:not(.active):hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        /* Buttons */
        .m-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 7px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
        }
        .m-btn-primary {
            background: #0284c7;
            color: #ffffff;
        }
        .m-btn-primary:hover {
            background: #0369a1;
        }
        .m-btn-danger {
            background: #ef4444;
            color: #ffffff;
        }
        .m-btn-danger:hover {
            background: #dc2626;
        }
        .m-btn-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .m-btn-secondary:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        /* Column 4: Inspector Panel */
        .m-inspector {
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            overflow-y: auto;
            flex: 1 1 auto;
        }
        .m-inspector-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .m-inspector-avatar {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 4px 6px -1px rgba(2, 132, 199, 0.25);
        }
        .m-inspector-title {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.3;
            margin: 0;
        }
        .m-inspector-sub {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .m-field-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .m-field-label {
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .m-field-input {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12.5px;
            color: #0f172a;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
        }
        .m-field-input:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
        }

        .m-metric-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .m-metric-label {
            font-size: 11.5px;
            font-weight: 600;
            color: #64748b;
        }
        .m-metric-value {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
        }

        /* Switch */
        .switch {
            position: relative;
            display: inline-block;
            width: 38px;
            height: 22px;
            flex-shrink: 0;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1; transition: .2s; border-radius: 22px;
        }
        .slider:before {
            position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px;
            background-color: white; transition: .2s; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        input:checked + .slider { background-color: #0284c7; }
        input:checked + .slider:before { transform: translateX(16px); }

        /* Bottom Breadcrumb Bar */
        .miller-path-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-top: none;
            border-radius: 0 0 10px 10px;
            padding: 9px 16px;
            font-size: 12px;
            color: #475569;
            overflow-x: auto;
            white-space: nowrap;
        }
        .miller-path-tag {
            font-weight: 700;
            color: #0f172a;
        }

        .m-empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            text-align: center;
            color: #94a3b8;
            height: 100%;
            box-sizing: border-box;
        }
        .m-empty-icon {
            font-size: 32px;
            margin-bottom: 12px;
            color: #cbd5e1;
        }

        /* Dark mode */
        [data-theme="dark"] .miller-browser,
        [data-theme="dark"] .miller-column {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .miller-column:nth-child(even) { background: #182234; }
        [data-theme="dark"] .m-col-header,
        [data-theme="dark"] .miller-path-bar {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-col-search {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .m-col-search input {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-item { color: #cbd5e1; }
        [data-theme="dark"] .m-item:hover { background: #334155; color: #f8fafc; }
        [data-theme="dark"] .m-item.active { background: #0284c7; color: #ffffff; }
        [data-theme="dark"] .m-field-input {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-metric-card {
            background: #0f172a;
            border-color: #334155;
        }
    </style>
@endpush

@if($layoutMode === 'default')
<div class="activity-logs-container">
    <!-- Tabs Header -->
    <div class="tabs-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-right: 12px;">
                <h1 style="font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-sitemap" style="color: #0284c7;"></i> Organizational Units & Offices
                </h1>
                <span style="font-size: 11px; font-weight: 700; background: #f1f5f9; color: #475569; padding: 3px 8px; border-radius: 9999px; border: 1px solid #cbd5e1;">
                    Default Layout
                </span>
            </div>
            <button type="button" class="tab-btn {{ $activeTab === 'offices' ? 'active' : '' }}" wire:click="$set('activeTab', 'offices')">
                <i class="fa-solid fa-building" style="margin-right: 6px;"></i> Offices Directory
            </button>
            <button type="button" class="tab-btn {{ $activeTab === 'other-offices' ? 'active' : '' }}" wire:click="$set('activeTab', 'other-offices')">
                <i class="fa-solid fa-globe" style="margin-right: 6px;"></i> Other Offices (External)
            </button>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <x-admin.layout-trigger :mode="$layoutMode" />
        </div>
    </div>
    @php
        // Old tabs header replaced
    @endphp
    <div style="display: none;">


    @if($activeTab === 'offices')
        <div class="admin-offices-container {{ !$selectedOfficeId ? 'no-selection' : 'has-selection' }}" wire:key="tab-offices-view">
            <!-- Left Pane: Offices Directory -->
            <div class="directory-panel">
                <div class="directory-header-row">
                    <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">Offices Directory</span>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn-create-new" wire:click="startCreate">
                            <i class="fa-solid fa-plus"></i> New Office
                        </button>
                        <button type="button" class="btn-create-new" style="background-color: #0284c7; border-color: #0284c7;" wire:click="startImport">
                            <i class="fa-solid fa-file-import"></i> Import File
                        </button>
                    </div>
                </div>

                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-box" placeholder="Search offices..." wire:model.live="search">
                </div>

                @if(count($selectedOfficeIds) > 0)
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 600; color: #be123c;">{{ count($selectedOfficeIds) }} selected</span>
                        <button type="button" x-data
                            x-on:click="if(confirm('Are you sure you want to deactivate {{ count($selectedOfficeIds) }} office(s)? They will be soft-deleted (hidden) but retained for transparency.')) { $el.disabled = true; $el.querySelector('.btn-idle').style.display = 'none'; $el.querySelector('.btn-loading').style.display = 'inline-flex'; $wire.bulkDeleteOffices(); }"
                            style="background: #e11d48; color: #fff; border: none; padding: 5px 12px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;">
                            <span class="btn-idle" style="display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-trash-can"></i> Delete Selected</span>
                            <span class="btn-loading" style="display: none; align-items: center; gap: 5px;"><i class="fa-solid fa-circle-notch fa-spin"></i> Deactivating {{ count($selectedOfficeIds) }} office(s)...</span>
                        </button>
                    </div>
                @endif

                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 12px; border-bottom: 1px solid #e2e8f0;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" wire:click="toggleAllOffices" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedOfficeIds) > 0 ? 'checked' : '' }}>
                        <span style="font-size: 12px; color: #64748b; font-weight: 500;">Select All</span>
                    </div>

                    <!-- Layout View Mode Toggle -->
                    <div class="view-mode-toggle" style="display: flex; gap: 2px; background: #f1f5f9; padding: 2px; border-radius: 6px; border: 1px solid #cbd5e1;">
                        <button type="button" wire:click="$set('officeViewMode', 'grid')" title="Cards Grid Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $officeViewMode === 'grid' ? '#ffffff' : 'transparent' }}; color: {{ $officeViewMode === 'grid' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $officeViewMode === 'grid' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                            <i class="fa-solid fa-border-all"></i> Cards
                        </button>
                        <button type="button" wire:click="$set('officeViewMode', 'table')" title="Table Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $officeViewMode === 'table' ? '#ffffff' : 'transparent' }}; color: {{ $officeViewMode === 'table' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $officeViewMode === 'table' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                            <i class="fa-solid fa-table-list"></i> Table
                        </button>
                    </div>
                </div>

                @if($officeViewMode === 'table')
                    <!-- Table Layout View -->
                    <div style="overflow-x: auto; max-height: calc(100vh - 280px); overflow-y: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-family: 'Inter', sans-serif; font-size: 12.5px;">
                            <thead>
                                <tr style="background: #f8fafc; color: #475569; text-align: left; position: sticky; top: 0; z-index: 10; font-weight: 600; font-size: 11.5px; border-bottom: 1.5px solid #cbd5e1;">
                                    <th style="padding: 8px 10px; width: 36px;"></th>
                                    <th style="padding: 8px 10px;">Code</th>
                                    <th style="padding: 8px 10px;">Office Name</th>
                                    <th style="padding: 8px 10px;">Cluster</th>
                                    <th style="padding: 8px 10px; text-align: center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($offices as $office)
                                    <tr class="office-tbl-row {{ $selectedOfficeId === $office->id ? 'selected-row' : '' }}" 
                                        wire:key="office-tbl-{{ $office->id }}" 
                                        wire:click="selectOffice({{ $office->id }})"
                                        style="border-bottom: 1px solid #f1f5f9; cursor: pointer; background: {{ $selectedOfficeId === $office->id ? '#eff6ff' : '#ffffff' }}; transition: background 0.12s ease;">
                                        <td style="padding: 8px 10px;" onclick="event.stopPropagation()">
                                            @if(!in_array($office->office_code, ['ORIGIN', '[H]']))
                                                <input type="checkbox" wire:click.stop="toggleOfficeSelection({{ $office->id }})" {{ in_array($office->id, $selectedOfficeIds) ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #3b82f6;">
                                            @endif
                                        </td>
                                        <td style="padding: 8px 10px; font-weight: 700; color: #0284c7; font-family: monospace; font-size: 12px;">{{ $office->office_code }}</td>
                                        <td style="padding: 8px 10px; font-weight: 600; color: #1e293b;">{{ $office->office_name }}</td>
                                        <td style="padding: 8px 10px; color: #64748b;">{{ $office->cluster ?: '—' }}</td>
                                        <td style="padding: 8px 10px; text-align: center;">
                                            @if($office->is_active)
                                                <span style="padding: 2px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 600; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">Active</span>
                                            @else
                                                <span style="padding: 2px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 600; background: #fee2e2; color: #991b1b; border: 1px solid #fecdd3;">Suspended</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" style="text-align: center; color: #94a3b8; padding: 20px;">No offices configured.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <!-- Cards Grid Layout View -->
                    <div class="offices-list">
                        @forelse($offices as $office)
                            @php
                                $officeInitials = strtoupper(substr($office->office_code ?: '?', 0, 3));
                            @endphp
                            <div class="office-item-card {{ $selectedOfficeId === $office->id ? 'active' : '' }}" wire:key="office-{{ $office->id }}" wire:click="selectOffice({{ $office->id }})">
                                @if(!in_array($office->office_code, ['ORIGIN', '[H]']))
                                    <input type="checkbox" wire:click.stop="toggleOfficeSelection({{ $office->id }})" {{ in_array($office->id, $selectedOfficeIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0;">
                                @endif
                                <div class="office-avatar-small">
                                    <span>{{ $officeInitials }}</span>
                                </div>
                                <div class="office-meta-info">
                                    <span class="office-display-name">{{ $office->office_name }}</span>
                                    <span class="office-display-code">Code: {{ $office->office_code }}</span>
                                    @if($office->cluster)
                                        <span class="office-status-badge" style="background: rgba(14, 165, 233, 0.08); color: #0284c7; border: 1px solid rgba(14, 165, 233, 0.2); font-weight: 600; padding: 2px 6px; border-radius: 4px; font-size: 11px; display: inline-block; margin-top: 4px;">
                                            <i class="fa-solid fa-sitemap" style="font-size: 9px; margin-right: 3px;"></i> {{ $office->cluster }}
                                        </span>
                                    @endif
                                    @if($office->is_active)
                                        <span class="office-status-badge active-badge" style="display: block; width: fit-content; margin-top: 4px;">Active</span>
                                    @else
                                        <span class="office-status-badge inactive-badge" style="display: block; width: fit-content; margin-top: 4px;">Suspended</span>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div style="text-align: center; color: #94a3b8; padding: 20px; font-family: 'Inter', sans-serif; font-size: 13.5px;">
                                No offices configured.
                            </div>
                        @endforelse
                    </div>
                @endif

                @if($offices->hasPages())
                    <div class="offices-pagination-bar">
                        {{ $offices->links('components.pagination') }}
                    </div>
                @endif
            </div>

            <!-- Right Pane: Office Form Configurator -->
            @if($selectedOfficeId)
                <div class="details-panel">
                @if($successMessage)
                    <div class="toast-alert success" style="margin: 20px 20px 0 20px;">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>{{ $successMessage }}</span>
                    </div>
                @endif

                @if($errorMessage)
                    <div class="toast-alert error" style="margin: 20px 20px 0 20px;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span>{{ $errorMessage }}</span>
                    </div>
                @endif

                @if($selectedOfficeId === -2)
                        <!-- Header -->
                        <div class="details-header">
                            <div class="details-header-avatar" style="background-color: #e0f2fe; color: #0369a1;">
                                <i class="fa-solid fa-file-import"></i>
                            </div>
                            <div class="details-header-info">
                                <h2 class="details-header-name">Import Offices from File</h2>
                                <span class="details-header-sub">Upload a .txt file containing multiple office configurations</span>
                            </div>
                            <button type="button" class="btn-close-details" wire:click="cancelSelection" title="Close Details Panel">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <!-- Body Form -->
                        <div class="details-body">
                            <div class="form-group">
                                <label class="form-label" for="officeFileInput" style="font-weight: 600; color: #334155; margin-bottom: 8px; display: block;">Select Office Text File (.txt)</label>
                                <input type="file" 
                                       id="officeFileInput" 
                                       wire:model="officeFile" 
                                       accept=".txt,text/plain" 
                                       style="padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; font-size: 13px; font-family: 'Inter', sans-serif;">
                                <div wire:loading wire:target="officeFile" style="font-size: 12px; color: #0284c7; margin-top: 6px; font-weight: 600;">
                                    <i class="fa-solid fa-spinner fa-spin"></i> Uploading file... Please wait.
                                </div>
                                @error('officeFile') <span style="color:#ef4444; font-size:11px; margin-top:4px; display:block;">{{ $message }}</span> @enderror
                                
                                <p style="font-size: 12.5px; color: #64748b; margin-top: 12px; line-height: 1.5; font-family: 'Inter', sans-serif;">
                                    <strong>Instructions:</strong> The uploaded file must be a plain text file (<code>.txt</code>). Every line must end with a semicolon (<code>;</code>):
                                    <br>• Line 1: <code>=&lt;office name&gt;;</code> (must start with an equals sign <code>=</code>)
                                    <br>• Line 2: <code>&lt;office short code&gt;;</code>
                                    <br>• Line 3 (Optional): <code>&lt;active status (true/false)&gt;;</code> (e.g. <code>true;</code> or <code>false;</code>)
                                    <br>• Line 4 (Optional): <code>&lt;cluster code&gt;;</code> (must refer to an existing cluster code)
                                    <br>• Lines starting with <code>#</code> are classified as comments and will be ignored.
                                </p>
                            </div>
                        </div>

                        <!-- Footer Actions -->
                        <div class="details-footer">
                            <button type="button" class="btn-cancel" wire:click="cancelSelection">Cancel</button>
                            <button type="button" class="btn-save" style="background-color: #0284c7; border-color: #0284c7;" 
                                    wire:click="importOffices" 
                                    wire:loading.attr="disabled" 
                                    wire:target="officeFile, importOffices">
                                <span wire:loading.remove wire:target="officeFile, importOffices">
                                    <i class="fa-solid fa-file-import"></i> Extract Office Data
                                </span>
                                <span wire:loading wire:target="officeFile, importOffices">
                                    <i class="fa-solid fa-spinner fa-spin"></i> Processing...
                                </span>
                            </button>
                        </div>
                    @else
                        <!-- Header -->
                        <div class="details-header">
                            <div class="details-header-avatar">
                                <i class="fa-solid fa-building"></i>
                            </div>
                            <div class="details-header-info">
                                <h2 class="details-header-name">
                                    {{ $selectedOfficeId === -1 ? 'Configure New Office' : $officeName }}
                                </h2>
                                <span class="details-header-sub">
                                    {{ $selectedOfficeId === -1 ? 'Add a new office entry to register tracking clearance' : 'Review & adjust active office registration' }}
                                </span>
                            </div>
                            <button type="button" class="btn-close-details" wire:click="cancelSelection" title="Close Details Panel">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <!-- Body Form -->
                        <div class="details-body">
                            <form wire:submit.prevent="saveOfficeChanges" style="display: flex; flex-direction: column; gap: 20px;">
                                <!-- Office Name -->
                                <div class="form-group">
                                    <span class="form-label">Office Name</span>
                                    <input type="text" class="form-input" placeholder="e.g. College of Computer Studies" wire:model="officeName">
                                    @error('officeName') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                                </div>

                                <!-- Office Code -->
                                <div class="form-group">
                                    <span class="form-label">Office Short Code</span>
                                    <input type="text" class="form-input" placeholder="e.g. CCS" wire:model="officeCode" {{ in_array($officeCode, ['ORIGIN', '[H]', '[HUB]', 'HUB']) ? 'disabled' : '' }}>
                                    @error('officeCode') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                                </div>

                                <!-- Cluster Dropdown -->
                                <div class="form-group" wire:click.outside="$set('showOfficeClusterDropdown', false)">
                                    <span class="form-label">Cluster</span>
                                    <div style="position: relative;">
                                        <input type="text" 
                                               class="form-input" 
                                               placeholder="Search and select cluster..." 
                                               wire:model.live="officeClusterSearch" 
                                               wire:focus="$set('showOfficeClusterDropdown', true)" 
                                               autocomplete="off" 
                                               style="padding-right: 32px;">
                                        <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #94a3b8; font-size: 10px;">▼</span>
                                        
                                        @if($showOfficeClusterDropdown)
                                            <div style="position: absolute; top: 100%; left: 0; right: 0; margin-top: 4px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); max-height: 160px; overflow-y: auto; z-index: 50;">
                                                <div wire:click="selectOfficeCluster('', '')" style="padding: 9px 14px; font-size: 13px; color: #64748b; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-style: italic;">
                                                    None (No Cluster)
                                                </div>
                                                @php
                                                    $oClusterSearchLower = strtolower($officeClusterSearch);
                                                    $allClusters = \App\Models\Cluster::where('is_active', true)->orderBy('cluster_name')->get();
                                                    $filteredClusters = $allClusters->filter(function($c) use ($oClusterSearchLower) {
                                                        return empty($oClusterSearchLower) 
                                                            || str_contains(strtolower($c->cluster_name), $oClusterSearchLower) 
                                                            || str_contains(strtolower($c->cluster_code), $oClusterSearchLower);
                                                    });
                                                @endphp
                                                @forelse($filteredClusters as $clusterObj)
                                                    <div wire:click="selectOfficeCluster('{{ $clusterObj->cluster_code }}', '{{ addslashes($clusterObj->cluster_name) }}')" style="padding: 9px 14px; font-size: 13px; cursor: pointer; border-bottom: 1px solid #f1f5f9;">
                                                        {{ $clusterObj->cluster_name }} ({{ $clusterObj->cluster_code }})
                                                    </div>
                                                @empty
                                                    <div style="padding: 12px 14px; font-size: 13px; color: #94a3b8; text-align: center;">No matching clusters found</div>
                                                @endforelse
                                            </div>
                                        @endif
                                    </div>
                                    @error('officeCluster') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                                </div>

                                <!-- Active Toggle Wrapper (Only shown for edit mode, not create mode) -->
                                @if($selectedOfficeId > 0)
                                    <div class="form-group">
                                        <div class="status-toggle-wrapper">
                                            <div class="status-toggle-label">
                                                <span class="status-toggle-title">Office Active Status</span>
                                                <span class="status-toggle-desc">Toggle whether this office is active or soft-deactivated for transparency.</span>
                                            </div>
                                            <label class="switch">
                                                <input type="checkbox" wire:model="isActive" {{ in_array($officeCode, ['ORIGIN', '[H]', '[HUB]', 'HUB']) ? 'disabled' : '' }}>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                @endif
                            </form>
                        </div>

                        <!-- Footer Actions -->
                        <div class="details-footer">
                            @if($selectedOfficeId > 0 && !in_array($officeCode, ['ORIGIN', '[H]', '[HUB]', 'HUB']))
                                <button type="button" class="btn-delete" wire:click="deleteOffice" style="margin-right: auto;">
                                    <i class="fa-solid fa-trash-can"></i> Delete Office
                                </button>
                            @endif
                            <button type="button" class="btn-cancel" wire:click="cancelSelection">Cancel</button>
                            <button type="button" class="btn-save" wire:click="saveOfficeChanges">
                                <i class="fa-solid fa-floppy-disk"></i> Save Configuration
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @elseif($activeTab === 'clusters')
        <div class="admin-offices-container {{ !$selectedClusterId ? 'no-selection' : 'has-selection' }}" wire:key="tab-clusters-view">
            <!-- Left Pane: Clusters Directory -->
            <div class="directory-panel">
                <div class="directory-header-row">
                    <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">Clusters Directory</span>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn-create-new" wire:click="startCreateCluster">
                            <i class="fa-solid fa-plus"></i> New Cluster
                        </button>
                        <button type="button" class="btn-create-new" style="background-color: #0284c7; border-color: #0284c7;" wire:click="startImportCluster">
                            <i class="fa-solid fa-file-import"></i> Import File
                        </button>
                    </div>
                </div>

                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-box" placeholder="Search clusters..." wire:model.live="clusterSearch">
                </div>

                @if(count($selectedClusterIds) > 0)
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 600; color: #be123c;">{{ count($selectedClusterIds) }} selected</span>
                        <button type="button" x-data
                            x-on:click="if(confirm('Are you sure you want to deactivate {{ count($selectedClusterIds) }} cluster(s)? They will be soft-deleted (hidden) but retained for transparency.')) { $el.disabled = true; $el.querySelector('.btn-idle').style.display = 'none'; $el.querySelector('.btn-loading').style.display = 'inline-flex'; $wire.bulkDeleteClusters(); }"
                            style="background: #e11d48; color: #fff; border: none; padding: 5px 12px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;">
                            <span class="btn-idle" style="display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-trash-can"></i> Delete Selected</span>
                            <span class="btn-loading" style="display: none; align-items: center; gap: 5px;"><i class="fa-solid fa-circle-notch fa-spin"></i> Deactivating {{ count($selectedClusterIds) }} cluster(s)...</span>
                        </button>
                    </div>
                @endif

                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 12px; border-bottom: 1px solid #e2e8f0;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" wire:click="toggleAllClusters" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedClusterIds) > 0 ? 'checked' : '' }}>
                        <span style="font-size: 12px; color: #64748b; font-weight: 500;">Select All</span>
                    </div>

                    <!-- Layout View Mode Toggle -->
                    <div class="view-mode-toggle" style="display: flex; gap: 2px; background: #f1f5f9; padding: 2px; border-radius: 6px; border: 1px solid #cbd5e1;">
                        <button type="button" wire:click="$set('clusterViewMode', 'grid')" title="Cards Grid Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $clusterViewMode === 'grid' ? '#ffffff' : 'transparent' }}; color: {{ $clusterViewMode === 'grid' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $clusterViewMode === 'grid' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                            <i class="fa-solid fa-border-all"></i> Cards
                        </button>
                        <button type="button" wire:click="$set('clusterViewMode', 'table')" title="Table Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $clusterViewMode === 'table' ? '#ffffff' : 'transparent' }}; color: {{ $clusterViewMode === 'table' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $clusterViewMode === 'table' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                            <i class="fa-solid fa-table-list"></i> Table
                        </button>
                    </div>
                </div>
                
                @if($clusterViewMode === 'table')
                    <!-- Table Layout View -->
                    <div style="overflow-x: auto; max-height: calc(100vh - 280px); overflow-y: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-family: 'Inter', sans-serif; font-size: 12.5px;">
                            <thead>
                                <tr style="background: #f8fafc; color: #475569; text-align: left; position: sticky; top: 0; z-index: 10; font-weight: 600; font-size: 11.5px; border-bottom: 1.5px solid #cbd5e1;">
                                    <th style="padding: 8px 10px; width: 36px;"></th>
                                    <th style="padding: 8px 10px;">Code</th>
                                    <th style="padding: 8px 10px;">Cluster Name</th>
                                    <th style="padding: 8px 10px;">Cluster Head</th>
                                    <th style="padding: 8px 10px; text-align: center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($clusters as $cluster)
                                    <tr class="cluster-tbl-row {{ $selectedClusterId === $cluster->id ? 'selected-row' : '' }}" 
                                        wire:key="cluster-tbl-{{ $cluster->id }}" 
                                        wire:click="selectCluster({{ $cluster->id }})"
                                        style="border-bottom: 1px solid #f1f5f9; cursor: pointer; background: {{ $selectedClusterId === $cluster->id ? '#eff6ff' : '#ffffff' }}; transition: background 0.12s ease;">
                                        <td style="padding: 8px 10px;" onclick="event.stopPropagation()">
                                            <input type="checkbox" wire:click.stop="toggleClusterSelection({{ $cluster->id }})" {{ in_array($cluster->id, $selectedClusterIds) ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #3b82f6;">
                                        </td>
                                        <td style="padding: 8px 10px; font-weight: 700; color: #166534; font-family: monospace; font-size: 12px;">{{ $cluster->cluster_code }}</td>
                                        <td style="padding: 8px 10px; font-weight: 600; color: #1e293b;">{{ $cluster->cluster_name }}</td>
                                        <td style="padding: 8px 10px; color: #64748b;">{{ $cluster->cluster_head ?: '—' }}</td>
                                        <td style="padding: 8px 10px; text-align: center;">
                                            @if($cluster->is_active)
                                                <span style="padding: 2px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 600; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">Active</span>
                                            @else
                                                <span style="padding: 2px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 600; background: #fee2e2; color: #991b1b; border: 1px solid #fecdd3;">Suspended</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" style="text-align: center; color: #94a3b8; padding: 20px;">No clusters configured.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <!-- Cards Grid Layout View -->
                    <div class="offices-list">
                        @forelse($clusters as $cluster)
                            @php
                                $clusterInitials = strtoupper(substr($cluster->cluster_code ?: '?', 0, 3));
                            @endphp
                            <div class="office-item-card {{ $selectedClusterId === $cluster->id ? 'active' : '' }}" wire:key="cluster-{{ $cluster->id }}" wire:click="selectCluster({{ $cluster->id }})">
                                <input type="checkbox" wire:click.stop="toggleClusterSelection({{ $cluster->id }})" {{ in_array($cluster->id, $selectedClusterIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0;">
                                <div class="office-avatar-small" style="background-color: #f0fdf4; color: #166534;">
                                    <span>{{ $clusterInitials }}</span>
                                </div>
                                <div class="office-meta-info">
                                    <span class="office-display-name">{{ $cluster->cluster_name }}</span>
                                    <span class="office-display-code">Code: {{ $cluster->cluster_code }}</span>
                                    @if($cluster->cluster_head)
                                        <span class="office-display-code" style="color: #64748b; font-size: 11px;">Head: {{ $cluster->cluster_head }}</span>
                                    @endif
                                    @if($cluster->is_active)
                                        <span class="office-status-badge active-badge" style="display: block; width: fit-content; margin-top: 4px;">Active</span>
                                    @else
                                        <span class="office-status-badge inactive-badge" style="display: block; width: fit-content; margin-top: 4px;">Suspended</span>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div style="text-align: center; color: #94a3b8; padding: 20px; font-family: 'Inter', sans-serif; font-size: 13.5px;">
                                No clusters configured.
                            </div>
                        @endforelse
                    </div>
                @endif

                @if($clusters->hasPages())
                    <div class="clusters-pagination-bar">
                        {{ $clusters->links('components.pagination') }}
                    </div>
                @endif
            </div>

            <!-- Right Pane: Cluster Form Configurator -->
            @if($selectedClusterId)
                <div class="details-panel">
                @if($successMessage)
                    <div class="toast-alert success" style="margin: 20px 20px 0 20px;">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>{{ $successMessage }}</span>
                    </div>
                @endif

                @if($errorMessage)
                    <div class="toast-alert error" style="margin: 20px 20px 0 20px;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span>{{ $errorMessage }}</span>
                    </div>
                @endif

                @if($selectedClusterId === -2)
                        <!-- Header -->
                        <div class="details-header">
                            <div class="details-header-avatar" style="background-color: #e0f2fe; color: #0369a1;">
                                <i class="fa-solid fa-file-import"></i>
                            </div>
                            <div class="details-header-info">
                                <h2 class="details-header-name">Import Clusters from File</h2>
                                <span class="details-header-sub">Upload a .txt file containing multiple cluster configurations</span>
                            </div>
                        </div>

                        <!-- Body Form -->
                        <div class="details-body">
                            <div class="form-group">
                                <label class="form-label" for="clusterFileInput" style="font-weight: 600; color: #334155; margin-bottom: 8px; display: block;">Select Cluster Text File (.txt)</label>
                                <input type="file" 
                                       id="clusterFileInput" 
                                       wire:model="clusterFile" 
                                       accept=".txt,text/plain" 
                                       style="padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; font-size: 13px; font-family: 'Inter', sans-serif;">
                                <div wire:loading wire:target="clusterFile" style="font-size: 12px; color: #0284c7; margin-top: 6px; font-weight: 600;">
                                    <i class="fa-solid fa-spinner fa-spin"></i> Uploading file... Please wait.
                                </div>
                                @error('clusterFile') <span style="color:#ef4444; font-size:11px; margin-top:4px; display:block;">{{ $message }}</span> @enderror
                                
                                <p style="font-size: 12.5px; color: #64748b; margin-top: 12px; line-height: 1.5; font-family: 'Inter', sans-serif;">
                                    <strong>Instructions:</strong> The uploaded file must be a plain text file (<code>.txt</code>). Every line must end with a semicolon (<code>;</code>):
                                    <br>• Line 1: <code>=&lt;cluster name&gt;;</code> (must start with an equals sign <code>=</code>)
                                    <br>• Line 2: <code>&lt;cluster short code&gt;;</code>
                                    <br>• Line 3 (Optional): <code>&lt;cluster head (office_code or office_name)&gt;;</code>
                                    <br>• Line 4 (Optional): <code>&lt;active status (true/false)&gt;;</code> (e.g. <code>true;</code> or <code>false;</code>)
                                    <br>• Lines starting with <code>#</code> are classified as comments and will be ignored.
                                </p>
                            </div>
                        </div>

                        <!-- Footer Actions -->
                        <div class="details-footer">
                            <button type="button" class="btn-cancel" wire:click="cancelSelection">Cancel</button>
                            <button type="button" class="btn-save" style="background-color: #0284c7; border-color: #0284c7;" 
                                    wire:click="importClusters" 
                                    wire:loading.attr="disabled" 
                                    wire:target="clusterFile, importClusters">
                                <span wire:loading.remove wire:target="clusterFile, importClusters">
                                    <i class="fa-solid fa-file-import"></i> Extract Cluster Data
                                </span>
                                <span wire:loading wire:target="clusterFile, importClusters">
                                    <i class="fa-solid fa-spinner fa-spin"></i> Processing...
                                </span>
                            </button>
                        </div>
                    @else
                        <!-- Header -->
                        <div class="details-header">
                            <div class="details-header-avatar" style="background-color: #f0fdf4; color: #166534;">
                                <i class="fa-solid fa-sitemap"></i>
                            </div>
                            <div class="details-header-info">
                                <h2 class="details-header-name">
                                    {{ $selectedClusterId === -1 ? 'Configure New Cluster' : $clusterName }}
                                </h2>
                                <span class="details-header-sub">
                                    {{ $selectedClusterId === -1 ? 'Add a new cluster entity to group related offices' : 'Review & adjust active cluster definition' }}
                                </span>
                            </div>
                        </div>

                        <!-- Body Form -->
                        <div class="details-body">
                            <form wire:submit.prevent="saveClusterChanges" style="display: flex; flex-direction: column; gap: 20px;">
                                <!-- Cluster Name -->
                                <div class="form-group">
                                    <span class="form-label">Cluster Name</span>
                                    <input type="text" class="form-input" placeholder="e.g. Academic Affairs" wire:model="clusterName">
                                    @error('clusterName') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                                </div>

                                <!-- Cluster Code -->
                                <div class="form-group">
                                    <span class="form-label">Cluster Short Code</span>
                                    <input type="text" class="form-input" placeholder="e.g. ACAD" wire:model="clusterCode">
                                    @error('clusterCode') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                                </div>

                                <!-- Cluster Head (Office dropdown) -->
                                <div class="form-group" wire:click.outside="$set('showClusterHeadDropdown', false)">
                                    <span class="form-label">Cluster Head (Office)</span>
                                    <div style="position: relative;">
                                        <input type="text" 
                                               class="form-input" 
                                               placeholder="Search and select office..." 
                                               wire:model.live="clusterHeadSearch" 
                                               wire:focus="$set('showClusterHeadDropdown', true)" 
                                               autocomplete="off" 
                                               style="padding-right: 32px; background-color: white;">
                                        <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #94a3b8; font-size: 10px;">▼</span>
                                        
                                        @if($showClusterHeadDropdown)
                                            <div style="position: absolute; top: 100%; left: 0; right: 0; margin-top: 4px; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); max-height: 160px; overflow-y: auto; z-index: 50;">
                                                <div wire:click="selectClusterHead('', '')" style="padding: 9px 14px; font-size: 13px; color: #64748b; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-style: italic;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='transparent'">
                                                    None (No Head)
                                                </div>
                                                @php
                                                    $cHeadSearchLower = strtolower($clusterHeadSearch);
                                                    $filteredClusterOffices = $clusterOffices->filter(function($o) use ($cHeadSearchLower) {
                                                        return empty($cHeadSearchLower) 
                                                            || str_contains(strtolower($o->office_name), $cHeadSearchLower) 
                                                            || str_contains(strtolower($o->office_code), $cHeadSearchLower);
                                                    });
                                                @endphp
                                                @forelse($filteredClusterOffices as $headOfficeOption)
                                                    <div wire:click="selectClusterHead('{{ $headOfficeOption->office_code }}', '{{ addslashes($headOfficeOption->office_name) }}')" style="padding: 9px 14px; font-size: 13px; color: #334155; cursor: pointer; border-bottom: 1px solid #f1f5f9;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='transparent'">
                                                        {{ $headOfficeOption->office_name }} ({{ $headOfficeOption->office_code }})
                                                    </div>
                                                @empty
                                                    <div style="padding: 12px 14px; font-size: 13px; color: #94a3b8; text-align: center;">No matching offices found in this cluster</div>
                                                @endforelse
                                            </div>
                                        @endif
                                    </div>
                                    @error('clusterHead') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                                </div>

                                <!-- Active Toggle Wrapper (Only shown for edit mode, not create mode) -->
                                @if($selectedClusterId > 0)
                                    <div class="form-group">
                                        <div class="status-toggle-wrapper">
                                            <div class="status-toggle-label">
                                                <span class="status-toggle-title">Cluster Active Status</span>
                                                <span class="status-toggle-desc">Toggle whether this cluster is active or suspended.</span>
                                            </div>
                                            <label class="switch">
                                                <input type="checkbox" wire:model="clusterIsActive">
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                @endif
                            </form>
                        </div>

                        <!-- Footer Actions -->
                        <div class="details-footer">
                            @if($selectedClusterId > 0)
                                <button type="button" class="btn-delete" wire:click="deleteCluster" wire:confirm="Are you sure you want to deactivate this cluster? It will be soft-deleted (hidden) but retained for transparency." style="margin-right: auto;">
                                    <i class="fa-solid fa-trash-can"></i> Delete Cluster
                                </button>
                            @endif
                            <button type="button" class="btn-cancel" wire:click="cancelSelection">Cancel</button>
                            <button type="button" class="btn-save" wire:click="saveClusterChanges">
                                <i class="fa-solid fa-floppy-disk"></i> Save Configuration
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @elseif($activeTab === 'other_offices')
        <div class="admin-offices-container {{ !$selectedOtherOfficeId ? 'no-selection' : 'has-selection' }}" wire:key="tab-other-offices-view">
            <!-- Left Pane: Other Offices Directory -->
            <div class="directory-panel">
                <div class="directory-header-row">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span class="form-label" style="margin: 0; font-size: 14px; font-weight: 700; color: #0f172a; font-family: 'Outfit', sans-serif;">External Source Offices</span>
                        <span style="background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 700;">{{ $otherOffices->total() }}</span>
                    </div>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn-create-new" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); border: none; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);" wire:click="startCreateOtherOffice">
                            <i class="fa-solid fa-plus"></i> New External Office
                        </button>
                    </div>
                </div>

                <div class="search-box-wrapper" style="margin-top: 10px;">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-box" placeholder="Search external office name or code..." wire:model.live="otherOfficeSearch">
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 4px; margin-top: 4px; border-bottom: 1px solid #e2e8f0;">
                    <span style="font-size: 12px; color: #64748b; font-weight: 500;">Registered External Entities</span>

                    <!-- Layout View Mode Toggle -->
                    <div class="view-mode-toggle" style="display: flex; gap: 2px; background: #f1f5f9; padding: 2px; border-radius: 6px; border: 1px solid #cbd5e1;">
                        <button type="button" wire:click="$set('otherOfficeViewMode', 'grid')" title="Cards Grid Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $otherOfficeViewMode === 'grid' ? '#ffffff' : 'transparent' }}; color: {{ $otherOfficeViewMode === 'grid' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $otherOfficeViewMode === 'grid' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                            <i class="fa-solid fa-border-all"></i> Cards
                        </button>
                        <button type="button" wire:click="$set('otherOfficeViewMode', 'table')" title="Table Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $otherOfficeViewMode === 'table' ? '#ffffff' : 'transparent' }}; color: {{ $otherOfficeViewMode === 'table' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $otherOfficeViewMode === 'table' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                            <i class="fa-solid fa-table-list"></i> Table
                        </button>
                    </div>
                </div>

                @if($otherOfficeViewMode === 'table')
                    <!-- Table Layout View -->
                    <div class="other-offices-table-wrapper" style="margin-top: 10px;">
                        <table class="other-offices-table">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>External Office Name</th>
                                    <th>Created By</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($otherOffices as $so)
                                    <tr class="{{ $selectedOtherOfficeId === $so->id ? 'selected' : '' }}" style="cursor: pointer;" wire:click="selectOtherOffice({{ $so->id }})" wire:key="so-row-{{ $so->id }}">
                                        <td>
                                            <span class="item-code-badge">{{ $so->s_office_code }}</span>
                                        </td>
                                        <td style="font-weight: 600; color: #0f172a;">
                                            {{ $so->s_office_name }}
                                        </td>
                                        <td style="color: #64748b; font-size: 12px;">
                                            <i class="fa-solid fa-circle-user" style="color: #94a3b8; margin-right: 4px;"></i>
                                            {{ $so->creator_office_name ?: ($so->created_by_office ?: 'System') }}
                                        </td>
                                        <td>
                                            @if($so->is_active ?? true)
                                                <span class="status-badge-active">Active</span>
                                            @else
                                                <span class="status-badge-inactive">Inactive</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" style="text-align: center; padding: 32px 16px; color: #94a3b8;">
                                            <i class="fa-solid fa-building-circle-exclamation" style="font-size: 28px; margin-bottom: 8px; color: #cbd5e1; display: block;"></i>
                                            <span style="font-weight: 600;">No external offices found.</span>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <!-- Cards Grid Layout View -->
                    <div class="directory-list grid-view" style="margin-top: 10px;">
                        @forelse($otherOffices as $so)
                            <div class="directory-item {{ $selectedOtherOfficeId === $so->id ? 'selected' : '' }}" wire:click="selectOtherOffice({{ $so->id }})" wire:key="so-item-{{ $so->id }}">
                                <div class="item-avatar">
                                    <i class="fa-solid fa-building-flag"></i>
                                </div>
                                <div class="item-info">
                                    <div class="item-name-row">
                                        <span class="item-name">{{ $so->s_office_name }}</span>
                                        <span class="item-code-badge">{{ $so->s_office_code }}</span>
                                    </div>
                                    <span class="item-meta">
                                        <i class="fa-solid fa-circle-user" style="color: #94a3b8;"></i>
                                        <span class="item-meta-creator">Created by {{ $so->creator_office_name ?: ($so->created_by_office ?: 'System') }}</span>
                                    </span>
                                </div>
                                <div>
                                    <i class="fa-solid fa-chevron-right" style="color: #cbd5e1; font-size: 12px;"></i>
                                </div>
                            </div>
                        @empty
                            <div class="no-results-placeholder" style="padding: 32px 16px; text-align: center;">
                                <i class="fa-solid fa-building-circle-exclamation" style="font-size: 28px; color: #cbd5e1; margin-bottom: 8px; display: block;"></i>
                                <span style="font-weight: 600; color: #64748b;">No external offices found.</span>
                            </div>
                        @endforelse
                    </div>
                @endif

                <div class="other-offices-pagination-bar">
                    {{ $otherOffices->links() }}
                </div>
            </div>

            <!-- Right Pane: Details & Form Panel -->
            @if($selectedOtherOfficeId !== null)
                <div class="details-panel" wire:key="so-details-panel">
                    <div class="details-header" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; color: white;">
                                <i class="fa-solid fa-building-flag"></i>
                            </div>
                            <div>
                                <h2 style="color: white; margin: 0; font-size: 16px; font-weight: 700;">{{ $selectedOtherOfficeId === -1 ? 'Create New External Office' : 'External Office Details' }}</h2>
                                <span style="color: rgba(255,255,255,0.8); font-size: 12px;">Configure external agency info for document tracking.</span>
                            </div>
                        </div>
                    </div>

                    <div class="details-body">
                        <form wire:submit.prevent="saveOtherOfficeChanges">
                            <div class="form-group">
                                <label class="form-label">External Office Name <span style="color: #ef4444;">*</span></label>
                                <input type="text" class="form-input" wire:model="otherOfficeName" placeholder="e.g. Commission on Higher Education - Region V">
                            </div>

                            <div class="form-group">
                                <label class="form-label">External Office Code <span style="color: #ef4444;">*</span></label>
                                <input type="text" class="form-input" wire:model="otherOfficeCode" placeholder="e.g. CHED RO V" style="text-transform: uppercase;">
                                <span style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">This code is stored as metadata on external transactions.</span>
                            </div>

                            @if($selectedOtherOfficeId > 0)
                                <div class="form-group">
                                    <div class="status-toggle-wrapper">
                                        <div class="status-toggle-label">
                                            <span class="status-toggle-title">Active Status</span>
                                            <span class="status-toggle-desc">Toggle whether this external office is active.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="otherOfficeIsActive">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            @endif
                        </form>
                    </div>

                    <div class="details-footer">
                        @if($selectedOtherOfficeId > 0)
                            <button type="button" class="btn-delete" wire:click="deleteOtherOffice" wire:confirm="Are you sure you want to deactivate this external office?" style="margin-right: auto;">
                                <i class="fa-solid fa-trash-can"></i> Deactivate Office
                            </button>
                        @endif
                        <button type="button" class="btn-cancel" wire:click="cancelSelection">Cancel</button>
                        <button type="button" class="btn-save" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); border: none;" wire:click="saveOtherOfficeChanges">
                            <i class="fa-solid fa-floppy-disk"></i> Save Office
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
@else
<div class="miller-container">
    {{-- Top Action Toolbar --}}
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 14px;">
        <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
            <div>
                <h1 style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-sitemap" style="color: #0284c7;"></i> Organizational Units & Offices
                </h1>
                <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">
                    Hierarchical structure of academic colleges, administrative units, and external agency partners.
                </p>
            </div>

            {{-- Scope Selector --}}
            <div style="display: flex; gap: 6px; background: #e2e8f0; padding: 3px; border-radius: 10px;">
                <button type="button" class="scope-pill {{ $activeTab === 'offices' ? 'active' : '' }}" wire:click="switchTab('offices')">
                    <i class="fa-solid fa-building"></i> Internal Offices & Clusters
                </button>
                <button type="button" class="scope-pill {{ $activeTab === 'other_offices' ? 'active' : '' }}" wire:click="switchTab('other_offices')">
                    <i class="fa-solid fa-building-flag"></i> External Other Offices (DTS)
                </button>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 8px;">
            @if($activeTab === 'offices')
                <x-admin.layout-trigger :mode="$layoutMode" />
            <button type="button" wire:click="startCreate" class="m-btn m-btn-primary">
                    <i class="fa-solid fa-plus"></i> New Office
                </button>
                <button type="button" wire:click="startCreateCluster" class="m-btn m-btn-secondary">
                    <i class="fa-solid fa-folder-plus"></i> New Cluster
                </button>
                <button type="button" wire:click="openImportModal('office')" class="m-btn m-btn-secondary" title="Batch import offices via text file">
                    <i class="fa-solid fa-file-arrow-up"></i> Import TXT
                </button>
            @else
                <button type="button" wire:click="startCreateOtherOffice" class="m-btn m-btn-primary">
                    <i class="fa-solid fa-plus"></i> New External Office
                </button>
            @endif

            @if($selectedOfficeId || $selectedClusterId || $selectedOtherOfficeId)
                <button type="button" wire:click="cancelSelection" class="m-btn m-btn-secondary" title="Close active selection">
                    <i class="fa-solid fa-xmark"></i> Close Selection
                </button>
            @endif
        </div>
    </div>

    {{-- Feedback Messages --}}
    @if($successMessage || session('success'))
        <div role="status" style="margin-bottom: 12px; padding: 10px 14px; border: 1px solid #bbf7d0; border-radius: 8px; background: #f0fdf4; color: #15803d; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
            <div><i class="fa-solid fa-circle-check" style="margin-right: 8px;"></i> {{ $successMessage ?: session('success') }}</div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #15803d; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif

    @if($errorMessage || session('error'))
        <div role="alert" style="margin-bottom: 12px; padding: 10px 14px; border: 1px solid #fecaca; border-radius: 8px; background: #fef2f2; color: #b91c1c; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
            <div><i class="fa-solid fa-triangle-exclamation" style="margin-right: 8px;"></i> {{ $errorMessage ?: session('error') }}</div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #b91c1c; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif

    {{-- ======================================================== --}}
    {{-- MODE 1: INTERNAL OFFICES & CLUSTERS --}}
    {{-- ======================================================== --}}
    @if($activeTab === 'offices')
        <div class="miller-browser">
            {{-- COLUMN 1: CLUSTERS & DIVISIONS --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-layer-group" style="color: #64748b;"></i> 1. Clusters & Divisions</span>
                    <span class="m-item-badge">{{ count($clusters) }}</span>
                </div>

                <div class="m-col-search">
                    <i class="fa-solid fa-magnifying-glass" style="font-size: 11px; color: #94a3b8;"></i>
                    <input type="text" placeholder="Search clusters..." wire:model.live="clusterSearch">
                </div>

                <div class="m-col-list">
                    {{-- All Offices root item --}}
                    <div class="m-item {{ $selectedClusterCode === null && $selectedClusterId === null ? 'active' : '' }}" wire:click="filterByCluster(null)">
                        <div class="m-item-left">
                            <i class="fa-solid fa-network-wired m-item-icon" style="font-size: 13px; color: #64748b; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">All College Offices</span>
                                <span class="m-item-sub">System-wide directory</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <span class="m-item-badge">{{ $totalOfficesCount }}</span>
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>

                    @if($selectedClusterId === -1)
                        <div class="m-item active" style="background: #0284c7;">
                            <div class="m-item-left">
                                <div class="m-item-avatar" style="background: rgba(255,255,255,0.25); color: #fff;">
                                    <i class="fa-solid fa-plus"></i>
                                </div>
                                <div class="m-item-text">
                                    <span class="m-item-title">{{ $clusterName ?: 'New Cluster Division' }}</span>
                                    <span class="m-item-sub">[Draft Cluster]</span>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <span class="m-item-badge">New</span>
                            </div>
                        </div>
                    @endif

                    @forelse($clusters as $c)
                        @php
                            $cOffices = $clusterOfficeCounts[$c->cluster_code] ?? 0;
                            $isSelectedFilter = ($selectedClusterCode === $c->cluster_code);
                            $isSelectedEdit = ($selectedClusterId === $c->id);
                        @endphp
                        <div class="m-item {{ ($isSelectedFilter || $isSelectedEdit) ? 'active' : '' }}" wire:click="filterByCluster('{{ $c->cluster_code }}')" wire:key="cluster-{{ $c->id }}">
                            <div class="m-item-left">
                                <i class="fa-solid fa-building-columns m-item-icon" style="font-size: 13px; color: #64748b; width: 18px; text-align: center;"></i>
                                <div class="m-item-text">
                                    <span class="m-item-title">{{ $c->cluster_name }}</span>
                                    <span class="m-item-sub">Code: {{ $c->cluster_code }}</span>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <button type="button" wire:click.stop="selectClusterForEdit({{ $c->id }})" style="background: none; border: none; padding: 2px 5px; color: {{ ($isSelectedFilter || $isSelectedEdit) ? '#fff' : '#64748b' }}; cursor: pointer;" title="Edit Cluster Division">
                                    <i class="fa-solid fa-gear" style="font-size: 11px;"></i>
                                </button>
                                <span class="m-item-badge">{{ $cOffices }}</span>
                                <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                            </div>
                        </div>
                    @empty
                        <div class="m-empty-state">
                            <span style="font-size: 12px; font-weight: 600;">No clusters found</span>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- COLUMN 2: OFFICES DIRECTORY --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span>
                        <i class="fa-solid fa-building" style="color: #64748b;"></i> 
                        2. Offices
                        @if($selectedClusterCode)
                            <span style="font-size: 10px; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 1px 6px; border-radius: 4px;">{{ $selectedClusterCode }}</span>
                        @endif
                    </span>
                    <span class="m-item-badge">{{ count($offices) }}</span>
                </div>

                <div class="m-col-search">
                    <i class="fa-solid fa-magnifying-glass" style="font-size: 11px; color: #94a3b8;"></i>
                    <input type="text" placeholder="Search offices in cluster..." wire:model.live="search">
                </div>

                <div class="m-col-list">
                    @if($selectedOfficeId === -1)
                        <div class="m-item active" style="background: #0284c7;">
                            <div class="m-item-left">
                                <div class="m-item-avatar" style="background: rgba(255,255,255,0.25); color: #fff;">
                                    <i class="fa-solid fa-plus"></i>
                                </div>
                                <div class="m-item-text">
                                    <span class="m-item-title">{{ $officeName ?: 'New Office Unit' }}</span>
                                    <span class="m-item-sub">[Draft Configuration]</span>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <span class="m-item-badge">New</span>
                            </div>
                        </div>
                    @endif

                    @forelse($offices as $off)
                        @php
                            $pCount = $personnelCounts[$off->id] ?? 0;
                            $isOffSelected = ($selectedOfficeId === $off->id);
                        @endphp
                        <div class="m-item {{ $isOffSelected ? 'active' : '' }}" wire:click="selectOffice({{ $off->id }})" wire:key="office-{{ $off->id }}">
                            <div class="m-item-left">
                                <div class="m-item-avatar">
                                    {{ strtoupper(substr($off->office_code ?: '?', 0, 3)) }}
                                </div>
                                <div class="m-item-text">
                                    <span class="m-item-title">{{ $off->office_name }}</span>
                                    <span class="m-item-sub">{{ $off->office_code }} {{ $off->cluster ? '· ' . $off->cluster : '' }}</span>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <span class="m-item-badge">{{ $pCount }} {{ $pCount === 1 ? 'staff' : 'staff' }}</span>
                                <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                            </div>
                        </div>
                    @empty
                        <div class="m-empty-state">
                            <i class="fa-solid fa-building-circle-exclamation m-empty-icon"></i>
                            <span style="font-size: 12px; font-weight: 600;">No offices in this view</span>
                            <span style="font-size: 11px; margin-top: 4px;">Click <strong>+ New Office</strong> to add one</span>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- COLUMN 3: OPERATIONS & PERSONNEL DIRECTORY --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-id-card-clip" style="color: #64748b;"></i> 3. Operations & Assignments</span>
                    @if($selectedOfficeId && $selectedOfficeId > 0)
                        <div style="display: flex; gap: 4px;">
                            <button type="button" wire:click="$set('officeOperationsTab', 'personnel')" class="scope-pill {{ $officeOperationsTab === 'personnel' ? 'active' : '' }}" style="padding: 2px 8px; font-size: 10.5px;">
                                Staff ({{ count($assignedStaff) }})
                            </button>
                            <button type="button" wire:click="$set('officeOperationsTab', 'storage')" class="scope-pill {{ $officeOperationsTab === 'storage' ? 'active' : '' }}" style="padding: 2px 8px; font-size: 10.5px;">
                                Storage / Cloud
                            </button>
                            <button type="button" wire:click="$set('officeOperationsTab', 'brackets')" class="scope-pill {{ $officeOperationsTab === 'brackets' ? 'active' : '' }}" style="padding: 2px 8px; font-size: 10.5px;">
                                RDP ({{ count($rdpBrackets) }})
                            </button>
                        </div>
                    @endif
                </div>

                <div class="m-col-list" style="padding: 12px 14px;">
                    @if($selectedOfficeId && $selectedOfficeId > 0)
                        {{-- 1. Personnel Tab --}}
                        @if($officeOperationsTab === 'personnel')
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                    <span style="font-size: 11.5px; font-weight: 700; color: #475569; text-transform: uppercase;">Assigned Staff Accounts</span>
                                    <span style="font-size: 11px; color: #64748b;">{{ count($assignedStaff) }} Personnel</span>
                                </div>

                                @forelse($assignedStaff as $staff)
                                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; border-radius: 6px; background: #f8fafc; border: 1px solid #e2e8f0;">
                                        <div style="display: flex; align-items: center; gap: 9px; overflow: hidden;">
                                            <div style="width: 28px; height: 28px; border-radius: 50%; background: #0284c7; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 700; flex-shrink: 0;">
                                                {{ strtoupper(substr($staff->first_name ?: $staff->username, 0, 1)) }}
                                            </div>
                                            <div style="display: flex; flex-direction: column; overflow: hidden;">
                                                <span style="font-size: 12px; font-weight: 700; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    {{ $staff->first_name ? $staff->first_name . ' ' . $staff->last_name : $staff->username }}
                                                </span>
                                                <span style="font-size: 10.5px; color: #64748b;">{{ $staff->email ?: '@' . $staff->username }}</span>
                                            </div>
                                        </div>
                                        <span style="font-size: 10px; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 7px; border-radius: 9999px; white-space: nowrap; border: 1px solid #bae6fd;">
                                            {{ $staff->role_name ?: 'Basic User' }}
                                        </span>
                                    </div>
                                @empty
                                    <div class="m-empty-state" style="padding: 24px 0;">
                                        <i class="fa-solid fa-user-group m-empty-icon" style="font-size: 24px;"></i>
                                        <span style="font-size: 12px; font-weight: 600;">No personnel assigned</span>
                                        <span style="font-size: 11px; margin-top: 2px;">Assign staff via the Accounts User directory</span>
                                    </div>
                                @endforelse
                            </div>

                        {{-- 2. Storage / Cloud Tab --}}
                        @elseif($officeOperationsTab === 'storage')
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <div class="m-metric-card" style="flex-direction: column; align-items: stretch; gap: 6px;">
                                    <span class="m-metric-label">Google Drive Target Folder</span>
                                    <div style="font-size: 12px; font-family: monospace; font-weight: 700; color: #0f172a; word-break: break-all;">
                                        {{ $storageData?->folder_id ?: 'Root / Pending auto-creation' }}
                                    </div>
                                </div>

                                <div class="m-metric-card" style="flex-direction: column; align-items: stretch; gap: 6px;">
                                    <span class="m-metric-label">On-Premise NAS S3 Path</span>
                                    <div style="font-size: 12px; font-family: monospace; font-weight: 700; color: #0284c7; word-break: break-all;">
                                        s3://rms-storage/offices/{{ strtolower($officeCode) }}/
                                    </div>
                                </div>

                                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">
                                    <div class="m-metric-card" style="flex-direction: column; align-items: flex-start; gap: 4px;">
                                        <span style="font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase;">DTS Files</span>
                                        <span style="font-size: 13px; font-weight: 800; color: #0f172a;">{{ $storageData?->dts_file_size ?: '0 KB' }}</span>
                                    </div>
                                    <div class="m-metric-card" style="flex-direction: column; align-items: flex-start; gap: 4px;">
                                        <span style="font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase;">RDP Archives</span>
                                        <span style="font-size: 13px; font-weight: 800; color: #0f172a;">{{ $storageData?->rdp_file_size ?: '0 KB' }}</span>
                                    </div>
                                    <div class="m-metric-card" style="flex-direction: column; align-items: flex-start; gap: 4px;">
                                        <span style="font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase;">DCS Intake</span>
                                        <span style="font-size: 13px; font-weight: 800; color: #0f172a;">{{ $storageData?->dcs_file_size ?: '0 KB' }}</span>
                                    </div>
                                </div>
                            </div>

                        {{-- 3. RDP Retention Brackets Tab --}}
                        @elseif($officeOperationsTab === 'brackets')
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                    <span style="font-size: 11.5px; font-weight: 700; color: #475569; text-transform: uppercase;">RDP Retention Bracket Routing</span>
                                    <span style="font-size: 11px; color: #64748b;">{{ count($rdpBrackets) }} Brackets</span>
                                </div>

                                @forelse($rdpBrackets as $rb)
                                    <div style="padding: 8px 10px; border-radius: 6px; background: #f8fafc; border: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 2px;">
                                        <span style="font-size: 12px; font-weight: 700; color: #0f172a;">{{ $rb->bracket_name ?: 'Main Cluster Bracket' }}</span>
                                        <span style="font-size: 11px; color: #64748b;">Sub-Bracket: {{ $rb->sub_name ?: 'Generalized (Office-Wide)' }}</span>
                                    </div>
                                @empty
                                    <div class="m-empty-state" style="padding: 24px 0;">
                                        <i class="fa-solid fa-box-archive m-empty-icon" style="font-size: 24px;"></i>
                                        <span style="font-size: 12px; font-weight: 600;">No RDP brackets mapped</span>
                                        <span style="font-size: 11px; margin-top: 2px;">Assign this office in the Record Series Brackets console</span>
                                    </div>
                                @endforelse
                            </div>
                        @endif

                    @elseif($selectedClusterId && $selectedClusterId > 0)
                        {{-- Cluster Division Overview --}}
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <div style="padding: 10px 12px; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 8px;">
                                <span style="font-size: 11.5px; font-weight: 700; color: #0369a1; display: block;">Cluster Division Selected</span>
                                <span style="font-size: 11px; color: #0284c7;">Review or edit this organizational cluster in Column 4.</span>
                            </div>

                            <div class="m-metric-card">
                                <span class="m-metric-label">Head Office Designation</span>
                                <span class="m-metric-value" style="color: #0284c7;">{{ $clusterHead ?: 'Not Designated' }}</span>
                            </div>

                            <div class="m-metric-card">
                                <span class="m-metric-label">Assigned Child Offices</span>
                                <span class="m-metric-value">{{ $clusterOfficeCounts[$clusterCode] ?? 0 }} Units</span>
                            </div>
                        </div>
                    @else
                        <div class="m-empty-state">
                            <i class="fa-solid fa-hand-pointer m-empty-icon"></i>
                            <span style="font-size: 12px; font-weight: 600;">Select an office from Column 2</span>
                            <span style="font-size: 11px; margin-top: 4px;">to inspect personnel, cloud storage, and retention routing</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- COLUMN 4: OFFICE / CLUSTER INSPECTOR & CONFIGURATION --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-sliders" style="color: #64748b;"></i> 4. Inspector & Settings</span>
                    @if($selectedOfficeId && $selectedOfficeId > 0)
                        <span class="m-item-badge" style="background: {{ $isActive ? '#dcfce7' : '#fee2e2' }}; color: {{ $isActive ? '#15803d' : '#991b1b' }}; border-color: {{ $isActive ? '#bbf7d0' : '#fecaca' }};">
                            {{ $isActive ? 'Active' : 'Suspended' }}
                        </span>
                    @elseif($selectedClusterId && $selectedClusterId > 0)
                        <span class="m-item-badge" style="background: {{ $clusterIsActive ? '#dcfce7' : '#fee2e2' }}; color: {{ $clusterIsActive ? '#15803d' : '#991b1b' }}; border-color: {{ $clusterIsActive ? '#bbf7d0' : '#fecaca' }};">
                            {{ $clusterIsActive ? 'Active' : 'Suspended' }}
                        </span>
                    @endif
                </div>

                @if($selectedOfficeId)
                    {{-- Office Inspector Form --}}
                    <div class="m-inspector">
                        <div class="m-inspector-header">
                            <div class="m-inspector-avatar">
                                <i class="fa-solid fa-building"></i>
                            </div>
                            <div style="overflow: hidden; flex: 1 1 auto;">
                                <h3 class="m-inspector-title">{{ $officeName ?: 'Untitled Office' }}</h3>
                                <span class="m-inspector-sub">{{ $selectedOfficeId === -1 ? 'New Office Configuration' : 'Office Code: ' . $officeCode }}</span>
                            </div>
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">Official Office Name</label>
                            <input type="text" class="m-field-input" placeholder="e.g. Office of the College Registrar" wire:model="officeName">
                            @error('officeName') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">Office Code / Acronym</label>
                            <input type="text" class="m-field-input" placeholder="e.g. REG" wire:model="officeCode" {{ in_array($officeCode, ['ORIGIN', '[H]', '[HUB]', 'HUB']) ? 'readonly' : '' }}>
                            @error('officeCode') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        <div class="m-field-group" style="position: relative;">
                            <label class="m-field-label">Cluster Division Assignment</label>
                            <input type="text" class="m-field-input" placeholder="Search name or abbreviation…" wire:model.live.debounce.150ms="officeClusterSearch" wire:focus="$set('showOfficeClusterDropdown', true)" autocomplete="off">
                            @if($showOfficeClusterDropdown)
                                <div style="position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); z-index: 50; max-height: 180px; overflow-y: auto; margin-top: 2px;">
                                    <div wire:click="selectOfficeCluster('', 'No Cluster Division')" style="padding: 7px 10px; font-size: 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; color: #64748b;">
                                        None (Standalone Office)
                                    </div>
                                    @forelse($clusterPickerOptions as $c)
                                        <div wire:click="selectOfficeCluster(@js($c->cluster_code), @js($c->cluster_name))" style="padding: 7px 10px; font-size: 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; color: #0f172a;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">
                                            <strong>{{ $c->cluster_name }}</strong> ({{ $c->cluster_code }})
                                        </div>
                                    @empty
                                        <div style="padding: 10px; font-size: 12px; color: #64748b;">No cluster matches “{{ $officeClusterSearch }}”.</div>
                                    @endforelse
                                </div>
                            @endif
                        </div>

                        @if($selectedOfficeId > 0 && !in_array($officeCode, ['ORIGIN', '[H]', '[HUB]', 'HUB']))
                            <div class="m-metric-card">
                                <span class="m-metric-label">Operational Active Status</span>
                                <label class="switch">
                                    <input type="checkbox" wire:model="isActive">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        <div style="display: flex; flex-direction: column; gap: 8px; margin-top: auto; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                            <button type="button" class="m-btn m-btn-primary" wire:click="saveOfficeChanges" wire:loading.attr="disabled" style="width: 100%;">
                                <i class="fa-solid fa-floppy-disk" wire:loading.remove wire:target="saveOfficeChanges"></i>
                                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="saveOfficeChanges"></i>
                                <span>Save Office Configuration</span>
                            </button>

                            <div style="display: flex; gap: 8px;">
                                @if($selectedOfficeId > 0 && !in_array($officeCode, ['ORIGIN', '[H]', '[HUB]', 'HUB']))
                                    <button type="button" class="m-btn m-btn-danger" wire:click="deleteOffice" wire:confirm="Are you sure you want to soft-deactivate this office?" style="flex: 1;" title="Soft-deactivate office">
                                        <i class="fa-solid fa-trash-can"></i> Deactivate
                                    </button>
                                @endif
                                <button type="button" class="m-btn m-btn-secondary" wire:click="cancelSelection" style="flex: 1;">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>

                @elseif($selectedClusterId)
                    {{-- Cluster Inspector Form --}}
                    <div class="m-inspector">
                        <div class="m-inspector-header">
                            <div class="m-inspector-avatar" style="background: linear-gradient(135deg, #475569 0%, #334155 100%);">
                                <i class="fa-solid fa-building-columns"></i>
                            </div>
                            <div style="overflow: hidden; flex: 1 1 auto;">
                                <h3 class="m-inspector-title">{{ $clusterName ?: 'Untitled Cluster' }}</h3>
                                <span class="m-inspector-sub">{{ $selectedClusterId === -1 ? 'New Cluster Division' : 'Cluster Code: ' . $clusterCode }}</span>
                            </div>
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">Cluster Division Title</label>
                            <input type="text" class="m-field-input" placeholder="e.g. Academic Affairs Division" wire:model="clusterName">
                            @error('clusterName') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">Cluster Code / Identifier</label>
                            <input type="text" class="m-field-input" placeholder="e.g. VPAA" wire:model="clusterCode">
                            @error('clusterCode') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        <div class="m-field-group" style="position: relative;">
                            <label class="m-field-label">Head Office Designation</label>
                            <input type="text" class="m-field-input" placeholder="Search name or abbreviation…" wire:model.live.debounce.150ms="clusterHeadSearch" wire:focus="$set('showClusterHeadDropdown', true)" autocomplete="off">
                            @if($showClusterHeadDropdown)
                                <div style="position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); z-index: 50; max-height: 180px; overflow-y: auto; margin-top: 2px;">
                                    <div wire:click="selectClusterHead('', 'No Head Office')" style="padding: 7px 10px; font-size: 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; color: #64748b;">
                                        None (Unassigned)
                                    </div>
                                    @forelse($clusterHeadOfficeOptions as $o)
                                        <div wire:click="selectClusterHead(@js($o->office_code), @js($o->office_name))" style="padding: 7px 10px; font-size: 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; color: #0f172a;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">
                                            <strong>{{ $o->office_name }}</strong> ({{ $o->office_code }})
                                        </div>
                                    @empty
                                        <div style="padding: 10px; font-size: 12px; color: #64748b;">No office matches “{{ $clusterHeadSearch }}”.</div>
                                    @endforelse
                                </div>
                            @endif
                        </div>

                        @if($selectedClusterId > 0)
                            <div class="m-metric-card">
                                <span class="m-metric-label">Active Status</span>
                                <label class="switch">
                                    <input type="checkbox" wire:model="clusterIsActive">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        <div style="display: flex; flex-direction: column; gap: 8px; margin-top: auto; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                            <button type="button" class="m-btn m-btn-primary" wire:click="saveClusterChanges" wire:loading.attr="disabled" style="width: 100%;">
                                <i class="fa-solid fa-floppy-disk" wire:loading.remove wire:target="saveClusterChanges"></i>
                                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="saveClusterChanges"></i>
                                <span>Save Cluster Details</span>
                            </button>

                            <div style="display: flex; gap: 8px;">
                                @if($selectedClusterId > 0)
                                    <button type="button" class="m-btn m-btn-danger" wire:click="deleteCluster" wire:confirm="Are you sure you want to soft-deactivate this cluster division?" style="flex: 1;" title="Soft-deactivate cluster">
                                        <i class="fa-solid fa-trash-can"></i> Deactivate
                                    </button>
                                @endif
                                <button type="button" class="m-btn m-btn-secondary" wire:click="cancelSelection" style="flex: 1;">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="m-empty-state">
                        <i class="fa-solid fa-building-circle-check m-empty-icon"></i>
                        <span style="font-size: 13px; font-weight: 700; color: #475569;">Office Inspector</span>
                        <span style="font-size: 11.5px; margin-top: 4px; max-width: 220px; line-height: 1.4;">
                            Select an office or click <strong>+ New Office</strong> to inspect properties, adjust clusters, and configure operations.
                        </span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Bottom Breadcrumb Path Bar --}}
        <div class="miller-path-bar">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-sitemap" style="color: #64748b;"></i>
                <span>College Hierarchy</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
                <span class="miller-path-tag">{{ $selectedClusterCode ?: 'All Clusters' }}</span>
                @if($selectedOfficeId && $selectedOfficeId > 0)
                    <i class="fa-solid fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
                    <span style="color: #0284c7; font-weight: 700;">{{ $officeName }} ({{ $officeCode }})</span>
                    <i class="fa-solid fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
                    <span>{{ count($assignedStaff) }} Active Staff</span>
                @endif
            </div>

            @if($selectedOfficeId)
                <button type="button" wire:click="saveOfficeChanges" style="background: none; border: none; color: #0284c7; font-size: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-floppy-disk"></i> Quick Save Office
                </button>
            @endif
        </div>

    {{-- ======================================================== --}}
    {{-- MODE 2: EXTERNAL OTHER OFFICES (DTS SOURCE AGENCIES) --}}
    {{-- ======================================================== --}}
    @else
        <div class="miller-browser">
            {{-- COLUMN 1: EXTERNAL FILTERS --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-filter" style="color: #64748b;"></i> 1. Agency Filters</span>
                    <span class="m-item-badge">{{ count($otherOffices) }}</span>
                </div>

                <div class="m-col-list">
                    <div class="m-item {{ $otherOfficeFilter === 'all' ? 'active' : '' }}" wire:click="$set('otherOfficeFilter', 'all')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-globe m-item-icon" style="font-size: 13px; color: #64748b; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">All External Sources</span>
                                <span class="m-item-sub">All registered partner agencies</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>

                    <div class="m-item {{ $otherOfficeFilter === 'active' ? 'active' : '' }}" wire:click="$set('otherOfficeFilter', 'active')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-circle-check m-item-icon" style="font-size: 13px; color: #16a34a; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">Active Senders</span>
                                <span class="m-item-sub">Available in DTS External filing</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>

                    <div class="m-item {{ $otherOfficeFilter === 'inactive' ? 'active' : '' }}" wire:click="$set('otherOfficeFilter', 'inactive')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-circle-xmark m-item-icon" style="font-size: 13px; color: #ef4444; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">Deactivated Agencies</span>
                                <span class="m-item-sub">Soft-deleted contacts</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>
                </div>
            </div>

            {{-- COLUMN 2: EXTERNAL AGENCIES DIRECTORY --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-building-flag" style="color: #64748b;"></i> 2. External Directory</span>
                    <span class="m-item-badge">{{ count($otherOffices) }}</span>
                </div>

                <div class="m-col-search">
                    <i class="fa-solid fa-magnifying-glass" style="font-size: 11px; color: #94a3b8;"></i>
                    <input type="text" placeholder="Search external agencies..." wire:model.live="otherOfficeSearch">
                </div>

                <div class="m-col-list">
                    @if($selectedOtherOfficeId === -1)
                        <div class="m-item active" style="background: #0284c7;">
                            <div class="m-item-left">
                                <div class="m-item-avatar" style="background: rgba(255,255,255,0.25); color: #fff;">
                                    <i class="fa-solid fa-plus"></i>
                                </div>
                                <div class="m-item-text">
                                    <span class="m-item-title">{{ $otherOfficeName ?: 'New External Agency' }}</span>
                                    <span class="m-item-sub">[Draft Entry]</span>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <span class="m-item-badge">New</span>
                            </div>
                        </div>
                    @endif

                    @forelse($otherOffices as $oth)
                        <div class="m-item {{ $selectedOtherOfficeId === $oth->id ? 'active' : '' }}" wire:click="selectOtherOffice({{ $oth->id }})" wire:key="oth-{{ $oth->id }}">
                            <div class="m-item-left">
                                <div class="m-item-avatar" style="background: #f1f5f9; color: #475569;">
                                    {{ strtoupper(substr($oth->s_office_code ?: '?', 0, 3)) }}
                                </div>
                                <div class="m-item-text">
                                    <span class="m-item-title">{{ $oth->s_office_name }}</span>
                                    <span class="m-item-sub">Code: {{ $oth->s_office_code }}</span>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                            </div>
                        </div>
                    @empty
                        <div class="m-empty-state">
                            <span style="font-size: 12px; font-weight: 600;">No external agencies found</span>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- COLUMN 3: INCOMING INFLOWS & RECEIVING INTERNAL OFFICES --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-file-import" style="color: #64748b;"></i> 3. Inflows & Receiving Offices</span>
                    @if($selectedOtherOfficeId && $selectedOtherOfficeId > 0)
                        <span class="m-item-badge">{{ $incomingCount }} Logged</span>
                    @endif
                </div>

                <div class="m-col-list" style="padding: 12px 14px;">
                    @if($selectedOtherOfficeId && $selectedOtherOfficeId > 0)
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            {{-- Top Receiving Internal Departments --}}
                            <div>
                                <span style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; display: block; margin-bottom: 6px;">
                                    Top Receiving College Offices
                                </span>
                                @if(count($topReceivingOffices) > 0)
                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                        @foreach($topReceivingOffices as $tro)
                                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0; font-size: 11.5px;">
                                                <span style="font-weight: 700; color: #0f172a;">{{ $tro->current_office_hold }}</span>
                                                <span style="font-weight: 600; color: #0284c7;">{{ $tro->count }} {{ $tro->count === 1 ? 'doc' : 'docs' }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <span style="font-size: 11px; color: #94a3b8; font-style: italic;">No transactions recorded from this external agency yet.</span>
                                @endif
                            </div>

                            {{-- Recent Transactions Log --}}
                            <div>
                                <span style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; display: block; margin-bottom: 6px;">
                                    Recent Incoming Transactions
                                </span>
                                @forelse($incomingTransactions as $it)
                                    <div style="padding: 8px 10px; border-radius: 6px; background: #f8fafc; border: 1px solid #e2e8f0; margin-bottom: 6px; display: flex; flex-direction: column; gap: 2px;">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-size: 11.5px; font-weight: 800; color: #0284c7; font-family: monospace;">{{ $it->control_number ?? ($it->id ?? 'DTS-DOC') }}</span>
                                            <span style="font-size: 10px; color: #64748b;">
                                                @if(!empty($it->date_created))
                                                    {{ \Carbon\Carbon::parse($it->date_created)->format('M d, Y') }}
                                                @elseif(!empty($it->created_at))
                                                    {{ \Carbon\Carbon::parse($it->created_at)->format('M d, Y') }}
                                                @endif
                                            </span>
                                        </div>
                                        <span style="font-size: 11.5px; color: #1e293b; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            {{ $it->subject ?? 'No subject' }}
                                        </span>
                                    </div>
                                @empty
                                    <div class="m-empty-state" style="padding: 16px 0;">
                                        <span style="font-size: 11.5px; color: #94a3b8;">No incoming transactions logged</span>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    @else
                        <div class="m-empty-state">
                            <i class="fa-solid fa-hand-pointer m-empty-icon"></i>
                            <span style="font-size: 12px; font-weight: 600;">Select an agency from Column 2</span>
                            <span style="font-size: 11px; margin-top: 4px;">to inspect transaction history and receiving units</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- COLUMN 4: EXTERNAL AGENCY INSPECTOR --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-sliders" style="color: #64748b;"></i> 4. Agency Inspector</span>
                    @if($selectedOtherOfficeId && $selectedOtherOfficeId > 0)
                        <span class="m-item-badge" style="background: {{ $otherOfficeIsActive ? '#dcfce7' : '#fee2e2' }}; color: {{ $otherOfficeIsActive ? '#15803d' : '#991b1b' }}; border-color: {{ $otherOfficeIsActive ? '#bbf7d0' : '#fecaca' }};">
                            {{ $otherOfficeIsActive ? 'Active' : 'Suspended' }}
                        </span>
                    @endif
                </div>

                @if($selectedOtherOfficeId)
                    <div class="m-inspector">
                        <div class="m-inspector-header">
                            <div class="m-inspector-avatar" style="background: linear-gradient(135deg, #059669 0%, #047857 100%);">
                                <i class="fa-solid fa-building-flag"></i>
                            </div>
                            <div style="overflow: hidden; flex: 1 1 auto;">
                                <h3 class="m-inspector-title">{{ $otherOfficeName ?: 'Untitled Agency' }}</h3>
                                <span class="m-inspector-sub">{{ $selectedOtherOfficeId === -1 ? 'New External Agency' : 'Code: ' . $otherOfficeCode }}</span>
                            </div>
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">External Agency / Office Name</label>
                            <input type="text" class="m-field-input" placeholder="e.g. Commission on Higher Education (CHED)" wire:model="otherOfficeName">
                            @error('otherOfficeName') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">Agency Code / Acronym</label>
                            <input type="text" class="m-field-input" placeholder="e.g. CHED" wire:model="otherOfficeCode">
                            @error('otherOfficeCode') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        @if($otherOfficeCreatedBy)
                            <div class="m-metric-card">
                                <span class="m-metric-label">Liaison Registering Unit</span>
                                <span class="m-metric-value" style="color: #0284c7;">{{ $otherOfficeCreatedBy }}</span>
                            </div>
                        @endif

                        @if($selectedOtherOfficeId > 0)
                            <div class="m-metric-card">
                                <span class="m-metric-label">Active Sender in DTS</span>
                                <label class="switch">
                                    <input type="checkbox" wire:model="otherOfficeIsActive">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        <div style="display: flex; flex-direction: column; gap: 8px; margin-top: auto; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                            <button type="button" class="m-btn m-btn-primary" wire:click="saveOtherOfficeChanges" wire:loading.attr="disabled" style="width: 100%;">
                                <i class="fa-solid fa-floppy-disk" wire:loading.remove wire:target="saveOtherOfficeChanges"></i>
                                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="saveOtherOfficeChanges"></i>
                                <span>Save Agency Details</span>
                            </button>

                            <div style="display: flex; gap: 8px;">
                                @if($selectedOtherOfficeId > 0)
                                    <button type="button" class="m-btn m-btn-danger" wire:click="deleteOtherOffice" wire:confirm="Are you sure you want to deactivate this external agency?" style="flex: 1;" title="Soft-deactivate agency">
                                        <i class="fa-solid fa-trash-can"></i> Deactivate
                                    </button>
                                @endif
                                <button type="button" class="m-btn m-btn-secondary" wire:click="cancelSelection" style="flex: 1;">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="m-empty-state">
                        <i class="fa-solid fa-building-flag m-empty-icon"></i>
                        <span style="font-size: 13px; font-weight: 700; color: #475569;">External Agency Inspector</span>
                        <span style="font-size: 11.5px; margin-top: 4px; max-width: 220px; line-height: 1.4;">
                            Select an agency or click <strong>+ New External Office</strong> to edit incoming partner parameters.
                        </span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Bottom Breadcrumb Path Bar --}}
        <div class="miller-path-bar">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-globe" style="color: #64748b;"></i>
                <span>External Agencies</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
                <span class="miller-path-tag">{{ $selectedOtherOfficeId ? $otherOfficeName . ' (' . $otherOfficeCode . ')' : 'Directory' }}</span>
                @if($selectedOtherOfficeId && $selectedOtherOfficeId > 0)
                    <i class="fa-solid fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
                    <span style="color: #0284c7; font-weight: 700;">{{ $incomingCount }} Logged Inflows</span>
                @endif
            </div>

            @if($selectedOtherOfficeId)
                <button type="button" wire:click="saveOtherOfficeChanges" style="background: none; border: none; color: #0284c7; font-size: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-floppy-disk"></i> Quick Save Agency
                </button>
            @endif
        </div>
    @endif

    {{-- Import Modal --}}
    @if($showImportModal)
        <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.45); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center;">
            <div style="background: white; border-radius: 12px; padding: 24px; width: 460px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); display: flex; flex-direction: column; gap: 16px; border: 1px solid #cbd5e1; font-family: 'Inter', system-ui, sans-serif;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; border-radius: 10px; background: #e0f2fe; display: flex; align-items: center; justify-content: center; color: #0369a1;">
                        <i class="fa-solid fa-file-arrow-up" style="font-size: 18px;"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 15px; font-weight: 800; color: #0f172a; margin: 0;">Batch Import Offices (.TXT)</h3>
                        <p style="font-size: 11.5px; color: #64748b; margin: 2px 0 0 0;">Upload a text file formatted with semicolons and equals signs.</p>
                    </div>
                </div>

                <div style="padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 11px; color: #475569; font-family: monospace;">
                    =Office Name;<br>
                    OFFICE_CODE;<br>
                    true; # (optional: active)<br>
                    CLUSTER_CODE; # (optional)
                </div>

                <input type="file" wire:model="officeFile" style="font-size: 12px; padding: 8px; border: 1px dashed #cbd5e1; border-radius: 6px; width: 100%; box-sizing: border-box;">
                @error('officeFile') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror

                <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; border-top: 1px solid #f1f5f9; padding-top: 12px;">
                    <button type="button" wire:click="$set('showImportModal', false)" class="m-btn m-btn-secondary">Cancel</button>
                    <button type="button" wire:click="importOffices" class="m-btn m-btn-primary" wire:loading.attr="disabled">
                        <i class="fa-solid fa-upload" wire:loading.remove wire:target="importOffices"></i>
                        <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="importOffices"></i>
                        <span>Upload & Import</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

@endif

