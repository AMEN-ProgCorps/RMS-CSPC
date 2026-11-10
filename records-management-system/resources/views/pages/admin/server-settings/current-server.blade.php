<?php

use App\Services\BackupService;
use App\Services\ServerManagementService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Current Server')] class extends Component {
    public array $metrics = [];
    public array $containers = [];
    public array $gitInfo = [];
    public bool $isMultiServer = false;
    public string $clusterRole = 'root';
    public string $serverLabel = '';
    public bool $isAutoLabel = false;
    public string $customLabelInput = '';

    public string $successMessage = '';
    public string $errorMessage = '';

    // Console output modal state
    public bool $showOutputModal = false;
    public string $modalTitle = '';
    public string $consoleOutput = '';
    public bool $isBusy = false;

    // Safety Backup Soft Protection & Escalation Sequence
    public bool $showSafetyBackupModal = false;
    public bool $isBackupModalSkippable = true;
    public int $backupSkipCount = 0; // 0 = initial (skippable, 5m), 1 = 5m reminder (skippable, 10m), 2+ = 20m mark (unskippable)
    public int $nextPromptMinutes = 5;
    public ?array $latestBackup = null;
    public bool $hasSafetyBackup = false;
    public bool $isCreatingSafetyBackup = false;

    public function boot(): void
    {
        $this->__alias = 'pages.admin.server-settings.current-server';
    }

    public function mount(ServerManagementService $service): void
    {
        $this->refreshData($service);
        $this->checkBackupRequirement();
    }

    public function checkBackupRequirement(): void
    {
        $this->latestBackup = BackupService::getLatestBackup();

        // Check if user has generated a backup during this session
        $backupCompletedAt = session()->get('safety_backup_completed_at');
        if ($backupCompletedAt) {
            $minutesSince = now()->diffInMinutes(\Carbon\Carbon::parse($backupCompletedAt));
            if ($minutesSince < 30) {
                $this->hasSafetyBackup = true;
                $this->showSafetyBackupModal = false;
                return;
            }
        }

        // If a fresh backup exists on disk within the last 10 minutes when opening page
        if (!$backupCompletedAt && $this->latestBackup && ($this->latestBackup['age_minutes'] ?? 999) < 10) {
            $this->hasSafetyBackup = true;
            $this->showSafetyBackupModal = false;
            return;
        }

        $this->hasSafetyBackup = false;
        $skipCount = (int) session()->get('safety_backup_skip_count', 0);
        $dismissedUntil = session()->get('safety_backup_dismissed_until');

        // If user dismissed and snooze timer has not elapsed yet, keep modal hidden
        if ($dismissedUntil && now()->timestamp < $dismissedUntil) {
            $this->showSafetyBackupModal = false;
            return;
        }

        // Sequence escalation:
        // 1st prompt: skippable, 5-minute snooze
        // 2nd prompt (5m mark): skippable, 10-minute snooze
        // 3rd prompt (20m mark / >= 2 skips): UNSKIPPABLE!
        $this->backupSkipCount = $skipCount;

        if ($skipCount === 0) {
            $this->isBackupModalSkippable = true;
            $this->nextPromptMinutes = 5;
            $this->showSafetyBackupModal = true;
        } elseif ($skipCount === 1) {
            $this->isBackupModalSkippable = true;
            $this->nextPromptMinutes = 10;
            $this->showSafetyBackupModal = true;
        } else {
            $this->isBackupModalSkippable = false;
            $this->nextPromptMinutes = 0;
            $this->showSafetyBackupModal = true;
        }
    }

    public function dismissSafetyBackupModal(): void
    {
        if (!$this->isBackupModalSkippable) {
            return; // Cannot dismiss at 20m unskippable stage
        }

        $skipCount = (int) session()->get('safety_backup_skip_count', 0);

        if ($skipCount === 0) {
            $snoozeMinutes = 5;
            session()->put('safety_backup_skip_count', 1);
        } elseif ($skipCount === 1) {
            $snoozeMinutes = 10;
            session()->put('safety_backup_skip_count', 2);
        } else {
            $snoozeMinutes = 20;
            session()->put('safety_backup_skip_count', 3);
        }

        session()->put('safety_backup_dismissed_until', now()->addMinutes($snoozeMinutes)->timestamp);
        $this->showSafetyBackupModal = false;
    }

    public function executeSafetyBackup(BackupService $backupService): void
    {
        $this->isCreatingSafetyBackup = true;
        $this->isBusy = true;

        $res = $backupService->createFullBackup('Safety Backup - Current Server Console');

        $this->isCreatingSafetyBackup = false;
        $this->isBusy = false;

        if (!$res['success']) {
            $this->errorMessage = "Safety backup failed: " . ($res['message'] ?? 'Unknown error');
            return;
        }

        session()->put('safety_backup_completed_at', now()->toDateTimeString());
        session()->put('server_update_safety_backup_done', true);
        session()->forget(['safety_backup_skip_count', 'safety_backup_dismissed_until']);

        $this->hasSafetyBackup = true;
        $this->showSafetyBackupModal = false;
        $this->latestBackup = BackupService::getLatestBackup();
        $this->successMessage = "Safety backup created successfully ({$res['filename']}). The system is secured.";
    }

    public function refreshData(ServerManagementService $service): void
    {
        $this->metrics = $service->getHardwareMetrics();
        $this->containers = $service->getDockerContainersStatus();
        $this->gitInfo = $service->getGitInfo();
        $this->isMultiServer = $service->isMultiServerEnabled();
        $this->clusterRole = $service->getSystemSetting('cluster_role', 'root');
        $this->serverLabel = $service->getServerLabel();
        $this->isAutoLabel = ServerManagementService::isAutoLabel();
        if (!$this->isAutoLabel) {
            $this->customLabelInput = $this->serverLabel;
        } else {
            $this->customLabelInput = '';
        }
    }

    public function toggleAutoDetect(ServerManagementService $service): void
    {
        if ($this->isAutoLabel) {
            // Disable Auto-Detect -> Unlock custom nickname editing
            $fallback = trim($this->customLabelInput) ?: $this->serverLabel;
            $service->setServerLabel($fallback);
            $this->refreshData($service);
            $this->isAutoLabel = false;
            $this->customLabelInput = $fallback;
            $this->successMessage = "Auto-Detect disabled. Custom label is now unlocked for manual editing.";
        } else {
            // Enable Auto-Detect -> Lock custom nickname to auto-detected node
            $service->setServerLabel('auto');
            $this->refreshData($service);
            $this->successMessage = "Auto-Detect enabled. Custom label is now locked to auto-detected '{$this->serverLabel}'.";
        }
    }

    public function saveCustomLabel(ServerManagementService $service): void
    {
        if ($this->isAutoLabel) {
            return;
        }

        $label = trim($this->customLabelInput);
        if (empty($label) || strtolower($label) === 'auto') {
            $this->toggleAutoDetect($service);
            return;
        }

        $service->setServerLabel($label);
        $this->refreshData($service);
        $this->successMessage = "Custom server label saved: '{$this->serverLabel}'.";
    }



    public function runGitPull(ServerManagementService $service): void
    {
        $this->isBusy = true;
        $this->modalTitle = 'Git Fetch & Pull Operation';
        
        $res = $service->runGitPull();
        $this->consoleOutput = $res['output'];
        $this->showOutputModal = true;
        $this->isBusy = false;

        if ($res['success']) {
            $this->successMessage = 'Git pull executed successfully.';
        } else {
            $this->errorMessage = 'Git pull reported errors.';
        }

        $this->refreshData($service);
    }

    public function optimizeCaches(ServerManagementService $service): void
    {
        $this->isBusy = true;
        $this->modalTitle = 'Application Cache Optimization';

        $res = $service->optimizeApp();
        $this->consoleOutput = $res['output'];
        $this->showOutputModal = true;
        $this->isBusy = false;

        if ($res['success']) {
            $this->successMessage = $res['message'];
        } else {
            $this->errorMessage = $res['message'];
        }

        $this->refreshData($service);
    }

    public function updateDatabase(ServerManagementService $service): void
    {
        $this->isBusy = true;
        $this->modalTitle = 'Database Migrations Update';

        $res = $service->updateDatabase();
        $this->consoleOutput = ($res['message'] ?? '') . "\n\n" . ($res['output'] ?? '');
        $this->showOutputModal = true;
        $this->isBusy = false;

        if ($res['success']) {
            $this->successMessage = $res['message'];
        } else {
            $this->errorMessage = $res['message'];
        }

        $this->refreshData($service);
    }

    public function renderNode(ServerManagementService $service): void
    {
        $this->isBusy = true;
        $this->modalTitle = 'Render Node & Frontend Build';

        $res = $service->renderNode();
        $this->consoleOutput = ($res['message'] ?? '') . "\n\n" . ($res['output'] ?? '');
        $this->showOutputModal = true;
        $this->isBusy = false;

        if ($res['success']) {
            $this->successMessage = $res['message'];
        } else {
            $this->errorMessage = $res['message'];
        }

        $this->refreshData($service);
    }

    public function stopLocalDb(ServerManagementService $service): void
    {
        $res = $service->stopLocalDatabaseContainers();
        $this->consoleOutput = $res['output'];
        $this->modalTitle = 'Local Database Containers Shutdown';
        $this->showOutputModal = true;
        $this->successMessage = $res['message'];
        $this->refreshData($service);
    }

    public function startLocalDb(ServerManagementService $service): void
    {
        $res = $service->startLocalDatabaseContainers();
        $this->consoleOutput = $res['output'];
        $this->modalTitle = 'Local Database Containers Startup';
        $this->showOutputModal = true;
        $this->successMessage = $res['message'];
        $this->refreshData($service);
    }

    public function closeModal(): void
    {
        $this->showOutputModal = false;
        $this->consoleOutput = '';
    }
};
?>

