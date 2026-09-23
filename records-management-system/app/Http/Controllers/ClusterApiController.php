<?php

namespace App\Http\Controllers;

use App\Services\ServerManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ClusterApiController extends Controller
{
    public function __construct(
        protected ServerManagementService $serverService
    ) {}

    /**
     * Authenticate cluster inter-node request.
     */
    protected function authenticateToken(Request $request): bool
    {
        $incoming = $request->bearerToken() 
            ?: $request->header('X-Cluster-Token') 
            ?: $request->input('cluster_token');

        if (empty($incoming)) {
            return false;
        }

        $expected = $this->serverService->getClusterSecretToken();

        return hash_equals($expected, $incoming);
    }

    /**
     * Return live status, hardware stats, and git version of this node.
     */
    public function nodeStatus(Request $request): JsonResponse
    {
        if (!$this->authenticateToken($request)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized cluster node request.',
            ], 401);
        }

        $metrics = $this->serverService->getHardwareMetrics();
        $git = $this->serverService->getGitInfo();
        $db = $this->serverService->validateExternalDatabase();
        $role = $this->serverService->getSystemSetting('cluster_role', 'backup');

        return response()->json([
            'status' => 'ok',
            'role' => $role,
            'hostname' => $metrics['host']['hostname'],
            'os' => $metrics['host']['os_detail'],
            'uptime' => $metrics['uptime'],
            'hardware' => [
                'cpu_pct' => $metrics['cpu']['usage_pct'],
                'ram_used_mb' => $metrics['ram']['used_mb'],
                'ram_total_mb' => $metrics['ram']['total_mb'],
                'ram_pct' => $metrics['ram']['usage_pct'],
                'disk_pct' => $metrics['disk']['usage_pct'],
            ],
            'git' => $git,
            'database' => [
                'valid' => $db['valid'],
                'host' => $db['host'],
                'latency_ms' => $db['latency_ms'],
            ],
            'containers' => $this->serverService->getDockerContainersStatus(),
        ]);
    }

    /**
     * Perform remote git pull and app cache optimization on this node.
     */
    public function remoteUpdate(Request $request): JsonResponse
    {
        if (!$this->authenticateToken($request)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized cluster node request.',
            ], 401);
        }

        Log::info('[Cluster] Remote update triggered by cluster root node.');

        $gitRes = $this->serverService->runGitPull();
        $optRes = $this->serverService->optimizeApp();

        return response()->json([
            'success' => $gitRes['success'],
            'message' => $gitRes['success'] 
                ? 'Update applied successfully.' 
                : 'Git pull reported errors.',
            'git_output' => $gitRes['output'],
            'optimize_output' => $optRes['output'],
        ]);
    }
}
