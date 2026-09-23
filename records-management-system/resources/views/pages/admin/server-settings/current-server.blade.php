<?php

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

    public function boot(): void
    {
        $this->__alias = 'pages.admin.server-settings.current-server';
    }

    public function mount(ServerManagementService $service): void
    {
        $this->refreshData($service);
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

<div class="space-y-6">
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
            <a href="{{ route('admin.server-settings.multi-server') }}" wire:navigate style="color: #2563eb; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; font-size: 11px;">
                <span>Configure in Multi-Server</span>
                <i class="fa-solid fa-arrow-right" style="font-size: 10px;"></i>
            </a>
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

    <!-- Telemetry 4-Card Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px;">
        <!-- 1. CPU Usage -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 13px; font-weight: 700; color: #64748b; text-transform: uppercase;">CPU Load</span>
                <span style="font-size: 18px; color: #3b82f6;"><i class="fa-solid fa-microchip"></i></span>
            </div>
            <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 28px; font-weight: 800; color: #0f172a;">{{ $metrics['cpu']['usage_pct'] ?? 0 }}%</span>
                <span style="font-size: 12px; color: #64748b;">({{ $metrics['cpu']['cores'] ?? 1 }} cores)</span>
            </div>
            <!-- Progress Bar -->
            <div style="height: 6px; width: 100%; background: #f1f5f9; border-radius: 4px; overflow: hidden; margin-bottom: 10px;">
                <div style="height: 100%; width: {{ min(100, $metrics['cpu']['usage_pct'] ?? 0) }}%; background: {{ ($metrics['cpu']['usage_pct'] ?? 0) > 80 ? '#ef4444' : '#3b82f6' }}; border-radius: 4px; transition: width 0.3s;"></div>
            </div>
            <div style="font-size: 11px; color: #64748b; display: flex; justify-content: space-between;">
                <span>Load avg:</span>
                <span>{{ $metrics['cpu']['load_1m'] ?? 0 }} (1m) &bull; {{ $metrics['cpu']['load_5m'] ?? 0 }} (5m)</span>
            </div>
        </div>

        <!-- 2. RAM Memory (Crucial for 1GB VMs) -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 13px; font-weight: 700; color: #64748b; text-transform: uppercase;">RAM Memory</span>
                <span style="font-size: 18px; color: #10b981;"><i class="fa-solid fa-memory"></i></span>
            </div>
            <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 28px; font-weight: 800; color: #0f172a;">{{ $metrics['ram']['used_mb'] ?? 0 }} <span style="font-size: 16px; font-weight: 600;">MB</span></span>
                <span style="font-size: 12px; color: #64748b;">/ {{ $metrics['ram']['total_mb'] ?? 1024 }} MB ({{ $metrics['ram']['usage_pct'] ?? 0 }}%)</span>
            </div>
            <div style="height: 6px; width: 100%; background: #f1f5f9; border-radius: 4px; overflow: hidden; margin-bottom: 10px;">
                <div style="height: 100%; width: {{ min(100, $metrics['ram']['usage_pct'] ?? 0) }}%; background: {{ ($metrics['ram']['usage_pct'] ?? 0) > 85 ? '#ef4444' : '#10b981' }}; border-radius: 4px; transition: width 0.3s;"></div>
            </div>
            <div style="font-size: 11px; color: #64748b; display: flex; justify-content: space-between;">
                <span>Free: {{ $metrics['ram']['free_mb'] ?? 0 }} MB</span>
                <span>Swap: {{ $metrics['ram']['swap_used_mb'] ?? 0 }}/{{ $metrics['ram']['swap_total_mb'] ?? 0 }} MB</span>
            </div>
        </div>

        <!-- 3. Disk Storage -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 13px; font-weight: 700; color: #64748b; text-transform: uppercase;">Disk Storage</span>
                <span style="font-size: 18px; color: #8b5cf6;"><i class="fa-solid fa-hard-drive"></i></span>
            </div>
            <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 28px; font-weight: 800; color: #0f172a;">{{ $metrics['disk']['used_gb'] ?? 0 }} <span style="font-size: 16px; font-weight: 600;">GB</span></span>
                <span style="font-size: 12px; color: #64748b;">/ {{ $metrics['disk']['total_gb'] ?? 0 }} GB</span>
            </div>
            <div style="height: 6px; width: 100%; background: #f1f5f9; border-radius: 4px; overflow: hidden; margin-bottom: 10px;">
                <div style="height: 100%; width: {{ min(100, $metrics['disk']['usage_pct'] ?? 0) }}%; background: {{ ($metrics['disk']['usage_pct'] ?? 0) > 90 ? '#ef4444' : '#8b5cf6' }}; border-radius: 4px; transition: width 0.3s;"></div>
            </div>
            <div style="font-size: 11px; color: #64748b; display: flex; justify-content: space-between;">
                <span>Available: {{ $metrics['disk']['free_gb'] ?? 0 }} GB</span>
                <span>{{ $metrics['disk']['usage_pct'] ?? 0 }}% used</span>
            </div>
        </div>

        <!-- 4. Host Environment -->
        <div style="background: #ffffff; padding: 20px; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 13px; font-weight: 700; color: #64748b; text-transform: uppercase;">Environment</span>
                <span style="font-size: 18px; color: #f59e0b;"><i class="fa-solid fa-layer-group"></i></span>
            </div>
            <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 24px; font-weight: 800; color: #0f172a;">PHP {{ $metrics['host']['php_version'] ?? '8.4' }}</span>
            </div>
            <div style="font-size: 12px; color: #475569; margin-bottom: 6px;">
                <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #10b981; margin-right: 6px;"></span>
                {{ $metrics['host']['is_docker'] ? 'Docker Containerized' : 'Baremetal Host' }}
            </div>
            <div style="font-size: 11px; color: #64748b;">
                Server: {{ $metrics['host']['server_software'] ?? 'Nginx' }}
            </div>
        </div>
    </div>

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
</div>