@push('styles')
    <style>
        [data-theme="dark"] div[style*="background: #ffffff"],
        [data-theme="dark"] div[style*="background:#ffffff"] {
            background-color: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
        }

        [data-theme="dark"] div[style*="background: #f8fafc"],
        [data-theme="dark"] div[style*="background:#f8fafc"] {
            background-color: #0b1120 !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] input[disabled] {
            background-color: #1e293b !important;
            border-color: #334155 !important;
            color: #64748b !important;
        }

        [data-theme="dark"] input:not([disabled]) {
            background-color: #0b1120 !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] button[disabled] {
            background-color: #1e293b !important;
            border-color: #334155 !important;
            color: #64748b !important;
        }

        [data-theme="dark"] h1[style*="color: #0f172a"],
        [data-theme="dark"] h2[style*="color: #0f172a"],
        [data-theme="dark"] h3[style*="color: #0f172a"],
        [data-theme="dark"] h4[style*="color: #0f172a"],
        [data-theme="dark"] div[style*="color: #0f172a"],
        [data-theme="dark"] span[style*="color: #0f172a"] {
            color: #f8fafc !important;
        }

        [data-theme="dark"] p[style*="color: #64748b"],
        [data-theme="dark"] span[style*="color: #64748b"] {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] div[style*="border-bottom: 1px solid #f1f5f9"],
        [data-theme="dark"] div[style*="border-top: 1px solid #f1f5f9"],
        [data-theme="dark"] div[style*="border: 1px solid #e2e8f0"] {
            border-color: #1e293b !important;
        }

        /* Console Modal Terminal Box */
        .terminal-box {
            background: #090d16;
            color: #38bdf8;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 12px;
            line-height: 1.5;
            padding: 16px;
            border-radius: 8px;
            max-height: 380px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
            border: 1px solid #1e293b;
        }
    </style>
