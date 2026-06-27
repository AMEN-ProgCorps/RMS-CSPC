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
    public $flowFile; // file upload property

    // ---- SEARCH FOR CUSTOM FLOWS ----
    public string $searchCustom = '';

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
        $this->clearMessages();
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
            });

            $this->resetForm();
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
                // We expect at least 3 lines for a flow (Name, Code, Sequence)
                if ($i + 2 >= count($lines)) {
                    throw new \Exception("Incomplete flow definition starting at Line {$lines[$i]['num']}. Each flow must contain at least a name, code, and sequence.");
                }

                $nameLine = $lines[$i];
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

                $flowName = trim(substr($nameLine['text'], 0, -1));
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

                // Determine advance count and Copy Furnished index early
                $nextIdx = $i + 3;
                $isNewFlowStart = false;
                if ($nextIdx < count($lines)) {
                    if ($nextIdx + 1 < count($lines)) {
                        $nextLineText = trim(substr($lines[$nextIdx + 1]['text'], 0, -1));
                        if (preg_match('/^[A-Z0-9_\-]+$/i', $nextLineText)) {
                            $isNewFlowStart = true;
                        }
                    }
                }
                $advanceCount = ($nextIdx < count($lines) && !$isNewFlowStart) ? 4 : 3;

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

        // Query predefined flows dropdown
        $predefinedFlows = \DB::table('dts_transaction_flow')
            ->where('flow_code', 'not like', 'FLOW-CUSTOM-%')
            ->orderBy('flow_name')
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
    @vite(['resources/css/admin/activity_logs.css', 'resources/css/admin/subsystems.css'])
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
    </style>
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="page-header">
        <h1>Document Tracking - Transaction Flows</h1>
        <p>Define standard routing sequences for documents and monitor custom pathways utilized by colleges.</p>
    </div>

    <!-- Alert Messages -->
    @if($successMessage)
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i> {{ $successMessage }}
        </div>
    @endif
    @if($errorMessage)
        <div class="alert alert-error">
            <i class="fa-solid fa-triangle-exclamation"></i> {{ $errorMessage }}
        </div>
    @endif

    <!-- Tab Selection -->
    <div class="tabs-header">
        <button type="button" class="tab-btn {{ $activeTab === 'predefined' ? 'active' : '' }}" wire:click="$set('activeTab', 'predefined')">
            <i class="fa-solid fa-gears" style="margin-right: 6px;"></i> Predefined Flow Manager
        </button>
        <button type="button" class="tab-btn {{ $activeTab === 'custom' ? 'active' : '' }}" wire:click="$set('activeTab', 'custom')">
            <i class="fa-solid fa-bezier-curve" style="margin-right: 6px;"></i> Custom Flows Directory
        </button>
    </div>

    <!-- Tab 1: Predefined Flow Manager -->
    @if($activeTab === 'predefined')
        <div class="subsystem-form-card" style="max-width: 700px;">
            <form wire:submit.prevent="savePredefinedFlow">
                
                <!-- Action selector -->
                <div class="form-group">
                    <label for="flowSelect">Action / Selected Flow</label>
                    <select id="flowSelect" wire:model.live="selectedPredefined">
                        <option value="">-- Choose Option --</option>
                        <option value="new">Configure New Predefined Flow</option>
                        <option value="import">Import Predefined Flow from File</option>
                        @foreach($predefinedFlows as $flow)
                            <option value="{{ $flow->id }}">Edit: {{ $flow->flow_name }} ({{ $flow->flow_code }})</option>
                        @endforeach
                    </select>
                </div>

                @if($selectedPredefined === 'import')
                    <!-- File Upload Option -->
                    <div class="form-group" style="margin-top: 20px;">
                        <label for="flowFileInput">Select Predefined Flow Text File (.txt)</label>
                        <input type="file" 
                               id="flowFileInput" 
                               wire:model="flowFile" 
                               accept=".txt" 
                               style="padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                        @error('flowFile') <span class="error-message">{{ $message }}</span> @enderror
                        <p style="font-size: 12.5px; color: #64748b; margin-top: 8px; line-height: 1.5;">
                            <strong>Instructions:</strong> The uploaded file must be a plain text file (<code>.txt</code>). Every line must end with a semicolon (<code>;</code>):
                            <br>• Line 1: <code>&lt;flow name&gt;;</code>
                            <br>• Line 2: <code>&lt;flow code&gt;;</code>
                            <br>• Line 3: <code>&lt;flow sequence&gt;;</code> (e.g. <code>[]-&gt;RFAO-&gt;ICTU;</code> where <code>[]</code> is the Originated Office)
                            <br>• Line 4 (Optional): <code>&lt;copy furnished offices (comma-separated)&gt;;</code> (e.g. <code>Unit Head, HRMDU, RFOIU;</code>)
                            <br>• Lines starting with <code>#</code> are classified as comments and will be ignored.
                        </p>
                    </div>

                    <!-- Actions for Import -->
                    <div class="form-actions" style="margin-top: 24px;">
                        <button type="button" class="btn-secondary" wire:click="resetForm">
                            Cancel
                        </button>
                        <button type="button" class="btn-primary" wire:click="importFlow">
                            <i class="fa-solid fa-file-import"></i> Extract Flow Data
                        </button>
                    </div>
                @elseif($selectedPredefined !== '')
                    <!-- Flow Name -->
                    <div class="form-group">
                        <label for="flowNameInput">Flow Display Name</label>
                        <input type="text" 
                               id="flowNameInput" 
                               placeholder="e.g. Standard Clearance Routing" 
                               wire:model="flowName">
                        @error('flowName') <span class="error-message">{{ $message }}</span> @enderror
                    </div>

                    <!-- Flow Code -->
                    <div class="form-group">
                        <label for="flowCodeInput">Flow Unique Code</label>
                        <input type="text" 
                               id="flowCodeInput" 
                               placeholder="e.g. FLOW-CLEARANCE" 
                               wire:model="flowCode"
                               @if($selectedPredefined !== 'new') disabled @endif>
                        @error('flowCode') <span class="error-message">{{ $message }}</span> @enderror
                    </div>

                    <!-- Active Status -->
                    <div class="form-group" style="flex-direction: row; align-items: center; gap: 10px;">
                        <input type="checkbox" id="flowActive" wire:model="isActive" style="width: 18px; height: 18px; margin: 0; cursor: pointer;">
                        <label for="flowActive" style="cursor: pointer; margin: 0;">Is Active (Visible for new transactions)</label>
                    </div>

                    <!-- Office Path Sequence Builder -->
                    <div class="form-group" style="margin-top: 24px;">
                        <label>Office Sequence Path</label>
                        
                        <!-- Add Office Row -->
                        <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 12px;">
                            <select wire:model="selectedOffice" style="margin: 0; flex: 1;">
                                <option value="">-- Select Office to Append --</option>
                                @foreach($activeOffices as $office)
                                    <option value="{{ $office->office_code }}">{{ $office->office_name }} ({{ $office->office_code }})</option>
                                @endforeach
                            </select>
                            <button type="button" class="btn-primary" style="height: 40px; padding: 0 16px; margin: 0; display: inline-flex; align-items: center; gap: 5px;" wire:click="addOfficeToPath">
                                <i class="fa-solid fa-plus"></i> Add
                            </button>
                        </div>

                        <!-- Sequence List -->
                        <div class="sequence-editor-box">
                            @forelse($flowOffices as $index => $officeCode)
                                @php
                                    $officeObj = $activeOffices->firstWhere('office_code', $officeCode);
                                    $displayName = ($officeCode === 'ORIGIN') ? 'Originated Office' : ($officeObj ? $officeObj->office_name : $officeCode);
                                @endphp
                                <div class="sequence-list-item" wire:key="seq-node-{{ $index }}">
                                    <div>
                                        <span class="seq-index">{{ $index + 1 }}.</span>
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
                                            <span class="subsystem-badge" style="background-color: #f1f5f9; color: #475569; border-color: #cbd5e1; font-size: 11px; font-weight: 600; padding: 4px 8px; border-radius: 6px;">
                                                <i class="fa-solid fa-lock" style="margin-right: 4px;"></i> Fixed First Step
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <div style="text-align: center; color: #94a3b8; font-size: 13px; padding: 12px;">
                                    Routing path is empty. Add offices above.
                                </div>
                            @endforelse
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="form-actions" style="margin-top: 24px;">
                        <button type="button" class="btn-secondary" wire:click="resetForm">
                            Cancel
                        </button>
                        <button type="submit" class="btn-primary">
                            <i class="fa-solid fa-floppy-disk"></i> Save Configuration
                        </button>
                    </div>
                @endif
            </form>
        </div>
    @endif

    <!-- Tab 2: Custom Flows Directory -->
    @if($activeTab === 'custom')
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
