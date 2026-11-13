<?php
/**
 * Admin Console - DTS Transaction Flows Volt Component
 * 
 * VMware ESXi / macOS Finder inspired Miller Columns UI.
 * Column 1: Scopes & Purpose Filters
 * Column 2: Workflow Directory
 * Column 3: Flow Configuration & RDP Continuity
 * Column 4: Routing Pipeline, Stops & Distribution
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Admin Console - DTS Transaction Flows')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    /** @var string Active tab: 'predefined' or 'custom' */
    public string $activeTab = 'predefined';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_dts_admin && !$perms->can_dts_modify_docflow)) {
            $this->redirect(route('portal'));
            return;
        }

        $this->loadContinRdpTypes();
    }

    // ---- PREDEFINED FLOW EDITOR PROPERTIES ----
    /** @var string Selected action/subsystem option (empty, 'new', 'import', or numeric ID) */
    public string $selectedPredefined = '';
    public string $predefinedViewMode = 'table'; // 'table' or 'grid'

    public string $flowName = '';
    public string $flowCode = '';
    public bool $isActive = true;
    public string $flowUse = 'none';
    public array $flowOffices = ['ORIGIN', 'ORIGIN']; // list of office codes in sequence
    public string $selectedOffice = ''; // selected office from dropdown to add
    public string $officeSearch = ''; // text query for searching offices to add
    public $flowFile; // file upload property

    // Pipeline Sub-tab for Column 4: 'sequence', 'cf', 'hub'
    public string $flowPipelineTab = 'sequence';

    // ---- BULK SELECTION ----
    public array $selectedFlowIds = [];

    // ---- COPY FURNISHED EDITOR PROPERTIES ----
    public array $cfOffices = []; // list of office codes for predefined copy furnished
    public string $cfSearch = ''; // text query for searching copy furnished offices to add
    public string $selectedCfOffice = ''; // selected office from dropdown for copy furnished

    // ---- HUB PREDEFINED OFFICES PROPERTIES ----
    public array $hubOffices = []; // list of office codes for predefined hub recipients
    public string $hubSearch = ''; // text query for searching hub offices to add
    public string $selectedHubOffice = ''; // selected office from dropdown for hub

    // ---- CUSTOM FLOW EDITOR PROPERTIES ----
    public string $selectedCustom = '';
    public string $customViewMode = 'table';
    public string $customFlowName = '';
    public string $customFlowCode = '';
    public bool $customIsActive = true;
    public string $customFlowUse = 'none';
    public string $customFlowFor = 'system'; // 'system', 'office', or 'user'
    public array $customFlowOffices = ['ORIGIN', 'ORIGIN'];
    public string $customSelectedOffice = '';
    public string $customOfficeSearch = '';
    public array $customCfOffices = [];
    public string $customCfSearch = '';
    public string $customSelectedCfOffice = '';
    public array $customHubOffices = [];
    public string $customHubSearch = '';
    public string $customSelectedHubOffice = '';
    public array $selectedCustomFlowIds = [];
    public string $customPipelineTab = 'sequence';

    // ---- RDP CONTINUITY (RECORD SERIES) ----
    /** @var int|null Selected rdp_record_series id for the predefined flow (null = Server default) */
    public $continRdpId = null;
    public string $continRdpSearch = '';
    public string $continRdpTypeFilter = '';
    /** @var array<int, array{id:int, type_name:string}> Record series types for the filter dropdown */
    public array $continRdpTypes = [];
    /** @var int|null Selected rdp_record_series id for the custom flow (null = Server default) */
    public $customContinRdpId = null;
    public string $customContinRdpSearch = '';
    public string $customContinRdpTypeFilter = '';
    public string $customPurposeFilter = 'all';

    // ---- SEARCH & FILTER FOR FLOWS ----
    public string $searchCustom = '';
    public string $searchPredefined = '';
    public string $predefinedPurposeFilter = 'all';
    public string $statusFilter = 'all'; // 'all', 'active', 'inactive'

    // Dropdown helpers
    public bool $showOfficeDropdown = false;
    public bool $showCfDropdown = false;
    public bool $showHubDropdown = false;
    public bool $showRdpDropdown = false;

    public bool $showCustomOfficeDropdown = false;
    public bool $showCustomCfDropdown = false;
    public bool $showCustomHubDropdown = false;
    public bool $showCustomRdpDropdown = false;

    // ---- ALERTS ----
    public string $successMessage = '';
    public string $errorMessage = '';

    /**
     * Component Lifecycles.
     */
    public function updatingActiveTab(): void
    {
        $this->resetPage();
        $this->clearMessages();
    }

    public function updatingSearchCustom(): void
    {
        $this->resetPage();
    }

    public function updatingSearchPredefined(): void
    {
        $this->resetPage();
        $this->selectedFlowIds = [];
    }

    public function updatingPredefinedPurposeFilter(): void
    {
        $this->resetPage();
        $this->selectedFlowIds = [];
    }

    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    public function resetForm(): void
    {
        $this->selectedPredefined = '';
        $this->flowName = '';
        $this->flowCode = '';
        $this->isActive = true;
        $this->flowOffices = ['ORIGIN', 'ORIGIN'];
        $this->selectedOffice = '';
        $this->officeSearch = '';
        $this->cfOffices = [];
        $this->cfSearch = '';
        $this->selectedCfOffice = '';
        $this->hubOffices = [];
        $this->hubSearch = '';
        $this->selectedHubOffice = '';
        $this->continRdpId = null;
        $this->continRdpSearch = '';
        $this->continRdpTypeFilter = '';
        $this->flowFile = null;
        $this->selectedFlowIds = [];
        $this->clearMessages();
    }

    // ---- RDP CONTINUITY (RECORD SERIES) HELPERS ----

    /**
     * Load the record series types available for the type filter dropdown.
     */
    public function loadContinRdpTypes(): void
    {
        $this->continRdpTypes = [];

        if (!\Illuminate\Support\Facades\Schema::hasTable('rdp_record_series_type')) {
            return;
        }

        $query = \DB::table('rdp_record_series_type')->where('is_active', true);
        if (\Illuminate\Support\Facades\Schema::hasColumn('rdp_record_series_type', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $this->continRdpTypes = $query->orderBy('type_name', 'asc')
            ->get(['id', 'type_name'])
            ->map(fn ($type) => ['id' => (int) $type->id, 'type_name' => $type->type_name])
            ->toArray();
    }

    /**
     * Record series choices (search + type filtered) for the flow details pickers.
     */
    public function continRdpChoices(string $search, string $typeFilter): \Illuminate\Support\Collection
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('rdp_record_series')) {
            return collect();
        }

        $query = \DB::table('rdp_record_series')
            ->leftJoin('rdp_record_series_type', 'rdp_record_series.series_type', '=', 'rdp_record_series_type.id')
            ->where('rdp_record_series.is_active', true)
            ->select('rdp_record_series.id', 'rdp_record_series.item_number', 'rdp_record_series.series_title', 'rdp_record_series_type.shorted_type');

        $term = trim($search);
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('rdp_record_series.series_title', 'ilike', '%' . $term . '%')
                    ->orWhereRaw('CAST(rdp_record_series.item_number AS TEXT) ILIKE ?', ['%' . $term . '%']);
            });
        }

        if (trim($typeFilter) !== '') {
            $query->where('rdp_record_series.series_type', (int) $typeFilter);
        }

        return $query->orderBy('rdp_record_series.series_title', 'asc')->limit(15)->get();
    }

    public function continRdpSelectedLabel($id, string $search): string
    {
        if ($id === null || $id === '' || !\Illuminate\Support\Facades\Schema::hasTable('rdp_record_series')) {
            return $search;
        }

        $itemNo = \DB::table('rdp_record_series')->where('id', (int) $id)->value('item_number');

        return ($itemNo !== null ? '#' . $itemNo . ' · ' : '') . $search;
    }

    public function selectContinRdpSeries(int $id): void
    {
        $title = $this->continRdpTitle($id);
        if ($title === null) return;

        $this->continRdpId = $id;
        $this->continRdpSearch = $title;
        $this->showRdpDropdown = false;
    }

    public function clearContinRdpSeries(): void
    {
        $this->continRdpId = null;
        $this->continRdpSearch = '';
    }

    public function selectCustomContinRdpSeries(int $id): void
    {
        $title = $this->continRdpTitle($id);
        if ($title === null) return;

        $this->customContinRdpId = $id;
        $this->customContinRdpSearch = $title;
        $this->showCustomRdpDropdown = false;
    }

    public function clearCustomContinRdpSeries(): void
    {
        $this->customContinRdpId = null;
        $this->customContinRdpSearch = '';
    }

    public function updatedContinRdpSearch(string $value): void
    {
        if ($this->continRdpId === null) return;

        $title = $this->continRdpTitle((int) $this->continRdpId);
        if ($title === null || trim($value) !== $title) {
            $this->continRdpId = null;
        }
    }

    public function updatedCustomContinRdpSearch(string $value): void
    {
        if ($this->customContinRdpId === null) return;

        $title = $this->continRdpTitle((int) $this->customContinRdpId);
        if ($title === null || trim($value) !== $title) {
            $this->customContinRdpId = null;
        }
    }

    private function continRdpTitle(int $id): ?string
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('rdp_record_series')) {
            return null;
        }

        $title = \DB::table('rdp_record_series')->where('id', $id)->value('series_title');

        return $title === null ? null : trim((string) $title);
    }

    public function selectFlow($id): void
    {
        $this->clearMessages();
        $this->selectedPredefined = (string) $id;
        $this->updatedSelectedPredefined((string) $id);
    }

    public function startCreate(): void
    {
        $this->clearMessages();
        $this->selectedPredefined = 'new';
        $this->updatedSelectedPredefined('new');
    }

    public function startImport(): void
    {
        $this->clearMessages();
        $this->selectedPredefined = 'import';
        $this->updatedSelectedPredefined('import');
    }

    public function selectOfficeForAppend(string $code, string $name): void
    {
        $this->selectedOffice = $code;
        $this->officeSearch = "$name ($code)";
        $this->showOfficeDropdown = false;
        $this->addOfficeToPath();
    }

    public function selectCfOfficeForAppend(string $code, string $name): void
    {
        $this->selectedCfOffice = $code;
        $this->cfSearch = "$name ($code)";
        $this->showCfDropdown = false;
        $this->addCfOfficeToPath();
    }

    public function addCfOfficeToPath(): void
    {
        if ($this->selectedCfOffice === '') return;

        if (!in_array($this->selectedCfOffice, $this->cfOffices)) {
            $this->cfOffices[] = $this->selectedCfOffice;
        }

        $this->selectedCfOffice = '';
        $this->cfSearch = '';
    }

    public function removeCfOffice(int $index): void
    {
        if (isset($this->cfOffices[$index])) {
            array_splice($this->cfOffices, $index, 1);
        }
    }

    public function selectHubOfficeForAppend(string $code, string $name): void
    {
        $this->selectedHubOffice = $code;
        $this->hubSearch = "$name ($code)";
        $this->showHubDropdown = false;
        $this->addHubOfficeToPath();
    }

    public function addHubOfficeToPath(): void
    {
        if ($this->selectedHubOffice === '') return;

        if (!in_array($this->selectedHubOffice, $this->hubOffices)) {
            $this->hubOffices[] = $this->selectedHubOffice;
        }

        $this->selectedHubOffice = '';
        $this->hubSearch = '';
    }

    public function removeHubOffice(int $index): void
    {
        if (isset($this->hubOffices[$index])) {
            array_splice($this->hubOffices, $index, 1);
        }
    }

    public function selectAllHubOffices(): void
    {
        $allActive = \DB::table('sys_office')
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]'])
            ->orderByRaw("COALESCE(cluster, 'ZZZZ') ASC")
            ->orderBy('office_name', 'asc')
            ->pluck('office_code')
            ->toArray();

        $this->hubOffices = $allActive;
    }

    public function clearHubOffices(): void
    {
        $this->hubOffices = [];
    }

    // ---- CUSTOM FLOW MANAGER METHODS ----
    public function selectCustomFlow($id): void
    {
        $this->clearMessages();
        $this->selectedCustom = (string) $id;
        $this->updatedSelectedCustom((string) $id);
    }

    public function startCreateCustom(): void
    {
        $this->clearMessages();
        $this->selectedCustom = 'new';
        $this->updatedSelectedCustom('new');
    }

    public function updatedSelectedCustom(string $value): void
    {
        $this->clearMessages();

        if ($value === '' || $value === 'new') {
            $this->customFlowName = '';
            $this->customFlowCode = '';
            $this->customIsActive = true;
            $this->customFlowUse = 'none';
            $this->customFlowFor = 'system';
            $this->customFlowOffices = ['ORIGIN', 'ORIGIN'];
            $this->customOfficeSearch = '';
            $this->customCfOffices = [];
            $this->customCfSearch = '';
            $this->customSelectedCfOffice = '';
            $this->customHubOffices = [];
            $this->customHubSearch = '';
            $this->customSelectedHubOffice = '';
            $this->customContinRdpId = null;
            $this->customContinRdpSearch = '';
            $this->customContinRdpTypeFilter = '';
            return;
        }

        $flow = \DB::table('dts_transaction_flow')->where('id', $value)->first();
        if ($flow) {
            $this->customFlowName = $flow->flow_name;
            $this->customFlowCode = $flow->flow_code;
            $this->customIsActive = (bool) $flow->is_active;
            $this->customFlowUse = $flow->flow_use ?? 'none';
            $this->customFlowFor = $flow->flow_for ?? 'system';

            // Load linked RDP record series (null = Server default)
            $this->customContinRdpId = (isset($flow->contin_rdp_id) && $flow->contin_rdp_id !== null)
                ? (int) $flow->contin_rdp_id
                : null;
            $this->customContinRdpSearch = $this->customContinRdpId !== null
                ? (string) ($this->continRdpTitle($this->customContinRdpId) ?? '')
                : '';
            $this->customContinRdpTypeFilter = '';

            $offices = \DB::table('dts_sequence_list')
                ->where('control_id', $flow->id)
                ->orderBy('sequence_ranking')
                ->pluck('office_code')
                ->toArray();

            if (empty($offices)) {
                $offices = ['ORIGIN'];
            } elseif ($offices[0] !== 'ORIGIN') {
                array_unshift($offices, 'ORIGIN');
            }
            $this->customFlowOffices = $offices;

            $cfTx = \DB::table('dts_copy_filled_transaction')
                ->where('control_num', $flow->flow_code)
                ->first();
            if ($cfTx) {
                $this->customCfOffices = \DB::table('dts_copy_filled_to_office')
                    ->where('control_id', $cfTx->assign_offices_id)
                    ->pluck('office_code')
                    ->toArray();
            } else {
                $this->customCfOffices = [];
            }
            $this->customCfSearch = '';
            $this->customSelectedCfOffice = '';

            // Load custom predefined HUB offices
            $this->customHubOffices = \DB::table('dts_hub_flow_datas')
                ->where('flow_owner', $flow->id)
                ->pluck('offices_hub')
                ->toArray();
            $this->customHubSearch = '';
            $this->customSelectedHubOffice = '';
        }
    }

    public function selectCustomOfficeForAppend(string $code, string $name): void
    {
        $this->customSelectedOffice = $code;
        $this->customOfficeSearch = "$name ($code)";
        $this->showCustomOfficeDropdown = false;
        $this->addCustomOfficeToPath();
    }

    public function addCustomOfficeToPath(): void
    {
        if ($this->customSelectedOffice === '') return;

        if (empty($this->customFlowOffices)) {
            $this->customFlowOffices[] = 'ORIGIN';
        } elseif ($this->customFlowOffices[0] !== 'ORIGIN') {
            array_unshift($this->customFlowOffices, 'ORIGIN');
        }

        $count = count($this->customFlowOffices);
        if ($count > 1 && $this->customFlowOffices[$count - 1] === 'ORIGIN') {
            array_splice($this->customFlowOffices, $count - 1, 0, $this->customSelectedOffice);
        } else {
            $this->customFlowOffices[] = $this->customSelectedOffice;
        }

        $this->customSelectedOffice = '';
        $this->customOfficeSearch = '';
    }

    public function moveCustomOfficeUp(int $index): void
    {
        if ($index <= 1 || !isset($this->customFlowOffices[$index])) return;
        $temp = $this->customFlowOffices[$index - 1];
        $this->customFlowOffices[$index - 1] = $this->customFlowOffices[$index];
        $this->customFlowOffices[$index] = $temp;
    }

    public function moveCustomOfficeDown(int $index): void
    {
        if ($index === 0 || $index >= count($this->customFlowOffices) - 1 || !isset($this->customFlowOffices[$index])) return;
        $temp = $this->customFlowOffices[$index + 1];
        $this->customFlowOffices[$index + 1] = $this->customFlowOffices[$index];
        $this->customFlowOffices[$index] = $temp;
    }

    public function removeCustomOffice(int $index): void
    {
        if ($index === 0 || !isset($this->customFlowOffices[$index])) return;
        array_splice($this->customFlowOffices, $index, 1);
    }

    public function selectCustomCfOfficeForAppend(string $code, string $name): void
    {
        $this->customSelectedCfOffice = $code;
        $this->customCfSearch = "$name ($code)";
        $this->showCustomCfDropdown = false;
        $this->addCustomCfOfficeToPath();
    }

    public function addCustomCfOfficeToPath(): void
    {
        if ($this->customSelectedCfOffice === '') return;
        if (!in_array($this->customSelectedCfOffice, $this->customCfOffices)) {
            $this->customCfOffices[] = $this->customSelectedCfOffice;
        }
        $this->customSelectedCfOffice = '';
        $this->customCfSearch = '';
    }

    public function removeCustomCfOffice(int $index): void
    {
        if (isset($this->customCfOffices[$index])) {
            array_splice($this->customCfOffices, $index, 1);
        }
    }

    public function selectCustomHubOfficeForAppend(string $code, string $name): void
    {
        $this->customSelectedHubOffice = $code;
        $this->customHubSearch = "$name ($code)";
        $this->showCustomHubDropdown = false;
        $this->addCustomHubOfficeToPath();
    }

    public function addCustomHubOfficeToPath(): void
    {
        if ($this->customSelectedHubOffice === '') return;
        if (!in_array($this->customSelectedHubOffice, $this->customHubOffices)) {
            $this->customHubOffices[] = $this->customSelectedHubOffice;
        }
        $this->customSelectedHubOffice = '';
        $this->customHubSearch = '';
    }

    public function removeCustomHubOffice(int $index): void
    {
        if (isset($this->customHubOffices[$index])) {
            array_splice($this->customHubOffices, $index, 1);
        }
    }

    public function selectAllCustomHubOffices(): void
    {
        $allActive = \DB::table('sys_office')
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]'])
            ->orderByRaw("COALESCE(cluster, 'ZZZZ') ASC")
            ->orderBy('office_name', 'asc')
            ->pluck('office_code')
            ->toArray();

        $this->customHubOffices = $allActive;
    }

    public function clearCustomHubOffices(): void
    {
        $this->customHubOffices = [];
    }

    public function saveCustomFlow(): void
    {
        $this->clearMessages();

        if ($this->selectedCustom === '') {
            $this->errorMessage = 'Please select a custom flow option.';
            return;
        }

        if (count($this->customFlowOffices) === 0) {
            $this->errorMessage = 'A custom transaction flow must contain at least one office in its routing path.';
            return;
        }

        if ($this->selectedCustom === 'new') {
            $codeToUse = !empty($this->customFlowCode) ? strtoupper(trim($this->customFlowCode)) : ('FLOW-CUSTOM-' . time() . '-' . rand(100, 999));
            $this->validate([
                'customFlowName' => 'required|string|max:255',
                'customFlowUse' => 'required|string|in:internal,external,issuances,application,others,none',
                'customFlowFor' => 'required|string|in:system,office,user',
            ]);
        } else {
            $codeToUse = $this->customFlowCode;
            $this->validate([
                'customFlowName' => 'required|string|max:255',
                'customFlowUse' => 'required|string|in:internal,external,issuances,application,others,none',
                'customFlowFor' => 'required|string|in:system,office,user',
            ]);
        }

        try {
            \DB::transaction(function () use ($codeToUse) {
                $flowId = null;

                if ($this->selectedCustom === 'new') {
                    $maxId = \DB::table('dts_transaction_flow')->max('id') ?? 0;
                    $flowId = $maxId + 1;

                    \DB::table('dts_transaction_flow')->insert([
                        'id' => $flowId,
                        'flow_name' => trim($this->customFlowName),
                        'flow_code' => $codeToUse,
                        'is_active' => $this->customIsActive,
                        'flow_use' => $this->customFlowUse,
                        'flow_for' => $this->customFlowFor,
                        'added_by' => auth()->id() ?? 1,
                        'date_added' => now(),
                        'contin_rdp_id' => $this->customContinRdpId,
                    ]);
                } else {
                    $flowId = (int) $this->selectedCustom;
                    \DB::table('dts_transaction_flow')
                        ->where('id', $flowId)
                        ->update([
                            'flow_name' => trim($this->customFlowName),
                            'is_active' => $this->customIsActive,
                            'flow_use' => $this->customFlowUse,
                            'flow_for' => $this->customFlowFor,
                            'contin_rdp_id' => $this->customContinRdpId,
                        ]);
                }

                // Sync sequence list
                \DB::table('dts_sequence_list')->where('control_id', $flowId)->delete();
                foreach ($this->customFlowOffices as $rank => $officeCode) {
                    \DB::table('dts_sequence_list')->insert([
                        'control_id' => $flowId,
                        'sequence_ranking' => $rank + 1,
                        'office_code' => $officeCode,
                    ]);
                }

                // Sync Copy Furnished offices
                $existingCfTx = \DB::table('dts_copy_filled_transaction')->where('control_num', $codeToUse)->first();
                if ($existingCfTx) {
                    \DB::table('dts_copy_filled_to_office')->where('control_id', $existingCfTx->assign_offices_id)->delete();
                    \DB::table('dts_copy_filled_transaction')->where('control_num', $codeToUse)->delete();
                }

                if (count($this->customCfOffices) > 0) {
                    $assignOfficesId = (\DB::table('dts_copy_filled_transaction')->max('assign_offices_id') ?? 1000) + 1;
                    \DB::table('dts_copy_filled_transaction')->insert([
                        'control_num' => $codeToUse,
                        'total_office' => count($this->customCfOffices),
                        'assign_offices_id' => $assignOfficesId,
                        'data_created' => now(),
                        'date_modified' => now(),
                    ]);

                    foreach ($this->customCfOffices as $cfOffice) {
                        \DB::table('dts_copy_filled_to_office')->insert([
                            'control_id' => $assignOfficesId,
                            'office_code' => $cfOffice,
                        ]);
                    }
                }

                // Sync Custom HUB Receiving Offices
                \DB::table('dts_hub_flow_datas')->where('flow_owner', $flowId)->delete();
                if (in_array('[HUB]', $this->customFlowOffices) && count($this->customHubOffices) > 0) {
                    foreach ($this->customHubOffices as $hubOffice) {
                        \DB::table('dts_hub_flow_datas')->insert([
                            'flow_owner' => $flowId,
                            'offices_hub' => $hubOffice,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $this->selectedCustom = (string) $flowId;
            });

            $this->successMessage = 'Custom transaction flow successfully saved!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save custom flow: ' . $e->getMessage();
        }
    }

    public function deleteCustomFlow(): void
    {
        $this->clearMessages();
        if (empty($this->selectedCustom) || $this->selectedCustom === 'new') return;

        try {
            $flowId = (int) $this->selectedCustom;
            \DB::table('dts_transaction_flow')
                ->where('id', $flowId)
                ->update(['is_active' => false]);

            $this->successMessage = 'Custom flow successfully deactivated.';
            $this->selectedCustom = '';
            $this->updatedSelectedCustom('');
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to deactivate custom flow: ' . $e->getMessage();
        }
    }

    public function toggleCustomFlowSelection(int $id): void
    {
        if (in_array($id, $this->selectedCustomFlowIds)) {
            $this->selectedCustomFlowIds = array_values(array_diff($this->selectedCustomFlowIds, [$id]));
        } else {
            $this->selectedCustomFlowIds[] = $id;
        }
    }

    public function toggleAllCustomFlows(): void
    {
        $customFlows = \DB::table('dts_transaction_flow')
            ->where('flow_code', 'like', 'FLOW-CUSTOM-%')
            ->whereNull('referenced_flow')
            ->where('flow_name', 'not like', 'Flow for %')
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();

        if (count($this->selectedCustomFlowIds) === count($customFlows)) {
            $this->selectedCustomFlowIds = [];
        } else {
            $this->selectedCustomFlowIds = $customFlows;
        }
    }

    public function bulkDeleteCustomFlows(): void
    {
        if (empty($this->selectedCustomFlowIds)) return;

        try {
            \DB::transaction(function () {
                $flows = \DB::table('dts_transaction_flow')
                    ->whereIn('id', $this->selectedCustomFlowIds)
                    ->get();

                $names = $flows->pluck('flow_name')->implode(', ');

                \DB::table('dts_transaction_flow')
                    ->whereIn('id', $this->selectedCustomFlowIds)
                    ->update(['is_active' => false]);

                \DB::table('sys_admin_logs')->insert([
                    'changes' => \Str::limit("Bulk soft-deleted " . $flows->count() . " custom transaction flow(s): {$names}", 245),
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);
            });

            $count = count($this->selectedCustomFlowIds);
            $this->successMessage = "Successfully deactivated {$count} custom flow(s).";
            $this->selectedCustomFlowIds = [];
            $this->selectedCustom = '';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to bulk deactivate custom flows: ' . $e->getMessage();
        }
    }

    /**
     * Triggered when selected predefined flow option changes.
     */
    public function updatedSelectedPredefined(string $value): void
    {
        $this->clearMessages();

        if ($value === '' || $value === 'new' || $value === 'import') {
            $this->flowName = '';
            $this->flowCode = '';
            $this->isActive = true;
            $this->flowUse = 'none';
            $this->flowOffices = ['ORIGIN', 'ORIGIN'];
            $this->flowFile = null;
            $this->officeSearch = '';
            $this->cfOffices = [];
            $this->cfSearch = '';
            $this->selectedCfOffice = '';
            $this->hubOffices = [];
            $this->hubSearch = '';
            $this->selectedHubOffice = '';
            $this->continRdpId = null;
            $this->continRdpSearch = '';
            $this->continRdpTypeFilter = '';
            return;
        }

        $flow = \DB::table('dts_transaction_flow')->where('id', $value)->first();
        if ($flow) {
            $this->flowName = $flow->flow_name;
            $this->flowCode = $flow->flow_code;
            $this->isActive = (bool) $flow->is_active;
            $this->flowUse = $flow->flow_use ?? 'none';

            // Load linked RDP record series (null = Server default)
            $this->continRdpId = (isset($flow->contin_rdp_id) && $flow->contin_rdp_id !== null)
                ? (int) $flow->contin_rdp_id
                : null;
            $this->continRdpSearch = $this->continRdpId !== null
                ? (string) ($this->continRdpTitle($this->continRdpId) ?? '')
                : '';
            $this->continRdpTypeFilter = '';

            // Load office sequences
            $offices = \DB::table('dts_sequence_list')
                ->where('control_id', $flow->id)
                ->orderBy('sequence_ranking')
                ->pluck('office_code')
                ->toArray();

            // Ensure first step is always ORIGIN
            if (empty($offices)) {
                $offices = ['ORIGIN'];
            } elseif ($offices[0] !== 'ORIGIN') {
                array_unshift($offices, 'ORIGIN');
            }
            $this->flowOffices = $offices;

            // Load copy-furnished offices
            $cfTx = \DB::table('dts_copy_filled_transaction')
                ->where('control_num', $flow->flow_code)
                ->first();
            if ($cfTx) {
                $this->cfOffices = \DB::table('dts_copy_filled_to_office')
                    ->where('control_id', $cfTx->assign_offices_id)
                    ->pluck('office_code')
                    ->toArray();
            } else {
                $this->cfOffices = [];
            }
            $this->cfSearch = '';
            $this->selectedCfOffice = '';

            // Load predefined HUB offices
            $this->hubOffices = \DB::table('dts_hub_flow_datas')
                ->where('flow_owner', $flow->id)
                ->pluck('offices_hub')
                ->toArray();
            $this->hubSearch = '';
            $this->selectedHubOffice = '';
        }
    }

    /**
     * Adds selected office to the current sequence path.
     */
    public function addOfficeToPath(): void
    {
        if ($this->selectedOffice === '') return;

        // Ensure ORIGIN is present at the beginning
        if (empty($this->flowOffices)) {
            $this->flowOffices[] = 'ORIGIN';
        } elseif ($this->flowOffices[0] !== 'ORIGIN') {
            array_unshift($this->flowOffices, 'ORIGIN');
        }

        // If the last step is 'ORIGIN', insert the new office BEFORE the last step!
        $count = count($this->flowOffices);
        if ($count > 1 && $this->flowOffices[$count - 1] === 'ORIGIN') {
            array_splice($this->flowOffices, $count - 1, 0, $this->selectedOffice);
        } else {
            $this->flowOffices[] = $this->selectedOffice;
        }

        $this->selectedOffice = '';
        $this->officeSearch = '';
    }

    public function moveOfficeUp(int $index): void
    {
        if ($index <= 1 || !isset($this->flowOffices[$index])) return;

        $temp = $this->flowOffices[$index - 1];
        $this->flowOffices[$index - 1] = $this->flowOffices[$index];
        $this->flowOffices[$index] = $temp;
    }

    public function moveOfficeDown(int $index): void
    {
        if ($index === 0 || $index >= count($this->flowOffices) - 1 || !isset($this->flowOffices[$index])) return;

        $temp = $this->flowOffices[$index + 1];
        $this->flowOffices[$index + 1] = $this->flowOffices[$index];
        $this->flowOffices[$index] = $temp;
    }

    public function removeOffice(int $index): void
    {
        if ($index === 0 || !isset($this->flowOffices[$index])) return;

        array_splice($this->flowOffices, $index, 1);
    }

    /**
     * Drag-and-drop reorder for intermediate stops in predefined flow pipeline.
     */
    public function reorderStep(int $fromIndex, int $toIndex): void
    {
        $count = count($this->flowOffices);
        if ($fromIndex === $toIndex || $count <= 2) return;
        if (!isset($this->flowOffices[$fromIndex]) || !isset($this->flowOffices[$toIndex])) return;

        // Origin at 0 is immovable
        if ($fromIndex === 0 || $toIndex === 0) return;
        // Last Origin is immovable
        if ($this->flowOffices[$count - 1] === 'ORIGIN' && ($fromIndex === $count - 1 || $toIndex === $count - 1)) return;

        $moved = array_splice($this->flowOffices, $fromIndex, 1)[0];
        array_splice($this->flowOffices, $toIndex, 0, [$moved]);
        $this->flowOffices = array_values($this->flowOffices);
    }

    /**
     * Drag-and-drop reorder for intermediate stops in custom flow pipeline.
     */
    public function reorderCustomStep(int $fromIndex, int $toIndex): void
    {
        $count = count($this->customFlowOffices);
        if ($fromIndex === $toIndex || $count <= 2) return;
        if (!isset($this->customFlowOffices[$fromIndex]) || !isset($this->customFlowOffices[$toIndex])) return;

        if ($fromIndex === 0 || $toIndex === 0) return;
        if ($this->customFlowOffices[$count - 1] === 'ORIGIN' && ($fromIndex === $count - 1 || $toIndex === $count - 1)) return;

        $moved = array_splice($this->customFlowOffices, $fromIndex, 1)[0];
        array_splice($this->customFlowOffices, $toIndex, 0, [$moved]);
        $this->customFlowOffices = array_values($this->customFlowOffices);
    }

    /**
     * Drag-and-drop flow reassignment to a new Purpose / Scope category.
     */
    public function changeFlowPurpose(int $flowId, string $newPurpose): void
    {
        $validPurposes = ['internal', 'external', 'issuances', 'application', 'others', 'none'];
        if (!in_array($newPurpose, $validPurposes)) return;

        $flow = \DB::table('dts_transaction_flow')->where('id', $flowId)->first();
        if (!$flow) return;

        \DB::table('dts_transaction_flow')->where('id', $flowId)->update([
            'flow_use' => $newPurpose,
        ]);

        \DB::table('sys_admin_logs')->insert([
            'changes' => "Reassigned flow '{$flow->flow_name}' ({$flow->flow_code}) scope to: " . ucfirst($newPurpose),
            'admin_id' => auth()->id() ?? 1,
            'what_system' => 3,
            'when_changes' => now(),
        ]);

        if ((int)$this->selectedPredefined === $flowId) {
            $this->flowUse = $newPurpose;
        }

        $this->successMessage = "Workflow '{$flow->flow_name}' moved to " . ucfirst($newPurpose) . " scope!";
    }

    /**
     * Drag-and-drop custom flow reassignment to a new Purpose / Scope category.
     */
    public function changeCustomFlowPurpose(int $flowId, string $newPurpose): void
    {
        $validPurposes = ['internal', 'external', 'issuances', 'application', 'others', 'none'];
        if (!in_array($newPurpose, $validPurposes)) return;

        $flow = \DB::table('dts_transaction_flow')->where('id', $flowId)->first();
        if (!$flow) return;

        \DB::table('dts_transaction_flow')->where('id', $flowId)->update([
            'flow_use' => $newPurpose,
        ]);

        if ((int)$this->selectedCustom === $flowId) {
            $this->customFlowUse = $newPurpose;
        }

        $this->successMessage = "Custom flow '{$flow->flow_name}' moved to " . ucfirst($newPurpose) . " scope!";
    }

    /**
     * Save predefined transaction flow configuration.
     */
    public function savePredefinedFlow(): void
    {
        $this->clearMessages();

        if ($this->selectedPredefined === '') {
            $this->errorMessage = 'Please select a flow option.';
            return;
        }

        if (count($this->flowOffices) === 0) {
            $this->errorMessage = 'A transaction flow must contain at least one office in its routing path.';
            return;
        }

        // 1. Validate inputs
        if ($this->selectedPredefined === 'new') {
            $deactivatedByCode = \DB::table('dts_transaction_flow')
                ->where('flow_code', strtoupper(trim($this->flowCode)))
                ->where('is_active', false)
                ->first();

            $codeExcludeId = $deactivatedByCode ? $deactivatedByCode->id : null;
            $nameRule = 'required|string|max:255';
            $codeRule = $codeExcludeId
                ? 'required|string|max:255|unique:dts_transaction_flow,flow_code,' . $codeExcludeId . ',id|regex:/^[A-Z0-9_\-]+$/i'
                : 'required|string|max:255|unique:dts_transaction_flow,flow_code|regex:/^[A-Z0-9_\-]+$/i';

            $this->validate([
                'flowName' => $nameRule,
                'flowCode' => $codeRule,
                'flowUse' => 'required|string|in:internal,external,issuances,application,others,none',
            ], [
                'flowCode.regex' => 'Flow code can only contain letters, numbers, dashes, and underscores.',
            ]);
        } else {
            $this->validate([
                'flowName' => 'required|string|max:255',
                'flowUse' => 'required|string|in:internal,external,issuances,application,others,none',
            ]);
        }

        // 2. Perform DB operations
        try {
            \DB::transaction(function () {
                $flowId = null;

                if ($this->selectedPredefined === 'new') {
                    $existingFlow = \DB::table('dts_transaction_flow')
                        ->where('flow_code', strtoupper(trim($this->flowCode)))
                        ->where('is_active', false)
                        ->first();

                    if ($existingFlow) {
                        $flowId = $existingFlow->id;

                        \DB::table('dts_transaction_flow')->where('id', $flowId)->update([
                            'flow_name' => trim($this->flowName),
                            'is_active' => true,
                            'flow_use' => $this->flowUse,
                            'added_by' => auth()->id() ?? 1,
                            'date_added' => now(),
                            'contin_rdp_id' => $this->continRdpId,
                        ]);

                        \DB::table('sys_admin_logs')->insert([
                            'changes' => "Reactivated previously soft-deleted predefined transaction flow: " . trim($this->flowName) . " (" . strtoupper(trim($this->flowCode)) . ")",
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now(),
                        ]);

                        $this->successMessage = 'Previously deactivated flow reactivated and updated successfully!';
                    } else {
                        $maxId = \DB::table('dts_transaction_flow')->max('id') ?? 0;
                        $flowId = $maxId + 1;

                        \DB::table('dts_transaction_flow')->insert([
                            'id' => $flowId,
                            'flow_name' => trim($this->flowName),
                            'flow_code' => strtoupper(trim($this->flowCode)),
                            'is_active' => $this->isActive,
                            'flow_use' => $this->flowUse,
                            'flow_for' => 'system',
                            'added_by' => auth()->id() ?? 1,
                            'date_added' => now(),
                            'contin_rdp_id' => $this->continRdpId,
                        ]);

                        \DB::table('sys_admin_logs')->insert([
                            'changes' => "Created predefined transaction flow: " . trim($this->flowName) . " (" . strtoupper(trim($this->flowCode)) . ")",
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now(),
                        ]);

                        $this->successMessage = 'Predefined flow created successfully!';
                    }
                } else {
                    $flowId = (int) $this->selectedPredefined;
                    $flow = \DB::table('dts_transaction_flow')->where('id', $flowId)->first();
                    if (!$flow) throw new \Exception('Predefined flow not found.');

                    \DB::table('dts_transaction_flow')->where('id', $flowId)->update([
                        'flow_name' => trim($this->flowName),
                        'is_active' => $this->isActive,
                        'flow_use' => $this->flowUse,
                        'contin_rdp_id' => $this->continRdpId,
                    ]);

                    \DB::table('sys_admin_logs')->insert([
                        'changes' => "Updated predefined transaction flow: {$flow->flow_name}",
                        'admin_id' => auth()->id(),
                        'what_system' => 3,
                        'when_changes' => now(),
                    ]);

                    $this->successMessage = 'Predefined flow configuration updated!';
                }

                // Update Sequence List
                \DB::table('dts_sequence_list')->where('control_id', $flowId)->delete();
                foreach ($this->flowOffices as $rank => $officeCode) {
                    \DB::table('dts_sequence_list')->insert([
                        'control_id' => $flowId,
                        'sequence_ranking' => $rank + 1,
                        'office_code' => $officeCode,
                    ]);
                }

                // Update Copy Furnished list
                $flowCodeUpper = strtoupper(trim($this->flowCode));
                $existingCFs = \DB::table('dts_copy_filled_transaction')->where('control_num', $flowCodeUpper)->get();
                foreach ($existingCFs as $existingCF) {
                    \DB::table('dts_copy_filled_to_office')->where('control_id', $existingCF->assign_offices_id)->delete();
                }
                \DB::table('dts_copy_filled_transaction')->where('control_num', $flowCodeUpper)->delete();

                if (count($this->cfOffices) > 0) {
                    $assignOfficesId = (\DB::table('dts_copy_filled_transaction')->max('assign_offices_id') ?? 1000) + 1;
                    \DB::table('dts_copy_filled_transaction')->insert([
                        'control_num' => $flowCodeUpper,
                        'total_office' => count($this->cfOffices),
                        'assign_offices_id' => $assignOfficesId,
                        'data_created' => now(),
                        'date_modified' => now(),
                    ]);
                    foreach ($this->cfOffices as $cfOffice) {
                        \DB::table('dts_copy_filled_to_office')->insert([
                            'control_id' => $assignOfficesId,
                            'office_code' => $cfOffice,
                        ]);
                    }
                }

                // Update Predefined HUB list
                \DB::table('dts_hub_flow_datas')->where('flow_owner', $flowId)->delete();
                if (in_array('[HUB]', $this->flowOffices) && count($this->hubOffices) > 0) {
                    foreach ($this->hubOffices as $hubOffice) {
                        \DB::table('dts_hub_flow_datas')->insert([
                            'flow_owner' => $flowId,
                            'offices_hub' => $hubOffice,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $this->selectedPredefined = (string) $flowId;
            });

            $msg = $this->successMessage;
            $this->selectFlow($this->selectedPredefined);
            $this->successMessage = $msg;
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save flow: ' . $e->getMessage();
        }
    }

    public function deleteFlow(): void
    {
        $this->clearMessages();

        if ($this->selectedPredefined === '' || $this->selectedPredefined === 'new' || $this->selectedPredefined === 'import') {
            $this->errorMessage = 'Please select an existing flow to delete.';
            return;
        }

        $flowId = (int) $this->selectedPredefined;

        try {
            \DB::transaction(function () use ($flowId) {
                $flow = \DB::table('dts_transaction_flow')->where('id', $flowId)->first();
                if (!$flow) throw new \Exception('Predefined flow not found.');

                \DB::table('dts_transaction_flow')->where('id', $flowId)->update([
                    'is_active' => false,
                ]);

                \DB::table('sys_admin_logs')->insert([
                    'changes' => "Soft-deleted predefined transaction flow (Deactivated): {$flow->flow_name} ({$flow->flow_code})",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);
            });

            $this->resetForm();
            $this->successMessage = 'Transaction flow soft-deleted (deactivated) successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete flow: ' . $e->getMessage();
        }
    }

    public function toggleFlowActive(): void
    {
        $this->clearMessages();
        if ($this->selectedPredefined === '' || $this->selectedPredefined === 'new' || $this->selectedPredefined === 'import') {
            return;
        }

        $flowId = (int) $this->selectedPredefined;
        try {
            $flow = \DB::table('dts_transaction_flow')->where('id', $flowId)->first();
            if (!$flow) throw new \Exception('Predefined flow not found.');

            $newStatus = !$flow->is_active;
            \DB::table('dts_transaction_flow')->where('id', $flowId)->update([
                'is_active' => $newStatus,
            ]);

            \DB::table('sys_admin_logs')->insert([
                'changes' => ($newStatus ? "Reactivated" : "Deactivated") . " predefined transaction flow: {$flow->flow_name} ({$flow->flow_code})",
                'admin_id' => auth()->id() ?? 1,
                'what_system' => 3,
                'when_changes' => now(),
            ]);

            $this->isActive = $newStatus;
            $this->successMessage = "Transaction flow " . ($newStatus ? "reactivated" : "deactivated") . " successfully!";
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to update flow status: ' . $e->getMessage();
        }
    }

    public function toggleFlowSelection(int $id): void
    {
        if (in_array($id, $this->selectedFlowIds)) {
            $this->selectedFlowIds = array_values(array_diff($this->selectedFlowIds, [$id]));
        } else {
            $this->selectedFlowIds[] = $id;
        }
    }

    public function toggleAllFlows(): void
    {
        $query = \DB::table('dts_transaction_flow')
            ->where('flow_code', 'not like', 'FLOW-CUSTOM-%')
            ->where('is_active', true);

        if ($this->predefinedPurposeFilter !== 'all') {
            $query->where('flow_use', $this->predefinedPurposeFilter);
        }

        if ($this->searchPredefined !== '') {
            $searchVal = '%' . $this->searchPredefined . '%';
            $query->where(function ($q) use ($searchVal) {
                $q->where('flow_name', 'ilike', $searchVal)
                  ->orWhere('flow_code', 'ilike', $searchVal);
            });
        }

        $visibleIds = $query->pluck('id')->toArray();

        if (count($this->selectedFlowIds) === count($visibleIds) && !array_diff($visibleIds, $this->selectedFlowIds)) {
            $this->selectedFlowIds = [];
        } else {
            $this->selectedFlowIds = $visibleIds;
        }
    }

    public function bulkDeleteFlows(): void
    {
        $this->clearMessages();

        if (empty($this->selectedFlowIds)) {
            $this->errorMessage = 'No flows selected for deletion.';
            return;
        }

        try {
            \DB::transaction(function () {
                $flows = \DB::table('dts_transaction_flow')
                    ->whereIn('id', $this->selectedFlowIds)
                    ->get();

                if ($flows->isEmpty()) throw new \Exception('No valid flows found to delete.');

                $names = $flows->pluck('flow_name')->implode(', ');

                \DB::table('dts_transaction_flow')
                    ->whereIn('id', $this->selectedFlowIds)
                    ->update(['is_active' => false]);

                \DB::table('sys_admin_logs')->insert([
                    'changes' => \Str::limit("Bulk soft-deleted " . $flows->count() . " predefined transaction flow(s): {$names}", 245),
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);
            });

            $count = count($this->selectedFlowIds);
            $this->selectedFlowIds = [];
            $this->resetForm();
            $this->successMessage = "{$count} flow(s) soft-deleted (deactivated) successfully!";
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to bulk delete flows: ' . $e->getMessage();
        }
    }

    /**
     * Parse the uploaded .txt file and populate flow creations.
     */
    public function importFlow(): void
    {
        $this->clearMessages();

        $this->validate([
            'flowFile' => 'required|file|extensions:txt,text|max:2048',
        ], [
            'flowFile.required' => 'Please select a text file to upload.',
            'flowFile.extensions' => 'The file must be a plain text file (.txt).',
            'flowFile.max' => 'The file size must be less than 2MB.',
        ]);

        try {
            $content = $this->flowFile->get();
            if ($content === false || $content === null) {
                $content = @file_get_contents($this->flowFile->getRealPath());
            }

            if ($content === false || $content === null) {
                throw new \Exception('Could not read the uploaded text file content.');
            }

            $rawLines = preg_split('/\r\n|\r|\n/', $content);
            $lines = [];
            foreach ($rawLines as $idx => $rawLine) {
                $trimmed = trim($rawLine);
                if ($trimmed !== '' && !str_starts_with($trimmed, '#')) {
                    $lines[] = [
                        'num' => $idx + 1,
                        'text' => $trimmed
                    ];
                }
            }

            if (count($lines) === 0) {
                throw new \Exception('The file is empty.');
            }

            $extractedFlows = [];
            $tempNames = [];
            $tempCodes = [];

            $i = 0;
            while ($i < count($lines)) {
                $nameLine = $lines[$i];

                if (!str_starts_with($nameLine['text'], '=')) {
                    throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\") must start with '=' to indicate the start of a flow name.");
                }

                $blockLines = [];
                $j = $i + 1;
                while ($j < count($lines) && !str_starts_with($lines[$j]['text'], '=')) {
                    $blockLines[] = $lines[$j];
                    $j++;
                }

                if (count($blockLines) < 2) {
                    throw new \Exception("Incomplete flow definition starting at Line {$lines[$i]['num']}. Each flow must contain at least a name, code, and sequence.");
                }

                $codeLine = $blockLines[0];
                $seqLine = $blockLines[1];

                if (!str_ends_with($nameLine['text'], ';')) {
                    throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\") must end with a semicolon ';'.");
                }
                if (!str_ends_with($codeLine['text'], ';')) {
                    throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\") must end with a semicolon ';'.");
                }
                if (!str_ends_with($seqLine['text'], ';')) {
                    throw new \Exception("Line {$seqLine['num']} (\"{$seqLine['text']}\") must end with a semicolon ';'.");
                }

                $flowName = trim(substr($nameLine['text'], 1, -1));
                $flowCode = strtoupper(trim(substr($codeLine['text'], 0, -1)));
                $seqText = trim(substr($seqLine['text'], 0, -1));

                if (empty($flowName)) {
                    throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\"): Flow name cannot be empty.");
                }
                if (empty($flowCode)) {
                    throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\"): Flow code cannot be empty.");
                }
                if (!preg_match('/^[A-Z0-9_\-]+$/i', $flowCode)) {
                    throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\"): Flow code can only contain letters, numbers, dashes, and underscores.");
                }

                $flowByCode = \DB::table('dts_transaction_flow')->where('flow_code', $flowCode)->first();
                $flowByName = \DB::table('dts_transaction_flow')->where('flow_name', $flowName)->first();

                if ($flowByCode || $flowByName) {
                    if ($flowByCode && $flowByName && $flowByCode->id === $flowByName->id) {
                        $i += 1 + count($blockLines);
                        continue;
                    }
                    if ($flowByCode && (!$flowByName || $flowByCode->flow_name !== $flowName)) {
                        throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\"): Conflict detected. The flow code '{$flowCode}' already exists in the database with a different name ('{$flowByCode->flow_name}').");
                    }
                    if ($flowByName && (!$flowByCode || $flowByName->flow_code !== $flowCode)) {
                        throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\"): Conflict detected. The flow name '{$flowName}' already exists in the database with a different code ('{$flowByName->flow_code}').");
                    }
                }

                if (isset($tempCodes[$flowCode])) {
                    if ($tempCodes[$flowCode] !== $flowName) {
                        throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\"): Duplicate flow code '{$flowCode}' found within the uploaded file with a different name.");
                    }
                    $i += 1 + count($blockLines);
                    continue;
                }
                if (isset($tempNames[$flowName])) {
                    if ($tempNames[$flowName] !== $flowCode) {
                        throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\"): Duplicate flow name '{$flowName}' found within the uploaded file with a different code.");
                    }
                    $i += 1 + count($blockLines);
                    continue;
                }
                $tempCodes[$flowCode] = $flowName;
                $tempNames[$flowName] = $flowCode;

                $nodes = explode('->', $seqText);
                $nodes = array_filter(array_map('trim', $nodes));

                if (count($nodes) === 0) {
                    throw new \Exception("Line {$seqLine['num']} (\"{$seqLine['text']}\"): The flow sequence cannot be empty.");
                }

                $officesSequence = [];
                foreach ($nodes as $node) {
                    if ($node === '[]') {
                        $officesSequence[] = 'ORIGIN';
                        continue;
                    }
                    if ($node === '[H]') {
                        $officesSequence[] = '[H]';
                        continue;
                    }

                    $office = \DB::table('sys_office')
                        ->where('is_active', true)
                        ->where(function($q) use ($node) {
                            $q->where('office_code', $node)
                              ->orWhere('office_name', $node);
                        })
                        ->first();

                    if (!$office) {
                        throw new \Exception("Line {$seqLine['num']} (\"{$seqLine['text']}\"): office '{$node}' does not exist in the database or is typoed.");
                    }

                    $officesSequence[] = $office->office_code;
                }

                if (empty($officesSequence)) {
                    $officesSequence = ['ORIGIN', 'ORIGIN'];
                } else {
                    if ($officesSequence[0] !== 'ORIGIN') {
                        array_unshift($officesSequence, 'ORIGIN');
                    }
                    if ($officesSequence[count($officesSequence) - 1] !== 'ORIGIN') {
                        $officesSequence[] = 'ORIGIN';
                    }
                }

                $cfOffices = [];
                $flowUse = 'none';

                for ($k = 2; $k < count($blockLines); $k++) {
                    $optLine = $blockLines[$k];

                    if (!str_ends_with($optLine['text'], ';')) {
                        throw new \Exception("Line {$optLine['num']} (\"{$optLine['text']}\") must end with a semicolon ';'.");
                    }

                    if (preg_match('/^\s*\[\s*([a-zA-Z0-9_\-]+)\s*\]\s*;\s*$/i', $optLine['text'], $matches)) {
                        $rawUse = strtolower(trim($matches[1]));
                        if ($rawUse === 'internal' || $rawUse === 'int') {
                            $flowUse = 'internal';
                        } elseif ($rawUse === 'external' || $rawUse === 'ext') {
                            $flowUse = 'external';
                        } elseif ($rawUse === 'issuances' || $rawUse === 'iss') {
                            $flowUse = 'issuances';
                        } elseif ($rawUse === 'application' || $rawUse === 'app') {
                            $flowUse = 'application';
                        } elseif ($rawUse === 'others' || $rawUse === 'oth') {
                            $flowUse = 'others';
                        } elseif ($rawUse === 'none') {
                            $flowUse = 'none';
                        } else {
                            throw new \Exception("Line {$optLine['num']} (\"{$optLine['text']}\"): Invalid flow use '{$matches[1]}'. Must be one of: internal (int), external (ext), issuances (iss), application (app), others (oth), none.");
                        }
                    } else {
                        $cfText = trim(substr($optLine['text'], 0, -1));
                        if (!empty($cfText)) {
                            $cfNodes = explode(',', $cfText);
                            $cfNodes = array_filter(array_map('trim', $cfNodes));
                            
                            foreach ($cfNodes as $node) {
                                $office = \DB::table('sys_office')
                                    ->where('is_active', true)
                                    ->where(function($q) use ($node) {
                                        $q->where('office_code', $node)
                                          ->orWhere('office_name', $node);
                                    })
                                    ->first();

                                if (!$office) {
                                    throw new \Exception("Line {$optLine['num']} (\"{$optLine['text']}\"): Copy furnished office '{$node}' does not exist in the database or is typoed.");
                                }

                                $cfOffices[] = $office->office_code;
                            }
                        }
                    }
                }

                $extractedFlows[] = [
                    'name' => $flowName,
                    'code' => $flowCode,
                    'sequence' => $officesSequence,
                    'cf' => $cfOffices,
                    'flow_use' => $flowUse,
                ];

                $i += 1 + count($blockLines);
            }

            if (count($extractedFlows) === 0) {
                $this->successMessage = 'All flows in the file already exist in the database with identical names and codes. No new records were imported.';
                $this->resetForm();
                return;
            }

            \DB::transaction(function () use ($extractedFlows) {
                $importedCount = 0;
                foreach ($extractedFlows as $flowData) {
                    $existingByCode = \DB::table('dts_transaction_flow')
                        ->where('flow_code', $flowData['code'])
                        ->where('is_active', false)
                        ->first();

                    if ($existingByCode) {
                        $flowId = $existingByCode->id;
                        \DB::table('dts_transaction_flow')->where('id', $flowId)->update([
                            'flow_name' => $flowData['name'],
                            'is_active' => true,
                            'flow_use' => $flowData['flow_use'],
                            'added_by' => auth()->id() ?? 1,
                            'date_added' => now(),
                        ]);
                    } else {
                        $maxId = \DB::table('dts_transaction_flow')->max('id') ?? 0;
                        $flowId = $maxId + 1;
                        \DB::table('dts_transaction_flow')->insert([
                            'id' => $flowId,
                            'flow_name' => $flowData['name'],
                            'flow_code' => $flowData['code'],
                            'is_active' => true,
                            'flow_use' => $flowData['flow_use'],
                            'flow_for' => 'system',
                            'added_by' => auth()->id() ?? 1,
                            'date_added' => now(),
                        ]);
                    }

                    \DB::table('dts_sequence_list')->where('control_id', $flowId)->delete();
                    foreach ($flowData['sequence'] as $rank => $officeCode) {
                        \DB::table('dts_sequence_list')->insert([
                            'control_id' => $flowId,
                            'sequence_ranking' => $rank + 1,
                            'office_code' => $officeCode,
                        ]);
                    }

                    $existingCFs = \DB::table('dts_copy_filled_transaction')->where('control_num', $flowData['code'])->get();
                    foreach ($existingCFs as $existingCF) {
                        \DB::table('dts_copy_filled_to_office')->where('control_id', $existingCF->assign_offices_id)->delete();
                    }
                    \DB::table('dts_copy_filled_transaction')->where('control_num', $flowData['code'])->delete();

                    if (count($flowData['cf']) > 0) {
                        $assignOfficesId = (\DB::table('dts_copy_filled_transaction')->max('assign_offices_id') ?? 1000) + 1;
                        \DB::table('dts_copy_filled_transaction')->insert([
                            'control_num' => $flowData['code'],
                            'total_office' => count($flowData['cf']),
                            'assign_offices_id' => $assignOfficesId,
                            'data_created' => now(),
                            'date_modified' => now(),
                        ]);
                        foreach ($flowData['cf'] as $cfOffice) {
                            \DB::table('dts_copy_filled_to_office')->insert([
                                'control_id' => $assignOfficesId,
                                'office_code' => $cfOffice,
                            ]);
                        }
                    }

                    $importedCount++;
                }

                \DB::table('sys_admin_logs')->insert([
                    'changes' => "Batch imported {$importedCount} predefined transaction flow(s) from .txt file",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);
            });

            $count = count($extractedFlows);
            $this->resetForm();
            $this->successMessage = "Successfully imported {$count} predefined flow(s) from the file!";
        } catch (\Exception $e) {
            $this->errorMessage = 'Extraction failed: ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        $activeOffices = \DB::table('sys_office')
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]'])
            ->orderBy('office_name', 'asc')
            ->get();

        // 1. Predefined Flows Query
        $predefinedQuery = \DB::table('dts_transaction_flow')
            ->leftJoin('rdp_record_series', 'dts_transaction_flow.contin_rdp_id', '=', 'rdp_record_series.id')
            ->where('dts_transaction_flow.flow_code', 'not like', 'FLOW-CUSTOM-%')
            ->select(
                'dts_transaction_flow.*',
                'rdp_record_series.series_title as rdp_series_title',
                'rdp_record_series.item_number as rdp_item_number'
            );

        if ($this->statusFilter === 'active') {
            $predefinedQuery->where('dts_transaction_flow.is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $predefinedQuery->where('dts_transaction_flow.is_active', false);
        }

        if ($this->predefinedPurposeFilter !== 'all') {
            $predefinedQuery->where('dts_transaction_flow.flow_use', $this->predefinedPurposeFilter);
        }

        if ($this->searchPredefined !== '') {
            $searchVal = '%' . $this->searchPredefined . '%';
            $predefinedQuery->where(function ($q) use ($searchVal) {
                $q->where('dts_transaction_flow.flow_name', 'ilike', $searchVal)
                  ->orWhere('dts_transaction_flow.flow_code', 'ilike', $searchVal);
            });
        }

        $predefinedFlows = $predefinedQuery->orderBy('dts_transaction_flow.flow_name', 'asc')->get();

        // Attach step sequence counts to flows
        $flowIds = $predefinedFlows->pluck('id')->toArray();
        $sequenceCounts = \DB::table('dts_sequence_list')
            ->whereIn('control_id', $flowIds)
            ->groupBy('control_id')
            ->selectRaw('control_id, count(*) as count')
            ->pluck('count', 'control_id')
            ->toArray();

        foreach ($predefinedFlows as $pf) {
            $pf->step_count = $sequenceCounts[$pf->id] ?? 0;
        }

        // 2. Custom Flows Query
        $customFlows = collect();
        if ($this->activeTab === 'custom') {
            $customQuery = \DB::table('dts_transaction_flow')
                ->leftJoin('sys_account', 'dts_transaction_flow.added_by', '=', 'sys_account.id')
                ->leftJoin('sys_account_details', 'sys_account.id', '=', 'sys_account_details.account_id')
                ->leftJoin('sys_office as office', 'sys_account_details.office_id', '=', 'office.id')
                ->leftJoin('rdp_record_series', 'dts_transaction_flow.contin_rdp_id', '=', 'rdp_record_series.id')
                ->where('dts_transaction_flow.flow_code', 'like', 'FLOW-CUSTOM-%')
                ->select(
                    'dts_transaction_flow.*',
                    'sys_account.username',
                    'sys_account_details.first_name',
                    'sys_account_details.last_name',
                    'office.office_name',
                    'office.office_code',
                    'rdp_record_series.series_title as rdp_series_title',
                    'rdp_record_series.item_number as rdp_item_number'
                );

            if ($this->statusFilter === 'active') {
                $customQuery->where('dts_transaction_flow.is_active', true);
            } elseif ($this->statusFilter === 'inactive') {
                $customQuery->where('dts_transaction_flow.is_active', false);
            }

            if ($this->customPurposeFilter !== 'all') {
                $customQuery->where('dts_transaction_flow.flow_use', $this->customPurposeFilter);
            }

            if ($this->searchCustom !== '') {
                $searchVal = '%' . $this->searchCustom . '%';
                $customQuery->where(function ($q) use ($searchVal) {
                    $q->where('dts_transaction_flow.flow_name', 'ilike', $searchVal)
                      ->orWhere('dts_transaction_flow.flow_code', 'ilike', $searchVal)
                      ->orWhere('office.office_name', 'ilike', $searchVal)
                      ->orWhere('office.office_code', 'ilike', $searchVal);
                });
            }

            $customFlows = $customQuery->orderBy('dts_transaction_flow.flow_name', 'asc')->get();

            $cFlowIds = $customFlows->pluck('id')->toArray();
            $cSequenceCounts = \DB::table('dts_sequence_list')
                ->whereIn('control_id', $cFlowIds)
                ->groupBy('control_id')
                ->selectRaw('control_id, count(*) as count')
                ->pluck('count', 'control_id')
                ->toArray();

            foreach ($customFlows as $cf) {
                $cf->step_count = $cSequenceCounts[$cf->id] ?? 0;
            }
        }

        // Office lookup dictionary for sequence rendering
        $officeLookup = \DB::table('sys_office')->pluck('office_name', 'office_code')->toArray();

        return [
            'activeOffices'   => $activeOffices,
            'predefinedFlows' => $predefinedFlows,
            'customFlows'     => $customFlows,
            'officeLookup'    => $officeLookup,
        ];
    }
};
?>

@push('styles')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .miller-container {
            max-width: 1650px;
            margin: 0 auto;
            padding: 16px 20px;
            height: calc(100vh - 100px);
            display: flex;
            flex-direction: column;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #1e293b;
        }

        /* Scope Selector Tabs */
        .m-scope-nav {
            display: inline-flex;
            background: #f1f5f9;
            padding: 3px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            gap: 2px;
        }
        .m-scope-tab {
            border: none;
            background: transparent;
            padding: 6px 14px;
            font-size: 12.5px;
            font-weight: 600;
            color: #64748b;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.15s ease;
        }
        .m-scope-tab:hover {
            color: #1e293b;
        }
        .m-scope-tab.active {
            background: #ffffff;
            color: #0284c7;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }

        /* 4-Column Miller Browser */
        .miller-browser {
            flex: 1;
            display: grid;
            grid-template-columns: 270px 320px 390px 1fr;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            min-height: 0;
        }

        .miller-column {
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            min-height: 0;
            overflow: hidden;
        }
        .miller-column:last-child {
            border-right: none;
        }

        .m-col-header {
            padding: 10px 14px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #475569;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .m-col-search {
            padding: 8px 12px;
            border-bottom: 1px solid #f1f5f9;
            background: #ffffff;
            flex-shrink: 0;
            position: relative;
        }
        .m-col-search input {
            width: 100%;
            padding: 6px 10px 6px 28px;
            font-size: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #f8fafc;
            outline: none;
            transition: border-color 0.15s, background-color 0.15s;
            box-sizing: border-box;
        }
        .m-col-search input:focus {
            border-color: #0284c7;
            background: #ffffff;
        }
        .m-col-search i.search-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 11px;
            color: #94a3b8;
        }

        .m-col-list {
            flex: 1;
            overflow-y: auto;
            padding: 6px;
            min-height: 0;
        }

        .m-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            color: #334155;
            transition: all 0.1s ease;
            margin-bottom: 2px;
            border: 1px solid transparent;
            user-select: none;
        }
        .m-item:hover {
            background: #f1f5f9;
        }
        .m-item.active {
            background: #e0f2fe;
            color: #0369a1;
            font-weight: 600;
            border-color: #bae6fd;
        }
        .m-item-left {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            flex: 1;
        }
        .m-item-right {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .m-item-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 999px;
            background: #e2e8f0;
            color: #475569;
            font-weight: 600;
        }
        .m-item.active .m-item-badge {
            background: #bae6fd;
            color: #0284c7;
        }
        .m-item-title {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }
        .m-item-sub {
            font-size: 10px;
            color: #64748b;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .m-item.active .m-item-sub {
            color: #0284c7;
        }
        .m-code-chip {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 9.5px;
            background: #f1f5f9;
            padding: 1px 4px;
            border-radius: 3px;
            color: #334155;
            border: 1px solid #e2e8f0;
        }
        .m-rdp-chip {
            font-size: 8.5px;
            background: #e0e7ff;
            color: #4338ca;
            padding: 1px 4px;
            border-radius: 3px;
            font-weight: 700;
            border: 1px solid #c7d2fe;
        }
        .m-batch-import-btn {
            width: 100%;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            padding: 8px 10px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
            color: #0284c7;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .m-batch-import-btn:hover {
            background: #e0f2fe;
            border-color: #7dd3fc;
        }
        .m-item-arrow {
            font-size: 9px;
            color: #94a3b8;
        }
        .m-item.active .m-item-arrow {
            color: #0284c7;
        }

        /* Drag and Drop Styling */
        .m-item.draggable-flow {
            cursor: pointer;
        }
        .m-item.draggable-flow:active {
            cursor: grabbing;
        }
        .m-item.is-dragging {
            cursor: grabbing;
            opacity: 0.45;
            background: #e0f2fe;
            border: 1px dashed #0284c7;
        }
        .drop-target-hover {
            background: #e0f2fe !important;
            border: 1.5px dashed #0284c7 !important;
            transform: scale(1.02);
            transition: all 0.12s ease;
        }
        .step-item.draggable-step {
            cursor: pointer;
        }
        .step-item.draggable-step:active {
            cursor: grabbing;
        }
        .step-item.step-dragging {
            cursor: grabbing;
            opacity: 0.4;
            background: #eff6ff;
            border: 1.5px dashed #3b82f6;
        }
        .step-item.step-drop-hover {
            border: 2px dashed #0284c7 !important;
            background: #f0f9ff !important;
            transform: translateY(2px);
        }

        /* Inspector Form Controls */
        .m-inspector {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            min-height: 0;
            background: #ffffff;
        }
        .m-field-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .m-field-label {
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .m-field-input, .m-field-select {
            padding: 7px 10px;
            font-size: 12.5px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #ffffff;
            color: #0f172a;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
            box-sizing: border-box;
            width: 100%;
        }
        .m-field-input:focus, .m-field-select:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
        }

        /* Segmented Pipeline Nav */
        .m-pipe-tabs {
            display: flex;
            background: #f1f5f9;
            padding: 3px;
            border-radius: 7px;
            gap: 2px;
            margin-bottom: 12px;
        }
        .m-pipe-tab {
            flex: 1;
            border: none;
            background: transparent;
            padding: 5px 8px;
            font-size: 11.5px;
            font-weight: 600;
            color: #64748b;
            border-radius: 5px;
            cursor: pointer;
            text-align: center;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        .m-pipe-tab.active {
            background: #ffffff;
            color: #0284c7;
            box-shadow: 0 1px 2px rgba(0,0,0,0.06);
        }

        /* Step Pipeline Sequence */
        .step-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 12px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 7px;
            margin-bottom: 6px;
            transition: all 0.15s;
        }
        .step-item:hover {
            border-color: #cbd5e1;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .step-badge {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #f1f5f9;
            color: #475569;
            font-weight: 700;
            font-size: 10.5px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .step-origin {
            background: #ecfdf5;
            color: #059669;
            border-color: #a7f3d0;
        }
        .step-hub {
            background: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe;
        }
        .step-btn {
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #475569;
            border-radius: 5px;
            padding: 4px 7px;
            font-size: 10px;
            cursor: pointer;
            transition: all 0.1s;
        }
        .step-btn:hover:not(:disabled) {
            background: #e2e8f0;
            color: #0f172a;
        }
        .step-btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }
        .step-btn-remove {
            color: #ef4444;
            background: #fef2f2;
            border-color: #fecaca;
        }
        .step-btn-remove:hover {
            background: #fee2e2;
        }

        /* Suggestion Dropdown */
        .m-dropdown-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            max-height: 180px;
            overflow-y: auto;
            z-index: 50;
            margin-top: 3px;
        }
        .m-dropdown-row {
            padding: 7px 10px;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #f1f5f9;
        }
        .m-dropdown-row:last-child {
            border-bottom: none;
        }
        .m-dropdown-row:hover {
            background: #f0f9ff;
            color: #0284c7;
        }

        /* Footer Path Bar */
        .miller-path-bar {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-top: none;
            border-radius: 0 0 10px 10px;
            padding: 8px 14px;
            font-size: 11px;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        /* Switches & Badges */
        .switch {
            position: relative;
            display: inline-block;
            width: 36px;
            height: 20px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1; transition: .2s; border-radius: 20px;
        }
        .slider:before {
            position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px;
            background-color: white; transition: .2s; border-radius: 50%;
        }
        input:checked + .slider { background-color: #10b981; }
        input:checked + .slider:before { transform: translateX(16px); }

        .m-empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 30px 16px;
            color: #94a3b8;
            gap: 8px;
        }

        .toast-banner {
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .toast-banner.success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .toast-banner.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* -------------------------------------------------------------
           DARK MODE — DTS Transaction Flow Manager only
           ------------------------------------------------------------- */
        [data-theme="dark"] .miller-container {
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .miller-container h1,
        [data-theme="dark"] .miller-container [style*="color: #0f172a"],
        [data-theme="dark"] .miller-container [style*="color:#0f172a"] {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .miller-container [style*="color: #64748b"],
        [data-theme="dark"] .miller-container [style*="color:#64748b"],
        [data-theme="dark"] .miller-container [style*="color: #94a3b8"],
        [data-theme="dark"] .miller-container [style*="color:#94a3b8"] {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .miller-container .m-scope-nav {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        [data-theme="dark"] .miller-container .m-scope-tab {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .miller-container .m-scope-tab:hover {
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .miller-container .m-scope-tab.active {
            background: #1e293b !important;
            color: #7dd3fc !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.35) !important;
        }

        [data-theme="dark"] .miller-container .miller-browser {
            background: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35) !important;
        }

        [data-theme="dark"] .miller-container .miller-column {
            background: #131c2e !important;
            border-right-color: #1e293b !important;
        }

        [data-theme="dark"] .miller-container .m-col-header {
            background: #0f172a !important;
            border-bottom-color: #1e293b !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .miller-container .m-col-search {
            background: #131c2e !important;
            border-bottom-color: #1e293b !important;
        }

        [data-theme="dark"] .miller-container .m-col-search input {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .miller-container .m-col-search input::placeholder {
            color: #64748b !important;
            opacity: 1 !important;
        }

        [data-theme="dark"] .miller-container .m-col-search input:focus {
            border-color: #0284c7 !important;
            background: #0b1220 !important;
        }

        [data-theme="dark"] .miller-container .m-col-search i.search-icon {
            color: #64748b !important;
        }

        [data-theme="dark"] .miller-container .m-item {
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .miller-container .m-item:hover {
            background: #1e293b !important;
        }

        [data-theme="dark"] .miller-container .m-item.active {
            background: #0c4a6e !important;
            color: #e0f2fe !important;
            border-color: #0369a1 !important;
        }

        [data-theme="dark"] .miller-container .m-item-title {
            color: inherit !important;
        }

        [data-theme="dark"] .miller-container .m-item-sub {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .miller-container .m-item.active .m-item-sub,
        [data-theme="dark"] .miller-container .m-item.active .m-item-arrow {
            color: #7dd3fc !important;
        }

        [data-theme="dark"] .miller-container .m-code-chip {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .miller-container .m-rdp-chip {
            background: #312e81 !important;
            border-color: #4338ca !important;
            color: #c7d2fe !important;
        }

        [data-theme="dark"] .miller-container .m-batch-import-btn {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #7dd3fc !important;
        }

        [data-theme="dark"] .miller-container .m-batch-import-btn:hover {
            background: #0c4a6e !important;
            border-color: #0284c7 !important;
            color: #e0f2fe !important;
        }

        /* Catch remaining light chips that still use inline slate colors */
        [data-theme="dark"] .miller-container [style*="background: #f1f5f9"],
        [data-theme="dark"] .miller-container [style*="background:#f1f5f9"],
        [data-theme="dark"] .miller-container [style*="background: #f8fafc"],
        [data-theme="dark"] .miller-container [style*="background:#f8fafc"] {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        [data-theme="dark"] .miller-container [style*="color: #475569"],
        [data-theme="dark"] .miller-container [style*="color:#475569"],
        [data-theme="dark"] .miller-container [style*="color: #334155"],
        [data-theme="dark"] .miller-container [style*="color:#334155"] {
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .miller-container [style*="background: #e0e7ff"],
        [data-theme="dark"] .miller-container [style*="background:#e0e7ff"] {
            background: #312e81 !important;
            border-color: #4338ca !important;
        }

        [data-theme="dark"] .miller-container [style*="color: #4338ca"],
        [data-theme="dark"] .miller-container [style*="color:#4338ca"] {
            color: #c7d2fe !important;
        }

        [data-theme="dark"] .miller-container .m-item-badge {
            background: #1e293b !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .miller-container .m-item.active .m-item-badge {
            background: #0369a1 !important;
            color: #e0f2fe !important;
        }

        [data-theme="dark"] .miller-container .m-item-arrow {
            color: #64748b !important;
        }

        [data-theme="dark"] .miller-container .m-item.is-dragging,
        [data-theme="dark"] .miller-container .drop-target-hover {
            background: #0c4a6e !important;
            border-color: #0284c7 !important;
        }

        [data-theme="dark"] .miller-container .m-inspector {
            background: #131c2e !important;
        }

        [data-theme="dark"] .miller-container .m-field-label {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .miller-container .m-field-input,
        [data-theme="dark"] .miller-container .m-field-select {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .miller-container .m-field-input:focus,
        [data-theme="dark"] .miller-container .m-field-select:focus {
            border-color: #0284c7 !important;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.25) !important;
        }

        [data-theme="dark"] .miller-container .m-pipe-tabs {
            background: #0f172a !important;
        }

        [data-theme="dark"] .miller-container .m-pipe-tab {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .miller-container .m-pipe-tab.active {
            background: #1e293b !important;
            color: #7dd3fc !important;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.35) !important;
        }

        [data-theme="dark"] .miller-container .step-item {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .miller-container .step-item:hover {
            border-color: #475569 !important;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.35) !important;
        }

        [data-theme="dark"] .miller-container .step-badge {
            background: #1e293b !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .miller-container .step-origin {
            background: #064e3b !important;
            color: #6ee7b7 !important;
            border-color: #059669 !important;
        }

        [data-theme="dark"] .miller-container .step-hub {
            background: #1e3a8a !important;
            color: #93c5fd !important;
            border-color: #3b82f6 !important;
        }

        [data-theme="dark"] .miller-container .step-btn {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .miller-container .step-btn:hover:not(:disabled) {
            background: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .miller-container .step-btn-remove {
            color: #f87171 !important;
            background: rgba(127, 29, 29, 0.35) !important;
            border-color: #7f1d1d !important;
        }

        [data-theme="dark"] .miller-container .step-btn-remove:hover {
            background: rgba(153, 27, 27, 0.55) !important;
        }

        [data-theme="dark"] .miller-container .step-item.step-dragging,
        [data-theme="dark"] .miller-container .step-item.step-drop-hover {
            background: #0c4a6e !important;
            border-color: #0284c7 !important;
        }

        [data-theme="dark"] .miller-container .m-dropdown-results {
            background: #0f172a !important;
            border-color: #334155 !important;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45) !important;
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .miller-container .m-dropdown-row {
            border-bottom-color: #1e293b !important;
        }

        [data-theme="dark"] .miller-container .m-dropdown-row:hover {
            background: #0c4a6e !important;
            color: #7dd3fc !important;
        }

        [data-theme="dark"] .miller-container .miller-path-bar {
            background: #0f172a !important;
            border-color: #1e293b !important;
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .miller-container .m-empty-state {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .miller-container .toast-banner.success {
            background: #064e3b !important;
            color: #a7f3d0 !important;
            border-color: #059669 !important;
        }

        [data-theme="dark"] .miller-container .toast-banner.error {
            background: #7f1d1d !important;
            color: #fecaca !important;
            border-color: #b91c1c !important;
        }

        [data-theme="dark"] .miller-container .slider {
            background-color: #475569 !important;
        }

        [data-theme="dark"] .miller-container input:checked + .slider {
            background-color: #10b981 !important;
        }
    </style>
@endpush

<div class="miller-container">
    {{-- TOP ACTION & SCOPE BAR --}}
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div>
                <h1 style="font-size: 19px; font-weight: 700; margin: 0; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-arrows-split-up-and-left" style="color: #0284c7;"></i>
                    DTS Transaction Flow Manager
                </h1>
                <p style="font-size: 12px; margin: 2px 0 0 0; color: #64748b;">
                    Miller Column workflow orchestrator &bull; Sequence Routing &bull; RDP Retention Continuity
                </p>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 10px;">
            <div class="m-scope-nav">
                <button type="button" class="m-scope-tab {{ $activeTab === 'predefined' ? 'active' : '' }}" wire:click="$set('activeTab', 'predefined')">
                    <i class="fa-solid fa-diagram-project"></i> Predefined Standard Flows
                </button>
                <button type="button" class="m-scope-tab {{ $activeTab === 'custom' ? 'active' : '' }}" wire:click="$set('activeTab', 'custom')">
                    <i class="fa-solid fa-bezier-curve"></i> Custom Office Flows
                </button>
            </div>

            @if($activeTab === 'predefined')
                <button type="button" wire:click="startCreate" style="background: #0284c7; color: #ffffff; border: none; padding: 7px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-plus"></i> New Flow
                </button>
            @else
                <button type="button" wire:click="startCreateCustom" style="background: #0284c7; color: #ffffff; border: none; padding: 7px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-plus"></i> New Custom Flow
                </button>
            @endif
        </div>
    </div>

    {{-- ALERTS --}}
    @if($successMessage)
        <div class="toast-banner success">
            <i class="fa-solid fa-circle-check"></i>
            <span>{{ $successMessage }}</span>
            <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; cursor: pointer; color: inherit;"><i class="fa-solid fa-xmark"></i></button>
        </div>
    @endif
    @if($errorMessage)
        <div class="toast-banner error">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>{{ $errorMessage }}</span>
            <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; cursor: pointer; color: inherit;"><i class="fa-solid fa-xmark"></i></button>
        </div>
    @endif

    {{-- ======================================================== --}}
    {{-- MODE 1: PREDEFINED STANDARD FLOWS                        --}}
    {{-- ======================================================== --}}
    @if($activeTab === 'predefined')
        <div class="miller-browser">
            {{-- COLUMN 1: SCOPES & PURPOSE FILTERS --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-filter" style="color: #64748b;"></i> 1. Scopes & Purpose</span>
                    <span class="m-item-badge">{{ count($predefinedFlows) }}</span>
                </div>

                <div class="m-col-list">
                    <div style="padding: 4px 6px; font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Workflow Purpose</div>
                    
                    <div class="m-item {{ $predefinedPurposeFilter === 'all' ? 'active' : '' }}" wire:click="$set('predefinedPurposeFilter', 'all')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-layer-group m-item-icon" style="color: #64748b;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">All Purpose Flows</span>
                                <span class="m-item-sub">Complete system catalog</span>
                            </div>
                        </div>
                        <div class="m-item-right"><i class="fa-solid fa-chevron-right m-item-arrow"></i></div>
                    </div>

                    <div class="m-item {{ $predefinedPurposeFilter === 'internal' ? 'active' : '' }}"
                         wire:click="$set('predefinedPurposeFilter', 'internal')"
                         ondragover="event.preventDefault(); event.dataTransfer.dropEffect = 'move'; this.classList.add('drop-target-hover');"
                         ondragleave="this.classList.remove('drop-target-hover');"
                         ondrop="event.preventDefault(); this.classList.remove('drop-target-hover'); const fId = event.dataTransfer.getData('text/flow-id'); if (fId) { window.dtsChangePurpose(fId, 'internal', this); }">
                        <div class="m-item-left">
                            <i class="fa-solid fa-building m-item-icon" style="color: #0284c7;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">Internal Only</span>
                                <span class="m-item-sub">Intra-college circulation</span>
                            </div>
                        </div>
                        <div class="m-item-right"><i class="fa-solid fa-chevron-right m-item-arrow"></i></div>
                    </div>

                    <div class="m-item {{ $predefinedPurposeFilter === 'external' ? 'active' : '' }}"
                         wire:click="$set('predefinedPurposeFilter', 'external')"
                         ondragover="event.preventDefault(); event.dataTransfer.dropEffect = 'move'; this.classList.add('drop-target-hover');"
                         ondragleave="this.classList.remove('drop-target-hover');"
                         ondrop="event.preventDefault(); this.classList.remove('drop-target-hover'); const fId = event.dataTransfer.getData('text/flow-id'); if (fId) { window.dtsChangePurpose(fId, 'external', this); }">
                        <div class="m-item-left">
                            <i class="fa-solid fa-earth-americas m-item-icon" style="color: #059669;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">External Only</span>
                                <span class="m-item-sub">Outbound / Agency flows</span>
                            </div>
                        </div>
                        <div class="m-item-right"><i class="fa-solid fa-chevron-right m-item-arrow"></i></div>
                    </div>

                    <div class="m-item {{ $predefinedPurposeFilter === 'issuances' ? 'active' : '' }}"
                         wire:click="$set('predefinedPurposeFilter', 'issuances')"
                         ondragover="event.preventDefault(); event.dataTransfer.dropEffect = 'move'; this.classList.add('drop-target-hover');"
                         ondragleave="this.classList.remove('drop-target-hover');"
                         ondrop="event.preventDefault(); this.classList.remove('drop-target-hover'); const fId = event.dataTransfer.getData('text/flow-id'); if (fId) { window.dtsChangePurpose(fId, 'issuances', this); }">
                        <div class="m-item-left">
                            <i class="fa-solid fa-scroll m-item-icon" style="color: #d97706;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">Issuances</span>
                                <span class="m-item-sub">Special orders & memos</span>
                            </div>
                        </div>
                        <div class="m-item-right"><i class="fa-solid fa-chevron-right m-item-arrow"></i></div>
                    </div>

                    <div class="m-item {{ $predefinedPurposeFilter === 'application' ? 'active' : '' }}"
                         wire:click="$set('predefinedPurposeFilter', 'application')"
                         ondragover="event.preventDefault(); event.dataTransfer.dropEffect = 'move'; this.classList.add('drop-target-hover');"
                         ondragleave="this.classList.remove('drop-target-hover');"
                         ondrop="event.preventDefault(); this.classList.remove('drop-target-hover'); const fId = event.dataTransfer.getData('text/flow-id'); if (fId) { window.dtsChangePurpose(fId, 'application', this); }">
                        <div class="m-item-left">
                            <i class="fa-solid fa-file-signature m-item-icon" style="color: #7c3aed;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">Applications</span>
                                <span class="m-item-sub">Clearances & requests</span>
                            </div>
                        </div>
                        <div class="m-item-right"><i class="fa-solid fa-chevron-right m-item-arrow"></i></div>
                    </div>

                    <div class="m-item {{ $predefinedPurposeFilter === 'none' ? 'active' : '' }}"
                         wire:click="$set('predefinedPurposeFilter', 'none')"
                         ondragover="event.preventDefault(); event.dataTransfer.dropEffect = 'move'; this.classList.add('drop-target-hover');"
                         ondragleave="this.classList.remove('drop-target-hover');"
                         ondrop="event.preventDefault(); this.classList.remove('drop-target-hover'); const fId = event.dataTransfer.getData('text/flow-id'); if (fId) { window.dtsChangePurpose(fId, 'none', this); }">
                        <div class="m-item-left">
                            <i class="fa-solid fa-folder-tree m-item-icon" style="color: #64748b;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">General / None</span>
                                <span class="m-item-sub">Uncategorized flows</span>
                            </div>
                        </div>
                        <div class="m-item-right"><i class="fa-solid fa-chevron-right m-item-arrow"></i></div>
                    </div>

                    <div style="margin-top: 10px; padding: 4px 6px; font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Flow Status</div>

                    <div class="m-item {{ $statusFilter === 'all' ? 'active' : '' }}" wire:click="$set('statusFilter', 'all')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-circle-dot m-item-icon" style="color: #64748b;"></i>
                            <div class="m-item-text"><span class="m-item-title">All Statuses</span></div>
                        </div>
                    </div>
                    <div class="m-item {{ $statusFilter === 'active' ? 'active' : '' }}" wire:click="$set('statusFilter', 'active')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-circle-check m-item-icon" style="color: #10b981;"></i>
                            <div class="m-item-text"><span class="m-item-title">Active Only</span></div>
                        </div>
                    </div>
                    <div class="m-item {{ $statusFilter === 'inactive' ? 'active' : '' }}" wire:click="$set('statusFilter', 'inactive')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-circle-xmark m-item-icon" style="color: #ef4444;"></i>
                            <div class="m-item-text"><span class="m-item-title">Deactivated</span></div>
                        </div>
                    </div>

                    <div style="margin-top: 12px; padding: 6px;">
                        <button type="button" wire:click="startImport" class="m-batch-import-btn">
                            <i class="fa-solid fa-file-arrow-up"></i> Batch TXT Import
                        </button>
                    </div>
                </div>
            </div>

            {{-- COLUMN 2: WORKFLOW DIRECTORY --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-list-check" style="color: #64748b;"></i> 2. Predefined Flows</span>
                    <span class="m-item-badge">{{ count($predefinedFlows) }}</span>
                </div>

                <div class="m-col-search">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" placeholder="Search flows by name or code..." wire:model.live.debounce.200ms="searchPredefined">
                </div>

                <div class="m-col-list">
                    @forelse($predefinedFlows as $f)
                        <div class="m-item draggable-flow {{ $selectedPredefined == $f->id ? 'active' : '' }}"
                             draggable="true"
                             ondragstart="event.dataTransfer.setData('text/flow-id', '{{ $f->id }}'); event.dataTransfer.effectAllowed = 'move'; this.classList.add('is-dragging');"
                             ondragend="this.classList.remove('is-dragging');"
                             title="Click to inspect, or drag to Column 1 to change scope"
                             wire:click="selectFlow({{ $f->id }})">
                            <div class="m-item-left">
                                <i class="fa-solid fa-route" style="font-size: 12px; color: {{ $f->is_active ? '#0284c7' : '#94a3b8' }};"></i>
                                <div class="m-item-text" style="min-width: 0;">
                                    <span class="m-item-title">{{ $f->flow_name }}</span>
                                    <div style="display: flex; align-items: center; gap: 4px; margin-top: 2px;">
                                        <span class="m-code-chip">{{ $f->flow_code }}</span>
                                        <span class="m-item-badge" style="font-size: 9px; padding: 1px 5px;">{{ $f->step_count }} stops</span>
                                        @if($f->rdp_series_title)
                                            <span class="m-rdp-chip">RDP</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                            </div>
                        </div>
                    @empty
                        <div class="m-empty-state">
                            <i class="fa-solid fa-folder-open" style="font-size: 24px;"></i>
                            <span style="font-size: 12px;">No predefined flows match filter.</span>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- COLUMN 3: FLOW CONFIGURATION & RDP CONTINUITY --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-sliders" style="color: #64748b;"></i> 3. Flow Config & RDP Link</span>
                    @if($selectedPredefined && $selectedPredefined !== 'import')
                        <span class="m-item-badge" style="background: {{ $isActive ? '#ecfdf5' : '#fee2e2' }}; color: {{ $isActive ? '#065f46' : '#991b1b' }};">
                            {{ $isActive ? 'Active' : 'Inactive' }}
                        </span>
                    @endif
                </div>

                <div class="m-inspector">
                    @if($selectedPredefined === 'import')
                        {{-- IMPORT FORM VIEW --}}
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;">
                            <h3 style="margin: 0 0 6px 0; font-size: 13.5px; color: #0284c7; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-file-import"></i> TXT Batch Import
                            </h3>
                            <p style="margin: 0 0 10px 0; font-size: 11.5px; color: #64748b;">
                                Upload a structured text file formatted with sequence nodes (<code style="background:#e2e8f0; padding:1px 3px;">=Name; Code; []->OFFICE->[];</code>).
                            </p>

                            <input type="file" wire:model="flowFile" accept=".txt,text/plain" class="m-field-input" style="padding: 6px;">
                            @error('flowFile') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror

                            <div style="margin-top: 12px; display: flex; gap: 8px;">
                                <button type="button" wire:click="importFlow" style="flex: 1; background: #0284c7; color: #fff; border: none; padding: 7px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer;">
                                    <i class="fa-solid fa-upload"></i> Process Import
                                </button>
                                <button type="button" wire:click="resetForm" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 7px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer;">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    @elseif($selectedPredefined)
                        {{-- CONFIGURATION FORM --}}
                        <div class="m-field-group">
                            <label class="m-field-label">Flow Title / Name</label>
                            <input type="text" class="m-field-input" placeholder="e.g. Standard Procurement Approval" wire:model="flowName">
                            @error('flowName') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">
                                <span>Flow Code</span>
                                <span style="font-size: 10px; color: #64748b; font-weight: normal;">Unique ID</span>
                            </label>
                            <input type="text" class="m-field-input" placeholder="e.g. FLOW-PR-APP" wire:model="flowCode" {{ $selectedPredefined !== 'new' ? 'readonly' : '' }} style="font-family: monospace; text-transform: uppercase;">
                            @error('flowCode') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">Workflow Purpose</label>
                            <select class="m-field-select" wire:model="flowUse">
                                <option value="none">General / None</option>
                                <option value="internal">Internal Workflow</option>
                                <option value="external">External / Inter-Agency</option>
                                <option value="issuances">Issuances & Directives</option>
                                <option value="application">Application & Clearance</option>
                                <option value="others">Other Routing</option>
                            </select>
                        </div>

                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
                            <div>
                                <span style="font-size: 12px; font-weight: 600; color: #1e293b; display: block;">Flow Active For Use?</span>
                                <span style="font-size: 10.5px; color: #64748b;">Available for routing in DTS</span>
                            </div>
                            <label class="switch">
                                <input type="checkbox" wire:model.live="isActive">
                                <span class="slider"></span>
                            </label>
                        </div>

                        {{-- RDP RECORD SERIES CONTINUITY --}}
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-top: 4px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #4338ca; display: flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid fa-box-archive"></i> RDP Retention Continuity
                                </span>
                                @if($continRdpId)
                                    <button type="button" wire:click="clearContinRdpSeries" style="background: none; border: none; font-size: 10px; color: #ef4444; cursor: pointer; font-weight: 600;">
                                        Disconnect
                                    </button>
                                @endif
                            </div>
                            <p style="font-size: 11px; color: #64748b; margin: 0 0 8px 0;">
                                Connect this workflow to an RDP Record Series to automatically assign retention & disposal policies.
                            </p>

                            @if($continRdpId)
                                <div style="display: flex; align-items: center; gap: 8px; padding: 6px 10px; background: #e0e7ff; border-radius: 6px; border: 1px solid #c7d2fe;">
                                    <i class="fa-solid fa-link" style="color: #4338ca; font-size: 11px;"></i>
                                    <span style="font-size: 11.5px; font-weight: 600; color: #3730a3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        {{ $this->continRdpSelectedLabel($continRdpId, $continRdpSearch) }}
                                    </span>
                                </div>
                            @else
                                <div style="position: relative;">
                                    <input type="text" class="m-field-input" placeholder="Search record series title or #number..." wire:model.live.debounce.150ms="continRdpSearch" wire:focus="$set('showRdpDropdown', true)">
                                    @if($showRdpDropdown && strlen($continRdpSearch) > 0)
                                        <div class="m-dropdown-results">
                                            @foreach($this->continRdpChoices($continRdpSearch, $continRdpTypeFilter) as $series)
                                                <div class="m-dropdown-row" wire:click="selectContinRdpSeries({{ $series->id }})">
                                                    <span style="font-weight: 600;">#{{ $series->item_number }} &bull; {{ $series->series_title }}</span>
                                                    <span style="font-size: 10px; color: #64748b;">{{ $series->shorted_type }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>

                        {{-- ACTIONS --}}
                        <div style="margin-top: auto; padding-top: 14px; border-top: 1px solid #f1f5f9; display: flex; flex-direction: column; gap: 8px;">
                            <button type="button" wire:click="savePredefinedFlow" style="width: 100%; background: #0284c7; color: #ffffff; border: none; padding: 8px 14px; border-radius: 6px; font-size: 12.5px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                <i class="fa-solid fa-floppy-disk"></i> Save Flow Settings
                            </button>

                            @if($selectedPredefined !== 'new')
                                @if($isActive)
                                    <button type="button" wire:click="deleteFlow" style="width: 100%; background: #fff1f2; color: #e11d48; border: 1px solid #ffe4e6; padding: 7px 14px; border-radius: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                        <i class="fa-solid fa-ban"></i> Deactivate Predefined Flow
                                    </button>
                                @else
                                    <button type="button" wire:click="toggleFlowActive" style="width: 100%; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; padding: 7px 14px; border-radius: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                        <i class="fa-solid fa-circle-check"></i> Reactivate Predefined Flow
                                    </button>
                                @endif
                            @endif
                        </div>
                    @else
                        <div class="m-empty-state" style="padding-top: 60px;">
                            <i class="fa-solid fa-sliders" style="font-size: 28px;"></i>
                            <span style="font-size: 13px; font-weight: 600;">Select a flow to configure</span>
                            <span style="font-size: 11px;">Pick from Directory or click "+ New Flow"</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- COLUMN 4: ROUTING PIPELINE & DISTRIBUTION (DEDICATED VISUAL AREA) --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-route" style="color: #64748b;"></i> 4. Routing Pipeline & Distribution</span>
                    @if($selectedPredefined && $selectedPredefined !== 'import')
                        <span class="m-item-badge">{{ count($flowOffices) }} Stops</span>
                    @endif
                </div>

                <div class="m-inspector" style="background: #f8fafc;">
                    @if($selectedPredefined && $selectedPredefined !== 'import')
                        {{-- SEGMENTED PIPELINE NAV --}}
                        <div class="m-pipe-tabs">
                            <button type="button" class="m-pipe-tab {{ $flowPipelineTab === 'sequence' ? 'active' : '' }}" wire:click="$set('flowPipelineTab', 'sequence')">
                                <i class="fa-solid fa-arrow-down-1-9"></i> Stops ({{ count($flowOffices) }})
                            </button>
                            <button type="button" class="m-pipe-tab {{ $flowPipelineTab === 'cf' ? 'active' : '' }}" wire:click="$set('flowPipelineTab', 'cf')">
                                <i class="fa-solid fa-copy"></i> Copy Furnished ({{ count($cfOffices) }})
                            </button>
                            <button type="button" class="m-pipe-tab {{ $flowPipelineTab === 'hub' ? 'active' : '' }}" wire:click="$set('flowPipelineTab', 'hub')">
                                <i class="fa-solid fa-share-nodes"></i> DTS Hub ({{ count($hubOffices) }})
                            </button>
                        </div>

                        {{-- SUB-VIEW 1: SEQUENCE STOPS --}}
                        @if($flowPipelineTab === 'sequence')
                            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 10px;">
                                <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px; display: block;">
                                    <i class="fa-solid fa-plus-circle" style="color: #0284c7;"></i> Append Office Stop
                                </label>
                                <div style="position: relative;">
                                    <input type="text" class="m-field-input" placeholder="Type office code or name to add..." wire:model.live.debounce.150ms="officeSearch" wire:focus="$set('showOfficeDropdown', true)">
                                    @if($showOfficeDropdown && strlen($officeSearch) > 0)
                                        <div class="m-dropdown-results">
                                            @foreach($activeOffices as $o)
                                                @if(stripos($o->office_name, $officeSearch) !== false || stripos($o->office_code, $officeSearch) !== false)
                                                    <div class="m-dropdown-row" wire:click="selectOfficeForAppend('{{ $o->office_code }}', '{{ addslashes($o->office_name) }}')">
                                                        <span style="font-weight: 600;">{{ $o->office_name }}</span>
                                                        <span style="font-family: monospace; font-size: 10px; color: #0284c7;">{{ $o->office_code }}</span>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div style="flex: 1; overflow-y: auto;">
                                <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 6px;">
                                    Sequential Routing Ladder
                                </div>

                                @foreach($flowOffices as $idx => $code)
                                    @php
                                        $isDraggable = ($idx > 0 && ($idx < count($flowOffices) - 1 || $code !== 'ORIGIN'));
                                    @endphp
                                    <div class="step-item {{ $isDraggable ? 'draggable-step' : '' }} {{ $code === 'ORIGIN' ? 'step-origin' : ($code === '[H]' || $code === '[HUB]' ? 'step-hub' : '') }}"
                                         @if($isDraggable)
                                             draggable="true"
                                             ondragstart="event.dataTransfer.setData('text/step-index', '{{ $idx }}'); event.dataTransfer.effectAllowed = 'move'; this.classList.add('step-dragging');"
                                             ondragend="this.classList.remove('step-dragging');"
                                             ondragover="event.preventDefault(); event.dataTransfer.dropEffect = 'move'; this.classList.add('step-drop-hover');"
                                             ondragleave="this.classList.remove('step-drop-hover');"
                                             ondrop="event.preventDefault(); this.classList.remove('step-drop-hover'); const fromIdx = event.dataTransfer.getData('text/step-index'); if(fromIdx !== '' && parseInt(fromIdx) !== {{ $idx }}) { window.dtsReorderStep(fromIdx, {{ $idx }}, this); }"
                                         @endif>
                                        <div style="display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1;">
                                            @if($isDraggable)
                                                <i class="fa-solid fa-grip-vertical" style="color: #94a3b8; font-size: 11px; cursor: pointer;" title="Drag up or down to reorder stop"></i>
                                            @else
                                                <span style="width: 11px;"></span>
                                            @endif
                                            <div class="step-badge {{ $code === 'ORIGIN' ? 'step-origin' : '' }}">
                                                {{ $idx + 1 }}
                                            </div>
                                            <div style="min-width: 0;">
                                                <div style="font-size: 12px; font-weight: 700; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    @if($code === 'ORIGIN')
                                                        Originating Office (Sender)
                                                    @elseif($code === '[H]' || $code === '[HUB]')
                                                        DTS Central Routing Hub
                                                    @else
                                                        {{ $officeLookup[$code] ?? $code }}
                                                    @endif
                                                </div>
                                                <span style="font-family: monospace; font-size: 10px; color: #64748b;">
                                                    {{ $code }}
                                                </span>
                                            </div>
                                        </div>

                                        <div style="display: flex; align-items: center; gap: 4px;">
                                            @if($idx > 1)
                                                <button type="button" class="step-btn" wire:click="moveOfficeUp({{ $idx }})" title="Move Step Up">
                                                    <i class="fa-solid fa-arrow-up"></i>
                                                </button>
                                            @endif
                                            @if($idx > 0 && $idx < count($flowOffices) - 1)
                                                <button type="button" class="step-btn" wire:click="moveOfficeDown({{ $idx }})" title="Move Step Down">
                                                    <i class="fa-solid fa-arrow-down"></i>
                                                </button>
                                            @endif
                                            @if($idx > 0)
                                                <button type="button" class="step-btn step-btn-remove" wire:click="removeOffice({{ $idx }})" title="Delete Stop">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                        {{-- SUB-VIEW 2: COPY FURNISHED --}}
                        @elseif($flowPipelineTab === 'cf')
                            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 10px;">
                                <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px; display: block;">
                                    <i class="fa-solid fa-copy" style="color: #0284c7;"></i> Add Copy Furnished Recipient
                                </label>
                                <div style="position: relative;">
                                    <input type="text" class="m-field-input" placeholder="Search office for copy furnished..." wire:model.live.debounce.150ms="cfSearch" wire:focus="$set('showCfDropdown', true)">
                                    @if($showCfDropdown && strlen($cfSearch) > 0)
                                        <div class="m-dropdown-results">
                                            @foreach($activeOffices as $o)
                                                @if(stripos($o->office_name, $cfSearch) !== false || stripos($o->office_code, $cfSearch) !== false)
                                                    <div class="m-dropdown-row" wire:click="selectCfOfficeForAppend('{{ $o->office_code }}', '{{ addslashes($o->office_name) }}')">
                                                        <span style="font-weight: 600;">{{ $o->office_name }}</span>
                                                        <span style="font-family: monospace; font-size: 10px; color: #0284c7;">{{ $o->office_code }}</span>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div style="flex: 1; overflow-y: auto;">
                                @forelse($cfOffices as $cIdx => $cCode)
                                    <div class="step-item">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <i class="fa-solid fa-file-invoice" style="color: #64748b; font-size: 12px;"></i>
                                            <div>
                                                <span style="font-size: 12px; font-weight: 600;">{{ $officeLookup[$cCode] ?? $cCode }}</span>
                                                <span style="font-family: monospace; font-size: 10px; color: #64748b; display: block;">{{ $cCode }}</span>
                                            </div>
                                        </div>
                                        <button type="button" class="step-btn step-btn-remove" wire:click="removeCfOffice({{ $cIdx }})">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </div>
                                @empty
                                    <div class="m-empty-state">
                                        <i class="fa-solid fa-copy" style="font-size: 20px;"></i>
                                        <span>No Copy Furnished recipients designated.</span>
                                    </div>
                                @endforelse
                            </div>

                        {{-- SUB-VIEW 3: DTS HUB --}}
                        @elseif($flowPipelineTab === 'hub')
                            <div style="display: flex; gap: 6px; margin-bottom: 10px;">
                                <button type="button" wire:click="selectAllHubOffices" style="flex: 1; background: #ffffff; border: 1px solid #cbd5e1; padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; color: #0284c7;">
                                    <i class="fa-solid fa-check-double"></i> Select All Active
                                </button>
                                <button type="button" wire:click="clearHubOffices" style="background: #ffffff; border: 1px solid #cbd5e1; padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; color: #ef4444;">
                                    Clear
                                </button>
                            </div>

                            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 10px;">
                                <div style="position: relative;">
                                    <input type="text" class="m-field-input" placeholder="Search and add hub office..." wire:model.live.debounce.150ms="hubSearch" wire:focus="$set('showHubDropdown', true)">
                                    @if($showHubDropdown && strlen($hubSearch) > 0)
                                        <div class="m-dropdown-results">
                                            @foreach($activeOffices as $o)
                                                @if(stripos($o->office_name, $hubSearch) !== false || stripos($o->office_code, $hubSearch) !== false)
                                                    <div class="m-dropdown-row" wire:click="selectHubOfficeForAppend('{{ $o->office_code }}', '{{ addslashes($o->office_name) }}')">
                                                        <span style="font-weight: 600;">{{ $o->office_name }}</span>
                                                        <span style="font-family: monospace; font-size: 10px; color: #0284c7;">{{ $o->office_code }}</span>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div style="flex: 1; overflow-y: auto;">
                                @forelse($hubOffices as $hIdx => $hCode)
                                    <div class="step-item">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <i class="fa-solid fa-share-nodes" style="color: #2563eb; font-size: 12px;"></i>
                                            <div>
                                                <span style="font-size: 12px; font-weight: 600;">{{ $officeLookup[$hCode] ?? $hCode }}</span>
                                                <span style="font-family: monospace; font-size: 10px; color: #64748b; display: block;">{{ $hCode }}</span>
                                            </div>
                                        </div>
                                        <button type="button" class="step-btn step-btn-remove" wire:click="removeHubOffice({{ $hIdx }})">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </div>
                                @empty
                                    <div class="m-empty-state">
                                        <i class="fa-solid fa-share-nodes" style="font-size: 20px;"></i>
                                        <span>No DTS Hub receiving offices selected.</span>
                                    </div>
                                @endforelse
                            </div>
                        @endif

                    @else
                        <div class="m-empty-state" style="padding-top: 60px;">
                            <i class="fa-solid fa-bezier-curve" style="font-size: 28px;"></i>
                            <span style="font-size: 13px; font-weight: 600;">Pipeline View Inactive</span>
                            <span style="font-size: 11px;">Select a flow from Column 2 to visualize routing ladder</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- BREADCRUMBS PATH BAR --}}
        <div class="miller-path-bar">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-folder-tree" style="color: #64748b;"></i>
                <span>Root</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #94a3b8;"></i>
                <span>{{ ucfirst($predefinedPurposeFilter) }} Purpose</span>
                @if($selectedPredefined && $selectedPredefined !== 'new' && $selectedPredefined !== 'import')
                    <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #94a3b8;"></i>
                    <span style="color: #0284c7; font-weight: 700;">{{ $flowName }} ({{ $flowCode }})</span>
                    <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #94a3b8;"></i>
                    <span>{{ count($flowOffices) }} Steps</span>
                @elseif($selectedPredefined === 'new')
                    <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #94a3b8;"></i>
                    <span style="color: #0284c7; font-weight: 700;">Creating New Flow</span>
                @endif
            </div>

            @if($selectedPredefined && $selectedPredefined !== 'import')
                <button type="button" wire:click="savePredefinedFlow" style="background: none; border: none; color: #0284c7; font-size: 11.5px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-floppy-disk"></i> Quick Save
                </button>
            @endif
        </div>

    {{-- ======================================================== --}}
    {{-- MODE 2: CUSTOM OFFICE GENERATED FLOWS                    --}}
    {{-- ======================================================== --}}
    @else
        <div class="miller-browser">
            {{-- COLUMN 1: CUSTOM FLOW SCOPES --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-filter" style="color: #64748b;"></i> 1. Scope & Purpose</span>
                    <span class="m-item-badge">{{ count($customFlows) }}</span>
                </div>

                <div class="m-col-list">
                    <div style="padding: 4px 6px; font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Custom Purpose</div>

                    <div class="m-item {{ $customPurposeFilter === 'all' ? 'active' : '' }}" wire:click="$set('customPurposeFilter', 'all')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-layer-group" style="color: #64748b;"></i>
                            <div class="m-item-text"><span class="m-item-title">All Custom Flows</span></div>
                        </div>
                    </div>
                    <div class="m-item {{ $customPurposeFilter === 'internal' ? 'active' : '' }}"
                         wire:click="$set('customPurposeFilter', 'internal')"
                         ondragover="event.preventDefault(); event.dataTransfer.dropEffect = 'move'; this.classList.add('drop-target-hover');"
                         ondragleave="this.classList.remove('drop-target-hover');"
                         ondrop="event.preventDefault(); this.classList.remove('drop-target-hover'); const fId = event.dataTransfer.getData('text/flow-id'); if (fId) { window.dtsChangeCustomPurpose(fId, 'internal', this); }">
                        <div class="m-item-left">
                            <i class="fa-solid fa-building" style="color: #0284c7;"></i>
                            <div class="m-item-text"><span class="m-item-title">Internal Only</span></div>
                        </div>
                    </div>
                    <div class="m-item {{ $customPurposeFilter === 'external' ? 'active' : '' }}"
                         wire:click="$set('customPurposeFilter', 'external')"
                         ondragover="event.preventDefault(); event.dataTransfer.dropEffect = 'move'; this.classList.add('drop-target-hover');"
                         ondragleave="this.classList.remove('drop-target-hover');"
                         ondrop="event.preventDefault(); this.classList.remove('drop-target-hover'); const fId = event.dataTransfer.getData('text/flow-id'); if (fId) { window.dtsChangeCustomPurpose(fId, 'external', this); }">
                        <div class="m-item-left">
                            <i class="fa-solid fa-earth-americas" style="color: #059669;"></i>
                            <div class="m-item-text"><span class="m-item-title">External Only</span></div>
                        </div>
                    </div>
                    <div class="m-item {{ $customPurposeFilter === 'issuances' ? 'active' : '' }}"
                         wire:click="$set('customPurposeFilter', 'issuances')"
                         ondragover="event.preventDefault(); event.dataTransfer.dropEffect = 'move'; this.classList.add('drop-target-hover');"
                         ondragleave="this.classList.remove('drop-target-hover');"
                         ondrop="event.preventDefault(); this.classList.remove('drop-target-hover'); const fId = event.dataTransfer.getData('text/flow-id'); if (fId) { window.dtsChangeCustomPurpose(fId, 'issuances', this); }">
                        <div class="m-item-left">
                            <i class="fa-solid fa-scroll" style="color: #d97706;"></i>
                            <div class="m-item-text"><span class="m-item-title">Issuances</span></div>
                        </div>
                    </div>
                    <div class="m-item {{ $customPurposeFilter === 'none' ? 'active' : '' }}"
                         wire:click="$set('customPurposeFilter', 'none')"
                         ondragover="event.preventDefault(); event.dataTransfer.dropEffect = 'move'; this.classList.add('drop-target-hover');"
                         ondragleave="this.classList.remove('drop-target-hover');"
                         ondrop="event.preventDefault(); this.classList.remove('drop-target-hover'); const fId = event.dataTransfer.getData('text/flow-id'); if (fId) { window.dtsChangeCustomPurpose(fId, 'none', this); }">
                        <div class="m-item-left">
                            <i class="fa-solid fa-folder-tree" style="color: #64748b;"></i>
                            <div class="m-item-text"><span class="m-item-title">General / None</span></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- COLUMN 2: CUSTOM FLOWS DIRECTORY --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-list-check" style="color: #64748b;"></i> 2. Custom Flows Directory</span>
                    <span class="m-item-badge">{{ count($customFlows) }}</span>
                </div>

                <div class="m-col-search">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" placeholder="Search custom flows..." wire:model.live.debounce.200ms="searchCustom">
                </div>

                <div class="m-col-list">
                    @forelse($customFlows as $cf)
                        <div class="m-item draggable-flow {{ $selectedCustom == $cf->id ? 'active' : '' }}"
                             draggable="true"
                             ondragstart="event.dataTransfer.setData('text/flow-id', '{{ $cf->id }}'); event.dataTransfer.effectAllowed = 'move'; this.classList.add('is-dragging');"
                             ondragend="this.classList.remove('is-dragging');"
                             title="Click to inspect, or drag to Column 1 to change scope"
                             wire:click="selectCustomFlow({{ $cf->id }})">
                            <div class="m-item-left">
                                <i class="fa-solid fa-bezier-curve" style="font-size: 12px; color: {{ $cf->is_active ? '#0284c7' : '#94a3b8' }};"></i>
                                <div class="m-item-text" style="min-width: 0;">
                                    <span class="m-item-title">{{ $cf->flow_name }}</span>
                                    <span class="m-item-sub">By {{ $cf->office_name ?? $cf->username ?? 'System' }} &bull; {{ $cf->step_count }} stops</span>
                                </div>
                            </div>
                            <div class="m-item-right"><i class="fa-solid fa-chevron-right m-item-arrow"></i></div>
                        </div>
                    @empty
                        <div class="m-empty-state">
                            <i class="fa-solid fa-folder-open" style="font-size: 24px;"></i>
                            <span>No custom flows recorded.</span>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- COLUMN 3: CUSTOM CONFIGURATION --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-sliders" style="color: #64748b;"></i> 3. Custom Settings & RDP</span>
                </div>

                <div class="m-inspector">
                    @if($selectedCustom)
                        <div class="m-field-group">
                            <label class="m-field-label">Flow Title</label>
                            <input type="text" class="m-field-input" wire:model="customFlowName">
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">Flow Scope Target</label>
                            <select class="m-field-select" wire:model="customFlowFor">
                                <option value="system">System Wide</option>
                                <option value="office">Office Specific</option>
                                <option value="user">User Specific</option>
                            </select>
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">Flow Purpose</label>
                            <select class="m-field-select" wire:model="customFlowUse">
                                <option value="none">General / None</option>
                                <option value="internal">Internal Workflow</option>
                                <option value="external">External / Inter-Agency</option>
                                <option value="issuances">Issuances</option>
                                <option value="application">Applications</option>
                                <option value="others">Others</option>
                            </select>
                        </div>

                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
                            <span style="font-size: 12px; font-weight: 600; color: #1e293b;">Active Status</span>
                            <label class="switch">
                                <input type="checkbox" wire:model.live="customIsActive">
                                <span class="slider"></span>
                            </label>
                        </div>

                        {{-- RDP RECORD SERIES LINK --}}
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #4338ca;">
                                    <i class="fa-solid fa-box-archive"></i> Linked Record Series
                                </span>
                                @if($customContinRdpId)
                                    <button type="button" wire:click="clearCustomContinRdpSeries" style="background: none; border: none; font-size: 10px; color: #ef4444; cursor: pointer; font-weight: 600;">Disconnect</button>
                                @endif
                            </div>

                            @if($customContinRdpId)
                                <div style="display: flex; align-items: center; gap: 8px; padding: 6px 10px; background: #e0e7ff; border-radius: 6px; border: 1px solid #c7d2fe;">
                                    <i class="fa-solid fa-link" style="color: #4338ca; font-size: 11px;"></i>
                                    <span style="font-size: 11.5px; font-weight: 600; color: #3730a3;">
                                        {{ $this->continRdpSelectedLabel($customContinRdpId, $customContinRdpSearch) }}
                                    </span>
                                </div>
                            @else
                                <div style="position: relative;">
                                    <input type="text" class="m-field-input" placeholder="Search series title or #..." wire:model.live.debounce.150ms="customContinRdpSearch" wire:focus="$set('showCustomRdpDropdown', true)">
                                    @if($showCustomRdpDropdown && strlen($customContinRdpSearch) > 0)
                                        <div class="m-dropdown-results">
                                            @foreach($this->continRdpChoices($customContinRdpSearch, $customContinRdpTypeFilter) as $series)
                                                <div class="m-dropdown-row" wire:click="selectCustomContinRdpSeries({{ $series->id }})">
                                                    <span>#{{ $series->item_number }} &bull; {{ $series->series_title }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <div style="margin-top: auto; padding-top: 14px; border-top: 1px solid #f1f5f9; display: flex; flex-direction: column; gap: 8px;">
                            <button type="button" wire:click="saveCustomFlow" style="width: 100%; background: #0284c7; color: #ffffff; border: none; padding: 8px 14px; border-radius: 6px; font-size: 12.5px; font-weight: 700; cursor: pointer;">
                                <i class="fa-solid fa-floppy-disk"></i> Save Custom Flow
                            </button>
                            @if($selectedCustom !== 'new')
                                <button type="button" wire:click="deleteCustomFlow" style="width: 100%; background: #fff1f2; color: #e11d48; border: 1px solid #ffe4e6; padding: 7px 14px; border-radius: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer;">
                                    <i class="fa-solid fa-trash-can"></i> Deactivate Custom Flow
                                </button>
                            @endif
                        </div>
                    @else
                        <div class="m-empty-state" style="padding-top: 60px;">
                            <i class="fa-solid fa-sliders" style="font-size: 28px;"></i>
                            <span>Select a custom flow to configure</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- COLUMN 4: CUSTOM ROUTING PIPELINE --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-route" style="color: #64748b;"></i> 4. Custom Pipeline Stops</span>
                    @if($selectedCustom)
                        <span class="m-item-badge">{{ count($customFlowOffices) }} Stops</span>
                    @endif
                </div>

                <div class="m-inspector" style="background: #f8fafc;">
                    @if($selectedCustom)
                        <div class="m-pipe-tabs">
                            <button type="button" class="m-pipe-tab {{ $customPipelineTab === 'sequence' ? 'active' : '' }}" wire:click="$set('customPipelineTab', 'sequence')">
                                <i class="fa-solid fa-arrow-down-1-9"></i> Stops ({{ count($customFlowOffices) }})
                            </button>
                            <button type="button" class="m-pipe-tab {{ $customPipelineTab === 'cf' ? 'active' : '' }}" wire:click="$set('customPipelineTab', 'cf')">
                                <i class="fa-solid fa-copy"></i> CF ({{ count($customCfOffices) }})
                            </button>
                        </div>

                        @if($customPipelineTab === 'sequence')
                            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 10px;">
                                <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px; display: block;">
                                    <i class="fa-solid fa-plus-circle" style="color: #0284c7;"></i> Append Stop
                                </label>
                                <div style="position: relative;">
                                    <input type="text" class="m-field-input" placeholder="Type office code or name..." wire:model.live.debounce.150ms="customOfficeSearch" wire:focus="$set('showCustomOfficeDropdown', true)">
                                    @if($showCustomOfficeDropdown && strlen($customOfficeSearch) > 0)
                                        <div class="m-dropdown-results">
                                            @foreach($activeOffices as $o)
                                                @if(stripos($o->office_name, $customOfficeSearch) !== false || stripos($o->office_code, $customOfficeSearch) !== false)
                                                    <div class="m-dropdown-row" wire:click="selectCustomOfficeForAppend('{{ $o->office_code }}', '{{ addslashes($o->office_name) }}')">
                                                        <span>{{ $o->office_name }}</span>
                                                        <span style="font-family: monospace; font-size: 10px; color: #0284c7;">{{ $o->office_code }}</span>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div style="flex: 1; overflow-y: auto;">
                                @foreach($customFlowOffices as $cRank => $cCode)
                                    @php
                                        $isCustomDraggable = ($cRank > 0 && ($cRank < count($customFlowOffices) - 1 || $cCode !== 'ORIGIN'));
                                    @endphp
                                    <div class="step-item {{ $isCustomDraggable ? 'draggable-step' : '' }} {{ $cCode === 'ORIGIN' ? 'step-origin' : '' }}"
                                         @if($isCustomDraggable)
                                             draggable="true"
                                             ondragstart="event.dataTransfer.setData('text/step-index', '{{ $cRank }}'); event.dataTransfer.effectAllowed = 'move'; this.classList.add('step-dragging');"
                                             ondragend="this.classList.remove('step-dragging');"
                                             ondragover="event.preventDefault(); event.dataTransfer.dropEffect = 'move'; this.classList.add('step-drop-hover');"
                                             ondragleave="this.classList.remove('step-drop-hover');"
                                             ondrop="event.preventDefault(); this.classList.remove('step-drop-hover'); const fromIdx = event.dataTransfer.getData('text/step-index'); if(fromIdx !== '' && parseInt(fromIdx) !== {{ $cRank }}) { window.dtsReorderCustomStep(fromIdx, {{ $cRank }}, this); }"
                                         @endif>
                                        <div style="display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1;">
                                            @if($isCustomDraggable)
                                                <i class="fa-solid fa-grip-vertical" style="color: #94a3b8; font-size: 11px; cursor: pointer;" title="Drag up or down to reorder stop"></i>
                                            @else
                                                <span style="width: 11px;"></span>
                                            @endif
                                            <div class="step-badge {{ $cCode === 'ORIGIN' ? 'step-origin' : '' }}">{{ $cRank + 1 }}</div>
                                            <div style="min-width: 0;">
                                                <span style="font-size: 12px; font-weight: 700; color: #1e293b; display: block;">
                                                    {{ $cCode === 'ORIGIN' ? 'Originating Office' : ($officeLookup[$cCode] ?? $cCode) }}
                                                </span>
                                                <span style="font-family: monospace; font-size: 10px; color: #64748b;">{{ $cCode }}</span>
                                            </div>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 4px;">
                                            @if($cRank > 1)
                                                <button type="button" class="step-btn" wire:click="moveCustomOfficeUp({{ $cRank }})"><i class="fa-solid fa-arrow-up"></i></button>
                                            @endif
                                            @if($cRank > 0 && $cRank < count($customFlowOffices) - 1)
                                                <button type="button" class="step-btn" wire:click="moveCustomOfficeDown({{ $cRank }})"><i class="fa-solid fa-arrow-down"></i></button>
                                            @endif
                                            @if($cRank > 0)
                                                <button type="button" class="step-btn step-btn-remove" wire:click="removeCustomOffice({{ $cRank }})"><i class="fa-solid fa-xmark"></i></button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 10px;">
                                <div style="position: relative;">
                                    <input type="text" class="m-field-input" placeholder="Search office for copy furnished..." wire:model.live.debounce.150ms="customCfSearch" wire:focus="$set('showCustomCfDropdown', true)">
                                    @if($showCustomCfDropdown && strlen($customCfSearch) > 0)
                                        <div class="m-dropdown-results">
                                            @foreach($activeOffices as $o)
                                                @if(stripos($o->office_name, $customCfSearch) !== false || stripos($o->office_code, $customCfSearch) !== false)
                                                    <div class="m-dropdown-row" wire:click="selectCustomCfOfficeForAppend('{{ $o->office_code }}', '{{ addslashes($o->office_name) }}')">
                                                        <span>{{ $o->office_name }}</span>
                                                        <span style="font-family: monospace; font-size: 10px; color: #0284c7;">{{ $o->office_code }}</span>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div style="flex: 1; overflow-y: auto;">
                                @forelse($customCfOffices as $ccIdx => $ccCode)
                                    <div class="step-item">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <i class="fa-solid fa-file-invoice" style="color: #64748b; font-size: 12px;"></i>
                                            <div>
                                                <span style="font-size: 12px; font-weight: 600;">{{ $officeLookup[$ccCode] ?? $ccCode }}</span>
                                                <span style="font-family: monospace; font-size: 10px; color: #64748b; display: block;">{{ $ccCode }}</span>
                                            </div>
                                        </div>
                                        <button type="button" class="step-btn step-btn-remove" wire:click="removeCustomCfOffice({{ $ccIdx }})">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </div>
                                @empty
                                    <div class="m-empty-state">
                                        <i class="fa-solid fa-copy" style="font-size: 20px;"></i>
                                        <span>No Copy Furnished offices assigned.</span>
                                    </div>
                                @endforelse
                            </div>
                        @endif
                    @else
                        <div class="m-empty-state" style="padding-top: 60px;">
                            <i class="fa-solid fa-bezier-curve" style="font-size: 28px;"></i>
                            <span>Select a custom flow from Column 2</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="miller-path-bar">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-bezier-curve" style="color: #64748b;"></i>
                <span>Custom Flows</span>
                @if($selectedCustom && $selectedCustom !== 'new')
                    <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #94a3b8;"></i>
                    <span style="color: #0284c7; font-weight: 700;">{{ $customFlowName }}</span>
                    <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #94a3b8;"></i>
                    <span>{{ count($customFlowOffices) }} Stops</span>
                @endif
            </div>

            @if($selectedCustom)
                <button type="button" wire:click="saveCustomFlow" style="background: none; border: none; color: #0284c7; font-size: 11.5px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-floppy-disk"></i> Quick Save
                </button>
            @endif
        </div>
    @endif
</div>

<script>
    window.dtsGetLivewire = function(el) {
        try {
            const root = el ? el.closest('[wire\\:id]') : document.querySelector('.miller-container[wire\\:id]');
            if (root && window.Livewire) {
                return window.Livewire.find(root.getAttribute('wire:id'));
            }
        } catch (e) {
            console.error('dtsGetLivewire error:', e);
        }
        return null;
    };

    window.dtsReorderStep = function(fromIdx, toIdx, el) {
        const lw = window.dtsGetLivewire(el);
        if (lw) {
            lw.call('reorderStep', parseInt(fromIdx), parseInt(toIdx));
        }
    };

    window.dtsReorderCustomStep = function(fromIdx, toIdx, el) {
        const lw = window.dtsGetLivewire(el);
        if (lw) {
            lw.call('reorderCustomStep', parseInt(fromIdx), parseInt(toIdx));
        }
    };

    window.dtsChangePurpose = function(flowId, purpose, el) {
        const lw = window.dtsGetLivewire(el);
        if (lw) {
            lw.call('changeFlowPurpose', parseInt(flowId), purpose);
        }
    };

    window.dtsChangeCustomPurpose = function(flowId, purpose, el) {
        const lw = window.dtsGetLivewire(el);
        if (lw) {
            lw.call('changeCustomFlowPurpose', parseInt(flowId), purpose);
        }
    };
</script>
