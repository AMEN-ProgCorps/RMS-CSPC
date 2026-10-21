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
     * @return bool
     */
    public static function createNotification(string $officeCode, string $message, ?string $redirectUrl = null): bool
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

            $contentId = DB::table($notifContentTbl)->insertGetId([
                'system'       => $subsystemId,
                'content'      => $message,
                'redirect_url' => $redirectUrl ?: '/rdp/received-documents/record-requests',
                'created_at'   => now(),
            ]);

            DB::table($notificationsTbl)->insert([
                'office'     => $officeCode,
                'contents'   => $contentId,
                'created_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('RdpNotificationService error: ' . $e->getMessage());
            return false;
        }
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
