<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RdpNotificationService
{
    /**
     * Cache and retrieve Records Disposition Program subsystem ID.
     */
    protected static function getRdpSubsystemId(): int
    {
        static $subsystemId = null;
        if ($subsystemId === null) {
            $subsystemsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
            $subsystemId = DB::table($subsystemsTbl)
                ->where('subsystem_name', 'Records Disposition Program')
                ->value('subsystem_id') ?? 2;
        }
        return (int) $subsystemId;
    }

    /**
     * Dispatch a notification to the notification tables for a given office code.
     *
     * @param string $officeCode The target office code receiving the notification
     * @param string $message The notification text message
     * @param string|null $redirectUrl Optional URL link when user clicks notification
     * @param int|null $excludeAccountId Optional account ID to suppress/auto-mark read
     * @return bool
     */
    public static function createNotification(string $officeCode, string $message, ?string $redirectUrl = null, ?int $excludeAccountId = null): bool
    {
        $officeCode = trim($officeCode);
        $message = trim($message);

        if (empty($officeCode) || empty($message)) {
            return false;
        }

        try {
            $subsystemId = static::getRdpSubsystemId();
            $notifContentTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
            $notificationsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
            $notifDivTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notification_div') ? 'sys_notification_div' : 'notification_div';

            $contentId = DB::table($notifContentTbl)->insertGetId([
                'system'       => $subsystemId,
                'content'      => $message,
                'redirect_url' => $redirectUrl ?: '/rdp/pending/list',
                'created_at'   => now(),
            ]);

            $notificationId = DB::table($notificationsTbl)->insertGetId([
                'office'     => $officeCode,
                'contents'   => $contentId,
                'created_at' => now(),
            ]);

            if ($excludeAccountId && $excludeAccountId > 0 && $notificationId) {
                DB::table($notifDivTbl)->updateOrInsert(
                    [
                        'id'          => $notificationId,
                        'account_rec' => $excludeAccountId,
                    ],
                    [
                        'is_dismissed' => true,
                        'status'       => 'read',
                        'processed_on' => now(),
                    ]
                );
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('RdpNotificationService error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Notify submitting office that their submitted form has been approved.
     */
    public static function notifyFormApproved(string $officeCode, string $formLabel, string $clusterName, ?int $actorId = null): void
    {
        $message = "Your Submitted {$formLabel}: '{$clusterName}' has been approved and will now be recorded in the record office.";
        $url = '/rdp/pending/list';
        static::createNotification($officeCode, $message, $url, $actorId);
    }

    /**
     * Notify submitting office that their submitted form was rejected/returned for correction.
     */
    public static function notifyFormRejected(string $officeCode, string $formLabel, string $clusterName, ?string $remarks = null, ?int $actorId = null): void
    {
        $reasonText = !empty(trim((string)$remarks)) ? " Reason: " . trim($remarks) : "";
        $message = "Your Submitted {$formLabel}: '{$clusterName}' was returned for correction.{$reasonText}";
        $url = '/rdp/pending/list';
        static::createNotification($officeCode, $message, $url, $actorId);
    }

    /**
     * Notify target office of a newly submitted Record Request.
     */
    public static function notifyRecordRequestSubmitted(string $targetOfficeCode, string $originOfficeCode, string $recordTitle): void
    {
        $message = "Office {$originOfficeCode} submitted a Record Request for '{$recordTitle}' for your office approval.";
        $url = '/rdp/received-documents/record-requests';
        static::createNotification($targetOfficeCode, $message, $url);
    }

    /**
     * Notify creator office when target office accepts their Record Request.
     */
    public static function notifyRecordRequestAccepted(string $originOfficeCode, string $targetOfficeCode, string $recordTitle): void
    {
        $message = "Office {$targetOfficeCode} accepted your Record Request for '{$recordTitle}'.";
        $url = '/rdp/received-documents/record-requests';
        static::createNotification($originOfficeCode, $message, $url);
    }

    /**
     * Notify creator office when target office declines their Record Request.
     */
    public static function notifyRecordRequestDeclined(string $originOfficeCode, string $targetOfficeCode, string $recordTitle, ?string $reason = null): void
    {
        $reasonText = !empty(trim((string)$reason)) ? " Reason: " . trim($reason) : "";
        $message = "Office {$targetOfficeCode} declined your Record Request for '{$recordTitle}'.{$reasonText}";
        $url = '/rdp/received-documents/record-requests';
        static::createNotification($originOfficeCode, $message, $url);
    }
}
