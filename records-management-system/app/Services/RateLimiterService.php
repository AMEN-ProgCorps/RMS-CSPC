<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Auth;
use App\Helpers\NetworkHelper;

class RateLimiterService
{
    /**
     * Check if system-wide rate limiting is enabled.
     */
    public static function isEnabled(): bool
    {
        try {
            $val = DB::table('sys_system_settings')->where('key', 'rate_limit_enabled')->value('value');
            return $val === 'true';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if the current user/IP is rate limited for a specific action.
     *
     * @param string $actionType e.g., 'dts_create', 'dts_action', 'rdp_create'
     * @param int|null $userId Optional specific user ID
     * @return array ['allowed' => bool, 'message' => string, 'retry_after' => int]
     */
    public static function check(string $actionType = 'dts_create', ?int $userId = null): array
    {
        if (!self::isEnabled()) {
            return ['allowed' => true, 'message' => '', 'retry_after' => 0];
        }

        $user = Auth::user();
        // Super Admin bypass: superadmins are never blocked by operational rate limits
        if ($user && ($user->permissions?->is_sadm ?? false)) {
            return ['allowed' => true, 'message' => '', 'retry_after' => 0];
        }

        $uid = $userId ?? Auth::id() ?? 0;
        $ip = class_exists(NetworkHelper::class) ? NetworkHelper::getClientIp() : request()->ip();
        $identity = $uid > 0 ? "user_{$uid}" : "ip_{$ip}";
        $key = "rms_rate_limit:{$actionType}:{$identity}";

        $maxAttempts = self::getMaxAttempts($actionType);
        $decaySeconds = 60; // 1-minute sliding decay window

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            $actionLabel = match ($actionType) {
                'dts_create' => 'creating transactions',
                'dts_action' => 'performing workflow actions',
                'rdp_create' => 'submitting records',
                default      => 'submitting data',
            };

            return [
                'allowed' => false,
                'message' => "Rate limit exceeded for {$actionLabel} ({$maxAttempts} per minute). Please wait {$seconds} second(s) before trying again.",
                'retry_after' => $seconds,
            ];
        }

        RateLimiter::hit($key, $decaySeconds);

        return ['allowed' => true, 'message' => '', 'retry_after' => 0];
    }

    /**
     * Get maximum allowed attempts per minute for a given action type.
     */
    public static function getMaxAttempts(string $actionType): int
    {
        $settingKey = match ($actionType) {
            'dts_create' => 'rate_limit_dts_create_per_minute',
            'dts_action' => 'rate_limit_dts_action_per_minute',
            'rdp_create' => 'rate_limit_rdp_create_per_minute',
            default      => 'rate_limit_default_per_minute',
        };

        try {
            $val = DB::table('sys_system_settings')->where('key', $settingKey)->value('value');
            if ($val !== null && is_numeric($val) && (int) $val > 0) {
                return (int) $val;
            }
        } catch (\Throwable) {}

        return match ($actionType) {
            'dts_create' => 10,
            'dts_action' => 20,
            'rdp_create' => 15,
            default      => 10,
        };
    }
}
