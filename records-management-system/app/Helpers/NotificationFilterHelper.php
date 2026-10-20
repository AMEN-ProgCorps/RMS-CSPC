<?php

namespace App\Helpers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Helper to classify system notifications into manageable categories
 * and manage user filter/mute preferences.
 */
class NotificationFilterHelper
{
    /**
     * Category definitions for user configurable notification preferences.
     *
     * @return array<string, array<string, string>>
     */
    public static function getAvailableFilters(): array
    {
        return [
            'dts_receiving' => [
                'key' => 'dts_receiving',
                'group' => 'Document Tracking System (DTS)',
                'label' => 'Receiving Transactions',
                'description' => 'When a new transaction is waiting to be received by your office',
                'icon' => 'fa-inbox',
            ],
            'dts_received' => [
                'key' => 'dts_received',
                'group' => 'Document Tracking System (DTS)',
                'label' => 'Received Transactions',
                'description' => 'When a transaction has been received by a team member or office',
                'icon' => 'fa-check-to-slot',
            ],
            'dts_forwarded' => [
                'key' => 'dts_forwarded',
                'group' => 'Document Tracking System (DTS)',
                'label' => 'Forwarded Transactions',
                'description' => 'When a transaction has been forwarded or routed between offices',
                'icon' => 'fa-share-nodes',
            ],
            'dts_completed' => [
                'key' => 'dts_completed',
                'group' => 'Document Tracking System (DTS)',
                'label' => 'Completed Transactions',
                'description' => 'When a transaction completes its routing and workflow cycle',
                'icon' => 'fa-circle-check',
            ],
            'dcs_distribution' => [
                'key' => 'dcs_distribution',
                'group' => 'Document Control System (DCS)',
                'label' => 'Controlled Document Distribution',
                'description' => 'When your office has an incoming documented information',
                'icon' => 'fa-file-shield',
            ],
            'dcs_registration' => [
                'key' => 'dcs_registration',
                'group' => 'Document Control System (DCS)',
                'label' => 'Document Registration & Revision',
                'description' => 'When a new document or revised version is officially registered',
                'icon' => 'fa-file-lines',
            ],
            'dcs_workflow' => [
                'key' => 'dcs_workflow',
                'group' => 'Document Control System (DCS)',
                'label' => 'Requests & Approval Notes',
                'description' => 'Document review remarks, change requests, and approval updates',
                'icon' => 'fa-clipboard-check',
            ],
            'general' => [
                'key' => 'general',
                'group' => 'General System & Records',
                'label' => 'General System Announcements',
                'description' => 'Records disposition updates and administrative notifications',
                'icon' => 'fa-bell',
            ],
        ];
    }

    /**
     * Categorize a notification message string into its filter key.
     *
     * @param string $content Notification message text
     * @param string $subsystem Optional subsystem name hint
     * @return string
     */
    public static function classify(string $content, string $subsystem = ''): string
    {
        $text = mb_strtolower(trim($content));
        $sys = mb_strtolower(trim($subsystem));

        // 1. DTS Receiving (e.g. "New Transaction ... is waiting to be received by your office.")
        if (str_contains($text, 'waiting to be received')) {
            return 'dts_receiving';
        }

        // 2. DTS Forwarded (e.g. "Transaction ... has been forwarded by ...", "completed and forwarded ... to")
        if (str_contains($text, 'has been forwarded') || str_contains($text, 'and forwarded transaction')) {
            return 'dts_forwarded';
        }

        // 3. DTS Completed (e.g. "Transaction ... has been completed, you can now check it.")
        if (str_contains($text, 'has been completed, you can now check it') || str_contains($text, 'has been completed')) {
            return 'dts_completed';
        }

        // 4. DTS Received (e.g. "Transaction ... has been received by ...", "... has received Transaction ...")
        if (str_contains($text, 'has been received by') || str_contains($text, 'has received transaction')) {
            return 'dts_received';
        }

        // 5. DCS Controlled Document Distribution
        if (str_contains($text, 'incoming documented information')
            || str_contains($text, 'controlled document to receive')
            || str_contains($text, 'new controlled document')) {
            return 'dcs_distribution';
        }

        // 6. DCS Document Registration & Revision
        if (str_contains($text, 'has been registered') || str_contains($text, 'document registered') || str_contains($text, '(rev ')) {
            return 'dcs_registration';
        }

        // 7. DCS Requests & Workflow
        if ($sys === 'document control system' || str_contains($text, 'request') || str_contains($text, 'remark') || str_contains($text, 'clearance')) {
            return 'dcs_workflow';
        }

        return 'general';
    }

