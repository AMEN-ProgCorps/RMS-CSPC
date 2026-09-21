<?php

use App\Services\ServerManagementService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Multi-Server')] class extends Component {
    public bool $isMultiServer = false;
    public string $clusterRole = 'root';
    public string $backupVmUrl = '';
    public string $clusterToken = '';

    // Prerequisites validation state
    public array $dbCheck = [];
    public array $vmCheck = [];
    public bool $canEnable = false;

    // Database Configuration modal state
    public bool $showDbModal = false;
    public bool $showBackupVmModal = false;
    public ?array $testVmResult = null;
    public bool $isTestingVm = false;
    public string $dbUrlInput = '';
    public string $dbHost = '';
    public string $dbPort = '5432';
    public string $dbName = 'rms';
    public string $dbUser = '';
    public string $dbPassword = '';
    public string $dbSslMode = 'require';
    public ?array $testDbResult = null;
    public bool $isTestingDb = false;

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

        $this->dbHost = config('database.connections.pgsql.host') ?: env('DB_HOST', '');
        $this->dbPort = (string) (config('database.connections.pgsql.port') ?: env('DB_PORT', '5432'));
        $this->dbName = config('database.connections.pgsql.database') ?: env('DB_DATABASE', 'rms');
        $this->dbUser = config('database.connections.pgsql.username') ?: env('DB_USERNAME', 'adminrms');
        $this->dbSslMode = config('database.connections.pgsql.sslmode') ?: env('DB_SSLMODE', 'prefer');

        $this->runPrerequisiteChecks($service);
    }

    public function runPrerequisiteChecks(ServerManagementService $service): void
    {
        $this->dbCheck = $service->validateExternalDatabase();
        $this->vmCheck = $service->validateBackupVm($this->backupVmUrl, $this->clusterToken);

        $this->canEnable = ($this->dbCheck['valid'] && $this->vmCheck['valid']);
    }

    public function openDbModal(): void
    {
        $this->showDbModal = true;
        $this->testDbResult = null;
    }

    public function closeDbModal(): void
    {
        $this->showDbModal = false;
        $this->testDbResult = null;
    }

    public function parseDbUrl(): void
    {
        $url = trim($this->dbUrlInput);
        if (empty($url)) {
            return;
        }

        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            $this->errorMessage = 'Invalid database URL format. Expected: postgresql://user:password@host:port/database';
            return;
        }

        $this->dbHost = $parsed['host'] ?? '';
        $this->dbPort = isset($parsed['port']) ? (string) $parsed['port'] : '5432';
        $this->dbUser = isset($parsed['user']) ? urldecode($parsed['user']) : '';
        $this->dbPassword = isset($parsed['pass']) ? urldecode($parsed['pass']) : '';
        $this->dbName = isset($parsed['path']) ? ltrim($parsed['path'], '/') : 'rms';

        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
            if (isset($query['sslmode'])) {
                $this->dbSslMode = $query['sslmode'];
            }
        }

        $this->successMessage = 'Database URL parsed. Click "Test Connection" to probe connectivity, then "Apply to .env".';
    }

    public function testDatabaseConnection(ServerManagementService $service): void
    {
        $this->isTestingDb = true;
        $this->testDbResult = $service->testCustomDatabaseConnection([
            'host' => $this->dbHost,
            'port' => $this->dbPort,
            'database' => $this->dbName,
            'username' => $this->dbUser,
            'password' => $this->dbPassword,
            'sslmode' => $this->dbSslMode,
        ]);
        $this->isTestingDb = false;
    }

    public function applyDatabaseConfig(ServerManagementService $service): void
    {
        $res = $service->applyDatabaseToEnv([
            'host' => $this->dbHost,
            'port' => $this->dbPort,
            'database' => $this->dbName,
            'username' => $this->dbUser,
            'password' => $this->dbPassword,
            'sslmode' => $this->dbSslMode,
        ]);

        if ($res['success']) {
            $this->successMessage = $res['message'];
            $this->testDbResult = null;
            $this->runPrerequisiteChecks($service);
        } else {
            $this->errorMessage = $res['message'];
        }
    }

    public function revertToLocalDb(ServerManagementService $service): void
    {
        $res = $service->revertDatabaseToLocal();
        if ($res['success']) {
            $this->dbHost = 'db';
            $this->dbPort = '5432';
            $this->dbName = 'rms';
            $this->dbUser = 'adminrms';
            $this->dbPassword = 'admin';
            $this->dbSslMode = 'prefer';
            $this->testDbResult = null;
            $this->successMessage = 'Database configuration reverted to local Docker container (db).';
            $this->runPrerequisiteChecks($service);
        } else {
            $this->errorMessage = $res['message'];
        }
    }

    public function runMigrations(ServerManagementService $service): void
    {
        $this->isBusy = true;
        $this->modalTitle = 'Database Schema Migrations';
        $res = $service->runDatabaseMigrations();
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

    public function openBackupVmModal(): void
    {
        $this->showBackupVmModal = true;
        $this->testVmResult = null;
    }

    public function closeBackupVmModal(): void
    {
        $this->showBackupVmModal = false;
        $this->testVmResult = null;
    }

    public function testBackupVmConnection(ServerManagementService $service): void
    {
        $this->isTestingVm = true;
        $cleanUrl = rtrim(trim($this->backupVmUrl), '/');
        $this->testVmResult = $service->validateBackupVm($cleanUrl, trim($this->clusterToken));
        $this->isTestingVm = false;
    }

    public function saveClusterConfig(ServerManagementService $service): void
    {
        $cleanUrl = rtrim(trim($this->backupVmUrl), '/');
        $service->setSystemSetting('backup_vm_url', $cleanUrl);
        $service->setSystemSetting('cluster_secret_token', trim($this->clusterToken));

        $this->backupVmUrl = $cleanUrl;
        $this->successMessage = 'Cluster configuration saved and Backup VM node verified.';
        $this->showBackupVmModal = false;
        $this->testVmResult = null;

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
                    <h1 style="font-size: 22px; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.02em;">Multi-Server Clustering</h1>
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
                <div style="margin-top: 12px; padding-top: 10px; border-top: 1px dashed {{ $dbCheck['valid'] ? '#bbf7d0' : '#fed7aa' }}; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 11px; color: #64748b;">
                        {{ $dbCheck['valid'] ? 'External database active' : 'Action required' }}
                    </span>
                    <button type="button" wire:click="openDbModal" style="background: #2563eb; color: #ffffff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 3px rgba(37, 99, 235, 0.2);">
                        <i class="fa-solid fa-gear"></i>
                        <span>Configure Database</span>
                    </button>
                </div>
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
                    {{ $vmCheck['message'] ?? 'Configure second VM settings below.' }}
                </div>
                @if ($vmCheck['valid'] && !empty($vmCheck['latency_ms']))
                    <div style="font-size: 11px; color: #64748b; margin-top: 6px;">
                        Mesh ping latency: <strong>{{ $vmCheck['latency_ms'] }} ms</strong>
                    </div>
                @endif
                <div style="margin-top: 12px; padding-top: 10px; border-top: 1px dashed {{ $vmCheck['valid'] ? '#bbf7d0' : '#fed7aa' }}; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 11px; color: #64748b;">
                        {{ $vmCheck['valid'] ? 'Backup node connected' : 'Action required' }}
                    </span>
                    <button type="button" wire:click="openBackupVmModal" style="background: #7c3aed; color: #ffffff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 3px rgba(124, 58, 237, 0.2);">
                        <i class="fa-solid fa-gear"></i>
                        <span>Configure Backup VM</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Cluster VM Nodes Display -->
    <div style="background: #ffffff; padding: 24px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 10px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                    <i class="fa-solid fa-network-wired"></i>
                </div>
                <div>
                    <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0;">Cluster VM Nodes</h3>
                    <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">Overview of virtual machines participating in this cluster</p>
                </div>
            </div>

            <button type="button" wire:click="triggerRemoteUpdate" wire:loading.attr="disabled" @disabled(!$vmCheck['valid']) style="background: #2563eb; color: white; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: {{ $vmCheck['valid'] ? 'pointer' : 'not-allowed' }}; opacity: {{ $vmCheck['valid'] ? '1' : '0.5' }}; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-arrows-rotate" wire:loading.remove wire:target="triggerRemoteUpdate"></i>
                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="triggerRemoteUpdate"></i>
                <span>Synchronize & Pull on Backup VM</span>
            </button>
        </div>

        <!-- Node Cards Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
            <!-- Node 1: Primary Root VM (This Server) -->
            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 12px; padding: 18px; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <span style="font-size: 14px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                            <span style="width: 10px; height: 10px; border-radius: 50%; background: #10b981; display: inline-block;"></span>
                            Primary Node (Root VM)
                        </span>
                        <span style="font-size: 10px; font-weight: 800; text-transform: uppercase; padding: 2px 8px; border-radius: 6px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">
                            This Server
                        </span>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 12px; color: #475569;">
                        <div>Role: <strong style="color: #0f172a;">Primary Origin / Cluster Controller</strong></div>
                        <div>Status: <strong style="color: #10b981;">Online & Serving</strong></div>
                        <div>Local DB Containers: <strong style="{{ $isMultiServer ? 'color: #64748b;' : 'color: #2563eb;' }}">{{ $isMultiServer ? 'Stopped (Memory Saved: ~250MB)' : 'Active (Standby for shutdown)' }}</strong></div>
                    </div>
                </div>
                <div style="margin-top: 14px; padding-top: 10px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end;">
                    <a href="{{ route('admin.server-settings.current') }}" wire:navigate style="font-size: 11px; font-weight: 700; color: #2563eb; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                        <span>Manage Root Metrics</span>
                        <i class="fa-solid fa-arrow-right" style="font-size: 10px;"></i>
                    </a>
                </div>
            </div>

            <!-- Node 2: Secondary Backup VM (Remote Machine) -->
            <div style="background: #f8fafc; border: 1px solid {{ $vmCheck['valid'] ? '#86efac' : '#e2e8f0' }}; border-radius: 12px; padding: 18px; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <span style="font-size: 14px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                            <span style="width: 10px; height: 10px; border-radius: 50%; background: {{ $vmCheck['valid'] ? '#10b981' : '#94a3b8' }}; display: inline-block;"></span>
                            Secondary Node (Backup VM)
                        </span>
                        <span style="font-size: 10px; font-weight: 800; text-transform: uppercase; padding: 2px 8px; border-radius: 6px; background: {{ $vmCheck['valid'] ? '#ecfdf5' : '#f1f5f9' }}; color: {{ $vmCheck['valid'] ? '#059669' : '#64748b' }}; border: 1px solid {{ $vmCheck['valid'] ? '#a7f3d0' : '#cbd5e1' }};">
                            {{ $vmCheck['valid'] ? 'Verified' : 'Pending' }}
                        </span>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 12px; color: #475569;">
                        <div>Address: <code style="background: #ffffff; padding: 2px 6px; border-radius: 4px; border: 1px solid #cbd5e1; font-weight: 700;">{{ $backupVmUrl ?: 'Not configured' }}</code></div>
                        <div>Status: <strong style="{{ $vmCheck['valid'] ? 'color: #10b981;' : 'color: #b45309;' }}">{{ $vmCheck['valid'] ? 'Online (' . ($vmCheck['latency_ms'] ?? '0') . ' ms)' : 'Unreachable' }}</strong></div>
                        <div>Mesh Connection: <span>{{ $vmCheck['valid'] ? 'Private Tailscale mesh active' : 'Click Configure Node to connect' }}</span></div>
                    </div>
                </div>
                <div style="margin-top: 14px; padding-top: 10px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end;">
                    <button type="button" wire:click="openBackupVmModal" style="background: none; border: none; font-size: 11px; font-weight: 700; color: #7c3aed; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; padding: 0;">
                        <i class="fa-solid fa-gear"></i>
                        <span>Configure Backup VM</span>
                    </button>
                </div>
            </div>
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

    <!-- Database Setup Modal (Requirement 1) -->
    @if ($showDbModal)
        <div style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px;">
            <div style="background: #ffffff; border-radius: 16px; max-width: 720px; width: 100%; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4); overflow: hidden; display: flex; flex-direction: column; max-height: 90vh;">
                <!-- Modal Header -->
                <div style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="width: 34px; height: 34px; border-radius: 8px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 15px;">
                            <i class="fa-solid fa-database"></i>
                        </div>
                        <div>
                            <h4 style="margin: 0; font-size: 15px; font-weight: 800; color: #0f172a;">Requirement 1: External SQL Database Setup</h4>
                            <p style="margin: 2px 0 0 0; font-size: 11px; color: #64748b;">Configure centralized Neon Postgres or cloud database connection</p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeDbModal" style="background: none; border: none; font-size: 22px; color: #64748b; cursor: pointer; line-height: 1;">&times;</button>
                </div>

                <!-- Modal Body (Scrollable) -->
                <div style="padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px;">
                    <!-- Quick-Paste Connection String -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px;">
                        <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                            <i class="fa-solid fa-bolt" style="color: #f59e0b; margin-right: 4px;"></i> Quick-Paste Connection String (Neon / Supabase / AWS):
                        </label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" 
                                   wire:model="dbUrlInput" 
                                   placeholder="postgresql://neondb_owner:password@ep-xxxx-pooler.c-4.us-east-2.aws.neon.tech/rms?sslmode=require" 
                                   style="flex: 1; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px; font-family: monospace;">
                            <button type="button" wire:click="parseDbUrl" style="background: #2563eb; color: white; border: none; padding: 0 14px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-arrow-down-short-wide"></i>
                                <span>Auto-Fill</span>
                            </button>
                        </div>
                        <p style="font-size: 11px; color: #64748b; margin: 4px 0 0 0;">
                            Paste your Neon connection URI to auto-populate the host, user, password, and port fields below.
                        </p>
                    </div>

                    <!-- Database Fields Form -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Database Host:</label>
                            <input type="text" wire:model="dbHost" placeholder="e.g. ep-xxxx-pooler.aws.neon.tech" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Database Port:</label>
                            <input type="text" wire:model="dbPort" placeholder="5432" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Database Name:</label>
                            <input type="text" wire:model="dbName" placeholder="rms" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Username:</label>
                            <input type="text" wire:model="dbUser" placeholder="neondb_owner" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Password:</label>
                            <input type="password" wire:model="dbPassword" placeholder="••••••••••••" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">SSL Mode:</label>
                            <select wire:model="dbSslMode" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px; background: white;">
                                <option value="require">require (Recommended for Neon)</option>
                                <option value="prefer">prefer</option>
                                <option value="disable">disable (Local container only)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Test Result Alert -->
                    @if ($testDbResult)
                        <div style="font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 8px; background: {{ $testDbResult['success'] ? '#ecfdf5' : '#fef2f2' }}; color: {{ $testDbResult['success'] ? '#047857' : '#b91c1c' }}; border: 1px solid {{ $testDbResult['success'] ? '#a7f3d0' : '#fecaca' }};">
                            <i class="fa-solid {{ $testDbResult['success'] ? 'fa-circle-check' : 'fa-circle-xmark' }}" style="font-size: 14px;"></i>
                            <span>{{ $testDbResult['message'] }}</span>
                        </div>
                    @endif

                    <!-- Auxiliary Tools: Migration & Revert -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <span style="font-size: 12px; color: #64748b;">
                            Database tools:
                        </span>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            @if ($dbCheck['valid'])
                                <button type="button" wire:click="revertToLocalDb" wire:confirm="Are you sure you want to revert to the local Docker database container (db)?" style="background: #ffffff; border: 1px solid #cbd5e1; color: #475569; padding: 6px 12px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid fa-rotate-left"></i>
                                    <span>Revert to Local DB</span>
                                </button>
                            @endif
                            <button type="button" wire:click="runMigrations" wire:loading.attr="disabled" title="Run php artisan migrate on the connected database" style="background: #ffffff; border: 1px solid #cbd5e1; color: #1e293b; padding: 6px 12px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                                <i class="fa-solid fa-table-list"></i>
                                <span>Run Schema Migrations</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div style="padding: 14px 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; flex-wrap: wrap; gap: 10px;">
                    <button type="button" 
                            wire:click="testDatabaseConnection" 
                            wire:loading.attr="disabled" 
                            style="background: #ffffff; border: 1px solid #cbd5e1; color: #1e293b; padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-plug" wire:loading.remove wire:target="testDatabaseConnection"></i>
                        <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="testDatabaseConnection"></i>
                        <span>Test Connection</span>
                    </button>

                    <div style="display: flex; gap: 8px; align-items: center;">
                        <button type="button" wire:click="closeDbModal" style="background: #ffffff; border: 1px solid #cbd5e1; color: #64748b; padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer;">
                            Cancel
                        </button>
                        <button type="button" 
                                wire:click="applyDatabaseConfig" 
                                wire:loading.attr="disabled" 
                                style="background: #0f172a; color: white; border: none; padding: 8px 18px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(15, 23, 42, 0.15);">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <span>Apply to .env & Connect</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Backup VM Setup Modal (Requirement 2) -->
    @if ($showBackupVmModal)
        <div style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px;">
            <div style="background: #ffffff; border-radius: 16px; max-width: 680px; width: 100%; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4); overflow: hidden; display: flex; flex-direction: column; max-height: 90vh;">
                <!-- Modal Header -->
                <div style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="width: 34px; height: 34px; border-radius: 8px; background: #f5f3ff; color: #7c3aed; display: flex; align-items: center; justify-content: center; font-size: 15px;">
                            <i class="fa-solid fa-server"></i>
                        </div>
                        <div>
                            <h4 style="margin: 0; font-size: 15px; font-weight: 800; color: #0f172a;">Requirement 2: Backup VM Node Setup</h4>
                            <p style="margin: 2px 0 0 0; font-size: 11px; color: #64748b;">Configure private Tailscale mesh address & authentication token</p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeBackupVmModal" style="background: none; border: none; font-size: 22px; color: #64748b; cursor: pointer; line-height: 1;">&times;</button>
                </div>

                <!-- Modal Body (Scrollable) -->
                <div style="padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px;">
                    <!-- Backup VM Address Field -->
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                            <i class="fa-solid fa-network-wired" style="color: #7c3aed; margin-right: 4px;"></i> Backup VM Tailscale Address / Origin URL:
                        </label>
                        <input type="text" 
                               wire:model="backupVmUrl" 
                               placeholder="http://100.x.y.z:80 (or http://backup-vm:80)" 
                               style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px; font-family: monospace;">
                        <p style="font-size: 11px; color: #64748b; margin: 4px 0 0 0;">
                            Enter the private Tailscale IP (starts with <code>100.</code>) or MagicDNS domain name of your secondary virtual machine.
                        </p>
                    </div>

                    <!-- Cluster Secret Token Field -->
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                            <i class="fa-solid fa-key" style="color: #f59e0b; margin-right: 4px;"></i> Cluster Secret Token:
                        </label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" 
                                   wire:model="clusterToken" 
                                   placeholder="Shared secret token..." 
                                   style="flex: 1; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px; font-family: monospace;">
                            <button type="button" 
                                    wire:click="generateNewToken" 
                                    title="Generate New Secret Token" 
                                    style="background: #f1f5f9; border: 1px solid #cbd5e1; padding: 0 14px; border-radius: 8px; color: #475569; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-dice"></i>
                                <span>Generate</span>
                            </button>
                        </div>
                        <p style="font-size: 11px; color: #64748b; margin: 4px 0 0 0;">
                            Both the Root VM and Backup VM must have this identical token configured to authenticate cluster requests and health checks.
                        </p>
                    </div>

                    <!-- Test Result Alert -->
                    @if ($testVmResult)
                        <div style="font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 8px; background: {{ $testVmResult['valid'] ? '#ecfdf5' : '#fef2f2' }}; color: {{ $testVmResult['valid'] ? '#047857' : '#b91c1c' }}; border: 1px solid {{ $testVmResult['valid'] ? '#a7f3d0' : '#fecaca' }};">
                            <i class="fa-solid {{ $testVmResult['valid'] ? 'fa-circle-check' : 'fa-circle-xmark' }}" style="font-size: 14px;"></i>
                            <span>{{ $testVmResult['message'] }}</span>
                        </div>
                    @endif

                    <!-- Step-by-Step Setup Instructions -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px;">
                        <h5 style="margin: 0 0 8px 0; font-size: 12px; font-weight: 800; color: #1e293b;">
                            <i class="fa-solid fa-circle-info" style="color: #2563eb; margin-right: 4px;"></i> How to configure the Backup VM:
                        </h5>
                        <ol style="margin: 0; padding-left: 18px; font-size: 11px; color: #475569; line-height: 1.6;">
                            <li><strong>Install Tailscale:</strong> Run <code>curl -fsSL https://tailscale.com/install.sh | sh && tailscale up</code> on your second VM.</li>
                            <li><strong>Clone Repository:</strong> Clone the RMS repository onto the second VM and prepare the <code>.env</code> file.</li>
                            <li><strong>Match Credentials:</strong> Set the identical <code>CLUSTER_SECRET_TOKEN</code> and Neon Postgres <code>DB_*</code> variables in the second VM's <code>.env</code>.</li>
                            <li><strong>Start Services:</strong> Run <code>docker compose up -d app caddy</code> on the Backup VM (no local database required).</li>
                            <li><strong>Verify:</strong> Click <strong>Test Connection</strong> below to verify the Root VM can ping the Backup node.</li>
                        </ol>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div style="padding: 14px 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; flex-wrap: wrap; gap: 10px;">
                    <button type="button" 
                            wire:click="testBackupVmConnection" 
                            wire:loading.attr="disabled" 
                            style="background: #ffffff; border: 1px solid #cbd5e1; color: #1e293b; padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-network-wired" wire:loading.remove wire:target="testBackupVmConnection"></i>
                        <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="testBackupVmConnection"></i>
                        <span>Test Connection</span>
                    </button>

                    <div style="display: flex; gap: 8px; align-items: center;">
                        <button type="button" wire:click="closeBackupVmModal" style="background: #ffffff; border: 1px solid #cbd5e1; color: #64748b; padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer;">
                            Cancel
                        </button>
                        <button type="button" 
                                wire:click="saveClusterConfig" 
                                wire:loading.attr="disabled" 
                                style="background: #7c3aed; color: white; border: none; padding: 8px 18px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(124, 58, 237, 0.25);">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <span>Save & Verify Node</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

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
