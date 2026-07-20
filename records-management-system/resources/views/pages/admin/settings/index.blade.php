<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - System Settings')] class extends Component {
    
    public bool $pagePrewarmingEnabled = true;
    public bool $emailAccessRequiredExternal = true;
    public bool $emailAccessRequiredApplication = true;
    public bool $emailAccessRequiredInternal = false;
    public bool $allowManualCompletionButton = false;
    public string $successMessage = '';
    public string $errorMessage = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_settings)) {
            $this->redirect(route('portal'));
            return;
        }

        $setting = \DB::table('system_settings')->where('key', 'page_prewarming_enabled')->value('value');
        $this->pagePrewarmingEnabled = ($setting === 'true');

        $ext = \DB::table('system_settings')->where('key', 'dts_email_access_required_external')->value('value');
        $this->emailAccessRequiredExternal = ($ext !== 'false');

        $app = \DB::table('system_settings')->where('key', 'dts_email_access_required_application')->value('value');
        $this->emailAccessRequiredApplication = ($app !== 'false');

        $int = \DB::table('system_settings')->where('key', 'dts_email_access_required_internal')->value('value');
        $this->emailAccessRequiredInternal = ($int === 'true');

        $manual = \DB::table('system_settings')->where('key', 'dts_allow_manual_completion_button')->value('value');
        $this->allowManualCompletionButton = ($manual === 'true');
    }

    public function saveSettings(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';

        try {
            \DB::transaction(function () {
                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'page_prewarming_enabled'],
                    [
                        'value' => $this->pagePrewarmingEnabled ? 'true' : 'false',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'dts_email_access_required_external'],
                    [
                        'value' => $this->emailAccessRequiredExternal ? 'true' : 'false',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'dts_email_access_required_application'],
                    [
                        'value' => $this->emailAccessRequiredApplication ? 'true' : 'false',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'dts_email_access_required_internal'],
                    [
                        'value' => $this->emailAccessRequiredInternal ? 'true' : 'false',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'dts_allow_manual_completion_button'],
                    [
                        'value' => $this->allowManualCompletionButton ? 'true' : 'false',
                        'updated_at' => now(),
                    ]
                );

                // Log activity
                \DB::table('admin_logs')->insert([
                    'changes' => "Updated system settings. Prewarming: " . ($this->pagePrewarmingEnabled ? 'true' : 'false') . 
                                 ", Email Access External: " . ($this->emailAccessRequiredExternal ? 'true' : 'false') . 
                                 ", Email Access Application: " . ($this->emailAccessRequiredApplication ? 'true' : 'false') . 
                                 ", Email Access Internal: " . ($this->emailAccessRequiredInternal ? 'true' : 'false') .
                                 ", Allow Manual Completion Button: " . ($this->allowManualCompletionButton ? 'true' : 'false'),
                    'admin_id' => auth()->id(),
                    'what_system' => 3, // Admin Console
                    'when_changes' => now(),
                ]);
            });

            $this->successMessage = 'System settings updated successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to update system settings: ' . $e->getMessage();
        }
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/activity_logs.css', 'resources/css/admin/subsystems.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .settings-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            margin-top: 24px;
            max-width: 600px;
        }
        .setting-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .setting-item:last-child {
            border-bottom: none;
        }
        .setting-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
            max-width: 75%;
        }
        .setting-title {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
        }
        .setting-desc {
            font-size: 13px;
            color: #6b7280;
        }
        /* Toggle Switch styling */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #d1d5db;
            transition: .4s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #3b82f6;
        }
        input:checked + .slider:before {
            transform: translateX(24px);
        }
        .save-btn {
            background-color: #3b82f6;
            color: #ffffff;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
        }
        .save-btn:hover {
            background-color: #2563eb;
        }
    </style>
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="page-header">
        <h1>System Settings</h1>
        <p>Manage system-wide behavior, optimizations, and general preferences.</p>
    </div>

    <!-- Alert Notifications -->
    @if ($successMessage)
        <div class="alert alert-success" style="background-color: #ecfdf5; border-left: 4px solid #10b981; color: #065f46; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px;">
            <i class="fa-solid fa-circle-check" style="margin-right: 8px;"></i>
            {{ $successMessage }}
        </div>
    @endif

    @if ($errorMessage)
        <div class="alert alert-danger" style="background-color: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px;">
            <i class="fa-solid fa-circle-xmark" style="margin-right: 8px;"></i>
            {{ $errorMessage }}
        </div>
    @endif

    <div class="settings-card">
        <form wire:submit.prevent="saveSettings">
            <!-- Setting: Page Prewarming -->
            <div class="setting-item">
                <div class="setting-details">
                    <span class="setting-title">Page Pre-warming / Health Checkup</span>
                    <span class="setting-desc">
                        Asynchronously pre-loads and caches system pages in the background right after a user logs in. 
                        This eliminates view compilation delay and prevents cold starts when navigating the application inside the Docker environment.
                    </span>
                </div>
                <label class="switch">
                    <input type="checkbox" wire:model="pagePrewarmingEnabled">
                    <span class="slider"></span>
                </label>
            </div>

            <!-- Setting: Require Email Access on External Transactions -->
            <div class="setting-item">
                <div class="setting-details">
                    <span class="setting-title">Require Email Access & Password for External Transactions</span>
                    <span class="setting-desc">
                        Enforces that users creating external transactions must specify an authorized tracking email and password. Non-CSPC users tracking this document must enter this password.
                    </span>
                </div>
                <label class="switch">
                    <input type="checkbox" wire:model="emailAccessRequiredExternal">
                    <span class="slider"></span>
                </label>
            </div>

            <!-- Setting: Require Email Access on Application Letters -->
            <div class="setting-item">
                <div class="setting-details">
                    <span class="setting-title">Require Email Access & Password for Application Letters</span>
                    <span class="setting-desc">
                        Enforces that users creating application letter transactions must specify an authorized tracking email and password.
                    </span>
                </div>
                <label class="switch">
                    <input type="checkbox" wire:model="emailAccessRequiredApplication">
                    <span class="slider"></span>
                </label>
            </div>

            <!-- Setting: Require Email Access on Internal Transactions -->
            <div class="setting-item">
                <div class="setting-details">
                    <span class="setting-title">Require Email Access & Password for Internal Transactions</span>
                    <span class="setting-desc">
                        Enforces that users creating internal transactions must specify an authorized tracking email and password. When disabled, this feature remains optional.
                    </span>
                </div>
                <label class="switch">
                    <input type="checkbox" wire:model="emailAccessRequiredInternal">
                    <span class="slider"></span>
                </label>
            </div>

            <!-- Setting: Allow Manual Complete / Forward Button -->
            <div class="setting-item">
                <div class="setting-details">
                    <span class="setting-title">Allow Manual Complete / Forward Buttons (Alternative to QR Scanning)</span>
                    <span class="setting-desc">
                        When disabled (default), manual completion buttons are hidden and transactions can only be forwarded and completed via physical QR Code scanning. When enabled, displays the manual COMPLETED / Complete Transaction button as an alternative override option.
                    </span>
                </div>
                <label class="switch">
                    <input type="checkbox" wire:model="allowManualCompletionButton">
                    <span class="slider"></span>
                </label>
            </div>

            <!-- Action buttons -->
            <button type="submit" class="save-btn">
                <i class="fa-solid fa-floppy-disk"></i> Save Settings
            </button>
        </form>
    </div>
</div>