    /**
     * Check if a given notification content is muted according to muted types or timestamp map.
     * If an associative map [category => muted_at_timestamp] is provided along with the notification's
     * creation time, muting will only apply to notifications created at or after the mute timestamp.
     *
     * @param string $content Notification message text
     * @param string $subsystem Subsystem name
     * @param array<mixed>|null $mutedData List of muted keys OR associative map [key => muted_at_timestamp]
     * @param mixed $notificationCreatedAt Optional notification creation timestamp
     * @return bool
     */
    public static function isMuted(string $content, string $subsystem = '', ?array $mutedData = null, mixed $notificationCreatedAt = null): bool
    {
        if (empty($mutedData)) {
            return false;
        }

        $category = self::classify($content, $subsystem);

        // Check if category exists as key in associative map [category => timestamp]
        if (array_key_exists($category, $mutedData)) {
            $muteTimestamp = $mutedData[$category];
            if ($notificationCreatedAt !== null && !empty($muteTimestamp)) {
                try {
                    $notifTime = \Carbon\Carbon::parse($notificationCreatedAt)->timestamp;
                    $mutedTime = is_numeric($muteTimestamp) ? (int) $muteTimestamp : \Carbon\Carbon::parse($muteTimestamp)->timestamp;
                    // If notification was created before the category was muted, it is NOT muted
                    if ($notifTime < $mutedTime) {
                        return false;
                    }
                } catch (\Throwable $e) {
                    // Fall back to muted on parsing exception
                }
            }
            return true;
        }

        // Check sequential array ['dts_receiving', ...]
        return in_array($category, $mutedData, true);
    }

    /**
     * Retrieve the associative map of muted notification type keys to their mute timestamp.
     * Format: ['dts_receiving' => 'YYYY-MM-DD HH:MM:SS', ...]
     *
     * @param int|null $userId
     * @return array<string, string>
     */
    public static function getUserMutedMap(?int $userId = null): array
    {
        $userId = $userId ?: Auth::id();
        if (!$userId) {
            return [];
        }

        $tbl = Schema::hasTable('sys_personal_settings') ? 'sys_personal_settings' : 'personal_settings';
        if (!Schema::hasTable($tbl) || !Schema::hasColumn($tbl, 'muted_notification_types')) {
            return [];
        }

        try {
            $raw = DB::table($tbl)->where('user', $userId)->value('muted_notification_types');
            if (empty($raw)) {
                return [];
            }

            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($decoded)) {
                return [];
            }

            $map = [];
            foreach ($decoded as $key => $val) {
                if (is_string($key) && !is_numeric($key)) {
                    $map[$key] = (string) $val;
                } elseif (is_string($val) && $val !== '') {
                    $map[$val] = '2000-01-01 00:00:00';
                }
            }

            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Retrieve the list of muted notification type keys for a given user ID.
     *
     * @param int|null $userId
     * @return array<int, string>
     */
    public static function getUserMutedTypes(?int $userId = null): array
    {
        return array_values(array_keys(self::getUserMutedMap($userId)));
    }

    /**
     * Check if notification is muted for a specific user, considering creation time.
     *
     * @param string $content
     * @param string $subsystem
     * @param int|null $userId
     * @param mixed $notificationCreatedAt
     * @return bool
     */
    public static function isMutedForUser(string $content, string $subsystem = '', ?int $userId = null, mixed $notificationCreatedAt = null): bool
    {
        $mutedMap = self::getUserMutedMap($userId);
        return self::isMuted($content, $subsystem, $mutedMap, $notificationCreatedAt);
    }

    /**
     * Update user's muted notification types in personal settings,
     * recording timestamps so that muting only applies starting from the user's change.
     *
     * @param int $userId
     * @param array<int|string, string> $mutedTypes
     * @return void
     */
    public static function setUserMutedTypes(int $userId, array $mutedTypes): void
    {
        $tbl = Schema::hasTable('sys_personal_settings') ? 'sys_personal_settings' : 'personal_settings';
        if (!Schema::hasTable($tbl)) {
            return;
        }

        $existingMap = self::getUserMutedMap($userId);
        $newMap = [];
        $nowStr = now()->toDateTimeString();

        foreach ($mutedTypes as $key => $val) {
            if (is_string($key) && !is_numeric($key)) {
                $typeKey = $key;
                $timestamp = !empty($val) ? (string) $val : ($existingMap[$typeKey] ?? $nowStr);
            } else {
                $typeKey = (string) $val;
                $timestamp = $existingMap[$typeKey] ?? $nowStr;
            }

            if ($typeKey !== '') {
                $newMap[$typeKey] = $timestamp;
            }
        }

        $json = json_encode($newMap);

        DB::table($tbl)->updateOrInsert(
            ['user' => $userId],
            [
                'muted_notification_types' => $json,
                'updated_at' => now(),
            ]
        );
    }
}
