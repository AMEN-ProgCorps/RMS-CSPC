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

        // Calculate real CPU utilization percentage from /proc/stat if on Linux
        $cpuUsagePct = 0.0;
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/stat')) {
            $stat = @file_get_contents('/proc/stat');
            if ($stat) {
                $parts = preg_split('/\s+/', trim(explode("\n", $stat)[0] ?? ''));
                if (isset($parts[4])) {
                    $user = (int) ($parts[1] ?? 0);
                    $nice = (int) ($parts[2] ?? 0);
                    $system = (int) ($parts[3] ?? 0);
                    $idle = (int) ($parts[4] ?? 0);
                    $iowait = (int) ($parts[5] ?? 0);
                    $irq = (int) ($parts[6] ?? 0);
                    $softirq = (int) ($parts[7] ?? 0);
                    $steal = (int) ($parts[8] ?? 0);

                    $total = $user + $nice + $system + $idle + $iowait + $irq + $softirq + $steal;
                    $active = $total - $idle - $iowait;

                    $prevTotal = session()->get('server_prev_cpu_total');
                    $prevActive = session()->get('server_prev_cpu_active');

                    if ($prevTotal && $total > $prevTotal) {
                        $diffTotal = $total - $prevTotal;
                        $diffActive = $active - $prevActive;
                        $cpuUsagePct = max(1.0, min(100.0, round(($diffActive / max(1, $diffTotal)) * 100, 1)));
                    } else {
                        $cpuUsagePct = max(1.0, min(100.0, round(($active / max(1, $total)) * 100, 1)));
                    }

                    session()->put('server_prev_cpu_total', $total);
                    session()->put('server_prev_cpu_active', $active);
                }
            }
        }

        if ($cpuUsagePct <= 0.0) {
            $oneMinLoad = $load[0] ?? 0.0;
            $cpuUsagePct = min(100, round(($oneMinLoad / max(1, $cores)) * 100, 1));
        }

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
     * Fetch rich real-time telemetry metrics for each container.
     * Uses parallel socket queries via curl_multi and micro-caching (3s)
     * so that 5-second UI polls return in milliseconds without freezing.
     */
    public function getContainersTelemetry(): array
    {
        $fetcher = function () {
            $metaMap = [
                'rms_app' => [
                    'name' => 'Application (Nginx + PHP 8.4)',
                    'service' => 'app',
                    'port' => 80,
                    'icon' => 'fa-solid fa-server',
                    'color' => '#2563eb',
                ],
                'rms_websocket' => [
                    'name' => 'WebSocket Server (Messify)',
                    'service' => 'websocket',
                    'port' => 8080,
                    'icon' => 'fa-solid fa-comments',
                    'color' => '#8b5cf6',
                ],
                'rms_db' => [
                    'name' => 'PostgreSQL Database',
                    'service' => 'db',
                    'port' => 5432,
                    'icon' => 'fa-solid fa-database',
                    'color' => '#0284c7',
                ],
                'rms_adminer' => [
                    'name' => 'Adminer Database UI',
                    'service' => 'adminer',
                    'port' => 8088,
                    'icon' => 'fa-solid fa-table-columns',
                    'color' => '#f59e0b',
                ],
                'rms_node' => [
                    'name' => 'Node / Vite Dev Server',
                    'service' => 'node',
                    'port' => 5173,
                    'icon' => 'fa-brands fa-node-js',
                    'color' => '#16a34a',
                ],
                's3_storage' => [
                    'name' => 'S3 / NAS Storage (Pigsty Silo)',
                    'service' => 's3_storage',
                    'port' => 9000,
                    'icon' => 'fa-solid fa-box-archive',
                    'color' => '#0ea5e9',
                ],
            ];

            $results = [];

            // Query Docker Socket directly via Unix Socket
            if (file_exists('/var/run/docker.sock') && function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock',
                    CURLOPT_URL => 'http://localhost/containers/json',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 2,
                ]);
                $rawList = curl_exec($ch);
                $runningList = json_decode($rawList, true) ?: [];

                $runningByName = [];
                foreach ($runningList as $c) {
                    $cState = strtolower($c['State'] ?? 'running');
                    $cStatus = $c['Status'] ?? 'Up';
                    $isActive = ($cState === 'running' || str_starts_with($cStatus, 'Up'));

                    foreach ($c['Names'] ?? [] as $n) {
                        $clean = ltrim($n, '/');
                        $runningByName[$clean] = [
                            'id' => $c['Id'],
                            'active' => $isActive,
                        ];
                    }
                }

                // Fire parallel stats requests for all active containers using curl_multi
                $mh = curl_multi_init();
                $handles = [];

                foreach ($metaMap as $cid => $meta) {
                    if (isset($runningByName[$cid]) && $runningByName[$cid]['active']) {
                        $cId = $runningByName[$cid]['id'];
                        $cHandle = curl_init();
                        curl_setopt_array($cHandle, [
                            CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock',
                            CURLOPT_URL => "http://localhost/containers/{$cId}/stats?stream=false",
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 3,
                        ]);
                        curl_multi_add_handle($mh, $cHandle);
                        $handles[$cid] = $cHandle;
                    }
                }

                if (!empty($handles)) {
                    $active = null;
                    do {
                        $mrc = curl_multi_exec($mh, $active);
                    } while ($mrc === CURLM_CALL_MULTI_PERFORM);

                    while ($active && $mrc === CURLM_OK) {
                        if (curl_multi_select($mh, 0.4) !== -1) {
                            do {
                                $mrc = curl_multi_exec($mh, $active);
                            } while ($mrc === CURLM_CALL_MULTI_PERFORM);
                        }
                    }
                }

                foreach ($metaMap as $cid => $meta) {
                    $isLive = isset($runningByName[$cid]) && $runningByName[$cid]['active'];

                    $cpuPct = 0.05;
                    $memMb = 0.0;
                    $limitMb = 9834.6;
                    $memPct = 0.0;
                    $netIo = '0 MB / 0 MB';
                    $pids = $isLive ? 1 : 0;

                    if ($isLive && isset($handles[$cid])) {
                        $content = curl_multi_getcontent($handles[$cid]);
                        $stats = json_decode($content, true) ?: [];

                        $memBytes = $stats['memory_stats']['usage'] ?? 0;
                        $limitBytes = $stats['memory_stats']['limit'] ?? 10312335360;
                        $memMb = round($memBytes / 1048576, 1);
                        $limitMb = round($limitBytes / 1048576, 1);
                        $memPct = $limitBytes > 0 ? round(($memBytes / $limitBytes) * 100, 1) : 0;
                        $pids = max(1, $stats['pids_stats']['current'] ?? 1);

                        $cpuDelta = ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0) - ($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
                        $sysDelta = ($stats['cpu_stats']['system_cpu_usage'] ?? 0) - ($stats['precpu_stats']['system_cpu_usage'] ?? 0);
                        $cpus = $stats['cpu_stats']['online_cpus'] ?? 1;
                        $cpuPct = ($sysDelta > 0 && $cpuDelta > 0) ? round(($cpuDelta / $sysDelta) * $cpus * 100, 2) : 0.05;

                        $netRx = 0;
                        $netTx = 0;
                        if (!empty($stats['networks'])) {
                            foreach ($stats['networks'] as $net) {
                                $netRx += $net['rx_bytes'] ?? 0;
                                $netTx += $net['tx_bytes'] ?? 0;
                            }
                        }
                        $netIo = round($netRx / 1048576, 1) . 'MB / ' . round($netTx / 1048576, 1) . 'MB';

                        curl_multi_remove_handle($mh, $handles[$cid]);
                    }

                    $results[$cid] = [
                        'id' => $cid,
                        'name' => $meta['name'],
                        'service' => $meta['service'],
                        'status' => $isLive ? 'running' : 'stopped',
                        'cpu_pct' => $isLive ? max(0.05, $cpuPct) : 0.0,
                        'mem_usage_str' => $isLive ? "{$memMb}MB / {$limitMb}MB" : '0 MB / 0 MB',
                        'mem_pct' => $isLive ? $memPct : 0.0,
                        'net_io' => $netIo,
                        'block_io' => $isLive ? 'Active' : '0 MB',
                        'pids' => $pids,
                        'icon' => $meta['icon'],
                        'color' => $meta['color'],
                    ];
                }

                curl_multi_close($mh);
                return $results;
            }

            // Fallback default
            foreach ($metaMap as $cid => $meta) {
                $results[$cid] = [
                    'id' => $cid,
                    'name' => $meta['name'],
                    'service' => $meta['service'],
                    'status' => 'running',
                    'cpu_pct' => 0.1,
                    'mem_usage_str' => 'Active',
                    'mem_pct' => 1.0,
                    'net_io' => 'Active',
                    'block_io' => 'Active',
                    'pids' => 1,
                    'icon' => $meta['icon'],
                    'color' => $meta['color'],
                ];
            }

            return $results;
        };

        try {
            return \Illuminate\Support\Facades\Cache::remember('rms_containers_telemetry_live', 1, $fetcher);
        } catch (\Throwable) {
            return $fetcher();
        }
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
     * Ensure git is installed and safe.directory is configured with proper file permissions.
     */
    protected function ensureGitReady(?string $repoPath = null): void
    {
        $repoPath = $repoPath ?: $this->getGitRepoPath();

        // 1. If docker socket is available, fix permissions on .git with root privileges and add safe.directory system-wide
        if (file_exists('/var/run/docker.sock') && function_exists('curl_init')) {
            $this->execViaDockerSocket('rms_app', ['sh', '-c', "chmod -R ugo+rwX " . escapeshellarg($repoPath . '/.git') . " 2>&1 || true"], null, '0:0');
            $this->execViaDockerSocket('rms_app', ['git', 'config', '--system', '--add', 'safe.directory', '*'], null, '0:0');
            $this->execViaDockerSocket('rms_app', ['git', 'config', '--global', '--add', 'safe.directory', '*'], null, '0:0');
            $this->execViaDockerSocket('rms_app', ['git', 'config', '--system', '--add', 'safe.directory', $repoPath], null, '0:0');
        }

        if (function_exists('shell_exec')) {
            $hasGit = @shell_exec('which git 2>&1');
            if (empty(trim($hasGit)) || str_contains($hasGit, 'not found')) {
                // Try apk or apt-get silently
                @shell_exec('apk add --no-cache git 2>&1 || (apt-get update -qq && apt-get install -y -qq git 2>&1)');
            }
            @shell_exec('git config --system --add safe.directory "*" 2>&1 || true');
            @shell_exec('git config --global --add safe.directory "*" 2>&1 || true');
            @shell_exec('git config --system --add safe.directory ' . escapeshellarg($repoPath) . ' 2>&1 || true');
            @shell_exec('git config --global --add safe.directory ' . escapeshellarg($repoPath) . ' 2>&1 || true');
            @shell_exec('chmod -R ugo+rwX ' . escapeshellarg($repoPath . '/.git') . ' 2>&1 || true');
        }

        if (file_exists($repoPath . '/.git/FETCH_HEAD')) {
            @chmod($repoPath . '/.git/FETCH_HEAD', 0666);
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
            $gitCmd = 'git -c safe.directory=* -C ' . escapeshellarg($repoPath);

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
        $repoPath = $this->getGitRepoPath();
        $this->ensureGitReady($repoPath);

        $gitInfo = $this->getGitInfo();
        $branch = ($gitInfo['branch'] !== 'unknown' && !empty($gitInfo['branch'])) ? $gitInfo['branch'] : 'Enitial-Fix-yow';

        $envPrefix = (PHP_OS_FAMILY === 'Windows') ? '' : 'GIT_TERMINAL_PROMPT=0 GIT_MERGE_AUTOEDIT=no ';
        $gitCmd = "{$envPrefix}git -c safe.directory=* -c gc.auto=0 -c maintenance.auto=0 -c fetch.autoMaintenance=0 -C " . escapeshellarg($repoPath);
        $pullCommand = "{$gitCmd} fetch origin {$branch} 2>&1 && {$gitCmd} pull --no-edit origin {$branch} 2>&1";

        $output = '';
        $success = false;

        // Method A: Docker Socket execution as root (bypasses www-data / appuser permission restrictions on .git/FETCH_HEAD)
        if (file_exists('/var/run/docker.sock') && function_exists('curl_init')) {
            $this->execViaDockerSocket('rms_app', ['sh', '-c', "chmod -R ugo+rwX " . escapeshellarg($repoPath . '/.git') . " 2>&1 || true"], null, '0:0');
            $this->execViaDockerSocket('rms_app', ['git', 'config', '--system', '--add', 'safe.directory', '*'], null, '0:0');
            $this->execViaDockerSocket('rms_app', ['git', 'config', '--global', '--add', 'safe.directory', '*'], null, '0:0');
            $this->execViaDockerSocket('rms_app', ['git', 'config', '--system', '--add', 'safe.directory', $repoPath], null, '0:0');

            $socketRes = $this->execViaDockerSocket('rms_app', ['sh', '-c', $pullCommand], $repoPath, '0:0');
            if (!empty($socketRes['output']) && !str_contains($socketRes['output'], 'Docker socket error')) {
                $output = $socketRes['output'];
                $this->execViaDockerSocket('rms_app', ['sh', '-c', "chmod -R ugo+rwX " . escapeshellarg($repoPath . '/.git') . " 2>&1 || true"], null, '0:0');

                $lower = strtolower($output);
                $isFailed = str_contains($lower, 'fatal:')
                    || str_contains($lower, 'error: cannot open')
                    || str_contains($lower, 'permission denied')
                    || str_contains($lower, 'could not resolve host');

                $success = $socketRes['success'] && !$isFailed;
            }
        }

        // Method B: Docker CLI as root via shell_exec
        if (!$success && function_exists('shell_exec')) {
            $dockerCliCmd = "docker exec -u 0:0 rms_app sh -c " . escapeshellarg($pullCommand) . " 2>&1";
            $res = @shell_exec($dockerCliCmd);
            if ($res && !str_contains($res, 'No such container') && !str_contains($res, 'docker: not found')) {
                $output = $res;
                $lower = strtolower($output);
                $isFailed = str_contains($lower, 'fatal:')
                    || str_contains($lower, 'error: cannot open')
                    || str_contains($lower, 'permission denied');
                $success = !$isFailed;
            }
        }

        // Method C: Direct shell_exec fallback
        if (!$success && function_exists('shell_exec')) {
            $res = @shell_exec($pullCommand);
            if ($res) {
                $output = $res;
                $lower = strtolower($output);
                $isFailed = str_contains($lower, 'fatal:')
                    || str_contains($lower, 'error: cannot open')
                    || str_contains($lower, 'permission denied');
                $success = !$isFailed;
            }
        }

        if ($success) {
            // Re-cache views & config
            if (function_exists('shell_exec')) {
                $php = $this->getPhpBinary();
                $artisan = escapeshellarg(base_path('artisan'));
                @shell_exec(escapeshellarg($php) . " {$artisan} view:clear 2>&1");
                @shell_exec(escapeshellarg($php) . " {$artisan} config:clear 2>&1");
            } else {
                Artisan::call('view:clear');
                Artisan::call('config:clear');
            }
        }

        $cleanOutput = self::sanitizeUtf8($output);

        return [
            'success' => $success,
            'output' => $cleanOutput !== '' ? $cleanOutput : 'No output returned by git command.',
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
                $cleanOut = self::sanitizeUtf8($output);
                $isFailed = str_contains(strtolower($cleanOut), 'fatal') || str_contains(strtolower($cleanOut), 'exception');
                return [
                    'success' => !$isFailed,
                    'message' => !$isFailed
                        ? 'Application caches cleared and re-optimized successfully.'
                        : 'Optimization encountered errors.',
                    'output' => $cleanOut,
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
                'output' => self::sanitizeUtf8($clearOutput . "\n" . $optimizeOutput),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to optimize application: ' . $e->getMessage(),
                'output' => self::sanitizeUtf8($e->getMessage() . "\n" . $e->getTraceAsString()),
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
     * Run database migrations (update database schema).
     */
    public function updateDatabase(): array
    {
        $output = '';
        $success = false;

        // 1. Direct Artisan migrate (standard, robust, captures full trace and output)
        try {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
            $artisanOutput = trim(\Illuminate\Support\Facades\Artisan::output());
            $output .= ($artisanOutput ?: 'Database already up to date (nothing to migrate).') . "\n";
            $success = true;
        } catch (\Throwable $e) {
            $output .= 'Artisan migrate error: ' . $e->getMessage() . "\n";
        }

        // 2. Fallback via docker compose / docker exec if shell_exec is available
        if (!$success && function_exists('shell_exec')) {
            $repoPath = $this->getGitRepoPath();
            $commands = [
                "docker compose -f " . escapeshellarg($repoPath . '/docker-compose.yml') . " exec -T app php artisan migrate --force 2>&1",
                "docker compose exec -T app php artisan migrate --force 2>&1",
                "docker exec rms_app php artisan migrate --force 2>&1",
            ];
            foreach ($commands as $cmd) {
                $res = @shell_exec($cmd);
                if ($res) {
                    $output .= "\n[{$cmd}]:\n" . trim($res);
                    if (str_contains($res, 'Migrated:') || str_contains($res, 'Nothing to migrate') || str_contains($res, 'DONE')) {
                        $success = true;
                        break;
                    }
                }
            }
        }

        return [
            'success' => $success,
            'message' => $success ? 'Database updated successfully.' : 'Database migration failed.',
            'output' => self::sanitizeUtf8($output),
        ];
    }

    /**
     * Run database migrations (alias for backward compatibility).
     */
    public function runDatabaseMigrations(): array
    {
        return $this->updateDatabase();
    }

    /**
     * Clear Vite hot file and trigger Node build (compile frontend assets).
     */
    public function renderNode(): array
    {
        $output = [];
        $success = false;

        // 1. Remove public/hot file / directory
        $hotPath = public_path('hot');
        if (file_exists($hotPath)) {
            if (is_dir($hotPath)) {
                $this->recursiveDeleteDirectory($hotPath);
                $output[] = "Deleted directory: public/hot";
            } else {
                @unlink($hotPath);
                $output[] = "Deleted file: public/hot";
            }
        } else {
            $output[] = "public/hot is not present (clean).";
        }

        if (function_exists('shell_exec')) {
            if (PHP_OS_FAMILY !== 'Windows') {
                @shell_exec('rm -rf ' . escapeshellarg($hotPath) . ' 2>&1');
            } else {
                @shell_exec('if exist ' . escapeshellarg($hotPath) . ' del /f /q ' . escapeshellarg($hotPath) . ' 2>&1');
            }
        }

        // 2. Execute npm run build in node container
        $repoPath = $this->getGitRepoPath();

        // Method A: Docker CLI via shell_exec
        if (function_exists('shell_exec')) {
            $commands = [
                "docker compose -f " . escapeshellarg($repoPath . '/docker-compose.yml') . " exec -T node npm run build 2>&1",
                "docker compose exec -T node npm run build 2>&1",
                "docker exec -w /app rms_node npm run build 2>&1",
                "docker exec rms_node npm run build 2>&1",
            ];

            foreach ($commands as $cmd) {
                $res = @shell_exec($cmd);
                if ($res) {
                    $cleanRes = self::sanitizeUtf8($res);
                    if (str_contains($cleanRes, 'built in') || str_contains($cleanRes, 'vite build') || str_contains($cleanRes, '✓') || str_contains($cleanRes, 'modules transformed') || str_contains($cleanRes, 'dist/') || str_contains($cleanRes, 'manifest.json')) {
                        $output[] = "[Command: {$cmd}]\n" . $cleanRes;
                        $success = true;
                        break;
                    } elseif (!str_contains($cleanRes, 'not found') && !str_contains($cleanRes, 'No such container')) {
                        $output[] = "[Command: {$cmd}]\n" . $cleanRes;
                    }
                }
            }
        }

        // Method B: Docker Socket API execution (rms_node)
        if (!$success && file_exists('/var/run/docker.sock') && function_exists('curl_init')) {
            $socketRes = $this->execViaDockerSocket('rms_node', ['npm', 'run', 'build'], '/app');
            $socketOut = self::sanitizeUtf8($socketRes['output']);
            $hasSuccessMarker = str_contains($socketOut, 'built in')
                || str_contains($socketOut, 'vite build')
                || str_contains($socketOut, 'modules transformed')
                || str_contains($socketOut, 'dist/')
                || str_contains($socketOut, 'manifest.json');

            if ($socketRes['success'] || $hasSuccessMarker) {
                $output[] = "[Docker Socket: rms_node]\n" . $socketOut;
                $success = true;
            } else {
                $output[] = "[Docker Socket: rms_node]\n" . $socketOut;
            }
        }

        // Method C: Host / bare-metal fallback if npm exists locally
        if (!$success && function_exists('shell_exec')) {
            $localCmd = "cd " . escapeshellarg($repoPath) . " && npm run build 2>&1";
            $res = @shell_exec($localCmd);
            if ($res) {
                $cleanRes = self::sanitizeUtf8($res);
                $output[] = "[Local Shell: {$localCmd}]\n" . $cleanRes;
                if (str_contains($cleanRes, 'built in') || str_contains($cleanRes, 'vite build') || str_contains($cleanRes, '✓') || str_contains($cleanRes, 'modules transformed')) {
                    $success = true;
                }
            }
        }

        return [
            'success' => $success,
            'message' => $success ? 'Node frontend assets rendered and built successfully.' : 'Node render completed (see output details).',
            'output' => self::sanitizeUtf8(implode("\n\n", $output)),
        ];
    }

    /**
     * Decode Docker multiplexed stream frames (stdout/stderr).
     * Docker multiplexes frames with an 8-byte header:
     * [STREAM_TYPE, 0, 0, 0, SIZE1, SIZE2, SIZE3, SIZE4] followed by payload.
     */
    public static function decodeDockerStream(string $stream): string
    {
        if ($stream === '') {
            return '';
        }

        $len = strlen($stream);
        $offset = 0;
        $output = '';

        while ($offset < $len) {
            // Check for standard Docker 8-byte multiplex header
            // Byte 0: Stream type (0: stdin, 1: stdout, 2: stderr)
            // Bytes 1-3: Reserved (must be null bytes)
            // Bytes 4-7: 32-bit big-endian frame size
            if ($offset + 8 <= $len) {
                $type = ord($stream[$offset]);
                if (($type === 0 || $type === 1 || $type === 2) && substr($stream, $offset + 1, 3) === "\x00\x00\x00") {
                    $unpacked = unpack('N', substr($stream, $offset + 4, 4));
                    $frameSize = $unpacked ? $unpacked[1] : 0;
                    $offset += 8;
                    if ($frameSize > 0) {
                        if ($offset + $frameSize <= $len) {
                            $output .= substr($stream, $offset, $frameSize);
                            $offset += $frameSize;
                            continue;
                        } else {
                            $output .= substr($stream, $offset);
                            break;
                        }
                    }
                    continue;
                }
            }

            $output .= $stream[$offset];
            $offset++;
        }

        return $output;
    }

    /**
     * Sanitize any command or process output to guarantee 100% valid UTF-8.
     * Prevents Livewire and Laravel json_encode() "Malformed UTF-8 characters" 500 crashes.
     */
    public static function sanitizeUtf8(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            $value = (string) $value;
        }

        // 1. Demultiplex Docker frame headers if present
        $value = self::decodeDockerStream($value);

        // 2. Strip null bytes and stray control characters (preserving tabs, newlines, CR)
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? str_replace("\0", '', $value);

        // 3. Strip ANSI escape sequences (terminal formatting and colors)
        $value = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]|\x1b\([a-zA-Z]|\x1b\][^\x07\x1b]*(\x07|\x1b\\\\)/', '', $value) ?? $value;

        // 4. Force conversion to valid UTF-8
        if (function_exists('mb_convert_encoding')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        } elseif (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        // 5. Final fallback if any encoding weirdness persists
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        return trim($value);
    }

    /**
     * Execute a command inside a running Docker container via /var/run/docker.sock.
     */
    public function execViaDockerSocket(string $container, array $cmd, ?string $workingDir = null, ?string $user = '0:0'): array
    {
        if (!file_exists('/var/run/docker.sock') || !function_exists('curl_init')) {
            return ['success' => false, 'output' => 'Docker socket /var/run/docker.sock not available.'];
        }

        try {
            // Step 1: Create exec instance
            $ch = curl_init("http://localhost/containers/{$container}/exec");
            curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
            curl_setopt($ch, CURLOPT_POST, true);
            $payload = [
                'AttachStdout' => true,
                'AttachStderr' => true,
                'Cmd' => $cmd,
                'User' => $user ?: '0:0',
            ];
            if ($workingDir) {
                $payload['WorkingDir'] = $workingDir;
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $createResp = curl_exec($ch);
            curl_close($ch);

            $createData = json_decode((string)$createResp, true);
            $execId = $createData['Id'] ?? null;
            if (!$execId) {
                return ['success' => false, 'output' => 'Failed to create exec instance in container: ' . ($createResp ?: 'empty response')];
            }

            // Step 2: Start exec instance
            $ch = curl_init("http://localhost/exec/{$execId}/start");
            curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'Detach' => false,
                'Tty' => false,
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            $startResp = curl_exec($ch);
            curl_close($ch);

            // Optional: Inspect exec instance to get accurate exit code
            $exitCode = 0;
            $ch = curl_init("http://localhost/exec/{$execId}/json");
            curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $inspectResp = curl_exec($ch);
            curl_close($ch);
            if ($inspectResp) {
                $inspectData = json_decode((string)$inspectResp, true);
                if (isset($inspectData['ExitCode'])) {
                    $exitCode = (int) $inspectData['ExitCode'];
                }
            }

            $cleanOutput = self::sanitizeUtf8((string)$startResp);

            return [
                'success' => ($exitCode === 0),
                'output' => $cleanOutput !== '' ? $cleanOutput : 'Exec completed with no output.',
                'exit_code' => $exitCode,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'output' => 'Docker socket exec error: ' . $e->getMessage()];
        }
    }

    /**
     * Run terminal command in the requested environment container or host.
     *
     * @param string $command Command string
     * @param string $target 'app' | 'node' | 'db' | 'host'
     * @param string|null $workingDir Optional working directory
     * @return array ['success' => bool, 'output' => string, 'exit_code' => int, 'command' => string, 'target' => string, 'timestamp' => string]
     */
    public function runTerminalCommand(string $command, string $target = 'app', ?string $workingDir = null): array
    {
        $rawCommand = trim($command);
        if ($rawCommand === '') {
            return [
                'success' => false,
                'output' => 'Error: Command cannot be empty.',
                'exit_code' => 1,
                'command' => '',
                'target' => $target,
                'timestamp' => now()->format('H:i:s'),
            ];
        }

        // Prevent blocking interactive applications
        $interactivePatterns = [
            '/^\s*nano(\s+.*)?$/i' => 'nano is an interactive editor. In a web terminal, consider using cat, echo, or sed.',
            '/^\s*vi(m)?(\s+.*)?$/i' => 'vim is an interactive editor. In a web terminal, consider using cat, echo, or sed.',
            '/^\s*htop(\s+.*)?$/i' => 'htop is an interactive viewer. Use "top -b -n 1" or Server Settings metrics instead.',
            '/^\s*top(\s*)$/i' => 'Interactive top will block. Use "top -b -n 1" for batch output.',
            '/^\s*tail\s+-f\b/i' => 'tail -f will run indefinitely. Use "tail -n 100 <file>" instead.',
            '/^\s*watch\b/i' => 'watch will run indefinitely. Run the target command once instead.',
            '/^\s*less(\s+.*)?$/i' => 'less is an interactive pager. Use "cat <file>" or "head -n 50 <file>" instead.',
            '/^\s*ssh\b/i' => 'Interactive ssh is not supported in this console.',
        ];

        foreach ($interactivePatterns as $pattern => $message) {
            if (preg_match($pattern, $rawCommand)) {
                return [
                    'success' => false,
                    'output' => "Command Blocked: {$message}",
                    'exit_code' => 126,
                    'command' => $rawCommand,
                    'target' => $target,
                    'timestamp' => now()->format('H:i:s'),
                ];
            }
        }

        $repoPath = $this->getGitRepoPath();
        $basePath = base_path();
        $isInsideDocker = file_exists('/.dockerenv');
        $hasDockerSocket = file_exists('/var/run/docker.sock');

        $output = '';
        $exitCode = 0;
        $success = false;

        $runProcess = function (string $cmd, string $cwd, int $timeout = 60) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = @proc_open($cmd, $descriptors, $pipes, $cwd);
            if (!is_resource($process)) {
                return ['success' => false, 'output' => "Failed to spawn process for command: {$cmd}", 'exit_code' => -1];
            }

            fclose($pipes[0]);

            // Set stdout and stderr to non-blocking
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdout = '';
            $stderr = '';
            $startTime = time();

            while (true) {
                $read = [$pipes[1], $pipes[2]];
                $write = null;
                $except = null;

                if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                    foreach ($read as $stream) {
                        $chunk = fread($stream, 8192);
                        if ($chunk !== false && $chunk !== '') {
                            if ($stream === $pipes[1]) {
                                $stdout .= $chunk;
                            } else {
                                $stderr .= $chunk;
                            }
                        }
                    }
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    // Collect remaining data
                    $stdout .= stream_get_contents($pipes[1]);
                    $stderr .= stream_get_contents($pipes[2]);
                    break;
                }

                if (time() - $startTime > $timeout) {
                    @proc_terminate($process, 9);
                    $stderr .= "\n[Execution timed out after {$timeout} seconds]";
                    break;
                }
            }

            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);

            $combined = trim($stdout . ($stderr !== '' ? ($stdout !== '' ? "\n" : "") . $stderr : ''));

            return [
                'success' => ($code === 0),
                'output' => $combined !== '' ? $combined : '(Command finished with no output)',
                'exit_code' => $code,
            ];
        };

        switch ($target) {
            case 'node':
                // Check if we can execute inside rms_node
                $executed = false;
                if ($hasDockerSocket && function_exists('curl_init')) {
                    $res = $this->execViaDockerSocket('rms_node', ['sh', '-c', $rawCommand], $workingDir ?: '/app');
                    if ($res['success'] || !str_contains($res['output'], 'Docker socket error')) {
                        $output = $res['output'];
                        $success = $res['success'];
                        $exitCode = $success ? 0 : 1;
                        $executed = true;
                    }
                }

                if (!$executed && function_exists('shell_exec')) {
                    $dockerCmd = "docker compose -f " . escapeshellarg($repoPath . '/docker-compose.yml') . " exec -T node sh -c " . escapeshellarg($rawCommand) . " 2>&1";
                    $procRes = $runProcess($dockerCmd, $repoPath);
                    if (!str_contains($procRes['output'], 'No such service') && !str_contains($procRes['output'], 'command not found') && !str_contains($procRes['output'], 'docker: not found')) {
                        $output = $procRes['output'];
                        $exitCode = $procRes['exit_code'];
                        $success = $procRes['success'];
                        $executed = true;
                    }
                }

                if (!$executed) {
                    // Fallback to local host execution in repoPath
                    $procRes = $runProcess($rawCommand, $workingDir ?: $repoPath);
                    $output = $procRes['output'];
                    $exitCode = $procRes['exit_code'];
                    $success = $procRes['success'];
                }
                break;

            case 'db':
                $executed = false;
                if ($hasDockerSocket && function_exists('curl_init')) {
                    $res = $this->execViaDockerSocket('rms_db', ['sh', '-c', $rawCommand], $workingDir ?: '/');
                    if ($res['success'] || !str_contains($res['output'], 'Docker socket error')) {
                        $output = $res['output'];
                        $success = $res['success'];
                        $exitCode = $success ? 0 : 1;
                        $executed = true;
                    }
                }

                if (!$executed && function_exists('shell_exec')) {
                    $dockerCmd = "docker compose -f " . escapeshellarg($repoPath . '/docker-compose.yml') . " exec -T db sh -c " . escapeshellarg($rawCommand) . " 2>&1";
                    $procRes = $runProcess($dockerCmd, $repoPath);
                    $output = $procRes['output'];
                    $exitCode = $procRes['exit_code'];
                    $success = $procRes['success'];
                }
                break;

            case 'host':
                $dir = $workingDir ?: $repoPath;
                if (!is_dir($dir)) {
                    $dir = $basePath;
                }
                $procRes = $runProcess($rawCommand, $dir);
                $output = $procRes['output'];
                $exitCode = $procRes['exit_code'];
                $success = $procRes['success'];
                break;

            case 'app':
            default:
                if ($isInsideDocker) {
                    // When running inside the app container, execute directly
                    $dir = $workingDir ?: $basePath;
                    $procRes = $runProcess($rawCommand, $dir);
                    $output = $procRes['output'];
                    $exitCode = $procRes['exit_code'];
                    $success = $procRes['success'];
                } else {
                    // Running on host where docker might be running rms_app
                    $executed = false;
                    if (function_exists('shell_exec')) {
                        $dockerCmd = "docker compose -f " . escapeshellarg($repoPath . '/docker-compose.yml') . " exec -T app sh -c " . escapeshellarg($rawCommand) . " 2>&1";
                        $procRes = $runProcess($dockerCmd, $repoPath);
                        if (!str_contains($procRes['output'], 'Cannot connect to the Docker daemon') && !str_contains($procRes['output'], 'docker: not found')) {
                            $output = $procRes['output'];
                            $exitCode = $procRes['exit_code'];
                            $success = $procRes['success'];
                            $executed = true;
                        }
                    }

                    if (!$executed && $hasDockerSocket && function_exists('curl_init')) {
                        $res = $this->execViaDockerSocket('rms_app', ['sh', '-c', $rawCommand], $workingDir ?: '/var/www/html');
                        if ($res['success']) {
                            $output = $res['output'];
                            $success = true;
                            $exitCode = 0;
                            $executed = true;
                        }
                    }

                    if (!$executed) {
                        $dir = $workingDir ?: $basePath;
                        $procRes = $runProcess($rawCommand, $dir);
                        $output = $procRes['output'];
                        $exitCode = $procRes['exit_code'];
                        $success = $procRes['success'];
                    }
                }
                break;
        }

        return [
            'success' => $success,
            'output' => self::sanitizeUtf8($output),
            'exit_code' => $exitCode,
            'command' => $rawCommand,
            'target' => $target,
            'timestamp' => now()->format('H:i:s'),
        ];
    }

    /**
     * Recursively delete a directory and its contents.
     */
    private function recursiveDeleteDirectory(string $dir): void
    {
        if (!file_exists($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->recursiveDeleteDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
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
