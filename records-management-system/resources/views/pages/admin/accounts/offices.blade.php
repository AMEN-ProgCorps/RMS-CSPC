<?php
/**
 * Admin Console - Accounts Management Offices & Clusters Volt Component
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Str;

new #[Layout('layouts.admin')] #[Title('Admin Console - Offices & Clusters')] class extends Component {
    use WithFileUploads;

    /** @var string Active tab: 'offices' or 'clusters' */
    public string $activeTab = 'offices';

    // ---- OFFICES DIRECTORY PROPERTIES ----
    public string $search = '';
    public ?int $selectedOfficeId = null;
    public $officeFile;

    // Office Form fields
    public string $officeName = '';
    public string $officeCode = '';
    public string $officeCluster = '';
    public bool $isActive = true;

    // ---- CLUSTERS DIRECTORY PROPERTIES ----
    public string $clusterSearch = '';
    public ?int $selectedClusterId = null;
    public $clusterFile;

    // Cluster Form fields
    public string $clusterName = '';
    public string $clusterCode = '';
    public string $clusterHead = '';
    public bool $clusterIsActive = true;

    // Toast notifications
    public string $successMessage = '';
    public string $errorMessage = '';

    /**
     * Component mount hook - initializes the default view.
     */
    public function mount(): void
    {
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

        $this->selectedClusterId = null;
        $this->clusterName = '';
        $this->clusterCode = '';
        $this->clusterHead = '';
        $this->clusterIsActive = true;
        $this->clusterFile = null;

        $this->clearMessages();
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
            'officeFile' => 'required|file|mimes:txt|max:1024',
        ], [
            'officeFile.required' => 'Please select a text file to upload.',
            'officeFile.mimes' => 'The file must be a plain text file (.txt).',
            'officeFile.max' => 'The file size must be less than 1MB.',
        ]);

        try {
            $path = $this->officeFile->getRealPath();
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
                                        $clusterExists = \DB::table('cluster')->where('cluster_code', $fourthVal)->exists();
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
                                $clusterExists = \DB::table('cluster')->where('cluster_code', $thirdVal)->exists();
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
                $officeByCode = \DB::table('office')->where('office_code', $officeCode)->first();
                $officeByName = \DB::table('office')->where('office_name', $officeName)->first();

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
                    \DB::table('admin_logs')->insert([
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

        // Validation rules: unique check excludes selected record if updating
        $uniqueNameRule = $this->selectedOfficeId > 0 
            ? 'unique:office,office_name,' . $this->selectedOfficeId . ',id'
            : 'unique:office,office_name';

        $uniqueCodeRule = $this->selectedOfficeId > 0 
            ? 'unique:office,office_code,' . $this->selectedOfficeId . ',id'
            : 'unique:office,office_code';

        $this->validate([
            'officeName' => 'required|string|max:255|' . $uniqueNameRule,
            'officeCode' => 'required|string|max:50|' . $uniqueCodeRule,
            'officeCluster' => 'nullable|string|exists:cluster,cluster_code',
        ]);

        try {
            \DB::transaction(function () {
                if ($this->selectedOfficeId === -1) {
                    // --- CREATE MODE ---
                    $office = new \App\Models\office();
                    $office->office_name = $this->officeName;
                    $office->office_code = $this->officeCode;
                    $office->cluster = $this->officeCluster ?: null;
                    $office->is_active = true; // Always active on initial creation
                    $office->save();

                    // Audit Log: created office
                    \DB::table('admin_logs')->insert([
                        'changes' => "Created office: {$this->officeName} ({$this->officeCode})",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now()
                    ]);
                    
                    // Update state to newly created ID
                    $this->selectedOfficeId = $office->id;
                    $this->successMessage = 'Office entry created successfully!';
                } else {
                    // --- EDIT MODE ---
                    $office = \App\Models\office::findOrFail($this->selectedOfficeId);
                    
                    if ($office->office_code === 'ORIGIN' || $office->office_code === '[H]') {
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
                    \DB::table('admin_logs')->insert([
                        'changes' => "Updated office details for: {$this->officeName}",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now()
                    ]);

                    // Audit Log: status changed
                    if ($statusChanged) {
                        \DB::table('admin_logs')->insert([
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
                
                if ($office->office_code === 'ORIGIN' || $office->office_code === '[H]') {
                    throw new \Exception("The system placeholder office '{$office->office_name}' ({$office->office_code}) cannot be deleted.");
                }

                $office->update([
                    'is_active' => false,
                ]);

                // Audit Log: soft-deleted office
                \DB::table('admin_logs')->insert([
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
        }
    }

    public function saveClusterChanges(): void
    {
        if ($this->selectedClusterId === null) {
            return;
        }

        $this->clearMessages();

        $uniqueNameRule = $this->selectedClusterId > 0 
            ? 'unique:cluster,cluster_name,' . $this->selectedClusterId . ',id'
            : 'unique:cluster,cluster_name';

        $uniqueCodeRule = $this->selectedClusterId > 0 
            ? 'unique:cluster,cluster_code,' . $this->selectedClusterId . ',id'
            : 'unique:cluster,cluster_code';

        $this->validate([
            'clusterName' => 'required|string|max:255|' . $uniqueNameRule,
            'clusterCode' => 'required|string|max:50|' . $uniqueCodeRule,
            'clusterHead' => 'nullable|string|exists:office,office_code',
        ]);

        try {
            \DB::transaction(function () {
                if ($this->selectedClusterId === -1) {
                    $cluster = new \App\Models\Cluster();
                    $cluster->cluster_name = $this->clusterName;
                    $cluster->cluster_code = $this->clusterCode;
                    $cluster->cluster_head = $this->clusterHead ?: null;
                    $cluster->is_active = true;
                    $cluster->save();

                    \DB::table('admin_logs')->insert([
                        'changes' => "Created cluster: {$this->clusterName} ({$this->clusterCode})",
                        'admin_id' => auth()->id(),
                        'what_system' => 3,
                        'when_changes' => now()
                    ]);

                    $this->selectedClusterId = $cluster->id;
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
                        \DB::table('office')
                            ->where('cluster', $oldCode)
                            ->update(['cluster' => $this->clusterCode]);
                    }

                    \DB::table('admin_logs')->insert([
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

                \DB::table('office')
                    ->where('cluster', $cluster->cluster_code)
                    ->update(['cluster' => null]);

                \DB::table('admin_logs')->insert([
                    'changes' => "Deleted cluster: {$cluster->cluster_name} ({$cluster->cluster_code})",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now()
                ]);

                $cluster->delete();

                $this->cancelSelection();
                $this->successMessage = 'Cluster deleted successfully!';
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete cluster: ' . $e->getMessage();
        }
    }

    public function importClusters(): void
    {
        $this->clearMessages();

        $this->validate([
            'clusterFile' => 'required|file|mimes:txt|max:1024',
        ], [
            'clusterFile.required' => 'Please select a text file to upload.',
            'clusterFile.mimes' => 'The file must be a plain text file (.txt).',
            'clusterFile.max' => 'The file size must be less than 1MB.',
        ]);

        try {
            $path = $this->clusterFile->getRealPath();
            $content = file_get_contents($path);

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
                                $office = \DB::table('office')
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

                $existingByName = \DB::table('cluster')->where('cluster_name', $cName)->first();
                $existingByCode = \DB::table('cluster')->where('cluster_code', $cCode)->first();

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

                    \DB::table('admin_logs')->insert([
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
        $officeQuery = \App\Models\office::query()->where('is_active', true);
        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $officeQuery->where(function($q) use ($searchVal) {
                $q->where('office_name', 'like', $searchVal)
                  ->orWhere('office_code', 'like', $searchVal);
            });
        }
        $offices = $officeQuery->orderBy('office_name', 'asc')->get();

        $clusterQuery = \App\Models\Cluster::query();
        if ($this->clusterSearch !== '') {
            $cSearchVal = '%' . $this->clusterSearch . '%';
            $clusterQuery->where(function($q) use ($cSearchVal) {
                $q->where('cluster_name', 'like', $cSearchVal)
                  ->orWhere('cluster_code', 'like', $cSearchVal);
            });
        }
        $clusters = $clusterQuery->orderBy('cluster_name', 'asc')->get();

        return [
            'offices' => $offices,
            'clusters' => $clusters,
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
    </div>

    @if($activeTab === 'offices')
        <div class="admin-offices-container" wire:key="tab-offices-view">
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
                
                <div class="offices-list">
                    @forelse($offices as $office)
                        @php
                            $officeInitials = strtoupper(substr($office->office_code ?: '?', 0, 3));
                        @endphp
                        <div class="office-item-card {{ $selectedOfficeId === $office->id ? 'active' : '' }}" wire:key="office-{{ $office->id }}" wire:click="selectOffice({{ $office->id }})">
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
            </div>

            <!-- Right Pane: Office Form Configurator -->
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

                @if($selectedOfficeId)
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
                        </div>

                        <!-- Body Form -->
                        <div class="details-body">
                            <div class="form-group">
                                <label class="form-label" for="officeFileInput" style="font-weight: 600; color: #334155; margin-bottom: 8px; display: block;">Select Office Text File (.txt)</label>
                                <input type="file" 
                                       id="officeFileInput" 
                                       wire:model="officeFile" 
                                       accept=".txt" 
                                       style="padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; font-size: 13px; font-family: 'Inter', sans-serif;">
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
                            <button type="button" class="btn-save" style="background-color: #0284c7; border-color: #0284c7;" wire:click="importOffices">
                                <i class="fa-solid fa-file-import"></i> Extract Office Data
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
                                    <input type="text" class="form-input" placeholder="e.g. CCS" wire:model="officeCode" {{ in_array($officeCode, ['ORIGIN', '[H]']) ? 'disabled' : '' }}>
                                    @error('officeCode') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                                </div>

                                <!-- Cluster Dropdown -->
                                <div class="form-group">
                                    <span class="form-label">Cluster</span>
                                    <select class="form-input" wire:model="officeCluster" style="background-color: white;">
                                        <option value="">None (No Cluster)</option>
                                        @foreach(\App\Models\Cluster::where('is_active', true)->orderBy('cluster_name')->get() as $clusterObj)
                                            <option value="{{ $clusterObj->cluster_code }}">{{ $clusterObj->cluster_name }} ({{ $clusterObj->cluster_code }})</option>
                                        @endforeach
                                    </select>
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
                                                <input type="checkbox" wire:model="isActive" {{ in_array($officeCode, ['ORIGIN', '[H]']) ? 'disabled' : '' }}>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                @endif
                            </form>
                        </div>

                        <!-- Footer Actions -->
                        <div class="details-footer">
                            @if($selectedOfficeId > 0 && !in_array($officeCode, ['ORIGIN', '[H]']))
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
                @else
                    <!-- Selection Placeholder -->
                    <div class="details-placeholder">
                        <i class="fa-solid fa-building"></i>
                        <h3>Offices Configuration</h3>
                        <p>Click on any office in the directory list to edit its details. Click the <strong>New Office</strong> button above to construct a new office entry from scratch, or click <strong>Import File</strong> to upload multiple offices at once.</p>
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="admin-offices-container" wire:key="tab-clusters-view">
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
                
                <div class="offices-list">
                    @forelse($clusters as $cluster)
                        @php
                            $clusterInitials = strtoupper(substr($cluster->cluster_code ?: '?', 0, 3));
                        @endphp
                        <div class="office-item-card {{ $selectedClusterId === $cluster->id ? 'active' : '' }}" wire:key="cluster-{{ $cluster->id }}" wire:click="selectCluster({{ $cluster->id }})">
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
            </div>

            <!-- Right Pane: Cluster Form Configurator -->
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

                @if($selectedClusterId)
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
                                       accept=".txt" 
                                       style="padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; font-size: 13px; font-family: 'Inter', sans-serif;">
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
                            <button type="button" class="btn-save" style="background-color: #0284c7; border-color: #0284c7;" wire:click="importClusters">
                                <i class="fa-solid fa-file-import"></i> Extract Cluster Data
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
                                <div class="form-group">
                                    <span class="form-label">Cluster Head (Office)</span>
                                    <select class="form-input" wire:model="clusterHead" style="background-color: white;">
                                        <option value="">None (No Head)</option>
                                        @foreach($offices as $headOfficeOption)
                                            <option value="{{ $headOfficeOption->office_code }}">{{ $headOfficeOption->office_name }} ({{ $headOfficeOption->office_code }})</option>
                                        @endforeach
                                    </select>
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
                                <button type="button" class="btn-delete" wire:click="deleteCluster" style="margin-right: auto;">
                                    <i class="fa-solid fa-trash-can"></i> Delete Cluster
                                </button>
                            @endif
                            <button type="button" class="btn-cancel" wire:click="cancelSelection">Cancel</button>
                            <button type="button" class="btn-save" wire:click="saveClusterChanges">
                                <i class="fa-solid fa-floppy-disk"></i> Save Configuration
                            </button>
                        </div>
                    @endif
                @else
                    <!-- Selection Placeholder -->
                    <div class="details-placeholder">
                        <i class="fa-solid fa-sitemap" style="color: #cbd5e1; font-size: 48px;"></i>
                        <h3>Clusters Configuration</h3>
                        <p>Click on any cluster in the directory list to edit its details. Click the <strong>New Cluster</strong> button above to construct a new cluster from scratch, or click <strong>Import File</strong> to upload multiple clusters at once.</p>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
