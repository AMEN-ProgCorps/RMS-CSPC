<?php

use App\Services\ServerManagementService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Adds-on Servers')] class extends Component {
    public bool $isMultiServer = false;
    public string $clusterRole = 'root';
    public string $backupVmUrl = '';
    public string $clusterToken = '';

    // Prerequisites validation state
    public array $dbCheck = [];
    public array $vmCheck = [];
    public bool $canEnable = false;

    // Feedback
    public string $successMessage = '';
    public string $errorMessage = '';

    // Remote update modal
    public bool $showOutputModal = false;
    public string $modalTitle = '';
    public string $consoleOutput = '';
    public bool $isBusy = false;

    public function mount(ServerManagementService $service): void
    {
        $this->isMultiServer = $service->isMultiServerEnabled();
        $this->clusterRole = $service->getSystemSetting('cluster_role', 'root');
        $this->backupVmUrl = $service->getSystemSetting('backup_vm_url', '');
        $this->clusterToken = $service->getClusterSecretToken();

        $this->runPrerequisiteChecks($service);
    }

    public function runPrerequisiteChecks(ServerManagementService $service): void
    {
        $this->dbCheck = $service->validateExternalDatabase();
        $this->vmCheck = $service->validateBackupVm($this->backupVmUrl, $this->clusterToken);

        $this->canEnable = ($this->dbCheck['valid'] && $this->vmCheck['valid']);
    }

    public function saveClusterConfig(ServerManagementService $service): void
    {
        $cleanUrl = rtrim(trim($this->backupVmUrl), '/');
        $service->setSystemSetting('backup_vm_url', $cleanUrl);
        $service->setSystemSetting('cluster_secret_token', trim($this->clusterToken));

        $this->backupVmUrl = $cleanUrl;
        $this->successMessage = 'Cluster configuration saved.';

        $this->runPrerequisiteChecks($service);
    }

    public function generateNewToken(ServerManagementService $service): void
    {
        $this->clusterToken = \Illuminate\Support\Str::random(40);
        $service->setSystemSetting('cluster_secret_token', $this->clusterToken);
        $this->successMessage = 'New cluster secret token generated. Make sure to copy it to your Backup VM as well!';
        $this->runPrerequisiteChecks($service);
    }

    public function toggleMultiServer(ServerManagementService $service): void
    {
        if ($this->isMultiServer) {
            // Disable Multi-VM mode
            $res = $service->disableMultiVmMode(false);
            $this->isMultiServer = false;
            $this->successMessage = $res['message'];
        } else {
            // Enable Multi-VM mode
            $res = $service->enableMultiVmMode();
            if ($res['success']) {
                $this->isMultiServer = true;
                $this->successMessage = $res['message'];
                $this->modalTitle = 'Multi-VM Activation: Local DB Containers Stopped';
                $this->consoleOutput = $res['details'] ?: 'Local db and adminer containers shut down. Neon Postgres is now serving all traffic.';
                $this->showOutputModal = true;
            } else {
                $this->errorMessage = $res['message'];
            }
        }

        $this->runPrerequisiteChecks($service);
    }

    public function triggerRemoteUpdate(ServerManagementService $service): void
    {
        $this->isBusy = true;
        $this->modalTitle = 'Remote Backup VM Synchronize & Update';

        $res = $service->triggerRemoteUpdateOnBackup();
        $this->consoleOutput = ($res['message'] ?? '') . "\n\n" . ($res['output'] ?? '');
        $this->showOutputModal = true;
        $this->isBusy = false;

        if ($res['success']) {
            $this->successMessage = $res['message'];
        } else {
            $this->errorMessage = $res['message'];
        }

        $this->runPrerequisiteChecks($service);
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

        [data-theme="dark"] input[type="text"],
        [data-theme="dark"] input[type="password"] {
            background-color: #0b1120 !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

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
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #ecfdf5; color: #059669; display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 4px 8px rgba(16, 185, 129, 0.15);">
                <i class="fa-solid fa-network-wired"></i>
            </div>
            <div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h1 style="font-size: 22px; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.02em;">Adds-on Server & Multi-VM Clustering</h1>
                    <span style="font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 6px; text-transform: uppercase; background: {{ $isMultiServer ? '#dcfce7' : '#fef3c7' }}; color: {{ $isMultiServer ? '#15803d' : '#b45309' }}; border: 1px solid {{ $isMultiServer ? '#86efac' : '#fde68a' }};">
                        {{ $isMultiServer ? 'Cluster Active' : 'Standalone Root' }}
                    </span>
                </div>
                <p style="font-size: 13px; color: #64748b; margin: 4px 0 0 0;">
                    High-availability cluster management for low-spec VMs with centralized database and load balancing
                </p>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" wire:click="runPrerequisiteChecks" wire:loading.attr="disabled" style="background: #0f172a; color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-rotate" wire:loading.remove wire:target="runPrerequisiteChecks"></i>
                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="runPrerequisiteChecks"></i>
                <span>Check Status</span>
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

    <!-- Multi-Server Activation Control & Hierarchy Rule -->
    <div style="background: #ffffff; padding: 24px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
            <div>
                <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0;">Multi-VM Cluster Function</h3>
                <p style="font-size: 13px; color: #64748b; margin: 4px 0 0 0;">
                    Hierarchy: Standalone Root by default. When enabled, requires <strong>1 Backup VM</strong> + <strong>1 External SQL DB</strong>, and shuts down local database containers to stabilize RAM.
                </p>
            </div>

            <!-- Activation Switch -->
            <div>
                @if ($isMultiServer)
                    <button type="button" wire:click="toggleMultiServer" wire:loading.attr="disabled" style="background: #ef4444; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 800; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);">
                        <i class="fa-solid fa-toggle-on"></i>
                        <span>Disable Multi-VM Cluster</span>
                    </button>
                @else
                    <button type="button" wire:click="toggleMultiServer" wire:loading.attr="disabled" @disabled(!$canEnable) style="background: {{ $canEnable ? '#10b981' : '#94a3b8' }}; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 800; font-size: 13px; cursor: {{ $canEnable ? 'pointer' : 'not-allowed' }}; display: inline-flex; align-items: center; gap: 8px; box-shadow: {{ $canEnable ? '0 4px 12px rgba(16, 185, 129, 0.25)' : 'none' }};">
                        <i class="fa-solid fa-toggle-off"></i>
                        <span>Enable Multi-VM Function</span>
                    </button>
                @endif
            </div>
        </div>

        @if (!$canEnable && !$isMultiServer)
            <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; font-size: 13px; color: #92400e; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-exclamation" style="font-size: 18px; color: #d97706;"></i>
                <div>
                    <strong>Requirements not met:</strong> You must configure and verify both <strong>1 External SQL Database</strong> and <strong>1 Backup VM</strong> below before you can activate the Multi-VM cluster.
                </div>
            </div>
        @endif

        <!-- Prerequisites Checklist Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px;">
            <!-- Condition 1: External SQL Database -->
            <div style="background: #f8fafc; border: 1px solid {{ $dbCheck['valid'] ? '#86efac' : '#fed7aa' }}; border-radius: 12px; padding: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 13px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-database" style="color: #2563eb;"></i>
                        Requirement 1: External SQL Database
                    </span>
                    <span style="font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; background: {{ $dbCheck['valid'] ? '#dcfce7' : '#fee2e2' }}; color: {{ $dbCheck['valid'] ? '#15803d' : '#b91c1c' }};">
                        {{ $dbCheck['valid'] ? 'Verified' : 'Missing' }}
                    </span>
                </div>
                <div style="font-size: 12px; color: #475569; margin-bottom: 8px;">
                    Host: <code style="background: #ffffff; padding: 2px 6px; border-radius: 4px; border: 1px solid #cbd5e1; font-weight: 700;">{{ $dbCheck['host'] ?? 'Local db' }}</code>
                </div>
                <div style="font-size: 12px; color: {{ $dbCheck['valid'] ? '#059669' : '#b45309' }};">
                    {{ $dbCheck['message'] ?? 'Checking database configuration...' }}
                </div>
                @if ($dbCheck['valid'] && !empty($dbCheck['latency_ms']))
                    <div style="font-size: 11px; color: #64748b; margin-top: 6px;">
                        Round-trip query latency: <strong>{{ $dbCheck['latency_ms'] }} ms</strong>
                    </div>
                @endif
            </div>

            <!-- Condition 2: 1 Backup VM -->
            <div style="background: #f8fafc; border: 1px solid {{ $vmCheck['valid'] ? '#86efac' : '#fed7aa' }}; border-radius: 12px; padding: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 13px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-server" style="color: #8b5cf6;"></i>
                        Requirement 2: 1 Backup VM Node
                    </span>
                    <span style="font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; background: {{ $vmCheck['valid'] ? '#dcfce7' : '#fee2e2' }}; color: {{ $vmCheck['valid'] ? '#15803d' : '#b91c1c' }};">
                        {{ $vmCheck['valid'] ? 'Online' : 'Unreachable' }}
                    </span>
                </div>
                <div style="font-size: 12px; color: #475569; margin-bottom: 8px;">
                    Address: <code style="background: #ffffff; padding: 2px 6px; border-radius: 4px; border: 1px solid #cbd5e1; font-weight: 700;">{{ $backupVmUrl ?: 'Not configured' }}</code>
                </div>
                <div style="font-size: 12px; color: {{ $vmCheck['valid'] ? '#059669' : '#b45309' }};">
                    {{ $vmCheck['message'] ?? 'Enter Backup VM Tailscale address below.' }}
                </div>
                @if ($vmCheck['valid'] && !empty($vmCheck['latency_ms']))
                    <div style="font-size: 11px; color: #64748b; margin-top: 6px;">
                        Mesh ping latency: <strong>{{ $vmCheck['latency_ms'] }} ms</strong>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Backup VM Configuration Form & Remote Update -->
    <div style="background: #ffffff; padding: 24px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
        <div style="margin-bottom: 18px;">
            <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0;">Backup VM Node Settings</h3>
            <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">Connect your second VM over Tailscale private mesh network</p>
        </div>

        <form wire:submit.prevent="saveClusterConfig" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 20px;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                    Backup VM Tailscale Address / Origin URL:
                </label>
                <input type="text" wire:model="backupVmUrl" placeholder="http://100.x.y.z:80" style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px;">
                <p style="font-size: 11px; color: #64748b; margin: 4px 0 0 0;">Use the private Tailscale IP of your second VM.</p>
            </div>

            <div>
                <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                    Cluster Secret Token:
                </label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" wire:model="clusterToken" style="flex: 1; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px; font-family: monospace;">
                    <button type="button" wire:click="generateNewToken" title="Generate New Key" style="background: #f1f5f9; border: 1px solid #cbd5e1; padding: 0 12px; border-radius: 8px; color: #475569; cursor: pointer;">
                        <i class="fa-solid fa-key"></i>
                    </button>
                </div>
                <p style="font-size: 11px; color: #64748b; margin: 4px 0 0 0;">Shared token to authenticate node-to-node communication.</p>
            </div>

            <div style="grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="submit" style="background: #0f172a; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer;">
                    <i class="fa-solid fa-floppy-disk" style="margin-right: 6px;"></i> Save Node Configuration
                </button>
            </div>
        </form>

        <hr style="border: 0; border-top: 1px solid #f1f5f9; margin: 20px 0;">

        <!-- Remote Management & Code Sync -->
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
            <div>
                <h4 style="font-size: 14px; font-weight: 800; color: #0f172a; margin: 0;">Remote Code Synchronization</h4>
                <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">
                    Execute git pull and clear application cache on the Backup VM remotely so both nodes run the exact same version.
                </p>
            </div>

            <button type="button" wire:click="triggerRemoteUpdate" wire:loading.attr="disabled" @disabled(!$vmCheck['valid']) style="background: #2563eb; color: white; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: {{ $vmCheck['valid'] ? 'pointer' : 'not-allowed' }}; opacity: {{ $vmCheck['valid'] ? '1' : '0.5' }}; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-arrows-rotate" wire:loading.remove wire:target="triggerRemoteUpdate"></i>
                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="triggerRemoteUpdate"></i>
                <span>Synchronize & Pull on Backup VM</span>
            </button>
        </div>
    </div>

    <!-- Architecture & Load Balancing Guide -->
    <div style="background: #ffffff; padding: 24px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
        <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0 0 12px 0;">Cluster Architecture & Traffic Routing</h3>
        <div style="font-size: 13px; color: #475569; line-height: 1.6;">
            <p style="margin: 0 0 10px 0;">
                In this multi-server topology, <strong>Cloudflare Tunnel</strong> sits in front of both VMs. It distributes web requests across <strong>Root VM</strong> and <strong>Backup VM</strong> while ensuring zero open ports to the public internet:
            </p>
            <ul style="margin: 0; padding-left: 20px;">
                <li><strong>Stateless Compute:</strong> Both VMs handle user requests independently.</li>
                <li><strong>Shared State:</strong> All transactions and session keys reside centrally in <strong>Neon PostgreSQL</strong>.</li>
                <li><strong>Shared Storage:</strong> All uploaded files and documents stream to and from <strong>Google Drive</strong>.</li>
                <li><strong>Private Mesh:</strong> Inter-node health checks and remote deployment run over <strong>Tailscale</strong>.</li>
            </ul>
        </div>
    </div>

    <!-- Output Modal -->
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
                    <div class="terminal-box">{{ $consoleOutput ?: 'Completed.' }}</div>
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
