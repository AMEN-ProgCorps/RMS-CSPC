<?php

namespace App\Helpers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Services\DocumentStorageService;
use App\Services\DcsNotificationService;

/**
 * Register writes via DB::table(). Real PK is always `id`.
 * DCS file: RegisterPersistHelper in app/ so Composer autoloads it.
 * Writes a registration through DB::table(). Scans, notices, and the office-intake handoff happen here.
 * Do not move this class back under resources/views.
 * Public methods:
 * logAdminChange, appendOfficeContext, logDcsAccess, logDcsBlockedAccess, scanFileRules,
 * masterlistOriginalNameFromRequest, applyMasterlistOriginalName,
 * syncExistingMasterlistScanName, syncDrfTitleForRequest,
 * syncMasterlistScanNameAfterCommit, syncSavedScanNamesAfterCommit,
 * rejectInactiveOfficeIds, blankStringsToNull, syncedDocTitle, resolveRevisedFromDocNo,
 * resolveReviseNo, parseSyllabiFacultyNames, resolveOriginator, validateCheckedSections,
 * safeDraftLeaveRedirect, validateMasterlistHasData, masterlistDeadlineValue,
 * withRequiredMasterlistChecklist, parentDocTypeCode, buildScanBasename,
 * dccGroupKeyFromDocType, dccSourceUnitFromRequest, dccContextFromRequest,
 * formatScanDatePart, titleToScanSegment, storeDcsScanUpload, isKnownPublicScanPath,
 * dcsScanFields, existingOfficeIdSet, syllabiDraftContext, saveDistributionOffices,
 * allowedDistributeOfficeIdsForDrfIntake, isAutosaveRequest, draftErrorResponse,
 * normalizeDrfNo, drfNoTaken, rejectDuplicateDrfNo, draftAutosaveSuccessResponse, persist,
 * dcsDocType, isSyllabiLikeSubTypeRow, normalizeSyllabiYearLevel, typeBlocksRevision,
 * effectiveAllowsRevision, pendingIntakeIsOfficeDcnRevision, versionIdIsRevised,
 * versionIdIsRecur, revisedVersionTypeId, newVersionTypeId,
 * applyAllowsRevisionToMasterlistRow, findMatchingRegistrationRows,
 * mismatchErrorMessageFromRow, rejectDuplicateSyllabiContext,
 * validateSyllabiLikeRequestRows, findSyllabiContextDuplicate,
 * masterlistScanPathForRevision, resolveRevisionScannedCopyPath, saveDcnOfficesById,
 * saveOriginsFromOfficeIds, saveRelatedDocumentIds, insertRows,
 * saveSyllabiRowsFromRequest, syncRevisionStatusForMasterlist, markLatestMasterlist,
 * promoteLatestForDoc, obsoleteSelectedRevisionSources, findLatestMasterlistForRecur,
 * supersedeLatestForRecur, applyRecurRevisedFrom.
*/
class RegisterPersistHelper
{
    public const SCAN_MAX_KB = 204800;

