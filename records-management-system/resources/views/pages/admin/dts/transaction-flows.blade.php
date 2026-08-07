<?php
/**
 * Admin Console - DTS Transaction Flows Volt Component
 * 
 * Provides a tabbed interface for managing predefined routing flows (Tab 1)
 * and auditing dynamically generated custom office transaction flows (Tab 2).
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
    }

    // ---- PREDEFINED FLOW EDITOR PROPERTIES ----
    /** @var string Selected action/subsystem option (empty, 'new', or numeric ID) */
    public string $selectedPredefined = '';
    public string $predefinedViewMode = 'table'; // 'table' or 'grid'

    public string $flowName = '';
    public string $flowCode = '';
    public bool $isActive = true;
    public string $flowUse = 'none';
    public array $flowOffices = []; // list of office codes in sequence
    public string $selectedOffice = ''; // selected office from dropdown to add
    public string $officeSearch = ''; // text query for searching offices to add
    public $flowFile; // file upload property

    // ---- BULK SELECTION ----
    public array $selectedFlowIds = [];

    // ---- COPY FURNISHED EDITOR PROPERTIES ----
    public array $cfOffices = []; // list of office codes for predefined copy furnished
    public string $cfSearch = ''; // text query for searching copy furnished offices to add
    public string $selectedCfOffice = ''; // selected office from dropdown for copy furnished

    // ---- CUSTOM FLOW EDITOR PROPERTIES ----
    public string $selectedCustom = '';
    public string $customViewMode = 'table';
    public string $customFlowName = '';
    public string $customFlowCode = '';
    public bool $customIsActive = true;
    public string $customFlowUse = 'none';
    public string $customFlowFor = 'system'; // 'system', 'office', or 'user'
    public array $customFlowOffices = [];
    public string $customSelectedOffice = '';
    public string $customOfficeSearch = '';
    public array $customCfOffices = [];
    public string $customCfSearch = '';
    public string $customSelectedCfOffice = '';
    public array $selectedCustomFlowIds = [];
    public string $customPurposeFilter = 'all';

    // ---- SEARCH FOR CUSTOM FLOWS ----
    public string $searchCustom = '';

    // ---- SEARCH FOR PREDEFINED FLOWS ----
    public string $searchPredefined = '';
    public string $predefinedPurposeFilter = 'all';

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
        $this->flowFile = null;
        $this->selectedFlowIds = [];
        $this->clearMessages();
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
    }

    public function selectCfOfficeForAppend(string $code, string $name): void
    {
        $this->selectedCfOffice = $code;
        $this->cfSearch = "$name ($code)";
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
            return;
        }

        $flow = \DB::table('dts_transaction_flow')->where('id', $value)->first();
        if ($flow) {
            $this->customFlowName = $flow->flow_name;
            $this->customFlowCode = $flow->flow_code;
            $this->customIsActive = (bool) $flow->is_active;
            $this->customFlowUse = $flow->flow_use ?? 'none';
            $this->customFlowFor = $flow->flow_for ?? 'system';

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
        }
    }

    public function selectCustomOfficeForAppend(string $code, string $name): void
    {
        $this->customSelectedOffice = $code;
        $this->customOfficeSearch = "$name ($code)";
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
            \DB::table('dts_transaction_flow')
                ->whereIn('id', $this->selectedCustomFlowIds)
                ->update(['is_active' => false]);

            $count = count($this->selectedCustomFlowIds);
            $this->successMessage = "Successfully deactivated {$count} custom flow(s).";
            $this->selectedCustomFlowIds = [];
            $this->selectedCustom = '';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to bulk deactivate custom flows: ' . $e->getMessage();
        }
    }

    /**
     * Triggered when selected predefined flow option changes in dropdown.
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
            return;
        }

        $flow = \DB::table('dts_transaction_flow')->where('id', $value)->first();
        if ($flow) {
            $this->flowName = $flow->flow_name;
            $this->flowCode = $flow->flow_code;
            $this->isActive = (bool) $flow->is_active;
            $this->flowUse = $flow->flow_use ?? 'none';

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

    /**
     * Move an office up in sequence hierarchy.
     */
    public function moveOfficeUp(int $index): void
    {
        if ($index <= 1 || !isset($this->flowOffices[$index])) return;

        $temp = $this->flowOffices[$index - 1];
        $this->flowOffices[$index - 1] = $this->flowOffices[$index];
        $this->flowOffices[$index] = $temp;
    }

    /**
     * Move an office down in sequence hierarchy.
     */
    public function moveOfficeDown(int $index): void
    {
        if ($index === 0 || $index >= count($this->flowOffices) - 1 || !isset($this->flowOffices[$index])) return;

        $temp = $this->flowOffices[$index + 1];
        $this->flowOffices[$index + 1] = $this->flowOffices[$index];
        $this->flowOffices[$index] = $temp;
    }

    /**
     * Remove office from current path.
     */
    public function removeOffice(int $index): void
    {
        if ($index === 0 || !isset($this->flowOffices[$index])) return;

        array_splice($this->flowOffices, $index, 1);
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
            // Check if a deactivated flow with the same name or code exists
            $deactivatedByCode = \DB::table('dts_transaction_flow')
                ->where('flow_code', strtoupper(trim($this->flowCode)))
                ->where('is_active', false)
                ->first();

            $deactivatedByName = \DB::table('dts_transaction_flow')
                ->where('flow_name', trim($this->flowName))
                ->where('is_active', false)
                ->first();

            // For uniqueness validation, exclude inactive records
            $nameExcludeId = $deactivatedByName ? $deactivatedByName->id : null;
            $codeExcludeId = $deactivatedByCode ? $deactivatedByCode->id : null;

            $nameRule = $nameExcludeId
                ? 'required|string|max:255|unique:dts_transaction_flow,flow_name,' . $nameExcludeId . ',id'
                : 'required|string|max:255|unique:dts_transaction_flow,flow_name';

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
                'flowName' => 'required|string|max:255|unique:dts_transaction_flow,flow_name,' . $this->selectedPredefined . ',id',
                'flowUse' => 'required|string|in:internal,external,issuances,application,others,none',
            ]);
        }

        // 2. Perform DB operations
        try {
            \DB::transaction(function () {
                $flowId = null;

                if ($this->selectedPredefined === 'new') {
                    // Check if a deactivated flow with the same code exists — reactivate it
                    $existingFlow = \DB::table('dts_transaction_flow')
                        ->where('flow_code', strtoupper(trim($this->flowCode)))
                        ->where('is_active', false)
                        ->first();

                    if ($existingFlow) {
                        $flowId = $existingFlow->id;

                        // Reactivate and update the deactivated flow
                        \DB::table('dts_transaction_flow')->where('id', $flowId)->update([
                            'flow_name' => trim($this->flowName),
                            'is_active' => true,
                            'flow_use' => $this->flowUse,
                            'added_by' => auth()->id() ?? 1,
                            'date_added' => now(),
                        ]);

                        \DB::table('admin_logs')->insert([
                            'changes' => "Reactivated previously soft-deleted predefined transaction flow: " . trim($this->flowName) . " (" . strtoupper(trim($this->flowCode)) . ")",
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now(),
                        ]);

                        $this->successMessage = 'Previously deactivated flow reactivated and updated successfully!';
                    } else {
                        $maxId = \DB::table('dts_transaction_flow')->max('id') ?? 0;
                        $flowId = $maxId + 1;

                        // Insert flow
                        \DB::table('dts_transaction_flow')->insert([
                            'id' => $flowId,
                            'flow_name' => trim($this->flowName),
                            'flow_code' => strtoupper(trim($this->flowCode)),
                            'is_active' => $this->isActive,
                            'flow_use' => $this->flowUse,
                            'flow_for' => 'system',
                            'added_by' => auth()->id() ?? 1,
                            'date_added' => now(),
                        ]);

                        // Insert admin log
                        \DB::table('admin_logs')->insert([
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

                    // Update flow details
                    \DB::table('dts_transaction_flow')->where('id', $flowId)->update([
                        'flow_name' => trim($this->flowName),
                        'is_active' => $this->isActive,
                        'flow_use' => $this->flowUse,
                    ]);

                    // Insert admin log
                    \DB::table('admin_logs')->insert([
                        'changes' => "Updated predefined transaction flow: {$flow->flow_name}",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now(),
                    ]);

                    $this->successMessage = 'Predefined flow configuration updated!';
                }

                // Update Sequence List: Clear and insert sequence path
                \DB::table('dts_sequence_list')->where('control_id', $flowId)->delete();
                foreach ($this->flowOffices as $rank => $officeCode) {
                    \DB::table('dts_sequence_list')->insert([
                        'control_id' => $flowId,
                        'sequence_ranking' => $rank + 1,
                        'office_code' => $officeCode,
                    ]);
                }

                // Update Predefined Copy Furnished list
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
            });

            $msg = $this->successMessage;
            $this->resetForm();
            $this->successMessage = $msg;
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save flow: ' . $e->getMessage();
        }
    }

    /**
     * Soft-delete (deactivate) a predefined transaction flow for transparency.
     */
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

                if (!$flow) {
                    throw new \Exception('Predefined flow not found.');
                }

                // Soft-delete: deactivate the flow
                \DB::table('dts_transaction_flow')->where('id', $flowId)->update([
                    'is_active' => false,
                ]);

                // Audit log
                \DB::table('admin_logs')->insert([
                    'changes' => "Soft-deleted predefined transaction flow (Deactivated for transparency): {$flow->flow_name} ({$flow->flow_code})",
                    'admin_id' => auth()->id(),
                    'what_system' => 3, // Admin Console
                    'when_changes' => now(),
                ]);
            });

            $this->resetForm();
            $this->successMessage = 'Transaction flow soft-deleted (deactivated) successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete flow: ' . $e->getMessage();
        }
    }

    /**
     * Toggle a flow ID in the bulk selection array.
     */
    public function toggleFlowSelection(int $id): void
    {
        if (in_array($id, $this->selectedFlowIds)) {
            $this->selectedFlowIds = array_values(array_diff($this->selectedFlowIds, [$id]));
        } else {
            $this->selectedFlowIds[] = $id;
        }
    }

    /**
     * Toggle select/deselect all visible predefined flows.
     */
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
                $q->where('flow_name', 'like', $searchVal)
                  ->orWhere('flow_code', 'like', $searchVal);
            });
        }

        $visibleIds = $query->pluck('id')->toArray();

        if (count($this->selectedFlowIds) === count($visibleIds) && !array_diff($visibleIds, $this->selectedFlowIds)) {
            $this->selectedFlowIds = [];
        } else {
            $this->selectedFlowIds = $visibleIds;
        }
    }

    /**
     * Bulk soft-delete (deactivate) all selected predefined flows.
     */
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

                if ($flows->isEmpty()) {
                    throw new \Exception('No valid flows found to delete.');
                }

                $names = $flows->pluck('flow_name')->implode(', ');

                \DB::table('dts_transaction_flow')
                    ->whereIn('id', $this->selectedFlowIds)
                    ->update(['is_active' => false]);

                \DB::table('admin_logs')->insert([
                    'changes' => "Bulk soft-deleted " . $flows->count() . " predefined transaction flow(s) (Deactivated for transparency): {$names}",
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
     * Parse the uploaded .txt file and populate the flow creation form.
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

            // Normalize line endings and split
            $rawLines = preg_split('/\r\n|\r|\n/', $content);
            
            // Filter out blank lines and comment lines starting with #
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

            // Loop over non-empty lines using a dynamic index pointer
            $i = 0;
            while ($i < count($lines)) {
                $nameLine = $lines[$i];

                if (!str_starts_with($nameLine['text'], '=')) {
                    throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\") must start with '=' to indicate the start of a flow name.");
                }

                // Look ahead to find all lines belonging to the current flow block
                // (i.e. until the next line starting with '=')
                $blockLines = [];
                $j = $i + 1;
                while ($j < count($lines) && !str_starts_with($lines[$j]['text'], '=')) {
                    $blockLines[] = $lines[$j];
                    $j++;
                }

                // We expect at least 2 lines (Code, Sequence) in blockLines
                if (count($blockLines) < 2) {
                    throw new \Exception("Incomplete flow definition starting at Line {$lines[$i]['num']}. Each flow must contain at least a name, code, and sequence.");
                }

                $codeLine = $blockLines[0];
                $seqLine = $blockLines[1];

                // 1. Verify semicolons
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

                // 2. Validate empty values and code format
                if (empty($flowName)) {
                    throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\"): Flow name cannot be empty.");
                }
                if (empty($flowCode)) {
                    throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\"): Flow code cannot be empty.");
                }
                if (!preg_match('/^[A-Z0-9_\-]+$/i', $flowCode)) {
                    throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\"): Flow code can only contain letters, numbers, dashes, and underscores.");
                }

                // 3. Check database existence and conflict matching
                $flowByCode = \DB::table('dts_transaction_flow')->where('flow_code', $flowCode)->first();
                $flowByName = \DB::table('dts_transaction_flow')->where('flow_name', $flowName)->first();

                if ($flowByCode || $flowByName) {
                    // Check if it is a perfect match (both name and code point to the same existing record)
                    if ($flowByCode && $flowByName && $flowByCode->id === $flowByName->id) {
                        // Perfect match: skip importing this flow since it already exists exactly as is
                        $i += 1 + count($blockLines);
                        continue;
                    }

                    // Otherwise, it's a conflict
                    if ($flowByCode && (!$flowByName || $flowByCode->flow_name !== $flowName)) {
                        throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\"): Conflict detected. The flow code '{$flowCode}' already exists in the database with a different name ('{$flowByCode->flow_name}').");
                    }
                    if ($flowByName && (!$flowByCode || $flowByName->flow_code !== $flowCode)) {
                        throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\"): Conflict detected. The flow name '{$flowName}' already exists in the database with a different code ('{$flowByName->flow_code}').");
                    }
                }

                // 4. Check uniqueness within the uploaded file itself
                if (isset($tempCodes[$flowCode])) {
                    if ($tempCodes[$flowCode] !== $flowName) {
                        throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\"): Duplicate flow code '{$flowCode}' found within the uploaded file with a different name.");
                    }
                    // Perfect file duplicate: skip
                    $i += 1 + count($blockLines);
                    continue;
                }
                if (isset($tempNames[$flowName])) {
                    if ($tempNames[$flowName] !== $flowCode) {
                        throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\"): Duplicate flow name '{$flowName}' found within the uploaded file with a different code.");
                    }
                    // Perfect file duplicate: skip
                    $i += 1 + count($blockLines);
                    continue;
                }
                $tempCodes[$flowCode] = $flowName;
                $tempNames[$flowName] = $flowCode;

                // 5. Parse sequence path
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

                    // Query by code or name
                    $office = \DB::table('office')
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

                // Prepend and append ORIGIN if not present
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

                // 6. Handle optional lines (Copy Furnished and Flow Use/Purpose)
                $cfOffices = [];
                $flowUse = 'none';

                for ($k = 2; $k < count($blockLines); $k++) {
                    $optLine = $blockLines[$k];

                    if (!str_ends_with($optLine['text'], ';')) {
                        throw new \Exception("Line {$optLine['num']} (\"{$optLine['text']}\") must end with a semicolon ';'.");
                    }

                    // Check if it's the Flow Use indicator (surrounded by brackets, case-insensitive, with abbreviation support)
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
                        // Otherwise, treat it as the Copy Furnished offices list
                        $cfText = trim(substr($optLine['text'], 0, -1));
                        if (!empty($cfText)) {
                            $cfNodes = explode(',', $cfText);
                            $cfNodes = array_filter(array_map('trim', $cfNodes));
                            
                            foreach ($cfNodes as $node) {
                                $office = \DB::table('office')
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
                    'offices' => $officesSequence,
                    'cf_offices' => $cfOffices,
                    'flow_use' => $flowUse,
                ];

                $i += 1 + count($blockLines);
            }

            // Save all flows to the database in a single transaction
            \DB::transaction(function () use ($extractedFlows) {
                foreach ($extractedFlows as $flowData) {
                    $maxId = \DB::table('dts_transaction_flow')->max('id') ?? 0;
                    $flowId = $maxId + 1;

                    // Insert flow record
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

                    // Insert sequence list
                    foreach ($flowData['offices'] as $rank => $officeCode) {
                        \DB::table('dts_sequence_list')->insert([
                            'control_id' => $flowId,
                            'sequence_ranking' => $rank + 1,
                            'office_code' => $officeCode,
                        ]);
                    }

                    // Save Predefined Copy Furnished if present
                    if (count($flowData['cf_offices']) > 0) {
                        // Clean up existing predefined copy furnished for this flow code to prevent duplicates
                        $existingCFs = \DB::table('dts_copy_filled_transaction')->where('control_num', $flowData['code'])->get();
                        foreach ($existingCFs as $existingCF) {
                            \DB::table('dts_copy_filled_to_office')->where('control_id', $existingCF->assign_offices_id)->delete();
                        }
                        \DB::table('dts_copy_filled_transaction')->where('control_num', $flowData['code'])->delete();

                        $assignOfficesId = (\DB::table('dts_copy_filled_transaction')->max('assign_offices_id') ?? 1000) + 1;
                        
                        \DB::table('dts_copy_filled_transaction')->insert([
                            'control_num' => $flowData['code'],
                            'total_office' => count($flowData['cf_offices']),
                            'assign_offices_id' => $assignOfficesId,
                            'data_created' => now(),
                            'date_modified' => now(),
                        ]);

                        foreach ($flowData['cf_offices'] as $cfOffice) {
                            \DB::table('dts_copy_filled_to_office')->insert([
                                'control_id' => $assignOfficesId,
                                'office_code' => $cfOffice,
                            ]);
                        }
                    }

                    // Insert admin log entry
                    \DB::table('admin_logs')->insert([
                        'changes' => "Imported predefined transaction flow via text file: {$flowData['name']} ({$flowData['code']})",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now(),
                    ]);
                }
            });

            $this->resetForm();
            $this->flowFile = null;

            $this->successMessage = "Successfully imported " . count($extractedFlows) . " predefined flow(s) from the file!";

        } catch (\Exception $e) {
            $this->errorMessage = 'Extraction failed: ' . $e->getMessage();
        }
    }

    /**
     * Dynamic computed data binder.
     */
    public function with(): array
    {
        // Fetch active offices for sequence selection
        $activeOffices = \DB::table('office')
            ->where('is_active', true)
            ->orderBy('office_name')
            ->get();

        // Query predefined flows dropdown/directory
        $predefinedQuery = \DB::table('dts_transaction_flow')
            ->where('flow_code', 'not like', 'FLOW-CUSTOM-%')
            ->where('is_active', true);

        if ($this->predefinedPurposeFilter !== 'all') {
            $predefinedQuery->where('flow_use', $this->predefinedPurposeFilter);
        }

        if ($this->searchPredefined !== '') {
            $searchVal = '%' . $this->searchPredefined . '%';
            $predefinedQuery->where(function ($q) use ($searchVal) {
                $q->where('flow_name', 'like', $searchVal)
                  ->orWhere('flow_code', 'like', $searchVal);
            });
        }

        $predefinedFlows = $predefinedQuery->orderBy('flow_name')
            ->paginate(20);

        // Query custom flows (Tab 2)
        $customFlows = collect();
        if ($this->activeTab === 'custom') {
            $customQuery = \DB::table('dts_transaction_flow')
                ->leftJoin('account_details', 'dts_transaction_flow.added_by', '=', 'account_details.account_id')
                ->leftJoin('office', 'account_details.office_id', '=', 'office.id')
                ->where('dts_transaction_flow.flow_code', 'like', 'FLOW-CUSTOM-%')
                ->whereNull('dts_transaction_flow.referenced_flow')
                ->where('dts_transaction_flow.flow_name', 'not like', 'Flow for %')
                ->select([
                    'dts_transaction_flow.id',
                    'dts_transaction_flow.flow_code',
                    'dts_transaction_flow.flow_name',
                    'dts_transaction_flow.flow_use',
                    'dts_transaction_flow.flow_for',
                    'dts_transaction_flow.is_active',
                    'dts_transaction_flow.added_by',
                    'dts_transaction_flow.date_added',
                    'account_details.first_name',
                    'account_details.last_name',
                    'office.office_name as owner_office_name',
                    'office.office_code as owner_office_code',
                ]);

            if ($this->searchCustom !== '') {
                $searchVal = '%' . $this->searchCustom . '%';
                $customQuery->where(function ($q) use ($searchVal) {
                    $q->where('dts_transaction_flow.flow_code', 'like', $searchVal)
                      ->orWhere('dts_transaction_flow.flow_name', 'like', $searchVal)
                      ->orWhere('account_details.first_name', 'like', $searchVal)
                      ->orWhere('account_details.last_name', 'like', $searchVal)
                      ->orWhere('office.office_name', 'like', $searchVal)
                      ->orWhere('office.office_code', 'like', $searchVal);
                });
            }

            if ($this->customPurposeFilter !== 'all') {
                $customQuery->where('dts_transaction_flow.flow_use', $this->customPurposeFilter);
            }

            $customFlows = $customQuery->orderBy('dts_transaction_flow.flow_name', 'asc')->paginate(20);
        }

        return [
            'activeOffices' => $activeOffices,
            'predefinedFlows' => $predefinedFlows,
            'customFlows' => $customFlows,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/activity_logs.css', 'resources/css/admin/subsystems.css', 'resources/css/admin/accounts_offices.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .tabs-header {
            display: flex;
            gap: 12px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 24px;
        }
        .tab-btn {
            background: none;
            border: none;
            padding: 12px 20px;
            font-size: 14.5px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            position: relative;
            outline: none;
            transition: all 0.2s ease;
        }
        .tab-btn.active {
            color: #003699;
        }
        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 3px;
            background: #003699;
            border-radius: 99px;
        }
        .activity-logs-container {
            padding: 12px 24px;
        }
        .sequence-editor-box {
            border: 1.5px dashed #cbd5e1;
            border-radius: 12px;
            padding: 16px;
            background-color: #f8fafc;
            margin-top: 10px;
        }
        .sequence-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .sequence-list-item:last-child {
            margin-bottom: 0;
        }
        .seq-index {
            font-weight: 700;
            color: #003699;
            margin-right: 8px;
        }
        .seq-name {
            font-weight: 500;
            color: #334155;
        }
        .seq-actions {
            display: flex;
            gap: 6px;
        }
        .btn-seq-nav {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
            padding: 5px 8px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.2s ease;
        }
        .btn-seq-nav:hover:not(:disabled) {
            background: #e2e8f0;
            color: #0f172a;
        }
        .btn-seq-remove {
            background: #fff1f2;
            color: #e11d48;
            border: 1px solid #ffe4e6;
            padding: 5px 8px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.2s ease;
        }
        .btn-seq-remove:hover {
            background: #ffe4e6;
        }
        .office-flow-path {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
        }
        .office-flow-node {
            background: #f1f5f9;
            color: #334155;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        .flow-separator {
            color: #94a3b8;
            font-size: 12px;
        }
        .suggestions-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            margin-top: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .suggestion-item {
            padding: 10px 14px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            transition: background 0.15s ease;
            border-bottom: 1px solid #f1f5f9;
        }
        .suggestion-item:last-child {
            border-bottom: none;
        }
        .suggestion-item:hover {
            background-color: #f1f5f9;
        }

        /* Flows Pagination Bar Wrapper */
        .flows-pagination-bar {
            padding-top: 14px;
            margin-top: 10px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
        }
    </style>
@endpush

<div class="activity-logs-container">

    <!-- Tab Selection -->
    <div class="tabs-header" style="margin-bottom: 20px;">
        <button type="button" class="tab-btn {{ $activeTab === 'predefined' ? 'active' : '' }}" wire:click="$set('activeTab', 'predefined')">
            <i class="fa-solid fa-gears" style="margin-right: 6px;"></i> Predefined Flow Manager
        </button>
        <button type="button" class="tab-btn {{ $activeTab === 'custom' ? 'active' : '' }}" wire:click="$set('activeTab', 'custom')">
            <i class="fa-solid fa-bezier-curve" style="margin-right: 6px;"></i> Custom Flows Directory
        </button>
    </div>

    <!-- Tab 1: Predefined Flow Manager -->
    @if($activeTab === 'predefined')
        <div class="admin-offices-container {{ !$selectedPredefined ? 'no-selection' : 'has-selection' }}">
            <!-- Left Pane: Predefined Flows Directory -->
            <div class="directory-panel">
                <div class="directory-header-row">
                    <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">Predefined Flows</span>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn-create-new" wire:click="startCreate">
                            <i class="fa-solid fa-plus"></i> New Flow
                        </button>
                        <button type="button" class="btn-create-new" style="background-color: #0284c7; border-color: #0284c7;" wire:click="startImport">
                            <i class="fa-solid fa-file-import"></i> Import File
                        </button>
                    </div>
                </div>

                <div class="search-box-wrapper" style="flex: none; position: relative; width: 100%;">
                    <i class="fa-solid fa-magnifying-glass search-icon" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; margin: 0; line-height: 1;"></i>
                    <input type="text" class="search-box" placeholder="Search flows..." wire:model.live="searchPredefined">
                </div>

                @if(count($selectedFlowIds) > 0)
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 600; color: #be123c;">{{ count($selectedFlowIds) }} selected</span>
                        <button type="button" wire:click="bulkDeleteFlows" wire:confirm="Are you sure you want to deactivate {{ count($selectedFlowIds) }} flow(s)? They will be soft-deleted (hidden) but retained for transparency." style="background: #e11d48; color: #fff; border: none; padding: 5px 12px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;">
                            <i class="fa-solid fa-trash-can" style="margin-right: 4px;"></i> Delete Selected
                        </button>
                    </div>
                @endif

                <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 12px; border-bottom: 1px solid #e2e8f0; gap: 8px;">
                    @if($predefinedFlows->count() > 0)
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" wire:click="toggleAllFlows" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedFlowIds) > 0 && count($selectedFlowIds) === $predefinedFlows->count() ? 'checked' : '' }}>
                            <span style="font-size: 12px; color: #64748b; font-weight: 500;">Select All</span>
                        </div>
                    @else
                        <div></div>
                    @endif
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select wire:model.live="predefinedPurposeFilter" style="padding: 5px 10px; border-radius: 6px; border: 1.5px solid #e2e8f0; outline: none; font-size: 11.5px; font-family: 'Inter', sans-serif; color: #64748b; cursor: pointer; transition: all 0.2s ease; background: #fff; font-weight: 500;">
                            <option value="all">All Purposes</option>
                            <option value="internal">Internal</option>
                            <option value="external">External</option>
                            <option value="issuances">Issuances</option>
                            <option value="application">Application</option>
                            <option value="others">Others</option>
                            <option value="none">None</option>
                        </select>

                        <!-- Layout View Mode Toggle -->
                        <div class="view-mode-toggle" style="display: flex; gap: 2px; background: #f1f5f9; padding: 2px; border-radius: 6px; border: 1px solid #cbd5e1;">
                            <button type="button" wire:click="$set('predefinedViewMode', 'grid')" title="Cards Grid Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $predefinedViewMode === 'grid' ? '#ffffff' : 'transparent' }}; color: {{ $predefinedViewMode === 'grid' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $predefinedViewMode === 'grid' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                                <i class="fa-solid fa-border-all"></i> Cards
                            </button>
                            <button type="button" wire:click="$set('predefinedViewMode', 'table')" title="Table Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $predefinedViewMode === 'table' ? '#ffffff' : 'transparent' }}; color: {{ $predefinedViewMode === 'table' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $predefinedViewMode === 'table' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                                <i class="fa-solid fa-table-list"></i> Table
                            </button>
                        </div>
                    </div>
                </div>

                @if($predefinedViewMode === 'table')
                    <!-- Table Layout View -->
                    <div style="overflow-x: auto; max-height: calc(100vh - 280px); overflow-y: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-family: 'Inter', sans-serif; font-size: 12.5px;">
                            <thead>
                                <tr style="background: #f8fafc; color: #475569; text-align: left; position: sticky; top: 0; z-index: 10; font-weight: 600; font-size: 11.5px; border-bottom: 1.5px solid #cbd5e1;">
                                    <th style="padding: 8px 10px; width: 36px;"></th>
                                    <th style="padding: 8px 10px;">Code</th>
                                    <th style="padding: 8px 10px;">Flow Name</th>
                                    <th style="padding: 8px 10px; text-align: center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($predefinedFlows as $flow)
                                    @php
                                        $isSelected = $selectedPredefined === (string) $flow->id;
                                    @endphp
                                    <tr class="flow-tbl-row {{ $isSelected ? 'selected-row' : '' }}" 
                                        wire:key="flow-tbl-{{ $flow->id }}" 
                                        wire:click="selectFlow({{ $flow->id }})"
                                        style="border-bottom: 1px solid #f1f5f9; cursor: pointer; background: {{ $isSelected ? '#eff6ff' : '#ffffff' }}; transition: background 0.12s ease;">
                                        <td style="padding: 8px 10px;" onclick="event.stopPropagation()">
                                            <input type="checkbox" wire:click.stop="toggleFlowSelection({{ $flow->id }})" {{ in_array($flow->id, $selectedFlowIds) ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #3b82f6;">
                                        </td>
                                        <td style="padding: 8px 10px; font-weight: 700; color: #003699; font-family: monospace; font-size: 12px;">{{ $flow->flow_code }}</td>
                                        <td style="padding: 8px 10px; font-weight: 600; color: #1e293b;">{{ $flow->flow_name }}</td>
                                        <td style="padding: 8px 10px; text-align: center;">
                                            @if($flow->is_active)
                                                <span style="padding: 2px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 600; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">Active</span>
                                            @else
                                                <span style="padding: 2px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 600; background: #fee2e2; color: #991b1b; border: 1px solid #fecdd3;">Inactive</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" style="text-align: center; color: #94a3b8; padding: 20px;">No predefined flows found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <!-- Cards Grid Layout View -->
                    <div class="offices-list">
                        @forelse($predefinedFlows as $flow)
                            @php
                                $isSelected = $selectedPredefined === (string) $flow->id;
                                $flowInitials = strtoupper(substr($flow->flow_code ?: '?', 0, 3));
                            @endphp
                            <div class="office-item-card {{ $isSelected ? 'active' : '' }}" wire:key="flow-item-{{ $flow->id }}" wire:click="selectFlow({{ $flow->id }})">
                                <input type="checkbox" wire:click.stop="toggleFlowSelection({{ $flow->id }})" {{ in_array($flow->id, $selectedFlowIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0;">
                                <div class="office-avatar-small">
                                    <span>{{ $flowInitials }}</span>
                                </div>
                                <div class="office-meta-info">
                                    <span class="office-display-name">{{ $flow->flow_name }}</span>
                                    <span class="office-display-code">Code: {{ $flow->flow_code }}</span>
                                    <span class="office-status-badge {{ $flow->is_active ? 'active-badge' : 'inactive-badge' }}">
                                        {{ $flow->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </div>
                            </div>
                        @empty
                            <div style="text-align: center; color: #94a3b8; font-size: 13.5px; padding: 24px 10px;">
                                No predefined flows found.
                            </div>
                        @endforelse
                    </div>
                @endif

                @if($predefinedFlows->hasPages())
                    <div class="flows-pagination-bar">
                        {{ $predefinedFlows->links('components.pagination') }}
                    </div>
                @endif
            </div>

            <!-- Right Pane: Flow Configuration details-panel -->
            @if($selectedPredefined)
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

                @if($selectedPredefined === 'import')
                        <!-- Header -->
                        <div class="details-header" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);">
                            <div class="details-header-avatar" style="background-color: rgba(255,255,255,0.15); color: #ffffff;">
                                <i class="fa-solid fa-file-import"></i>
                            </div>
                            <div class="details-header-info">
                                <h2 class="details-header-name">Import Predefined Flow</h2>
                                <span class="details-header-sub">Upload a text file to configure a standard routing pathway</span>
                            </div>
                        </div>

                        <!-- Body -->
                        <div class="details-body">
                            <div class="form-group">
                                <label class="form-label" for="flowFileInput" style="font-weight: 600; color: #334155; margin-bottom: 8px; display: block;">Select Predefined Flow Text File (.txt)</label>
                                <input type="file" 
                                       id="flowFileInput" 
                                       wire:model="flowFile" 
                                       accept=".txt" 
                                       style="padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; font-size: 13px; font-family: 'Inter', sans-serif;">
                                @error('flowFile') <span style="color:#ef4444; font-size:11px; margin-top:4px; display:block;">{{ $message }}</span> @enderror
                                <p style="font-size: 12.5px; color: #64748b; margin-top: 12px; line-height: 1.5; font-family: 'Inter', sans-serif;">
                                    <strong>Instructions:</strong> The uploaded file must be a plain text file (<code>.txt</code>). Every line must end with a semicolon (<code>;</code>):
                                    <br>• Line 1: <code>=&lt;flow name&gt;;</code> (must start with an equals sign <code>=</code>)
                                    <br>• Line 2: <code>&lt;flow code&gt;;</code>
                                    <br>• Line 3: <code>&lt;flow sequence&gt;;</code> (e.g. <code>[]-&gt;[H]-&gt;ICTU;</code> where <code>[]</code> is the Originated Office and <code>[H]</code> is the Cluster Head of that office)
                                    <br>• Line 4 (Optional): <code>&lt;copy furnished offices (comma-separated)&gt;;</code> (e.g. <code>Unit Head, HRMDU, RFOIU;</code>)
                                    <br>• Line 5 (Optional): <code>&lt;flow purpose/use&gt;;</code> (one of: <code>internal</code>, <code>external</code>, <code>issuances</code>, <code>application</code>, <code>others</code>, <code>none</code>), (use this like this e.g. <code>[ internal ];</code> or <code>[int];</code> for internal transactions)
                                    <br>• Lines starting with <code>#</code> are classified as comments and will be ignored.
                                </p>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="details-footer">
                            <button type="button" class="btn-cancel" wire:click="resetForm">Cancel</button>
                            <button type="button" class="btn-save" style="background-color: #0284c7; border-color: #0284c7;" wire:click="importFlow">
                                <i class="fa-solid fa-file-import"></i> Extract Flow Data
                            </button>
                        </div>
                    @else
                        <!-- Header -->
                        <div class="details-header">
                            <div class="details-header-avatar">
                                <i class="fa-solid fa-route"></i>
                            </div>
                            <div class="details-header-info">
                                <h2 class="details-header-name">
                                    {{ $selectedPredefined === 'new' ? 'Configure New Flow' : $flowName }}
                                </h2>
                                <span class="details-header-sub">
                                    {{ $selectedPredefined === 'new' ? 'Create a standard routing path template' : 'Review & adjust predefined routing path' }}
                                </span>
                            </div>
                        </div>

                        <!-- Body -->
                        <div class="details-body">
                            <form wire:submit.prevent="savePredefinedFlow" style="display: flex; flex-direction: column; gap: 20px;">
                                <!-- Flow Name -->
                                <div class="form-group">
                                    <span class="form-label">Flow Display Name</span>
                                    <input type="text" class="form-input" placeholder="e.g. Standard Clearance Routing" wire:model="flowName">
                                    @error('flowName') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                                </div>

                                <!-- Flow Code -->
                                <div class="form-group">
                                    <span class="form-label">Flow Unique Code</span>
                                    <input type="text" class="form-input" placeholder="e.g. FLOW-CLEARANCE" wire:model="flowCode" @if($selectedPredefined !== 'new') disabled @endif>
                                    @error('flowCode') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                                </div>

                                <!-- Active status switch -->
                                <div class="form-group">
                                    <div class="status-toggle-wrapper">
                                        <div class="status-toggle-label">
                                            <span class="status-toggle-title">Flow Active Status</span>
                                            <span class="status-toggle-desc">Toggle whether this predefined flow is visible for new transactions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="isActive">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Flow Purpose / Use -->
                                <div class="form-group">
                                    <span class="form-label">Flow Purpose / Module Use</span>
                                    <select class="form-input" wire:model="flowUse" style="background-color: #fff; cursor: pointer; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                                        <option value="none">No Specific Purpose (none)</option>
                                        <option value="internal">Internal Transactions</option>
                                        <option value="external">External Transactions</option>
                                        <option value="issuances">Issuances / Memos</option>
                                        <option value="application">Application Letters</option>
                                        <option value="others">Others</option>
                                    </select>
                                    @error('flowUse') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                                </div>

                                <!-- Sequence Builder -->
                                <div class="form-group">
                                    <span class="form-label">Office Sequence Path</span>
                                    
                                    <div style="display: flex; gap: 8px; align-items: center; margin-top: 6px; position: relative;">
                                        <div x-data="{ open: false }" @click.outside="open = false" style="position: relative; flex: 1;">
                                            <input type="text" 
                                                   class="form-input" 
                                                   placeholder="Search and select office to append..." 
                                                   wire:model.live="officeSearch"
                                                   @focus="open = true"
                                                   style="margin: 0; width: 100%; padding: 9px 14px;">
                                            
                                            <div x-show="open" class="suggestions-dropdown">
                                                @php
                                                    $filtered = $activeOffices->filter(function($off) {
                                                        if (empty($this->officeSearch)) return true;
                                                        return stripos($off->office_name, $this->officeSearch) !== false 
                                                            || stripos($off->office_code, $this->officeSearch) !== false;
                                                    });
                                                @endphp
                                                
                                                @forelse($filtered as $off)
                                                    <div class="suggestion-item" 
                                                         @click="open = false"
                                                         wire:click="selectOfficeForAppend('{{ $off->office_code }}', '{{ $off->office_name }}')">
                                                        <span style="font-weight: 500; color: #1e293b;">{{ $off->office_name }}</span>
                                                        <span style="color: #64748b; font-weight: 600;">{{ $off->office_code }}</span>
                                                    </div>
                                                @empty
                                                    <div style="padding: 10px 14px; color: #94a3b8; font-size: 13px; font-style: italic; text-align: center;">No offices found</div>
                                                @endforelse
                                            </div>
                                        </div>
                                        <button type="button" class="btn-save" style="padding: 10px 18px; display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0;" wire:click="addOfficeToPath">
                                            <i class="fa-solid fa-plus"></i> Add
                                        </button>
                                    </div>

                                    <div class="sequence-editor-box" style="margin-top: 14px;">
                                        @forelse($flowOffices as $index => $officeCode)
                                            @php
                                                $officeObj = $activeOffices->firstWhere('office_code', $officeCode);
                                                $displayName = ($officeCode === 'ORIGIN') ? 'Originated Office' : ($officeObj ? $officeObj->office_name : $officeCode);
                                            @endphp
                                            <div class="sequence-list-item" wire:key="seq-node-{{ $index }}">
                                                <div style="display: flex; align-items: center;">
                                                    <span class="seq-index">#{{ $index + 1 }}</span>
                                                    <span class="seq-name" @if($officeCode === 'ORIGIN') style="font-weight: 700; color: #0f172a;" @endif>{{ $displayName }} ({{ $officeCode }})</span>
                                                </div>
                                                <div class="seq-actions">
                                                    @if($index > 0)
                                                        <button type="button" class="btn-seq-nav" @if($index === 1) disabled @endif wire:click="moveOfficeUp({{ $index }})" title="Move Up">
                                                            <i class="fa-solid fa-chevron-up"></i>
                                                        </button>
                                                        <button type="button" class="btn-seq-nav" @if($index === count($flowOffices) - 1) disabled @endif wire:click="moveOfficeDown({{ $index }})" title="Move Down">
                                                            <i class="fa-solid fa-chevron-down"></i>
                                                        </button>
                                                        <button type="button" class="btn-seq-remove" wire:click="removeOffice({{ $index }})" title="Remove">
                                                            <i class="fa-solid fa-trash-can"></i>
                                                        </button>
                                                    @else
                                                        <span style="font-size: 11px; font-weight: 600; color: #64748b; background: #f1f5f9; padding: 4px 8px; border-radius: 6px;">
                                                            <i class="fa-solid fa-lock" style="margin-right: 4px;"></i> Fixed First Step
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        @empty
                                            <div style="text-align: center; color: #94a3b8; font-size: 13px; padding: 16px;">
                                                Routing path is empty. Add offices above.
                                            </div>
                                        @endforelse
                                    </div>
                                </div>

                                <!-- Predefined Copy Furnished Configuration -->
                                <div class="form-group" style="border-top: 1.5px dashed #e2e8f0; padding-top: 20px; margin-top: 10px;">
                                    <span class="form-label" style="font-size: 13.5px; font-weight: 700; color: #1e3a8a; display: flex; align-items: center; gap: 6px;">
                                        <i class="fa-solid fa-copy"></i> Predefined Copy Furnished (CF)
                                    </span>
                                    <span style="font-size: 11.5px; color: #64748b; margin-bottom: 12px; display: block; line-height: 1.4;">
                                        Specify offices that should automatically receive copy-furnished document notifications when a document is routed using this flow.
                                    </span>
                                    
                                    <div style="display: flex; gap: 8px; align-items: center; position: relative;">
                                        <div x-data="{ open: false }" @click.outside="open = false" style="position: relative; flex: 1;">
                                            <input type="text" 
                                                   class="form-input" 
                                                   placeholder="Search and select office to append..." 
                                                   wire:model.live="cfSearch"
                                                   @focus="open = true"
                                                   style="margin: 0; width: 100%; padding: 9px 14px;">
                                            
                                            <div x-show="open" class="suggestions-dropdown" style="bottom: 100%; top: auto; margin-bottom: 4px; margin-top: 0;">
                                                @php
                                                    $filteredCf = $activeOffices->filter(function($off) {
                                                        if (empty($this->cfSearch)) return true;
                                                        return stripos($off->office_name, $this->cfSearch) !== false 
                                                            || stripos($off->office_code, $this->cfSearch) !== false;
                                                    });
                                                @endphp
                                                
                                                @forelse($filteredCf as $off)
                                                    <div class="suggestion-item" 
                                                         @click="open = false"
                                                         wire:click="selectCfOfficeForAppend('{{ $off->office_code }}', '{{ $off->office_name }}')">
                                                        <span style="font-weight: 500; color: #1e293b;">{{ $off->office_name }}</span>
                                                        <span style="color: #64748b; font-weight: 600;">{{ $off->office_code }}</span>
                                                    </div>
                                                @empty
                                                    <div style="padding: 10px 14px; color: #94a3b8; font-size: 13px; font-style: italic; text-align: center;">No offices found</div>
                                                @endforelse
                                            </div>
                                        </div>
                                        <button type="button" class="btn-save" style="padding: 10px 18px; display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0; background-color: #10b981; border-color: #10b981;" wire:click="addCfOfficeToPath">
                                            <i class="fa-solid fa-plus"></i> Add
                                        </button>
                                    </div>

                                    <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; min-height: 48px; box-sizing: border-box;">
                                        @forelse($cfOffices as $index => $officeCode)
                                            @php
                                                $officeObj = $activeOffices->firstWhere('office_code', $officeCode);
                                                $displayName = $officeObj ? $officeObj->office_name : $officeCode;
                                            @endphp
                                            <div style="display: inline-flex; align-items: center; gap: 6px; background: #e2e8f0; color: #1e293b; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; border: 1px solid #cbd5e1; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                                <span>{{ $displayName }} ({{ $officeCode }})</span>
                                                <button type="button" wire:click="removeCfOffice({{ $index }})" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 14px; font-weight: bold; line-height: 1; padding: 0; display: inline-flex; align-items: center; margin-left: 2px;">&times;</button>
                                            </div>
                                        @empty
                                            <div style="display: flex; align-items: center; justify-content: center; width: 100%; color: #94a3b8; font-style: italic; font-size: 12.5px; height: 24px;">
                                                No copy furnished offices added to this flow yet.
                                            </div>
                                        @endforelse
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Footer -->
                        <div class="details-footer">
                            @if($selectedPredefined !== 'new')
                                <button type="button" class="btn-delete" wire:click="deleteFlow" wire:confirm="Are you sure you want to deactivate this transaction flow? It will be soft-deleted (hidden) but retained for transparency." style="margin-right: auto;">
                                    <i class="fa-solid fa-trash-can"></i> Delete Flow
                                </button>
                            @endif
                            <button type="button" class="btn-cancel" wire:click="resetForm">Cancel</button>
                            <button type="button" class="btn-save" wire:click="savePredefinedFlow">
                                <i class="fa-solid fa-floppy-disk"></i> Save Configuration
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <!-- Tab 2: Custom Flow Manager -->
    @if($activeTab === 'custom')
        <div class="admin-offices-container {{ !$selectedCustom ? 'no-selection' : 'has-selection' }}">
            <!-- Left Pane: Custom Flows Directory -->
            <div class="directory-panel">
                <div class="directory-header-row">
                    <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">Custom Flows</span>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn-create-new" wire:click="startCreateCustom">
                            <i class="fa-solid fa-plus"></i> New Flow
                        </button>
                    </div>
                </div>

                <div class="search-box-wrapper" style="flex: none; position: relative; width: 100%;">
                    <i class="fa-solid fa-magnifying-glass search-icon" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; margin: 0; line-height: 1;"></i>
                    <input type="text" class="search-box" placeholder="Search custom flows..." wire:model.live="searchCustom">
                </div>

                @if(count($selectedCustomFlowIds) > 0)
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 600; color: #be123c;">{{ count($selectedCustomFlowIds) }} selected</span>
                        <button type="button" wire:click="bulkDeleteCustomFlows" wire:confirm="Are you sure you want to deactivate {{ count($selectedCustomFlowIds) }} flow(s)? They will be soft-deleted (hidden) but retained for transparency." style="background: #e11d48; color: #fff; border: none; padding: 5px 12px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;">
                            <i class="fa-solid fa-trash-can" style="margin-right: 4px;"></i> Delete Selected
                        </button>
                    </div>
                @endif

                <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 12px; border-bottom: 1px solid #e2e8f0; gap: 8px;">
                    @if($customFlows->count() > 0)
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" wire:click="toggleAllCustomFlows" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedCustomFlowIds) > 0 && count($selectedCustomFlowIds) === $customFlows->count() ? 'checked' : '' }}>
                            <span style="font-size: 12px; color: #64748b; font-weight: 500;">Select All</span>
                        </div>
                    @else
                        <div></div>
                    @endif
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <select wire:model.live="customPurposeFilter" style="padding: 5px 10px; border-radius: 6px; border: 1.5px solid #e2e8f0; outline: none; font-size: 11.5px; font-family: 'Inter', sans-serif; color: #64748b; cursor: pointer; transition: all 0.2s ease; background: #fff; font-weight: 500;">
                            <option value="all">All Purposes</option>
                            <option value="internal">Internal</option>
                            <option value="external">External</option>
                            <option value="issuances">Issuances</option>
                            <option value="application">Application</option>
                            <option value="others">Others</option>
                            <option value="none">None</option>
                        </select>

                        <!-- Layout View Mode Toggle -->
                        <div class="view-mode-toggle" style="display: flex; gap: 2px; background: #f1f5f9; padding: 2px; border-radius: 6px; border: 1px solid #cbd5e1;">
                            <button type="button" wire:click="$set('customViewMode', 'grid')" title="Cards Grid Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $customViewMode === 'grid' ? '#ffffff' : 'transparent' }}; color: {{ $customViewMode === 'grid' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $customViewMode === 'grid' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                                <i class="fa-solid fa-border-all"></i> Cards
                            </button>
                            <button type="button" wire:click="$set('customViewMode', 'table')" title="Table Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $customViewMode === 'table' ? '#ffffff' : 'transparent' }}; color: {{ $customViewMode === 'table' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $customViewMode === 'table' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                                <i class="fa-solid fa-table-list"></i> Table
                            </button>
                        </div>
                    </div>
                </div>

                @if($customViewMode === 'table')
                    <!-- Table Layout View -->
                    <div style="overflow-x: auto; max-height: calc(100vh - 280px); overflow-y: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-family: 'Inter', sans-serif; font-size: 12.5px;">
                            <thead>
                                <tr style="background: #f8fafc; color: #475569; text-align: left; position: sticky; top: 0; z-index: 10; font-weight: 600; font-size: 11.5px; border-bottom: 1.5px solid #cbd5e1;">
                                    <th style="padding: 8px 10px; width: 36px;"></th>
                                    <th style="padding: 8px 10px;">Code</th>
                                    <th style="padding: 8px 10px;">Flow Name</th>
                                    <th style="padding: 8px 10px;">Visibility</th>
                                    <th style="padding: 8px 10px;">Office Owner</th>
                                    <th style="padding: 8px 10px;">Created By</th>
                                    <th style="padding: 8px 10px;">Date Created</th>
                                    <th style="padding: 8px 10px; text-align: center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($customFlows as $flow)
                                    @php
                                        $isSelected = $selectedCustom === (string) $flow->id;
                                        $creatorName = trim(($flow->first_name ?? '') . ' ' . ($flow->last_name ?? '')) ?: 'System / Admin';
                                        $officeOwner = $flow->owner_office_name ? ($flow->owner_office_name . ' (' . $flow->owner_office_code . ')') : 'Global / Unassigned';
                                        $dateCreatedFormatted = $flow->date_added ? \Carbon\Carbon::parse($flow->date_added)->format('M d, Y') : '—';
                                    @endphp
                                    <tr class="flow-tbl-row {{ $isSelected ? 'selected-row' : '' }}" 
                                        wire:key="custom-flow-tbl-{{ $flow->id }}" 
                                        wire:click="selectCustomFlow({{ $flow->id }})"
                                        style="border-bottom: 1px solid #f1f5f9; cursor: pointer; background: {{ $isSelected ? '#eff6ff' : '#ffffff' }}; transition: background 0.12s ease;">
                                        <td style="padding: 8px 10px;" onclick="event.stopPropagation()">
                                            <input type="checkbox" wire:click.stop="toggleCustomFlowSelection({{ $flow->id }})" {{ in_array($flow->id, $selectedCustomFlowIds) ? 'checked' : '' }} style="width: 15px; height: 15px; cursor: pointer; accent-color: #3b82f6;">
                                        </td>
                                        <td style="padding: 8px 10px; font-weight: 700; color: #003699; font-family: monospace; font-size: 12px;">{{ $flow->flow_code }}</td>
                                        <td style="padding: 8px 10px; font-weight: 600; color: #1e293b;">{{ $flow->flow_name }}</td>
                                        <td style="padding: 8px 10px;">
                                            @if(($flow->flow_for ?? 'system') === 'system')
                                                <span style="padding: 2px 7px; border-radius: 4px; font-size: 10.5px; font-weight: 600; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;">System-Wide</span>
                                            @elseif(($flow->flow_for ?? 'system') === 'office')
                                                <span style="padding: 2px 7px; border-radius: 4px; font-size: 10.5px; font-weight: 600; background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0;">Office-Wide</span>
                                            @else
                                                <span style="padding: 2px 7px; border-radius: 4px; font-size: 10.5px; font-weight: 600; background: #faf5ff; color: #7e22ce; border: 1px solid #e9d5ff;">Personal</span>
                                            @endif
                                        </td>
                                        <td style="padding: 8px 10px; color: #0369a1; font-weight: 500;">{{ $officeOwner }}</td>
                                        <td style="padding: 8px 10px; color: #475569;">{{ $creatorName }}</td>
                                        <td style="padding: 8px 10px; color: #64748b; font-size: 11.5px;">{{ $dateCreatedFormatted }}</td>
                                        <td style="padding: 8px 10px; text-align: center;">
                                            @if($flow->is_active)
                                                <span style="padding: 2px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 600; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">Active</span>
                                            @else
                                                <span style="padding: 2px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 600; background: #fee2e2; color: #991b1b; border: 1px solid #fecdd3;">Inactive</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: #94a3b8; padding: 20px;">No custom flows found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <!-- Cards Grid Layout View -->
                    <div class="offices-list">
                        @forelse($customFlows as $flow)
                            @php
                                $isSelected = $selectedCustom === (string) $flow->id;
                                $flowInitials = strtoupper(substr($flow->flow_code ?: '?', 0, 3));
                                $creatorName = trim(($flow->first_name ?? '') . ' ' . ($flow->last_name ?? '')) ?: 'System / Admin';
                                $officeOwner = $flow->owner_office_code ?: 'N/A';
                            @endphp
                            <div class="office-item-card {{ $isSelected ? 'active' : '' }}" wire:key="custom-flow-item-{{ $flow->id }}" wire:click="selectCustomFlow({{ $flow->id }})">
                                <input type="checkbox" wire:click.stop="toggleCustomFlowSelection({{ $flow->id }})" {{ in_array($flow->id, $selectedCustomFlowIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0;">
                                <div class="office-avatar-small">
                                    <span>{{ $flowInitials }}</span>
                                </div>
                                <div class="office-meta-info">
                                    <span class="office-display-name">{{ $flow->flow_name }}</span>
                                    <span class="office-display-code">Code: {{ $flow->flow_code }} | Owner: {{ $officeOwner }}</span>
                                    <span class="office-display-code" style="color: #64748b; font-size: 11px;">By: {{ $creatorName }}</span>
                                    <span class="office-status-badge {{ $flow->is_active ? 'active-badge' : 'inactive-badge' }}" style="margin-top: 4px; display: block; width: fit-content;">
                                        {{ $flow->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </div>
                            </div>
                        @empty
                            <div style="text-align: center; color: #94a3b8; font-size: 13.5px; padding: 24px 10px;">
                                No custom flows found.
                            </div>
                        @endforelse
                    </div>
                @endif

                @if($customFlows->hasPages())
                    <div class="flows-pagination-bar">
                        {{ $customFlows->links('components.pagination') }}
                    </div>
                @endif
            </div>

            <!-- Right Pane: Custom Flow Configurator -->
            @if($selectedCustom)
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

                    <!-- Header -->
                    <div class="details-header">
                        <div class="details-header-avatar">
                            <i class="fa-solid fa-bezier-curve"></i>
                        </div>
                        <div class="details-header-info">
                            <h2 class="details-header-name">
                                {{ $selectedCustom === 'new' ? 'Configure New Custom Flow' : $customFlowName }}
                            </h2>
                            <span class="details-header-sub">
                                {{ $selectedCustom === 'new' ? 'Create a custom routing sequence' : 'Review & adjust custom transaction flow office routing' }}
                            </span>
                            @if($selectedCustom !== 'new')
                                @php
                                    $curFlow = \DB::table('dts_transaction_flow')
                                        ->leftJoin('account_details', 'dts_transaction_flow.added_by', '=', 'account_details.account_id')
                                        ->leftJoin('office', 'account_details.office_id', '=', 'office.id')
                                        ->where('dts_transaction_flow.id', (int) $selectedCustom)
                                        ->select(['account_details.first_name', 'account_details.last_name', 'office.office_name', 'office.office_code', 'dts_transaction_flow.date_added'])
                                        ->first();
                                    $curCreator = trim(($curFlow?->first_name ?? '') . ' ' . ($curFlow?->last_name ?? '')) ?: 'System / Admin';
                                    $curOffice = $curFlow?->office_name ? ($curFlow->office_name . ' (' . $curFlow->office_code . ')') : 'Global / Unassigned';
                                    $curDate = $curFlow?->date_added ? \Carbon\Carbon::parse($curFlow->date_added)->format('M d, Y h:i A') : '—';
                                @endphp
                                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; font-size: 11.5px;">
                                    <span style="display: inline-flex; align-items: center; gap: 5px; background: rgba(255, 255, 255, 0.15); padding: 3px 10px; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.25); color: #ffffff;">
                                        <i class="fa-solid fa-building" style="color: #93c5fd;"></i>
                                        <strong style="color: #ffffff;">Owner Office:</strong>
                                        <span style="color: #e0f2fe;">{{ $curOffice }}</span>
                                    </span>
                                    <span style="display: inline-flex; align-items: center; gap: 5px; background: rgba(255, 255, 255, 0.15); padding: 3px 10px; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.25); color: #ffffff;">
                                        <i class="fa-solid fa-user" style="color: #93c5fd;"></i>
                                        <strong style="color: #ffffff;">Created By:</strong>
                                        <span style="color: #e0f2fe;">{{ $curCreator }}</span>
                                    </span>
                                    <span style="display: inline-flex; align-items: center; gap: 5px; background: rgba(255, 255, 255, 0.15); padding: 3px 10px; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.25); color: #ffffff;">
                                        <i class="fa-solid fa-calendar-day" style="color: #93c5fd;"></i>
                                        <strong style="color: #ffffff;">Date Created:</strong>
                                        <span style="color: #e0f2fe;">{{ $curDate }}</span>
                                    </span>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Form Body -->
                    <div class="details-body">
                        <form wire:submit.prevent="saveCustomFlow" style="display: flex; flex-direction: column; gap: 20px;">
                            <!-- Flow Name -->
                            <div class="form-group">
                                <span class="form-label">Flow Name</span>
                                <input type="text" class="form-input" placeholder="e.g. Special Department Project Route" wire:model="customFlowName">
                                @error('customFlowName') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                            </div>

                            <!-- Flow Short Code -->
                            <div class="form-group">
                                <span class="form-label">Flow Code</span>
                                <input type="text" class="form-input" placeholder="e.g. FLOW-CUSTOM-01" wire:model="customFlowCode" {{ $selectedCustom !== 'new' ? 'disabled' : '' }}>
                                @error('customFlowCode') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                            </div>

                            <!-- Flow Purpose -->
                            <div class="form-group">
                                <span class="form-label">Subsystem Purpose / Category</span>
                                <select class="form-input" wire:model="customFlowUse" style="cursor: pointer;">
                                    <option value="none">General / All Subsystems</option>
                                    <option value="internal">Internal Transactions</option>
                                    <option value="external">External Communications</option>
                                    <option value="issuances">Document Issuances</option>
                                    <option value="application">Application Letters</option>
                                    <option value="others">Others</option>
                                </select>
                                @error('customFlowUse') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                            </div>

                            <!-- Flow Visibility / Who Can See This Flow -->
                            <div class="form-group">
                                <span class="form-label">Flow Visibility / Who Can See This Flow</span>
                                <select class="form-input" wire:model="customFlowFor" style="cursor: pointer;">
                                    <option value="system">🌐 System-Wide (Visible to All Offices & Users)</option>
                                    <option value="office">🏢 Office-Wide (Visible to Creator's Office/Department Only)</option>
                                    <option value="user">👤 Personal / Private (Visible ONLY to Creator)</option>
                                </select>
                                <span style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">
                                    Controls which users can select this flow template when creating a new transaction in DTS.
                                </span>
                                @error('customFlowFor') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                            </div>

                            <!-- Active Status Toggle -->
                            @if($selectedCustom !== 'new')
                                <div class="form-group">
                                    <div class="status-toggle-wrapper">
                                        <div class="status-toggle-label">
                                            <span class="status-toggle-title">Flow Active Status</span>
                                            <span class="status-toggle-desc">Soft-deactivate to temporarily hide from routing options.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="customIsActive">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            @endif

                            <!-- Office Routing Sequence -->
                            <div class="form-group">
                                <span class="form-label">Sequential Office Route</span>
                                <span style="font-size: 11.5px; color: #64748b; margin-bottom: 8px; display: block;">
                                    Step 1 is fixed to <strong>ORIGIN</strong> (Requesting Office). Add intermediate recipient offices below.
                                </span>

                                <div style="display: flex; gap: 8px; position: relative;" x-data="{ open: false }" @click.outside="open = false">
                                    <div style="flex: 1; position: relative;">
                                        <input type="text" class="form-input" placeholder="Search office to insert into route..." wire:model.live="customOfficeSearch" @focus="open = true">
                                        <div class="suggestions-dropdown" x-show="open" x-cloak>
                                            @php
                                                $cSearchLower = strtolower($customOfficeSearch);
                                                $filteredOffices = $activeOffices->filter(function($o) use ($cSearchLower) {
                                                    return empty($cSearchLower) || str_contains(strtolower($o->office_name), $cSearchLower) || str_contains(strtolower($o->office_code), $cSearchLower);
                                                });
                                            @endphp
                                            @forelse($filteredOffices as $off)
                                                <div class="suggestion-item" @click="open = false" wire:click="selectCustomOfficeForAppend('{{ $off->office_code }}', '{{ $off->office_name }}')">
                                                    <span style="font-weight: 500; color: #1e293b;">{{ $off->office_name }}</span>
                                                    <span style="color: #64748b; font-weight: 600;">{{ $off->office_code }}</span>
                                                </div>
                                            @empty
                                                <div style="padding: 10px 14px; color: #94a3b8; font-size: 13px; font-style: italic; text-align: center;">No offices found</div>
                                            @endforelse
                                        </div>
                                    </div>
                                    <button type="button" class="btn-save" style="padding: 10px 18px; display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0;" wire:click="addCustomOfficeToPath">
                                        <i class="fa-solid fa-plus"></i> Add Step
                                    </button>
                                </div>

                                <div class="sequence-editor-box">
                                    @foreach($customFlowOffices as $index => $officeCode)
                                        @php
                                            $officeObj = $activeOffices->firstWhere('office_code', $officeCode);
                                            $displayName = $officeObj ? $officeObj->office_name : ($officeCode === 'ORIGIN' ? 'Origin Office' : ($officeCode === '[H]' ? 'Cluster Head' : $officeCode));
                                            $isOrigin = $index === 0;
                                        @endphp
                                        <div class="sequence-list-item">
                                            <div>
                                                <span class="seq-index">Step {{ $index + 1 }}:</span>
                                                <span class="seq-name">{{ $displayName }} ({{ $officeCode }})</span>
                                            </div>
                                            <div class="seq-actions">
                                                @if(!$isOrigin)
                                                    <button type="button" class="btn-seq-nav" wire:click="moveCustomOfficeUp({{ $index }})" {{ $index <= 1 ? 'disabled' : '' }}>
                                                        <i class="fa-solid fa-arrow-up"></i>
                                                    </button>
                                                    <button type="button" class="btn-seq-nav" wire:click="moveCustomOfficeDown({{ $index }})" {{ $index >= count($customFlowOffices) - 1 ? 'disabled' : '' }}>
                                                        <i class="fa-solid fa-arrow-down"></i>
                                                    </button>
                                                    <button type="button" class="btn-seq-remove" wire:click="removeCustomOffice({{ $index }})">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                @else
                                                    <span style="font-size: 11px; color: #94a3b8; font-style: italic; padding: 4px 8px;">Initial Origin</span>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Footer Actions -->
                    <div class="details-footer">
                        @if($selectedCustom !== 'new')
                            <button type="button" class="btn-delete" wire:click="deleteCustomFlow" wire:confirm="Are you sure you want to deactivate this custom flow? It will be soft-deleted (hidden) but retained for transparency." style="margin-right: auto;">
                                <i class="fa-solid fa-trash-can"></i> Delete Flow
                            </button>
                        @endif
                        <button type="button" class="btn-cancel" wire:click="$set('selectedCustom', '')">Cancel</button>
                        <button type="button" class="btn-save" wire:click="saveCustomFlow">
                            <i class="fa-solid fa-floppy-disk"></i> Save Configuration
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
