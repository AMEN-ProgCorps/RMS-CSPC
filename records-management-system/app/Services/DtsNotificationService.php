<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DtsNotificationService
{
    /**
     * Cache and retrieve Document Tracking System subsystem ID.
     */
    protected static function getDtsSubsystemId(): int
    {
        static $subsystemId = null;
        if ($subsystemId === null) {
            $subsystemsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
            $subsystemId = DB::table($subsystemsTbl)
                ->where('subsystem_name', 'Document Tracking System')
                ->value('subsystem_id') ?? 1;
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
        if (empty($officeCode) || empty($message)) {
            return false;
        }

        try {
            $subsystemId = static::getDtsSubsystemId();
            $notifContentTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
            $notificationsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';

            $contentId = DB::table($notifContentTbl)->insertGetId([
                'system'       => $subsystemId,
                'content'      => $message,
                'redirect_url' => $redirectUrl ?: '/dts',
                'created_at'   => now(),
            ]);

            DB::table($notificationsTbl)->insert([
                'office'     => $officeCode,
                'contents'   => $contentId,
                'created_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('DtsNotificationService error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Trigger notification for a target office: "New Transaction [Control No] is waiting to be received."
     */
    public static function notifyWaitingToBeReceived(string $targetOfficeCode, string $controlNumber, string $transactionId): void
    {
        $message = "New Transaction {$controlNumber} is waiting to be received by your office.";
        $url = '/dts?open=' . urlencode($transactionId);
        static::createNotification($targetOfficeCode, $message, $url);
    }

    /**
     * Trigger notification for an office: "Transaction [Control No] has been received by [First Name]."
     */
    public static function notifyReceived(string $officeCode, string $userFirstName, string $controlNumber, string $transactionId): void
    {
        $name = !empty(trim($userFirstName)) ? trim($userFirstName) : 'A team member';
        $message = "Transaction {$controlNumber} has been received by {$name}.";
        $url = '/dts?open=' . urlencode($transactionId);
        static::createNotification($officeCode, $message, $url);
    }

    /**
     * Trigger notification for an office: "Transaction [Control No] has been forwarded by [First Name]."
     */
    public static function notifyForwarded(string $officeCode, string $userFirstName, string $controlNumber, string $transactionId): void
    {
        $name = !empty(trim($userFirstName)) ? trim($userFirstName) : 'A team member';
        $message = "Transaction {$controlNumber} has been forwarded by {$name}.";
        $url = '/dts?open=' . urlencode($transactionId);
        static::createNotification($officeCode, $message, $url);
    }

    /**
     * Trigger notification for an office: "Transaction [Control No] has been completed, you can now check it."
     */
    public static function notifyCompleted(string $officeCode, string $controlNumber, string $transactionId): void
    {
        $message = "Transaction {$controlNumber} has been completed, you can now check it.";
        $url = '/dts?open=' . urlencode($transactionId);
        static::createNotification($officeCode, $message, $url);
    }

    /**
     * Trigger notification for origin office when a hub office receives: "[Recipient Office] has received Transaction [Control No]."
     */
    public static function notifyHubOfficeReceived(string $originOfficeCode, string $recipientOfficeCode, string $controlNumber, string $transactionId): void
    {
        if (empty($originOfficeCode) || $originOfficeCode === $recipientOfficeCode) {
            return;
        }
        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $recipientName = DB::table($officeTbl)->where('office_code', $recipientOfficeCode)->value('office_name') ?: $recipientOfficeCode;
        $message = "{$recipientName} ({$recipientOfficeCode}) has received Transaction {$controlNumber}.";
        $url = '/dts?open=' . urlencode($transactionId);
        static::createNotification($originOfficeCode, $message, $url);
    }

    /**
     * Trigger notification for origin office when a hub office forwards/completes: "[Recipient Office] has completed and forwarded Transaction [Control No] to origin."
     */
    public static function notifyHubOfficeForwarded(string $originOfficeCode, string $recipientOfficeCode, string $controlNumber, string $transactionId, ?string $userFirstName = null): void
    {
        if (empty($originOfficeCode) || $originOfficeCode === $recipientOfficeCode) {
            return;
        }
        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $recipientName = DB::table($officeTbl)->where('office_code', $recipientOfficeCode)->value('office_name') ?: $recipientOfficeCode;
        $name = !empty(trim($userFirstName ?? '')) ? (' by ' . trim($userFirstName)) : '';
        $message = "{$recipientName} ({$recipientOfficeCode}) has completed and forwarded Transaction {$controlNumber} to {$originOfficeCode}{$name}.";
        $url = '/dts?open=' . urlencode($transactionId);
        static::createNotification($originOfficeCode, $message, $url);
    }
}
