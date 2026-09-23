<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DcsNotificationService
{
    /** @deprecated Prefer RegisterQueryHelper::rfioNotificationOfficeCode() — live DB may use RFOIU. */
    public const RFIO_OFFICE_CODE = 'RFOIU';

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
        $message = trim($message);
        $canonical = static::resolveOfficeCode($officeCode);

        if ($canonical === null || $message === '') {
            if (trim($officeCode) !== '' && $message !== '') {
                Log::warning('DcsNotificationService skipped: office_code not in office table', [
                    'office' => trim($officeCode),
                ]);
            }

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
                'office'     => $canonical,
                'contents'   => $contentId,
                'created_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('DcsNotificationService error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Match office_code case-insensitively and return the exact DB value
     * (Postgres FK is case-sensitive — "ACCOUNTING" ≠ "Accounting").
     */
    public static function resolveOfficeCode(?string $officeCode): ?string
    {
        $officeCode = trim((string) $officeCode);
        if ($officeCode === '') {
            return null;
        }

        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        $exact = DB::table($officeTbl)->where('office_code', $officeCode)->value('office_code');
        if (is_string($exact) && $exact !== '') {
            return $exact;
        }

        $ci = DB::table($officeTbl)
            ->whereRaw('LOWER(office_code) = ?', [strtolower($officeCode)])
            ->value('office_code');

        return is_string($ci) && $ci !== '' ? $ci : null;
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

    /**
     * Notify an office that appears on Document Distribution — not the submitter.
     * Limited DCS users only see /dcs/office/* notification links.
     */
    public static function notifyDocumentDistributed(
        string $officeCode,
        string $docNo,
        ?string $docTitle = null,
        ?int $revNo = null
    ): void {
        $docNo = trim($docNo);
        $title = trim((string) $docTitle);
        $revSuffix = $revNo !== null && $revNo > 0 ? ", Rev {$revNo}" : '';

        if ($title !== '' && $docNo !== '') {
            $message = "Incoming document \"{$title}\" ({$docNo}{$revSuffix}) will be distributed to your office.";
        } elseif ($title !== '') {
            $revLabel = $revNo !== null && $revNo > 0 ? " (Rev {$revNo})" : '';
            $message = "Incoming document \"{$title}\"{$revLabel} will be distributed to your office.";
        } elseif ($docNo !== '') {
            $revLabel = $revNo !== null && $revNo > 0 ? " (Rev {$revNo})" : '';
            $message = "Incoming document {$docNo}{$revLabel} will be distributed to your office.";
        } else {
            $message = 'An incoming document will be distributed to your office.';
        }

        static::createNotification($officeCode, $message, '/dcs/office/documents');
    }

    public static function notifyOfficeDocumentReceived(
        string $officeCode,
        string $receiverName,
        string $docTitle,
        ?string $docNo = null,
        ?int $receiverAccountId = null
    ): void {
        $name = static::displayName($receiverName);
        $title = trim($docTitle);
        $docNo = trim((string) $docNo);
        $label = $title !== '' ? "\"{$title}\"" : ($docNo !== '' ? $docNo : 'the document');
        $message = "{$name} marked incoming document {$label} as received.";
        $url = '/dcs/office/documents';
        if ($receiverAccountId && $receiverAccountId > 0) {
            $url .= '?ack_by=' . $receiverAccountId;
        }

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
        $number = trim($drfNo) !== '' ? ' ' . trim($drfNo) : '';
        $label = trim($title) !== '' ? ": {$title}" : '';
        $message = "New Document Request Form{$number}{$label} was submitted by {$name} and is ready for RFIO processing.";
        $url = '/dcs/register/requests/drf/' . $drfId;

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
        $number = trim($dcnNo) !== '' ? ' ' . trim($dcnNo) : '';
        $docLabel = trim($docNo) !== '' ? " for document {$docNo}" : '';
        $message = "New Document Change Notice{$number}{$docLabel} was submitted by {$name} and is ready for RFIO processing.";
        $url = '/dcs/register/requests/dcn/' . $dcnId;

        static::createNotification($targetOfficeCode, $message, $url);
    }

    /** Office corrected an existing intake form — notify RFIO without implying a brand-new form. */
    public static function notifyOfficeIntakeResubmitted(
        string $targetOfficeCode,
        string $submitterName,
        string $type,
        int $intakeId,
        string $title = ''
    ): void {
        $name = static::displayName($submitterName);
        $type = strtolower($type);
        $formLabel = $type === 'dcn' ? 'Document Change Notice' : 'Document Request Form';
        $label = trim($title) !== '' ? ": {$title}" : '';
        $message = "Updated {$formLabel}{$label} was resubmitted by {$name} after RFIO correction and is ready for review.";
        $url = '/dcs/register/requests/' . ($type === 'dcn' ? 'dcn' : 'drf') . '/' . $intakeId;

        static::createNotification($targetOfficeCode, $message, $url);
    }

    /**
     * RFIO approved the electronic submission — tell the office to print, sign, and bring the hard copy.
     */
    public static function notifyOfficeIntakeReadyForPrintSign(
        string $targetOfficeCode,
        string $type,
        int $intakeId,
        string $title = ''
    ): bool {
        $type = strtolower($type);
        $formLabel = $type === 'dcn' ? 'Document Change Notice' : 'Document Request Form';
        $label = trim($title) !== '' ? " \"{$title}\"" : '';
        $message = "Your {$formLabel}{$label} has been reviewed and is correct. "
            . 'You can now print and sign the request, then bring the signed hard copy to the Records Office for further processing.';
        $url = '/dcs/office/' . ($type === 'dcn' ? 'dcn' : 'drf') . '/' . $intakeId;

        return static::createNotification($targetOfficeCode, $message, $url);
    }

    /**
     * Remove RFIO office-intake submit notifications for a DRF/DCN once it is registered
     * or returned/resubmitted. Keeps ?registered=1 success notices.
     */
    public static function dismissOfficeIntakeNotifications(string $type, int $id): void
    {
        $type = strtolower(trim($type));
        $id = (int) $id;
        if (! in_array($type, ['drf', 'dcn'], true) || $id < 1) {
            return;
        }

        try {
            $notifTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
            $notifContentTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
            $notifDivTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notification_div') ? 'sys_notification_div' : 'notification_div';

            $rows = DB::table($notifContentTbl)
                ->whereNotNull('redirect_url')
                ->where('redirect_url', '!=', '')
                ->get(['id', 'redirect_url', 'content']);

            $contentIds = [];
            foreach ($rows as $row) {
                $url = (string) ($row->redirect_url ?? '');
                if ($url === '' || str_contains($url, 'registered=1') || str_contains($url, '/edit')) {
                    continue;
                }

                $parsed = \App\Helpers\OfficeIntakeHelper::parseIntakeNotificationUrl($url);
                if ($parsed && $parsed['type'] === $type && (int) $parsed['id'] === $id) {
                    $contentIds[] = (int) $row->id;
                    continue;
                }

                // Fallback: exact office/request path match (avoid /drf/1 matching /drf/12)
                if (preg_match('#/dcs/(?:office|requests|register/requests)/' . preg_quote($type, '#') . '/' . $id . '(?:/|$|\?)#', $url)) {
                    $contentIds[] = (int) $row->id;
                    continue;
                }

                $content = (string) ($row->content ?? '');
                if (
                    str_contains($content, 'ready for RFIO processing')
                    && (
                        str_contains($url, '/dcs/office/' . $type . '/' . $id)
                        || str_contains($url, '/dcs/requests/' . $type . '/' . $id)
                        || str_contains($url, '/dcs/register/requests/' . $type . '/' . $id)
                        || (str_contains($url, 'intake=' . $type) && (str_contains($url, 'id=' . $id) || str_contains($url, 'intake_id=' . $id)))
                    )
                ) {
                    $contentIds[] = (int) $row->id;
                }
            }

            $contentIds = array_values(array_unique(array_filter($contentIds)));
            if ($contentIds === []) {
                return;
            }

            $notificationIds = DB::table($notifTbl)
                ->whereIn('contents', $contentIds)
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            if ($notificationIds !== []) {
                DB::table($notifDivTbl)->whereIn('id', $notificationIds)->delete();
                DB::table($notifTbl)->whereIn('id', $notificationIds)->delete();
            }

            DB::table($notifContentTbl)->whereIn('id', $contentIds)->delete();
        } catch (\Throwable $e) {
            Log::error('DcsNotificationService dismissOfficeIntakeNotifications: ' . $e->getMessage());
        }
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
            ->map(fn ($code) => trim((string) $code))
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
