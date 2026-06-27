<?php
/**
 * Admin Console - Accounts Management Offices Volt Component
 * 
 * This component provides an interface for administrators to manage office entities.
 * Features:
 *  - Real-time search of offices by name or code
 *  - Creation of new office entries
 *  - Editing of existing office entries (name, code, active status)
 *  - Soft-deactivation (suspension) of offices to maintain system transparency
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Admin Console - Offices')] class extends Component {
    use WithFileUploads;

    /** @var string Holds the active search input query */
    public string $search = '';

    /** @var int|null ID of the selected office. (null = placeholder, -1 = create mode, >0 = edit mode, -2 = import mode) */
    public ?int $selectedOfficeId = null;

    /** @var mixed Holds the uploaded office text file */
    public $officeFile;

    // Form fields
    /** @var string Holds the office name */
    public string $officeName = '';

    /** @var string Holds the office short code */
    public string $officeCode = '';

    /** @var bool Active status flag of the office */
    public bool $isActive = true;

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
        $this->isActive = true;
        $this->officeFile = null;
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
                // We expect at least 2 lines for an office (Name, Code)
                if ($i + 1 >= count($lines)) {
                    throw new \Exception("Incomplete office definition starting at Line {$lines[$i]['num']}. Each office must contain at least a name and code.");
                }

                $nameLine = $lines[$i];
                $codeLine = $lines[$i + 1];

                // 1. Verify semicolons
                if (!str_ends_with($nameLine['text'], ';')) {
                    throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\") must end with a semicolon ';'.");
                }
                if (!str_ends_with($codeLine['text'], ';')) {
                    throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\") must end with a semicolon ';'.");
                }

                $officeName = trim(substr($nameLine['text'], 0, -1));
                $officeCode = trim(substr($codeLine['text'], 0, -1));

                // 2. Validate empty values
                if (empty($officeName)) {
                    throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\"): Office name cannot be empty.");
                }
                if (empty($officeCode)) {
                    throw new \Exception("Line {$codeLine['num']} (\"{$codeLine['text']}\"): Office code cannot be empty.");
                }

                // Determine advance count and active status early
                $isActiveVal = true;
                $nextIdx = $i + 2;
                $hasStatusLine = false;
                if ($nextIdx < count($lines)) {
                    $candidateLine = $lines[$nextIdx];
                    $candidateText = trim(substr($candidateLine['text'], 0, -1));

                    if (strcasecmp($candidateText, 'true') === 0 || strcasecmp($candidateText, 'false') === 0) {
                        $hasStatusLine = true;
                        $isActiveVal = (strcasecmp($candidateText, 'true') === 0);
                    }
                }
                $advanceCount = $hasStatusLine ? 3 : 2;

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
                    // Perfect file duplicate: skip
                    $i += $advanceCount;
                    continue;
                }
                if (isset($tempNames[$officeName])) {
                    if ($tempNames[$officeName] !== $officeCode) {
                        throw new \Exception("Line {$nameLine['num']} (\"{$nameLine['text']}\"): Duplicate office name '{$officeName}' found within the uploaded file with a different code.");
                    }
                    // Perfect file duplicate: skip
                    $i += $advanceCount;
                    continue;
                }
                $tempCodes[$officeCode] = $officeName;
                $tempNames[$officeName] = $officeCode;

                // 5. Handle semicolon validation on optional 3rd line
                if ($advanceCount === 3) {
                    $candidateLine = $lines[$nextIdx];
                    if (!str_ends_with($candidateLine['text'], ';')) {
                        throw new \Exception("Line {$candidateLine['num']} (\"{$candidateLine['text']}\") must end with a semicolon ';'.");
                    }
                }

                $extractedOffices[] = [
                    'name' => $officeName,
                    'code' => $officeCode,
                    'is_active' => $isActiveVal
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
     * 
     * @param int $id The ID of the office record (office.id)
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
        }
    }

    /**
     * Creates a new office or updates an existing office in the database.
     * Wraps modifications in a Transaction to ensure data integrity.
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
        ]);

        try {
            \DB::transaction(function () {
                if ($this->selectedOfficeId === -1) {
                    // --- CREATE MODE ---
                    $office = new \App\Models\office();
                    $office->office_name = $this->officeName;
                    $office->office_code = $this->officeCode;
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
                    
                    if ($office->office_code === 'ORIGIN') {
                        $this->isActive = true;
                        $this->officeCode = 'ORIGIN';
                    }

                    // Detect if status changed
                    $statusChanged = $office->is_active != $this->isActive;

                    $office->update([
                        'office_name' => $this->officeName,
                        'office_code' => $this->officeCode,
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
                
                if ($office->office_code === 'ORIGIN') {
                    throw new \Exception("The system placeholder office 'Originated Office' (ORIGIN) cannot be deleted.");
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

    /**
     * Computed Lifecycle Provider - returns list of offices.
     * 
     * @return array Contains offices collection
     */
    public function with(): array
    {
        // Only return active offices in the directory to match deletion expectations
        $query = \App\Models\office::query()->where('is_active', true);

        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $query->where(function($q) use ($searchVal) {
                $q->where('office_name', 'like', $searchVal)
                  ->orWhere('office_code', 'like', $searchVal);
            });
        }

        $offices = $query->orderBy('office_name', 'asc')->get();

        return [
            'offices' => $offices,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/accounts_offices.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="admin-offices-container">
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
                        @if($office->is_active)
                            <span class="office-status-badge active-badge">Active</span>
                        @else
                            <span class="office-status-badge inactive-badge">Suspended</span>
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
                            <br>• Line 1: <code>&lt;office name&gt;;</code>
                            <br>• Line 2: <code>&lt;office short code&gt;;</code>
                            <br>• Line 3 (Optional): <code>&lt;active status (true/false)&gt;;</code> (e.g. <code>true;</code> or <code>false;</code>)
                            <br>• Lines starting with <code>#</code> are classified as comments and will be ignored.
                            <br><br>
                            <em>Note: If Line 3 is omitted, the office status defaults to active (true).</em>
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
                            <input type="text" class="form-input" placeholder="e.g. CCS" wire:model="officeCode" {{ $officeCode === 'ORIGIN' ? 'disabled' : '' }}>
                            @error('officeCode') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
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
                                        <input type="checkbox" wire:model="isActive" {{ $officeCode === 'ORIGIN' ? 'disabled' : '' }}>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                            </div>
                        @endif
                    </form>
                </div>

                <!-- Footer Actions -->
                <div class="details-footer">
                    @if($selectedOfficeId > 0 && $officeCode !== 'ORIGIN')
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
