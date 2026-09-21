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

    public string $successMessage = '';
    public string $errorMessage = '';

    // Console output modal state
    public bool $showOutputModal = false;
    public string $modalTitle = '';
    public string $consoleOutput = '';
    public bool $isBusy = false;

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
