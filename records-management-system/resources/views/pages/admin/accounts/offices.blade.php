<?php
/**
 * Admin Console - Accounts Management Offices & Clusters Volt Component
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Illuminate\Support\Str;

new #[Layout('layouts.admin')] #[Title('Admin Console - Offices & Clusters')] class extends Component {
    use WithFileUploads, WithPagination;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingClusterSearch(): void
    {
        $this->resetPage();
    }

    /** @var string Active tab: 'offices' or 'clusters' */
    public string $activeTab = 'offices';

    // ---- OFFICES DIRECTORY PROPERTIES ----
    public string $search = '';
    public string $officeViewMode = 'table'; // 'table' or 'grid'
    public ?int $selectedOfficeId = null;
    public $officeFile;
    public array $selectedOfficeIds = [];

    // Office Form fields
    public string $officeName = '';
    public string $officeCode = '';
    public string $officeCluster = '';
    public bool $isActive = true;

    // ---- CLUSTERS DIRECTORY PROPERTIES ----
    public string $clusterSearch = '';
    public string $clusterViewMode = 'table'; // 'table' or 'grid'
    public ?int $selectedClusterId = null;
    public $clusterFile;
    public array $selectedClusterIds = [];

    // Cluster Form fields
    public string $clusterName = '';
    public string $clusterCode = '';
    public string $clusterHead = '';
    public bool $clusterIsActive = true;

    // ---- OTHER OFFICES (EXTERNAL SOURCE OFFICES) DIRECTORY PROPERTIES ----
    public string $otherOfficeSearch = '';
    public string $otherOfficeViewMode = 'table';
    public ?int $selectedOtherOfficeId = null;
    public string $otherOfficeName = '';
    public string $otherOfficeCode = '';
    public bool $otherOfficeIsActive = true;
    public string $otherOfficeCreatedBy = '';

    public function updatingOtherOfficeSearch(): void
    {
        $this->resetPage();
    }

    // Searchable dropdown properties
    public string $clusterHeadSearch = '';
    public bool $showClusterHeadDropdown = false;
    public string $officeClusterSearch = '';
    public bool $showOfficeClusterDropdown = false;

    // Toast notifications
    public string $successMessage = '';
    public string $errorMessage = '';

    /**
     * Component mount hook - initializes the default view.
     */
    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->is_admin && !$perms->can_sadm_modify_accountlist)) {
            $this->redirect(route('portal'));
            return;
        }
        $this->cancelSelection();
    }

    /**
     * Clears all session alert banners.
     */
    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * Resets form fields and returns to the selection placeholder screen.
     */
    public function cancelSelection(): void
    {
        $this->selectedOfficeId = null;
        $this->officeName = '';
        $this->officeCode = '';
        $this->officeCluster = '';
        $this->isActive = true;
        $this->officeFile = null;
        $this->selectedOfficeIds = [];
        $this->officeClusterSearch = '';
        $this->showOfficeClusterDropdown = false;
        $this->clusterHeadSearch = '';
        $this->showClusterHeadDropdown = false;

        $this->selectedClusterId = null;
        $this->clusterName = '';
        $this->clusterCode = '';
        $this->clusterHead = '';
        $this->clusterIsActive = true;
        $this->clusterFile = null;
        $this->selectedClusterIds = [];

        $this->selectedOtherOfficeId = null;
        $this->otherOfficeName = '';
        $this->otherOfficeCode = '';
        $this->otherOfficeIsActive = true;
        $this->otherOfficeCreatedBy = '';

        $this->clearMessages();
    }

    public function startCreateOtherOffice(): void
    {
        $this->cancelSelection();
        $this->selectedOtherOfficeId = -1;
    }

    public function selectOtherOffice(int $id): void
    {
        $this->cancelSelection();
        $sourceOffice = \DB::table('dts_source_office')->where('id', $id)->first();
        if ($sourceOffice) {
            $this->selectedOtherOfficeId = $sourceOffice->id;
            $this->otherOfficeName = $sourceOffice->s_office_name;
            $this->otherOfficeCode = $sourceOffice->s_office_code;
            $this->otherOfficeIsActive = (bool) $sourceOffice->is_active;
            $this->otherOfficeCreatedBy = $sourceOffice->created_by_office;
        }
    }

    public function saveOtherOfficeChanges(): void
    {
        $this->clearMessages();

        $this->validate([
            'otherOfficeName' => 'required|string|max:255',
            'otherOfficeCode' => 'required|string|max:100',
        ], [
            'otherOfficeName.required' => 'The External Office Name is required.',
            'otherOfficeCode.required' => 'The External Office Code is required.',
        ]);

        $code = strtoupper(trim($this->otherOfficeCode));
        $name = trim($this->otherOfficeName);

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

            \DB::table('dts_source_office')->insert([
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

            $this->cancelSelection();
            $this->successMessage = 'External source office created successfully!';
        } else {
            $exists = \DB::table('dts_source_office')
                ->where('s_office_code', $code)
                ->where('id', '!=', $this->selectedOtherOfficeId)
                ->exists();
            if ($exists) {
                $this->errorMessage = "The External Office Code '{$code}' is already used by another office.";
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

            $this->cancelSelection();
            $this->successMessage = 'External source office updated successfully!';
        }
    }

    public function deleteOtherOffice(): void
    {
        if (!$this->selectedOtherOfficeId) return;

        \DB::table('dts_source_office')
            ->where('id', $this->selectedOtherOfficeId)
            ->update(['is_active' => false]);

        $this->cancelSelection();
        $this->successMessage = 'External source office deactivated successfully!';
    }

    /**
     * Initializes "Create Mode" for configuring a new office.
     */
    public function startCreate(): void
    {
        $this->cancelSelection();
        $this->selectedOfficeId = -1; // -1 denotes "Create Mode"
    }

    /**
     * Initializes "Import Mode" for configuring multiple offices via file.
     */
    public function startImport(): void
    {
        $this->cancelSelection();
        $this->selectedOfficeId = -2; // -2 denotes "Import Mode"
    }

    /**
     * Parse the uploaded .txt file and import office entries.
     */
    public function importOffices(): void
    {
        $this->clearMessages();

        $this->validate([
            'officeFile' => 'required|file|extensions:txt,text|max:2048',
        ], [
            'officeFile.required' => 'Please select a text file to upload.',
            'officeFile.extensions' => 'The file must be a plain text file (.txt).',
            'officeFile.max' => 'The file size must be less than 2MB.',
        ]);

        try {
            $content = $this->officeFile->get();
            if ($content === false || $content === null) {
                $content = @file_get_contents($this->officeFile->getRealPath());
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

            $extractedOffices = [];
            $tempNames = [];
            $tempCodes = [];

            // Loop over non-empty lines using a dynamic index pointer
            $i = 0;
            while ($i < count($lines)) {
                $nameLine = $lines[$i];

                if (!str_starts_with($nameLine['text'], '=')) {
                    throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\") must start with '=' to indicate the start of an office name.");
                }

                // We expect at least 2 lines for an office (Name, Code)
                if ($i + 1 >= count($lines)) {
                    throw new \Exception("Incomplete office definition starting at Line {$lines[$i]['num']}. Each office must contain at least a name and code.");
                }

                $codeLine = $lines[$i + 1];

                // 1. Verify semicolons
                if (!str_ends_with($nameLine['text'], ';')) {
                    throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\") must end with a semicolon ';'.");
                }
                if (!str_ends_with($codeLine['text'], ';')) {
                    throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\") must end with a semicolon ';'.");
                }

                $officeName = trim(substr($nameLine['text'], 1, -1));
                $officeCode = trim(substr($codeLine['text'], 0, -1));

                // 2. Validate empty values
                if (empty($officeName)) {
                    throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\"): Office name cannot be empty.");
                }
                if (empty($officeCode)) {
                    throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\"): Office code cannot be empty.");
                }

                // Parse optional lines: active status and/or cluster code
                $isActiveVal = true;
                $officeClusterVal = null;
                $advanceCount = 2;

                if ($i + 2 < count($lines)) {
                    $thirdLine = $lines[$i + 2];
                    if (!str_starts_with($thirdLine['text'], '=')) {
                        $thirdVal = trim(substr($thirdLine['text'], 0, -1));

                        if (strcasecmp($thirdVal, 'true') === 0 || strcasecmp($thirdVal, 'false') === 0) {
                            $isActiveVal = (strcasecmp($thirdVal, 'true') === 0);
                            $advanceCount = 3;

                            // Check if 4th line is cluster code
                            if ($i + 3 < count($lines)) {
                                $fourthLine = $lines[$i + 3];
                                if (!str_starts_with($fourthLine['text'], '=')) {
                                    $fourthVal = trim(substr($fourthLine['text'], 0, -1));
                                    
                                    // A cluster code must end with semicolon and exist in cluster database
                                    if (str_ends_with($fourthLine['text'], ';')) {
                                        $clusterExists = \DB::table('sys_cluster')->where('cluster_code', $fourthVal)->exists();
                                        if ($clusterExists) {
                                            $officeClusterVal = $fourthVal;
                                            $advanceCount = 4;
                                        }
                                    }
                                }
                            }
                        } else {
                            // 3rd line is not boolean, so it must be cluster code
                            if (str_ends_with($thirdLine['text'], ';')) {
                                $clusterExists = \DB::table('sys_cluster')->where('cluster_code', $thirdVal)->exists();
                                if ($clusterExists) {
                                    $officeClusterVal = $thirdVal;
                                    $advanceCount = 3;
                                }
                            }
                        }
                    }
                }

                // Check semicolons for consumed lines
                for ($k = 2; $k < $advanceCount; $k++) {
                    $consumedLine = $lines[$i + $k];
                    if (!str_ends_with($consumedLine['text'], ';')) {
                        throw new \Exception("Line {$consumedLine['num']} (\"{$consumedLine['text']}\") must end with a semicolon ';'.");
                    }
                }

                // 3. Check database existence and conflict matching
                $officeByCode = \DB::table('sys_office')->where('office_code', $officeCode)->first();
                $officeByName = \DB::table('sys_office')->where('office_name', $officeName)->first();

                if ($officeByCode || $officeByName) {
                    // Check if it is a perfect match (both name and code point to the same existing record)
                    if ($officeByCode && $officeByName && $officeByCode->id === $officeByName->id) {
                        // Perfect match: skip importing this office since it already exists exactly as is
                        $i += $advanceCount;
                        continue;
                    }

                    // Otherwise, it's a conflict
                    if ($officeByCode && (!$officeByName || $officeByCode->office_name !== $officeName)) {
                        throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\"): Conflict detected. The office code '{$officeCode}' already exists in the database with a different name ('{$officeByCode->office_name}').");
                    }
                    if ($officeByName && (!$officeByCode || $officeByName->office_code !== $officeCode)) {
                        throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\"): Conflict detected. The office name '{$officeName}' already exists in the database with a different code ('{$officeByName->office_code}').");
                    }
                }

                // 4. Check uniqueness within the uploaded file itself
                if (isset($tempCodes[$officeCode])) {
                    if ($tempCodes[$officeCode] !== $officeName) {
                        throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\"): Duplicate office code '{$officeCode}' found within the uploaded file with a different name.");
                    }
                    $i += $advanceCount;
                    continue;
                }
                if (isset($tempNames[$officeName])) {
                    if ($tempNames[$officeName] !== $officeCode) {
                        throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\"): Duplicate office name '{$officeName}' found within the uploaded file with a different code.");
                    }
                    $i += $advanceCount;
                    continue;
                }
                $tempCodes[$officeCode] = $officeName;
                $tempNames[$officeName] = $officeCode;

                $extractedOffices[] = [
                    'name' => $officeName,
                    'code' => $officeCode,
                    'is_active' => $isActiveVal,
                    'cluster' => $officeClusterVal
                ];

                $i += $advanceCount;
            }

            // Save all offices to the database in a single transaction
            \DB::transaction(function () use ($extractedOffices) {
                foreach ($extractedOffices as $officeData) {
                    $office = new \App\Models\office();
                    $office->office_name = $officeData['name'];
                    $office->office_code = $officeData['code'];
                    $office->is_active = $officeData['is_active'];
                    $office->cluster = $officeData['cluster'];
                    $office->save();

                    // Insert admin log entry
                    \DB::table('sys_admin_logs')->insert([
                        'changes' => "Imported office via text file: {$officeData['name']} ({$officeData['code']})",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now(),
                    ]);
                }
            });

            $this->cancelSelection();
            $this->officeFile = null;
            $this->successMessage = "Successfully imported " . count($extractedOffices) . " office(s) from the file!";

        } catch (\Exception $e) {
            $this->errorMessage = 'Extraction failed: ' . $e->getMessage();
        }
    }

    /**
     * Selects an office from the directory to load details.
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
     * Creates a new office or updates an existing office in the database.
     */
    public function saveOfficeChanges(): void
    {
        if ($this->selectedOfficeId === null) {
            return;
        }

        $this->clearMessages();

        // Check for deactivated duplicates when creating
        $nameExcludeId = null;
        $codeExcludeId = null;

        if ($this->selectedOfficeId === -1) {
            $deactivatedByName = \App\Models\office::where('office_name', trim($this->officeName))
                ->where('is_active', false)
                ->first();
            $deactivatedByCode = \App\Models\office::where('office_code', trim($this->officeCode))
                ->where('is_active', false)
                ->first();

            $nameExcludeId = $deactivatedByName ? $deactivatedByName->id : null;
            $codeExcludeId = $deactivatedByCode ? $deactivatedByCode->id : null;
        }

        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $clusterTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_cluster') ? 'sys_cluster' : 'cluster';

        // Validation rules: unique check excludes selected record if updating, or deactivated record if creating
        $uniqueNameRule = $this->selectedOfficeId > 0
            ? "unique:{$officeTbl},office_name," . $this->selectedOfficeId . ',id'
            : ($nameExcludeId
                ? "unique:{$officeTbl},office_name," . $nameExcludeId . ',id'
                : "unique:{$officeTbl},office_name");

        $uniqueCodeRule = $this->selectedOfficeId > 0
            ? "unique:{$officeTbl},office_code," . $this->selectedOfficeId . ',id'
            : ($codeExcludeId
                ? "unique:{$officeTbl},office_code," . $codeExcludeId . ',id'
                : "unique:{$officeTbl},office_code");

        $this->validate([
            'officeName' => 'required|string|max:255|' . $uniqueNameRule,
            'officeCode' => 'required|string|max:50|' . $uniqueCodeRule,
            'officeCluster' => "nullable|string|exists:{$clusterTbl},cluster_code",
        ]);

        try {
            \DB::transaction(function () {
                if ($this->selectedOfficeId === -1) {
                    // --- CREATE MODE ---
                    // Check if a deactivated office with the same code exists — reactivate it
                    $existingOffice = \App\Models\office::where('office_code', trim($this->officeCode))
                        ->where('is_active', false)
                        ->first();

                    if ($existingOffice) {
                        // Reactivate and update the deactivated office
                        $existingOffice->update([
                            'office_name' => $this->officeName,
                            'office_code' => $this->officeCode,
                            'cluster' => $this->officeCluster ?: null,
                            'is_active' => true,
                        ]);

                        \DB::table('sys_admin_logs')->insert([
                            'changes' => "Reactivated previously soft-deleted office: {$this->officeName} ({$this->officeCode})",
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now(),
                        ]);

                        $this->selectedOfficeId = $existingOffice->id;
                        $this->successMessage = 'Previously deactivated office reactivated and updated successfully!';
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
                        $this->successMessage = 'Office entry created successfully!';
                    }
                } else {
                    // --- EDIT MODE ---
                    $office = \App\Models\office::findOrFail($this->selectedOfficeId);
                    
                    if (in_array($office->office_code, ['ORIGIN', '[H]', '[HUB]', 'HUB'])) {
                        $this->isActive = true;
                        $this->officeCode = $office->office_code;
                    }

                    // Detect if status changed
                    $statusChanged = $office->is_active != $this->isActive;

                    $office->update([
                        'office_name' => $this->officeName,
                        'office_code' => $this->officeCode,
                        'cluster' => $this->officeCluster ?: null,
                        'is_active' => $this->isActive,
                    ]);

                    // Audit Log: updated details
                    \DB::table('sys_admin_logs')->insert([
                        'changes' => "Updated office details for: {$this->officeName}",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now()
                    ]);

                    // Audit Log: status changed
                    if ($statusChanged) {
                        \DB::table('sys_admin_logs')->insert([
                            'changes' => "Toggled active status (Value: " . ($this->isActive ? '1' : '0') . ") for office: {$this->officeName}",
                            'admin_id' => auth()->id(),
                            'what_system' => 3, // Admin Console
                            'when_changes' => now()
                        ]);
                    }

                    $this->successMessage = 'Office configuration updated successfully!';
                }
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save changes: ' . $e->getMessage();
        }
    }

    /**
     * Soft-deactivates (soft-deletes) the selected office for transparency.
     */
    public function deleteOffice(): void
    {
        if ($this->selectedOfficeId === null || $this->selectedOfficeId === -1) {
            return;
        }

        $this->clearMessages();

        try {
            \DB::transaction(function () {
                $office = \App\Models\office::findOrFail($this->selectedOfficeId);
                
                if (in_array($office->office_code, ['ORIGIN', '[H]', '[HUB]', 'HUB'])) {
                    throw new \Exception("The system placeholder office '{$office->office_name}' ({$office->office_code}) cannot be deleted.");
                }

                $office->update([
                    'is_active' => false,
                ]);

                // Audit Log: soft-deleted office
                \DB::table('sys_admin_logs')->insert([
                    'changes' => "Soft-deleted office (Deactivated for transparency): {$office->office_name}",
                    'admin_id' => auth()->id(),
                    'what_system' => 3, // Admin Console
                    'when_changes' => now()
                ]);

                // Reset selection to close edit details pane but set success message
                $this->cancelSelection();
                $this->successMessage = 'Office soft-deleted successfully!';
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete office: ' . $e->getMessage();
        }
    }

    /**
     * Toggle an office ID in the bulk selection array.
     */
    public function toggleOfficeSelection(int $id): void
    {
        if (in_array($id, $this->selectedOfficeIds)) {
            $this->selectedOfficeIds = array_values(array_diff($this->selectedOfficeIds, [$id]));
        } else {
            $this->selectedOfficeIds[] = $id;
        }
    }

    /**
     * Toggle select/deselect all visible offices.
     */
    public function toggleAllOffices(): void
    {
        $visibleIds = \App\Models\office::where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]', 'HUB'])
            ->when($this->search !== '', function ($q) {
                $q->where(function ($sub) {
                    $sub->where('office_name', 'like', '%' . $this->search . '%')
                        ->orWhere('office_code', 'like', '%' . $this->search . '%');
                });
            })
            ->pluck('id')
            ->toArray();

        if (count($this->selectedOfficeIds) === count($visibleIds) && !array_diff($visibleIds, $this->selectedOfficeIds)) {
            $this->selectedOfficeIds = [];
        } else {
            $this->selectedOfficeIds = $visibleIds;
        }
    }

    /**
     * Bulk soft-delete (deactivate) all selected offices.
     */
    public function bulkDeleteOffices(): void
    {
        $this->clearMessages();

        if (empty($this->selectedOfficeIds)) {
            $this->errorMessage = 'No offices selected for deletion.';
            return;
        }

        try {
            \DB::transaction(function () {
                $offices = \App\Models\office::whereIn('id', $this->selectedOfficeIds)
                    ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]', 'HUB'])
                    ->get();

                if ($offices->isEmpty()) {
                    throw new \Exception('No valid offices found to delete.');
                }

                $names = $offices->pluck('office_name')->implode(', ');

                \App\Models\office::whereIn('id', $offices->pluck('id')->toArray())
                    ->update(['is_active' => false]);

                \DB::table('sys_admin_logs')->insert([
                    'changes' => \Str::limit("Bulk soft-deleted " . $offices->count() . " office(s): {$names}", 245),
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);
            });

            $count = count($this->selectedOfficeIds);
            $this->selectedOfficeIds = [];
            $this->cancelSelection();
            $this->successMessage = "{$count} office(s) soft-deleted (deactivated) successfully!";
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to bulk delete offices: ' . $e->getMessage();
        }
    }

    // ==========================================
    // ---- CLUSTERS DIRECTORY ACTIONS ----
    // ==========================================

    public function startCreateCluster(): void
    {
        $this->cancelSelection();
        $this->selectedClusterId = -1;
    }

    public function startImportCluster(): void
    {
        $this->cancelSelection();
        $this->selectedClusterId = -2;
    }

    public function selectCluster(int $id): void
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

    public function selectClusterHead(string $code, string $name): void
    {
        $this->clusterHead = $code;
        $this->clusterHeadSearch = $code ? "$name ($code)" : '';
        $this->showClusterHeadDropdown = false;
    }

    public function selectOfficeCluster(string $code, string $name): void
    {
        $this->officeCluster = $code;
        $this->officeClusterSearch = $code ? "$name ($code)" : '';
        $this->showOfficeClusterDropdown = false;
    }

    public function saveClusterChanges(): void
    {
        if ($this->selectedClusterId === null) {
            return;
        }

        $this->clearMessages();

        // Check for deactivated duplicates when creating
        $nameExcludeId = null;
        $codeExcludeId = null;

        if ($this->selectedClusterId === -1) {
            $deactivatedByName = \App\Models\Cluster::where('cluster_name', trim($this->clusterName))
                ->where('is_active', false)
                ->first();
            $deactivatedByCode = \App\Models\Cluster::where('cluster_code', trim($this->clusterCode))
                ->where('is_active', false)
                ->first();

            $nameExcludeId = $deactivatedByName ? $deactivatedByName->id : null;
            $codeExcludeId = $deactivatedByCode ? $deactivatedByCode->id : null;
        }

        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $clusterTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_cluster') ? 'sys_cluster' : 'cluster';

        // Validation rules: unique check excludes selected record if updating, or deactivated record if creating
        $uniqueNameRule = $this->selectedClusterId > 0 
            ? "unique:{$clusterTbl},cluster_name," . $this->selectedClusterId . ',id'
            : ($nameExcludeId
                ? "unique:{$clusterTbl},cluster_name," . $nameExcludeId . ',id'
                : "unique:{$clusterTbl},cluster_name");

        $uniqueCodeRule = $this->selectedClusterId > 0 
            ? "unique:{$clusterTbl},cluster_code," . $this->selectedClusterId . ',id'
            : ($codeExcludeId
                ? "unique:{$clusterTbl},cluster_code," . $codeExcludeId . ',id'
                : "unique:{$clusterTbl},cluster_code");

        $this->validate([
            'clusterName' => 'required|string|max:255|' . $uniqueNameRule,
            'clusterCode' => 'required|string|max:50|' . $uniqueCodeRule,
            'clusterHead' => "nullable|string|exists:{$officeTbl},office_code",
        ]);

        try {
            \DB::transaction(function () {
                if ($this->selectedClusterId === -1) {
                    // Check if a deactivated cluster with the same code exists — reactivate it
                    $existingCluster = \App\Models\Cluster::where('cluster_code', trim($this->clusterCode))
                        ->where('is_active', false)
                        ->first();

                    if ($existingCluster) {
                        // Reactivate and update the deactivated cluster
                        $existingCluster->update([
                            'cluster_name' => $this->clusterName,
                            'cluster_code' => $this->clusterCode,
                            'cluster_head' => $this->clusterHead ?: null,
                            'is_active' => true,
                        ]);

                        \DB::table('sys_admin_logs')->insert([
                            'changes' => "Reactivated previously soft-deleted cluster: {$this->clusterName} ({$this->clusterCode})",
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now(),
                        ]);

                        $this->selectedClusterId = $existingCluster->id;
                        $this->successMessage = 'Previously deactivated cluster reactivated and updated successfully!';
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
                        $this->successMessage = 'Cluster created successfully!';
                    }
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
                        \DB::table('sys_office')
                            ->where('cluster', $oldCode)
                            ->update(['cluster' => $this->clusterCode]);
                    }

                    \DB::table('sys_admin_logs')->insert([
                        'changes' => "Updated cluster details for: {$this->clusterName} ({$this->clusterCode})",
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

    public function deleteCluster(): void
    {
        if ($this->selectedClusterId === null || $this->selectedClusterId <= 0) {
            return;
        }

        $this->clearMessages();

        try {
            \DB::transaction(function () {
                $cluster = \App\Models\Cluster::findOrFail($this->selectedClusterId);

                \DB::table('sys_office')
                    ->where('cluster', $cluster->cluster_code)
                    ->update(['cluster' => null]);

                // Soft-delete the cluster (deactivate)
                $cluster->update([
                    'is_active' => false,
                ]);

                \DB::table('sys_admin_logs')->insert([
                    'changes' => "Soft-deleted cluster (Deactivated for transparency): {$cluster->cluster_name} ({$cluster->cluster_code})",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now()
                ]);

                $this->cancelSelection();
                $this->successMessage = 'Cluster soft-deleted successfully!';
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete cluster: ' . $e->getMessage();
        }
    }

    /**
     * Toggle a cluster ID in the bulk selection array.
     */
    public function toggleClusterSelection(int $id): void
    {
        if (in_array($id, $this->selectedClusterIds)) {
            $this->selectedClusterIds = array_values(array_diff($this->selectedClusterIds, [$id]));
        } else {
            $this->selectedClusterIds[] = $id;
        }
    }

    /**
     * Toggle select/deselect all visible clusters.
     */
    public function toggleAllClusters(): void
    {
        $visibleIds = \App\Models\Cluster::where('is_active', true)
            ->when($this->clusterSearch !== '', function ($q) {
                $q->where(function ($sub) {
                    $sub->where('cluster_name', 'like', '%' . $this->clusterSearch . '%')
                        ->orWhere('cluster_code', 'like', '%' . $this->clusterSearch . '%');
                });
            })
            ->pluck('id')
            ->toArray();

        if (count($this->selectedClusterIds) === count($visibleIds) && !array_diff($visibleIds, $this->selectedClusterIds)) {
            $this->selectedClusterIds = [];
        } else {
            $this->selectedClusterIds = $visibleIds;
        }
    }

    /**
     * Bulk soft-delete (deactivate) all selected clusters.
     */
    public function bulkDeleteClusters(): void
    {
        $this->clearMessages();

        if (empty($this->selectedClusterIds)) {
            $this->errorMessage = 'No clusters selected for deletion.';
            return;
        }

        try {
            \DB::transaction(function () {
                $clusters = \App\Models\Cluster::whereIn('id', $this->selectedClusterIds)->get();

                if ($clusters->isEmpty()) {
                    throw new \Exception('No valid clusters found to delete.');
                }

                $names = $clusters->pluck('cluster_name')->implode(', ');

                // Set associated offices cluster to null
                \DB::table('sys_office')
                    ->whereIn('cluster', $clusters->pluck('cluster_code')->toArray())
                    ->update(['cluster' => null]);

                \App\Models\Cluster::whereIn('id', $clusters->pluck('id')->toArray())
                    ->update(['is_active' => false]);

                \DB::table('sys_admin_logs')->insert([
                    'changes' => \Str::limit("Bulk soft-deleted " . $clusters->count() . " cluster(s): {$names}", 245),
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);
            });

            $count = count($this->selectedClusterIds);
            $this->selectedClusterIds = [];
            $this->cancelSelection();
            $this->successMessage = "{$count} cluster(s) soft-deleted (deactivated) successfully!";
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to bulk delete clusters: ' . $e->getMessage();
        }
    }

    public function importClusters(): void
    {
        $this->clearMessages();

        $this->validate([
            'clusterFile' => 'required|file|extensions:txt,text|max:2048',
        ], [
            'clusterFile.required' => 'Please select a text file to upload.',
            'clusterFile.extensions' => 'The file must be a plain text file (.txt).',
            'clusterFile.max' => 'The file size must be less than 2MB.',
        ]);

        try {
            $content = $this->clusterFile->get();
            if ($content === false || $content === null) {
                $content = @file_get_contents($this->clusterFile->getRealPath());
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

            $extractedClusters = [];
            $tempNames = [];
            $tempCodes = [];

            $i = 0;
            while ($i < count($lines)) {
                $nameLine = $lines[$i];

                if (!str_starts_with($nameLine['text'], '=')) {
                    throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\") must start with '=' to indicate the start of a cluster name.");
                }

                if ($i + 1 >= count($lines)) {
                    throw new \Exception("Incomplete cluster definition starting at Line {$lines[$i]['num']}. Each cluster must contain at least a name and code.");
                }

                $codeLine = $lines[$i + 1];

                if (!str_ends_with($nameLine['text'], ';')) {
                    throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\") must end with a semicolon ';'.");
                }
                if (!str_ends_with($codeLine['text'], ';')) {
                    throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\") must end with a semicolon ';'.");
                }

                $cName = trim(substr($nameLine['text'], 1, -1));
                $cCode = trim(substr($codeLine['text'], 0, -1));

                if (empty($cName)) {
                    throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\"): Cluster name cannot be empty.");
                }
                if (empty($cCode)) {
                    throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\"): Cluster code cannot be empty.");
                }

                $cHead = null;
                $cIsActive = true;
                $advanceCount = 2;

                if ($i + 2 < count($lines)) {
                    $thirdLine = $lines[$i + 2];
                    if (!str_starts_with($thirdLine['text'], '=')) {
                        $val = trim(substr($thirdLine['text'], 0, -1));

                        if (strcasecmp($val, 'true') === 0 || strcasecmp($val, 'false') === 0) {
                            $cIsActive = strcasecmp($val, 'true') === 0;
                            $advanceCount = 3;
                        } else {
                            if (str_ends_with($thirdLine['text'], ';')) {
                                $office = \DB::table('sys_office')
                                    ->where('office_code', $val)
                                    ->orWhere('office_name', $val)
                                    ->first();

                                if ($office) {
                                    $cHead = $office->office_code;
                                    $advanceCount = 3;

                                    if ($i + 3 < count($lines)) {
                                        $fourthLine = $lines[$i + 3];
                                        if (!str_starts_with($fourthLine['text'], '=')) {
                                            $val4 = trim(substr($fourthLine['text'], 0, -1));
                                            if (strcasecmp($val4, 'true') === 0 || strcasecmp($val4, 'false') === 0) {
                                                $cIsActive = strcasecmp($val4, 'true') === 0;
                                                $advanceCount = 4;
                                            }
                                        }
                                    }
                                } else {
                                    throw new \Exception("Line {$thirdLine['num']}: Cluster head office '{$val}' does not exist in the offices database.");
                                }
                            }
                        }
                    }
                }

                for ($k = 2; $k < $advanceCount; $k++) {
                    $consumedLine = $lines[$i + $k];
                    if (!str_ends_with($consumedLine['text'], ';')) {
                        throw new \Exception("Line {$consumedLine['num']} (\"{$consumedLine['text']}\") must end with a semicolon ';'.");
                    }
                }

                if (in_array($cName, $tempNames)) {
                    throw new \Exception("Duplicate cluster name '{$cName}' found in import file.");
                }
                if (in_array($cCode, $tempCodes)) {
                    throw new \Exception("Duplicate cluster code '{$cCode}' found in import file.");
                }

                $tempNames[] = $cName;
                $tempCodes[] = $cCode;

                $existingByName = \DB::table('sys_cluster')->where('cluster_name', $cName)->first();
                $existingByCode = \DB::table('sys_cluster')->where('cluster_code', $cCode)->first();

                if ($existingByName || $existingByCode) {
                    if ($existingByName && $existingByCode && $existingByName->id === $existingByCode->id) {
                        $i += $advanceCount;
                        continue;
                    }

                    if ($existingByName && (!$existingByCode || $existingByName->cluster_code !== $cCode)) {
                        throw new \Exception("Conflict detected. The cluster name '{$cName}' already exists in the database with a different code ('{$existingByName->cluster_code}').");
                    }
                    if ($existingByCode && (!$existingByName || $existingByCode->cluster_name !== $cName)) {
                        throw new \Exception("Conflict detected. The cluster code '{$cCode}' already exists in the database with a different name ('{$existingByCode->cluster_name}').");
                    }
                }

                $extractedClusters[] = [
                    'name' => $cName,
                    'code' => $cCode,
                    'head' => $cHead,
                    'is_active' => $cIsActive
                ];

                $i += $advanceCount;
            }

            \DB::transaction(function () use ($extractedClusters) {
                foreach ($extractedClusters as $cData) {
                    $cluster = new \App\Models\Cluster();
                    $cluster->cluster_name = $cData['name'];
                    $cluster->cluster_code = $cData['code'];
                    $cluster->cluster_head = $cData['head'];
                    $cluster->is_active = $cData['is_active'];
                    $cluster->save();

                    \DB::table('sys_admin_logs')->insert([
                        'changes' => "Imported cluster via text file: {$cData['name']} ({$cData['code']})",
                        'admin_id' => auth()->id(),
                        'what_system' => 3,
                        'when_changes' => now()
                    ]);
                }
            });

            $this->cancelSelection();
            $this->clusterFile = null;
            $this->successMessage = "Successfully imported " . count($extractedClusters) . " cluster(s) from the file!";

        } catch (\Exception $e) {
            $this->errorMessage = 'Extraction failed: ' . $e->getMessage();
        }
    }

    /**
     * Computed Lifecycle Provider.
     */
    public function with(): array
    {
        $officeQuery = \App\Models\office::query()
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]', 'HUB']);
        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $officeQuery->where(function($q) use ($searchVal) {
                $q->where('office_name', 'like', $searchVal)
                  ->orWhere('office_code', 'like', $searchVal);
            });
        }
        $offices = $officeQuery->orderBy('office_name', 'asc')->paginate(20);

        $clusterQuery = \App\Models\Cluster::query()->where('is_active', true);
        if ($this->clusterSearch !== '') {
            $cSearchVal = '%' . $this->clusterSearch . '%';
            $clusterQuery->where(function($q) use ($cSearchVal) {
                $q->where('cluster_name', 'like', $cSearchVal)
                  ->orWhere('cluster_code', 'like', $cSearchVal);
            });
        }
        $clusters = $clusterQuery->orderBy('cluster_name', 'asc')->paginate(20);

        // Get offices assigned to the currently selected cluster
        $clusterOffices = collect();
        if ($this->selectedClusterId > 0) {
            $currentCluster = \App\Models\Cluster::find($this->selectedClusterId);
            if ($currentCluster) {
                $clusterOffices = \App\Models\office::where('cluster', $currentCluster->cluster_code)
                    ->where('is_active', true)
                    ->orderBy('office_name', 'asc')
                    ->get();
            }
        }

        $otherOfficeQuery = \DB::table('dts_source_office as so')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as creator', 'creator.office_code', '=', 'so.created_by_office')
            ->select('so.*', 'creator.office_name as creator_office_name')
            ->where('so.is_active', true);
        if ($this->otherOfficeSearch !== '') {
            $oSearchVal = '%' . $this->otherOfficeSearch . '%';
            $otherOfficeQuery->where(function($q) use ($oSearchVal) {
                $q->where('so.s_office_name', 'like', $oSearchVal)
                  ->orWhere('so.s_office_code', 'like', $oSearchVal);
            });
        }
        $otherOffices = $otherOfficeQuery->orderBy('so.s_office_name', 'asc')->paginate(20);

        return [
            'offices' => $offices,
            'clusters' => $clusters,
            'clusterOffices' => $clusterOffices,
            'otherOffices' => $otherOffices,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/activity_logs.css', 'resources/css/admin/accounts_offices.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .tabs-header {
            display: flex;
            gap: 12px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 20px;
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
            font-family: 'Outfit', sans-serif;
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
        .search-box-wrapper {
            position: relative;
            width: 100% !important;
            max-width: none !important;
            min-width: 0 !important;
            flex: none !important;
            margin-bottom: 12px;
        }
    </style>
@endpush

<div class="activity-logs-container">
    <!-- Tabs Header -->
    <div class="tabs-header">
        <button type="button" class="tab-btn {{ $activeTab === 'offices' ? 'active' : '' }}" wire:click="$set('activeTab', 'offices')">
            <i class="fa-solid fa-building" style="margin-right: 6px;"></i> Offices Directory
        </button>
        <button type="button" class="tab-btn {{ $activeTab === 'clusters' ? 'active' : '' }}" wire:click="$set('activeTab', 'clusters')">
            <i class="fa-solid fa-sitemap" style="margin-right: 6px;"></i> Clusters Directory
        </button>
        <button type="button" class="tab-btn {{ $activeTab === 'other_offices' ? 'active' : '' }}" wire:click="$set('activeTab', 'other_offices')">
            <i class="fa-solid fa-building-flag" style="margin-right: 6px;"></i> Other Offices
        </button>
    </div>

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
