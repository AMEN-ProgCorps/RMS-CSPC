<?php

namespace App\Services;

use App\Helpers\NetworkHelper;
use App\Helpers\RegisterPersistHelper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DcsAuditService
{
    /**
     * Structured DCS activity event + optional short admin_logs line.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function log(
        string $action,
        ?string $module = null,
        ?int $requestId = null,
        ?string $path = null,
        array $meta = [],
        ?string $adminLogLine = null
    ): void {
        try {
            $userId = Auth::id();
            if (! $userId) {
                return;
            }

            if (Schema::hasTable('dcs_activity_events')) {
                DB::table('dcs_activity_events')->insert([
                    'user_id' => $userId,
                    'action' => $action,
                    'module' => $module,
                    'request_id' => $requestId,
                    'path' => $path !== null ? mb_substr($path, 0, 500) : null,
                    'meta' => $meta === [] ? null : json_encode($meta),
                    'ip' => class_exists(NetworkHelper::class)
                        ? NetworkHelper::getClientIp()
                        : request()?->ip(),
                    'created_at' => now(),
                ]);
            }

            if ($adminLogLine !== null && $adminLogLine !== '') {
                RegisterPersistHelper::logAdminChange($adminLogLine);
            }
        } catch (\Throwable) {
            // Non-fatal
        }
    }
}