@endpush

<div class="space-y-6" wire:poll.15s="checkBackupRequirement">
    <!-- Header Banner -->
    <div style="background: #ffffff; padding: 24px 28px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 4px 8px rgba(37, 99, 235, 0.15);">
                <i class="fa-solid fa-server"></i>
            </div>
            <div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h1 style="font-size: 22px; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.02em;">Current Server Management</h1>
                    <span style="font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; background: {{ $isMultiServer ? '#ecfdf5' : '#f1f5f9' }}; color: {{ $isMultiServer ? '#059669' : '#475569' }}; border: 1px solid {{ $isMultiServer ? '#a7f3d0' : '#cbd5e1' }};">
                        {{ $isMultiServer ? 'Cluster: Root Node' : 'Standalone Root' }}
                    </span>
                </div>
                <p style="font-size: 13px; color: #64748b; margin: 4px 0 0 0;">
                    Node: <strong style="color: #0f172a;">{{ $metrics['host']['hostname'] ?? 'unknown' }}</strong> &bull;
                    OS: {{ $metrics['host']['os_detail'] ?? 'Linux' }} &bull;
                    Uptime: <strong style="color: #10b981;">{{ $metrics['uptime'] ?? 'Online' }}</strong>
                </p>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" wire:click="refreshData" wire:loading.attr="disabled" style="background: #0f172a; color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-rotate" wire:loading.remove wire:target="refreshData"></i>
                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="refreshData"></i>
                <span>Refresh Metrics</span>
            </button>
        </div>
    </div>

    <!-- Node Display Label Card -->
    <div style="background: #ffffff; padding: 20px 24px; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 2px 6px rgba(0,0,0,0.02); display: flex; flex-direction: column; gap: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 42px; height: 42px; border-radius: 10px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                    <i class="fa-solid fa-tag"></i>
                </div>
                <div>
                    <div style="font-size: 14px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span>Node Display Label:</span>
                        <span style="font-size: 14px; font-weight: 800; color: #2563eb; background: #eff6ff; padding: 3px 10px; border-radius: 6px; border: 1px solid #bfdbfe; display: inline-flex; align-items: center;">
                            {{ $serverLabel }}
                        </span>
                        @if ($isAutoLabel)
                            <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; background: #ecfdf5; color: #059669; padding: 3px 8px; border-radius: 6px; border: 1px solid #a7f3d0; display: inline-flex; align-items: center; gap: 5px;">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Dynamic Auto-Detect
                            </span>
                        @else
                            <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; background: #f8fafc; color: #475569; padding: 3px 8px; border-radius: 6px; border: 1px solid #cbd5e1; display: inline-flex; align-items: center; gap: 5px;">
                                <i class="fa-solid fa-pen"></i> Custom Nickname
                            </span>
                        @endif
                    </div>
                    <p style="font-size: 12px; color: #64748b; margin: 4px 0 0 0;">
                        Auto-detects GCP VM instance name (or system hostname). Shown on the Portal as <em>"your currently at {{ $serverLabel }}"</em> and in all subsystem sidebars.
                    </p>
                </div>
            </div>

            <!-- Controls: Auto vs Custom -->
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <!-- Auto-Detect Toggle Button -->
                <button type="button" 
                        wire:click="toggleAutoDetect" 
                        wire:loading.attr="disabled" 
                        title="{{ $isAutoLabel ? 'Auto-Detect is ENABLED (Custom label is locked). Click to disable and unlock custom editing.' : 'Auto-Detect is DISABLED. Click to enable Auto-Detect and lock custom label.' }}"
                        style="height: 38px; box-sizing: border-box; background: {{ $isAutoLabel ? '#2563eb' : '#f8fafc' }}; color: {{ $isAutoLabel ? '#ffffff' : '#334155' }}; border: 1px solid {{ $isAutoLabel ? '#1d4ed8' : '#cbd5e1' }}; padding: 0 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: {{ $isAutoLabel ? '0 2px 6px rgba(37, 99, 235, 0.25)' : 'none' }}; transition: all 0.2s;">
                    <i class="fa-solid {{ $isAutoLabel ? 'fa-wand-magic-sparkles' : 'fa-power-off' }}" style="font-size: 13px;"></i>
                    <span>{{ $isAutoLabel ? 'Auto-Detect: Enabled' : 'Auto-Detect: Disabled' }}</span>
                </button>

                <!-- Custom Label Form (Locked when Auto-Detect is enabled) -->
                <form wire:submit.prevent="saveCustomLabel" style="display: flex; gap: 8px; align-items: center; margin: 0; padding: 0;">
                    <input type="text" 
                           wire:model="customLabelInput" 
                           {{ $isAutoLabel ? 'disabled' : '' }}
                           placeholder="{{ $isAutoLabel ? 'Locked (Auto Enabled)' : 'Enter custom label...' }}" 
                           title="{{ $isAutoLabel ? 'Custom label is locked because Auto-Detect is enabled. Click Auto-Detect button to unlock.' : 'Enter custom nickname' }}"
                           style="height: 38px; box-sizing: border-box; padding: 0 12px; border-radius: 8px; border: 1px solid {{ $isAutoLabel ? '#e2e8f0' : '#cbd5e1' }}; font-size: 13px; font-weight: 600; width: 170px; background: {{ $isAutoLabel ? '#f1f5f9' : '#ffffff' }}; color: {{ $isAutoLabel ? '#94a3b8' : '#0f172a' }}; cursor: {{ $isAutoLabel ? 'not-allowed' : 'text' }}; transition: all 0.2s;">
                    
                    @if ($isAutoLabel)
                        <button type="button" 
                                disabled 
                                title="Custom label is locked while Auto-Detect is enabled"
                                style="height: 38px; box-sizing: border-box; background: #f1f5f9; color: #94a3b8; border: 1px solid #e2e8f0; padding: 0 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: not-allowed; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                            <i class="fa-solid fa-lock" style="font-size: 12px;"></i>
                            <span>Save Custom</span>
                        </button>
                    @else
                        <button type="submit" 
                                wire:loading.attr="disabled" 
                                style="height: 38px; box-sizing: border-box; background: #0f172a; color: white; border: 1px solid #0f172a; padding: 0 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; box-shadow: 0 2px 6px rgba(15, 23, 42, 0.15); transition: all 0.2s;">
                            <i class="fa-solid fa-check" style="font-size: 12px;"></i>
                            <span>Save Custom</span>
                        </button>
                    @endif
                </form>
            </div>
        </div>

        <!-- Visibility Bar -->
        <div style="padding: 10px 14px; border-radius: 8px; font-size: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; background: {{ $isMultiServer ? '#ecfdf5' : '#f8fafc' }}; border: 1px solid {{ $isMultiServer ? '#a7f3d0' : '#e2e8f0' }};">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: {{ $isMultiServer ? '#10b981' : '#94a3b8' }};"></span>
                <span style="font-weight: 700; color: {{ $isMultiServer ? '#065f46' : '#475569' }};">
                    Multi-Server Indicator Visibility:
                </span>
                <span style="color: {{ $isMultiServer ? '#047857' : '#64748b' }};">
                    {{ $isMultiServer ? 'VISIBLE on Portal & Subsystem Sidebars (Multi-Server is Active)' : 'HIDDEN (Cluster mode is currently Standalone)' }}
                </span>
            </div>
            @if(\Illuminate\Support\Facades\Route::has('admin.server-settings.multi-server'))
            <a href="{{ route('admin.server-settings.multi-server') }}" wire:navigate style="color: #2563eb; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; font-size: 11px;">
                <span>Configure in Multi-Server</span>
                <i class="fa-solid fa-arrow-right" style="font-size: 10px;"></i>
            </a>
            @endif
        </div>
    </div>

    <!-- Alerts -->
    @if ($successMessage)
        <div style="padding: 14px 18px; border-radius: 12px; font-size: 13px; font-weight: 600; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check" style="font-size: 16px;"></i>
                <span>{{ $successMessage }}</span>
            </div>
            <button type="button" wire:click="$set('successMessage', '')" style="background: none; border: none; color: #166534; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif

    @if ($errorMessage)
        <div style="padding: 14px 18px; border-radius: 12px; font-size: 13px; font-weight: 600; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 16px;"></i>
                <span>{{ $errorMessage }}</span>
            </div>
            <button type="button" wire:click="$set('errorMessage', '')" style="background: none; border: none; color: #991b1b; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif



    <!-- Docker Containers Monitor -->
    <div style="background: #ffffff; padding: 24px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0;">Docker Services Monitor</h3>
                <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">Container runtime state on this node</p>
            </div>
            @if ($isMultiServer)
                <span style="font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">
                    <i class="fa-solid fa-circle-info" style="margin-right: 4px;"></i> Local DB stopped for stability
                </span>
            @endif
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px;">
            @foreach ($containers as $cid => $container)
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span style="width: 10px; height: 10px; border-radius: 50%; background: {{ $container['status'] === 'running' ? '#10b981' : ($container['status'] === 'stopped' ? '#f59e0b' : '#94a3b8') }}; box-shadow: 0 0 6px {{ $container['status'] === 'running' ? 'rgba(16, 185, 129, 0.4)' : 'rgba(245, 158, 11, 0.4)' }};"></span>
                        <div>
                            <div style="font-size: 13px; font-weight: 700; color: #0f172a;">{{ $container['label'] }}</div>
                            <div style="font-size: 11px; color: #64748b;">{{ $container['id'] }} &bull; {{ $container['details'] }}</div>
                        </div>
                    </div>
                    <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 8px; border-radius: 6px; background: {{ $container['status'] === 'running' ? '#dcfce7' : '#fef3c7' }}; color: {{ $container['status'] === 'running' ? '#15803d' : '#b45309' }};">
                        {{ $container['status'] }}
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Deployment, Updates & Actions -->
    <div style="background: #ffffff; padding: 24px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
        <div style="margin-bottom: 20px;">
            <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0;">Application Deployment & Operations</h3>
            <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">Synchronize Git code version, clear caches, and manage containers</p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
            <!-- Current Git Version -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px;">
                <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Active Deployed Commit</div>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <code style="font-size: 14px; font-weight: 800; background: #0f172a; color: #38bdf8; padding: 4px 10px; border-radius: 6px;">{{ $gitInfo['commit'] ?? 'HEAD' }}</code>
                    <span style="font-size: 12px; font-weight: 600; color: #059669; background: #ecfdf5; padding: 3px 8px; border-radius: 4px; border: 1px solid #a7f3d0;">
                        <i class="fa-solid fa-code-branch" style="margin-right: 4px;"></i> {{ $gitInfo['branch'] ?? 'main' }}
                    </span>
                </div>
                <div style="font-size: 12px; color: #334155; margin-bottom: 4px; line-height: 1.4;">
                    {{ $gitInfo['message'] ?: 'Latest release' }}
                </div>
                <div style="font-size: 11px; color: #64748b;">
                    Author: {{ $gitInfo['author'] ?: 'System' }} &bull; {{ $gitInfo['date'] ?: 'Recently' }}
                </div>
            </div>

            <!-- Actions Panel -->
            <div style="display: flex; flex-direction: column; gap: 12px; justify-content: center;">
                @if($hasSafetyBackup)
                    <div style="font-size: 11.5px; font-weight: 700; color: #059669; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 6px 12px; border-radius: 6px; display: flex; align-items: center; justify-content: space-between;">
                        <span style="display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Safety Backup: Verified Active</span>
                        </span>
                        @if($latestBackup)
                            <span style="font-size: 10.5px; color: #047857;">{{ $latestBackup['filename'] }} ({{ $latestBackup['age_minutes'] }}m ago)</span>
                        @endif
                    </div>
                @else
                    <div style="font-size: 11.5px; font-weight: 700; color: #b45309; background: #fffbeb; border: 1px solid #fcd34d; padding: 6px 12px; border-radius: 6px; display: flex; align-items: center; justify-content: space-between;">
                        <span style="display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-shield-halved"></i>
                            <span>Safety Backup: Recommended before updates</span>
                        </span>
                        <button type="button" wire:click="$set('showSafetyBackupModal', true)" style="background: none; border: none; font-size: 11px; font-weight: 700; color: #b45309; text-decoration: underline; cursor: pointer;">
                            Backup Now
                        </button>
                    </div>
                @endif

                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                    <button type="button" wire:click="runGitPull" wire:loading.attr="disabled" style="flex: 1; min-width: 160px; background: #2563eb; color: white; border: none; padding: 12px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);">
                        <i class="fa-solid fa-code-pull-request" wire:loading.remove wire:target="runGitPull"></i>
                        <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="runGitPull"></i>
                        <span>Fetch & Pull (Git)</span>
                    </button>

                    <button type="button" wire:click="optimizeCaches" wire:loading.attr="disabled" style="flex: 1; min-width: 160px; background: #0f172a; color: white; border: none; padding: 12px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                        <i class="fa-solid fa-bolt" wire:loading.remove wire:target="optimizeCaches"></i>
                        <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="optimizeCaches"></i>
                        <span>Optimize & Cache App</span>
                    </button>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                    <button type="button" wire:click="updateDatabase" wire:loading.attr="disabled" style="flex: 1; min-width: 160px; background: #0284c7; color: white; border: none; padding: 12px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 10px rgba(2, 132, 199, 0.2);">
                        <i class="fa-solid fa-database" wire:loading.remove wire:target="updateDatabase"></i>
                        <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="updateDatabase"></i>
                        <span>Update Database</span>
                    </button>

                    <button type="button" wire:click="renderNode" wire:loading.attr="disabled" style="flex: 1; min-width: 160px; background: #16a34a; color: white; border: none; padding: 12px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 10px rgba(22, 163, 74, 0.2);">
                        <i class="fa-brands fa-node-js" wire:loading.remove wire:target="renderNode"></i>
                        <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="renderNode"></i>
                        <span>Render Node</span>
                    </button>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                    <button type="button" wire:click="stopLocalDb" wire:loading.attr="disabled" style="flex: 1; background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; padding: 10px 14px; border-radius: 8px; font-weight: 600; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                        <i class="fa-solid fa-power-off"></i>
                        <span>Stop Local DB & Adminer</span>
                    </button>

                    <button type="button" wire:click="startLocalDb" wire:loading.attr="disabled" style="flex: 1; background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; padding: 10px 14px; border-radius: 8px; font-weight: 600; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                        <i class="fa-solid fa-play"></i>
                        <span>Start Local DB & Adminer</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Console Output Modal -->
    @if ($showOutputModal)
        <div style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px;">
            <div style="background: #ffffff; border-radius: 16px; max-width: 680px; width: 100%; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4); overflow: hidden;">
                <div style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-terminal" style="color: #2563eb;"></i>
                        <h4 style="margin: 0; font-size: 15px; font-weight: 800; color: #0f172a;">{{ $modalTitle }}</h4>
                    </div>
                    <button type="button" wire:click="closeModal" style="background: none; border: none; font-size: 20px; color: #64748b; cursor: pointer;">&times;</button>
                </div>
                <div style="padding: 20px;">
                    <div class="terminal-box">{{ $consoleOutput ?: 'Process completed with no output.' }}</div>
                </div>
                <div style="padding: 14px 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; background: #f8fafc;">
                    <button type="button" wire:click="closeModal" style="background: #0f172a; color: white; border: none; padding: 8px 18px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer;">
                        Close Window
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Safety Backup Escalation Notice Modal -->
    @if ($showSafetyBackupModal)
        <div style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px;">
            <div style="background: #ffffff; border-radius: 16px; max-width: 580px; width: 100%; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4); overflow: hidden;">
                <div style="padding: 18px 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: {{ $isBackupModalSkippable ? '#fffbeb' : '#fef2f2' }};">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 38px; height: 38px; border-radius: 10px; background: {{ $isBackupModalSkippable ? '#fef3c7' : '#fee2e2' }}; color: {{ $isBackupModalSkippable ? '#d97706' : '#dc2626' }}; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                            <i class="fa-solid {{ $isBackupModalSkippable ? 'fa-shield-halved' : 'fa-triangle-exclamation' }}"></i>
                        </div>
                        <div>
                            <h4 style="margin: 0; font-size: 15px; font-weight: 800; color: {{ $isBackupModalSkippable ? '#92400e' : '#991b1b' }};">
                                {{ $isBackupModalSkippable ? 'Safety Backup Required' : 'Safety Backup Strictly Required' }}
                            </h4>
                            <span style="font-size: 11px; font-weight: 700; color: {{ $isBackupModalSkippable ? '#b45309' : '#b91c1c' }}; text-transform: uppercase;">
                                @if ($backupSkipCount === 0)
                                    Session Initial Protection Notice
                                @elseif ($backupSkipCount === 1)
                                    5-Minute Escalation Reminder
                                @else
                                    20-Minute Protection Gate (Unskippable)
                                @endif
                            </span>
                        </div>
                    </div>
                    @if ($isBackupModalSkippable)
                        <button type="button" wire:click="dismissSafetyBackupModal" style="background: none; border: none; font-size: 20px; color: #92400e; cursor: pointer;">&times;</button>
                    @endif
                </div>

                <div style="padding: 24px; display: flex; flex-direction: column; gap: 16px;">
                    <p style="font-size: 13.5px; color: #334155; line-height: 1.5; margin: 0;">
                        @if ($backupSkipCount === 0)
                            Welcome to Current Server Management. You have not created a database backup during this session. It is strongly advised to generate a safety backup snapshot before executing critical maintenance or server updates. You can dismiss this notice for 5 minutes.
                        @elseif ($backupSkipCount === 1)
                            It has been 5 minutes since your first reminder and no database backup has been created. A database backup protects your schemas and records from unrecoverable failures. You may dismiss this reminder for another 10 minutes.
                        @else
                            You have skipped the backup prompts and it has reached the 20-minute mark without a safety backup. Because critical server operations can modify database schemas and repositories, creating a safety backup is now <strong>mandatory and unskippable</strong>.
                        @endif
                    </p>

                    @if($latestBackup)
                        <div style="font-size: 12px; color: #64748b; background: #f1f5f9; padding: 10px 14px; border-radius: 8px; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-clock-rotate-left" style="color: #64748b;"></i>
                            <span>Previous backup on disk: <strong>{{ $latestBackup['filename'] }}</strong> ({{ $latestBackup['age_minutes'] }} mins ago)</span>
                        </div>
                    @else
                        <div style="font-size: 12px; color: #b45309; background: #fffbeb; padding: 10px 14px; border-radius: 8px; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-triangle-exclamation" style="color: #d97706;"></i>
                            <span>No existing backups found on local storage. Creating a backup is essential.</span>
                        </div>
                    @endif
                </div>

                <div style="padding: 16px 24px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 10px; background: #f8fafc;">
                    @if ($isBackupModalSkippable)
                        <button type="button" wire:click="dismissSafetyBackupModal" wire:loading.attr="disabled" style="background: #ffffff; color: #64748b; border: 1px solid #cbd5e1; padding: 10px 16px; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer;">
                            Dismiss (Remind in {{ $nextPromptMinutes }}m)
                        </button>
                    @endif
                    <button type="button" wire:click="executeSafetyBackup" wire:loading.attr="disabled" wire:target="executeSafetyBackup" style="background: {{ $isBackupModalSkippable ? '#d97706' : '#dc2626' }}; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
                        <i class="fa-solid fa-cloud-arrow-up" wire:loading.remove wire:target="executeSafetyBackup"></i>
                        <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="executeSafetyBackup"></i>
                        <span wire:loading.remove wire:target="executeSafetyBackup">Generate Safety Backup</span>
                        <span wire:loading wire:target="executeSafetyBackup">Creating Backup...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
