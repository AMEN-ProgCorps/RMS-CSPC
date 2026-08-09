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
            $subsystemId = DB::table('subsystems')
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

            $contentId = DB::table('notif_content')->insertGetId([
                'system'       => $subsystemId,
                'content'      => $message,
                'redirect_url' => $redirectUrl ?: '/dts',
                'created_at'   => now(),
            ]);

            DB::table('notifications')->insert([
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
}
