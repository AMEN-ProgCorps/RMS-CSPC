<?php

use App\Services\ServerManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Site Diagnostic')] class extends Component {
    // Database diagnostic
    public array $dbDiagnostic = [];

    // Cloud storage diagnostic
    public array $driveDiagnostic = [];

    // Network & ingress diagnostic
    public array $networkDiagnostic = [];

    // PHP & engine diagnostic
    public array $engineDiagnostic = [];

    public string $lastTestedAt = '';

    public function mount(ServerManagementService $service): void
    {
        $this->runAllDiagnostics($service);
    }

    public function runAllDiagnostics(ServerManagementService $service): void
    {
        $this->diagnoseDatabase($service);
        $this->diagnoseGoogleDrive();
        $this->diagnoseNetwork();
        $this->diagnosePhpEngine();

        $this->lastTestedAt = now()->format('Y-m-d H:i:s');
    }

    protected function diagnoseDatabase(ServerManagementService $service): void
    {
        $dbCheck = $service->validateExternalDatabase();
        $version = 'unknown';
        $tableCount = 0;
        $connectionCount = 1;
        $sslStatus = 'unknown';

        if ($dbCheck['valid']) {
            try {
                $verRow = DB::select("SELECT version() as ver");
                $version = $verRow[0]->ver ?? 'PostgreSQL';

                $tblRow = DB::select("SELECT count(*) as cnt FROM information_schema.tables WHERE table_schema = 'public'");
                $tableCount = (int) ($tblRow[0]->cnt ?? 0);

                $connRow = DB::select("SELECT count(*) as cnt FROM pg_stat_activity");
                $connectionCount = (int) ($connRow[0]->cnt ?? 1);

                $sslStatus = config('database.connections.pgsql.sslmode') ?: env('DB_SSLMODE', 'require');
            } catch (\Throwable $e) {
                // Ignore failure on metadata
            }
        }

        $this->dbDiagnostic = [
            'valid' => $dbCheck['valid'],
            'host' => $dbCheck['host'] ?? '127.0.0.1',
            'database' => $dbCheck['database'] ?? 'rms',
            'is_external' => $dbCheck['is_external'] ?? false,
            'is_pooler' => $dbCheck['is_pooler'] ?? false,
            'latency_ms' => $dbCheck['latency_ms'],
            'message' => $dbCheck['message'] ?? '',
            'version' => $version,
            'tables_count' => $tableCount,
            'active_connections' => $connectionCount,
            'ssl_mode' => $sslStatus,
        ];
    }

    protected function diagnoseGoogleDrive(): void
    {
        $start = microtime(true);
        $status = 'healthy';
        $message = 'Google Drive API connected and responsive.';
        $latencyMs = 0;

        try {
            $isConfigured = !empty(env('GOOGLE_DRIVE_CLIENT_ID')) || !empty(env('GOOGLE_DRIVE_FOLDER_ID'));
            if (!$isConfigured) {
                $status = 'unconfigured';
                $message = 'Google Drive credentials not set in environment or database.';
            } else {
                // Test drive storage disk
                $files = Storage::disk('google')->files();
                $latencyMs = round((microtime(true) - $start) * 1000, 2);
            }
        } catch (\Throwable $e) {
            $status = 'error';
            $message = 'Failed to connect to Google Drive: ' . $e->getMessage();
        }

        $this->driveDiagnostic = [
            'status' => $status,
            'message' => $message,
            'latency_ms' => $latencyMs,
            'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID', '1tGkgf7DGmxzMRjwj42hjwyYwvRfhh-_F'),
            'disk_driver' => config('filesystems.default', 'google'),
        ];
    }

    protected function diagnoseNetwork(): void
    {
        $request = request();
        $isCloudflare = !empty($request->header('CF-Ray')) || !empty($request->server('HTTP_CF_CONNECTING_IP'));
        $clientIp = $request->header('CF-Connecting-IP') 
            ?: $request->header('X-Forwarded-For') 
            ?: $request->ip();

        $cfRay = $request->header('CF-Ray') ?: 'Not detected (Direct Access)';
        $cfCountry = $request->header('CF-IPCountry') ?: 'N/A';

        $this->networkDiagnostic = [
            'is_cloudflare' => $isCloudflare,
            'client_ip' => $clientIp,
            'cf_ray' => $cfRay,
            'cf_country' => $cfCountry,
            'server_ip' => $request->server('SERVER_ADDR') ?: '127.0.0.1',
            'server_port' => $request->server('SERVER_PORT') ?: '80',
            'scheme' => $request->secure() ? 'HTTPS' : 'HTTP',
        ];
    }

    protected function diagnosePhpEngine(): void
    {
        $opcacheEnabled = function_exists('opcache_get_status') && !empty(opcache_get_status(false)['opcache_enabled']);
        $opcacheHitRate = 0.0;
        $opcacheMemoryUsedMb = 0;

        if ($opcacheEnabled) {
            $status = opcache_get_status(false);
            $opcacheHitRate = round($status['opcache_statistics']['opcache_hit_rate'] ?? 0, 1);
            $usedBytes = $status['memory_usage']['used_memory'] ?? 0;
            $opcacheMemoryUsedMb = round($usedBytes / (1024 * 1024), 1);
        }

        $this->engineDiagnostic = [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time') . 's',
            'opcache_enabled' => $opcacheEnabled,
            'opcache_hit_rate' => $opcacheHitRate,
            'opcache_memory_used_mb' => $opcacheMemoryUsedMb,
            'session_driver' => config('session.driver', 'database'),
            'cache_driver' => config('cache.default', 'database'),
            'queue_driver' => config('queue.default', 'database'),
        ];
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
    </style>
@endpush

<div class="space-y-6">
    <!-- Header Banner -->
    <div style="background: #ffffff; padding: 24px 28px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #faf5ff; color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 4px 8px rgba(147, 51, 234, 0.15);">
                <i class="fa-solid fa-chart-line"></i>
            </div>
            <div>
                <h1 style="font-size: 22px; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.02em;">Site Diagnostic & Latency Monitor</h1>
                <p style="font-size: 13px; color: #64748b; margin: 4px 0 0 0;">
                    Live health telemetry across external database, Google Drive storage, Cloudflare tunnel, and PHP execution engine
                </p>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 11px; color: #64748b;">Last benchmark: {{ $lastTestedAt ?: 'Just now' }}</span>
            <button type="button" wire:click="runAllDiagnostics" wire:loading.attr="disabled" style="background: #0f172a; color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-bolt" wire:loading.remove wire:target="runAllDiagnostics"></i>
                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="runAllDiagnostics"></i>
                <span>Benchmark Now</span>
            </button>
        </div>
    </div>

    <!-- Diagnostic Cards Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px;">
        <!-- 1. Database Diagnostic (Neon PostgreSQL) -->
        <div style="background: #ffffff; padding: 22px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 36px; height: 36px; border-radius: 8px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-database"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 15px; font-weight: 800; color: #0f172a; margin: 0;">Database (PostgreSQL)</h3>
                        <span style="font-size: 11px; color: #64748b;">Centralized state storage</span>
                    </div>
                </div>
                <span style="font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; background: {{ $dbDiagnostic['valid'] ? '#dcfce7' : '#fee2e2' }}; color: {{ $dbDiagnostic['valid'] ? '#15803d' : '#b91c1c' }};">
                    {{ $dbDiagnostic['valid'] ? 'Connected' : 'Offline' }}
                </span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 10px; font-size: 12px;">
                <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                    <span style="color: #64748b;">Query Ping Latency:</span>
                    <strong style="color: {{ ($dbDiagnostic['latency_ms'] ?? 0) < 100 ? '#10b981' : '#f59e0b' }};">
                        {{ $dbDiagnostic['latency_ms'] ?? 'N/A' }} ms
                    </strong>
                </div>

                <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                    <span style="color: #64748b;">Connection Pooler:</span>
                    <strong style="color: {{ $dbDiagnostic['is_pooler'] ? '#10b981' : '#64748b' }};">
                        {{ $dbDiagnostic['is_pooler'] ? 'Neon PgBouncer (Active)' : 'Direct Connection' }}
                    </strong>
                </div>

                <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                    <span style="color: #64748b;">SSL Mode:</span>
                    <strong style="color: #0f172a;">{{ $dbDiagnostic['ssl_mode'] ?? 'require' }}</strong>
                </div>

                <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                    <span style="color: #64748b;">Public Tables Count:</span>
                    <strong style="color: #0f172a;">{{ $dbDiagnostic['tables_count'] ?? 0 }} tables</strong>
                </div>

                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #64748b;">Active DB Connections:</span>
                    <strong style="color: #0f172a;">{{ $dbDiagnostic['active_connections'] ?? 1 }}</strong>
                </div>
            </div>
        </div>

        <!-- 2. Cloud Storage Diagnostic (Google Drive) -->
        <div style="background: #ffffff; padding: 22px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 36px; height: 36px; border-radius: 8px; background: #ecfdf5; color: #059669; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-cloud"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 15px; font-weight: 800; color: #0f172a; margin: 0;">Storage (Google Drive)</h3>
                        <span style="font-size: 11px; color: #64748b;">Centralized filesystem</span>
                    </div>
                </div>
                <span style="font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; background: {{ $driveDiagnostic['status'] === 'healthy' ? '#dcfce7' : '#fee2e2' }}; color: {{ $driveDiagnostic['status'] === 'healthy' ? '#15803d' : '#b91c1c' }};">
                    {{ $driveDiagnostic['status'] }}
                </span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 10px; font-size: 12px;">
                <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                    <span style="color: #64748b;">API Response Time:</span>
                    <strong style="color: #10b981;">{{ $driveDiagnostic['latency_ms'] ?? 0 }} ms</strong>
                </div>

                <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                    <span style="color: #64748b;">Default Filesystem Disk:</span>
                    <strong style="color: #0f172a;">{{ $driveDiagnostic['disk_driver'] ?? 'google' }}</strong>
                </div>

                <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                    <span style="color: #64748b;">Drive Folder ID:</span>
                    <code style="font-size: 11px;">{{ substr($driveDiagnostic['folder_id'] ?? '', 0, 10) }}...</code>
                </div>

                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #64748b;">API Status:</span>
                    <span style="color: {{ $driveDiagnostic['status'] === 'healthy' ? '#059669' : '#b45309' }};">
                        {{ $driveDiagnostic['message'] }}
                    </span>
                </div>
            </div>
        </div>

        <!-- 3. Network & Ingress Diagnostic (Cloudflare Tunnel) -->
        <div style="background: #ffffff; padding: 22px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 36px; height: 36px; border-radius: 8px; background: #fff7ed; color: #ea580c; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 15px; font-weight: 800; color: #0f172a; margin: 0;">Ingress & Cloudflare</h3>
                        <span style="font-size: 11px; color: #64748b;">Traffic distribution & edge</span>
                    </div>
                </div>
                <span style="font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; background: {{ $networkDiagnostic['is_cloudflare'] ? '#dcfce7' : '#f1f5f9' }}; color: {{ $networkDiagnostic['is_cloudflare'] ? '#15803d' : '#475569' }};">
                    {{ $networkDiagnostic['is_cloudflare'] ? 'CF Tunnel' : 'Direct IP' }}
                </span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 10px; font-size: 12px;">
                <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                    <span style="color: #64748b;">Client Real IP:</span>
                    <strong style="color: #0f172a;">{{ $networkDiagnostic['client_ip'] ?? '127.0.0.1' }}</strong>
                </div>

                <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                    <span style="color: #64748b;">Cloudflare Ray ID:</span>
                    <code style="font-size: 11px;">{{ $networkDiagnostic['cf_ray'] }}</code>
                </div>

                <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                    <span style="color: #64748b;">Client Country:</span>
                    <strong style="color: #0f172a;">{{ $networkDiagnostic['cf_country'] }}</strong>
                </div>

                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #64748b;">Protocol & Port:</span>
                    <strong style="color: #0f172a;">{{ $networkDiagnostic['scheme'] }} &bull; Port {{ $networkDiagnostic['server_port'] }}</strong>
                </div>
            </div>
        </div>

        <!-- 4. PHP Runtime & Cache Optimization -->
        <div style="background: #ffffff; padding: 22px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 36px; height: 36px; border-radius: 8px; background: #fdf2f8; color: #db2777; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-gauge-high"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 15px; font-weight: 800; color: #0f172a; margin: 0;">PHP Engine & OPcache</h3>
                        <span style="font-size: 11px; color: #64748b;">Execution performance</span>
                    </div>
                </div>
                <span style="font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; background: {{ $engineDiagnostic['opcache_enabled'] ? '#dcfce7' : '#fee2e2' }}; color: {{ $engineDiagnostic['opcache_enabled'] ? '#15803d' : '#b91c1c' }};">
                    {{ $engineDiagnostic['opcache_enabled'] ? 'OPcache On' : 'OPcache Off' }}
                </span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 10px; font-size: 12px;">
                <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                    <span style="color: #64748b;">OPcache Hit Rate:</span>
                    <strong style="color: #10b981;">{{ $engineDiagnostic['opcache_hit_rate'] ?? 0 }}%</strong>
                </div>

                <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                    <span style="color: #64748b;">Memory Limit:</span>
                    <strong style="color: #0f172a;">{{ $engineDiagnostic['memory_limit'] }}</strong>
                </div>

                <div style="display: flex; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                    <span style="color: #64748b;">Session & Cache Driver:</span>
                    <strong style="color: #2563eb;">{{ $engineDiagnostic['session_driver'] }} / {{ $engineDiagnostic['cache_driver'] }}</strong>
                </div>

                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #64748b;">Max Execution Time:</span>
                    <strong style="color: #0f172a;">{{ $engineDiagnostic['max_execution_time'] }}</strong>
                </div>
            </div>
        </div>
    </div>
</div>
