<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DcsNotificationService
{
    public const RFIO_OFFICE_CODE = 'RFIO';

    /**
     * Cache and retrieve Document Control System subsystem ID.
     */
    protected static function getDcsSubsystemId(): int
    {
        static $subsystemId = null;
        if ($subsystemId === null) {
            $subsystemId = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems')
                ->where('subsystem_name', 'Document Control System')
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
     */
    public static function createNotification(string $officeCode, string $message, ?string $redirectUrl = null): bool
    {
        $officeCode = strtoupper(trim($officeCode));
        $message = trim($message);

        if ($officeCode === '' || $message === '') {
            return false;
        }

        try {
            $subsystemId = static::getDcsSubsystemId();

            $contentId = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content')->insertGetId([
                'system'       => $subsystemId,
                'content'      => $message,
                'redirect_url' => $redirectUrl ?: '/dcs',
                'created_at'   => now(),
            ]);

            DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications')->insert([
                'office'     => $officeCode,
                'contents'   => $contentId,
                'created_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('DcsNotificationService error: ' . $e->getMessage());

            return false;
        }
    }

    public static function notifyDocumentRegistered(
        string $officeCode,
        string $registrarName,
        string $docNo,
        int $requestId,
        ?int $revNo = null
    ): void {
        $name = static::displayName($registrarName);
        $revLabel = $revNo !== null && $revNo > 0 ? " (Rev {$revNo})" : '';
        $message = "Document {$docNo}{$revLabel} has been registered by {$name}.";
        $url = '/dcs/register/' . $requestId . '/edit';

        static::createNotification($officeCode, $message, $url);
    }

    public static function notifyOfficeDrfSubmitted(
        string $targetOfficeCode,
        string $submitterName,
        string $drfNo,
        string $title,
        int $drfId
    ): void {
        $name = static::displayName($submitterName);
        $label = trim($title) !== '' ? ": {$title}" : '';
        $message = "New Document Request Form {$drfNo}{$label} was submitted by {$name} and is ready for RFIO processing.";
        $url = '/dcs/office/drf/' . $drfId;

        static::createNotification($targetOfficeCode, $message, $url);
    }

    public static function notifyOfficeDcnSubmitted(
        string $targetOfficeCode,
        string $submitterName,
        string $dcnNo,
        string $docNo,
        int $dcnId
    ): void {
        $name = static::displayName($submitterName);
        $docLabel = trim($docNo) !== '' ? " for document {$docNo}" : '';
        $message = "New Document Change Notice {$dcnNo}{$docLabel} was submitted by {$name} and is ready for RFIO processing.";
        $url = '/dcs/office/dcn/' . $dcnId;

        static::createNotification($targetOfficeCode, $message, $url);
    }

    public static function notifyDocumentStamped(
        string $officeCode,
        string $stamperName,
        string $docNo,
        int $requestId,
        string $stampLabel
    ): void {
        $name = static::displayName($stamperName);
        $stamp = trim($stampLabel) !== '' ? $stampLabel : 'Reference';
        $message = "Document {$docNo} has been stamped ({$stamp}) by {$name}.";
        $url = '/dcs/stamping?request_id=' . urlencode((string) $requestId);

        static::createNotification($officeCode, $message, $url);
    }

    /** @param  array<int|string|null>  $officeIds
     * @return list<string>
     */
    public static function officeCodesFromIds(array $officeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $officeIds))));
        if ($ids === []) {
            return [];
        }

        return DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')
            ->whereIn('id', $ids)
            ->whereNotNull('office_code')
            ->where('office_code', '!=', '')
            ->pluck('office_code')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected static function displayName(?string $name): string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : 'A team member';
    }
}