    /**
     * Shared admin_logs entry with Document Control System as what_system reference.
     * Same pattern as RDP/Admin Console activity logging.
     */
    public static function logAdminChange(string $changes, bool $includeOffice = true): void
    {
        try {
            $adminId = auth()->id();
            if (!$adminId) {
                return;
            }

            $systemId = once(static function () {
                return (int) DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems')
                    ->where('subsystem_name', 'Document Control System')
                    ->value('subsystem_id');
            });

            if ($systemId < 1) {
                return;
            }

            if ($includeOffice) {
                $changes = self::appendOfficeContext($changes);
            }

            DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
                'changes' => $changes,
                'admin_id' => $adminId,
                'what_system' => $systemId,
                'when_changes' => now(),
            ]);
        } catch (\Throwable $e) {
            // Non-fatal — never break the primary operation
        }
    }

    /** Append the acting user's office to a DCS log line when known. */
    public static function appendOfficeContext(string $changes): string
    {
        if (str_contains($changes, ' — Office: ')) {
            return $changes;
        }

        $office = RegisterQueryHelper::currentOfficeName();
        if ($office === '—') {
            return $changes;
        }

        return $changes . ' — Office: ' . $office;
    }

    /** Log once per distinct DCS page visit (per session). */
    public static function logDcsAccess(Request $request): void
    {
        if (! self::shouldLogDcsPageVisit($request)) {
            return;
        }

        $routeName = $request->route()?->getName();
        $label = self::dcsPageActionLabel($routeName);
        if ($label === null) {
            return;
        }

        $sessionKey = 'dcs_page_logged.' . ($routeName ?? md5($request->path()));
        if (session()->get($sessionKey)) {
            return;
        }
        session()->put($sessionKey, true);

        $details = self::dcsPageActionDetails($request, $routeName);
        self::logAdminChange($label . $details);
    }

    /** Log blocked full-DCS or allowlist access attempts (once per session per path). */
    public static function logDcsBlockedAccess(Request $request, string $reason): void
    {
        try {
            $adminId = auth()->id();
            if (!$adminId) {
                return;
            }

            $path = ltrim($request->path(), '/');
            $sessionKey = 'dcs_blocked_logged.' . md5($reason . '|' . $path);
            if (session()->get($sessionKey)) {
                return;
            }
            session()->put($sessionKey, true);

            $routeName = $request->route()?->getName();
            $detail = $routeName ? (' — route: ' . $routeName) : (' — path: ' . $path);
            $method = strtoupper($request->method());

            self::logAdminChange('Blocked DCS access (' . $reason . ') — ' . $method . $detail);
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    private static function shouldLogDcsPageVisit(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        $path = ltrim($request->path(), '/');
        if (str_contains($path, '/api/') || str_starts_with($path, 'dcs/api/')) {
            return false;
        }
        if (str_contains($path, 'view-document')) {
            return false;
        }
        if (str_contains($path, 'livewire')) {
            return false;
        }

        return true;
    }

    private static function dcsPageActionLabel(?string $routeName): ?string
    {
        return match ($routeName) {
            'dcs', 'dcs.dashboard' => 'Opened DCS Dashboard',
            'dcs.register.create' => 'Opened Document Registration',
            'dcs.register.update' => 'Opened Update Documents',
            'dcs.register.edit' => 'Opened Document Editor',
            'dcs.register.history' => 'Viewed Document History',
            'dcs.recycle-bin' => 'Opened Recycle Bin',
            'dcs.review' => 'Opened Document Review',
            'dcs.database.index' => 'Opened Document Database',
            'dcs.stamping.index' => 'Opened Document Stamping',
            'dcs.manage-files' => 'Opened Manage Files',
            'dcs.settings.index' => 'Opened DCS Settings',
            'dcs.office.drf.index' => 'Opened Office DRF List',
            'dcs.office.drf.create' => 'Opened Create Office DRF',
            'dcs.office.drf.show' => 'Viewed Office DRF',
            'dcs.office.drf.print' => 'Printed Office DRF',
            'dcs.office.dcn.index' => 'Opened Office DCN List',
            'dcs.office.dcn.create' => 'Opened Create Office DCN',
            'dcs.office.dcn.show' => 'Viewed Office DCN',
            'dcs.office.dcn.print' => 'Printed Office DCN',
            'dcs.reports.masterlist' => 'Opened Masterlist Report',
            'dcs.reports.monitoring' => 'Opened Monitoring Report',
            'dcs.reports.distributionRetrieval' => 'Opened Distribution and Retrieval monitor',
            'dcs.reports.opcr' => 'Opened OPCR Report',
            'dcs.reports.others' => 'Opened Other Reports',
            'dcs.reports.syllabiTos' => 'Opened Syllabi/TOS Report',
            default => null,
        };
    }

    private static function dcsPageActionDetails(Request $request, ?string $routeName): string
    {
        $details = '';

        if ($routeName === 'dcs.register.edit') {
            $id = $request->route('id');
            if ($id) {
                $details = ' #' . $id;
            }
        } elseif ($routeName === 'dcs.register.history') {
            $docNo = $request->route('docNo');
            if ($docNo) {
                $details = ' — ' . $docNo;
            }
        } elseif (in_array($routeName, ['dcs.office.drf.show', 'dcs.office.drf.print'], true)) {
            $id = $request->route('id');
            if ($id) {
                $details = ' #' . $id;
            }
        } elseif (in_array($routeName, ['dcs.office.dcn.show', 'dcs.office.dcn.print'], true)) {
            $id = $request->route('id');
            if ($id) {
                $details = ' #' . $id;
            }
        }

        return $details;
    }

    public static function scanFileRules(): array
    {
        $rule = ['nullable', 'file', 'max:'.self::SCAN_MAX_KB, function (string $attribute, mixed $value, \Closure $fail): void {
            if (! $value instanceof \Illuminate\Http\UploadedFile) {
                return;
            }
            $problem = \App\Support\DcsUploadGuard::pdfProblem($value, self::SCAN_MAX_KB);
            if ($problem !== null) {
                $fail($problem);
            }
        }];

        return [
            'drfFile' => $rule,
            'dcnFile' => $rule,
            'uploadScannedCopy' => $rule,
            'scanneddist' => $rule,
            'scannedCopy.*' => $rule,
            'syllabiScannedDrf.*' => $rule,
        ];
    }

    /** Original client filename for the masterlist scan (when column exists). */
    public static function masterlistOriginalNameFromRequest(Request $request): ?string
    {
        $convention = self::buildScanBasename($request, 'DOC', $request->input('masterlistEffectivityDate'));

        return DocumentStorageService::sanitizeDcsScanBasename($convention) . '.pdf';
    }

    public static function applyMasterlistOriginalName(array &$row, Request $request, bool $onlyIfUploaded = true): void
    {
        if (!Schema::hasColumn('dcs_masterlist_registration', 'scanned_masterlist_original_name')) {
            return;
        }
        if ($onlyIfUploaded && !$request->hasFile('uploadScannedCopy')) {
            return;
        }
        $original = self::masterlistOriginalNameFromRequest($request);
        if ($original !== null) {
            $row['scanned_masterlist_original_name'] = $original;
        }
    }

    /**
     * When title/date/rev change on update without a new upload, rename the stored scan
     * and refresh the display name to match the convention.
     */
    public static function syncExistingMasterlistScanName(Request $request, ?string $existingPath): array
    {
        $result = [
            'path' => $existingPath,
            'original_name' => null,
        ];
        if (!is_string($existingPath) || trim($existingPath) === '') {
            return $result;
        }

        $convention = self::buildScanBasename($request, 'DOC', $request->input('masterlistEffectivityDate'));
        $ext = pathinfo($existingPath, PATHINFO_EXTENSION) ?: 'pdf';
        $desiredName = DocumentStorageService::sanitizeDcsScanBasename($convention) . '.' . $ext;
        $result['original_name'] = $desiredName;

        $currentBase = pathinfo($existingPath, PATHINFO_FILENAME);
        $desiredBase = pathinfo($desiredName, PATHINFO_FILENAME);
        if (strcasecmp($currentBase, $desiredBase) === 0) {
            return $result;
        }

        $newPath = DocumentStorageService::renameDcsScanToBasename($existingPath, $convention);
        if (is_string($newPath) && $newPath !== '') {
            $result['path'] = $newPath;
            $result['original_name'] = basename($newPath);
        }

        return $result;
    }

    /** Keep DRF title aligned with the masterlist / shared document title. */
    public static function syncDrfTitleForRequest(int $requestId, ?string $title): void
    {
        $title = trim((string) $title);
        if ($requestId < 1 || $title === '') {
            return;
        }
        if (! Schema::hasTable('dcs_document_request_form')) {
            return;
        }
        DB::table('dcs_document_request_form')
            ->where('request_id', $requestId)
            ->update(array_filter([
                'doc_title' => $title,
                'updated_at' => Schema::hasColumn('dcs_document_request_form', 'updated_at') ? now() : null,
            ], fn ($v) => $v !== null));
    }

    /**
     * Rename masterlist scan after the registration transaction committed
     * (file moves are not transactional — avoid orphaning the PDF on rollback).
     */
    public static function syncMasterlistScanNameAfterCommit(Request $request, int $requestId): void
    {
        if ($requestId < 1) {
            return;
        }
        $ml = DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first();
        if (! $ml || empty($ml->scanned_masterlist)) {
            return;
        }

        $synced = self::syncExistingMasterlistScanName($request, (string) $ml->scanned_masterlist);
        $newPath = $synced['path'] ?? null;
        $original = $synced['original_name'] ?? null;
        if (! is_string($newPath) || $newPath === '') {
            return;
        }

        $payload = ['scanned_masterlist' => $newPath];
        if ($original && Schema::hasColumn('dcs_masterlist_registration', 'scanned_masterlist_original_name')) {
            $payload['scanned_masterlist_original_name'] = $original;
        }
        if (Schema::hasColumn('dcs_masterlist_registration', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        DB::table('dcs_masterlist_registration')->where('id', $ml->id)->update($payload);
        DocumentStorageService::relocateMasterlistScanToSource((int) $ml->id);
    }

    /**
     * After the registration is saved, rename every stored scan to the naming convention.
     * The name comes from the saved document, not from the file that was uploaded.
     */
    public static function syncSavedScanNamesAfterCommit(Request $request, int $requestId): void
    {
        self::syncMasterlistScanNameAfterCommit($request, $requestId);
        self::renameStoredScan($request, $requestId, 'dcs_document_request_form', 'scanned_drf', 'DRF', $request->input('drfDate'));
        self::renameStoredScan($request, $requestId, 'dcs_document_change_notice', 'scanned_dcn', 'DCN', $request->input('noticeDate'));
        $distributionDate = $request->input('distributionFormDate') ?: $request->input('distributionDate');
        self::renameStoredScan($request, $requestId, 'dcs_document_distribution', 'scanned_distribution', 'D&R', $distributionDate);
    }

    private static function renameStoredScan(Request $request, int $requestId, string $table, string $column, string $token, mixed $date): void
    {
        if ($requestId < 1 || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        $row = DB::table($table)->where('request_id', $requestId)->orderByDesc('id')->first();
        if (! $row || empty($row->{$column})) {
            return;
        }

        $convention = self::buildScanBasename($request, $token, is_string($date) ? $date : null);
        $newPath = DocumentStorageService::renameDcsScanToBasename((string) $row->{$column}, $convention);
        if (! is_string($newPath) || $newPath === '' || $newPath === (string) $row->{$column}) {
            return;
        }

        $payload = [$column => $newPath];
        if (Schema::hasColumn($table, 'updated_at')) {
            $payload['updated_at'] = now();
        }
        DB::table($table)->where('id', $row->id)->update($payload);
    }

    public static function rejectInactiveOfficeIds(Request $request): ?RedirectResponse
    {
        $ids = [];
        foreach (['drfSourceUnit', 'dcnSourceUnit', 'masterlistOfficeIds', 'distOffice', 'retrievalOffice'] as $key) {
            foreach ((array) $request->input($key, []) as $value) {
                $id = (int) $value;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return null;
        }

        $inactive = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')
            ->whereIn('id', $ids)
            ->where('is_active', false)
            ->orderBy('office_name')
            ->pluck('office_name');

        if ($inactive->isEmpty()) {
            return null;
        }

        return back()->withInput()->with(
            'error',
            'These offices are inactive and cannot be used: ' . $inactive->implode(', ') . '.'
        );
    }

    public static function blankStringsToNull(Request $request): void
    {
        $multiline = [
            'descriptionReason', 'description_reason', 'keywords',
            'changeFrom', 'changeTo', 'change_from', 'change_to',
            'deleteReason', 'deleted_reason', 'remarks', 'recommended_actions',
            'revisionPurpose', 'briefPurpose', 'justification', 'dcnJustification',
            'editUnlockReason', 'edit_unlock_reason', 'reason', 'denyNote', 'description',
        ];
        $skip = array_merge(['_token', '_method'], array_keys($request->allFiles()));
        $clean = [];
        foreach ($request->all() as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            $clean[$key] = self::sanitizeIncomingValue($value, is_string($key) ? $key : '', $multiline);
        }
        if ($clean !== []) {
            $request->merge($clean);
        }
    }

    /** Strip HTML/scripts from DCS form text; keep document numbers and punctuation. */
    private static function sanitizeIncomingValue(mixed $value, string $key, array $multiline): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $childKey => $child) {
                $nextKey = is_string($childKey) ? $childKey : $key;
                $out[$childKey] = self::sanitizeIncomingValue($child, $nextKey, $multiline);
            }

            return $out;
        }
        if (! is_string($value)) {
            return $value;
        }

        $value = str_replace("\0", '', $value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $value) ?? '';
        $value = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $value) ?? '';
        $value = strip_tags($value);
        $value = str_replace(['<', '>'], '', $value);
        if (in_array($key, $multiline, true)) {
            $value = str_replace(["\r\n", "\r"], "\n", $value);
            $value = preg_replace("/[ \t]+/u", ' ', $value) ?? '';
            $value = preg_replace("/\n{3,}/u", "\n\n", $value) ?? '';
        } else {
            $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public static function syncedDocTitle(Request $request): ?string
    {
        // Masterlist / syllabi title is the controlled-document source of truth.
        // Prefer them over a stale DRF title left in the form when DRF is unchecked.
        $ml = trim((string) $request->input('masterlistDocTitle', ''));
        if ($ml !== '') {
            return $ml;
        }
        $syllabi = trim((string) $request->input('syllabiDocTitle', ''));
        if ($syllabi !== '') {
            return $syllabi;
        }
        $drf = trim((string) $request->input('drfTitle', ''));

        return $drf !== '' ? $drf : null;
    }

    /**
     * Resolve lineage prior doc no for a revised registration.
     * Prefer the form's revised_from when renumbering; otherwise inherit from
     * any existing row of the new doc no so unlimited same-number revises keep the chain.
     */
    public static function resolveRevisedFromDocNo(
        Request $request,
        string $newDocNo,
        int $docTypeId,
        ?int $subTypeId = null
    ): ?string {
        if (!Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
            return null;
        }

        $newDocNo = trim($newDocNo);
        $from = trim((string) $request->input('revised_from_doc_no', ''));
        if ($from !== '' && ($newDocNo === '' || strcasecmp($from, $newDocNo) !== 0)) {
            return $from;
        }

        if ($newDocNo === '' || $docTypeId < 1) {
            return null;
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->where('ml.doc_no', $newDocNo)
            ->where('dr.doc_type_id', $docTypeId)
            ->whereNotNull('ml.revised_from_doc_no')
            ->where('ml.revised_from_doc_no', '!=', '');

        if ($subTypeId) {
            $query->where('dr.sub_type_id', $subTypeId);
        } else {
            $query->whereNull('dr.sub_type_id');
        }

        if (Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            $query->whereNull('dr.deleted_at');
        }

        $inherited = $query
            ->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->value('ml.revised_from_doc_no');

        $inherited = trim((string) ($inherited ?? ''));
        if ($inherited === '' || strcasecmp($inherited, $newDocNo) === 0) {
            return null;
        }

        return $inherited;
    }

    /**
     * Revision number for masterlist: empty/null → 0; otherwise the entered integer (>= 0).
     */
    public static function resolveReviseNo(Request $request, mixed $fallback = null): int
    {
        $mode = $request->input('registration_mode', 'new');
        if ($mode !== 'revised' && ($request->boolean('insert_shift_confirmed') || $request->boolean('allow_duplicate_doc_no'))) {
            return 0;
        }

        $raw = $request->input('masterlistRevisionNo');
        if ($raw === null || $raw === '') {
            if ($fallback === null || $fallback === '') {
                return 0;
            }

            return max(0, (int) $fallback);
        }

        return max(0, (int) $raw);
    }

    /**
     * Split a syllabi faculty field into names without breaking credentials
     * such as "Bien Paolo Monsalve, MNE".
     *
     * @return list<string>
     */
    public static function parseSyllabiFacultyNames(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }

        $known = [];
        if (Schema::hasTable('dcs_faculties')) {
            $q = DB::table('dcs_faculties');
            if (class_exists(SettingsRecycleHelper::class)) {
                SettingsRecycleHelper::applyNotDeleted($q, 'dcs_faculties');
            }
            $known = $q->pluck('faculty_name')->filter()->map(fn ($n) => trim((string) $n))->values()->all();
            usort($known, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        }

        foreach ($known as $name) {
            if (strcasecmp($name, $raw) === 0) {
                return [$name];
            }
        }

        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        $matched = [];
        $buffer = '';
        foreach ($parts as $part) {
            $candidate = $buffer === '' ? $part : ($buffer.', '.$part);
            $hit = null;
            foreach ($known as $name) {
                if (strcasecmp($name, trim($candidate)) === 0) {
                    $hit = $name;
                    break;
                }
            }
            if ($hit !== null) {
                $matched[] = $hit;
                $buffer = '';
            } else {
                $buffer = $candidate;
            }
        }

        if ($matched === []) {
            return [$raw];
        }
        if (trim($buffer) !== '') {
            $matched[] = trim($buffer);
        }

        return array_values(array_filter($matched, fn ($n) => $n !== ''));
    }

    /** Join originator chips or a comma-typed string into one stored value. */
    public static function joinedOriginatorNames(mixed $rawName): string
    {
        $parts = is_array($rawName) ? $rawName : [$rawName];
        $names = [];
        foreach ($parts as $part) {
            foreach (preg_split('/\s*,\s*/u', trim((string) $part)) ?: [] as $name) {
                $name = trim($name);
                if ($name !== '' && ! in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return implode(', ', $names);
    }

    /**
     * @return list<array{type: string, id: mixed, label: string}>
     */
    public static function originatorSeedFromStored(mixed $rawName, mixed $originatorId = null): array
    {
        $joined = self::joinedOriginatorNames($rawName);
        if ($joined === '') {
            return [];
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/u', $joined) ?: [])));
        $seed = [];
        foreach ($parts as $i => $label) {
            $seed[] = [
                'type' => 'name',
                'id' => ($i === 0 && $originatorId) ? $originatorId : ('n' . ($i + 1)),
                'label' => $label,
            ];
        }

        return $seed;
    }

    /**
     * Resolve masterlist originator for analytics: find-or-create in dcs_originators.
     * Returns ['originator_id' => int|null, 'originator_name' => string|null].
     */
    public static function resolveOriginator(mixed $rawName): array
    {
        $name = self::joinedOriginatorNames($rawName);
        if ($name === '') {
            return ['originator_id' => null, 'originator_name' => null];
        }

        if (!Schema::hasTable('dcs_originators')) {
            return ['originator_id' => null, 'originator_name' => $name];
        }

        $existing = DB::table('dcs_originators')
            ->whereRaw('LOWER(originator_name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            return [
                'originator_id' => (int) $existing->id,
                'originator_name' => $existing->originator_name,
            ];
        }

        $now = now();
        try {
            $id = DB::table('dcs_originators')->insertGetId([
                'originator_name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // Race: another request inserted the same name — re-read.
            $existing = DB::table('dcs_originators')
                ->whereRaw('LOWER(originator_name) = ?', [mb_strtolower($name)])
                ->first();
            if ($existing) {
                return [
                    'originator_id' => (int) $existing->id,
                    'originator_name' => $existing->originator_name,
                ];
            }
            throw $e;
        }

        return ['originator_id' => (int) $id, 'originator_name' => $name];
    }

    public static function validateCheckedSections(Request $request): ?RedirectResponse
    {
        $checked = self::withRequiredMasterlistChecklist(
            array_map('intval', $request->input('checklists', []))
        );

        if (! in_array(3, $checked, true)) {
            return back()->withInput()->with('error', 'Masterlist Registration is required.');
        }

        if (! $request->boolean('save_as_draft') && $checked === []) {
            return back()->withInput()->with('error', 'Select at least one checklist section.');
        }

        return null;
    }

    /** Allow only same-app relative paths after draft leave autosave. */
    public static function safeDraftLeaveRedirect(mixed $raw): ?string
    {
        $path = trim((string) $raw);
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }
        if (str_contains($path, "\0") || preg_match('/[\s\\\\]/', $path)) {
            return null;
        }

        return $path;
    }

    /**
     * Masterlist must contain some substance (always on). Drafts cannot be empty shells
     * or Document No alone — drafts need at least one other filled masterlist field.
     */
    public static function validateMasterlistHasData(Request $request, bool $requireScan = false): RedirectResponse|\Illuminate\Http\JsonResponse|null
    {
        $docNo = trim((string) $request->input('masterlistDocNo', ''));
        $title = trim((string) (self::syncedDocTitle($request)
            ?: $request->input('masterlistDocTitle')
            ?: $request->input('drfTitle')
            ?: $request->input('syllabiDocTitle')
            ?: ''));
        $effectivity = trim((string) $request->input('masterlistEffectivityDate', ''));
        $pages = trim((string) $request->input('masterlistNoOfPages', ''));
        $keywords = trim((string) $request->input('keywords', ''));
        $deadline = trim((string) $request->input('deadlineOfSubmission', ''));
        $receiptDate = trim((string) $request->input('masterlistReceiptDate', ''));
        $hasUpload = $request->hasFile('uploadScannedCopy');
        $hasExistingScan = (bool) $request->boolean('has_existing_masterlist_scan');
        $hasOriginator = collect((array) $request->input('masterlistOriginator', []))
            ->merge((array) $request->input('masterlistOriginatorNames', []))
            ->filter(fn ($v) => trim((string) $v) !== '')
            ->isNotEmpty()
            || trim((string) $request->input('masterlistOriginatorName', '')) !== '';
        $hasSource = collect((array) $request->input('masterlistOfficeIds', []))
            ->merge((array) $request->input('masterlistSourceOffice', []))
            ->merge((array) $request->input('sourceOffice', []))
            ->filter()
            ->isNotEmpty();

        $flags = [
            'docNo' => $docNo !== '',
            'title' => $title !== '',
            'effectivity' => $effectivity !== '',
            'pages' => $pages !== '' && $pages !== '0',
            'keywords' => $keywords !== '',
            'deadline' => $deadline !== '',
            'receiptDate' => $receiptDate !== '',
            'originator' => $hasOriginator,
            'sourceUnit' => $hasSource,
            'scannedCopy' => $hasUpload || $hasExistingScan,
        ];
        $filledCount = count(array_filter($flags));
        $saveAsDraft = $request->boolean('save_as_draft');

        if ($saveAsDraft) {
            if ($filledCount < 2 || ($flags['docNo'] && $filledCount === 1)) {
                return self::draftErrorResponse(
                    $request,
                    'To save a draft, fill Document No plus at least one other masterlist field (e.g. Title, Effectivity Date, Pages, Keywords, Originator, or Source Unit).'
                );
            }
        } elseif ($filledCount < 1) {
            return self::draftErrorResponse(
                $request,
                'Masterlist Registration needs data (Document No, Title, Effectivity Date, or a scanned master copy) before saving.'
            );
        }

        if ($requireScan && ! $hasUpload && ! $hasExistingScan) {
            $subType = self::dcsDocType($request->input('sub_type_id'));
            if (! self::isSyllabiLikeSubTypeRow($subType)) {
                return self::draftErrorResponse(
                    $request,
                    'Upload the scanned master copy in Masterlist Registration before saving.'
                );
            }
        }

        if (! $saveAsDraft && $effectivity === '') {
            return self::draftErrorResponse(
                $request,
                'Effectivity Date is required.'
            );
        }

        return null;
    }

    public static function masterlistDeadlineValue(Request $request): ?string
    {
        if ($request->boolean('deadlineNotApplicable')) {
            return null;
        }

        $deadline = trim((string) $request->input('deadlineOfSubmission', ''));

        return $deadline !== '' ? $deadline : null;
    }

    /** Ensure checklist id 3 (Masterlist) is always included. */
    public static function withRequiredMasterlistChecklist(array $checked): array
    {
        $checked = array_values(array_unique(array_map('intval', $checked)));
        if (! in_array(3, $checked, true)) {
            $checked[] = 3;
        }

        return $checked;
    }

    /**
     * Parent doc-type code for scan filenames: INT / IF / EXT / F / LB.
     */
    public static function parentDocTypeCode(mixed $docTypeId): string
    {
        $type = self::dcsDocType($docTypeId);
        if (! $type) {
            return 'INT';
        }
        if (! empty($type->parent_id)) {
            $type = self::dcsDocType($type->parent_id) ?: $type;
        }
        $name = mb_strtolower(trim((string) ($type->doc_type_name ?? '')));

        if (str_contains($name, 'internal form')) {
            return 'IF';
        }
        if ($name === 'forms' || str_starts_with($name, 'form')) {
            return 'F';
        }
        if (str_contains($name, 'logbook')) {
            return 'LB';
        }
        if (str_contains($name, 'external')) {
            return 'EXT';
        }
        if ($name === 'internal' || str_starts_with($name, 'internal')) {
            return 'INT';
        }

        return 'INT';
    }

    /**
     * Build convention basename (no extension):
     * {Y-m-d}_{FORM}_{DOCTYPE}_{Document_Title}_Rev{N}
     * FORM: DRF | DOC | D&R | DCN | DRR
     */
    public static function buildScanBasename(Request $request, string $formToken, ?string $date): string
    {
        $datePart = self::formatScanDatePart($date);
        $typeCode = self::parentDocTypeCode($request->input('doc_type_id'));
        $title = trim((string) (self::syncedDocTitle($request)
            ?: $request->input('masterlistDocTitle')
            ?: $request->input('drfTitle')
            ?: 'Untitled'));
        $titlePart = self::titleToScanSegment($title);
        $rev = self::resolveReviseNo($request);

        if (strcasecmp($formToken, 'D&R') === 0) {
            return "{$datePart}_{$formToken}_{$titlePart}_Rev{$rev}";
        }

        return "{$datePart}_{$formToken}_{$typeCode}_{$titlePart}_Rev{$rev}";
    }

    public static function dccGroupKeyFromDocType(mixed $docTypeId): string
    {
        return DocumentStorageService::dccGroupKeyFromDocTypeId($docTypeId);
    }

    /** @return array{cluster: string, office_name: string} */
    public static function dccSourceUnitFromRequest(Request $request): array
    {
        $ids = array_values(array_filter(array_map('intval', array_merge(
            (array) $request->input('masterlistOfficeIds', []),
            (array) $request->input('drfSourceUnit', []),
            (array) $request->input('dcnSourceUnit', [])
        ))));

        return DocumentStorageService::sourceClusterOfficeForOfficeId((int) ($ids[0] ?? 0));
    }

    /**
     * @return array<string, mixed>
     */
    public static function dccContextFromRequest(Request $request, string $category, bool $latest = true): array
    {
        $date = match (DocumentStorageService::normalizeDcsCategory($category)) {
            'drf', 'syllabi' => $request->input('drfDate') ?: $request->input('syllabiEffectivityDate'),
            'dcn', 'revisions' => $request->input('noticeDate'),
            'distribution', 'retrieval' => $request->input('distributionFormDate'),
            default => $request->input('masterlistEffectivityDate'),
        };
        $source = self::dccSourceUnitFromRequest($request);

        return [
            'date' => $date,
            'group' => self::dccGroupKeyFromDocType($request->input('doc_type_id')),
            'latest' => $latest,
            'cluster' => $source['cluster'],
            'office_name' => $source['office_name'],
        ];
    }

    public static function formatScanDatePart(?string $date): string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return now()->format('Y-m-d');
        }
        try {
            return \Carbon\Carbon::parse($date)->format('Y-m-d');
        } catch (\Throwable) {
            return now()->format('Y-m-d');
        }
    }

    public static function titleToScanSegment(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', '_', $title) ?? '');
        // Keep letters, numbers, underscore, hyphen, ampersand (for consistency with D&R token).
        $title = preg_replace('/[^\p{L}\p{N}_&\-]+/u', '', $title) ?? '';
        $title = trim($title, '_');

        return $title !== '' ? $title : 'Untitled';
    }

    public static function storeDcsScanUpload($file, array &$uploadedFiles, string $category, ?string $conventionBase = null, array $dccContext = []): string
    {
        $original = null;
        $useConvention = false;
        if ($conventionBase !== null && trim($conventionBase) !== '') {
            $ext = 'pdf';
            $base = pathinfo($conventionBase, PATHINFO_FILENAME) ?: $conventionBase;
            $original = DocumentStorageService::sanitizeDcsScanBasename($base) . '.' . $ext;
            $useConvention = true;
        }
        if ($dccContext === [] && request()) {
            $dccContext = self::dccContextFromRequest(request(), $category, true);
        }
        $path = DocumentStorageService::storeDcsScan($file, auth()->user(), $original, $category, $useConvention, $dccContext);
        $uploadedFiles[] = $path;

        return $path;
    }

    public static function isKnownPublicScanPath(string $path, array $extraAllowed = []): bool
    {
        $path = ltrim(str_replace(['../', '..\\'], '', $path), '/');
        if ($path === '' || str_contains($path, '..')) {
            return false;
        }
        if ($extraAllowed === [] || !in_array($path, $extraAllowed, true)) {
            return false;
        }

        return DocumentStorageService::dcsScanExists($path);
    }

    public static function dcsScanFields(string $table, string $pathColumn, ?string $path): array
    {
        return DocumentStorageService::dcsScanFields($table, $pathColumn, $path);
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int, int> office id => office id, for isset() checks
     */
    public static function existingOfficeIdSet(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $table = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();
    }

    /**
     * @return array{college_id: int, program_id: int, semester_id: int, school_year_id: int, course_type: string}
     */
    public static function syllabiDraftContext(Request $request): array
    {
        return [
            'college_id' => (int) $request->input('college_id', 0),
            'program_id' => (int) $request->input('program_id', 0),
            'semester_id' => (int) $request->input('semester_id', 0),
            'school_year_id' => (int) $request->input('school_year_id', 0),
            'course_type' => trim((string) $request->input('course_type', '')),
        ];
    }

    public static function saveDistributionOffices(int $distributionId, Request $request): void
    {
        $officeIds = array_values(array_filter(array_map('intval', (array) $request->input('distOffice', []))));
        $copies = (array) $request->input('distCopies', []);
        $validOffices = self::existingOfficeIdSet($officeIds);

        // DRF office intake: distribution offices are fixed to what the submitter selected.
        $intakeType = strtolower(trim((string) $request->input('office_intake_type', '')));
        $intakeId = (int) $request->input('office_intake_id', 0);
        if ($intakeType === 'drf' && $intakeId > 0) {
            $allowed = self::allowedDistributeOfficeIdsForDrfIntake($intakeId);
            if ($allowed !== []) {
                $officeIds = array_values(array_filter(
                    $officeIds,
                    static fn (int $id) => in_array($id, $allowed, true)
                ));
            }
        }

        $keptReceipts = [];
        if (Schema::hasColumn('dcs_distribution_offices', 'office_received_at')) {
            $keptReceipts = DB::table('dcs_distribution_offices')
                ->where('distribution_id', $distributionId)
                ->whereNotNull('office_received_at')
                ->get(['office_id', 'office_received_at', 'office_received_by'])
                ->keyBy(fn ($row) => (int) $row->office_id)
                ->all();
        }

        DB::table('dcs_distribution_offices')->where('distribution_id', $distributionId)->delete();

        $hasDistributionDate = Schema::hasColumn('dcs_distribution_offices', 'distribution_date');
        $hasReceivedAt = Schema::hasColumn('dcs_distribution_offices', 'office_received_at');
        $rows = [];
        foreach ($officeIds as $i => $id) {
            if ($id <= 0 || ! isset($validOffices[$id])) {
                continue;
            }
            $row = [
                'distribution_id' => $distributionId,
                'office_id' => $id,
                'copies' => $copies[$i] ?? 1,
                'sort_order' => $i,
            ];
            if ($hasDistributionDate) {
                $row['distribution_date'] = $request->input('distOfficeDate')[$i] ?? null;
            }
            $prior = $keptReceipts[$id] ?? null;
            if ($hasReceivedAt) {
                $row['office_received_at'] = $prior->office_received_at ?? null;
                $row['office_received_by'] = $prior->office_received_by ?? null;
            }
            $rows[] = $row;
        }
        self::insertRows('dcs_distribution_offices', $rows);
    }

    /**
     * Office IDs the DRF submitter selected for distribution (empty = no lock / unknown).
     *
     * @return list<int>
     */
    public static function allowedDistributeOfficeIdsForDrfIntake(int $drfId): array
    {
        $drf = OfficeIntakeHelper::findOfficeDrf($drfId);
        if (! $drf) {
            return [];
        }

        return OfficeIntakeHelper::distributeOfficeIds($drfId);
    }

    public static function isAutosaveRequest(Request $request): bool
    {
        return $request->boolean('autosave')
            || $request->header('X-DCS-Autosave') === '1';
    }

    /**
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public static function draftErrorResponse(Request $request, string $message, int $status = 422)
    {
        if (self::isAutosaveRequest($request) && $request->boolean('save_as_draft')) {
            return response()->json([
                'ok' => false,
                'autosave' => true,
                'message' => $message,
            ], $status);
        }

        return back()->withInput()->with('error', $message);
    }

    public static function normalizeDrfNo(mixed $raw): string
    {
        return trim((string) $raw);
    }

    public static function drfNoTaken(string $drfNo, int $excludeRequestId = 0): bool
    {
        $drfNo = self::normalizeDrfNo($drfNo);
        if ($drfNo === '' || ! Schema::hasTable('dcs_document_request_form')) {
            return false;
        }

        $query = DB::table('dcs_document_request_form')
            ->whereNotNull('drf_no')
            ->whereRaw("TRIM(drf_no) <> ''")
            ->whereRaw('LOWER(TRIM(drf_no)) = ?', [mb_strtolower($drfNo)]);
        if ($excludeRequestId > 0) {
            $query->where('request_id', '!=', $excludeRequestId);
        }

        return $query->exists();
    }

    public static function rejectDuplicateDrfNo(Request $request, int $excludeRequestId = 0): RedirectResponse|\Illuminate\Http\JsonResponse|null
    {
        $drfNo = self::normalizeDrfNo($request->input('drfNo'));
        if ($drfNo === '' || ! self::drfNoTaken($drfNo, $excludeRequestId)) {
            return null;
        }

        return self::draftErrorResponse(
            $request,
            'DRF No. "' . $drfNo . '" is already used. Please enter a unique DRF number.'
        );
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public static function draftAutosaveSuccessResponse(int $requestId, string $message = '')
    {
        return response()->json([
            'ok' => true,
            'autosave' => true,
            'request_id' => $requestId,
            'edit_url' => route('dcs.register.edit', $requestId, absolute: false),
            'update_url' => route('dcs.register.updateDoc', $requestId, absolute: false),
            'drafts_url' => route('dcs.register.drafts', absolute: false),
            'message' => $message !== ''
                ? $message
                : 'Draft auto-saved. Continue anytime from Document Registration → Drafts.',
            'saved_at' => now('Asia/Manila')->format('g:i A'),
        ]);
    }

    public static function persist(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        RegisterQueryHelper::assertFullDcsUser('register');

        $autosave = self::isAutosaveRequest($request) && $request->boolean('save_as_draft');

        if (! $autosave) {
            $rateCheck = \App\Services\RateLimiterService::check('dcs_create');
            if (!$rateCheck['allowed']) {
                return self::draftErrorResponse($request, $rateCheck['message'], 429);
            }
        }

        self::blankStringsToNull($request);
        $mode = $request->input('registration_mode', 'new');
        $saveAsDraft = $request->boolean('save_as_draft');

        $allowsRevision = self::effectiveAllowsRevision(
            $request->input('doc_type_id'),
            $request->input('sub_type_id')
        );
        $officeDcnRevision = self::pendingIntakeIsOfficeDcnRevision($request);

        if (
            ! $request->boolean('allow_duplicate_doc_no')
            && ! $request->boolean('insert_shift_confirmed')
        ) {
            $versionName = strtolower((string) DB::table('dcs_version_type')
                ->where('id', $request->input('version_id'))
                ->value('version_name'));
            if (str_contains($versionName, 'recur')) {
                $mode = 'recurring';
                $request->merge(['registration_mode' => 'recurring']);
            } elseif (str_contains($versionName, 'revis')) {
                $mode = 'revised';
                $request->merge(['registration_mode' => 'revised']);
            }
        }

        if ($mode === 'new' && ($request->boolean('insert_shift_confirmed') || $request->boolean('allow_duplicate_doc_no'))) {
            $request->merge([
                'masterlistRevisionNo' => 0,
                'revised_from_doc_no' => null,
            ]);
        }

        if ($mode === 'recurring') {
            $picked = self::findLatestMasterlistForRecur($request);
            if ($picked === null) {
                return back()->withInput()
                    ->with('error', 'This document is not in the system. Recur can only continue a document that is already registered.');
            }
            $typedNo = trim((string) $request->input('masterlistDocNo', ''));
            $pickedNo = trim((string) ($picked->doc_no ?? ''));
            $recurMerge = [
                'registration_mode' => 'recurring',
                'masterlistRevisionNo' => (int) ($picked->revise_no ?? 0),
                'supersede_masterlist_id' => (int) ($picked->id ?? 0),
            ];
            // Keep an empty Recur number empty. Do not copy a blank source onto masterlistDocNo.
            if ($pickedNo !== '') {
                $recurMerge['revised_from_doc_no'] = $pickedNo;
                if ($typedNo === '') {
                    $recurMerge['masterlistDocNo'] = $pickedNo;
                }
            } else {
                $recurMerge['revised_from_doc_no'] = '';
            }
            $request->merge($recurMerge);
        }

        if ($mode === 'revised' && ! $saveAsDraft) {
            $subType = self::dcsDocType($request->input('sub_type_id'));
            $isSyllabi = self::isSyllabiLikeSubTypeRow($subType);
            $docNo = trim((string) $request->input('masterlistDocNo'));
            $fromDocNo = trim((string) $request->input('revised_from_doc_no', ''));
            $lookupDocNo = $fromDocNo !== '' ? $fromDocNo : $docNo;
            $docTypeId = $request->input('doc_type_id');
            $subTypeId = $request->input('sub_type_id');

            // Non-syllabi revised docs must be based on a DCN "Documents for Revision" pick.
            if (!$isSyllabi) {
                $hasRevisionDoc = false;
                $titles = $request->input('documentTitle', []);
                $numbers = $request->input('documentNo', []);
                $rowCount = max(is_array($titles) ? count($titles) : 0, is_array($numbers) ? count($numbers) : 0);
                for ($i = 0; $i < $rowCount; $i++) {
                    if (trim((string) ($titles[$i] ?? '')) !== '' || trim((string) ($numbers[$i] ?? '')) !== '') {
                        $hasRevisionDoc = true;
                        break;
                    }
                }
                if (!$hasRevisionDoc || $fromDocNo === '') {
                    return back()->withInput()
                        ->with('error', 'Select the document being revised under Documents for Revision (DCN) before saving. That selection is required for revised registrations.');
                }
            }

            if ($lookupDocNo === '') {
                return back()->withInput()
                    ->with('error', 'You must enter a registered document number before revising it. Register it as a New Document first if it is not yet registered.');
            }

            if ($docNo === '') {
                return back()->withInput()
                    ->with('error', 'Document number is required for the revised registration.');
            }

            $result = self::findMatchingRegistrationRows($lookupDocNo, (int) $docTypeId, $subTypeId ? (int) $subTypeId : null);

            if (!$result['found']) {
                if (($result['reason'] ?? '') === 'not_registered') {
                    return back()->withInput()
                        ->with('error', 'Document "' . $lookupDocNo . '" is not registered. You must register it as a New Document first before revising it.');
                }

                return back()->withInput()
                    ->with('error', self::mismatchErrorMessageFromRow($lookupDocNo, $result));
            }

            $requestedRev = self::resolveReviseNo($request);
            // Family-wide: Rev 2 on an older renumbered doc_no still occupies Rev 2 for the tip.
            $visibleIds = RegisterQueryHelper::visibleRequestIds();
            $takenFamilyRevs = RegisterQueryHelper::familyReviseNumbers(
                $docNo,
                (int) $docTypeId,
                $subTypeId ? (int) $subTypeId : null,
                $visibleIds
            );
            if (in_array($requestedRev, $takenFamilyRevs, true)) {
                $suggested = $takenFamilyRevs !== [] ? (max($takenFamilyRevs) + 1) : ($requestedRev + 1);

                return back()->withInput()
                    ->with(
                        'error',
                        'Revision ' . $requestedRev . ' is taken. Use Rev ' . $suggested . '.'
                    );
            }

            // Exact (doc_no, revise_no) safety net when family walk is empty.
            $newFamily = self::findMatchingRegistrationRows($docNo, (int) $docTypeId, $subTypeId ? (int) $subTypeId : null);
            if ($newFamily['found'] && $takenFamilyRevs === []) {
                $matchingIds = $newFamily['matches']->pluck('id');
                $dupQuery = DB::table('dcs_masterlist_registration')
                    ->whereIn('request_id', $matchingIds)
                    ->where('doc_no', $docNo)
                    ->where('revise_no', $requestedRev);
                if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
                    $dupQuery->whereIn('revision_status', ['latest', 'obsolete']);
                }
                if ($dupQuery->exists()) {
                    return back()->withInput()
                        ->with('error', 'Revision ' . $requestedRev . ' for document "' . $docNo . '" already exists. Please use a different revision number.');
                }
            }
        }

        if ($redirect = self::rejectInactiveOfficeIds($request)) {
            return $redirect;
        }

        if ($redirect = self::rejectDuplicateDrfNo($request)) {
            return $redirect;
        }

        if ($mode === 'new') {
            $request->validate([
                'doc_type_id' => 'required|integer|exists:dcs_doc_types,id',
                'version_id' => 'required|integer|exists:dcs_version_type,id',
                'approval_status' => $saveAsDraft ? 'nullable|in:applicable,not_applicable' : 'required|in:applicable,not_applicable',
                'masterlistRevisionNo' => 'nullable|integer|min:0',
            ]);
        }

        $request->validate(array_merge([
            'doc_type_id' => 'required|integer|exists:dcs_doc_types,id',
            'version_id' => 'required|integer|exists:dcs_version_type,id',
            'approval_status' => $saveAsDraft ? 'nullable|in:applicable,not_applicable' : 'required|in:applicable,not_applicable',
        ], self::scanFileRules()));

        $subType = self::dcsDocType($request->sub_type_id);
        $isSyllabi = self::isSyllabiLikeSubTypeRow($subType);
        $allowsRevision = self::effectiveAllowsRevision($request->doc_type_id, $request->sub_type_id);
        if (! empty($officeDcnRevision)) {
            $allowsRevision = true;
            $mode = 'revised';
            $revisedVersionId = self::revisedVersionTypeId();
            $request->merge([
                'registration_mode' => 'revised',
                'version_id' => $revisedVersionId ?: $request->input('version_id'),
            ]);
        }
        if ($mode === 'recurring') {
            $allowsRevision = true;
        }

        if ($redirect = self::rejectDuplicateSyllabiContext($request)) {
            return $redirect;
        }
        if (! $saveAsDraft) {
            if ($redirect = self::validateSyllabiLikeRequestRows($request)) {
                return $redirect;
            }
        }
        if ($redirect = self::validateCheckedSections($request)) {
            return $redirect;
        }
        if ($redirect = self::validateMasterlistHasData($request, requireScan: false)) {
            return $redirect;
        }

        if ($saveAsDraft && RegisterQueryHelper::supportsDrafts()) {
            $draftDocNo = trim((string) $request->input('masterlistDocNo', ''));
            $draftSubTypeId = $request->input('sub_type_id') ? (int) $request->input('sub_type_id') : null;
            $draftContext = $isSyllabi ? self::syllabiDraftContext($request) : null;
            $existingDraftId = $draftDocNo !== ''
                ? RegisterQueryHelper::findExistingDraftRequestId(
                    $draftDocNo,
                    (int) $request->input('doc_type_id'),
                    $draftSubTypeId,
                    0,
                    $draftContext
                )
                : null;
            if ($existingDraftId) {
                RegisterUpdateHelper::collapseDuplicateDrafts($existingDraftId);
                $existingDraftId = RegisterQueryHelper::findExistingDraftRequestId(
                    $draftDocNo,
                    (int) $request->input('doc_type_id'),
                    $draftSubTypeId,
                    0,
                    $draftContext
                ) ?: $existingDraftId;

                return RegisterUpdateHelper::update($request, $existingDraftId);
            }
        }

        if ($mode === 'new' && $allowsRevision) {
            $docNo = $request->input('masterlistDocNo');
            $docTypeId = (int) $request->input('doc_type_id');
            $subTypeId = $request->input('sub_type_id');

            if ($docNo) {
                $result = self::findMatchingRegistrationRows($docNo, $docTypeId, $subTypeId ? (int) $subTypeId : null);

                if ($result['found']) {
                    // Ignore other drafts — only published (or non-draft) rows block a new registration.
                    $publishedMatches = $result['matches'];
                    if (RegisterQueryHelper::supportsDrafts()) {
                        $publishedMatches = $publishedMatches->filter(fn ($row) => empty($row->is_draft))->values();
                    }
                    if ($publishedMatches->isNotEmpty()
                        && ! $request->boolean('insert_shift_confirmed')
                        && ! $request->boolean('allow_duplicate_doc_no')) {
                        $latest = DB::table('dcs_masterlist_registration')
                            ->whereIn('request_id', $publishedMatches->pluck('id'))
                            ->where('doc_no', $docNo)
                            ->orderByDesc('revise_no')
                            ->first();

                        return back()->withInput()
                            ->with(
                                'error',
                                'Document "' . $docNo . '" is already registered (Rev ' . ($latest->revise_no ?? 0) . '). Please use Revised Registration to create a new revision.'
                            );
                    }
                }
            }
        }

        DB::beginTransaction();

        if ($mode === 'recurring' && ! $saveAsDraft) {
            self::supersedeLatestForRecur($request);
        }

        $uploadedFiles = [];
        $filesToDelete = [];

        try {
            $now = now();
            $userId = auth()->id();

            if ($mode === 'new' && ! $saveAsDraft && $request->boolean('insert_shift_confirmed')) {
                $shift = \App\Helpers\DocumentNumberSeriesHelper::applyInsertShift($request);
                if (empty($shift['ok'])) {
                    DB::rollBack();

                    return self::draftErrorResponse(
                        $request,
                        $shift['error'] ?? 'Could not insert this document number into the series.'
                    );
                }
            }

            $requestRow = [
                'version_id' => $request->version_id,
                'doc_type_id' => $request->doc_type_id,
                'sub_type_id' => $request->sub_type_id ?: null,
                'approval_status' => $request->input('approval_status', 'not_applicable') ?: 'not_applicable',
                'is_draft' => RegisterQueryHelper::supportsDrafts() ? $saveAsDraft : null,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('dcs_document_requests', 'appraisal_opt_in')) {
                $requestRow['appraisal_opt_in'] = ! $saveAsDraft && (string) $request->input('open_appraisal') === '1';
            }
            $requestId = DB::table('dcs_document_requests')->insertGetId(array_filter($requestRow, fn ($v) => $v !== null));

            $docTypeId = $request->doc_type_id;
            $checkedChecklists = self::withRequiredMasterlistChecklist(
                array_map('intval', $request->input('checklists', []))
            );

            if (in_array(1, $checkedChecklists, true)) {
                $drfFile = null;
                if ($request->hasFile('drfFile')) {
                    $drfFile = self::storeDcsScanUpload(
                        $request->file('drfFile'),
                        $uploadedFiles,
                        'drf',
                        self::buildScanBasename($request, 'DRF', $request->input('drfDate'))
                    );
                }

                $drfOfficeIds = array_values(array_filter($request->input('drfSourceUnit', [])));

                $drfId = DB::table('dcs_document_request_form')->insertGetId(array_merge([
                    'request_id' => $requestId,
                    'drf_no' => $request->drfNo,
                    'drf_date' => $request->drfDate,
                    'drf_receipt_date' => $request->drfReceiptDate,
                    'drf_receipt_time' => $request->drfTime,
                    'doc_title' => self::syncedDocTitle($request) ?: $request->drfTitle,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], self::dcsScanFields('dcs_document_request_form', 'scanned_drf', $drfFile)));

                $drfOfficeRows = [];
                foreach ($drfOfficeIds as $officeId) {
                    $id = (int) $officeId;
                    if ($id <= 0) {
                        continue;
                    }
                    $drfOfficeRows[] = [
                        'document_request_form_id' => $drfId,
                        'office_id' => $id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                self::insertRows('dcs_drf_offices', $drfOfficeRows);
            }

            if (in_array(2, $checkedChecklists, true) && $allowsRevision) {
                $dcnFile = null;
                if ($request->hasFile('dcnFile')) {
                    $dcnFile = self::storeDcsScanUpload(
                        $request->file('dcnFile'),
                        $uploadedFiles,
                        'dcn',
                        self::buildScanBasename($request, 'DCN', $request->input('noticeDate'))
                    );
                }

                $dcnOfficeIds = array_values(array_filter($request->input('dcnSourceUnit', [])));

                $dcnRow = array_merge([
                    'request_id' => $requestId,
                    'dcn_no' => $request->dcnNumber,
                    'dcn_date' => $request->noticeDate,
                    'dcn_receipt_date' => $request->receiptDate,
                    'dcn_receipt_time' => $request->receiptTime,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], self::dcsScanFields('dcs_document_change_notice', 'scanned_dcn', $dcnFile));
                if (Schema::hasColumn('dcs_document_change_notice', 'brief_purpose')) {
                    $dcnRow['brief_purpose'] = $request->dcnJustification;
                }
                $dcnId = DB::table('dcs_document_change_notice')->insertGetId($dcnRow);
                self::saveDcnOfficesById($dcnId, $dcnOfficeIds);

                if ($request->has('documentTitle') || $request->has('documentNo')) {
                    $titles = $request->documentTitle ?? [];
                    $numbers = $request->documentNo ?? [];
                    $totalRows = max(count($titles), count($numbers));
                    $revisionHasPurpose = Schema::hasColumn('dcs_doc_revision', 'brief_purpose');
                    $revRows = [];

                    for ($i = 0; $i < $totalRows; $i++) {
                        $title = $titles[$i] ?? null;
                        $docNo = $numbers[$i] ?? null;
                        if (empty($title) && empty($docNo)) {
                            continue;
                        }

                        $revPath = self::resolveRevisionScannedCopyPath($request, $i, $uploadedFiles);
                        $revRow = array_merge([
                            'dcn_id' => $dcnId,
                            'title' => $title,
                            'document_no' => $docNo,
                            'effectivity_date' => $request->effectiveDate[$i] ?? null,
                            'revision_no' => $request->revisionNo[$i] ?? null,
                            'created_at' => $now,
                        ], self::dcsScanFields('dcs_doc_revision', 'scanned_copy', $revPath));
                        if ($revisionHasPurpose) {
                            $revRow['brief_purpose'] = $request->revisionPurpose[$i] ?? null;
                        }
                        $revRows[] = $revRow;
                    }
                    self::insertRows('dcs_doc_revision', $revRows);
                }
            }

            if (in_array(3, $checkedChecklists, true) && !$isSyllabi) {
                $masterlistFile = null;
                if ($request->hasFile('uploadScannedCopy')) {
                    $masterlistFile = self::storeDcsScanUpload(
                        $request->file('uploadScannedCopy'),
                        $uploadedFiles,
                        'masterlist',
                        self::buildScanBasename($request, 'DOC', $request->input('masterlistEffectivityDate')),
                        self::dccContextFromRequest($request, 'masterlist', ! $saveAsDraft)
                    );
                    $uploadedFiles[] = $masterlistFile;
                }

                $masterlistTimeSpent = null;
                if ($request->filled('masterlistTimeSpent') && is_numeric($request->masterlistTimeSpent) && $request->masterlistTimeSpent >= 0) {
                    $masterlistTimeSpent = intval($request->masterlistTimeSpent);
                }

                $originator = self::resolveOriginator($request->masterlistOriginator);
                $masterlistRow = array_merge([
                    'request_id' => $requestId,
                    'doc_type_id' => $docTypeId,
                    'doc_no' => $request->masterlistDocNo,
                    'doc_receipt_date' => $request->masterlistReceiptDate,
                    'doc_receipt_time' => $request->masterlistReceiptTime,
                    'doc_registered_date' => $request->masterlistRegisteredDate,
                    'doc_registered_time' => $request->masterlistRegisteredTime,
                    'time_spent' => $masterlistTimeSpent,
                    'doc_title' => self::syncedDocTitle($request) ?: $request->masterlistDocTitle,
                    'effectivity_date' => $request->masterlistEffectivityDate,
                    'revise_no' => self::resolveReviseNo($request),
                    'no_pages' => $request->masterlistNoOfPages,
                    'deadline' => self::masterlistDeadlineValue($request),
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], self::dcsScanFields('dcs_masterlist_registration', 'scanned_masterlist', $masterlistFile));
                self::applyMasterlistOriginalName($masterlistRow, $request);
                if (RegisterQueryHelper::supportsRevisionStatus()) {
                    // DCS statuses are latest | obsolete only. Drafts use obsolete + is_draft
                    // so they stay out of the live unique index and inventory listings.
                    $masterlistRow['revision_status'] = $saveAsDraft ? 'obsolete' : 'latest';
                }
                self::applyAllowsRevisionToMasterlistRow(
                    $masterlistRow,
                    $allowsRevision && ! $request->boolean('allow_duplicate_doc_no')
                );
                if (Schema::hasColumn('dcs_masterlist_registration', 'originator_id')) {
                    $masterlistRow['originator_id'] = $originator['originator_id'];
                }
                if (Schema::hasColumn('dcs_masterlist_registration', 'originator_name')) {
                    $masterlistRow['originator_name'] = $originator['originator_name'];
                }
                if (Schema::hasColumn('dcs_masterlist_registration', 'originator_account_id')) {
                    $masterlistRow['originator_account_id'] = RegisterQueryHelper::resolveOriginatorAccountIdForName($originator['originator_name']);
                }
                $keywordVal = $request->keywords ?? $request->briefPurpose;
                if (Schema::hasColumn('dcs_masterlist_registration', 'keywords')) {
                    $masterlistRow['keywords'] = $keywordVal;
                }
                self::applyRelatedStackGroup($masterlistRow, $request);
                if ($mode === 'revised' && Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
                    $fromDocNo = self::resolveRevisedFromDocNo(
                        $request,
                        trim((string) $request->masterlistDocNo),
                        (int) $docTypeId,
                        $request->sub_type_id ? (int) $request->sub_type_id : null
                    );
                    if ($fromDocNo) {
                        $masterlistRow['revised_from_doc_no'] = $fromDocNo;
                    }
                }
                self::applyRecurRevisedFrom($masterlistRow, $request, $mode);
                $masterlistId = DB::table('dcs_masterlist_registration')->insertGetId($masterlistRow);

                self::saveOriginsFromOfficeIds($masterlistId, $request->input('masterlistOfficeIds', []));
                $relatedIds = array_filter(array_map('intval', $request->input('relatedDocumentIds', [])));
                self::saveRelatedDocumentIds($masterlistId, $relatedIds);
            }

            if (in_array(3, $checkedChecklists, true) && $isSyllabi) {
                $totalPages = 0;
                if ($request->has('syllabiNoPages')) {
                    $totalPages = array_sum(
                        array_filter($request->syllabiNoPages, fn ($p) => is_numeric($p) && $p > 0)
                    );
                }

                $masterlist = DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first();
                $masterlistFile = $masterlist ? $masterlist->scanned_masterlist : null;

                if ($request->hasFile('uploadScannedCopy')) {
                    if ($masterlistFile) {
                        $filesToDelete[] = $masterlistFile;
                    }
                    $masterlistFile = self::storeDcsScanUpload(
                        $request->file('uploadScannedCopy'),
                        $uploadedFiles,
                        'masterlist',
                        self::buildScanBasename($request, 'DOC', $request->input('masterlistEffectivityDate')),
                        self::dccContextFromRequest($request, 'masterlist', ! $saveAsDraft)
                    );
                    $uploadedFiles[] = $masterlistFile;
                }

                $masterlistTimeSpent = null;
                if ($request->filled('masterlistTimeSpent') && is_numeric($request->masterlistTimeSpent) && $request->masterlistTimeSpent >= 0) {
                    $masterlistTimeSpent = intval($request->masterlistTimeSpent);
                }

                $originator = self::resolveOriginator($request->masterlistOriginator);
                $masterlistData = [
                    'doc_type_id' => $docTypeId,
                    'doc_no' => $request->masterlistDocNo,
                    'doc_title' => $request->syllabiDocTitle ?: $request->masterlistDocTitle,
                    'doc_receipt_date' => $request->masterlistReceiptDate,
                    'doc_receipt_time' => $request->masterlistReceiptTime,
                    'doc_registered_date' => $request->masterlistRegisteredDate,
                    'doc_registered_time' => $request->masterlistRegisteredTime,
                    'time_spent' => $masterlistTimeSpent,
                    'effectivity_date' => $request->masterlistEffectivityDate,
                    'deadline' => self::masterlistDeadlineValue($request),
                    'revise_no' => self::resolveReviseNo($request),
                    'no_pages' => $totalPages,
                    'updated_at' => $now,
                ];
                $masterlistData = array_merge(
                    $masterlistData,
                    self::dcsScanFields('dcs_masterlist_registration', 'scanned_masterlist', $masterlistFile)
                );
                self::applyMasterlistOriginalName($masterlistData, $request);
                if (RegisterQueryHelper::supportsRevisionStatus()) {
                    $masterlistData['revision_status'] = $saveAsDraft ? 'obsolete' : 'latest';
                }
                self::applyAllowsRevisionToMasterlistRow(
                    $masterlistData,
                    $allowsRevision && ! $request->boolean('allow_duplicate_doc_no')
                );
                if (Schema::hasColumn('dcs_masterlist_registration', 'originator_id')) {
                    $masterlistData['originator_id'] = $originator['originator_id'];
                }
                if (Schema::hasColumn('dcs_masterlist_registration', 'originator_name')) {
                    $masterlistData['originator_name'] = $originator['originator_name'];
                }
                if (Schema::hasColumn('dcs_masterlist_registration', 'originator_account_id')) {
                    $masterlistData['originator_account_id'] = RegisterQueryHelper::resolveOriginatorAccountIdForName($originator['originator_name']);
                }
                $keywordVal = $request->keywords ?? $request->briefPurpose;
                if (Schema::hasColumn('dcs_masterlist_registration', 'keywords')) {
                    $masterlistData['keywords'] = $keywordVal;
                }
                self::applyRelatedStackGroup($masterlistData, $request);
                if ($mode === 'revised' && Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
                    $fromDocNo = self::resolveRevisedFromDocNo(
                        $request,
                        trim((string) $request->masterlistDocNo),
                        (int) $docTypeId,
                        $request->sub_type_id ? (int) $request->sub_type_id : null
                    );
                    if ($fromDocNo) {
                        $masterlistData['revised_from_doc_no'] = $fromDocNo;
                    }
                }
                self::applyRecurRevisedFrom($masterlistData, $request, $mode);

                if ($masterlist) {
                    DB::table('dcs_masterlist_registration')->where('id', $masterlist->id)->update($masterlistData);
                    $masterlistId = $masterlist->id;
                } else {
                    $masterlistId = DB::table('dcs_masterlist_registration')->insertGetId(array_merge($masterlistData, [
                        'request_id' => $requestId,
                        'created_by' => $userId,
                        'created_at' => $now,
                    ]));
                }

                DB::table('dcs_masterlist_source_offices')->where('masterlist_id', $masterlistId)->delete();
                self::saveOriginsFromOfficeIds($masterlistId, $request->input('masterlistOfficeIds', []));

                $relatedIds = array_filter(array_map('intval', $request->input('relatedDocumentIds', [])));
                self::saveRelatedDocumentIds($masterlistId, $relatedIds);

                self::saveSyllabiRowsFromRequest($requestId, $request, $uploadedFiles);
            }

            if (in_array(4, $checkedChecklists, true)) {
                $retrievalId = DB::table('dcs_document_retrieval')->insertGetId([
                    'request_id' => $requestId,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($request->has('retrievalOffice')) {
                    $validRetrievalOffices = self::existingOfficeIdSet((array) $request->retrievalOffice);
                    $retrievalHasStatus = Schema::hasColumn('dcs_retrieval_offices', 'retrieval_status');
                    $retrievalHasDate = Schema::hasColumn('dcs_retrieval_offices', 'retrieval_date');
                    $retrievalHasTime = Schema::hasColumn('dcs_retrieval_offices', 'retrieval_time');
                    $retrievalRows = [];
                    foreach ($request->retrievalOffice as $i => $officeId) {
                        $id = (int) $officeId;
                        if ($id <= 0 || ! isset($validRetrievalOffices[$id])) {
                            continue;
                        }
                        $retrievalOfficeRow = [
                            'retrieval_id' => $retrievalId,
                            'office_id' => $id,
                            'copies' => $request->retrievalCopies[$i] ?? 1,
                            'retrieval_status' => $retrievalHasStatus
                                ? ($request->input('retrievalStatus')[$i] ?? 'pending')
                                : null,
                        ];
                        if ($retrievalHasDate) {
                            $retrievalOfficeRow['retrieval_date'] = $request->input('retrievalOfficeDate')[$i] ?? null;
                        }
                        if ($retrievalHasTime) {
                            $retrievalOfficeRow['retrieval_time'] = $request->input('retrievalOfficeTime')[$i] ?? null;
                        }
                        $retrievalRows[] = $retrievalOfficeRow;
                    }
                    self::insertRows('dcs_retrieval_offices', $retrievalRows);
                }
            }

            if (in_array(5, $checkedChecklists, true)) {
                $distFile = null;
                if ($request->hasFile('scanneddist')) {
                    $distFile = self::storeDcsScanUpload(
                        $request->file('scanneddist'),
                        $uploadedFiles,
                        'distribution',
                        self::buildScanBasename($request, 'D&R', $request->input('distributionFormDate'))
                    );
                    $uploadedFiles[] = $distFile;
                }

                $distTimeSpent = null;
                if ($request->filled('distributionTimeSpent') && is_numeric($request->distributionTimeSpent) && $request->distributionTimeSpent >= 0) {
                    $distTimeSpent = intval($request->distributionTimeSpent);
                }

                $distRow = array_merge([
                    'request_id' => $requestId,
                    'doc_distribution_date_actual' => $request->distributionDate,
                    'doc_distribution_time_actual' => $request->distributionTime,
                    'doc_distribution_date_file' => $request->distributionFormDate,
                    'doc_distribution_time_file' => $request->distributionFormTime,
                    'time_spent' => $distTimeSpent,
                    'remarks' => $request->distributionRemarks,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], self::dcsScanFields('dcs_document_distribution', 'scanned_distribution', $distFile));
                $distributionId = DB::table('dcs_document_distribution')->insertGetId($distRow);

                if ($request->has('distOffice')) {
                    self::saveDistributionOffices($distributionId, $request);
                }
            }

            if ($request->approval_status === 'applicable' && $request->filled('approvalBody')) {
                DB::table('dcs_approval_records')->insert([
                    'request_id' => $requestId,
                    'approval_body_id' => $request->approvalBody,
                    'approval_date' => $request->approvalDate,
                    'approval_no' => $request->approvalNo,
                ]);
            }

            $savedMl = DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first();
            if ($savedMl && ! $saveAsDraft && $mode === 'recurring') {
                // Recur already superseded the selected latest. Do not let the
                // family tip rewrite turn that row back into Latest.
                self::supersedeLatestForRecur($request, (int) $savedMl->id);
                if (RegisterQueryHelper::supportsRevisionStatus()) {
                    DB::table('dcs_masterlist_registration')
                        ->where('id', (int) $savedMl->id)
                        ->update(['revision_status' => 'latest', 'updated_at' => now()]);
                }
            } elseif ($savedMl && ! $saveAsDraft) {
                $rowAllowsRevision = RegisterQueryHelper::supportsAllowsRevisionColumn()
                    ? (bool) ($savedMl->allows_revision ?? true)
                    : $allowsRevision;

                if ($rowAllowsRevision) {
                    self::syncRevisionStatusForMasterlist((int) $savedMl->id);

                    // Re-assert tip = max revise_no across the whole renumber family.
                    self::promoteLatestForDoc(
                        trim((string) $savedMl->doc_no),
                        (int) $docTypeId,
                        $request->sub_type_id ? (int) $request->sub_type_id : null
                    );
                } elseif (RegisterQueryHelper::supportsRevisionStatus()) {
                    DB::table('dcs_masterlist_registration')
                        ->where('id', $savedMl->id)
                        ->update(['revision_status' => 'latest', 'revise_no' => 0, 'updated_at' => now()]);
                }

                if ($mode === 'revised') {
                    self::obsoleteSelectedRevisionSources(
                        $request,
                        (int) $requestId,
                        trim((string) ($savedMl->doc_no ?? ''))
                    );
                }
            }

            DB::commit();
            RegisterQueryHelper::bumpDocumentListCache();

            foreach ($filesToDelete as $file) {
                if ($file) {
                    DocumentStorageService::deleteDcsScan($file);
                }
            }

            try {
                self::syncSavedScanNamesAfterCommit($request, (int) $requestId);
            } catch (\Throwable $e) {
                \Log::warning('Scan rename after register skipped: '.$e->getMessage());
            }

            RegisterPersistHelper::logAdminChange(
                ($saveAsDraft ? 'Saved draft #' : 'Registered document #') . $requestId
                . (!empty($savedMl?->doc_no) ? ' — ' . $savedMl->doc_no : '')
                . (isset($savedMl?->revise_no) ? ' (Rev ' . $savedMl->revise_no . ')' : '')
                . (!empty($savedMl?->doc_title) ? ': ' . $savedMl->doc_title : '')
            );

            \App\Services\DcsAuditService::log(
                $saveAsDraft ? 'register.draft' : 'register.create',
                'register',
                (int) $requestId,
                null,
                [
                    'doc_no' => $savedMl->doc_no ?? null,
                    'revise_no' => $savedMl->revise_no ?? null,
                    'is_draft' => $saveAsDraft,
                ]
            );

            $docNo = trim((string) ($savedMl->doc_no ?? ''));
            $docTitle = trim((string) ($savedMl->doc_title ?? ''));
            $revisedFrom = '';
            if ($savedMl && Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
                $revisedFrom = trim((string) ($savedMl->revised_from_doc_no ?? ''));
            }
            $pending = OfficeIntakeHelper::pendingRegisterIntake($request);

            // Notify the office that created this My DRF / My DCN form when this
            // save is that form (Proceed to Registration). A document the admin
            // creates with only a DRF or DCN source unit has no pending intake,
            // so those offices are not notified. A draft does not mark the forms.
            $submitterOfficeCodes = [];
            $holdForAppraisal = (string) $request->input('open_appraisal') === '1';            if (! $saveAsDraft && $pending && in_array($pending['type'], ['drf', 'dcn'], true) && $pending['id'] > 0) {
                // When Save and Appraise is used, defer "Your DRF/DCN was registered"
                // until appraisal completes for the creating office.
                $submitterOfficeCodes = OfficeIntakeHelper::closePendingIntakeForRegistration(
                    $pending['type'],
                    (int) $pending['id'],
                    (int) $requestId,
                    $docNo,
                    $docTitle !== '' ? $docTitle : null,
                    $revisedFrom !== '' ? $revisedFrom : null,
                    $holdForAppraisal
                );
            }

            if (! $saveAsDraft) {
                OfficeIntakeHelper::reconcileClaimedIntakesWithRegistrations();
            }

            if (! $saveAsDraft && $docNo !== '' && ! $holdForAppraisal) {
                $registrarName = RegisterQueryHelper::currentUserDisplayName();
                $revNo = isset($savedMl->revise_no) ? (int) $savedMl->revise_no : null;
                $actorOffice = RegisterQueryHelper::currentOfficeCode();
                $skipCodes = collect($submitterOfficeCodes)
                    ->map(fn ($c) => strtoupper(trim((string) $c)))
                    ->filter()
                    ->unique()
                    ->all();
                if ($actorOffice) {
                    $skipCodes[] = strtoupper(trim((string) $actorOffice));
                    $skipCodes = array_values(array_unique($skipCodes));
                }

                $distIds = array_values(array_unique(array_filter(array_map(
                    'intval',
                    (array) $request->input('distOffice', [])
                ))));
                $masterlistIds = array_values(array_unique(array_filter(array_map(
                    'intval',
                    (array) $request->input('masterlistOfficeIds', [])
                ))));
                $masterlistOnlyIds = array_values(array_diff($masterlistIds, $distIds));

                $notifyDocNo = $docNo;
                $notifyTitle = $docTitle !== '' ? $docTitle : null;
                $notifyRequestId = (int) $requestId;
                app()->terminating(function () use ($distIds, $masterlistOnlyIds, $skipCodes, $registrarName, $notifyDocNo, $notifyTitle, $notifyRequestId, $revNo) {
                    foreach (DcsNotificationService::officeCodesFromIds($distIds) as $officeCode) {
                        if (in_array(strtoupper($officeCode), $skipCodes, true)) {
                            continue;
                        }
                        DcsNotificationService::notifyDocumentDistributed(
                            $officeCode,
                            $notifyDocNo,
                            $notifyTitle,
                            $revNo
                        );
                    }

                    foreach (DcsNotificationService::officeCodesFromIds($masterlistOnlyIds) as $officeCode) {
                        if (in_array(strtoupper($officeCode), $skipCodes, true)) {
                            continue;
                        }
                        DcsNotificationService::notifyDocumentRegistered(
                            $officeCode,
                            $registrarName,
                            $notifyDocNo,
                            $notifyRequestId,
                            $revNo
                        );
                    }
                });
            }

            $successMessage = $saveAsDraft
                ? 'Draft saved. Continue anytime from Document Registration → Drafts.'
                : 'Document registered successfully!';

            if ($saveAsDraft && self::isAutosaveRequest($request)) {
                return self::draftAutosaveSuccessResponse((int) $requestId, $successMessage);
            }

            if ($saveAsDraft) {
                $leaveTo = self::safeDraftLeaveRedirect($request->input('draft_leave_to'));
                if ($leaveTo) {
                    return redirect()->to($leaveTo)->with('success', $successMessage);
                }
            }

            $appraisalRedirect = \App\Services\RdpIntakeService::redirectToAdminAppraisal($request, (int) $requestId, $successMessage);
            if (! $saveAsDraft && $appraisalRedirect) {
                return $appraisalRedirect;
            }

            return redirect()->route('dcs.register.edit', $requestId)
                ->with('success', $successMessage);
        } catch (\Throwable $e) {
            DB::rollBack();

            foreach ($uploadedFiles as $file) {
                DocumentStorageService::deleteDcsScan($file);
            }

            $refId = uniqid('err_');
            Log::error("Document registration failed [{$refId}]: " . $e->getMessage());

            return self::draftErrorResponse(
                $request,
                'Failed to save document. Please try again. (ref: ' . $refId . ')',
                500
            );
        }
    }

    public static function dcsDocType(mixed $id): ?object
    {
        if (!$id) {
            return null;
        }

        return DB::table('dcs_doc_types')->where('id', (int) $id)->first();
    }

    public static function isSyllabiLikeSubTypeRow(?object $subType): bool
    {
        if (!$subType) {
            return false;
        }

        return RegisterQueryHelper::isSyllabiLikeName($subType->doc_type_name ?? null);
    }

    public static function normalizeSyllabiYearLevel(mixed $year): ?string
    {
        $year = trim((string) $year);
        if ($year === '' || ! in_array($year, SyllabiMonitoringHelper::YEAR_LEVELS, true)) {
            return null;
        }

        return $year;
    }

    /** True when the effective type/subtype does not allow Revised / DCN. */
    public static function typeBlocksRevision(?object $typeRow): bool
    {
        return ! RegisterQueryHelper::typeAllowsRevision($typeRow);
    }

    public static function effectiveAllowsRevision(mixed $docTypeId, mixed $subTypeId = null): bool
    {
        return RegisterQueryHelper::effectiveTypeAllowsRevision($docTypeId, $subTypeId);
    }

    /**
     * Office DCN registration (including a DRF created from that DCN) revises
     * the document the office already selected.
     */
    public static function pendingIntakeIsOfficeDcnRevision(\Illuminate\Http\Request $request): bool
    {
        if (! class_exists(\App\Helpers\OfficeIntakeHelper::class)) {
            return false;
        }

        $pending = \App\Helpers\OfficeIntakeHelper::pendingRegisterIntake($request);
        if (! $pending) {
            return false;
        }
        if (($pending['type'] ?? '') === 'dcn') {
            return true;
        }
        if (($pending['type'] ?? '') === 'drf') {
            return \App\Helpers\OfficeIntakeHelper::findSourceDcnIdForOfficeDrf((int) ($pending['id'] ?? 0)) !== null;
        }

        return false;
    }

    public static function versionIdIsRevised(int $versionId): bool
    {
        if ($versionId < 1) {
            return false;
        }
        $name = strtolower((string) DB::table('dcs_version_type')->where('id', $versionId)->value('version_name'));

        return str_contains($name, 'revis');
    }

    public static function versionIdIsRecur(int $versionId): bool
    {
        if ($versionId < 1) {
            return false;
        }
        $name = strtolower((string) DB::table('dcs_version_type')->where('id', $versionId)->value('version_name'));

        return str_contains($name, 'recur');
    }

    public static function revisedVersionTypeId(): ?int
    {
        static $id = null;
        if ($id !== null) {
            return $id;
        }
        $row = DB::table('dcs_version_type')
            ->whereRaw("LOWER(version_name) LIKE '%revis%'")
            ->orderBy('id')
            ->first();
        $id = $row ? (int) $row->id : 0;

        return $id > 0 ? $id : null;
    }

    public static function newVersionTypeId(): ?int
    {
        static $id = null;
        if ($id !== null) {
            return $id;
        }
        $row = DB::table('dcs_version_type')
            ->whereRaw("LOWER(version_name) LIKE '%new%'")
            ->whereRaw("LOWER(version_name) NOT LIKE '%revis%'")
            ->orderBy('id')
            ->first();
        $id = $row ? (int) $row->id : (int) (DB::table('dcs_version_type')->orderBy('id')->value('id') ?: 0);

        return $id > 0 ? $id : null;
    }

    public static function applyAllowsRevisionToMasterlistRow(array &$row, bool $allowsRevision): void
    {
        if (RegisterQueryHelper::supportsAllowsRevisionColumn()) {
            $row['allows_revision'] = $allowsRevision;
        }
    }

    public static function findMatchingRegistrationRows(string $docNo, int $docTypeId, ?int $subTypeId, bool $anySubType = false): array
    {
        $allMl = DB::table('dcs_masterlist_registration')->where('doc_no', $docNo)->get();

        if ($allMl->isEmpty()) {
            return ['found' => false, 'reason' => 'not_registered'];
        }

        $requestIds = $allMl->pluck('request_id')->unique()->filter();
        $relatedDocRequests = DB::table('dcs_document_requests')->whereIn('id', $requestIds)->get();
        if (\Illuminate\Support\Facades\Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            $relatedDocRequests = $relatedDocRequests->filter(fn ($dr) => $dr->deleted_at === null)->values();
        }

        if ($relatedDocRequests->isEmpty()) {
            return ['found' => false, 'reason' => 'not_registered'];
        }

        $hasSubType = $subTypeId && (int) $subTypeId > 0;

        $matching = $relatedDocRequests->filter(function ($dr) use ($docTypeId, $subTypeId, $hasSubType, $anySubType) {
            if ((int) $dr->doc_type_id !== (int) $docTypeId) {
                return false;
            }
            if ($anySubType) {
                return true;
            }
            if ($hasSubType) {
                return $dr->sub_type_id && (int) $dr->sub_type_id === (int) $subTypeId;
            }

            return $dr->sub_type_id === null || $dr->sub_type_id === '';
        });

        if ($matching->isNotEmpty()) {
            $matchingIds = $matching->pluck('id');
            $latestQuery = DB::table('dcs_masterlist_registration')
                ->whereIn('request_id', $matchingIds)
                ->where('doc_no', $docNo);
            if (RegisterQueryHelper::supportsRevisionStatus()) {
                $latestQuery->where('revision_status', 'latest');
            }
            $latest = $latestQuery->orderByDesc('id')->first();
            if (!$latest) {
                $latest = DB::table('dcs_masterlist_registration')
                    ->whereIn('request_id', $matchingIds)
                    ->where('doc_no', $docNo)
                    ->orderByDesc('revise_no')
                    ->orderByDesc('id')
                    ->first();
            }

            return [
                'found' => true,
                'latest' => $latest,
                'all' => $allMl,
                'matches' => $matching,
            ];
        }

        $existingDr = $relatedDocRequests->first();

        if (!$existingDr) {
            return ['found' => false, 'reason' => 'not_registered'];
        }

        if ($hasSubType && (int) $existingDr->doc_type_id === (int) $docTypeId) {
            return [
                'found' => false,
                'reason' => 'wrong_subtype',
                'existing_dr' => $existingDr,
            ];
        }

        return [
            'found' => false,
            'reason' => 'wrong_type',
            'existing_dr' => $existingDr,
        ];
    }

    public static function mismatchErrorMessageFromRow(string $docNo, array $result): string
    {
        $existingDr = $result['existing_dr'];

        if ($result['reason'] === 'wrong_subtype') {
            $existingSubType = self::dcsDocType($existingDr->sub_type_id);

            return 'Document "' . $docNo . '" is registered under "'
                . ($existingSubType ? $existingSubType->doc_type_name : 'Unknown sub-type')
                . '", not the selected sub-type.';
        }

        $type = self::dcsDocType($existingDr->doc_type_id);

        return 'Document "' . $docNo . '" is registered under "'
            . ($type ? $type->doc_type_name : 'Unknown')
            . '", not the selected Document Type.';
    }

    /**
     * Block a second Syllabi/TOS pack for the same college + program + semester
     * + school year + course type, even when course rows were cleared.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse|null
     */
    public static function rejectDuplicateSyllabiContext(Request $request, ?int $exceptRequestId = null)
    {
        $subType = self::dcsDocType($request->sub_type_id);
        if (! self::isSyllabiLikeSubTypeRow($subType)) {
            return null;
        }

        $collegeId = (int) $request->input('college_id', 0);
        $programId = (int) $request->input('program_id', 0);
        $semesterId = (int) $request->input('semester_id', 0);
        $schoolYearId = (int) $request->input('school_year_id', 0);
        $courseType = trim((string) $request->input('course_type', ''));
        $subTypeId = $request->input('sub_type_id') ? (int) $request->input('sub_type_id') : null;

        if ($collegeId < 1 || $programId < 1 || $semesterId < 1 || $schoolYearId < 1 || $courseType === '') {
            return null;
        }

        $duplicate = self::findSyllabiContextDuplicate(
            $collegeId,
            $programId,
            $semesterId,
            $schoolYearId,
            $courseType,
            $subTypeId,
            $exceptRequestId
        );
        if (! $duplicate) {
            return null;
        }

        $label = RegisterQueryHelper::isSyllabiLikeName($subType->doc_type_name ?? null)
            ? trim((string) ($subType->doc_type_name ?? 'Syllabi'))
            : 'Syllabi';
        $sy = DB::table('dcs_school_years')->where('id', $schoolYearId)->value('school_year');
        $sem = DB::table('dcs_semesters')->where('id', $semesterId)->value('semester_name');

        return self::draftErrorResponse(
            $request,
            "{$label} for {$courseType}, {$sem}, S/Y {$sy} is already registered. "
            . 'Only one registration is allowed per semester and school year for this course type.'
        );
    }

    public static function validateSyllabiLikeRequestRows(Request $request, ?int $exceptRequestId = null): ?RedirectResponse
    {
        $subType = self::dcsDocType($request->sub_type_id);
        $isSyllabi = self::isSyllabiLikeSubTypeRow($subType);

        if (! $isSyllabi) {
            return null;
        }

        $courseNames = collect((array) $request->input('syllabiCourseName', []))
            ->map(fn ($name) => trim((string) $name))
            ->filter();
        $missingContext = [];
        foreach ([
            'college_id' => 'College',
            'program_id' => 'Program',
            'semester_id' => 'Semester',
            'course_type' => 'Course type',
            'school_year_id' => 'School year',
        ] as $field => $label) {
            if (trim((string) $request->input($field, '')) === '') {
                $missingContext[] = $label;
            }
        }
        if ($missingContext !== []) {
            return back()->withInput()->with(
                'error',
                'Select ' . implode(', ', $missingContext) . ' before registering this document.'
            );
        }

        if (! self::settingsHasSyllabiCourses($request)) {
            return back()->withInput()->with(
                'error',
                'There are no course names in Settings for this program, semester, and course type. Add them in Settings before registering.'
            );
        }

        if ($courseNames->isEmpty()) {
            return back()->withInput()->with(
                'error',
                'Add at least one course from Settings before registering this document.'
            );
        }

        if ($redirect = self::rejectDuplicateSyllabiContext($request, $exceptRequestId)) {
            return $redirect instanceof \Illuminate\Http\RedirectResponse ? $redirect : back()->withInput()->with(
                'error',
                $redirect->getData(true)['message'] ?? 'This semester and school year are already registered.'
            );
        }

        if (! $request->has('syllabiCourseName')) {
            return null;
        }

        $courseNames = $request->syllabiCourseName;
        $copiesArr = $request->syllabiCopies ?? [];
        $yearArr = $request->syllabiYearLevel ?? [];
        $total = count($courseNames);
        $i = 0;

        while ($i < $total) {
            $courseName = $courseNames[$i];
            $copies = max(1, (int) ($copiesArr[$i] ?? 1));
            $courseLabel = $courseName ?: ('Course group starting row ' . ($i + 1));

            if (empty($courseName)) {
                $i += $copies;
                continue;
            }

            $rowYear = self::normalizeSyllabiYearLevel($yearArr[$i] ?? null);
            if ($rowYear === null && Schema::hasColumn('dcs_program_courses', 'year_level')) {
                return back()->withInput()->with('error',
                    "{$courseLabel}: Year level is required.");
            }

            if (! self::settingsCourseForSyllabiRow($request, trim((string) $courseName), $rowYear)) {
                return back()->withInput()->with(
                    'error',
                    "\"{$courseLabel}\" is not a course name in Settings for this program, semester, and course type."
                );
            }

            $usedFaculty = [];
            for ($c = 0; $c < $copies; $c++) {
                $rowIdx = $i + $c;
                if ($rowIdx >= $total) {
                    break;
                }
                $copyNum = $c + 1;
                $rowLabel = "Syllabi \"{$courseLabel}\" (Copy {$copyNum})";

                if ($copies > 1) {
                    $rowFaculties = self::parseSyllabiFacultyNames($request->syllabiFaculty[$rowIdx] ?? '');
                    if (count($rowFaculties) > 1) {
                        return back()->withInput()->with('error',
                            "{$rowLabel}: Only one faculty per row is allowed when copies are split across rows.");
                    }
                    $rowName = mb_strtolower($rowFaculties[0] ?? '');
                    if ($rowName !== '') {
                        if (isset($usedFaculty[$rowName])) {
                            return back()->withInput()->with('error',
                                "{$rowLabel}: The same faculty cannot be used on more than one copy of this syllabi.");
                        }
                        $usedFaculty[$rowName] = true;
                    }
                }

                if ($request->hasFile('syllabiScannedDrf') && isset($request->file('syllabiScannedDrf')[$rowIdx])) {
                    $file = $request->file('syllabiScannedDrf')[$rowIdx];
                    $ext = strtolower($file->getClientOriginalExtension());
                    if ($ext !== 'pdf') {
                        return back()->withInput()->with('error', "{$rowLabel}: Scanned DRF — only scanned PDF files are accepted.");
                    }
                    if ($file->getSize() > self::SCAN_MAX_KB * 1024) {
                        return back()->withInput()->with('error', "{$rowLabel}: Scanned DRF — file size must not exceed 200MB.");
                    }
                }
            }

            $i += $copies;
        }

        $request->validate([
            'college_id' => 'required|integer|exists:dcs_colleges,id',
            'program_id' => 'required|integer|exists:dcs_programs,id',
            'semester_id' => 'required|integer|exists:dcs_semesters,id',
            'school_year_id' => 'required|integer|exists:dcs_school_years,id',
            'course_type' => 'required|string|max:50|in:GE Courses,PE Courses,NSTP,Major',
            'year_levels' => 'nullable|array',
            'year_levels.*' => 'nullable|string|max:50|in:1st Year,2nd Year,3rd Year,4th Year,5th Year',
            'syllabiYearLevel' => 'nullable|array',
            'syllabiYearLevel.*' => 'nullable|string|max:50|in:1st Year,2nd Year,3rd Year,4th Year,5th Year',
            'syllabiDocNo' => 'nullable|string',
            'syllabiDocTitle' => 'nullable|string',
            'syllabiEffectivityDate' => 'nullable|date',
            'syllabiDeadline' => 'nullable|date',
        ]);

        $duplicate = self::findSyllabiContextDuplicate(
            (int) $request->input('college_id'),
            (int) $request->input('program_id'),
            (int) $request->input('semester_id'),
            (int) $request->input('school_year_id'),
            trim((string) $request->input('course_type')),
            $request->input('sub_type_id') ? (int) $request->input('sub_type_id') : null,
            $exceptRequestId
        );

        if ($duplicate) {
            $label = RegisterQueryHelper::isSyllabiLikeName($subType->doc_type_name ?? null)
                ? trim((string) ($subType->doc_type_name ?? 'Syllabi'))
                : 'Syllabi';
            $sy = DB::table('dcs_school_years')->where('id', (int) $request->input('school_year_id'))->value('school_year');
            $sem = DB::table('dcs_semesters')->where('id', (int) $request->input('semester_id'))->value('semester_name');
            $courseType = trim((string) $request->input('course_type'));

            return back()->withInput()->with(
                'error',
                "{$label} for {$courseType}, {$sem}, S/Y {$sy} is already registered. "
                . 'Only one registration is allowed per semester and school year for this course type.'
            );
        }

        return null;
    }

    /**
     * Syllabi / TOS-like registrations are once per college + program + semester
     * + school year + course type (+ document sub-type). Year levels live on
     * the course rows inside that one pack.
     */
    public static function findSyllabiContextDuplicate(
        int $collegeId,
        int $programId,
        int $semesterId,
        int $schoolYearId,
        string $courseType,
        ?int $subTypeId = null,
        ?int $exceptRequestId = null
    ): ?object {
        if ($collegeId < 1 || $programId < 1 || $semesterId < 1 || $schoolYearId < 1) {
            return null;
        }
        if ($courseType === '') {
            return null;
        }
        if (! Schema::hasTable('dcs_syllabi')) {
            return null;
        }

        $query = DB::table('dcs_syllabi as s')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 's.request_id')
            ->where('s.college_id', $collegeId)
            ->where('s.program_id', $programId)
            ->where('s.semester_id', $semesterId)
            ->where('s.school_year_id', $schoolYearId);

        if ($subTypeId) {
            $query->where('dr.sub_type_id', $subTypeId);
        }

        if ($exceptRequestId) {
            $query->where('s.request_id', '!=', $exceptRequestId);
        }

        if (Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            $query->whereNull('dr.deleted_at');
        }

        // Drafts are not registrations. Only a saved document blocks the same context.
        if (Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            $query->where(function ($q) {
                $q->where('dr.is_draft', false)->orWhereNull('dr.is_draft');
            });
        }

        if (Schema::hasColumn('dcs_program_courses', 'course_type')) {
            $query->join('dcs_program_courses as pc', 'pc.id', '=', 's.course_id')
                ->where('pc.course_type', $courseType);
        }

        return $query
            ->orderByDesc('s.request_id')
            ->select(['s.request_id', 's.college_id', 's.program_id', 's.semester_id', 's.school_year_id'])
            ->first();
    }

    /**
     * Prior masterlist scanned copy for a DCN "Documents for Revision" row
     * (matched by document no. + revise_no). Used when dcs_doc_revision.scanned_copy
     * was never stored — the Database tip/obsolete rows still have scanned_masterlist.
     */
    public static function masterlistScanPathForRevision(
        string $docNo,
        mixed $reviseNo,
        ?int $docTypeId = null,
        ?int $subTypeId = null
    ): ?string {
        $docNo = trim($docNo);
        if ($docNo === '' || $reviseNo === null || $reviseNo === '') {
            return null;
        }
        if (! Schema::hasTable('dcs_masterlist_registration')) {
            return null;
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->where('ml.doc_no', $docNo)
            ->where('ml.revise_no', (int) $reviseNo)
            ->whereNotNull('ml.scanned_masterlist')
            ->where('ml.scanned_masterlist', '!=', '');

        if (Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            $query->whereNull('dr.deleted_at');
        }
        if (Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            $query->where(function ($w) {
                $w->where('dr.is_draft', false)->orWhereNull('dr.is_draft');
            });
        }
        if ($docTypeId !== null && $docTypeId > 0) {
            $query->where('dr.doc_type_id', $docTypeId);
        }
        if ($subTypeId !== null && $subTypeId > 0) {
            $query->where('dr.sub_type_id', $subTypeId);
        } elseif ($subTypeId === null && $docTypeId !== null && $docTypeId > 0) {
            $query->whereNull('dr.sub_type_id');
        }

        $path = $query->orderByDesc('ml.id')->value('ml.scanned_masterlist');
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = ltrim(str_replace(['../', '..\\'], '', $path), '/');
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return DocumentStorageService::dcsScanExists($path) ? $path : null;
    }

    public static function resolveRevisionScannedCopyPath(Request $request, int $i, array &$uploadedFiles, array $allowedPaths = []): ?string
    {
        if ($request->hasFile('scannedCopy') && isset($request->file('scannedCopy')[$i])) {
            $rowTitle = trim((string) ($request->input('documentTitle')[$i] ?? ''));
            $base = self::buildScanBasename($request, 'DCN', $request->input('noticeDate'));
            if ($rowTitle !== '') {
                // Prefer per-row title in the basename when present.
                $datePart = self::formatScanDatePart($request->input('noticeDate'));
                $typeCode = self::parentDocTypeCode($request->input('doc_type_id'));
                $rev = self::resolveReviseNo($request);
                $base = "{$datePart}_DCN_{$typeCode}_" . self::titleToScanSegment($rowTitle) . "_Rev{$rev}";
            }
            $path = self::storeDcsScanUpload($request->file('scannedCopy')[$i], $uploadedFiles, 'revisions', $base);

            return $path;
        }

        $docNo = trim((string) ($request->input('documentNo')[$i] ?? ''));
        $rowRev = $request->input('revisionNo')[$i] ?? null;
        $docTypeId = (int) ($request->input('doc_type_id') ?? 0);
        $subTypeRaw = $request->input('sub_type_id');
        $subTypeId = ($subTypeRaw !== null && $subTypeRaw !== '') ? (int) $subTypeRaw : null;
        $masterlistPath = self::masterlistScanPathForRevision(
            $docNo,
            $rowRev,
            $docTypeId > 0 ? $docTypeId : null,
            $subTypeId
        );
        if ($masterlistPath) {
            $allowedPaths[] = $masterlistPath;
        }

        $source = $request->input('revisionScannedPath')[$i] ?? null;
        if (! is_string($source) || trim($source) === '') {
            $source = $masterlistPath;
        }
        if (! is_string($source) || trim($source) === '') {
            return null;
        }

        $source = ltrim(str_replace(['../', '..\\'], '', $source), '/');
        if (! self::isKnownPublicScanPath($source, $allowedPaths)) {
            return null;
        }

        // The revision row shows the document already on file. Keep that path.
        return $source;
    }

    public static function saveDcnOfficesById(int $dcnId, array $officeIds): void
    {
        DB::table('dcs_dcn_offices')->where('dcn_id', $dcnId)->delete();
        $now = now();
        $seen = [];
        $rows = [];
        $validOffices = self::existingOfficeIdSet($officeIds);
        foreach ($officeIds as $officeId) {
            $id = (int) trim((string) $officeId);
            if ($id <= 0 || isset($seen[$id]) || ! isset($validOffices[$id])) {
                continue;
            }
            $seen[$id] = true;
            $rows[] = [
                'dcn_id' => $dcnId,
                'office_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        self::insertRows('dcs_dcn_offices', $rows);
    }

    public static function saveOriginsFromOfficeIds(int $masterlistId, array $officeIds): void
    {
        $now = now();
        $seen = [];
        $rows = [];
        $validOffices = self::existingOfficeIdSet($officeIds);
        foreach ($officeIds as $officeId) {
            $id = (int) trim((string) $officeId);
            if ($id <= 0 || isset($seen[$id]) || ! isset($validOffices[$id])) {
                continue;
            }
            $seen[$id] = true;
            $rows[] = [
                'masterlist_id' => $masterlistId,
                'office_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        self::insertRows('dcs_masterlist_source_offices', $rows);
    }

    public static function saveRelatedDocumentIds(int $masterlistId, array $relatedIds): void
    {
        $now = now();
        DB::table('dcs_masterlist_related_docs')
            ->where(function ($q) use ($masterlistId) {
                $q->where('masterlist_id', $masterlistId)
                    ->orWhere('related_doc_id', $masterlistId);
            })
            ->delete();

        $rows = [];
        foreach ($relatedIds as $id) {
            if ((int) $id === (int) $masterlistId) {
                continue;
            }
            $rows[] = [
                'masterlist_id' => $masterlistId,
                'related_doc_id' => (int) $id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        self::insertRows('dcs_masterlist_related_docs', $rows);

        // Related documents are a sidecar list for retrieval. They must not join this family stack.
        if ($relatedIds === [] || ! Schema::hasColumn('dcs_masterlist_registration', 'stack_group')) {
            return;
        }
        $host = DB::table('dcs_masterlist_registration')->where('id', $masterlistId)->first(['doc_no', 'stack_group']);
        $hostStack = trim((string) ($host->stack_group ?? ''));
        $hostNo = mb_strtolower(trim((string) ($host->doc_no ?? '')));
        if ($hostStack === '' || $hostNo === '') {
            return;
        }
        DB::table('dcs_masterlist_registration')
            ->whereIn('id', $relatedIds)
            ->where('stack_group', $hostStack)
            ->whereRaw('LOWER(TRIM(COALESCE(doc_no, \'\'))) <> ?', [$hostNo])
            ->update(['stack_group' => null, 'updated_at' => now()]);
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function insertRows(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $keys = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $keys[$key] = true;
            }
        }

        $normalized = [];
        foreach ($rows as $row) {
            $filled = [];
            foreach ($keys as $key => $unused) {
                $filled[$key] = $row[$key] ?? null;
            }
            $normalized[] = $filled;
        }

        foreach (array_chunk($normalized, 200) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private static function settingsHasSyllabiCourses(Request $request): bool
    {
        if (! Schema::hasTable('dcs_program_courses') || empty($request->program_id) || empty($request->semester_id)) {
            return false;
        }

        $query = DB::table('dcs_program_courses')
            ->where('program_id', $request->program_id)
            ->where('semester_id', $request->semester_id)
            ->whereRaw("TRIM(COALESCE(course_name, '')) <> ''");

        if (Schema::hasColumn('dcs_program_courses', 'course_type')) {
            $courseType = trim((string) $request->input('course_type', ''));
            if ($courseType !== '') {
                $query->where('course_type', $courseType);
            }
        }

        return $query->exists();
    }

    private static function settingsCourseForSyllabiRow(Request $request, string $courseName, ?string $yearLevel): ?object
    {
        $courseName = trim($courseName);
        if ($courseName === '' || ! Schema::hasTable('dcs_program_courses') || empty($request->program_id) || empty($request->semester_id)) {
            return null;
        }

        $query = DB::table('dcs_program_courses')
            ->where('program_id', $request->program_id)
            ->where('semester_id', $request->semester_id)
            ->whereRaw('LOWER(TRIM(course_name)) = ?', [mb_strtolower($courseName)]);

        if (Schema::hasColumn('dcs_program_courses', 'course_type')) {
            $courseType = trim((string) $request->input('course_type', ''));
            if ($courseType !== '') {
                $query->where('course_type', $courseType);
            }
        }

        if ($yearLevel !== null && Schema::hasColumn('dcs_program_courses', 'year_level')) {
            $query->where('year_level', $yearLevel);
        }

        return $query->first();
    }

    public static function saveSyllabiRowsFromRequest(
        int $requestId,
        Request $request,
        array &$uploadedFiles,
        array $allowedExistingPaths = []
    ): void {
        if (!$request->has('syllabiCourseName')) {
            return;
        }

        $courseNames = $request->syllabiCourseName;
        $availability = $request->syllabiAvailability ?? [];
        $copiesArr = $request->syllabiCopies ?? [];
        $pagesArr = $request->syllabiNoPages ?? [];
        $dateReceived = $request->syllabiDateReceived ?? [];
        $timeReceived = $request->syllabiTimeReceived ?? [];
        $facultyArr = $request->syllabiFaculty ?? [];
        $drfAvailArr = $request->syllabiDrfAvailability ?? [];
        $drfNoArr = $request->syllabiDrfNo ?? [];
        $drfDateArr = $request->syllabiDrfDate ?? [];
        $drfRecvArr = $request->syllabiDrfReceived ?? [];
        $existingScanned = $request->input('syllabiExistingScannedDrf', []);

        $total = count($courseNames);
        $i = 0;
        $now = now();
        $hasYearLevel = Schema::hasColumn('dcs_program_courses', 'year_level');
        $yearArr = $request->syllabiYearLevel ?? [];

        while ($i < $total) {
            $courseName = $courseNames[$i];
            $copies = max(1, (int) ($copiesArr[$i] ?? 1));
            $yearLevel = $hasYearLevel ? self::normalizeSyllabiYearLevel($yearArr[$i] ?? null) : null;

            if (empty($courseName)) {
                $i += $copies;
                continue;
            }

            if (empty($request->program_id) || empty($request->semester_id)) {
                $i += $copies;
                continue;
            }

            $course = self::settingsCourseForSyllabiRow($request, trim((string) $courseName), $yearLevel);
            if (! $course) {
                $i += $copies;
                continue;
            }
            $courseId = $course->id;

            $syllabiId = DB::table('dcs_syllabi')->insertGetId([
                'request_id' => $requestId,
                'college_id' => $request->college_id,
                'program_id' => $request->program_id,
                'semester_id' => $request->semester_id,
                'school_year_id' => $request->school_year_id,
                'course_id' => $courseId,
                'is_available' => ($availability[$i] ?? 'not available') === 'available',
                'no_copies' => $copies,
                'no_pages' => $pagesArr[$i] ?? null,
                'date_received' => $dateReceived[$i] ?? null,
                'time_received' => $timeReceived[$i] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            for ($c = 0; $c < $copies; $c++) {
                $rowIdx = $i + $c;
                if ($rowIdx >= $total) {
                    break;
                }

                $scannedDrf = self::storeOptionalUploadedFile(
                    $request, 'syllabiScannedDrf', $rowIdx, 'scans/syllabi-drf',
                    "Syllabi \"{$courseName}\" copy " . ($c + 1) . ': Scanned DRF'
                );
                if (!$scannedDrf && !empty($existingScanned[$rowIdx])) {
                    $candidate = ltrim((string) $existingScanned[$rowIdx], '/');
                    if ($allowedExistingPaths !== [] && self::isKnownPublicScanPath($candidate, $allowedExistingPaths)) {
                        $scannedDrf = $candidate;
                    }
                }
                if ($scannedDrf && $request->hasFile('syllabiScannedDrf') && isset($request->file('syllabiScannedDrf')[$rowIdx])) {
                    $uploadedFiles[] = $scannedDrf;
                }

                $facultyNames = self::parseSyllabiFacultyNames($facultyArr[$rowIdx] ?? '');
                if (empty($facultyNames)) {
                    $facultyNames = [''];
                }

                foreach ($facultyNames as $facultyName) {
                    $facultyId = null;
                    if ($facultyName !== '') {
                        $faculty = DB::table('dcs_faculties')->where('faculty_name', $facultyName)->first();
                        if (!$faculty) {
                            $facultyId = DB::table('dcs_faculties')->insertGetId([
                                'faculty_name' => $facultyName,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        } else {
                            $facultyId = $faculty->id;
                        }
                    }

                    $syllabiDrfRow = [
                        'syllabi_id' => $syllabiId,
                        'faculty_id' => $facultyId,
                        'is_drf_available' => ($drfAvailArr[$rowIdx] ?? 'not available') === 'available',
                        'drf_no' => $drfNoArr[$rowIdx] ?? null,
                        'drf_date' => $drfDateArr[$rowIdx] ?? null,
                        'drf_received_date' => $drfRecvArr[$rowIdx] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    if (Schema::hasColumn('dcs_syllabi_drf', 'faculty_name')) {
                        $syllabiDrfRow['faculty_name'] = $facultyName;
                    }
                    DB::table('dcs_syllabi_drf')->insert(array_merge(
                        $syllabiDrfRow,
                        self::dcsScanFields('dcs_syllabi_drf', 'scanned_drf', $scannedDrf)
                    ));
                }
            }

            $i += $copies;
        }
    }

    private static function programCourseCodeTaken(
        int $programId,
        int $semesterId,
        string $courseCode,
        ?int $exceptId = null,
        ?string $yearLevel = null,
        ?string $courseType = null
    ): bool {
        $query = DB::table('dcs_program_courses')
            ->where('program_id', $programId)
            ->where('semester_id', $semesterId)
            ->where('course_code', $courseCode);
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        if ($yearLevel !== null && Schema::hasColumn('dcs_program_courses', 'year_level')) {
            $query->where('year_level', $yearLevel);
        }
        if ($courseType !== null && Schema::hasColumn('dcs_program_courses', 'course_type')) {
            $query->where('course_type', $courseType);
        }

        return $query->exists();
    }

    private static function storeOptionalUploadedFile(
        Request $request,
        string $inputName,
        int $index,
        string $directory,
        string $label
    ): ?string {
        if (!$request->hasFile($inputName) || !isset($request->file($inputName)[$index])) {
            return null;
        }

        $file = $request->file($inputName)[$index];
        $category = DocumentStorageService::normalizeDcsCategory($directory);
        $convention = null;
        if ($category === 'syllabi' || $category === 'drf') {
            $convention = self::buildScanBasename($request, 'DRF', $request->input('drfDate') ?: $request->input('syllabiEffectivityDate'));
        }
        $original = null;
        $useConvention = false;
        if ($convention) {
            $original = DocumentStorageService::sanitizeDcsScanBasename($convention) . '.pdf';
            $useConvention = true;
        }

        return DocumentStorageService::storeDcsScan(
            $file,
            auth()->user(),
            $original,
            $category,
            $useConvention,
            self::dccContextFromRequest($request, $category, true)
        );
    }

    public static function syncRevisionStatusForMasterlist(int $masterlistId): void
    {
        if (! RegisterQueryHelper::supportsRevisionStatus()) {
            return;
        }

        $ml = DB::table('dcs_masterlist_registration')->where('id', $masterlistId)->first();
        if (!$ml || !trim((string) $ml->doc_no)) {
            if ($ml) {
                DB::table('dcs_masterlist_registration')
                    ->where('id', $masterlistId)
                    ->update(['revision_status' => 'latest', 'updated_at' => now()]);
            }

            return;
        }

        $dr = DB::table('dcs_document_requests')->where('id', $ml->request_id)->first();
        if (!$dr) {
            return;
        }

        // Tip = highest revise_no in the renumber family (not "row just saved").
        // Gap-fill Rev 5 while Rev 7 exists → 7 stays Latest; new Rev 10 → 10 becomes Latest.
        self::promoteLatestForDoc(
            trim((string) $ml->doc_no),
            (int) $dr->doc_type_id,
            $dr->sub_type_id ? (int) $dr->sub_type_id : null
        );
    }

    public static function markLatestMasterlist(string $docNo, int $docTypeId, ?int $subTypeId, int $latestMasterlistId): void
    {
        // Tip is always max revise_no in the renumber family (explicit id is ignored).
        self::promoteLatestForDoc($docNo, $docTypeId, $subTypeId);
    }

    public static function promoteLatestForDoc(string $docNo, int $docTypeId, ?int $subTypeId): void
    {
        if (! RegisterQueryHelper::supportsRevisionStatus()) {
            return;
        }

        $docNo = trim($docNo);
        if ($docNo === '') {
            return;
        }

        // Non-revisable types stack many Rev 0 latest rows — never obsolesce siblings.
        if (! RegisterQueryHelper::effectiveTypeAllowsRevision($docTypeId, $subTypeId)) {
            return;
        }

        $requestIds = RegisterQueryHelper::requestIdsWithSameDocType((object) [
            'doc_type_id' => $docTypeId,
            'sub_type_id' => $subTypeId,
        ]);
        if ($requestIds === []) {
            return;
        }

        // Also bail if any live family row is marked non-revisable (denormalized flag).
        if (RegisterQueryHelper::supportsAllowsRevisionColumn()) {
            $hasNonRevisable = DB::table('dcs_masterlist_registration')
                ->whereIn('request_id', $requestIds)
                ->where('doc_no', $docNo)
                ->where('allows_revision', false)
                ->exists();
            if ($hasNonRevisable) {
                return;
            }
        }

        $familySeed = RegisterQueryHelper::revisionFamilyDocNos(
            $docNo,
            $docTypeId,
            $subTypeId,
            $requestIds,
            true
        );
        if ($familySeed === []) {
            $familySeed = [$docNo];
        }

        // Active tip = highest revise_no among live rows in this revision family.
        $activeTipQuery = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $requestIds)
            ->whereIn('ml.doc_no', $familySeed)
            ->where(function ($q) {
                $q->whereIn('ml.revision_status', ['latest', 'obsolete'])
                    ->orWhereNull('ml.revision_status')
                    ->orWhere('ml.revision_status', '');
            });
        RegisterQueryHelper::applyNotDeleted($activeTipQuery, 'dr');
        RegisterQueryHelper::applyExcludeDrafts($activeTipQuery, 'dr');
        if ($subTypeId) {
            $activeTipQuery->where('dr.sub_type_id', $subTypeId);
        } else {
            $activeTipQuery->whereNull('dr.sub_type_id');
        }

        $activeTip = $activeTipQuery
            ->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->select('ml.id', 'ml.doc_no')
            ->first();

        if (!$activeTip || !trim((string) ($activeTip->doc_no ?? ''))) {
            return;
        }

        $anchorDocNo = trim((string) $activeTip->doc_no);
        $familyNos = RegisterQueryHelper::revisionFamilyDocNos(
            $anchorDocNo,
            $docTypeId,
            $subTypeId,
            $requestIds,
            true
        );
        if ($familyNos === []) {
            $familyNos = [$anchorDocNo];
        }

        if (strcasecmp($docNo, $anchorDocNo) !== 0) {
            $extraFamily = RegisterQueryHelper::revisionFamilyDocNos(
                $docNo,
                $docTypeId,
                $subTypeId,
                $requestIds,
                true
            );
            if ($extraFamily !== []) {
                $merged = [];
                foreach (array_merge($familyNos, $extraFamily) as $no) {
                    $merged[strtolower($no)] = $no;
                }
                $familyNos = array_values($merged);
            }
        }

        // Heal live legacy "archived" → obsolete (DCS no longer uses archived).
        // Skip drafts (is_draft) and soft-deleted recycle-bin rows.
        $legacyArchived = [];
        if (RegisterQueryHelper::supportsArchivedRevisionStatus()) {
            $legacyQuery = DB::table('dcs_masterlist_registration as m')
                ->join('dcs_document_requests as dr', 'dr.id', '=', 'm.request_id')
                ->whereIn('m.request_id', $requestIds)
                ->whereIn('m.doc_no', $familyNos)
                ->where('m.revision_status', 'archived')
                ->select('m.id', 'm.doc_no', 'm.revise_no', 'm.doc_type_id');
            RegisterQueryHelper::applyNotDeleted($legacyQuery, 'dr');
            RegisterQueryHelper::applyExcludeDrafts($legacyQuery, 'dr');
            $legacyArchived = $legacyQuery->get();
        }

        foreach ($legacyArchived as $row) {
            $conflict = DB::table('dcs_masterlist_registration')
                ->where('id', '!=', $row->id)
                ->where('doc_no', $row->doc_no)
                ->where('doc_type_id', $row->doc_type_id)
                ->where('revise_no', $row->revise_no)
                ->whereIn('revision_status', ['latest', 'obsolete'])
                ->exists();
            if (!$conflict) {
                DB::table('dcs_masterlist_registration')
                    ->where('id', $row->id)
                    ->update(['revision_status' => 'obsolete', 'updated_at' => now()]);
            }
        }

        $tipId = (int) $activeTip->id;

        $obsoleteOthers = DB::table('dcs_masterlist_registration')
            ->whereIn('doc_no', $familyNos)
            ->whereIn('request_id', $requestIds)
            ->where(function ($q) {
                $q->whereIn('revision_status', ['latest', 'obsolete'])
                    ->orWhereNull('revision_status')
                    ->orWhere('revision_status', '');
            })
            ->where('id', '!=', $tipId);
        if (Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            $obsoleteOthers->whereNotIn('request_id', function ($q) {
                $q->select('id')->from('dcs_document_requests')->where('is_draft', true);
            });
        }
        $obsoleteOthers->update(['revision_status' => 'obsolete', 'updated_at' => now()]);

        DB::table('dcs_masterlist_registration')
            ->where('id', $tipId)
            ->update(['revision_status' => 'latest', 'updated_at' => now()]);

        DocumentStorageService::moveObsoleteDocinfoFilesForFamily($familyNos, $requestIds, $tipId);
        RegisterQueryHelper::bumpDocumentListCache();
    }


    public static function obsoleteSelectedRevisionSources(Request $request, int $newRequestId, string $newDocNo): void
    {
        if (! RegisterQueryHelper::supportsRevisionStatus()) {
            return;
        }
        if (self::isSyllabiLikeSubTypeRow(self::dcsDocType($request->input('sub_type_id')))) {
            return;
        }
        $versionName = strtolower((string) DB::table('dcs_version_type')->where('id', $request->input('version_id'))->value('version_name'));
        $mode = strtolower((string) $request->input('registration_mode', ''));
        if ($mode !== 'revised' && ! str_contains($versionName, 'revis')) {
            return;
        }

        $docNos = array_values((array) $request->input('documentNo', []));
        $titles = array_values((array) $request->input('documentTitle', []));
        $revNos = array_values((array) $request->input('revisionNo', []));
        $picks = [];
        $count = max(count($docNos), count($titles), count($revNos));
        for ($i = 0; $i < $count; $i++) {
            $no = trim((string) ($docNos[$i] ?? ''));
            $title = trim((string) ($titles[$i] ?? ''));
            if ($no === '' && $title === '') {
                continue;
            }
            $picks[] = [
                'doc_no' => $no,
                'title' => $title,
                'revise_no' => isset($revNos[$i]) && trim((string) $revNos[$i]) !== '' ? (int) $revNos[$i] : null,
            ];
        }
        if ($picks === []) {
            return;
        }

        $docTypeId = (int) $request->input('doc_type_id');
        $hasStack = Schema::hasColumn('dcs_masterlist_registration', 'stack_group');
        foreach ($picks as $pick) {
            $query = DB::table('dcs_masterlist_registration as ml')
                ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                ->where('dr.doc_type_id', $docTypeId)
                ->where('ml.request_id', '!=', $newRequestId)
                ->select(array_values(array_filter([
                    'ml.id',
                    'ml.doc_no',
                    'ml.doc_title',
                    'ml.revision_status',
                    'ml.revise_no',
                    $hasStack ? 'ml.stack_group' : null,
                ])));
            if ($pick['doc_no'] !== '') {
                $query->whereRaw('LOWER(TRIM(ml.doc_no)) = ?', [mb_strtolower($pick['doc_no'])]);
            }
            RegisterQueryHelper::applyNotDeleted($query, 'dr');
            RegisterQueryHelper::applyExcludeDrafts($query, 'dr');
            $matched = $query->get()->filter(function ($row) use ($pick) {
                if ($pick['title'] !== '' && strcasecmp(trim((string) $row->doc_title), $pick['title']) !== 0) {
                    return false;
                }
                if ($pick['revise_no'] !== null && (int) $row->revise_no !== $pick['revise_no']) {
                    return false;
                }

                return true;
            })->values();
            if ($matched->isEmpty()) {
                continue;
            }
            $selected = $matched->first(function ($row) {
                $status = strtolower(trim((string) ($row->revision_status ?? '')));

                return $status === '' || $status === 'latest';
            }) ?? $matched->first();

            $stackGroup = $hasStack ? trim((string) ($selected->stack_group ?? '')) : '';
            if ($hasStack && $stackGroup === '') {
                $stackGroup = (string) \Illuminate\Support\Str::uuid();
            }
            $update = [
                'revision_status' => 'obsolete',
                'updated_at' => now(),
            ];
            if ($hasStack && $stackGroup !== '') {
                $update['stack_group'] = $stackGroup;
            }
            $status = strtolower(trim((string) ($selected->revision_status ?? '')));
            if ($status !== '' && $status !== 'latest') {
                unset($update['revision_status']);
            }
            DB::table('dcs_masterlist_registration')->where('id', (int) $selected->id)->update($update);
            if ($hasStack && $stackGroup !== '') {
                DB::table('dcs_masterlist_registration')
                    ->where('request_id', $newRequestId)
                    ->update(['stack_group' => $stackGroup, 'updated_at' => now()]);
                self::attachEmptyStackPredecessors((int) $selected->id, $stackGroup);
            }
        }
    }

    /**
     * The current latest registration Recur will continue.
     * Accepts a latest whose version type is New or Revise.
     */
    public static function findLatestMasterlistForRecur(Request $request, int $exceptMasterlistId = 0): ?object
    {
        $pickedId = (int) $request->input('supersede_masterlist_id');
        $lookup = trim((string) $request->input('revised_from_doc_no', ''));
        if ($lookup === '') {
            $lookup = trim((string) $request->input('masterlistDocNo', ''));
        }
        if ($lookup === '') {
            $numbers = collect((array) $request->input('documentNo', []))
                ->map(fn ($no) => trim((string) $no))
                ->filter();
            $lookup = (string) ($numbers->first() ?? '');
        }
        if ($pickedId < 1 && $lookup === '') {
            return null;
        }

        $docTypeId = (int) $request->input('doc_type_id');
        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->where(function ($q) {
                $q->where('ml.revision_status', 'latest')
                    ->orWhereNull('ml.revision_status')
                    ->orWhere('ml.revision_status', '');
            })
            ->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->select('ml.id', 'ml.doc_no', 'ml.revise_no', 'ml.request_id');
        if ($pickedId > 0) {
            $query->where('ml.id', $pickedId);
        } else {
            $query->whereRaw('LOWER(TRIM(ml.doc_no)) = ?', [mb_strtolower($lookup)]);
            if ($exceptMasterlistId > 0) {
                $query->where('ml.id', '!=', $exceptMasterlistId);
            }
        }
        if ($docTypeId > 0) {
            $query->where('dr.doc_type_id', $docTypeId);
        }
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyExcludeDrafts($query, 'dr');

        $row = $query->first();

        return $row ?: null;
    }

    /** Mark the selected latest superseded before the new Recur row is inserted as latest. */
    public static function supersedeLatestForRecur(Request $request, int $exceptMasterlistId = 0): void
    {
        if (! RegisterQueryHelper::supportsRevisionStatus()) {
            return;
        }
        $picked = self::findLatestMasterlistForRecur($request, $exceptMasterlistId);
        if ($picked === null || (int) $picked->id === $exceptMasterlistId) {
            return;
        }
        $update = [
            'revision_status' => 'superseded',
            'updated_at' => now(),
        ];
        if (RegisterQueryHelper::supportsAllowsRevisionColumn()) {
            $update['allows_revision'] = true;
        }
        if (Schema::hasColumn('dcs_masterlist_registration', 'stack_group')) {
            $group = trim((string) DB::table('dcs_masterlist_registration')->where('id', (int) $picked->id)->value('stack_group'));
            if ($group === '') {
                $group = (string) \Illuminate\Support\Str::uuid();
                $update['stack_group'] = $group;
            }
            $request->merge(['recur_stack_group' => $group]);
            self::attachEmptyStackPredecessors((int) $picked->id, $group);
        }
        DB::table('dcs_masterlist_registration')
            ->where('id', (int) $picked->id)
            ->update($update);
    }

    /**
     * Older obsolete or superseded copies of this same document join its stack.
     * Another latest that only shares the document number is left alone.
     */
    private static function attachEmptyStackPredecessors(int $masterlistId, string $stackGroup): void
    {
        if ($stackGroup === '' || ! Schema::hasColumn('dcs_masterlist_registration', 'stack_group')) {
            return;
        }
        $picked = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->where('ml.id', $masterlistId)
            ->select('ml.doc_no', 'ml.doc_title', 'dr.doc_type_id')
            ->first();
        if (! $picked) {
            return;
        }
        $title = trim((string) ($picked->doc_title ?? ''));
        $docNo = trim((string) ($picked->doc_no ?? ''));
        if ($title === '') {
            return;
        }
        $ids = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->where('dr.doc_type_id', (int) $picked->doc_type_id)
            ->where('ml.id', '!=', $masterlistId)
            ->whereRaw('LOWER(TRIM(ml.doc_title)) = ?', [mb_strtolower($title)])
            ->when($docNo !== '', function ($q) use ($docNo) {
                $q->whereRaw('LOWER(TRIM(ml.doc_no)) = ?', [mb_strtolower($docNo)]);
            }, function ($q) {
                $q->where(function ($empty) {
                    $empty->whereNull('ml.doc_no')->orWhere('ml.doc_no', '');
                });
            })
            ->where(function ($q) {
                $q->whereNull('ml.stack_group')->orWhere('ml.stack_group', '');
            })
            ->whereIn('ml.revision_status', ['obsolete', 'superseded'])
            ->pluck('ml.id');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('dcs_masterlist_registration')
            ->whereIn('id', $ids->all())
            ->update(['stack_group' => $stackGroup, 'updated_at' => now()]);
    }

    public static function applyRecurRevisedFrom(array &$row, Request $request, string $mode): void
    {
        if ($mode !== 'recurring') {
            return;
        }
        if (Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
            $from = trim((string) $request->input('revised_from_doc_no', ''));
            if ($from === '') {
                $from = trim((string) ($request->input('masterlistDocNo') ?? ''));
            }
            if ($from !== '') {
                $row['revised_from_doc_no'] = $from;
            }
        }
        if (Schema::hasColumn('dcs_masterlist_registration', 'stack_group')) {
            $group = trim((string) $request->input('recur_stack_group', ''));
            if ($group !== '') {
                $row['stack_group'] = $group;
            }
        }
    }

    private static function applyRelatedStackGroup(array &$row, Request $request): void
    {
        // Stacking two Latest copies of the same number is no longer offered.
        return;
    }
}