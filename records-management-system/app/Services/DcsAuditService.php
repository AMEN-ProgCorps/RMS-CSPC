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
                $event = [
                    'user_id' => $userId,
                    'action' => $action,
                    'module' => $module,
                    'request_id' => $requestId,
                    'path' => $path !== null ? mb_substr($path, 0, 500) : null,
                    'ip' => class_exists(NetworkHelper::class)
                        ? NetworkHelper::getClientIp()
                        : request()?->ip(),
                    'created_at' => now(),
                ];
                if (Schema::hasColumn('dcs_activity_events', 'meta')) {
                    $event['meta'] = $meta === [] ? null : json_encode($meta);
                }
                $eventId = (int) DB::table('dcs_activity_events')->insertGetId($event);
                self::storeEventMeta($eventId, $meta);
            }

            if ($adminLogLine !== null && $adminLogLine !== '') {
                RegisterPersistHelper::logAdminChange($adminLogLine);
            }
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function storeEventMeta(int $eventId, array $meta): void
    {
        if ($eventId < 1 || $meta === [] || ! Schema::hasTable('dcs_activity_event_meta')) {
            return;
        }

        $rows = [];
        self::flattenMeta($meta, '', $rows);
        foreach ($rows as $row) {
            DB::table('dcs_activity_event_meta')->insert([
                'event_id' => $eventId,
                'meta_key' => $row['key'],
                'sort_order' => $row['sort'],
                'meta_value' => $row['value'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{key: string, sort: int, value: string}>  $rows
     */
    private static function flattenMeta(array $payload, string $prefix, array &$rows): void
    {
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $isList = array_keys($value) === range(0, count($value) - 1);
                if ($isList) {
                    foreach (array_values($value) as $index => $item) {
                        if (is_array($item) || $item === null || $item === '') {
                            continue;
                        }
                        $rows[] = [
                            'key' => mb_substr($name, 0, 150),
                            'sort' => $index,
                            'value' => is_bool($item) ? ($item ? '1' : '0') : (string) $item,
                        ];
                    }
                    continue;
                }
                self::flattenMeta($value, $name, $rows);
                continue;
            }
            $rows[] = [
                'key' => mb_substr($name, 0, 150),
                'sort' => 0,
                'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            ];
        }
    }
}
