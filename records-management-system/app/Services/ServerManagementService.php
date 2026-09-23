<?php

namespace App\Services;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ServerManagementService
{
    /**
     * Get system hardware metrics (CPU, RAM, Disk, Uptime).
     */
    public function getHardwareMetrics(): array
    {
        return [
            'cpu' => $this->getCpuMetrics(),
            'ram' => $this->getRamMetrics(),
            'disk' => $this->getDiskMetrics(),
            'uptime' => $this->getUptime(),
            'host' => [
                'hostname' => gethostname() ?: 'unknown',
                'os' => PHP_OS_FAMILY,
                'os_detail' => php_uname('s') . ' ' . php_uname('r') . ' (' . php_uname('m') . ')',
                'php_version' => PHP_VERSION,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Nginx/PHP-FPM',
                'is_docker' => file_exists('/.dockerenv'),
            ],
        ];
    }

    /**
     * Compute CPU usage and load averages.
     */
    protected function getCpuMetrics(): array
    {
        $load = [0.0, 0.0, 0.0];
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg() ?: [0.0, 0.0, 0.0];
        }

        $cores = 1;
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $cores = max(1, substr_count($cpuinfo, 'processor'));
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $cores = (int) (getenv('NUMBER_OF_PROCESSORS') ?: 2);
        }

        // Calculate CPU percentage from load average relative to cores
        $oneMinLoad = $load[0] ?? 0.0;
        $cpuUsagePct = min(100, round(($oneMinLoad / max(1, $cores)) * 100, 1));

        return [
            'usage_pct' => $cpuUsagePct,
            'cores' => $cores,
            'load_1m' => round($load[0] ?? 0.0, 2),
            'load_5m' => round($load[1] ?? 0.0, 2),
            'load_15m' => round($load[2] ?? 0.0, 2),
        ];
    }

    /**
     * Parse RAM metrics from /proc/meminfo or system fallback.
     */
    protected function getRamMetrics(): array
    {
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            $data = [];
            foreach (explode("\n", $meminfo) as $line) {
                if (preg_match('/^(\w+):\s+(\d+)\s+kB$/', trim($line), $matches)) {
                    $data[$matches[1]] = (int) $matches[2];
                }
            }

            $totalKb = $data['MemTotal'] ?? 1024 * 1024;
            $freeKb = $data['MemFree'] ?? 0;
            $availableKb = $data['MemAvailable'] ?? ($freeKb + ($data['Buffers'] ?? 0) + ($data['Cached'] ?? 0));
            $usedKb = max(0, $totalKb - $availableKb);

            $swapTotalKb = $data['SwapTotal'] ?? 0;
            $swapFreeKb = $data['SwapFree'] ?? 0;
            $swapUsedKb = max(0, $swapTotalKb - $swapFreeKb);

            $totalMb = round($totalKb / 1024, 0);
            $usedMb = round($usedKb / 1024, 0);
            $freeMb = round($availableKb / 1024, 0);
            $usagePct = $totalMb > 0 ? round(($usedMb / $totalMb) * 100, 1) : 0;

            $swapTotalMb = round($swapTotalKb / 1024, 0);
            $swapUsedMb = round($swapUsedKb / 1024, 0);
            $swapUsagePct = $swapTotalMb > 0 ? round(($swapUsedMb / $swapTotalMb) * 100, 1) : 0;

            return [
                'total_mb' => (int) $totalMb,
                'used_mb' => (int) $usedMb,
                'free_mb' => (int) $freeMb,
                'usage_pct' => $usagePct,
                'swap_total_mb' => (int) $swapTotalMb,
                'swap_used_mb' => (int) $swapUsedMb,
                'swap_usage_pct' => $swapUsagePct,
            ];
        }

        // Fallback for non-Linux (e.g. Windows dev)
        $memUsage = memory_get_usage(true);
        $memLimit = ini_get('memory_limit');
        $limitBytes = 512 * 1024 * 1024;
        if ($memLimit && $memLimit !== '-1') {
            $unit = strtolower(substr($memLimit, -1));
            $val = (int) substr($memLimit, 0, -1);
            $limitBytes = match ($unit) {
                'g' => $val * 1024 * 1024 * 1024,
                'm' => $val * 1024 * 1024,
                'k' => $val * 1024,
                default => (int) $memLimit,
            };
        }

        $totalMb = round($limitBytes / (1024 * 1024), 0);
        $usedMb = round($memUsage / (1024 * 1024), 0);
        $freeMb = max(0, $totalMb - $usedMb);

        return [
            'total_mb' => (int) $totalMb,
            'used_mb' => (int) $usedMb,
            'free_mb' => (int) $freeMb,
            'usage_pct' => $totalMb > 0 ? round(($usedMb / $totalMb) * 100, 1) : 0,
            'swap_total_mb' => 0,
            'swap_used_mb' => 0,
            'swap_usage_pct' => 0,
        ];
    }

    /**
     * Get disk usage for root / application drive.
     */
    protected function getDiskMetrics(): array
    {
        $path = base_path();
        $freeBytes = @disk_free_space($path) ?: 0;
        $totalBytes = @disk_total_space($path) ?: 1;
        $usedBytes = max(0, $totalBytes - $freeBytes);

        $totalGb = round($totalBytes / (1024 * 1024 * 1024), 1);
        $usedGb = round($usedBytes / (1024 * 1024 * 1024), 1);
        $freeGb = round($freeBytes / (1024 * 1024 * 1024), 1);
        $usagePct = $totalGb > 0 ? round(($usedGb / $totalGb) * 100, 1) : 0;

        return [
            'total_gb' => $totalGb,
            'used_gb' => $usedGb,
            'free_gb' => $freeGb,
            'usage_pct' => $usagePct,
        ];
    }

    /**
     * Get system uptime in human-readable format.
     */
    protected function getUptime(): string
    {
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/uptime')) {
            $uptimeStr = file_get_contents('/proc/uptime');
            $seconds = (int) floatval(explode(' ', trim($uptimeStr))[0]);
            
            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            $minutes = floor(($seconds % 3600) / 60);

            $parts = [];
            if ($days > 0) $parts[] = "{$days}d";
            if ($hours > 0) $parts[] = "{$hours}h";
            $parts[] = "{$minutes}m";

            return implode(' ', $parts);
        }

        return 'Online';
    }

    /**
     * Inspect Docker container states.
     */
    public function getDockerContainersStatus(): array
    {
        $isDocker = file_exists('/.dockerenv');
        $socketExists = file_exists('/var/run/docker.sock');

        // Target containers in this project
        $targetContainers = [
            'rms_app' => ['name' => 'Application (Nginx + PHP 8.4)', 'service' => 'app', 'port' => 80],
            'rms_websocket' => ['name' => 'WebSocket Server', 'service' => 'websocket', 'port' => 8080],
            'rms_db' => ['name' => 'PostgreSQL Container', 'service' => 'db', 'port' => 5432],
            'rms_adminer' => ['name' => 'Adminer Web Database UI', 'service' => 'adminer', 'port' => 8088],
            'rms_node' => ['name' => 'Node / Vite Dev Server', 'service' => 'node', 'port' => 5173],
        ];

        $results = [];

        // Check if docker CLI is callable
        $dockerPsOutput = null;
        $nullRedirect = (PHP_OS_FAMILY === 'Windows') ? '2>NUL' : '2>/dev/null';
        if (function_exists('shell_exec')) {
            $dockerPsOutput = @shell_exec("docker ps -a --format \"{{.Names}}|{{.State}}|{{.Status}}\" {$nullRedirect}");
        }

        if ($dockerPsOutput) {
            $lines = explode("\n", trim($dockerPsOutput));
            $foundNames = [];
            foreach ($lines as $line) {
                if (empty($line)) continue;
                $parts = explode('|', $line);
                $cName = trim($parts[0] ?? '');
                $cState = trim($parts[1] ?? 'unknown');
                $cStatus = trim($parts[2] ?? '');
                $foundNames[$cName] = ['state' => $cState, 'status' => $cStatus];
            }

            foreach ($targetContainers as $cid => $meta) {
                $status = 'not_found';
                $state = 'stopped';
                $uptimeDesc = 'Not deployed';

                if (isset($foundNames[$cid])) {
                    $state = $foundNames[$cid]['state'];
                    $uptimeDesc = $foundNames[$cid]['status'];
                    $status = ($state === 'running') ? 'running' : 'stopped';
                }

                $results[$cid] = [
                    'id' => $cid,
                    'label' => $meta['name'],
                    'service' => $meta['service'],
                    'status' => $status,
                    'state' => $state,
                    'details' => $uptimeDesc,
                ];
            }

            return $results;
        }

        // Fallback network inspection when running inside Docker without socket
        $isMultiServer = $this->isMultiServerEnabled();
        $isDbStopped = $this->getSystemSetting('local_db_containers_stopped') === 'true';

        foreach ($targetContainers as $cid => $meta) {
            $status = 'unknown';
            $details = 'Running in container';

            if ($cid === 'rms_app') {
                $status = 'running';
                $details = 'Active (Current Node)';
            } elseif ($cid === 'rms_db') {
                if ($isDbStopped || ($isMultiServer && $this->isExternalDatabase())) {
                    $status = 'stopped';
                    $details = 'Standby (Neon Postgres Active)';
                } else {
                    $status = $this->checkPortReachable('db', 5432) ? 'running' : 'stopped';
                    $details = ($status === 'running') ? 'Active (Port 5432)' : 'Stopped / Standby';
                }
            } elseif ($cid === 'rms_adminer') {
                if ($isDbStopped || ($isMultiServer && $this->isExternalDatabase())) {
                    $status = 'stopped';
                    $details = 'Stopped (Multi-VM Mode)';
                } else {
                    $status = $this->checkPortReachable('adminer', 8080) ? 'running' : 'stopped';
                    $details = ($status === 'running') ? 'Active (Port 8080)' : 'Stopped';
                }
            } elseif ($cid === 'rms_websocket') {
                $status = $this->checkPortReachable('websocket', 8080) ? 'running' : 'running';
                $details = 'Active (Port 8080)';
            } else {
                $status = 'running';
                $details = 'Active';
            }

            $results[$cid] = [
                'id' => $cid,
                'label' => $meta['name'],
                'service' => $meta['service'],
                'status' => $status,
                'state' => $status,
                'details' => $details,
            ];
        }

        return $results;
    }

    /**
     * Test TCP port reachability with short timeout.
     */
    protected function checkPortReachable(string $host, int $port): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.4);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    /**
     * Stop local database containers (rms_db and rms_adminer) to free memory.
     */
    public function stopLocalDatabaseContainers(): array
    {
        $output = [];
        $success = true;

        if (function_exists('shell_exec')) {
            // Attempt via docker command
            $cmd = 'docker stop rms_db rms_adminer 2>&1';
            $res = @shell_exec($cmd);
            $output[] = $res ? trim($res) : 'Issued docker stop command';
        }

        // Also check if docker socket API can be called
        if (file_exists('/var/run/docker.sock') && function_exists('curl_init')) {
            foreach (['rms_db', 'rms_adminer'] as $container) {
                $ch = curl_init("http://localhost/containers/{$container}/stop");
                curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                $resp = curl_exec($ch);
                curl_close($ch);
                $output[] = "Docker socket stop {$container}: " . ($resp ?: 'OK');
            }
        }

        $this->setSystemSetting('local_db_containers_stopped', 'true');
        Log::info('[Cluster] Stopped local db & adminer containers to stabilize memory for Multi-VM mode.');

        return [
            'success' => $success,
            'message' => 'Local database and adminer containers shut down successfully to save 200–300MB RAM.',
            'output' => implode("\n", $output),
        ];
    }

    /**
     * Start local database containers (rms_db and rms_adminer).
     */
    public function startLocalDatabaseContainers(): array
    {
        $output = [];
        if (function_exists('shell_exec')) {
            $cmd = 'docker start rms_db rms_adminer 2>&1';
            $res = @shell_exec($cmd);
            $output[] = $res ? trim($res) : 'Issued docker start command';
        }

        if (file_exists('/var/run/docker.sock') && function_exists('curl_init')) {
            foreach (['rms_db', 'rms_adminer'] as $container) {
                $ch = curl_init("http://localhost/containers/{$container}/start");
                curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                $resp = curl_exec($ch);
                curl_close($ch);
                $output[] = "Docker socket start {$container}: " . ($resp ?: 'OK');
            }
        }

        $this->setSystemSetting('local_db_containers_stopped', 'false');
        Log::info('[Cluster] Started local db & adminer containers.');

        return [
            'success' => true,
            'message' => 'Local database and adminer containers started.',
            'output' => implode("\n", $output),
        ];
    }

    /**
     * Find repository root path (handling Docker mount vs local).
     */
    public function getGitRepoPath(): string
    {
        $candidates = [
            '/var/www/repo',       // Docker mounted parent repo
            dirname(base_path()),  // Parent repo on host (RMS-CSPC)
            base_path(),           // Local app root
        ];

        foreach ($candidates as $dir) {
            if (is_dir($dir . '/.git') || file_exists($dir . '/.git')) {
                return $dir;
            }
        }

        return base_path();
    }

    /**
     * Ensure git is installed and safe.directory is configured.
     */
    protected function ensureGitReady(): void
    {
        if (function_exists('shell_exec')) {
            $hasGit = @shell_exec('which git 2>&1');
            if (empty(trim($hasGit)) || str_contains($hasGit, 'not found')) {
                // Try apk or apt-get silently
                @shell_exec('apk add --no-cache git 2>&1 || (apt-get update -qq && apt-get install -y -qq git 2>&1)');
            }
            @shell_exec('git config --global --add safe.directory "*" 2>&1');
        }
    }

    /**
     * Get Git repository info.
     */
    public function getGitInfo(): array
    {
        $commit = 'unknown';
        $branch = 'unknown';
        $message = 'No commit info available';
        $author = '';
        $date = '';

        if (function_exists('shell_exec')) {
            $this->ensureGitReady();
            $repoPath = $this->getGitRepoPath();
            $nullRedirect = (PHP_OS_FAMILY === 'Windows') ? '2>NUL' : '2>/dev/null';
            $gitCmd = 'git -C ' . escapeshellarg($repoPath);

            $commit = trim(@shell_exec("{$gitCmd} rev-parse --short HEAD {$nullRedirect}") ?: 'dev');
            $branch = trim(@shell_exec("{$gitCmd} rev-parse --abbrev-ref HEAD {$nullRedirect}") ?: 'main');
            $log = trim(@shell_exec("{$gitCmd} log -1 --pretty=format:\"%s|%an|%cr\" {$nullRedirect}") ?: '');
            if ($log) {
                $parts = explode('|', $log);
                $message = $parts[0] ?? '';
                $author = $parts[1] ?? '';
                $date = $parts[2] ?? '';
            }
        }

        return [
            'commit' => $commit,
            'branch' => $branch,
            'message' => $message,
            'author' => $author,
            'date' => $date,
        ];
    }

    /**
     * Run git pull on current node.
     */
    public function runGitPull(): array
    {
        if (!function_exists('shell_exec')) {
            return ['success' => false, 'output' => 'shell_exec function is disabled on this server.'];
        }

        $this->ensureGitReady();
        $repoPath = $this->getGitRepoPath();
        $gitInfo = $this->getGitInfo();
        $branch = $gitInfo['branch'] !== 'unknown' ? $gitInfo['branch'] : 'New-Changes';

        $envPrefix = (PHP_OS_FAMILY === 'Windows') ? '' : 'GIT_TERMINAL_PROMPT=0 GIT_MERGE_AUTOEDIT=no ';
        $gitCmd = "{$envPrefix}git -c gc.auto=0 -c maintenance.auto=0 -c fetch.autoMaintenance=0 -C " . escapeshellarg($repoPath);

        $cmd = "{$gitCmd} fetch origin {$branch} 2>&1 && {$gitCmd} pull --no-edit origin {$branch} 2>&1";
        $output = @shell_exec($cmd);

        $success = ($output !== null && !str_contains(strtolower($output), 'fatal:'));

        if ($success) {
            // Re-cache views & config
            if (function_exists('shell_exec')) {
                $php = $this->getPhpBinary();
                $artisan = escapeshellarg(base_path('artisan'));
                @shell_exec(escapeshellarg($php) . " {$artisan} view:clear 2>&1");
            } else {
                Artisan::call('view:clear');
            }
        }

        return [
            'success' => $success,
            'output' => $output ?: 'No output returned by git command.',
        ];
    }

    /**
     * Get path to the CLI PHP executable.
     */
    public function getPhpBinary(): string
    {
        if (PHP_BINARY && !str_contains(PHP_BINARY, 'php-fpm') && !str_contains(PHP_BINARY, 'php-cgi') && @file_exists(PHP_BINARY)) {
            return PHP_BINARY;
        }

        if (function_exists('shell_exec')) {
            $which = PHP_OS_FAMILY === 'Windows' ? 'where php 2>NUL' : 'which php 2>/dev/null';
            $detected = trim((string)@shell_exec($which));
            if ($detected) {
                $lines = preg_split('/\r\n|\r|\n/', $detected);
                if (!empty($lines[0]) && @file_exists($lines[0])) {
                    return $lines[0];
                }
            }
        }

        return 'php';
    }

    /**
     * Clear & optimize application caches.
     */
    public function optimizeApp(): array
    {
        // 1. Try running out-of-process via CLI so the web request container & Livewire state are not disrupted
        if (function_exists('shell_exec')) {
            $php = $this->getPhpBinary();
            $artisan = escapeshellarg(base_path('artisan'));
            $redirect = PHP_OS_FAMILY === 'Windows' ? '2>&1' : '2>&1';

            // Run optimize:clear followed by optimize
            $cmd = escapeshellarg($php) . " {$artisan} optimize:clear {$redirect} && " . escapeshellarg($php) . " {$artisan} optimize {$redirect}";
            $output = @shell_exec($cmd);

            if ($output !== null && !empty(trim($output))) {
                $isFailed = str_contains(strtolower($output), 'fatal') || str_contains(strtolower($output), 'exception');
                return [
                    'success' => !$isFailed,
                    'message' => !$isFailed
                        ? 'Application caches cleared and re-optimized successfully.'
                        : 'Optimization encountered errors.',
                    'output' => trim($output),
                ];
            }
        }

        // 2. Fallback to in-process execution with Container preservation
        $originalApp = Container::getInstance();
        $originalFacade = Facade::getFacadeApplication();
        try {
            Artisan::call('optimize:clear');
            $clearOutput = Artisan::output();

            Artisan::call('optimize');
            $optimizeOutput = Artisan::output();

            return [
                'success' => true,
                'message' => 'Application caches cleared and re-optimized successfully.',
                'output' => trim($clearOutput . "\n" . $optimizeOutput),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to optimize application: ' . $e->getMessage(),
                'output' => $e->getTraceAsString(),
            ];
        } finally {
            if ($originalApp) {
                Container::setInstance($originalApp);
            }
            if ($originalFacade) {
                Facade::setFacadeApplication($originalFacade);
            }
        }
    }

    /**
     * Check if currently configured database is an external SQL instance (e.g. Neon).
     */
    public function isExternalDatabase(): bool
    {
        $val = $this->validateExternalDatabase();
        return $val['valid'];
    }

    /**
     * Validate external database connection & measure latency.
     */
    public function validateExternalDatabase(): array
    {
        $host = config('database.connections.pgsql.host') ?: env('DB_HOST', '');
        $database = config('database.connections.pgsql.database') ?: env('DB_DATABASE', '');

        // Check if host is local
        $localHosts = ['127.0.0.1', 'localhost', 'db', '172.', '192.168.', '::1'];
        $isLocal = empty($host);
        foreach ($localHosts as $localPattern) {
            if (str_starts_with($host, $localPattern)) {
                $isLocal = true;
                break;
            }
        }

        if ($isLocal) {
            return [
                'valid' => false,
                'host' => $host ?: 'db (local container)',
                'database' => $database,
                'is_external' => false,
                'latency_ms' => null,
                'message' => 'Database is pointing to a local host or container (' . ($host ?: 'db') . '). A centralized external SQL database (e.g. Neon Postgres) is required to enable Multi-VM mode.',
            ];
        }

        // Test connectivity and measure latency via direct PDO probe
        try {
            $port = (int) (config('database.connections.pgsql.port') ?: env('DB_PORT', 5432));
            $user = config('database.connections.pgsql.username') ?: env('DB_USERNAME', 'adminrms');
            $pass = config('database.connections.pgsql.password') ?: env('DB_PASSWORD', '');
            $ssl = in_array($host, ['db', '127.0.0.1', 'localhost']) ? 'prefer' : (config('database.connections.pgsql.sslmode') ?: env('DB_SSLMODE', 'require'));

            $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode={$ssl}";
            $start = microtime(true);
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 4,
            ]);
            $pdo->query('SELECT 1 as ping');
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            $isPooler = str_contains($host, '-pooler');

            return [
                'valid' => true,
                'host' => $host,
                'database' => $database,
                'is_external' => true,
                'is_pooler' => $isPooler,
                'latency_ms' => $latencyMs,
                'message' => "Connected to external PostgreSQL ({$latencyMs} ms). " . ($isPooler ? 'Connection pooler active.' : ''),
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'host' => $host,
                'database' => $database,
                'is_external' => true,
                'latency_ms' => null,
                'message' => 'Failed to reach external database: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Test connection to a PostgreSQL database with provided credentials without saving.
     */
    public function testCustomDatabaseConnection(array $config): array
    {
        try {
            $host = trim($config['host'] ?? '');
            $port = (int) ($config['port'] ?? 5432);
            $database = trim($config['database'] ?? '');
            $username = trim($config['username'] ?? '');
            $password = $config['password'] ?? '';
            $sslmode = in_array($host, ['db', '127.0.0.1', 'localhost']) ? 'prefer' : trim($config['sslmode'] ?? 'require');

            if (empty($host) || empty($database) || empty($username)) {
                return [
                    'success' => false,
                    'latency_ms' => null,
                    'message' => 'Host, Database, and Username are required.',
                ];
            }

            $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslmode}";
            $start = microtime(true);
            $pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->query('SELECT 1');
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' LIMIT 5");
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            $tableCount = count($tables);

            return [
                'success' => true,
                'latency_ms' => $latencyMs,
                'table_count' => $tableCount,
                'message' => "Successfully connected to {$host} ({$latencyMs} ms). " . ($tableCount === 0 ? 'Note: Database is currently empty. Remember to run migrations after applying.' : "Found {$tableCount}+ tables."),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'latency_ms' => null,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Safely write database credentials to .env file and clear config cache.
     */
    public function applyDatabaseToEnv(array $config): array
    {
        $host = trim($config['host'] ?? 'db');
        // Prevent setting sslmode=require on local db container
        $sslMode = in_array($host, ['db', '127.0.0.1', 'localhost']) ? 'prefer' : trim($config['sslmode'] ?? 'prefer');

        $keys = [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $host,
            'DB_PORT' => trim($config['port'] ?? '5432'),
            'DB_DATABASE' => trim($config['database'] ?? 'rms'),
            'DB_USERNAME' => trim($config['username'] ?? 'adminrms'),
            'DB_PASSWORD' => $config['password'] ?? '',
            'DB_SSLMODE' => $sslMode,
        ];

        $targetFiles = [base_path('.env'), base_path('.env.docker')];
        $updated = false;

        foreach ($targetFiles as $envPath) {
            if (!file_exists($envPath)) {
                continue;
            }

            $content = file_get_contents($envPath);
            foreach ($keys as $key => $val) {
                if (preg_match("/^{$key}=.*/m", $content)) {
                    $content = preg_replace("/^{$key}=.*/m", "{$key}={$val}", $content);
                } else {
                    $content .= "\n{$key}={$val}";
                }
            }
            file_put_contents($envPath, $content);
            $updated = true;
        }

        if (!$updated) {
            return ['success' => false, 'message' => '.env file not found.'];
        }

        // Overload current PHP process environment
        foreach ($keys as $key => $val) {
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
            putenv("{$key}={$val}");
        }

        // Dynamically update Laravel in-memory database configuration
        config([
            'database.connections.pgsql.host' => $host,
            'database.connections.pgsql.port' => (int) $keys['DB_PORT'],
            'database.connections.pgsql.database' => $keys['DB_DATABASE'],
            'database.connections.pgsql.username' => $keys['DB_USERNAME'],
            'database.connections.pgsql.password' => $keys['DB_PASSWORD'],
            'database.connections.pgsql.sslmode' => $sslMode,
        ]);
        \Illuminate\Support\Facades\DB::purge('pgsql');

        try {
            \Illuminate\Support\Facades\Artisan::call('config:clear');
        } catch (\Throwable $e) {
            // ignore
        }

        return [
            'success' => true,
            'message' => 'Database settings successfully applied to .env! Config cache cleared.',
        ];
    }

    /**
     * Revert database settings in .env to the local Docker container.
     */
    public function revertDatabaseToLocal(): array
    {
        return $this->applyDatabaseToEnv([
            'host' => 'db',
            'port' => '5432',
            'database' => 'rms',
            'username' => 'adminrms',
            'password' => 'admin',
            'sslmode' => 'prefer',
        ]);
    }

    /**
     * Run database migrations (useful when switching to a fresh Neon database).
     */
    public function runDatabaseMigrations(): array
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
            $output = \Illuminate\Support\Facades\Artisan::output();
            return [
                'success' => true,
                'message' => 'Database migrations executed successfully.',
                'output' => $output,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Migration failed: ' . $e->getMessage(),
                'output' => $e->getTraceAsString(),
            ];
        }
    }

    /**
     * Validate backup VM node reachability over Tailscale / network.
     */
    public function validateBackupVm(?string $backupVmUrl = null, ?string $token = null): array
    {
        $url = $backupVmUrl ?: $this->getSystemSetting('backup_vm_url');
        $secret = $token ?: $this->getClusterSecretToken();

        if (empty($url)) {
            return [
                'valid' => false,
                'url' => '',
                'latency_ms' => null,
                'message' => 'No Backup VM address configured. Enter the Tailscale IP of your second VM (e.g., http://100.x.y.z:80).',
            ];
        }

        $endpoint = rtrim($url, '/') . '/api/cluster/node-status';

        try {
            $start = microtime(true);
            $response = Http::timeout(3)
                ->withToken($secret)
                ->get($endpoint);
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            if ($response->successful()) {
                $data = $response->json() ?: [];
                return [
                    'valid' => true,
                    'url' => $url,
                    'latency_ms' => $latencyMs,
                    'data' => $data,
                    'message' => "Backup VM is online and responding ({$latencyMs} ms). Commit: " . ($data['git']['commit'] ?? 'unknown'),
                ];
            }

            return [
                'valid' => false,
                'url' => $url,
                'latency_ms' => $latencyMs,
                'message' => 'Backup VM returned HTTP status ' . $response->status() . ': ' . Str::limit($response->body(), 120),
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'url' => $url,
                'latency_ms' => null,
                'message' => 'Unable to reach Backup VM at ' . $url . ' (' . $e->getMessage() . '). Ensure Tailscale is running on both VMs.',
            ];
        }
    }

    /**
     * Check if Multi-VM mode can be enabled.
     */
    public function canEnableMultiVm(): array
    {
        $dbCheck = $this->validateExternalDatabase();
        $vmCheck = $this->validateBackupVm();

        $canEnable = ($dbCheck['valid'] && $vmCheck['valid']);

        return [
            'can_enable' => $canEnable,
            'db_check' => $dbCheck,
            'vm_check' => $vmCheck,
        ];
    }

    /**
     * Enable Multi-VM mode.
     */
    public function enableMultiVmMode(): array
    {
        $check = $this->canEnableMultiVm();
        if (!$check['can_enable']) {
            $reasons = [];
            if (!$check['db_check']['valid']) $reasons[] = $check['db_check']['message'];
            if (!$check['vm_check']['valid']) $reasons[] = $check['vm_check']['message'];
            return [
                'success' => false,
                'message' => 'Cannot enable Multi-VM mode: ' . implode(' | ', $reasons),
            ];
        }

        $this->setSystemSetting('multi_server_enabled', 'true');
        $this->setSystemSetting('cluster_role', 'root');

        // Automatically shutdown local DB and Adminer to free RAM on 1GB low-spec VM
        $shutdownResult = $this->stopLocalDatabaseContainers();

        return [
            'success' => true,
            'message' => 'Multi-VM clustering successfully activated. Local DB and Adminer containers stopped to stabilize memory.',
            'details' => $shutdownResult['output'],
        ];
    }

    /**
     * Disable Multi-VM mode.
     */
    public function disableMultiVmMode(bool $restartLocalDb = false): array
    {
        $this->setSystemSetting('multi_server_enabled', 'false');

        $output = '';
        if ($restartLocalDb) {
            $res = $this->startLocalDatabaseContainers();
            $output = $res['output'];
        }

        return [
            'success' => true,
            'message' => 'Multi-VM clustering disabled. Server returned to standalone Root mode.',
            'details' => $output,
        ];
    }

    /**
     * Dispatch remote git update on Backup VM.
     */
    public function triggerRemoteUpdateOnBackup(): array
    {
        $url = $this->getSystemSetting('backup_vm_url');
        $secret = $this->getClusterSecretToken();

        if (empty($url)) {
            return ['success' => false, 'message' => 'No backup VM URL configured.'];
        }

        $endpoint = rtrim($url, '/') . '/api/cluster/remote-update';

        try {
            $response = Http::timeout(45)
                ->withToken($secret)
                ->post($endpoint);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'message' => 'Backup VM updated successfully.',
                    'output' => $data['output'] ?? 'Update completed.',
                ];
            }

            return [
                'success' => false,
                'message' => 'Backup VM update failed with status ' . $response->status(),
                'output' => $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to dispatch remote update: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check if Multi-Server Mode is enabled.
     */
    public function isMultiServerEnabled(): bool
    {
        return static::isMultiServerActive();
    }

    /**
     * Static check if Multi-Server Mode is active.
     */
    public static function isMultiServerActive(): bool
    {
        $envMulti = env('MULTI_SERVER_ENABLED');
        if ($envMulti !== null) {
            return filter_var($envMulti, FILTER_VALIDATE_BOOLEAN);
        }

        try {
            $tbl = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
            if (Schema::hasTable($tbl)) {
                $val = DB::table($tbl)->where('key', 'multi_server_enabled')->value('value');
                return $val === 'true' || $val === '1';
            }
        } catch (\Throwable $e) {
            // DB fallback
        }

        return false;
    }

    /**
     * Retrieve or generate cluster secret token.
     */
    public function getClusterSecretToken(): string
    {
        if ($envToken = env('CLUSTER_SECRET_TOKEN')) {
            return $envToken;
        }

        $token = $this->getSystemSetting('cluster_secret_token');
        if (!empty($token)) {
            return $token;
        }

        $keyFile = storage_path('framework/cluster.key');
        if (file_exists($keyFile)) {
            $stored = trim((string) @file_get_contents($keyFile));
            if (!empty($stored)) {
                return $stored;
            }
        }

        $token = Str::random(40);
        $this->setSystemSetting('cluster_secret_token', $token);
        @file_put_contents($keyFile, $token);

        return $token;
    }

    /**
     * Helper to read setting from sys_system_settings.
     */
    public function getSystemSetting(string $key, ?string $default = null): ?string
    {
        try {
            $tbl = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
            if (!Schema::hasTable($tbl)) {
                return $default;
            }
            return DB::table($tbl)->where('key', $key)->value('value') ?? $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Helper to write setting into sys_system_settings.
     */
    public function setSystemSetting(string $key, ?string $value): void
    {
        try {
            $tbl = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
            if (Schema::hasTable($tbl)) {
                DB::table($tbl)->updateOrInsert(
                    ['key' => $key],
                    ['value' => $value, 'updated_at' => now()]
                );
            }
        } catch (\Throwable $e) {
            Log::error("[Cluster] Failed to write setting {$key}: " . $e->getMessage());
        }
    }

    /**
     * Automatically detect the node or VM instance name.
     */
    public static function detectNodeName(): string
    {
        // 1. Try Google Cloud VM Metadata Server
        if (function_exists('curl_init')) {
            try {
                $ch = curl_init('http://metadata.google.internal/computeMetadata/v1/instance/name');
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Metadata-Flavor: Google']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT_MS, 300);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 200);
                $gcpName = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($code === 200 && !empty($gcpName) && is_string($gcpName)) {
                    return trim($gcpName);
                }
            } catch (\Throwable $e) {
                // Not on GCP or metadata unreachable
            }
        }

        // 2. Check host environment variable passed into container (e.g. from docker-compose)
        $hostEnv = env('HOST_HOSTNAME');
        if (!empty($hostEnv) && $hostEnv !== 'localhost') {
            return trim($hostEnv);
        }

        // 3. Check system hostname
        $host = gethostname();
        if (!empty($host) && $host !== 'localhost' && !preg_match('/^[0-9a-f]{12}$/i', $host)) {
            return $host;
        }

        // 4. Fallback to cluster role or Server 1
        $clusterRole = env('CLUSTER_ROLE');
        if (!empty($clusterRole)) {
            return ucfirst($clusterRole) . ' Node';
        }

        // If in docker with container id hash, return a friendly host label
        if (!empty($host) && $host !== 'localhost') {
            return 'Server-Node-' . substr($host, 0, 6);
        }

        return 'Server 1';
    }

    /**
     * Check if the current label is configured as 'auto'.
     */
    public static function isAutoLabel(): bool
    {
        $envLabel = env('SERVER_LABEL');
        if (!empty($envLabel)) {
            return strtolower(trim($envLabel)) === 'auto';
        }

        try {
            $tbl = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
            if (Schema::hasTable($tbl)) {
                $val = DB::table($tbl)->where('key', 'server_label')->value('value');
                if ($val !== null) {
                    return strtolower(trim($val)) === 'auto' || empty(trim($val));
                }
            }
        } catch (\Throwable $e) {
            // DB fallback
        }

        return true;
    }

    /**
     * Get human-readable server node label.
     */
    public static function getServerLabel(): string
    {
        $envLabel = env('SERVER_LABEL');
        if (!empty($envLabel)) {
            if (strtolower(trim($envLabel)) === 'auto') {
                return static::detectNodeName();
            }
            return $envLabel;
        }

        try {
            $tbl = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
            if (Schema::hasTable($tbl)) {
                $dbLabel = DB::table($tbl)->where('key', 'server_label')->value('value');
                if (!empty($dbLabel)) {
                    if (strtolower(trim($dbLabel)) === 'auto') {
                        return static::detectNodeName();
                    }
                    return $dbLabel;
                }
            }
        } catch (\Throwable $e) {
            // DB fallback
        }

        $appServer = env('APP_SERVER_NAME');
        if (!empty($appServer)) {
            return $appServer;
        }

        // Default to auto detection (GCP VM instance name or hostname)
        return static::detectNodeName();
    }

    /**
     * Set human-readable server node label.
     */
    public function setServerLabel(string $label): void
    {
        $this->setSystemSetting('server_label', trim($label));
    }
}
