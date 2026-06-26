<?php
/**
 * Admin Console - Add/Update Subsystems Volt Component
 * 
 * Provides an editor to register new subsystems or update the version of existing subsystems.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Register & Update Subsystems')] class extends Component {
    /** @var string|null Selected subsystem option (empty, 'new', or numeric ID) */
    public ?string $selectedOption = '';

    /** @var string The name of the subsystem */
    public string $subsystemName = '';

    /** @var string The version number */
    public string $subsystemVersion = '';

    /** @var string Success message */
    public string $successMessage = '';

    /** @var string Error message */
    public string $errorMessage = '';

    /**
     * Clear alert messages.
     */
    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * Reset the form inputs.
     */
    public function resetForm(): void
    {
        $this->selectedOption = '';
        $this->subsystemName = '';
        $this->subsystemVersion = '';
        $this->clearMessages();
    }

    /**
     * Triggered when the selected subsystem option changes.
     */
    public function updatedSelectedOption(string $value): void
    {
        $this->clearMessages();
        
        if ($value === '' || $value === 'new') {
            $this->subsystemName = '';
            $this->subsystemVersion = '';
            return;
        }

        $subsystem = \DB::table('subsystems')->where('subsystem_id', $value)->first();
        if ($subsystem) {
            $this->subsystemName = $subsystem->subsystem_name;
            $this->subsystemVersion = $subsystem->subsystem_version;
        }
    }

    /**
     * Save/Update the subsystem configuration.
     */
    public function saveSubsystem(): void
    {
        $this->clearMessages();

        // 1. Validation
        if ($this->selectedOption === '') {
            $this->errorMessage = 'Please select an option from the dropdown.';
            return;
        }

        if ($this->selectedOption === 'new') {
            $this->validate([
                'subsystemName' => 'required|string|max:255|unique:subsystems,subsystem_name',
                'subsystemVersion' => ['required', 'string', 'max:50', 'regex:/^\d+\.\d+\.\d+$/'],
            ], [
                'subsystemName.unique' => 'This subsystem is already registered.',
                'subsystemVersion.regex' => 'Version must follow semantic versioning (e.g. 1.0.0).',
            ]);
        } else {
            $this->validate([
                'subsystemVersion' => ['required', 'string', 'max:50', 'regex:/^\d+\.\d+\.\d+$/'],
            ], [
                'subsystemVersion.regex' => 'Version must follow semantic versioning (e.g. 1.0.0).',
            ]);
        }

        // 2. Perform DB transaction
        try {
            \DB::transaction(function () {
                if ($this->selectedOption === 'new') {
                    // Create mode
                    $id = \DB::table('subsystems')->insertGetId([
                        'subsystem_name' => trim($this->subsystemName),
                        'subsystem_version' => trim($this->subsystemVersion),
                        'is_active' => true,
                        'created_at' => now(),
                        'update_at' => now(),
                    ]);

                    // Insert version log
                    \DB::table('subsystem_versions_log')->insert([
                        'subsystem_key' => $id,
                        'version_change' => trim($this->subsystemVersion),
                        'changes_on' => now(),
                    ]);

                    // Insert admin audit log
                    \DB::table('admin_logs')->insert([
                        'changes' => "Registered new subsystem: " . trim($this->subsystemName) . " (Version: " . trim($this->subsystemVersion) . ")",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now(),
                    ]);

                    $this->successMessage = 'Subsystem successfully registered!';
                } else {
                    // Update mode
                    $subsystem = \DB::table('subsystems')->where('subsystem_id', $this->selectedOption)->first();
                    if (!$subsystem) {
                        throw new \Exception('Subsystem not found.');
                    }

                    $oldVersion = $subsystem->subsystem_version;
                    $newVersion = trim($this->subsystemVersion);

                    if ($oldVersion !== $newVersion) {
                        // Update version
                        \DB::table('subsystems')->where('subsystem_id', $this->selectedOption)->update([
                            'subsystem_version' => $newVersion,
                            'update_at' => now(),
                        ]);

                        // Insert version log
                        \DB::table('subsystem_versions_log')->insert([
                            'subsystem_key' => $this->selectedOption,
                            'version_change' => $newVersion,
                            'changes_on' => now(),
                        ]);

                        // Insert admin audit log
                        \DB::table('admin_logs')->insert([
                            'changes' => "Updated subsystem version for '{$subsystem->subsystem_name}' from {$oldVersion} to {$newVersion}",
                            'admin_id' => auth()->id(),
                            'what_system' => 3, // Admin Console
                            'when_changes' => now(),
                        ]);

                        $this->successMessage = 'Subsystem version updated successfully!';
                    } else {
                        $this->successMessage = 'No changes detected. Version is already ' . $newVersion;
                    }
                }
            });

            // Reset selection to clear form
            $this->resetForm();
        } catch (\Exception $e) {
            $this->errorMessage = 'Error saving subsystem: ' . $e->getMessage();
        }
    }

    /**
     * Pass subsystems list to the template.
     */
    public function with(): array
    {
        $subsystems = \DB::table('subsystems')->orderBy('subsystem_name')->get();
        return [
            'subsystems' => $subsystems,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/activity_logs.css', 'resources/css/admin/subsystems.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="page-header">
        <h1>Register & Update Subsystems</h1>
        <p>Register a new system module or bump the version of an existing module.</p>
    </div>

    <!-- Alert Banners -->
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

    <!-- Form Card -->
    <div class="subsystem-form-card">
        <form wire:submit.prevent="saveSubsystem">
            
            <!-- Selection Dropdown -->
            <div class="form-group">
                <label for="subsystemSelect">Choose Action / Subsystem</label>
                <select id="subsystemSelect" wire:model.live="selectedOption">
                    <option value="">-- Choose Option --</option>
                    <option value="new">Register New Subsystem</option>
                    @foreach($subsystems as $sub)
                        <option value="{{ $sub->subsystem_id }}">Update Version: {{ $sub->subsystem_name }} (Current: v{{ $sub->subsystem_version }})</option>
                    @endforeach
                </select>
            </div>

            @if($selectedOption !== '')
                <!-- Subsystem Name -->
                <div class="form-group">
                    <label for="subName">Subsystem Name</label>
                    <input type="text" 
                           id="subName" 
                           placeholder="e.g. Document Tracking System" 
                           wire:model="subsystemName"
                           @if($selectedOption !== 'new') disabled @endif>
                    @error('subsystemName') <span class="error-message">{{ $message }}</span> @enderror
                </div>

                <!-- Version -->
                <div class="form-group">
                    <label for="subVersion">Version Number</label>
                    <input type="text" 
                           id="subVersion" 
                           placeholder="e.g. 1.0.0" 
                           wire:model="subsystemVersion">
                    @error('subsystemVersion') <span class="error-message">{{ $message }}</span> @enderror
                </div>

                <!-- Actions -->
                <div class="form-actions">
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
</div>
