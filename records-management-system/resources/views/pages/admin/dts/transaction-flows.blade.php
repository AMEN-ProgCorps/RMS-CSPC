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

    // ---- PREDEFINED FLOW EDITOR PROPERTIES ----
    /** @var string Selected action/subsystem option (empty, 'new', or numeric ID) */
    public string $selectedPredefined = '';

    public string $flowName = '';
    public string $flowCode = '';
    public bool $isActive = true;
    public array $flowOffices = []; // list of office codes in sequence
    public string $selectedOffice = ''; // selected office from dropdown to add
    public string $officeSearch = ''; // text query for searching offices to add
    public $flowFile; // file upload property

    // ---- COPY FURNISHED EDITOR PROPERTIES ----
    public array $cfOffices = []; // list of office codes for predefined copy furnished
    public string $cfSearch = ''; // text query for searching copy furnished offices to add
    public string $selectedCfOffice = ''; // selected office from dropdown for copy furnished

    // ---- SEARCH FOR CUSTOM FLOWS ----
    public string $searchCustom = '';

    // ---- SEARCH FOR PREDEFINED FLOWS ----
    public string $searchPredefined = '';

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
            $this->validate([
                'flowName' => 'required|string|max:255|unique:dts_transaction_flow,flow_name',
                'flowCode' => 'required|string|max:255|unique:dts_transaction_flow,flow_code|regex:/^[A-Z0-9_\-]+$/i',
            ], [
                'flowCode.regex' => 'Flow code can only contain letters, numbers, dashes, and underscores.',
            ]);
        } else {
            $this->validate([
                'flowName' => 'required|string|max:255|unique:dts_transaction_flow,flow_name,' . $this->selectedPredefined . ',id',
            ]);
        }

        // 2. Perform DB operations
        try {
            \DB::transaction(function () {
                $flowId = null;

                if ($this->selectedPredefined === 'new') {
                    $maxId = \DB::table('dts_transaction_flow')->max('id') ?? 0;
                    $flowId = $maxId + 1;

                    // Insert flow
                    \DB::table('dts_transaction_flow')->insert([
                        'id' => $flowId,
                        'flow_name' => trim($this->flowName),
                        'flow_code' => strtoupper(trim($this->flowCode)),
                        'is_active' => $this->isActive,
                    ]);

                    // Insert admin log
                    \DB::table('admin_logs')->insert([
                        'changes' => "Created predefined transaction flow: " . trim($this->flowName) . " (" . strtoupper(trim($this->flowCode)) . ")",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now(),
                    ]);

                    $this->successMessage = 'Predefined flow created successfully!';
                } else {
                    $flowId = (int) $this->selectedPredefined;
                    $flow = \DB::table('dts_transaction_flow')->where('id', $flowId)->first();

                    if (!$flow) throw new \Exception('Predefined flow not found.');

                    // Update flow details
                    \DB::table('dts_transaction_flow')->where('id', $flowId)->update([
                        'flow_name' => trim($this->flowName),
                        'is_active' => $this->isActive,
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
     * Parse the uploaded .txt file and populate the flow creation form.
     */
    public function importFlow(): void
    {
        $this->clearMessages();

        $this->validate([
            'flowFile' => 'required|file|mimes:txt|max:1024',
        ], [
            'flowFile.required' => 'Please select a text file to upload.',
            'flowFile.mimes' => 'The file must be a plain text file (.txt).',
            'flowFile.max' => 'The file size must be less than 1MB.',
        ]);

        try {
            $path = $this->flowFile->getRealPath();
            $content = file_get_contents($path);

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

                // We expect at least 3 lines for a flow (Name, Code, Sequence)
                if ($i + 2 >= count($lines)) {
                    throw new \Exception("Incomplete flow definition starting at Line {$lines[$i]['num']}. Each flow must contain at least a name, code, and sequence.");
                }

                $codeLine = $lines[$i + 1];
                $seqLine = $lines[$i + 2];

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

                // Determine advance count and Copy Furnished index early
                $advanceCount = 3;
                $nextIdx = $i + 3;
                if ($nextIdx < count($lines)) {
                    $fourthLine = $lines[$nextIdx];
                    if (!str_starts_with($fourthLine['text'], '=')) {
                        $advanceCount = 4;
                    }
                }

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
                        $i += $advanceCount;
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
                    $i += $advanceCount;
                    continue;
                }
                if (isset($tempNames[$flowName])) {
                    if ($tempNames[$flowName] !== $flowCode) {
                        throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\"): Duplicate flow name '{$flowName}' found within the uploaded file with a different code.");
                    }
                    // Perfect file duplicate: skip
                    $i += $advanceCount;
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

                // 6. Handle optional 4th line for Copy Furnished offices
                $cfOffices = [];
                if ($advanceCount === 4) {
                    $candidateLine = $lines[$nextIdx];
                    if (!str_ends_with($candidateLine['text'], ';')) {
                        throw new \Exception("Line {$candidateLine['num']} (\"{$candidateLine['text']}\") must end with a semicolon ';'.");
                    }

                    $cfText = trim(substr($candidateLine['text'], 0, -1));
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
                                    throw new \Exception("Line {$candidateLine['num']} (\"{$candidateLine['text']}\"): Copy furnished office '{$node}' does not exist in the database or is typoed.");
                            }

                            $cfOffices[] = $office->office_code;
                        }
                    }
                }

                $extractedFlows[] = [
                    'name' => $flowName,
                    'code' => $flowCode,
                    'offices' => $officesSequence,
                    'cf_offices' => $cfOffices
                ];
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
            ->where('flow_code', 'not like', 'FLOW-CUSTOM-%');

        if ($this->searchPredefined !== '') {
            $searchVal = '%' . $this->searchPredefined . '%';
            $predefinedQuery->where(function ($q) use ($searchVal) {
                $q->where('flow_name', 'like', $searchVal)
                  ->orWhere('flow_code', 'like', $searchVal);
            });
        }

        $predefinedFlows = $predefinedQuery->orderBy('flow_name')
            ->get();

        // Query custom flows (Tab 2)
        $customFlows = collect();
        if ($this->activeTab === 'custom') {
            $customQuery = \DB::table('dts_transaction_flow')
                ->leftJoin('dts_transaction_details', 'dts_transaction_flow.flow_code', '=', 'dts_transaction_details.transaction_flow')
                ->where('dts_transaction_flow.flow_code', 'like', 'FLOW-CUSTOM-%')
                ->select([
                    'dts_transaction_flow.id',
                    'dts_transaction_flow.flow_code',
                    'dts_transaction_flow.flow_name',
                    'dts_transaction_details.control_number',
                    'dts_transaction_details.type as transaction_type',
                    'dts_transaction_details.date_created',
                ]);

            if ($this->searchCustom !== '') {
                $searchVal = '%' . $this->searchCustom . '%';
                $customQuery->where(function ($q) use ($searchVal) {
                    $q->where('dts_transaction_flow.flow_code', 'like', $searchVal)
                      ->orWhere('dts_transaction_flow.flow_name', 'like', $searchVal)
                      ->orWhere('dts_transaction_details.control_number', 'like', $searchVal);
                });
            }

            $customFlows = $customQuery->orderBy('dts_transaction_details.date_created', 'desc')->paginate(15);

            // Emulate Eager Loading: Retrieve all sequences for custom flows on current page in 1 single query
            if ($customFlows->count() > 0) {
                $flowIds = $customFlows->pluck('id')->toArray();
                $sequences = \DB::table('dts_sequence_list')
                    ->leftJoin('office', 'dts_sequence_list.office_code', '=', 'office.office_code')
                    ->whereIn('control_id', $flowIds)
                    ->orderBy('sequence_ranking')
                    ->select(['control_id', 'sequence_ranking', 'office.office_name', 'office.office_code'])
                    ->get()
                    ->groupBy('control_id');

                // Map sequences back onto customFlows collection
                $customFlows->each(function ($item) use ($sequences) {
                    $item->path = $sequences->get($item->id) ?? collect();
                });
            }
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
        .admin-offices-container {
            min-height: calc(100vh - 190px) !important;
            height: calc(100vh - 190px) !important;
        }
        .directory-panel {
            max-height: calc(100vh - 210px) !important;
        }
        .details-panel {
            max-height: calc(100vh - 210px) !important;
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
        <div class="admin-offices-container">
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

                <div class="offices-list">
                    @forelse($predefinedFlows as $flow)
                        @php
                            $isSelected = $selectedPredefined === (string) $flow->id;
                            $flowInitials = strtoupper(substr($flow->flow_code ?: '?', 0, 3));
                        @endphp
                        <div class="office-item-card {{ $isSelected ? 'active' : '' }}" wire:key="flow-item-{{ $flow->id }}" wire:click="selectFlow({{ $flow->id }})">
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
            </div>

            <!-- Right Pane: Flow Configuration details-panel -->
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

                @if($selectedPredefined)
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
                            <button type="button" class="btn-cancel" wire:click="resetForm">Cancel</button>
                            <button type="button" class="btn-save" wire:click="savePredefinedFlow">
                                <i class="fa-solid fa-floppy-disk"></i> Save Configuration
                            </button>
                        </div>
                    @endif
                @else
                    <!-- Placeholder -->
                    <div class="details-placeholder">
                        <i class="fa-solid fa-route"></i>
                        <h3>Transaction Flows Configuration</h3>
                        <p>Click on any flow in the directory list to edit its sequence path. Click the <strong>New Flow</strong> button above to construct a new flow template from scratch, or click <strong>Import File</strong> to upload predefined flow definitions.</p>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Tab 2: Custom Flows Directory -->
    @if($activeTab === 'custom')
        @if($successMessage)
            <div class="toast-alert success" style="margin-bottom: 16px;">
                <i class="fa-solid fa-circle-check"></i>
                <span>{{ $successMessage }}</span>
            </div>
        @endif

        @if($errorMessage)
            <div class="toast-alert error" style="margin-bottom: 16px;">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span>{{ $errorMessage }}</span>
            </div>
        @endif
        <!-- Search -->
        <div class="logs-controls-card">
            <div class="search-filter-group">
                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" 
                           class="search-input" 
                           placeholder="Search custom flow or control number..." 
                           wire:model.live="searchCustom">
                </div>
                @if($searchCustom !== '')
                    <button type="button" class="btn-clear-filters" wire:click="clearFilters">
                        <i class="fa-solid fa-filter-circle-xmark"></i> Clear Search
                    </button>
                @endif
            </div>
        </div>

        <!-- Table Grid -->
        <div class="logs-table-card">
            <div class="table-responsive">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th style="width: 20%">Flow Code</th>
                            <th style="width: 25%">Used by Transaction</th>
                            <th style="width: 40%">Sequential Office Route</th>
                            <th style="width: 15%">Created On</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($customFlows as $flow)
                            <tr wire:key="custom-flow-{{ $flow->id }}">
                                <td>
                                    <span style="font-family: monospace; font-size: 12.5px; font-weight: 600; color: #475569;">{{ $flow->flow_code }}</span>
                                </td>
                                <td>
                                    <div class="admin-name-cell">
                                        @if($flow->control_number)
                                            <span class="name" style="color: #003699;">{{ $flow->control_number }}</span>
                                            <span class="email-sub">Type: {{ strtoupper($flow->transaction_type) }}</span>
                                        @else
                                            <span class="name" style="color: #94a3b8; font-style: italic;">No Transaction Linked</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="office-flow-path">
                                        @forelse($flow->path as $nodeIndex => $node)
                                            @if($nodeIndex > 0)
                                                <i class="fa-solid fa-angle-right flow-separator"></i>
                                            @endif
                                            <span class="office-flow-node" title="{{ $node->office_name }}">{{ $node->office_code }}</span>
                                        @empty
                                            <span style="color: #94a3b8; font-style: italic; font-size: 12px;">No sequence loaded</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td>
                                    <div class="log-timestamp">
                                        {{ $flow->date_created ? \Carbon\Carbon::parse($flow->date_created)->format('Y-m-d H:i:s') : 'N/A' }}
                                        @if($flow->date_created)
                                            <span class="time-ago">{{ \Carbon\Carbon::parse($flow->date_created)->diffForHumans() }}</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-bezier-curve"></i>
                                        <h3>No Custom Flows Found</h3>
                                        <p>No customized office routing paths recorded in system database.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Custom Pagination -->
            @if($customFlows->hasPages())
                <div class="pagination-container">
                    <div class="pagination-info">
                        Showing {{ $customFlows->firstItem() ?? 0 }} to {{ $customFlows->lastItem() ?? 0 }} of {{ $customFlows->total() }} entries
                    </div>
                    <div class="pagination-links">
                        @if ($customFlows->onFirstPage())
                            <button type="button" class="pagination-btn" disabled>&laquo;</button>
                        @else
                            <button type="button" class="pagination-btn" wire:click="previousPage" wire:loading.attr="disabled">&laquo;</button>
                        @endif

                        @foreach ($customFlows->getUrlRange(max(1, $customFlows->currentPage() - 2), min($customFlows->lastPage(), $customFlows->currentPage() + 2)) as $page => $url)
                            @if ($page == $customFlows->currentPage())
                                <button type="button" class="pagination-btn active">{{ $page }}</button>
                            @else
                                <button type="button" class="pagination-btn" wire:click="gotoPage({{ $page }})" wire:loading.attr="disabled">{{ $page }}</button>
                            @endif
                        @endforeach

                        @if ($customFlows->hasMorePages())
                            <button type="button" class="pagination-btn" wire:click="nextPage" wire:loading.attr="disabled">&raquo;</button>
                        @else
                            <button type="button" class="pagination-btn" disabled>&raquo;</button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
