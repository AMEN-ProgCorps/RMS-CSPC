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
                'description' => 'When your office has a new controlled document to receive',
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
        if (str_contains($text, 'controlled document to receive') || str_contains($text, 'new controlled document')) {
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
     * Check if a given notification content is muted according to an array of muted type keys.
     *
     * @param string $content Notification message text
     * @param string $subsystem Subsystem name
     * @param array<int, string>|null $mutedTypes List of muted keys, e.g. ['dts_receiving']
     * @return bool
     */
    public static function isMuted(string $content, string $subsystem = '', ?array $mutedTypes = null): bool
    {
        if (empty($mutedTypes)) {
            return false;
        }

        $category = self::classify($content, $subsystem);
        return in_array($category, $mutedTypes, true);
    }

    /**
     * Retrieve the list of muted notification type keys for a given user ID.
     *
     * @param int|null $userId
     * @return array<int, string>
     */
    public static function getUserMutedTypes(?int $userId = null): array
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
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, 'is_string'));
            }
        } catch (\Throwable $e) {
            return [];
        }

        return [];
    }

    /**
     * Check if notification is muted for a specific user.
     *
     * @param string $content
     * @param string $subsystem
     * @param int|null $userId
     * @return bool
     */
    public static function isMutedForUser(string $content, string $subsystem = '', ?int $userId = null): bool
    {
        $mutedTypes = self::getUserMutedTypes($userId);
        return self::isMuted($content, $subsystem, $mutedTypes);
    }

    /**
     * Update user's muted notification types in personal settings.
     *
     * @param int $userId
     * @param array<int, string> $mutedTypes
     * @return void
     */
    public static function setUserMutedTypes(int $userId, array $mutedTypes): void
    {
        $tbl = Schema::hasTable('sys_personal_settings') ? 'sys_personal_settings' : 'personal_settings';
        if (!Schema::hasTable($tbl)) {
            return;
        }

        $cleanTypes = array_values(array_unique(array_filter($mutedTypes, 'is_string')));
        $json = json_encode($cleanTypes);

        DB::table($tbl)->updateOrInsert(
            ['user' => $userId],
            [
                'muted_notification_types' => $json,
                'updated_at' => now(),
            ]
        );
    }
}
