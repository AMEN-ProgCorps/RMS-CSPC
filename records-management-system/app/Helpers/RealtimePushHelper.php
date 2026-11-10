<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Best-effort WebSocket push into Messify's ws-server from Laravel.
 * Failures are swallowed — notification DB rows must still succeed.
 */
class RealtimePushHelper
{
    /**
     * Push a system (bell) notification to every account in an office.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function pushSystemNotificationToOffice(
        string $officeCode,
        array $payload,
        ?int $excludeAccountId = null
    ): void {
        $officeCode = trim($officeCode);
        if ($officeCode === '' || empty($payload['id'])) {
            return;
        }

        try {
            $accountIds = self::accountIdsForOffice($officeCode, $excludeAccountId);
            if ($accountIds === []) {
                return;
            }

            self::push($accountIds, 'rms_system_notification', $payload);
        } catch (\Throwable $e) {
            Log::warning('RealtimePushHelper::pushSystemNotificationToOffice: '.$e->getMessage());
        }
    }

    /**
     * @return list<int>
     */
    public static function accountIdsForOffice(string $officeCode, ?int $excludeAccountId = null): array
    {
        $officeCode = trim($officeCode);
        if ($officeCode === '') {
            return [];
        }

        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $accountTbl = Schema::hasTable('sys_account') ? 'sys_account' : 'account';

        $query = DB::table($accDetailsTbl)
            ->join($officeTbl, "{$accDetailsTbl}.office_id", '=', "{$officeTbl}.id")
            ->join($accountTbl, "{$accDetailsTbl}.account_id", '=', "{$accountTbl}.id")
            ->where("{$officeTbl}.office_code", $officeCode)
            ->where("{$accountTbl}.account_active", true);

        if ($excludeAccountId && $excludeAccountId > 0) {
            $query->where("{$accDetailsTbl}.account_id", '!=', $excludeAccountId);
        }

        return $query
            ->pluck("{$accDetailsTbl}.account_id")
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $accountIds
     * @param  array<string, mixed>  $data
     */
    public static function push(array $accountIds, string $type, array $data = []): void
    {
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if ($accountIds === [] || $type === '') {
            return;
        }

        try {
            if (! class_exists('WsPush', false)) {
                $bootstrap = is_file(public_path('chatify/bootstrap.php'))
                    ? public_path('chatify/bootstrap.php')
                    : public_path('messify/bootstrap.php');
                if (! is_file($bootstrap)) {
                    return;
                }
                require_once $bootstrap;
            }

            if (! class_exists('WsPush', false)) {
                return;
            }

            \WsPush::push($accountIds, $type, $data);
        } catch (\Throwable $e) {
            Log::warning('RealtimePushHelper::push: '.$e->getMessage());
        }
    }
}
