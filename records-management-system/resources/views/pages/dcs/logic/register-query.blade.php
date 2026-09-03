<?php

namespace App\Helpers;

use App\Services\DocumentStorageService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Register/Update/Edit/History reads. PK is always `id`.
 */
class RegisterQueryHelper
{
    /** Soft-deleted DCS documents are kept in the Recycle Bin for this many years (same as Admin Console). */
    public const RECYCLE_BIN_RETENTION_YEARS = 1;

    public static function recycleBinExpiresAt(\DateTimeInterface|string $deletedAt): Carbon
    {
        return Carbon::parse($deletedAt)->addYears(self::RECYCLE_BIN_RETENTION_YEARS);
    }

    public static function scanUrl(?string $path): ?string
    {
        return DocumentStorageService::dcsScanUrl($path);
    }

    public static function pgBool(mixed $val): bool
    {
        if (is_bool($val)) {
            return $val;
        }
        if (is_int($val) || is_float($val)) {
            return (bool) $val;
        }

        return in_array(strtolower(trim((string) $val)), ['1', 't', 'true', 'yes', 'on'], true);
    }

    public static function findDocumentRequest(int $id): ?object
    {
        $query = DB::table('dcs_document_requests')->where('id', $id);
        if (Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->first();
    }

    public static function findTrashedDocumentRequest(int $id): ?object
    {
        if (!Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            return null;
        }

        return DB::table('dcs_document_requests')->where('id', $id)->whereNotNull('deleted_at')->first();
    }

    public static function supportsSoftDelete(): bool
    {
        return Schema::hasColumn('dcs_document_requests', 'deleted_at');
    }

    public static function supportsRevisionStatus(): bool
    {
        return Schema::hasColumn('dcs_masterlist_registration', 'revision_status');
    }

    /**
     * Legacy DCS code sometimes "heals" rows where revision_status = 'archived'
     * (then recomputes latest/obsolete).
     *
     * On Postgres when revision_status is an ENUM type that does NOT include the
     * label 'archived', the SQL comparison `revision_status = 'archived'`
     * throws an invalid-enum-label error even if there are zero archived rows.
     */
    public static function supportsArchivedRevisionStatus(): bool
    {
        if (! self::supportsRevisionStatus()) {
            return false;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'pgsql') {
            // MySQL/SQLite/etc: revision_status is typically a string-like column;
            // querying for 'archived' is safe.
            return true;
        }

        $row = DB::selectOne(
            '
            SELECT t.typtype, a.atttypid
            FROM pg_attribute a
            JOIN pg_class c ON c.oid = a.attrelid
            JOIN pg_type t ON t.oid = a.atttypid
            WHERE c.relname = ?
              AND a.attname = ?
            LIMIT 1
            ',
            ['dcs_masterlist_registration', 'revision_status']
        );

        if (!$row) {
            // Unknown column type: err on the side of allowing the legacy query.
            return true;
        }

        // Postgres ENUM types have typtype = 'e'.
        if (($row->typtype ?? null) !== 'e') {
            return true;
        }

        $enumOid = (int) ($row->atttypid ?? 0);
        if ($enumOid <= 0) {
            return false;
        }

        $exists = DB::selectOne(
            'SELECT 1 FROM pg_enum WHERE enumtypid = ? AND enumlabel = ? LIMIT 1',
            [$enumOid, 'archived']
        );

        return $exists !== null;
    }

    public static function supportsRevisedFromDocNo(): bool
    {
        return Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no');
    }

    /**
     * Prefer non-obsolete masterlist rows (latest / null / empty).
     * No-op when revision_status column is missing (treat all as latest).
     */
    public static function applyLatestRevisionStatus($query, string $alias = 'ml')
    {
        if (! self::supportsRevisionStatus()) {
            return $query;
        }

        return $query->where(function ($q) use ($alias) {
            $q->whereNull("{$alias}.revision_status")
                ->orWhere("{$alias}.revision_status", '')
                ->orWhere("{$alias}.revision_status", 'latest');
        });
    }

    /** Keep latest + obsolete (exclude soft-deleted docs via applyNotDeleted separately). */
    public static function applyLiveRevisionStatuses($query, string $alias = 'ml')
    {
        if (! self::supportsRevisionStatus()) {
            return $query;
        }

        return $query->whereIn("{$alias}.revision_status", ['latest', 'obsolete']);
    }

    public static function isSyllabiLikeName(?string $name): bool
    {
        if ($name === null) {
            return false;
        }
        $n = mb_strtolower($name);

        return str_contains($n, 'syllab') || str_contains($n, 'tos') || str_contains($n, 'rubric');
    }

    /** Parent doc-type IDs keyed by report/dashboard tab. */
    public static function parentTypeIdMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $byName = DB::table('dcs_doc_types')
            ->whereNull('parent_id')
            ->get(['id', 'doc_type_name'])
            ->mapWithKeys(fn ($row) => [mb_strtolower(trim($row->doc_type_name)) => (int) $row->id]);

        $map = [
            'internal_docs' => $byName['internal'] ?? 1,
            'internal_forms' => $byName['internal forms'] ?? 2,
            'external_docs' => $byName['external'] ?? 3,
            'forms' => $byName['forms'] ?? 4,
            'logbooks' => $byName['logbooks'] ?? 5,
        ];

        return $map;
    }

    public static function requestIdsWithSameDocType(object $docRequest): array
    {
        $query = DB::table('dcs_document_requests')
            ->where('doc_type_id', $docRequest->doc_type_id);

        if ($docRequest->sub_type_id === null || $docRequest->sub_type_id === '') {
            $query->whereNull('sub_type_id');
        } else {
            $query->where('sub_type_id', $docRequest->sub_type_id);
        }

        return $query->pluck('id')->all();
    }

    public static function formatDate(mixed $val): string
    {
        if (!$val) {
            return '';
        }

        return Carbon::parse($val)->format('Y-m-d');
    }

    public static function formatTime(mixed $val): string
    {
        if (!$val) {
            return '';
        }

        return Carbon::parse($val)->format('H:i');
    }

    public static function parentDocTypes()
    {
        return DB::table('dcs_doc_types')
            ->whereNull('parent_id')
            ->orderBy('id')
            ->get(['id', 'doc_type_name']);
    }

    public static function isLatestRevisionStatus(?string $status): bool
    {
        return strtolower(trim((string) $status)) !== 'obsolete';
    }

    /** Cast mixed ID lists (Postgres bigint strings) to unique ints. */
    public static function intIds($ids): array
    {
        return collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }

    /** Exclude soft-deleted document requests when the column exists. */
    public static function applyNotDeleted($query, string $alias = 'dr')
    {
        if (Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            $query->whereNull($alias . '.deleted_at');
        }

        return $query;
    }

    /** Inventory scope bypass: super admin, RFIO, or roles with dcs_view_all_documents. */
    public static function canViewAllDocuments(): bool
    {
        return self::isFullDcsUser();
    }

    public static function currentOfficeCode(): ?string
    {
        $code = auth()->user()?->details?->office?->office_code ?? null;

        return $code !== null && $code !== '' ? strtoupper(trim((string) $code)) : null;
    }

    public static function isRfioOffice(): bool
    {
        return self::currentOfficeCode() === 'RFIO';
    }

    /**
     * Full DCS (Register, Database, Stamp, etc.):
     * - super admin, or
     * - can_access_dcs + RFIO office, or
     * - can_access_dcs + dcs_view_all_documents (any office)
     */
    public static function isFullDcsUser(): bool
    {
        $perms = auth()->user()?->permissions;
        if (!$perms) {
            return false;
        }
        if (!empty($perms->is_sadm)) {
            return true;
        }
        if (empty($perms->can_access_dcs)) {
            return false;
        }

        return self::isRfioOffice() || !empty($perms->dcs_view_all_documents);
    }

    /** Non-full DCS user with DCS access: DRF/DCN intake only. */
    public static function isLimitedDcsUser(): bool
    {
        $perms = auth()->user()?->permissions;
        if (!$perms) {
            return false;
        }
        if (!empty($perms->is_sadm)) {
            return false;
        }
        if (empty($perms->can_access_dcs)) {
            return false;
        }

        return !self::isFullDcsUser();
    }

    public static function assertFullDcsUser(): void
    {
        abort_unless(self::isFullDcsUser(), 403, 'Full Document Control System access is required.');
    }

    /** Full DCS operators may open any office intake form by ID (notification deep link). */
    public static function canBrowseAllOfficeIntake(): bool
    {
        return self::isFullDcsUser();
    }

    public static function normalizedOriginatorName(?string $name = null): string
    {
        $name ??= self::currentUserDisplayName();

        return mb_strtolower(trim((string) $name));
    }

    /** Limited DCS: documents where the user is the registered originator account (name fallback for legacy rows). */
    public static function visibleOriginatorRequestIds(): array
    {
        $userId = (int) auth()->id();
        $selfName = self::normalizedOriginatorName();

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id');
        self::applyNotDeleted($query, 'dr');

        $query->where(function ($q) use ($userId, $selfName) {
            if ($userId > 0 && Schema::hasColumn('dcs_masterlist_registration', 'originator_account_id')) {
                $q->where('ml.originator_account_id', $userId);
            }

            if ($selfName !== '' && $selfName !== '—') {
                $method = ($userId > 0 && Schema::hasColumn('dcs_masterlist_registration', 'originator_account_id'))
                    ? 'orWhere'
                    : 'where';
                $q->{$method}(function ($legacy) use ($selfName) {
                    if (Schema::hasColumn('dcs_masterlist_registration', 'originator_account_id')) {
                        $legacy->whereNull('ml.originator_account_id');
                    }
                    $legacy->whereRaw('LOWER(TRIM(COALESCE(ml.originator_name, \'\'))) = ?', [$selfName]);
                });
            } elseif ($userId < 1) {
                $q->whereRaw('1 = 0');
            }
        });

        return self::intIds($query->pluck('ml.request_id'));
    }

    public static function resolveOriginatorAccountIdForName(?string $originatorName): ?int
    {
        if (self::originatorMatchesCurrentUser($originatorName)) {
            $userId = (int) auth()->id();

            return $userId > 0 ? $userId : null;
        }

        return null;
    }

    public static function originatorMatchesCurrentUser(?string $originatorName, ?int $originatorAccountId = null): bool
    {
        $userId = (int) auth()->id();
        if ($userId > 0 && $originatorAccountId && (int) $originatorAccountId === $userId) {
            return true;
        }

        $a = self::normalizedOriginatorName($originatorName);
        $b = self::normalizedOriginatorName();

        return $a !== '' && $b !== '' && $b !== '—' && $a === $b;
    }

    public static function assertCanAccessScanPath(string $path): void
    {
        self::assertFullDcsUser();

        $normalized = DocumentStorageService::normalizeDcsScanPath($path);
        abort_unless($normalized, 404);

        if (self::isOfficeIntakeScanPath($normalized)) {
            abort(403, 'Office intake forms are not part of the registered document inventory.');
        }

        $requestId = DocumentStorageService::resolveRequestIdForScanPath($normalized);
        if ($requestId !== null) {
            self::assertCanAccessRequest($requestId);

            return;
        }

        abort_unless(
            self::userCanAccessGeneratedReportPath($normalized),
            404,
            'Document file not found.'
        );
    }

    private static function userCanAccessGeneratedReportPath(string $path): bool
    {
        if (! Schema::hasTable('dcs_generated_reports')) {
            return false;
        }

        $report = DB::table('dcs_generated_reports')->where('file_path', $path)->first();
        if (!$report) {
            return false;
        }

        if (self::canViewAllDocuments()) {
            return true;
        }

        $userOffice = self::currentOfficeCode();
        $reportOffice = strtoupper(trim((string) ($report->office_code ?: explode('/', $path)[0] ?? '')));
        if ($userOffice && $reportOffice && $userOffice === $reportOffice) {
            return true;
        }

        return (int) ($report->generated_by ?? 0) === (int) auth()->id();
    }

    /** @param  array<string, mixed>  $row */
    private static function mapSearchResultForAudience(array $row): array
    {
        if (! self::isLimitedDcsUser()) {
            return $row;
        }

        unset($row['scanned_copy_path'], $row['scanned_copy_url'], $row['keywords']);
        $row['edit_url'] = null;
        $row['stamp_url'] = null;
        $row['inventory_url'] = null;
        $row['checklists'] = [
            'drf' => false,
            'dcn' => false,
            'masterlist' => true,
            'approval' => false,
            'distribution' => false,
            'retrieval' => false,
        ];

        return $row;
    }

    /** @param  array<string, mixed>  $row */
    private static function mapRevisionResultForAudience(array $row): array
    {
        if (! self::isLimitedDcsUser()) {
            return $row;
        }

        unset($row['scanned_copy_path'], $row['scanned_copy_url']);

        return $row;
    }

    public static function currentUserDisplayName(): string
    {
        $d = auth()->user()?->details;
        if (!$d) {
            return auth()->user()?->username ?? 'User';
        }
        $parts = array_filter([
            trim((string) ($d->first_name ?? '')),
            trim((string) ($d->middle_name ?? '')),
            trim((string) ($d->last_name ?? '')),
        ]);

        return $parts !== [] ? implode(' ', $parts) : (auth()->user()?->username ?? 'User');
    }

    public static function currentOfficeName(): string
    {
        return trim((string) (auth()->user()?->details?->office?->office_name ?? '')) ?: '—';
    }

    public static function currentOfficeId(): ?int
    {
        $id = auth()->user()?->details?->office_id ?? auth()->user()?->details?->office?->id ?? null;

        return $id ? (int) $id : null;
    }

    /**
     * Limit a query on dcs_document_requests (alias) to docs linked to the user’s office
     * unless they can view all. Linkage = source / DRF / distribution / retrieval office,
     * or the creator’s office.
     */
    public static function applyOfficeScope($query, string $drAlias = 'dr')
    {
        if (self::canViewAllDocuments()) {
            return $query;
        }

        $officeId = self::currentOfficeId();
        if (!$officeId) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        $query->where(function ($q) use ($drAlias, $officeId) {
            $q->whereExists(function ($sub) use ($drAlias, $officeId) {
                $sub->select(DB::raw(1))
                    ->from('dcs_masterlist_registration as ml_os')
                    ->join('dcs_masterlist_source_offices as so_os', 'so_os.masterlist_id', '=', 'ml_os.id')
                    ->whereColumn('ml_os.request_id', $drAlias . '.id')
                    ->where('so_os.office_id', $officeId);
            })->orWhereExists(function ($sub) use ($drAlias, $officeId) {
                $sub->select(DB::raw(1))
                    ->from('dcs_document_request_form as drf_os')
                    ->join('dcs_drf_offices as drfo_os', 'drfo_os.document_request_form_id', '=', 'drf_os.id')
                    ->whereColumn('drf_os.request_id', $drAlias . '.id')
                    ->where('drfo_os.office_id', $officeId);
            })->orWhereExists(function ($sub) use ($drAlias, $officeId) {
                $sub->select(DB::raw(1))
                    ->from('dcs_document_distribution as dist_os')
                    ->join('dcs_distribution_offices as disto_os', 'disto_os.distribution_id', '=', 'dist_os.id')
                    ->whereColumn('dist_os.request_id', $drAlias . '.id')
                    ->where('disto_os.office_id', $officeId);
            })->orWhereExists(function ($sub) use ($drAlias, $officeId) {
                $sub->select(DB::raw(1))
                    ->from('dcs_document_retrieval as ret_os')
                    ->join('dcs_retrieval_offices as reto_os', 'reto_os.retrieval_id', '=', 'ret_os.id')
                    ->whereColumn('ret_os.request_id', $drAlias . '.id')
                    ->where('reto_os.office_id', $officeId);
            })->orWhereExists(function ($sub) use ($drAlias, $officeId) {
                $sub->select(DB::raw(1))
                    ->from((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as ad_os')
                    ->whereColumn('ad_os.account_id', $drAlias . '.created_by')
                    ->where('ad_os.office_id', $officeId);
            });
        });

        return $query;
    }

    /**
     * Office intake DRF/DCN are pre-registration forms only — never part of RFIO inventory.
     */
    public static function isOfficeIntakeRequestId(int $requestId): bool
    {
        if ($requestId < 1) {
            return false;
        }

        if (Schema::hasColumn('dcs_document_request_form', 'is_office_intake')) {
            if (DB::table('dcs_document_request_form')
                ->where('request_id', $requestId)
                ->where('is_office_intake', true)
                ->exists()) {
                return true;
            }
        }

        if (Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
            if (DB::table('dcs_document_change_notice')
                ->where('request_id', $requestId)
                ->where('is_office_intake', true)
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Exclude office-intake-only rows from registered-document queries on dcs_document_requests. */
    public static function applyExcludeOfficeIntakeRequests($query, string $drAlias = 'dr'): void
    {
        if (! Schema::hasColumn('dcs_document_request_form', 'is_office_intake')
            && ! Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
            return;
        }

        $query->where(function ($q) use ($drAlias) {
            if (Schema::hasColumn('dcs_document_request_form', 'is_office_intake')) {
                $q->whereNotExists(function ($sub) use ($drAlias) {
                    $sub->select(DB::raw(1))
                        ->from('dcs_document_request_form as oi_drf')
                        ->whereColumn('oi_drf.request_id', $drAlias . '.id')
                        ->where('oi_drf.is_office_intake', true);
                });
            }
            if (Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
                $q->whereNotExists(function ($sub) use ($drAlias) {
                    $sub->select(DB::raw(1))
                        ->from('dcs_document_change_notice as oi_dcn')
                        ->whereColumn('oi_dcn.request_id', $drAlias . '.id')
                        ->where('oi_dcn.is_office_intake', true);
                });
            }
        });
    }

    public static function applyExcludeOfficeIntakeDrf($query, string $drfAlias = 'drf'): void
    {
        if (! Schema::hasColumn('dcs_document_request_form', 'is_office_intake')) {
            return;
        }

        $query->where(function ($q) use ($drfAlias) {
            $q->whereNull($drfAlias . '.is_office_intake')
                ->orWhere($drfAlias . '.is_office_intake', false);
        });
    }

    public static function applyExcludeOfficeIntakeDcn($query, string $dcnAlias = 'dcn'): void
    {
        if (! Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
            return;
        }

        $query->where(function ($q) use ($dcnAlias) {
            $q->whereNull($dcnAlias . '.is_office_intake')
                ->orWhere($dcnAlias . '.is_office_intake', false);
        });
    }

    /** Registered DCS inventory: office scope plus no office-intake placeholders. */
    public static function applyRegisteredDocumentScope($query, string $drAlias = 'dr'): void
    {
        self::applyOfficeScope($query, $drAlias);
        self::applyExcludeOfficeIntakeRequests($query, $drAlias);
    }

    public static function isOfficeIntakeScanPath(string $path): bool
    {
        $path = DocumentStorageService::normalizeDcsScanPath($path);
        if ($path === null) {
            return false;
        }

        foreach ([
            ['table' => 'dcs_document_request_form', 'column' => 'scanned_drf'],
            ['table' => 'dcs_document_change_notice', 'column' => 'scanned_dcn'],
        ] as $source) {
            if (! Schema::hasTable($source['table'])
                || ! Schema::hasColumn($source['table'], $source['column'])
                || ! Schema::hasColumn($source['table'], 'is_office_intake')) {
                continue;
            }

            if (DB::table($source['table'])
                ->where($source['column'], $path)
                ->where('is_office_intake', true)
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    public static function userCanAccessRequest(int $requestId): bool
    {
        if (self::isOfficeIntakeRequestId($requestId)) {
            return false;
        }

        if (self::canViewAllDocuments()) {
            return true;
        }

        $q = DB::table('dcs_document_requests as dr')->where('dr.id', $requestId);
        self::applyOfficeScope($q, 'dr');

        return $q->exists();
    }

    public static function assertCanAccessRequest(int $requestId): void
    {
        abort_unless(self::userCanAccessRequest($requestId), 403, 'You do not have access to this document.');
    }

    /** Search/stamp/preview: latest (including null/empty status), numbered obsolete, and empty-doc-no rows. */
    public static function visibleRequestIds(): array
    {
        $mlIds = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id');
        self::applyNotDeleted($mlIds, 'dr');
        self::applyRegisteredDocumentScope($mlIds, 'dr');
        $mlIds = $mlIds->pluck('ml.request_id');

        $noMlIds = DB::table('dcs_document_requests as dr')
            ->leftJoin('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dr.id')
            ->whereNull('ml.id');
        self::applyNotDeleted($noMlIds, 'dr');
        self::applyRegisteredDocumentScope($noMlIds, 'dr');
        $noMlIds = $noMlIds->pluck('dr.id');

        return self::intIds($mlIds->merge($noMlIds));
    }

    /** Update list: only latest (null/empty treated as latest) plus incomplete registrations. */
    public static function latestEditableRequestIds(): array
    {
        $latestIds = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id');
        self::applyLatestRevisionStatus($latestIds, 'ml');
        self::applyNotDeleted($latestIds, 'dr');
        self::applyRegisteredDocumentScope($latestIds, 'dr');
        $latestIds = $latestIds->pluck('ml.request_id');

        $noMlIds = DB::table('dcs_document_requests as dr')
            ->leftJoin('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dr.id')
            ->where(function ($q) {
                $q->whereNull('ml.id')
                    ->orWhereNull('ml.doc_no')
                    ->orWhere('ml.doc_no', '');
            });
        self::applyNotDeleted($noMlIds, 'dr');
        self::applyRegisteredDocumentScope($noMlIds, 'dr');
        $noMlIds = $noMlIds->pluck('dr.id');

        return self::intIds($latestIds->merge($noMlIds));
    }

    /** Query params that still target a document when doc_no is blank. */
    public static function documentSearchParams(object $row): array
    {
        $docNo = trim((string) ($row->doc_no ?? ''));
        $title = trim((string) ($row->doc_title ?? ''));
        $requestId = (int) ($row->request_id ?? 0);
        $search = $docNo !== '' ? $docNo : ($title !== '' ? $title : (string) $requestId);
        $params = ['search' => $search];
        if ($docNo === '' && $requestId > 0) {
            $params['request_id'] = $requestId;
        }

        return $params;
    }

    /** Group key for doc_no + type + sub_type (Update list / Database lineage). */
    private static function docFamilyGroupKey(string $docNo, int $docTypeId, int $subTypeId): string
    {
        return strtolower(trim($docNo)) . '||' . $docTypeId . '||' . $subTypeId;
    }

    /**
     * Score tip rows for lineage merge (higher revise_no wins; request_id breaks ties).
     *
     * @return array<string, array{doc_no: string, doc_type_id: int, sub_type_id: int, rev_no: int, request_id: int, revised_from: string, score: int}>
     */
    private static function lineageGroupTipsFromParents(array $parentsByKey): array
    {
        $tips = [];
        foreach ($parentsByKey as $key => $p) {
            if (($p['doc_no'] ?? 'N/A') === 'N/A') {
                continue;
            }
            $rev = (int) ($p['rev_no'] ?? 0);
            $rid = (int) ($p['request_id'] ?? 0);
            $tips[$key] = [
                'doc_no' => (string) $p['doc_no'],
                'doc_type_id' => (int) ($p['doc_type_id'] ?? 0),
                'sub_type_id' => (int) ($p['sub_type_id'] ?? 0),
                'rev_no' => $rev,
                'request_id' => $rid,
                'revised_from' => trim((string) ($p['revised_from_doc_no'] ?? '')),
                'score' => ($rev * 1_000_000_000) + $rid,
                'is_active' => !empty($p['is_active']),
            ];
        }

        return $tips;
    }

    /**
     * Decide which doc-no families fold under which tip.
     * Holder at prior doc no competes with renumbered revisions so delete → renumber → restore
     * still keeps one aligned family (e.g. Rev 2 at MSTR-…-11 absorbs Rev 1 at MSTR-…-12).
     *
     * @param array<string, array{doc_no: string, doc_type_id: int, sub_type_id: int, rev_no: int, request_id: int, revised_from: string, score: int}> $groupTips
     * @param list<int> $visibleIds
     * @return array<string, array<string, true>> winnerKey => absorbed group keys
     */
    public static function buildLineageMergeEdges(array $groupTips, array $visibleIds = []): array
    {
        if ($groupTips === []) {
            return [];
        }

        $pickWinner = static function (array $candidates) use ($groupTips): ?string {
            $active = [];
            foreach ($candidates as $key => $score) {
                if (!empty($groupTips[$key]['is_active'])) {
                    $active[$key] = $score;
                }
            }
            if ($active === []) {
                return null;
            }
            arsort($active);

            return (string) array_key_first($active);
        };

        $fromKeys = [];
        foreach ($groupTips as $key => $meta) {
            $fromKeys[$key] = $meta['doc_no'];
            $fromNo = $meta['revised_from'];
            if ($fromNo === '' || strcasecmp($fromNo, $meta['doc_no']) === 0) {
                continue;
            }
            $fromKey = self::docFamilyGroupKey(
                $fromNo,
                (int) $meta['doc_type_id'],
                (int) $meta['sub_type_id']
            );
            $fromKeys[$fromKey] = $fromNo;
        }

        $edges = [];
        foreach ($fromKeys as $fromKey => $fromDocNo) {
            if (!isset($groupTips[$fromKey])) {
                continue;
            }

            $candidates = [];
            if (isset($groupTips[$fromKey])) {
                $candidates[$fromKey] = (int) $groupTips[$fromKey]['score'];
            }
            foreach ($groupTips as $key => $meta) {
                if (strcasecmp($meta['revised_from'], $fromDocNo) === 0) {
                    $candidates[$key] = max($candidates[$key] ?? 0, (int) $meta['score']);
                }
            }

            if (count($candidates) < 2) {
                continue;
            }

            $winner = $pickWinner($candidates);
            if ($winner === null) {
                continue;
            }

            if ($winner !== $fromKey) {
                $edges[$winner][$fromKey] = true;
            }

            foreach ($groupTips as $key => $meta) {
                if ($key === $winner || empty($meta['is_active'])) {
                    continue;
                }
                if (strcasecmp($meta['revised_from'], $fromDocNo) === 0
                    && strcasecmp($meta['doc_no'], $fromDocNo) !== 0) {
                    $edges[$winner][$key] = true;
                }
            }
        }

        // Same lineage root (shared revised_from): keep one family when renumber left stale links.
        $byRoot = [];
        foreach ($groupTips as $key => $meta) {
            if (empty($meta['is_active'])) {
                continue;
            }
            $from = $meta['revised_from'];
            if ($from === '' || strcasecmp($from, $meta['doc_no']) === 0) {
                continue;
            }
            $bucket = (int) $meta['doc_type_id'] . '||' . (int) $meta['sub_type_id'] . '||' . strtolower($from);
            $byRoot[$bucket][$key] = (int) $meta['score'];
        }

        foreach ($byRoot as $members) {
            if (count($members) < 2) {
                continue;
            }
            $winner = $pickWinner($members);
            if ($winner === null) {
                continue;
            }
            foreach (array_keys($members) as $key) {
                if ($key !== $winner && !empty($groupTips[$key]['is_active'])) {
                    $edges[$winner][$key] = true;
                }
            }
        }

        if ($visibleIds !== []) {
            self::applyDcnLineageMergeEdges($groupTips, $edges);
            self::applyRevisionFamilyMergeEdges($groupTips, $edges, $visibleIds);
        }

        return $edges;
    }

    /**
     * Fold lower-rev groups referenced in the winner's DCN "Documents for Revision" rows.
     *
     * @param array<string, array{doc_no: string, doc_type_id: int, sub_type_id: int, rev_no: int, request_id: int, revised_from: string, score: int, is_active?: bool}> $groupTips
     * @param array<string, array<string, true>> $edges
     */
    private static function applyDcnLineageMergeEdges(array $groupTips, array &$edges): void
    {
        foreach ($groupTips as $winnerKey => $winner) {
            if (empty($winner['is_active'])) {
                continue;
            }

            $winnerRev = (int) ($winner['rev_no'] ?? 0);
            $requestId = (int) ($winner['request_id'] ?? 0);
            if ($requestId < 1) {
                continue;
            }

            $typeId = (int) ($winner['doc_type_id'] ?? 0);
            $subId = (int) ($winner['sub_type_id'] ?? 0);

            foreach (self::dcnReferencedDocNosForRequest($requestId) as $refDocNo) {
                $refKey = self::docFamilyGroupKey($refDocNo, $typeId, $subId);
                if ($refKey === $winnerKey || !isset($groupTips[$refKey])) {
                    continue;
                }

                $refMeta = $groupTips[$refKey];
                if (empty($refMeta['is_active'])) {
                    continue;
                }
                if ((int) ($refMeta['rev_no'] ?? 0) >= $winnerRev) {
                    continue;
                }

                $edges[$winnerKey][$refKey] = true;
            }
        }
    }

    /**
     * Fold every lower-rev doc-no group in the same revision family under the family tip.
     *
     * @param array<string, array{doc_no: string, doc_type_id: int, sub_type_id: int, rev_no: int, request_id: int, revised_from: string, score: int}> $groupTips
     * @param array<string, array<string, true>> $edges
     * @param list<int> $visibleIds
     */
    private static function applyRevisionFamilyMergeEdges(array $groupTips, array &$edges, array $visibleIds): void
    {
        foreach ($groupTips as $winnerKey => $winner) {
            if (empty($winner['is_active'])) {
                continue;
            }
            $typeId = (int) ($winner['doc_type_id'] ?? 0);
            $subId = (int) ($winner['sub_type_id'] ?? 0) ?: null;

            $familyNos = self::revisionFamilyDocNos(
                (string) $winner['doc_no'],
                $typeId,
                $subId,
                $visibleIds
            );
            if ($familyNos === []) {
                continue;
            }

            if (!self::isRevisionFamilyTip($winnerKey, $winner, $groupTips, $familyNos, $typeId, $subId, $visibleIds)) {
                continue;
            }

            $familyLookup = [];
            foreach ($familyNos as $no) {
                $familyLookup[strtolower($no)] = true;
            }

            $winnerRev = (int) ($winner['rev_no'] ?? 0);
            foreach ($groupTips as $key => $meta) {
                if ($key === $winnerKey || empty($meta['is_active'])) {
                    continue;
                }
                if ((int) ($meta['doc_type_id'] ?? 0) !== $typeId) {
                    continue;
                }
                if (((int) ($meta['sub_type_id'] ?? 0) ?: null) !== ($subId ?: null)) {
                    continue;
                }
                if ((int) ($meta['rev_no'] ?? 0) >= $winnerRev) {
                    continue;
                }
                if (!isset($familyLookup[strtolower((string) ($meta['doc_no'] ?? ''))])) {
                    continue;
                }
                $edges[$winnerKey][$key] = true;
            }
        }
    }

    /**
     * @param array<string, array{doc_no: string, doc_type_id: int, sub_type_id: int, rev_no: int, request_id: int, revised_from: string, score: int}> $groupTips
     * @param list<string> $familyNos
     */
    private static function isRevisionFamilyTip(
        string $winnerKey,
        array $winner,
        array $groupTips,
        array $familyNos,
        int $docTypeId,
        ?int $subTypeId,
        array $visibleIds
    ): bool {
        $winnerRev = (int) ($winner['rev_no'] ?? 0);
        $familyLookup = [];
        foreach ($familyNos as $no) {
            $familyLookup[strtolower($no)] = true;
        }

        foreach ($groupTips as $key => $meta) {
            if ($key === $winnerKey || empty($meta['is_active'])) {
                continue;
            }
            if ((int) ($meta['doc_type_id'] ?? 0) !== $docTypeId) {
                continue;
            }
            if (((int) ($meta['sub_type_id'] ?? 0) ?: null) !== ($subTypeId ?: null)) {
                continue;
            }

            $otherRev = (int) ($meta['rev_no'] ?? 0);
            $otherNo = (string) ($meta['doc_no'] ?? '');
            $otherInFamily = isset($familyLookup[strtolower($otherNo)]);

            if (!$otherInFamily) {
                $otherFamily = self::revisionFamilyDocNos($otherNo, $docTypeId, $subTypeId, $visibleIds);
                $otherInFamily = in_array(strtolower((string) ($winner['doc_no'] ?? '')), array_map('strtolower', $otherFamily), true);
            }

            if ($otherInFamily && $otherRev > $winnerRev) {
                return false;
            }
        }

        return true;
    }

    /** @return list<int> */
    private static function reviseNosPresentForDocNos(
        array $docNos,
        int $docTypeId,
        ?int $subTypeId,
        array $visibleIds
    ): array {
        if ($docNos === []) {
            return [];
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $visibleIds)
            ->whereIn('ml.doc_no', $docNos)
            ->where('dr.doc_type_id', $docTypeId);

        if ($subTypeId) {
            $query->where('dr.sub_type_id', $subTypeId);
        } else {
            $query->whereNull('dr.sub_type_id');
        }
        if (Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            $query->whereNull('dr.deleted_at');
        }

        return $query
            ->pluck('ml.revise_no')
            ->map(fn ($n) => (int) $n)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * All doc numbers in a revision family (renumber chain + gap-filled renumbered revisions).
     *
     * @return list<string>
     */
    public static function revisionFamilyDocNos(
        string $docNo,
        int $docTypeId,
        ?int $subTypeId,
        array $requestIds,
        bool $includeTrashed = false
    ): array {
        $docNo = trim($docNo);
        if ($docNo === '') {
            return [];
        }

        $seen = [];
        foreach (self::docNosForRevisionLookup($docNo, $docTypeId, $subTypeId, $requestIds, $includeTrashed) as $no) {
            $seen[strtolower($no)] = $no;
        }

        $maxRev = 0;
        if ($seen !== []) {
            $maxRevQuery = DB::table('dcs_masterlist_registration as ml')
                ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                ->whereIn('ml.request_id', $requestIds)
                ->where(function ($q) use ($seen, $docNo) {
                    $q->whereIn('ml.doc_no', array_values($seen))
                        ->orWhere('ml.doc_no', $docNo);
                })
                ->where('dr.doc_type_id', $docTypeId);
            if ($subTypeId) {
                $maxRevQuery->where('dr.sub_type_id', $subTypeId);
            } else {
                $maxRevQuery->whereNull('dr.sub_type_id');
            }
            if (!$includeTrashed) {
                self::applyNotDeleted($maxRevQuery, 'dr');
            }
            $maxRev = (int) ($maxRevQuery->max('ml.revise_no') ?? 0);
        }

        if ($maxRev < 1) {
            return array_values($seen);
        }

        $presentRevs = self::reviseNosPresentForDocNos(array_values($seen), $docTypeId, $subTypeId, $requestIds);
        $changed = true;
        while ($changed) {
            $changed = false;
            for ($rev = 0; $rev < $maxRev; $rev++) {
                if (in_array($rev, $presentRevs, true)) {
                    continue;
                }

                $candidateQuery = DB::table('dcs_masterlist_registration as ml')
                    ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                    ->whereIn('ml.request_id', $requestIds)
                    ->where('ml.revise_no', $rev)
                    ->where('dr.doc_type_id', $docTypeId);
                if ($subTypeId) {
                    $candidateQuery->where('dr.sub_type_id', $subTypeId);
                } else {
                    $candidateQuery->whereNull('dr.sub_type_id');
                }
                self::applyNotDeleted($candidateQuery, 'dr');

                $candidates = $candidateQuery
                    ->distinct()
                    ->pluck('ml.doc_no')
                    ->map(fn ($no) => trim((string) $no))
                    ->filter(fn ($no) => $no !== '' && !isset($seen[strtolower($no)]))
                    ->values()
                    ->all();

                if ($candidates === []) {
                    continue;
                }

                $picked = count($candidates) === 1
                    ? $candidates[0]
                    : self::pickGapFillDocNo(
                        $candidates,
                        array_values($seen),
                        $requestIds,
                        $docTypeId,
                        $subTypeId,
                        $includeTrashed
                    );

                if ($picked === null) {
                    continue;
                }

                $seen[strtolower($picked)] = $picked;
                $presentRevs[] = $rev;
                $changed = true;

                foreach (self::docNosForRevisionLookup($picked, $docTypeId, $subTypeId, $requestIds, $includeTrashed) as $extra) {
                    $seen[strtolower($extra)] = $extra;
                }
            }
        }

        return array_values($seen);
    }

    /** @param list<string> $candidates @param list<string> $familyNos @param list<int> $requestIds */
    private static function pickGapFillDocNo(
        array $candidates,
        array $familyNos,
        array $requestIds,
        int $docTypeId,
        ?int $subTypeId,
        bool $includeTrashed = false
    ): ?string {
        $familyLookup = [];
        foreach ($familyNos as $no) {
            $familyLookup[strtolower($no)] = true;
        }

        $linked = [];
        if (self::supportsRevisedFromDocNo()) {
            foreach ($candidates as $docNo) {
                $from = trim((string) (DB::table('dcs_masterlist_registration')
                    ->where('doc_no', $docNo)
                    ->whereNotNull('revised_from_doc_no')
                    ->orderByDesc('revise_no')
                    ->value('revised_from_doc_no') ?? ''));
                if ($from !== '' && isset($familyLookup[strtolower($from)])) {
                    $linked[] = $docNo;
                }
            }
        }

        if (count($linked) === 1) {
            return $linked[0];
        }

        $predecessors = [];
        if (self::supportsRevisedFromDocNo()) {
            foreach ($candidates as $docNo) {
                $predQuery = DB::table('dcs_masterlist_registration as ml')
                    ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                    ->whereIn('ml.request_id', $requestIds)
                    ->where('dr.doc_type_id', $docTypeId)
                    ->whereRaw('LOWER(TRIM(ml.revised_from_doc_no)) = ?', [strtolower(trim($docNo))]);
                if ($subTypeId) {
                    $predQuery->where('dr.sub_type_id', $subTypeId);
                } else {
                    $predQuery->whereNull('dr.sub_type_id');
                }
                if (!$includeTrashed) {
                    self::applyNotDeleted($predQuery, 'dr');
                }
                if ($predQuery->exists()) {
                    $predecessors[] = $docNo;
                }
            }
        }

        if (count($predecessors) === 1) {
            return $predecessors[0];
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Fold prior doc-no families under one tip via revised_from_doc_no.
     * Each prior family is absorbed at most once (prevents duplicate obsolete rows
     * under unrelated documents after delete → renumber → restore).
     *
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $groups
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public static function mergeUpdateListLineageGroups(Collection $groups, array $visibleIds): Collection
    {
        if ($groups->isEmpty()
            || !Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
            return $groups;
        }

        $byKey = [];
        foreach ($groups as $g) {
            $p = $g['parent'];
            if (($p['doc_no'] ?? 'N/A') === 'N/A') {
                continue;
            }
            $byKey[self::docFamilyGroupKey(
                (string) $p['doc_no'],
                (int) $p['doc_type_id'],
                (int) ($p['sub_type_id'] ?? 0)
            )] = $g;
        }

        if ($byKey === []) {
            return $groups;
        }

        $parentIds = $groups
            ->pluck('parent.request_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $fromByRequest = DB::table('dcs_masterlist_registration')
            ->whereIn('request_id', $parentIds)
            ->pluck('revised_from_doc_no', 'request_id');

        $parentsByKey = [];
        foreach ($byKey as $key => $g) {
            $p = $g['parent'];
            $p['revised_from_doc_no'] = trim((string) ($fromByRequest[(int) ($p['request_id'] ?? 0)] ?? ''));
            $p['is_active'] = true;
            $parentsByKey[$key] = $p;
        }

        $edges = self::buildLineageMergeEdges(self::lineageGroupTipsFromParents($parentsByKey), $visibleIds);

        if ($edges === []) {
            return $groups;
        }

        $absorbedAsTopLevel = [];
        foreach ($edges as $priors) {
            foreach (array_keys($priors) as $fromKey) {
                $absorbedAsTopLevel[$fromKey] = true;
            }
        }

        $globallyMergedFrom = [];
        $merged = collect();

        foreach ($groups as $g) {
            $p = $g['parent'];
            if (($p['doc_no'] ?? 'N/A') === 'N/A') {
                $merged->push($g);
                continue;
            }
            $key = self::docFamilyGroupKey(
                (string) $p['doc_no'],
                (int) $p['doc_type_id'],
                (int) ($p['sub_type_id'] ?? 0)
            );
            if (isset($absorbedAsTopLevel[$key])) {
                continue;
            }

            $children = $g['children'];
            $memberIds = $g['member_ids'];
            $seen = [$key => true];
            $queue = array_keys($edges[$key] ?? []);

            while ($queue !== []) {
                $fromKey = array_shift($queue);
                if (isset($seen[$fromKey]) || !isset($byKey[$fromKey])) {
                    continue;
                }
                if (isset($globallyMergedFrom[$fromKey])) {
                    continue;
                }
                $seen[$fromKey] = true;
                $globallyMergedFrom[$fromKey] = $key;

                $prior = $byKey[$fromKey];
                $priorParent = $prior['parent'];
                $priorParent['is_latest'] = false;
                $priorParent['revision_status'] = 'obsolete';
                $priorParent['can_delete'] = false;
                $children[] = $priorParent;
                foreach ($prior['children'] as $c) {
                    $children[] = $c;
                }
                $memberIds = array_merge($memberIds, $prior['member_ids']);

                foreach (array_keys($edges[$fromKey] ?? []) as $nextFrom) {
                    $queue[] = $nextFrom;
                }
            }

            usort($children, fn ($a, $b) => ($b['rev_no'] <=> $a['rev_no']) ?: ($b['request_id'] <=> $a['request_id']));

            $stack = array_merge([$g['parent']], $children);
            usort($stack, fn ($a, $b) => ($b['rev_no'] <=> $a['rev_no']) ?: ($b['request_id'] <=> $a['request_id']));
            $tip = $stack[0];
            foreach ($stack as &$member) {
                $isTip = (int) $member['request_id'] === (int) $tip['request_id'];
                $member['is_latest'] = $isTip;
                $member['revision_status'] = $isTip ? 'latest' : 'obsolete';
                $member['can_delete'] = $isTip;
            }
            unset($member);

            $g['parent'] = $tip;
            $g['children'] = array_values(array_filter(
                $stack,
                fn ($r) => (int) $r['request_id'] !== (int) $tip['request_id']
            ));
            $g['has_revisions'] = $g['children'] !== [];
            $g['revision_count'] = 1 + count($g['children']);
            $g['member_ids'] = array_values(array_unique($memberIds));
            $g['sort_id'] = $tip['request_id'];
            $merged->push($g);
        }

        return $merged;
    }

    /**
     * Promote the active tip for each revision family at most once.
     * Prevents dual "Latest" rows when renumbered families were not folded yet.
     *
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $groups
     * @param list<int> $visibleIds
     */
    public static function promoteLatestForLineageGroups(Collection $groups, array $visibleIds): void
    {
        if ($groups->isEmpty()) {
            return;
        }

        $promotedFamilies = [];
        $promotedDocNos = [];
        $sortedGroups = $groups->sortByDesc(fn ($g) => (int) (($g['parent']['rev_no'] ?? 0)))->values();

        foreach ($sortedGroups as $g) {
            $p = $g['parent'] ?? null;
            if (!$p || ($p['doc_no'] ?? 'N/A') === 'N/A') {
                continue;
            }

            $docTypeId = (int) ($p['doc_type_id'] ?? 0);
            $subTypeId = !empty($p['sub_type_id']) ? (int) $p['sub_type_id'] : null;
            $docNo = (string) $p['doc_no'];

            $familyNos = self::revisionFamilyDocNos($docNo, $docTypeId, $subTypeId, $visibleIds, true);
            if ($familyNos === []) {
                $familyNos = [$docNo];
            }

            $alreadyPromoted = false;
            foreach ($familyNos as $no) {
                if (isset($promotedDocNos[strtolower(trim($no))])) {
                    $alreadyPromoted = true;
                    break;
                }
            }
            if ($alreadyPromoted) {
                continue;
            }

            $familyKey = strtolower(implode("\0", array_map(
                static fn ($no) => strtolower(trim($no)),
                $familyNos
            )));
            if (isset($promotedFamilies[$familyKey])) {
                continue;
            }

            $familyLookup = [];
            foreach ($familyNos as $no) {
                $familyLookup[strtolower($no)] = true;
            }

            $bestDocNo = $docNo;
            $bestRev = (int) ($p['rev_no'] ?? 0);
            if (!empty($p['is_deleted'])) {
                $bestRev = -1;
            }

            foreach ($sortedGroups as $other) {
                $op = $other['parent'] ?? null;
                if (!$op || ($op['doc_no'] ?? 'N/A') === 'N/A') {
                    continue;
                }
                if (!empty($op['is_deleted'])) {
                    continue;
                }

                $otherNo = strtolower((string) $op['doc_no']);
                $otherRev = (int) ($op['rev_no'] ?? 0);
                $inSameFamily = isset($familyLookup[$otherNo]);
                if (!$inSameFamily) {
                    $otherFamily = self::revisionFamilyDocNos(
                        (string) $op['doc_no'],
                        (int) ($op['doc_type_id'] ?? 0),
                        !empty($op['sub_type_id']) ? (int) $op['sub_type_id'] : null,
                        $visibleIds,
                        true
                    );
                    $inSameFamily = (bool) array_intersect(
                        array_map('strtolower', $familyNos),
                        array_map('strtolower', $otherFamily ?: [(string) $op['doc_no']])
                    );
                }

                if (!$inSameFamily) {
                    $requestId = (int) ($op['request_id'] ?? 0);
                    if ($requestId > 0) {
                        foreach (self::dcnReferencedDocNosForRequest($requestId) as $refNo) {
                            if (isset($familyLookup[strtolower($refNo)])) {
                                $inSameFamily = true;
                                break;
                            }
                        }
                    }
                }

                if ($inSameFamily && $otherRev > $bestRev) {
                    $bestRev = $otherRev;
                    $bestDocNo = (string) $op['doc_no'];
                }
            }

            $promotedFamilies[$familyKey] = true;
            RegisterPersistHelper::promoteLatestForDoc($bestDocNo, $docTypeId, $subTypeId);

            $expandedFamily = self::revisionFamilyDocNos($bestDocNo, $docTypeId, $subTypeId, $visibleIds, true);
            foreach ($expandedFamily ?: [$bestDocNo] as $no) {
                $promotedDocNos[strtolower(trim($no))] = true;
            }
        }
    }

    public static function updateList(string $search, string $docTypeId, int $page, int $perPage = 15): array
    {
        $empty = [
            'rows' => [],
            'total' => 0,
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $perPage,
        ];

        $visibleIds = self::visibleRequestIds();
        if ($visibleIds === []) {
            return $empty;
        }

        $query = DB::table('dcs_document_requests as dr')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_request_form as drf', 'drf.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_change_notice as dcn', 'dcn.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_retrieval as ret', 'ret.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_distribution as dist', 'dist.request_id', '=', 'dr.id')
            ->whereIn('dr.id', $visibleIds);
        self::applyNotDeleted($query, 'dr');
        $select = [
            'dr.id',
            'dr.doc_type_id',
            'dr.sub_type_id',
            'dt.doc_type_name',
            'ml.id as ml_id',
            'ml.doc_no',
            'ml.doc_title as ml_title',
            'ml.revise_no',
            'drf.doc_title as drf_title',
            'drf.id as drf_id',
            'dcn.id as dcn_id',
            'ret.id as ret_id',
            'dist.id as dist_id',
        ];
        if (self::supportsRevisionStatus()) {
            $select[] = 'ml.revision_status';
        }
        $query->select($select);

        if ($docTypeId !== '' && $docTypeId !== 'all') {
            $query->where('dr.doc_type_id', (int) $docTypeId);
        }

        $search = trim($search);
        $matchedIds = null;
        if ($search !== '') {
            $like = '%' . $search . '%';
            $matchQuery = (clone $query)->where(function ($q) use ($like) {
                $q->whereRaw('dr.id::text ilike ?', [$like])
                    ->orWhere('ml.doc_no', 'ilike', $like)
                    ->orWhere('ml.doc_title', 'ilike', $like)
                    ->orWhere('drf.drf_no', 'ilike', $like)
                    ->orWhere('drf.doc_title', 'ilike', $like)
                    ->orWhere('dcn.dcn_no', 'ilike', $like);
            });
            $matchedIds = $matchQuery->pluck('dr.id')->map(fn ($id) => (int) $id)->all();
            if ($matchedIds === []) {
                return $empty;
            }
        }

        $documents = $query->orderByDesc('dr.id')->get();

        $mapRow = function ($doc): array {
            $docNo = trim((string) ($doc->doc_no ?? ''));
            $title = $doc->ml_title ?: ($doc->drf_title ?: 'N/A');
            $status = strtolower(trim((string) ($doc->revision_status ?? '')));
            if ($status === '') {
                $status = 'latest';
            }

            return [
                'request_id' => (int) $doc->id,
                'doc_type_id' => (int) ($doc->doc_type_id ?? 0),
                'sub_type_id' => $doc->sub_type_id ? (int) $doc->sub_type_id : 0,
                'doc_no' => $docNo !== '' ? $docNo : 'N/A',
                'title' => $title,
                'rev_no' => (int) ($doc->revise_no ?? 0),
                'doc_type' => $doc->doc_type_name ?? 'N/A',
                'revision_status' => $status,
                'is_latest' => $status !== 'obsolete',
                'edit_url' => route('dcs.register.edit', $doc->id),
                'history_url' => $docNo !== '' ? route('dcs.register.history', $docNo) : null,
                'can_delete' => $status !== 'obsolete',
            ];
        };

        $rows = $documents->map($mapRow);

        $grouped = $rows->groupBy(function ($row) {
            if ($row['doc_no'] === 'N/A') {
                return 'no_ml_' . $row['request_id'];
            }

            return strtolower($row['doc_no']) . '||' . $row['doc_type_id'] . '||' . $row['sub_type_id'];
        });

        $groups = collect();
        foreach ($grouped as $family) {
            $sorted = $family->sortByDesc('rev_no')->sortByDesc('request_id')->values();

            // Heal: tip must be the highest revise_no (e.g. Rev 10 beats Rev 7).
            $tipRow = $sorted->first();
            if ($tipRow && ($tipRow['doc_no'] ?? 'N/A') !== 'N/A') {
                $latestRows = $family->filter(fn ($r) => !empty($r['is_latest']));
                $tipIsLatest = !empty($tipRow['is_latest']);
                $needsHeal = !$tipIsLatest
                    || $latestRows->count() !== 1
                    || (int) ($latestRows->first()['request_id'] ?? 0) !== (int) $tipRow['request_id'];

                if ($needsHeal) {
                    $family = $family->map(function ($r) use ($tipRow) {
                        $isTip = (int) $r['request_id'] === (int) $tipRow['request_id'];
                        $r['revision_status'] = $isTip ? 'latest' : 'obsolete';
                        $r['is_latest'] = $isTip;
                        $r['can_delete'] = $isTip;

                        return $r;
                    });
                    $sorted = $family->sortByDesc('rev_no')->sortByDesc('request_id')->values();
                }
            }

            $parent = $sorted->first(fn ($r) => !empty($r['is_latest'])) ?? $sorted->first();
            $children = $family
                ->filter(fn ($r) => $r['request_id'] !== $parent['request_id'])
                ->sortByDesc('rev_no')
                ->values()
                ->all();

            $groups->push([
                'doc_no' => $parent['doc_no'],
                'parent' => $parent,
                'children' => $children,
                'has_revisions' => $children !== [],
                'revision_count' => $family->count(),
                'sort_id' => $parent['request_id'],
                'member_ids' => $family->pluck('request_id')->all(),
            ]);
        }

        // Stack renumbered prior doc numbers under the tip (same as Database).
        $groups = self::mergeUpdateListLineageGroups($groups, $visibleIds);

        if ($matchedIds !== null) {
            $matchSet = array_flip($matchedIds);
            $groups = $groups->filter(function ($g) use ($matchSet) {
                foreach ($g['member_ids'] as $id) {
                    if (isset($matchSet[$id])) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        $groups = $groups->sortByDesc('sort_id')->values();

        $total = $groups->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $pageGroups = $groups->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return [
            'rows' => $pageGroups,
            'total' => $total,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
        ];
    }

    public static function recycleBinList(string $search, int $page, int $perPage = 15): array
    {
        if (!Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            return [
                'rows' => [],
                'total' => 0,
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $perPage,
                'retention_years' => self::RECYCLE_BIN_RETENTION_YEARS,
            ];
        }

        // Drop items past the 1-year retention window (same policy as Admin Console).
        RegisterUpdateHelper::purgeExpiredRecycleBin();

        $query = DB::table('dcs_document_requests as dr')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_request_form as drf', 'drf.request_id', '=', 'dr.id')
            ->whereNotNull('dr.deleted_at')
            ->orderByDesc('dr.deleted_at');
        self::applyOfficeScope($query, 'dr');

        $select = [
            'dr.id',
            'dr.deleted_at',
            'dt.doc_type_name',
            'ml.doc_no',
            'ml.doc_title as ml_title',
            'ml.revise_no',
            'drf.doc_title as drf_title',
        ];
        if (Schema::hasColumn('dcs_document_requests', 'deleted_by')) {
            $select[] = 'dr.deleted_by';
        }

        $query->select($select);

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('dr.id::text ilike ?', [$like])
                    ->orWhere('ml.doc_no', 'ilike', $like)
                    ->orWhere('ml.doc_title', 'ilike', $like)
                    ->orWhere('drf.doc_title', 'ilike', $like);
            });
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        $documents = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        $deletedByIds = $documents->pluck('deleted_by')->filter()->unique()->all();
        $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $deletedByNames = $deletedByIds && Schema::hasColumn('dcs_document_requests', 'deleted_by')
            ? DB::table($accDetailsTbl)
                ->whereIn('account_id', $deletedByIds)
                ->get()
                ->mapWithKeys(fn ($d) => [(int) $d->account_id => trim($d->first_name . ' ' . $d->last_name)])
            : collect();

        $now = Carbon::now();
        $rows = $documents->map(function ($doc) use ($deletedByNames, $now) {
            $title = $doc->ml_title ?: ($doc->drf_title ?: 'N/A');
            $deletedBy = isset($doc->deleted_by) && $doc->deleted_by
                ? ($deletedByNames[(int) $doc->deleted_by] ?? null)
                : null;
            $deletedAt = $doc->deleted_at ? Carbon::parse($doc->deleted_at) : null;
            $expiresAt = $deletedAt ? self::recycleBinExpiresAt($deletedAt) : null;
            $daysLeft = $expiresAt ? (int) $now->diffInDays($expiresAt, false) : null;

            return [
                'request_id' => $doc->id,
                'doc_no' => $doc->doc_no ?: 'N/A',
                'title' => $title,
                'rev_no' => (int) ($doc->revise_no ?? 0),
                'doc_type' => $doc->doc_type_name ?? 'N/A',
                'deleted_at' => $deletedAt
                    ? $deletedAt->format('M d, Y h:i A')
                    : '—',
                'deleted_by' => $deletedBy,
                'expires_at' => $expiresAt ? $expiresAt->format('M d, Y') : '—',
                'days_left' => $daysLeft,
            ];
        })->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'retention_years' => self::RECYCLE_BIN_RETENTION_YEARS,
        ];
    }

    public static function reviewList(string $search, string $docTypeId, int $page, int $perPage = 15): array
    {
        $empty = [
            'rows' => [],
            'total' => 0,
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $perPage,
            'filtered' => trim($search) !== '' || ($docTypeId !== '' && $docTypeId !== 'all'),
        ];

        $visibleIds = self::visibleRequestIds();
        if ($visibleIds === []) {
            return $empty;
        }

        // Prefer rows marked latest; fall back to highest revise_no / id per doc_no family.
        $latestMl = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $visibleIds)
            ->whereNotNull('ml.doc_no')
            ->where('ml.doc_no', '!=', '');
        self::applyNotDeleted($latestMl, 'dr');
        self::applyLatestRevisionStatus($latestMl, 'ml');
        $latestMl = $latestMl
            ->select('ml.doc_no', DB::raw('MAX(ml.id) as ml_id'))
            ->groupBy('ml.doc_no');

        $revCounts = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $visibleIds)
            ->whereNotNull('ml.doc_no')
            ->where('ml.doc_no', '!=', '');
        self::applyNotDeleted($revCounts, 'dr');
        $revCounts = $revCounts
            ->select('ml.doc_no', DB::raw('COUNT(*) as rev_count'))
            ->groupBy('ml.doc_no');

        $query = DB::table('dcs_masterlist_registration as ml')
            ->joinSub($latestMl, 'latest', function ($join) {
                $join->on('ml.id', '=', 'latest.ml_id');
            })
            ->joinSub($revCounts, 'rc', function ($join) {
                $join->on('rc.doc_no', '=', 'ml.doc_no');
            })
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_document_request_form as drf', 'drf.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_change_notice as dcn', 'dcn.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_retrieval as ret', 'ret.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_distribution as dist', 'dist.request_id', '=', 'dr.id')
            ->whereIn('dr.id', $visibleIds);
        self::applyNotDeleted($query, 'dr');
        $query->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->select([
                'dr.id as request_id',
                'ml.doc_no',
                'ml.doc_title',
                'ml.revise_no',
                'dr.doc_type_id',
                'dr.sub_type_id',
                'dt.doc_type_name',
                'rc.rev_count',
                'drf.id as drf_id',
                'dcn.id as dcn_id',
                'ml.id as ml_id',
                'ret.id as ret_id',
                'dist.id as dist_id',
            ]);

        if ($docTypeId !== '' && $docTypeId !== 'all') {
            $query->where('dr.doc_type_id', (int) $docTypeId);
        }

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('ml.doc_no', 'ilike', $like)
                    ->orWhere('ml.doc_title', 'ilike', $like);
            });
        }

        // Resolve renumber lineage tips so prior numbers don't appear as separate review rows.
        $candidates = $query->limit(120)->get();
        $hasKeywords = Schema::hasColumn('dcs_masterlist_registration', 'keywords');
        $tipRows = collect();
        $seenTipKeys = [];
        foreach ($candidates as $hit) {
            $tip = self::resolveLineageTipMasterlist($hit, $visibleIds);
            if (!$tip) {
                continue;
            }
            $key = strtolower(trim((string) ($tip->doc_no ?? ''))) . '|'
                . (int) ($tip->doc_type_id ?? 0) . '|'
                . (int) ($tip->sub_type_id ?? 0);
            if (isset($seenTipKeys[$key])) {
                continue;
            }
            $seenTipKeys[$key] = true;
            $enriched = self::loadSearchDocumentRow((int) $tip->id, $hasKeywords);
            if ($enriched) {
                $enriched->rev_count = (int) ($hit->rev_count ?? 0);
                $enriched->drf_id = $hit->drf_id ?? null;
                $enriched->dcn_id = $hit->dcn_id ?? null;
                $enriched->ret_id = $hit->ret_id ?? null;
                $enriched->dist_id = $hit->dist_id ?? null;
                $tipRows->push($enriched);
            }
        }

        // Recount revisions across the full renumber chain for each tip.
        $tipRows = $tipRows->map(function ($doc) use ($visibleIds) {
            $docTypeId = (int) ($doc->doc_type_id ?? 0);
            $subTypeId = !empty($doc->sub_type_id) ? (int) $doc->sub_type_id : null;
            $chain = RegisterQueryHelper::docNosForRevisionLookup(
                (string) $doc->doc_no,
                $docTypeId,
                $subTypeId,
                $visibleIds
            );
            $count = 0;
            foreach ($chain as $chainDocNo) {
                $count += count(self::masterlistRevisionsForDocNo($chainDocNo, $docTypeId, $subTypeId, $visibleIds));
            }
            $doc->rev_count = max($count, (int) ($doc->rev_count ?? 0));

            return $doc;
        })->values();

        $total = $tipRows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $documents = $tipRows->slice(($page - 1) * $perPage, $perPage)->values();

        $requestIds = $documents->pluck('request_id')->all();
        $drfIds = $requestIds ? DB::table('dcs_document_request_form')->whereIn('request_id', $requestIds)->pluck('request_id')->flip() : collect();
        $dcnIds = $requestIds ? DB::table('dcs_document_change_notice')->whereIn('request_id', $requestIds)->pluck('request_id')->flip() : collect();
        $distIds = $requestIds ? DB::table('dcs_document_distribution')->whereIn('request_id', $requestIds)->pluck('request_id')->flip() : collect();
        $retIds = $requestIds ? DB::table('dcs_document_retrieval')->whereIn('request_id', $requestIds)->pluck('request_id')->flip() : collect();

        $checklistNames = [
            1 => 'Document Request Form',
            2 => 'Document Change Notice',
            3 => 'Masterlist',
            4 => 'Retrieval',
            5 => 'Distribution',
        ];
        foreach (DB::table('dcs_checklist_types')->get(['id', 'checklist_name']) as $row) {
            $checklistNames[$row->id] = $row->checklist_name;
        }

        $rows = $documents->map(function ($doc) use ($checklistNames, $drfIds, $dcnIds, $distIds, $retIds) {
            $revCount = (int) ($doc->rev_count ?? 0);
            $canCompare = $revCount >= 2;
            $checklists = [];
            if (isset($drfIds[$doc->request_id])) {
                $checklists[] = $checklistNames[1] ?? 'DRF';
            }
            if (isset($dcnIds[$doc->request_id])) {
                $checklists[] = $checklistNames[2] ?? 'DCN';
            }
            $checklists[] = $checklistNames[3] ?? 'Masterlist';
            if (isset($retIds[$doc->request_id])) {
                $checklists[] = $checklistNames[4] ?? 'Retrieval';
            }
            if (isset($distIds[$doc->request_id])) {
                $checklists[] = $checklistNames[5] ?? 'Distribution';
            }

            return [
                'request_id' => (int) $doc->request_id,
                'doc_no' => (string) $doc->doc_no,
                'title' => $doc->doc_title ?: $doc->doc_no,
                'rev_no' => (int) ($doc->revise_no ?? 0),
                'rev_count' => $revCount,
                'can_compare' => $canCompare,
                'reason' => null,
                'doc_type' => $doc->type_name ?? 'N/A',
                'checklists' => $checklists,
            ];
        })->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'filtered' => $empty['filtered'],
        ];
    }

    public static function history(string $docNo): array
    {
        $docNo = trim($docNo);
        if ($docNo === '') {
            abort(404, 'Document not found.');
        }

        $anchor = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->where('ml.doc_no', $docNo);
        self::applyNotDeleted($anchor, 'dr');
        self::applyOfficeScope($anchor, 'dr');
        $anchor = $anchor
            ->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->select('ml.doc_no', 'ml.doc_title', 'dr.doc_type_id', 'dr.sub_type_id')
            ->first();

        if (!$anchor) {
            abort(404, 'Document not found.');
        }

        $visibleIds = self::visibleRequestIds();
        $docTypeId = (int) $anchor->doc_type_id;
        $subTypeId = $anchor->sub_type_id ? (int) $anchor->sub_type_id : null;
        $familyNos = self::expandRenumberFamily($docNo, $docTypeId, $subTypeId, $visibleIds);
        if ($familyNos === []) {
            $familyNos = self::buildRenumberChain($docNo, $docTypeId, $subTypeId, $visibleIds);
        }
        if ($familyNos === []) {
            $familyNos = [$docNo];
        }

        $mls = collect();
        foreach ($familyNos as $chainDocNo) {
            $query = DB::table('dcs_masterlist_registration as ml')
                ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                ->whereIn('ml.request_id', $visibleIds)
                ->where('ml.doc_no', $chainDocNo)
                ->where('dr.doc_type_id', $docTypeId);

            if ($subTypeId) {
                $query->where('dr.sub_type_id', $subTypeId);
            } else {
                $query->whereNull('dr.sub_type_id');
            }

            self::applyNotDeleted($query, 'dr');

            foreach ($query
                ->orderByDesc('ml.revise_no')
                ->orderByDesc('ml.id')
                ->get([
                    'ml.*',
                    'dr.version_id as request_version_id',
                    'dr.approval_status',
                    'dr.created_at as request_created_at',
                    'dr.created_by as request_created_by',
                ]) as $row) {
                $mls->push($row);
            }
        }

        $mls = $mls
            ->unique(fn ($row) => (int) $row->request_id)
            ->sort(function ($a, $b) {
                $revCmp = ((int) ($b->revise_no ?? 0)) <=> ((int) ($a->revise_no ?? 0));
                if ($revCmp !== 0) {
                    return $revCmp;
                }

                return ((int) ($b->id ?? 0)) <=> ((int) ($a->id ?? 0));
            })
            ->values();

        if ($mls->isEmpty()) {
            abort(404, 'Document not found.');
        }

        $tipRequestId = (int) $mls->first()->request_id;

        $requestIds = self::intIds($mls->pluck('request_id'));
        $docs = self::hydrateRequests(
            DB::table('dcs_document_requests')->whereIn('id', $requestIds)->get()
        )->keyBy(fn ($d) => (int) $d->id);

        $drfIds = $docs->map(fn ($d) => $d->documentRequestForm->id ?? null)->filter()->values()->all();
        $drfOffices = $drfIds
            ? DB::table('dcs_drf_offices as d')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
                ->whereIn('d.document_request_form_id', $drfIds)
                ->get(['d.document_request_form_id', 'o.office_name'])
                ->groupBy('document_request_form_id')
            : collect();

        $dcnIds = $docs->map(fn ($d) => $d->documentChangeNotice->id ?? null)->filter()->values()->all();
        $dcnOffices = $dcnIds
            ? DB::table('dcs_dcn_offices as d')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
                ->whereIn('d.dcn_id', $dcnIds)
                ->get(['d.dcn_id', 'o.office_name'])
                ->groupBy('dcn_id')
            : collect();

        foreach ($docs as $doc) {
            if ($doc->documentRequestForm) {
                $doc->documentRequestForm->offices = $drfOffices->get($doc->documentRequestForm->id, collect());
            }
            if ($doc->documentChangeNotice) {
                $doc->documentChangeNotice->offices = $dcnOffices->get($doc->documentChangeNotice->id, collect());
            }
        }

        $lookups = [
            'versions' => DB::table('dcs_version_type')->pluck('version_name', 'id'),
            'approvals' => DB::table('dcs_approval_body')->pluck('approval_name', 'id'),
            'colleges' => DB::table('dcs_colleges')->pluck('college_name', 'id'),
            'programs' => DB::table('dcs_programs')->pluck('program_name', 'id'),
            'semesters' => DB::table('dcs_semesters')->pluck('semester_name', 'id'),
            'years' => DB::table('dcs_school_years')->pluck('school_year', 'id'),
            'creators' => collect(),
        ];

        $creatorIds = $mls->pluck('request_created_by')->merge($mls->pluck('created_by'))->filter()->unique()->all();
        if ($creatorIds) {
            $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
            $lookups['creators'] = DB::table($accDetailsTbl)
                ->whereIn('account_id', $creatorIds)
                ->get()
                ->mapWithKeys(fn ($d) => [(int) $d->account_id => trim($d->first_name . ' ' . $d->last_name)]);
        }

        $revisions = [];
        foreach ($mls as $ml) {
            $doc = $docs->get((int) $ml->request_id);
            $isTip = (int) $ml->request_id === $tipRequestId;
            $rev = self::buildHistoryRevision($ml, $doc, $lookups, $isTip);
            $rev['is_latest'] = $isTip;
            $revisions[] = $rev;
        }

        $lineageDocNos = $mls->pluck('doc_no')->filter()->unique()->values()->all();

        $noiseKeys = ['revise_no', 'registered_at', 'registered_by'];
        $summaryKeys = ['doc_type', 'sub_type', 'originator', 'effectivity', 'pages', 'purpose', 'deadline', 'drf_no', 'dcn_no'];

        for ($i = 0; $i < count($revisions); $i++) {
            $older = $revisions[$i + 1] ?? null;
            $changes = [];
            foreach ($revisions[$i]['sections'] as $s => $section) {
                $olderRows = [];
                if ($older) {
                    foreach ($older['sections'] as $os) {
                        if ($os['key'] === $section['key']) {
                            foreach ($os['rows'] as $or) {
                                $olderRows[$or['key']] = $or;
                            }
                        }
                    }
                }
                $sectionChanged = false;
                foreach ($section['rows'] as $r => $row) {
                    $prev = $olderRows[$row['key']] ?? null;
                    $isChanged = $older !== null
                        && ($prev === null || ($prev['compare'] ?? '') !== ($row['compare'] ?? ''));
                    $revisions[$i]['sections'][$s]['rows'][$r]['changed'] = $isChanged;
                    $revisions[$i]['sections'][$s]['rows'][$r]['previous'] = $isChanged
                        ? ($prev['value'] ?? '—')
                        : null;
                    if ($isChanged && !in_array($row['key'], $noiseKeys, true)) {
                        $changes[] = $revisions[$i]['sections'][$s]['rows'][$r];
                        $sectionChanged = true;
                    }
                }
                $revisions[$i]['sections'][$s]['changed'] = $sectionChanged;
                if (($section['key'] ?? '') === 'syllabi') {
                    $revisions[$i]['sections'][$s]['table'] = self::hstSyllabiTableFromRows(
                        $revisions[$i]['sections'][$s]['rows']
                    );
                }
            }
            $revisions[$i]['is_initial'] = $older === null;
            $revisions[$i]['changes'] = $changes;
            $revisions[$i]['changed_count'] = count($changes);
            $revisions[$i]['summary'] = self::hstPickRows($revisions[$i]['sections'], $summaryKeys);
        }

        return [
            'docNo' => $docNo,
            'docTitle' => $mls->first()->doc_title ?: $docNo,
            'lineageDocNos' => $lineageDocNos,
            'revisions' => $revisions,
        ];
    }

    public static function reviewCompare(string $docNo, ?int $leftId = null, ?int $rightId = null, ?string $extractTab = null): array
    {
        $empty = [
            'docNo' => trim($docNo),
            'docTitle' => '',
            'options' => [],
            'pair_options' => [],
            'prior_options' => [],
            'left_id' => null,
            'right_id' => null,
            'latest_id' => null,
            'latest_revise_no' => null,
            'tabs' => [],
            'pairs' => [],
            'can_compare' => false,
            'can_view' => false,
            'error' => null,
            'left_label' => 'Older revision',
            'right_label' => 'Newer revision',
        ];

        $docNo = trim($docNo);
        if ($docNo === '' || strcasecmp($docNo, 'N/A') === 0) {
            return array_merge($empty, ['error' => 'no_doc_no']);
        }

        if (!DB::table('dcs_masterlist_registration')->where('doc_no', $docNo)->exists()) {
            return array_merge($empty, ['error' => 'not_found']);
        }

        $history = self::history($docNo);
        $revs = $history['revisions']; // highest revise_no first
        $byId = [];
        foreach ($revs as $rev) {
            $byId[(int) $rev['id']] = $rev;
        }

        $options = [];
        foreach ($revs as $rev) {
            $label = 'Rev ' . $rev['revise_no'];
            if (!empty($rev['is_latest'])) {
                $label .= ' · current';
            }
            if (!empty($rev['doc_no']) && $rev['doc_no'] !== $history['docNo']) {
                $label .= ' · ' . $rev['doc_no'];
            }
            $options[] = [
                'id' => (int) $rev['id'],
                'revise_no' => (int) $rev['revise_no'],
                'is_latest' => (bool) $rev['is_latest'],
                'doc_no' => $rev['doc_no'] ?? null,
                'created_label' => $rev['created_label'] ?? '',
                'label' => $label,
            ];
        }

        $latest = $revs[0] ?? null;
        $latestId = $latest ? (int) $latest['id'] : null;

        // Ascending by revise_no → consecutive neighbors (0→1, 3→5 when 4 missing, …).
        $asc = array_values(array_reverse($revs));
        $pairOptions = [];
        for ($i = 0; $i < count($asc) - 1; $i++) {
            $older = $asc[$i];
            $newer = $asc[$i + 1];
            $pairOptions[] = [
                'left_id' => (int) $older['id'],
                'right_id' => (int) $newer['id'],
                'left_rev' => (int) $older['revise_no'],
                'right_rev' => (int) $newer['revise_no'],
                'label' => 'Rev ' . (int) $older['revise_no'] . ' → Rev ' . (int) $newer['revise_no'],
                'key' => (int) $older['id'] . ':' . (int) $newer['id'],
            ];
        }

        $base = [
            'docNo' => $history['docNo'],
            'docTitle' => $history['docTitle'],
            'options' => $options,
            'pair_options' => $pairOptions,
            'prior_options' => [],
            'left_id' => null,
            'right_id' => null,
            'latest_id' => $latestId,
            'latest_revise_no' => $latest ? (int) $latest['revise_no'] : null,
            'tabs' => [],
            'pairs' => [],
            'can_compare' => false,
            'can_view' => false,
            'error' => null,
            'left_label' => 'Older revision',
            'right_label' => 'Newer revision',
        ];

        if (!$latest) {
            return array_merge($base, ['error' => 'not_found']);
        }

        if (count($revs) < 2 || $pairOptions === []) {
            $pair = self::reviewPairSection(null, $byId[$latestId], 'masterlist');
            $hasScan = !empty($pair['right_scan']['url']);

            return array_merge($base, [
                'right_id' => $latestId,
                'tabs' => $hasScan ? [['key' => 'masterlist', 'label' => 'Masterlist']] : [],
                'pairs' => $hasScan ? ['masterlist' => $pair] : [],
                'can_view' => $hasScan,
                'can_compare' => false,
                'right_label' => 'Rev ' . (int) $latest['revise_no'] . ( !empty($latest['is_latest']) ? ' · current' : ''),
                'error' => $hasScan ? null : 'need_scan',
            ]);
        }

        $chosen = self::resolveConsecutivePair($pairOptions, $leftId, $rightId);
        $left = $byId[$chosen['left_id']];
        $right = $byId[$chosen['right_id']];

        $leftLabel = 'Rev ' . (int) $left['revise_no']
            . (!empty($left['doc_no']) && $left['doc_no'] !== $history['docNo'] ? ' · ' . $left['doc_no'] : '')
            . ' · older';
        $rightLabel = 'Rev ' . (int) $right['revise_no']
            . (!empty($right['is_latest']) ? ' · current' : '')
            . ' · newer';

        $pair = self::reviewPairSection($left, $right, 'masterlist');
        $leftHasScan = !empty($pair['left_scan']['url']);
        $rightHasScan = !empty($pair['right_scan']['url']);
        $hasScan = $leftHasScan || $rightHasScan;
        $bothPdf = !empty($pair['left_scan']['is_pdf']) && !empty($pair['right_scan']['is_pdf'])
            && $leftHasScan && $rightHasScan;

        $newerOptions = [];
        foreach ($pairOptions as $po) {
            $newerOptions[] = [
                'id' => $po['right_id'],
                'left_id' => $po['left_id'],
                'revise_no' => $po['right_rev'],
                'label' => 'Rev ' . $po['right_rev'] . ($po['right_id'] === $latestId ? ' · current' : ''),
                'pair_label' => $po['label'],
            ];
        }

        return [
            'docNo' => $history['docNo'],
            'docTitle' => $history['docTitle'],
            'options' => $options,
            'pair_options' => $pairOptions,
            'prior_options' => $newerOptions,
            'left_id' => $chosen['left_id'],
            'right_id' => $chosen['right_id'],
            'latest_id' => $latestId,
            'latest_revise_no' => (int) $latest['revise_no'],
            'tabs' => $hasScan ? [['key' => 'masterlist', 'label' => 'Masterlist']] : [],
            'pairs' => $hasScan ? ['masterlist' => $pair] : [],
            'can_compare' => $bothPdf,
            'can_view' => $hasScan,
            'error' => $hasScan ? null : 'need_scan',
            'left_label' => $leftLabel,
            'right_label' => $rightLabel,
        ];
    }

    /**
     * Pick a consecutive revise_no pair. Defaults to the tip pair (… → latest).
     *
     * @param  list<array{left_id:int,right_id:int,left_rev:int,right_rev:int,label:string,key:string}>  $pairOptions
     * @return array{left_id:int,right_id:int,left_rev:int,right_rev:int,label:string,key:string}
     */
    private static function resolveConsecutivePair(array $pairOptions, ?int $leftId, ?int $rightId): array
    {
        $fallback = $pairOptions[count($pairOptions) - 1];

        if ($leftId && $rightId) {
            foreach ($pairOptions as $po) {
                if ((int) $po['left_id'] === $leftId && (int) $po['right_id'] === $rightId) {
                    return $po;
                }
            }
        }

        // Prefer matching the newer side, then snap older to its predecessor.
        if ($rightId) {
            foreach ($pairOptions as $po) {
                if ((int) $po['right_id'] === $rightId) {
                    return $po;
                }
            }
        }

        if ($leftId) {
            foreach ($pairOptions as $po) {
                if ((int) $po['left_id'] === $leftId) {
                    return $po;
                }
            }
        }

        return $fallback;
    }

    private static function reviewRevIsOlder(array $left, array $right): bool
    {
        $leftRev = (int) ($left['revise_no'] ?? 0);
        $rightRev = (int) ($right['revise_no'] ?? 0);
        if ($leftRev !== $rightRev) {
            return $leftRev < $rightRev;
        }

        return strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? '')) <= 0;
    }

    private static function reviewPairSection(?array $left, ?array $right, string $key): array
    {
        $leftSection = self::reviewSectionByKey($left, $key);
        $rightSection = self::reviewSectionByKey($right, $key);
        $skip = ['scanned_ml', 'drf_scan', 'dcn_scan', 'dist_scan', 'ret_scan'];

        $rows = [];
        if ($key !== 'syllabi') {
            $leftMap = self::reviewRowMap($leftSection);
            $rightMap = self::reviewRowMap($rightSection);
            $order = [];
            foreach (array_merge(array_keys($leftMap), array_keys($rightMap)) as $rowKey) {
                if (in_array($rowKey, $skip, true) || isset($order[$rowKey])) {
                    continue;
                }
                $order[$rowKey] = true;
            }
            foreach (array_keys($order) as $rowKey) {
                $l = $leftMap[$rowKey] ?? null;
                $r = $rightMap[$rowKey] ?? null;
                if (($l['kind'] ?? $r['kind'] ?? '') === 'offices' || in_array($rowKey, ['sources', 'drf_offices', 'dcn_offices', 'dist_offices', 'ret_offices'], true)) {
                    $rows[] = self::reviewOfficePair($l, $r);
                    continue;
                }
                $leftVal = $l['value'] ?? '—';
                $rightVal = $r['value'] ?? '—';
                $rows[] = array_merge(self::reviewInlineDiff($leftVal, $rightVal), [
                    'key' => $rowKey,
                    'label' => $l['label'] ?? $r['label'] ?? $rowKey,
                ]);
            }
        }

        $syllabi = null;
        if ($key === 'syllabi') {
            $leftTable = $leftSection['table'] ?? self::hstSyllabiTableFromRows($leftSection['rows'] ?? []);
            $rightTable = $rightSection['table'] ?? self::hstSyllabiTableFromRows($rightSection['rows'] ?? []);
            $meta = [];
            $leftMeta = self::reviewRowMap(['rows' => $leftTable['meta'] ?? []]);
            $rightMeta = self::reviewRowMap(['rows' => $rightTable['meta'] ?? []]);
            foreach (['syl_college', 'syl_program', 'syl_sem', 'syl_sy'] as $metaKey) {
                if (!isset($leftMeta[$metaKey]) && !isset($rightMeta[$metaKey])) {
                    continue;
                }
                $l = $leftMeta[$metaKey] ?? null;
                $r = $rightMeta[$metaKey] ?? null;
                $meta[] = array_merge(self::reviewInlineDiff($l['value'] ?? '—', $r['value'] ?? '—'), [
                    'key' => $metaKey,
                    'label' => $l['label'] ?? $r['label'] ?? $metaKey,
                ]);
            }

            $fields = ['course', 'avail', 'copies', 'pages', 'received', 'faculty', 'drf'];
            $leftCourses = [];
            foreach ($leftTable['courses'] ?? [] as $course) {
                $name = $course['course']['compare'] ?? mb_strtolower($course['course']['value'] ?? '');
                $leftCourses[$name !== '' ? $name : uniqid('l.', true)] = $course;
            }
            $rightCourses = [];
            foreach ($rightTable['courses'] ?? [] as $course) {
                $name = $course['course']['compare'] ?? mb_strtolower($course['course']['value'] ?? '');
                $rightCourses[$name !== '' ? $name : uniqid('r.', true)] = $course;
            }
            $names = array_unique(array_merge(array_keys($leftCourses), array_keys($rightCourses)));
            $courses = [];
            foreach ($names as $name) {
                $lc = $leftCourses[$name] ?? null;
                $rc = $rightCourses[$name] ?? null;
                $cells = [];
                $rowStatus = 'same';
                foreach ($fields as $field) {
                    $diff = self::reviewInlineDiff($lc[$field]['value'] ?? '—', $rc[$field]['value'] ?? '—');
                    $cells[$field] = $diff;
                    if ($diff['status'] !== 'same' && $rowStatus === 'same') {
                        $rowStatus = $diff['status'];
                    } elseif ($diff['status'] !== 'same' && $diff['status'] !== $rowStatus) {
                        $rowStatus = 'changed';
                    }
                }
                $courses[] = ['status' => $rowStatus, 'cells' => $cells];
            }
            $syllabi = ['meta' => $meta, 'courses' => $courses];
        }

        $leftScan = self::reviewScanPayload($leftSection);
        $rightScan = self::reviewScanPayload($rightSection);
        $textDiff = self::reviewScanDiff($leftScan, $rightScan);

        return [
            'title' => $leftSection['title'] ?? $rightSection['title'] ?? ucfirst($key),
            'rows' => $rows,
            'syllabi' => $syllabi,
            'left_scan' => $leftScan,
            'right_scan' => $rightScan,
            'text_diff' => $textDiff,
            'scan_status' => $textDiff['status'] ?? 'none',
        ];
    }

    private static function reviewSectionByKey(?array $rev, string $key): ?array
    {
        if (!$rev) {
            return null;
        }
        foreach ($rev['sections'] as $section) {
            if (($section['key'] ?? '') === $key) {
                return $section;
            }
        }

        return null;
    }

    private static function reviewRowMap(?array $section): array
    {
        $map = [];
        foreach ($section['rows'] ?? [] as $row) {
            $map[$row['key']] = $row;
        }

        return $map;
    }

    private static function reviewOfficePair(?array $left, ?array $right): array
    {
        $leftMap = self::reviewOfficeItemMap($left);
        $rightMap = self::reviewOfficeItemMap($right);
        $withCopies = (bool) ($left['with_copies'] ?? $right['with_copies'] ?? false);
        if (!$withCopies) {
            foreach (array_merge($leftMap, $rightMap) as $item) {
                if (($item['copies'] ?? null) !== null) {
                    $withCopies = true;
                    break;
                }
            }
        }

        $names = array_unique(array_merge(array_keys($leftMap), array_keys($rightMap)));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        $offices = [];
        $status = 'same';
        foreach ($names as $key) {
            $l = $leftMap[$key] ?? null;
            $r = $rightMap[$key] ?? null;
            $name = $l['name'] ?? $r['name'] ?? $key;
            $leftCopies = $l['copies'] ?? null;
            $rightCopies = $r['copies'] ?? null;
            $leftPresent = $l !== null;
            $rightPresent = $r !== null;

            if ($leftPresent && $rightPresent) {
                if ($withCopies && (string) $leftCopies !== (string) $rightCopies) {
                    $rowStatus = 'changed';
                    $leftHtml = '<span class="drr-chg">' . e((string) $leftCopies) . '</span>';
                    $rightHtml = '<span class="drr-chg">' . e((string) $rightCopies) . '</span>';
                } else {
                    $rowStatus = 'same';
                    $leftHtml = $withCopies ? e((string) ($leftCopies ?? '1')) : 'Present';
                    $rightHtml = $withCopies ? e((string) ($rightCopies ?? '1')) : 'Present';
                }
            } elseif ($leftPresent) {
                $rowStatus = 'removed';
                $leftHtml = $withCopies
                    ? '<span class="drr-del">' . e((string) ($leftCopies ?? '1')) . '</span>'
                    : '<span class="drr-del">Present</span>';
                $rightHtml = '<span class="drr-del">Removed</span>';
            } else {
                $rowStatus = 'added';
                $leftHtml = '—';
                $rightHtml = $withCopies
                    ? '<span class="drr-ins">' . e((string) ($rightCopies ?? '1')) . '</span>'
                    : '<span class="drr-ins">Present</span>';
            }

            if ($rowStatus !== 'same' && $status === 'same') {
                $status = $rowStatus;
            } elseif ($rowStatus !== 'same' && $rowStatus !== $status) {
                $status = 'changed';
            }

            $offices[] = [
                'name' => $name,
                'status' => $rowStatus,
                'left_html' => $leftHtml,
                'right_html' => $rightHtml,
            ];
        }

        return [
            'key' => $left['key'] ?? $right['key'] ?? 'offices',
            'label' => $left['label'] ?? $right['label'] ?? 'Offices',
            'kind' => 'offices',
            'with_copies' => $withCopies,
            'status' => $status,
            'offices' => $offices,
            'left_html' => '',
            'right_html' => '',
        ];
    }

    private static function reviewOfficeItemMap(?array $row): array
    {
        if (!$row) {
            return [];
        }

        $map = [];
        foreach ($row['items'] ?? [] as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $map[mb_strtolower($name)] = [
                'name' => $name,
                'copies' => $item['copies'] ?? null,
            ];
        }
        if ($map !== []) {
            return $map;
        }

        $value = trim((string) ($row['value'] ?? ''));
        if ($value === '' || $value === '—') {
            return [];
        }

        foreach (preg_split('/\s*,\s*/', $value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $copies = null;
            $name = $part;
            if (preg_match('/^(.*)\s+\((\d+)\)\s*$/u', $part, $match)) {
                $name = trim($match[1]);
                $copies = $match[2];
            }
            if ($name === '') {
                continue;
            }
            $map[mb_strtolower($name)] = [
                'name' => $name,
                'copies' => $copies,
            ];
        }

        return $map;
    }

    private static function reviewScanPayload(?array $section): array
    {
        $path = $section['scan_path'] ?? null;
        $url = $section['scan_url'] ?? null;
        $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

        return [
            'path' => $path,
            'url' => $url,
            'name' => $path ? basename($path) : null,
            'is_pdf' => $ext === 'pdf',
            'text' => '',
            'has_text' => false,
        ];
    }

    private static function reviewScanDiff(array $leftScan, array $rightScan): array
    {
        $leftHas = (bool) ($leftScan['url'] ?? null);
        $rightHas = (bool) ($rightScan['url'] ?? null);
        $leftText = (string) ($leftScan['text'] ?? '');
        $rightText = (string) ($rightScan['text'] ?? '');

        if (!$leftHas && !$rightHas) {
            return [
                'status' => 'none',
                'left_html' => '',
                'right_html' => '',
                'has_text' => false,
                'note' => null,
            ];
        }

        if ($leftText !== '' || $rightText !== '') {
            $diff = self::reviewLineDiff($leftText, $rightText);
            $diff['has_text'] = true;
            $diff['note'] = null;

            return $diff;
        }

        $status = 'same';
        if ($leftHas && !$rightHas) {
            $status = 'removed';
        } elseif (!$leftHas && $rightHas) {
            $status = 'added';
        } elseif (($leftScan['path'] ?? '') !== ($rightScan['path'] ?? '')) {
            $status = 'changed';
        }

        return [
            'status' => $status,
            'left_html' => '',
            'right_html' => '',
            'has_text' => false,
            'note' => 'No extractable text on these scans. Only file presence could be compared.',
        ];
    }

    private static function reviewLineDiff(string $old, string $new): array
    {
        $oldLines = preg_split("/\r\n|\r|\n/", $old) ?: [];
        $newLines = preg_split("/\r\n|\r|\n/", $new) ?: [];
        $oldLines = array_slice($oldLines, 0, 250);
        $newLines = array_slice($newLines, 0, 250);

        if (implode("\n", $oldLines) === implode("\n", $newLines)) {
            $html = e($old !== '' ? $old : '—');

            return [
                'status' => 'same',
                'left_html' => $html,
                'right_html' => $html,
            ];
        }

        $ops = self::reviewLcsOps($oldLines, $newLines);
        $left = '';
        $right = '';
        foreach ($ops as $op) {
            $line = (string) $op['t'];
            if ($op['k'] === 'eq') {
                $left .= e($line) . "\n";
                $right .= e($line) . "\n";
            } elseif ($op['k'] === 'del') {
                $left .= '<span class="drr-del">' . e($line) . "</span>\n";
            } else {
                $right .= '<span class="drr-ins">' . e($line) . "</span>\n";
            }
        }

        $status = 'changed';
        if (trim($old) === '') {
            $status = 'added';
        } elseif (trim($new) === '') {
            $status = 'removed';
        }

        return [
            'status' => $status,
            'left_html' => rtrim($left),
            'right_html' => rtrim($right),
        ];
    }

    private static function reviewInlineDiff(string $old, string $new): array
    {
        $oldPlain = $old === '—' ? '' : $old;
        $newPlain = $new === '—' ? '' : $new;

        if ($oldPlain === $newPlain) {
            $safe = e($old !== '' ? $old : '—');

            return [
                'status' => 'same',
                'left_html' => $safe,
                'right_html' => $safe,
                'left' => $old !== '' ? $old : '—',
                'right' => $new !== '' ? $new : '—',
            ];
        }

        if ($oldPlain === '' && $newPlain !== '') {
            return [
                'status' => 'added',
                'left_html' => '—',
                'right_html' => '<span class="drr-ins">' . e($new) . '</span>',
                'left' => '—',
                'right' => $new,
            ];
        }

        if ($newPlain === '' && $oldPlain !== '') {
            return [
                'status' => 'removed',
                'left_html' => '<span class="drr-del">' . e($old) . '</span>',
                'right_html' => '—',
                'left' => $old,
                'right' => '—',
            ];
        }

        [$leftHtml, $rightHtml] = self::reviewTokenDiffHtml($oldPlain, $newPlain);

        return [
            'status' => 'changed',
            'left_html' => $leftHtml,
            'right_html' => $rightHtml,
            'left' => $old,
            'right' => $new,
        ];
    }

    private static function reviewTokenDiffHtml(string $old, string $new): array
    {
        $a = self::reviewTokens($old);
        $b = self::reviewTokens($new);
        $a = array_slice($a, 0, 800);
        $b = array_slice($b, 0, 800);
        $ops = self::reviewLcsOps($a, $b);

        $left = '';
        $right = '';
        foreach ($ops as $op) {
            $token = e($op['t']);
            if ($op['k'] === 'eq') {
                $left .= $token;
                $right .= $token;
            } elseif ($op['k'] === 'del') {
                $left .= '<span class="drr-del">' . $token . '</span>';
            } else {
                $right .= '<span class="drr-ins">' . $token . '</span>';
            }
        }

        return [trim($left), trim($right)];
    }

    private static function reviewTokens(string $text): array
    {
        $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        return array_values(array_filter($parts === false ? [] : $parts, fn ($part) => $part !== ''));
    }

    private static function reviewLcsOps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $dp[$i][$j] = $a[$i - 1] === $b[$j - 1]
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        $ops = [];
        $i = $n;
        $j = $m;
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
                $ops[] = ['k' => 'eq', 't' => $a[$i - 1]];
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $dp[$i][$j - 1] >= $dp[$i - 1][$j])) {
                $ops[] = ['k' => 'ins', 't' => $b[$j - 1]];
                $j--;
            } else {
                $ops[] = ['k' => 'del', 't' => $a[$i - 1]];
                $i--;
            }
        }

        return array_reverse($ops);
    }

    private static function hstRow(string $key, string $label, mixed $value, array $extra = []): array
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            $text = '—';
        }

        return array_merge([
            'key' => $key,
            'label' => $label,
            'value' => $text,
            'compare' => mb_strtolower($text === '—' ? '' : $text),
        ], $extra);
    }

    private static function hstDate(mixed $val): string
    {
        return $val ? Carbon::parse($val)->format('M d, Y') : '—';
    }

    private static function hstDateTime(mixed $val): string
    {
        return $val ? Carbon::parse($val)->format('M d, Y · h:i A') : '—';
    }

    private static function hstTime(mixed $val): string
    {
        return $val ? Carbon::parse($val)->format('h:i A') : '—';
    }

    private static function hstFile(mixed $path): string
    {
        return $path ? basename((string) $path) : '—';
    }

    private static function hstOfficeItems($rows, bool $withCopies = false): array
    {
        if (!$rows || (method_exists($rows, 'isEmpty') && $rows->isEmpty())) {
            return [];
        }

        $items = [];
        foreach (collect($rows) as $row) {
            $name = trim((string) ($row->office->office_name ?? $row->office_name ?? ''));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            $items[$key] = [
                'name' => $name,
                'copies' => $withCopies ? (string) max(1, (int) ($row->copies ?? 1)) : null,
            ];
        }

        return array_values($items);
    }

    private static function hstOfficeRow(string $key, string $label, $rows, bool $withCopies = false): array
    {
        $items = self::hstOfficeItems($rows, $withCopies);
        $value = $items === []
            ? '—'
            : collect($items)->map(function ($item) {
                return $item['copies'] !== null ? $item['name'] . ' (' . $item['copies'] . ')' : $item['name'];
            })->implode(', ');

        return self::hstRow($key, $label, $value, [
            'kind' => 'offices',
            'items' => $items,
            'with_copies' => $withCopies,
        ]);
    }

    private static function hstFilledRows(array $rows): array
    {
        return array_values(array_filter($rows, function ($row) {
            $value = trim((string) ($row['value'] ?? ''));

            return $value !== '' && $value !== '—';
        }));
    }

    private static function hstScanMeta(?string $path): array
    {
        $path = $path ? trim($path) : '';
        if ($path === '') {
            return ['scan_path' => null, 'scan_url' => null];
        }

        return [
            'scan_path' => $path,
            'scan_url' => self::scanUrl($path),
        ];
    }

    private static function hstPushSection(array &$sections, string $key, string $title, array $rows, array $extra = []): void
    {
        $rows = self::hstFilledRows($rows);
        if ($rows === [] && empty($extra['scan_path'])) {
            return;
        }

        $sections[] = array_merge([
            'key' => $key,
            'title' => $title,
            'rows' => $rows,
            'scan_path' => null,
            'scan_url' => null,
        ], $extra);
    }

    private static function buildHistoryRevision(object $ml, ?object $doc, array $lookups, bool $isLatestFlag): array
    {
        $isLatest = ($ml->revision_status ?? '') === 'latest'
            || (($ml->revision_status ?? null) === null && $isLatestFlag);

        $mlHydrated = $doc->masterlistRegistration ?? $ml;
        $drf = $doc->documentRequestForm ?? null;
        $dcn = $doc->documentChangeNotice ?? null;
        $dist = $doc->documentDistribution ?? null;
        $ret = $doc->documentRetrieval ?? null;
        $approvals = $doc->approvalRecords ?? collect();
        $syllabi = $doc->syllabi ?? collect();

        $versionId = $ml->request_version_id ?? $doc->version_id ?? null;
        $creatorId = $ml->request_created_by ?? $ml->created_by ?? null;

        $sections = [];

        self::hstPushSection($sections, 'masterlist', 'Masterlist', [
            self::hstRow('title', 'Title', $ml->doc_title),
            self::hstRow('doc_type', 'Document type', $doc->docType->doc_type_name ?? null),
            self::hstRow('sub_type', 'Sub type', $doc->subType->doc_type_name ?? null),
            self::hstRow('version', 'Version', $lookups['versions'][$versionId] ?? null),
            self::hstRow('revise_no', 'Revision no.', $ml->revise_no === null ? null : (string) $ml->revise_no),
            self::hstRow('registered_at', 'Registered', self::hstDateTime($ml->request_created_at ?? $ml->created_at)),
            self::hstRow('registered_by', 'Registered by', $lookups['creators'][(int) $creatorId] ?? null),
            self::hstRow('originator', 'Originator', $ml->originator_name),
            self::hstOfficeRow('sources', 'Source offices', $mlHydrated->sourceOffices ?? collect()),
            self::hstRow('effectivity', 'Effectivity', self::hstDate($ml->effectivity_date)),
            self::hstRow('deadline', 'Deadline', self::hstDate($ml->deadline)),
            self::hstRow('pages', 'Pages', $ml->no_pages === null ? null : (string) $ml->no_pages),
            self::hstRow('receipt_date', 'Date received', self::hstDate($ml->doc_receipt_date)),
            self::hstRow('receipt_time', 'Time received', self::hstTime($ml->doc_receipt_time)),
            self::hstRow('reg_date', 'Date registered', self::hstDate($ml->doc_registered_date)),
            self::hstRow('reg_time', 'Time registered', self::hstTime($ml->doc_registered_time)),
            self::hstRow('time_spent', 'Time spent', $ml->time_spent === null ? null : (string) $ml->time_spent),
            self::hstRow('related', 'Related documents', collect($mlHydrated->relatedList ?? [])
                ->map(fn ($r) => trim(($r->doc_title ?? '') . ($r->doc_no ? ' (' . $r->doc_no . ')' : '')))
                ->filter()
                ->unique()
                ->implode(', ')),
            self::hstRow('scanned_ml', 'Scanned masterlist', self::hstFile($ml->scanned_masterlist)),
        ], self::hstScanMeta($ml->scanned_masterlist));

        if ($drf) {
            self::hstPushSection($sections, 'drf', 'Document Request Form', [
                self::hstRow('drf_no', 'DRF no.', $drf->drf_no ?? null),
                self::hstRow('drf_date', 'DRF date', self::hstDate($drf->drf_date ?? null)),
                self::hstRow('drf_receipt_date', 'DRF received date', self::hstDate($drf->drf_receipt_date ?? null)),
                self::hstRow('drf_receipt_time', 'DRF received time', self::hstTime($drf->drf_receipt_time ?? null)),
                self::hstOfficeRow('drf_offices', 'DRF offices', $drf->offices ?? collect()),
                self::hstRow('drf_scan', 'Scanned DRF', self::hstFile($drf->scanned_drf ?? null)),
            ], self::hstScanMeta($drf->scanned_drf ?? null));
        }

        if ($dcn) {
            $dcnRevisionRows = collect($dcn->revisions ?? [])->map(function ($rev) {
                return [
                    'document_no' => $rev->document_no ?: '—',
                    'title' => $rev->title ?: '—',
                    'revision_no' => $rev->revision_no !== null ? (string) $rev->revision_no : '—',
                    'effectivity_date' => self::hstDate($rev->effectivity_date) ?: '—',
                    'brief_purpose' => $rev->brief_purpose ?: '—',
                ];
            })->all();

            $dcnRevText = collect($dcn->revisions ?? [])->map(function ($rev) {
                $bits = array_filter([
                    $rev->title ?: null,
                    $rev->document_no ? 'No. ' . $rev->document_no : null,
                    $rev->revision_no !== null ? 'Rev ' . $rev->revision_no : null,
                    $rev->effectivity_date ? self::hstDate($rev->effectivity_date) : null,
                    $rev->brief_purpose ?: null,
                ]);

                return implode(' · ', $bits);
            })->filter()->implode("\n");

            self::hstPushSection($sections, 'dcn', 'Document Change Notice', [
                self::hstRow('dcn_no', 'DCN no.', $dcn->dcn_no ?? null),
                self::hstRow('dcn_date', 'DCN date', self::hstDate($dcn->dcn_date ?? null)),
                self::hstRow('dcn_receipt_date', 'DCN received date', self::hstDate($dcn->dcn_receipt_date ?? null)),
                self::hstRow('dcn_receipt_time', 'DCN received time', self::hstTime($dcn->dcn_receipt_time ?? null)),
                self::hstOfficeRow('dcn_offices', 'DCN offices', $dcn->offices ?? collect()),
                self::hstRow('dcn_scan', 'Scanned DCN', self::hstFile($dcn->scanned_dcn ?? null)),
                self::hstRow('dcn_revs', 'Change items', $dcnRevText),
            ], array_merge(self::hstScanMeta($dcn->scanned_dcn ?? null), [
                'revisions' => $dcnRevisionRows,
            ]));
        }

        if ($dist) {
            self::hstPushSection($sections, 'distribution', 'Distribution', [
                self::hstRow('dist_date_actual', 'Actual date', self::hstDate($dist->doc_distribution_date_actual ?? null)),
                self::hstRow('dist_time_actual', 'Actual time', self::hstTime($dist->doc_distribution_time_actual ?? null)),
                self::hstRow('dist_date_file', 'File date', self::hstDate($dist->doc_distribution_date_file ?? null)),
                self::hstRow('dist_time_file', 'File time', self::hstTime($dist->doc_distribution_time_file ?? null)),
                self::hstRow('dist_spent', 'Time spent', $dist->time_spent ?? null),
                self::hstOfficeRow('dist_offices', 'Offices / copies', $dist->offices ?? collect(), true),
                self::hstRow('dist_remarks', 'Remarks', $dist->remarks ?? null),
                self::hstRow('dist_scan', 'Scanned copy', self::hstFile($dist->scanned_distribution ?? null)),
            ], self::hstScanMeta($dist->scanned_distribution ?? null));
        }

        if ($ret) {
            self::hstPushSection($sections, 'retrieval', 'Retrieval', [
                self::hstRow('ret_date_actual', 'Actual date', self::hstDate($ret->doc_retrieval_date_actual ?? null)),
                self::hstRow('ret_time_actual', 'Actual time', self::hstTime($ret->doc_retrieval_time_actual ?? null)),
                self::hstRow('ret_date_file', 'File date', self::hstDate($ret->doc_retrieval_date_file ?? null)),
                self::hstRow('ret_time_file', 'File time', self::hstTime($ret->doc_retrieval_time_file ?? null)),
                self::hstRow('ret_spent', 'Time spent', $ret->time_spent ?? null),
                self::hstOfficeRow('ret_offices', 'Offices / copies', $ret->offices ?? collect(), true),
                self::hstRow('ret_remarks', 'Remarks', $ret->remarks ?? null),
                self::hstRow('ret_scan', 'Scanned copy', self::hstFile($ret->scanned_retrieval ?? null)),
            ], self::hstScanMeta($ret->scanned_retrieval ?? null));
        }

        $approvalText = collect($approvals)->map(function ($a) use ($lookups) {
            $body = $lookups['approvals'][$a->approval_body_id] ?? null;
            $bits = array_filter([
                $body,
                $a->approval_no ? 'No. ' . $a->approval_no : null,
                $a->approval_date ? self::hstDate($a->approval_date) : null,
            ]);

            return implode(' · ', $bits);
        })->filter()->implode('; ');

        self::hstPushSection($sections, 'approval', 'Approval', [
            self::hstRow('approval_status', 'Approval status', $ml->approval_status ?? $doc->approval_status ?? null),
            self::hstRow('approval_records', 'Approval records', $approvalText),
        ]);

        if ($syllabi->isNotEmpty()) {
            $first = $syllabi->first();
            $metaRows = self::hstFilledRows([
                self::hstRow('syl_college', 'College', $lookups['colleges'][$first->college_id] ?? null),
                self::hstRow('syl_program', 'Program', $lookups['programs'][$first->program_id] ?? null),
                self::hstRow('syl_sem', 'Semester', $lookups['semesters'][$first->semester_id] ?? null),
                self::hstRow('syl_sy', 'School year', $lookups['years'][$first->school_year_id] ?? null),
            ]);

            $courseRows = [];
            foreach ($syllabi as $idx => $syl) {
                $code = $syl->course->course_code ?? $syl->course_code ?? '';
                $name = $syl->course->course_name ?? $syl->course_name ?? '';
                $label = trim($code . ' ' . $name) ?: ('Course ' . ($idx + 1));
                $prefix = 'syl.' . ($syl->course_id ?? $syl->id);
                $faculties = collect($syl->drfs ?? [])->pluck('faculty_name')->filter()->unique()->implode(', ');
                $drfBits = collect($syl->drfs ?? [])->map(function ($d) {
                    if (!self::pgBool($d->is_drf_available ?? false)) {
                        return null;
                    }

                    return trim(($d->faculty_name ? $d->faculty_name . ': ' : '') . ($d->drf_no ?: 'DRF') .
                        ($d->drf_date ? ' (' . self::hstDate($d->drf_date) . ')' : ''));
                })->filter()->implode('; ');

                $recv = [];
                if ($syl->date_received) {
                    $recv[] = self::hstDate($syl->date_received);
                }
                if ($syl->time_received) {
                    $recv[] = self::hstTime($syl->time_received);
                }

                $courseRows[] = self::hstRow($prefix . '.course', 'Course', $label);
                $courseRows[] = self::hstRow($prefix . '.avail', 'Available', self::pgBool($syl->is_available) ? 'Yes' : 'No');
                $courseRows[] = self::hstRow($prefix . '.copies', 'Copies', (string) ($syl->no_copies ?? 1));
                $courseRows[] = self::hstRow($prefix . '.pages', 'Pages', $syl->no_pages === null ? null : (string) $syl->no_pages);
                $courseRows[] = self::hstRow($prefix . '.received', 'Received', implode(' ', $recv));
                $courseRows[] = self::hstRow($prefix . '.faculty', 'Faculty', $faculties);
                $courseRows[] = self::hstRow($prefix . '.drf', 'DRF', $drfBits);
            }

            $sections[] = [
                'key' => 'syllabi',
                'title' => 'Syllabi',
                'rows' => array_merge($metaRows, $courseRows),
                'table' => ['meta' => $metaRows, 'courses' => []],
                'scan_path' => null,
                'scan_url' => null,
            ];
        }

        $tabLabels = [
            'masterlist' => 'Masterlist',
            'drf' => 'DRF',
            'dcn' => 'DCN',
            'distribution' => 'Distribution',
            'retrieval' => 'Retrieval',
            'approval' => 'Approval',
            'syllabi' => 'Syllabi',
        ];
        $checklists = [];
        foreach ($sections as $section) {
            if (isset($tabLabels[$section['key']])) {
                $checklists[] = [
                    'key' => $section['key'],
                    'label' => $tabLabels[$section['key']],
                ];
            }
        }

        return [
            'id' => (int) $ml->request_id,
            'doc_no' => $ml->doc_no ?: null,
            'revise_no' => (int) ($ml->revise_no ?? 0),
            'is_latest' => $isLatest,
            'created_at' => $ml->request_created_at ?? $ml->created_at,
            'created_label' => self::hstDateTime($ml->request_created_at ?? $ml->created_at),
            'checklists' => $checklists,
            'sections' => $sections,
            'summary' => [],
            'changes' => [],
        ];
    }

    private static function hstSyllabiTableFromRows(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['key']] = $row;
        }

        $meta = [];
        foreach (['syl_college', 'syl_program', 'syl_sem', 'syl_sy'] as $key) {
            if (isset($byKey[$key])) {
                $meta[] = $byKey[$key];
            }
        }

        $prefixes = [];
        foreach ($rows as $row) {
            if (preg_match('/^(syl\.[^.]+)\./', $row['key'] ?? '', $match)) {
                $prefixes[$match[1]] = true;
            }
        }

        $fields = ['course', 'avail', 'copies', 'pages', 'received', 'faculty', 'drf'];
        $empty = ['value' => '—', 'changed' => false, 'previous' => null];
        $courses = [];
        foreach (array_keys($prefixes) as $prefix) {
            $course = [];
            foreach ($fields as $field) {
                $course[$field] = $byKey[$prefix . '.' . $field] ?? $empty;
            }
            $courses[] = $course;
        }

        return [
            'meta' => $meta,
            'courses' => $courses,
        ];
    }

    private static function hstPickRows(array $sections, array $keys): array
    {
        $found = [];
        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                $found[$row['key']] = $row;
            }
        }

        $picked = [];
        foreach ($keys as $key) {
            if (isset($found[$key])) {
                $picked[] = $found[$key];
            }
        }

        return $picked;
    }

    public static function searchDocuments(Request $request)
    {
        $q = trim($request->input('q', ''));
        if (mb_strlen($q) < 1) {
            return [];
        }

        if (self::isLimitedDcsUser()) {
            abort_unless(
                $request->boolean('originator_self'),
                403,
                'Document search is limited to your own originator records.'
            );
            $visibleIds = self::visibleOriginatorRequestIds();
        } else {
            $visibleIds = self::visibleRequestIds();
        }
        $field = $request->input('field');
        $docTypeId = $request->input('doc_type_id');
        $subTypeId = $request->input('sub_type_id');
        $allRevisions = $request->boolean('all_revisions');
        $hasKeywords = Schema::hasColumn('dcs_masterlist_registration', 'keywords');
        // Dashboard search: include prior revisions so obsolete keywords still match.
        // Keyword hits stay on the matching revision; title/doc_no hits resolve to the lineage tip.
        $dashboardSearch = !$allRevisions && ($field === null || $field === '');

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
            ->whereIn('ml.request_id', $visibleIds);

        if ($request->boolean('originator_self')) {
            $userId = (int) auth()->id();
            $selfName = mb_strtolower(trim(self::currentUserDisplayName()));
            if ($userId < 1 && ($selfName === '' || $selfName === '—')) {
                return [];
            }
            $query->where(function ($q) use ($userId, $selfName) {
                if ($userId > 0 && Schema::hasColumn('dcs_masterlist_registration', 'originator_account_id')) {
                    $q->where('ml.originator_account_id', $userId);
                }
                if ($selfName !== '' && $selfName !== '—') {
                    $method = ($userId > 0 && Schema::hasColumn('dcs_masterlist_registration', 'originator_account_id'))
                        ? 'orWhere'
                        : 'where';
                    $q->{$method}(function ($legacy) use ($selfName) {
                        if (Schema::hasColumn('dcs_masterlist_registration', 'originator_account_id')) {
                            $legacy->whereNull('ml.originator_account_id');
                        }
                        $legacy->whereRaw('LOWER(TRIM(COALESCE(ml.originator_name, \'\'))) = ?', [$selfName]);
                    });
                }
            });
        }

        if (!$allRevisions && !$dashboardSearch) {
            self::applyLatestRevisionStatus($query, 'ml');
        }

        if ($docTypeId) {
            $query->where('dr.doc_type_id', $docTypeId);
            if ($subTypeId) {
                $query->where('dr.sub_type_id', $subTypeId);
            }
        }

        $query->where(function ($qr) use ($q, $field, $hasKeywords) {
            if ($field === 'no') {
                $qr->where('ml.doc_no', 'ilike', "%{$q}%");
            } elseif ($field === 'title') {
                $qr->where('ml.doc_title', 'ilike', "%{$q}%");
            } else {
                $qr->where('ml.doc_title', 'ilike', "%{$q}%")
                    ->orWhere('ml.doc_no', 'ilike', "%{$q}%");
                if ($hasKeywords) {
                    $qr->orWhere('ml.keywords', 'ilike', "%{$q}%");
                }
            }
        });

        if ($request->filled('exclude_request_id')) {
            $query->where('ml.request_id', '!=', $request->exclude_request_id);
        }

        $select = [
            'ml.id',
            'ml.request_id',
            'ml.doc_no',
            'ml.doc_title',
            'ml.revise_no',
            'ml.effectivity_date',
            'ml.scanned_masterlist',
            'ml.originator_name',
            'dr.doc_type_id',
            'dr.sub_type_id',
            'dt.doc_type_name as type_name',
            'st.doc_type_name as sub_type_name',
        ];
        if (self::supportsRevisionStatus()) {
            $select[] = 'ml.revision_status';
        }
        if ($hasKeywords) {
            $select[] = 'ml.keywords';
        }
        if (self::supportsRevisedFromDocNo()) {
            $select[] = 'ml.revised_from_doc_no';
        }

        $rows = $query->orderByDesc('ml.revise_no')
            ->orderBy('ml.doc_no')
            ->orderBy('ml.doc_title')
            ->limit($allRevisions ? 50 : ($dashboardSearch ? 60 : 30))
            ->get($select);

        if ($rows->isEmpty()) {
            return [];
        }

        if ($dashboardSearch) {
            $rows = self::resolveDashboardSearchHits($rows, $visibleIds, $hasKeywords, $q);
        } elseif (!$allRevisions) {
            $seen = [];
            $rows = $rows->filter(function ($m) use (&$seen) {
                $key = strtolower(trim((string) ($m->doc_no ?? ''))) . '|'
                    . (int) ($m->doc_type_id ?? 0) . '|'
                    . (int) ($m->sub_type_id ?? 0);
                if (isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;

                return true;
            })->values();
        }

        $rows = $rows->take(15);

        $requestIds = $rows->pluck('request_id')->all();
        $drfIds = DB::table('dcs_document_request_form')->whereIn('request_id', $requestIds)->pluck('request_id')->flip();
        $dcnIds = DB::table('dcs_document_change_notice')->whereIn('request_id', $requestIds)->pluck('request_id')->flip();
        $distIds = DB::table('dcs_document_distribution')->whereIn('request_id', $requestIds)->pluck('request_id')->flip();
        $retIds = DB::table('dcs_document_retrieval')->whereIn('request_id', $requestIds)->pluck('request_id')->flip();
        $approvalIds = DB::table('dcs_document_requests')
            ->whereIn('id', $requestIds)
            ->where('approval_status', 'applicable')
            ->pluck('id')
            ->flip();

        return $rows->map(function ($m) use ($drfIds, $dcnIds, $distIds, $retIds, $approvalIds) {
            $docNo = $m->doc_no ?: 'No number';
            $title = $m->doc_title ?: 'Untitled';
            $isObsolete = strtolower(trim((string) ($m->revision_status ?? ''))) === 'obsolete';

            return self::mapSearchResultForAudience([
                'masterlist_id' => $m->id,
                'request_id' => $m->request_id,
                'doc_no' => $m->doc_no,
                'doc_title' => $m->doc_title,
                'type_name' => $m->type_name,
                'sub_type_name' => $m->sub_type_name,
                'revise_no' => $m->revise_no,
                'revision_status' => $m->revision_status ?? null,
                'effectivity_date' => $m->effectivity_date ? Carbon::parse($m->effectivity_date)->format('Y-m-d') : null,
                // Brief Purpose is DCN justification only — never masterlist keywords.
                'brief_purpose' => null,
                'originator_name' => $m->originator_name ?? null,
                'keywords' => $m->keywords ?? null,
                'scanned_copy_url' => $m->scanned_masterlist ? self::scanUrl($m->scanned_masterlist) : null,
                'scanned_copy_path' => $m->scanned_masterlist,
                'label' => $docNo . ' — ' . $title . ' (Rev ' . (int) $m->revise_no . ')'
                    . ($isObsolete ? ' (Obsolete)' : ''),
                'checklists' => [
                    'drf' => isset($drfIds[$m->request_id]),
                    'dcn' => isset($dcnIds[$m->request_id]),
                    'masterlist' => true,
                    'approval' => isset($approvalIds[$m->request_id]),
                    'distribution' => isset($distIds[$m->request_id]),
                    'retrieval' => isset($retIds[$m->request_id]),
                ],
                'edit_url' => route('dcs.register.edit', $m->request_id, false),
                'stamp_url' => route('dcs.stamping.index', self::documentSearchParams($m), false),
                'inventory_url' => route('dcs.database.index', self::documentSearchParams($m), false),
                'match_request_id' => isset($m->match_request_id) ? (int) $m->match_request_id : (int) $m->request_id,
                'match_revise_no' => isset($m->match_revise_no) ? (int) $m->match_revise_no : (int) ($m->revise_no ?? 0),
                'match_masterlist_id' => isset($m->match_masterlist_id) ? (int) $m->match_masterlist_id : (int) $m->id,
                'lineage_request_id' => isset($m->lineage_request_id) ? (int) $m->lineage_request_id : (int) $m->request_id,
            ]);
        })
            ->values()
            ->all();
    }

    /**
     * Dashboard search hit resolution:
     * - Keyword matches stay on the revision that owns those keywords (obsolete or latest).
     * - Title / doc-no matches still resolve to the current lineage tip.
     */
    private static function resolveDashboardSearchHits(Collection $rows, array $visibleIds, bool $hasKeywords, string $q): Collection
    {
        $out = collect();
        $seenKeys = [];

        foreach ($rows as $hit) {
            if (self::searchHitMatchedOnKeywords($hit, $q, $hasKeywords)) {
                $key = 'ml:' . (int) $hit->id;
                if (isset($seenKeys[$key])) {
                    continue;
                }
                $seenKeys[$key] = true;

                // Keep the revision that owns these keywords (obsolete or latest).
                $hit->match_request_id = (int) $hit->request_id;
                $hit->match_revise_no = (int) ($hit->revise_no ?? 0);
                $hit->match_masterlist_id = (int) $hit->id;

                // Still expose the lineage tip so the detail modal can load the full chain.
                $tip = self::resolveLineageTipMasterlist($hit, $visibleIds);
                $hit->lineage_request_id = $tip
                    ? (int) $tip->request_id
                    : (int) $hit->request_id;

                $out->push($hit);
                continue;
            }

            $tip = self::resolveLineageTipMasterlist($hit, $visibleIds);
            if (!$tip) {
                continue;
            }

            $key = strtolower(trim((string) ($tip->doc_no ?? ''))) . '|'
                . (int) ($tip->doc_type_id ?? 0) . '|'
                . (int) ($tip->sub_type_id ?? 0);
            if (isset($seenKeys[$key])) {
                continue;
            }
            $seenKeys[$key] = true;

            $enriched = self::loadSearchDocumentRow((int) $tip->id, $hasKeywords);
            if ($enriched) {
                $enriched->match_request_id = (int) $hit->request_id;
                $enriched->match_revise_no = (int) ($hit->revise_no ?? 0);
                $enriched->match_masterlist_id = (int) $hit->id;
                $out->push($enriched);
            }
        }

        return $out->values();
    }

    private static function searchHitMatchedOnKeywords(object $hit, string $q, bool $hasKeywords): bool
    {
        if (!$hasKeywords || $q === '') {
            return false;
        }
        $keywords = trim((string) ($hit->keywords ?? ''));
        if ($keywords === '') {
            return false;
        }

        return mb_stripos($keywords, $q) !== false;
    }

    /**
     * @deprecated Kept for callers; dashboard search uses resolveDashboardSearchHits().
     */
    private static function resolveSearchHitsToLineageTips(Collection $rows, array $visibleIds, bool $hasKeywords): Collection
    {
        return self::resolveDashboardSearchHits($rows, $visibleIds, $hasKeywords, '');
    }

    private static function resolveLineageTipMasterlist(object $hit, array $visibleIds): ?object
    {
        $docTypeId = (int) ($hit->doc_type_id ?? 0);
        $subTypeId = !empty($hit->sub_type_id) ? (int) $hit->sub_type_id : null;
        $docNo = trim((string) ($hit->doc_no ?? ''));
        if ($docNo === '' || $docTypeId < 1) {
            return null;
        }

        $familyNos = self::docNosForRevisionLookup($docNo, $docTypeId, $subTypeId, $visibleIds);
        if ($familyNos === []) {
            $familyNos = [$docNo];
        }

        $tip = null;
        $bestScore = -1;
        foreach ($familyNos as $familyNo) {
            $latest = self::latestMasterlistForDocNo($familyNo, $docTypeId, $subTypeId, $visibleIds);
            if (!$latest) {
                continue;
            }
            $score = ((int) ($latest->revise_no ?? 0) * 1_000_000_000) + (int) ($latest->id ?? 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $tip = $latest;
            }
        }

        if ($tip) {
            $tip->doc_type_id = $docTypeId;
            $tip->sub_type_id = $subTypeId;
        }

        return $tip;
    }

    private static function loadSearchDocumentRow(int $masterlistId, bool $hasKeywords): ?object
    {
        $select = [
            'ml.id',
            'ml.request_id',
            'ml.doc_no',
            'ml.doc_title',
            'ml.revise_no',
            'ml.effectivity_date',
            'ml.scanned_masterlist',
            'dr.doc_type_id',
            'dr.sub_type_id',
            'dt.doc_type_name as type_name',
            'st.doc_type_name as sub_type_name',
        ];
        if (self::supportsRevisionStatus()) {
            $select[] = 'ml.revision_status';
        }
        if ($hasKeywords) {
            $select[] = 'ml.keywords';
        }

        return DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
            ->where('ml.id', $masterlistId)
            ->select($select)
            ->first();
    }

    public static function documentRevisions(Request $request): array
    {
        $requestId = (int) $request->input('request_id');
        if ($requestId < 1) {
            return [];
        }

        $visibleIds = self::isLimitedDcsUser()
            ? self::visibleOriginatorRequestIds()
            : self::visibleRequestIds();
        if (! in_array($requestId, $visibleIds, true)) {
            abort(403, 'You do not have access to this document revision.');
        }

        $anchor = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->where('ml.request_id', $requestId)
            ->select('ml.doc_no', 'ml.revise_no', 'dr.doc_type_id', 'dr.sub_type_id')
            ->first();

        if (!$anchor || !trim((string) ($anchor->doc_no ?? ''))) {
            return [];
        }

        $docNo = trim((string) $request->input('doc_no', ''));
        if ($docNo === '') {
            $docNo = trim((string) $anchor->doc_no);
        }

        $docTypeId = (int) $anchor->doc_type_id;
        $subTypeId = $anchor->sub_type_id ? (int) $anchor->sub_type_id : null;
        $maxReviseNo = (int) ($anchor->revise_no ?? 0);
        $chain = self::revisionFamilyDocNos($docNo, $docTypeId, $subTypeId, $visibleIds);

        $merged = [];
        foreach ($chain as $chainDocNo) {
            foreach (self::masterlistRevisionsForDocNo($chainDocNo, $docTypeId, $subTypeId, $visibleIds) as $row) {
                // When picking an older/obsolete tip (e.g. Rev 3) to insert a gap
                // (Rev 4) below a newer Latest (Rev 5), only show that tip and older.
                if ((int) ($row['revise_no'] ?? 0) > $maxReviseNo) {
                    continue;
                }
                $merged[] = $row;
            }
        }

        $merged = self::attachChecklistPresenceToRevisions($merged);

        return array_map(fn (array $row) => self::mapRevisionResultForAudience($row), $merged);
    }

    /**
     * Which checklist sections actually exist for each request (only checked/saved ones).
     *
     * @param  list<int>  $requestIds
     * @return array<int, array{drf: bool, dcn: bool, masterlist: bool, approval: bool, distribution: bool, retrieval: bool}>
     */
    public static function checklistPresenceForRequests(array $requestIds): array
    {
        $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
        if ($requestIds === []) {
            return [];
        }

        $out = [];
        foreach ($requestIds as $id) {
            $out[$id] = [
                'drf' => false,
                'dcn' => false,
                'masterlist' => false,
                'approval' => false,
                'distribution' => false,
                'retrieval' => false,
            ];
        }

        foreach (DB::table('dcs_document_request_form')->whereIn('request_id', $requestIds)->pluck('request_id') as $id) {
            $out[(int) $id]['drf'] = true;
        }
        foreach (DB::table('dcs_document_change_notice')->whereIn('request_id', $requestIds)->pluck('request_id') as $id) {
            $out[(int) $id]['dcn'] = true;
        }
        foreach (DB::table('dcs_masterlist_registration')->whereIn('request_id', $requestIds)->pluck('request_id') as $id) {
            $out[(int) $id]['masterlist'] = true;
        }
        foreach (DB::table('dcs_document_distribution')->whereIn('request_id', $requestIds)->pluck('request_id') as $id) {
            $out[(int) $id]['distribution'] = true;
        }
        foreach (DB::table('dcs_document_retrieval')->whereIn('request_id', $requestIds)->pluck('request_id') as $id) {
            $out[(int) $id]['retrieval'] = true;
        }
        foreach (
            DB::table('dcs_document_requests')
                ->whereIn('id', $requestIds)
                ->where('approval_status', 'applicable')
                ->pluck('id') as $id
        ) {
            $out[(int) $id]['approval'] = true;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $revisions
     * @return list<array<string, mixed>>
     */
    private static function attachChecklistPresenceToRevisions(array $revisions): array
    {
        $presence = self::checklistPresenceForRequests(
            array_map(fn ($row) => (int) ($row['request_id'] ?? 0), $revisions)
        );

        foreach ($revisions as &$row) {
            $id = (int) ($row['request_id'] ?? 0);
            $row['checklists'] = $presence[$id] ?? [
                'drf' => false,
                'dcn' => false,
                'masterlist' => true,
                'approval' => false,
                'distribution' => false,
                'retrieval' => false,
            ];
        }
        unset($row);

        return $revisions;
    }

    /**
     * Doc nos to load revision rows for: backward renumber chain plus forward renames
     * (older revision renumbered while a higher revision kept the original number).
     *
     * @return list<string>
     */
    public static function docNosForRevisionLookup(
        string $docNo,
        int $docTypeId,
        ?int $subTypeId,
        array $requestIds,
        bool $includeTrashed = false
    ): array {
        $backward = self::buildRenumberChain($docNo, $docTypeId, $subTypeId, $requestIds, $includeTrashed);
        $seen = [];
        $all = [];

        foreach ($backward as $no) {
            $key = strtolower($no);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $all[] = $no;
            }

            foreach (self::forwardRenumberDocNos($no, $docTypeId, $subTypeId, $requestIds, $includeTrashed) as $forwardNo) {
                $forwardKey = strtolower($forwardNo);
                if (!isset($seen[$forwardKey])) {
                    $seen[$forwardKey] = true;
                    $all[] = $forwardNo;
                }
            }
        }

        foreach (self::lineageRootsForDocNos($all, $docTypeId, $subTypeId, $requestIds, $includeTrashed) as $root) {
            foreach (self::peerDocNosWithLineageRoot($root, $docTypeId, $subTypeId, $requestIds, $includeTrashed) as $peerNo) {
                $peerKey = strtolower($peerNo);
                if (!isset($seen[$peerKey])) {
                    $seen[$peerKey] = true;
                    $all[] = $peerNo;
                }
            }
        }

        return self::expandDocNosWithDcnReferences($all, $docTypeId, $subTypeId, $requestIds);
    }

    /** Primary doc no from DCN "Documents for Revision" (lowest revision_no row). */
    public static function primaryDcnRevisionDocNo(int $requestId): ?string
    {
        if ($requestId < 1 || !Schema::hasTable('dcs_doc_revision')) {
            return null;
        }

        $dcnId = DB::table('dcs_document_change_notice')
            ->where('request_id', $requestId)
            ->value('id');
        if (!$dcnId) {
            return null;
        }

        $row = DB::table('dcs_doc_revision')
            ->where('dcn_id', $dcnId)
            ->whereNotNull('document_no')
            ->where('document_no', '!=', '')
            ->orderBy('revision_no')
            ->orderBy('id')
            ->first(['document_no']);

        $docNo = trim((string) ($row->document_no ?? ''));

        return $docNo !== '' ? $docNo : null;
    }

    /**
     * Doc numbers listed on this registration's DCN revision table.
     *
     * @return list<string>
     */
    public static function dcnReferencedDocNosForRequest(int $requestId): array
    {
        if ($requestId < 1 || !Schema::hasTable('dcs_doc_revision')) {
            return [];
        }

        $dcnId = DB::table('dcs_document_change_notice')
            ->where('request_id', $requestId)
            ->value('id');
        if (!$dcnId) {
            return [];
        }

        $nos = [];
        foreach (
            DB::table('dcs_doc_revision')
                ->where('dcn_id', $dcnId)
                ->orderBy('revision_no')
                ->orderBy('id')
                ->pluck('document_no') as $no
        ) {
            $no = trim((string) $no);
            if ($no !== '') {
                $nos[strtolower($no)] = $no;
            }
        }

        return array_values($nos);
    }

    /**
     * After deleting the tip, align revised_from_doc_no on live rows from DCN picks.
     */
    public static function healRevisedFromFromDcnForActiveFamily(
        string $anchorDocNo,
        int $docTypeId,
        ?int $subTypeId
    ): void {
        if (!Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
            return;
        }

        $anchorDocNo = trim($anchorDocNo);
        if ($anchorDocNo === '') {
            return;
        }

        $requestIds = self::requestIdsWithSameDocType((object) [
            'doc_type_id' => $docTypeId,
            'sub_type_id' => $subTypeId,
        ]);
        if ($requestIds === []) {
            return;
        }

        $familyNos = self::revisionFamilyDocNos($anchorDocNo, $docTypeId, $subTypeId, $requestIds, true);
        if ($familyNos === []) {
            $familyNos = [$anchorDocNo];
        }

        $rows = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $requestIds)
            ->whereIn('ml.doc_no', $familyNos)
            ->whereNull('dr.deleted_at')
            ->select('ml.id', 'ml.request_id', 'ml.doc_no', 'ml.revised_from_doc_no')
            ->get();

        foreach ($rows as $row) {
            $currentNo = trim((string) ($row->doc_no ?? ''));
            $dcnFrom = self::primaryDcnRevisionDocNo((int) $row->request_id);
            if ($dcnFrom === null || strcasecmp($dcnFrom, $currentNo) === 0) {
                continue;
            }

            $existingFrom = trim((string) ($row->revised_from_doc_no ?? ''));
            if (strcasecmp($existingFrom, $dcnFrom) === 0) {
                continue;
            }

            DB::table('dcs_masterlist_registration')
                ->where('id', $row->id)
                ->update([
                    'revised_from_doc_no' => $dcnFrom,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Include DCN "Documents for Revision" picks in a renumber/revision family.
     *
     * @param list<string> $seed
     * @param list<int> $requestIds
     * @return list<string>
     */
    private static function expandDocNosWithDcnReferences(
        array $seed,
        int $docTypeId,
        ?int $subTypeId,
        array $requestIds
    ): array {
        if ($seed === [] || $requestIds === [] || !Schema::hasTable('dcs_doc_revision')) {
            return $seed;
        }

        $seen = [];
        foreach ($seed as $no) {
            $trimmed = trim($no);
            if ($trimmed !== '') {
                $seen[strtolower($trimmed)] = $trimmed;
            }
        }

        $queue = array_values($seen);
        while ($queue !== []) {
            $no = array_shift($queue);

            $holderQuery = DB::table('dcs_masterlist_registration as ml')
                ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                ->where('ml.doc_no', $no)
                ->whereIn('ml.request_id', $requestIds)
                ->where('dr.doc_type_id', $docTypeId);
            if ($subTypeId) {
                $holderQuery->where('dr.sub_type_id', $subTypeId);
            } else {
                $holderQuery->whereNull('dr.sub_type_id');
            }

            foreach ($holderQuery->pluck('ml.request_id') as $requestId) {
                foreach (self::dcnReferencedDocNosForRequest((int) $requestId) as $refNo) {
                    $refKey = strtolower($refNo);
                    if (!isset($seen[$refKey])) {
                        $seen[$refKey] = $refNo;
                        $queue[] = $refNo;
                    }
                }
            }

            foreach ($requestIds as $requestId) {
                $refs = self::dcnReferencedDocNosForRequest((int) $requestId);
                $linked = false;
                foreach ($refs as $refNo) {
                    if (strcasecmp($refNo, $no) === 0) {
                        $linked = true;
                        break;
                    }
                }
                if (!$linked) {
                    continue;
                }

                $holderQuery = DB::table('dcs_masterlist_registration as ml')
                    ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                    ->where('ml.request_id', (int) $requestId)
                    ->where('dr.doc_type_id', $docTypeId);
                if ($subTypeId) {
                    $holderQuery->where('dr.sub_type_id', $subTypeId);
                } else {
                    $holderQuery->whereNull('dr.sub_type_id');
                }

                $holderNo = trim((string) ($holderQuery->value('ml.doc_no') ?? ''));
                if ($holderNo === '') {
                    continue;
                }

                $holderKey = strtolower($holderNo);
                if (!isset($seen[$holderKey])) {
                    $seen[$holderKey] = $holderNo;
                    $queue[] = $holderNo;
                }
            }
        }

        return array_values($seen);
    }

    /**
     * Distinct non-empty revised_from values on registrations for these doc numbers.
     *
     * @param  list<string>  $docNos
     * @return list<string>
     */
    private static function lineageRootsForDocNos(
        array $docNos,
        int $docTypeId,
        ?int $subTypeId,
        array $requestIds,
        bool $includeTrashed = false
    ): array {
        if ($docNos === []
            || !Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
            return [];
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $requestIds)
            ->whereIn('ml.doc_no', $docNos)
            ->whereNotNull('ml.revised_from_doc_no')
            ->where('ml.revised_from_doc_no', '!=', '')
            ->where('dr.doc_type_id', $docTypeId);

        if ($subTypeId) {
            $query->where('dr.sub_type_id', $subTypeId);
        } else {
            $query->whereNull('dr.sub_type_id');
        }
        if (!$includeTrashed) {
            self::applyNotDeleted($query, 'dr');
        }

        $roots = [];
        foreach ($query->distinct()->pluck('ml.revised_from_doc_no') as $root) {
            $root = trim((string) $root);
            if ($root !== '') {
                $roots[strtolower($root)] = $root;
            }
        }

        return array_values($roots);
    }

    /**
     * Doc numbers whose tip row shares the same lineage root (revised_from).
     *
     * @return list<string>
     */
    private static function peerDocNosWithLineageRoot(
        string $lineageRoot,
        int $docTypeId,
        ?int $subTypeId,
        array $requestIds,
        bool $includeTrashed = false
    ): array {
        $lineageRoot = trim($lineageRoot);
        if ($lineageRoot === ''
            || !Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
            return [];
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $requestIds)
            ->where('ml.revised_from_doc_no', $lineageRoot)
            ->where('dr.doc_type_id', $docTypeId);

        if ($subTypeId) {
            $query->where('dr.sub_type_id', $subTypeId);
        } else {
            $query->whereNull('dr.sub_type_id');
        }
        if (!$includeTrashed) {
            self::applyNotDeleted($query, 'dr');
        }

        $docNos = $query
            ->distinct()
            ->pluck('ml.doc_no')
            ->map(fn ($no) => trim((string) $no))
            ->filter(fn ($no) => $no !== '')
            ->values()
            ->all();

        $docNos[] = $lineageRoot;

        $unique = [];
        foreach ($docNos as $no) {
            $unique[strtolower($no)] = $no;
        }

        return array_values($unique);
    }

    /**
     * Doc numbers an older revision moved to after masterlist renumber (not new higher revisions).
     *
     * @return list<string>
     */
    private static function forwardRenumberDocNos(
        string $fromDocNo,
        int $docTypeId,
        ?int $subTypeId,
        array $requestIds,
        bool $includeTrashed = false
    ): array {
        $fromDocNo = trim($fromDocNo);
        if ($fromDocNo === ''
            || !Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
            return [];
        }

        $maxRevQuery = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $requestIds)
            ->where('ml.doc_no', $fromDocNo)
            ->where('dr.doc_type_id', $docTypeId);
        if ($subTypeId) {
            $maxRevQuery->where('dr.sub_type_id', $subTypeId);
        } else {
            $maxRevQuery->whereNull('dr.sub_type_id');
        }
        if (!$includeTrashed) {
            self::applyNotDeleted($maxRevQuery, 'dr');
        }

        $maxRev = (int) ($maxRevQuery->max('ml.revise_no') ?? 0);

        $childQuery = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $requestIds)
            ->where('ml.revised_from_doc_no', $fromDocNo)
            ->where('ml.doc_no', '!=', $fromDocNo)
            ->where('ml.revise_no', '<', $maxRev)
            ->where('dr.doc_type_id', $docTypeId);

        if ($subTypeId) {
            $childQuery->where('dr.sub_type_id', $subTypeId);
        } else {
            $childQuery->whereNull('dr.sub_type_id');
        }
        if (!$includeTrashed) {
            self::applyNotDeleted($childQuery, 'dr');
        }

        return $childQuery
            ->distinct()
            ->pluck('ml.doc_no')
            ->map(fn ($no) => trim((string) $no))
            ->filter(fn ($no) => $no !== '')
            ->values()
            ->all();
    }

    /** @return list<string> Current doc no first, then each prior renumbered doc no. */
    private static function buildRenumberChain(string $docNo, int $docTypeId, ?int $subTypeId, array $requestIds, bool $includeTrashed = false): array
    {
        $docNo = trim($docNo);
        if ($docNo === '') {
            return [];
        }

        $chain = [];
        $seen = [];
        $current = $docNo;

        while ($current !== '' && !isset($seen[strtolower($current)])) {
            $seen[strtolower($current)] = true;
            $chain[] = $current;

            if (!Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
                break;
            }

            // Prefer any family member with a lineage link (not only Latest tip).
            $fromQuery = DB::table('dcs_masterlist_registration as ml')
                ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                ->whereIn('ml.request_id', $requestIds)
                ->where('ml.doc_no', $current)
                ->where('dr.doc_type_id', $docTypeId)
                ->whereNotNull('ml.revised_from_doc_no')
                ->where('ml.revised_from_doc_no', '!=', '');

            if ($subTypeId) {
                $fromQuery->where('dr.sub_type_id', $subTypeId);
            } else {
                $fromQuery->whereNull('dr.sub_type_id');
            }
            if (!$includeTrashed) {
                self::applyNotDeleted($fromQuery, 'dr');
            }

            $from = trim((string) ($fromQuery
                ->orderByDesc('ml.revise_no')
                ->orderByDesc('ml.id')
                ->value('ml.revised_from_doc_no') ?? ''));

            if ($from === '' || strcasecmp($from, $current) === 0) {
                break;
            }

            $current = $from;
        }

        return $chain;
    }

    /** Whether two document numbers belong to the same renumber / revised_from family. */
    private static function docNosShareLineage(string $docNoA, string $docNoB, int $docTypeId, ?int $subTypeId): bool
    {
        $a = trim($docNoA);
        $b = trim($docNoB);
        if ($a === '' || $b === '') {
            return false;
        }
        if (strcasecmp($a, $b) === 0) {
            return true;
        }

        $visibleIds = self::visibleRequestIds();
        $family = array_map('strtolower', self::expandRenumberFamily($a, $docTypeId, $subTypeId, $visibleIds));

        return in_array(strtolower($b), $family, true);
    }

    /**
     * All doc nos in a renumber family (walk revised_from backward and forward).
     *
     * @return list<string>
     */
    public static function expandRenumberFamily(string $docNo, int $docTypeId, ?int $subTypeId, array $visibleIds): array
    {
        $docNo = trim($docNo);
        if ($docNo === '') {
            return [];
        }

        $family = [];
        $seen = [];
        $queue = [$docNo];

        while ($queue !== []) {
            $current = trim((string) array_shift($queue));
            $key = strtolower($current);
            if ($current === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $family[] = $current;

            foreach (self::buildRenumberChain($current, $docTypeId, $subTypeId, $visibleIds) as $prior) {
                if (!isset($seen[strtolower($prior)])) {
                    $queue[] = $prior;
                }
            }

            if (!Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
                continue;
            }

            $childQuery = DB::table('dcs_masterlist_registration as ml')
                ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                ->whereIn('ml.request_id', $visibleIds)
                ->where('ml.revised_from_doc_no', $current)
                ->where('dr.doc_type_id', $docTypeId);

            if ($subTypeId) {
                $childQuery->where('dr.sub_type_id', $subTypeId);
            } else {
                $childQuery->whereNull('dr.sub_type_id');
            }

            foreach ($childQuery->distinct()->pluck('ml.doc_no') as $childNo) {
                $child = trim((string) $childNo);
                if ($child !== '' && !isset($seen[strtolower($child)])) {
                    $queue[] = $child;
                }
            }
        }

        return $family;
    }

    /**
     * Active revise_no values across the whole renumber family for a doc no.
     *
     * @return list<int>
     */
    public static function familyReviseNumbers(
        string $docNo,
        int $docTypeId,
        ?int $subTypeId,
        array $visibleIds,
        int $excludeRequestId = 0
    ): array {
        $family = self::expandRenumberFamily($docNo, $docTypeId, $subTypeId, $visibleIds);
        if ($family === []) {
            $family = [trim($docNo)];
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $visibleIds)
            ->whereIn('ml.doc_no', $family)
            ->where('dr.doc_type_id', $docTypeId);

        if ($subTypeId) {
            $query->where('dr.sub_type_id', $subTypeId);
        } else {
            $query->whereNull('dr.sub_type_id');
        }

        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            $query->whereIn('ml.revision_status', ['latest', 'obsolete']);
        }
        if ($excludeRequestId > 0) {
            $query->where('ml.request_id', '!=', $excludeRequestId);
        }

        return $query
            ->orderBy('ml.revise_no')
            ->pluck('ml.revise_no')
            ->map(fn ($n) => (int) $n)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Family revisions with scanned masterlist URLs (for DRR: previous vs new upload).
     *
     * @return list<array{revise_no:int,doc_no:string,scanned_copy_url:?string}>
     */
    public static function familyRevisionScans(
        string $docNo,
        int $docTypeId,
        ?int $subTypeId,
        array $visibleIds,
        int $excludeRequestId = 0
    ): array {
        $family = self::expandRenumberFamily($docNo, $docTypeId, $subTypeId, $visibleIds);
        if ($family === []) {
            $family = [trim($docNo)];
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $visibleIds)
            ->whereIn('ml.doc_no', $family)
            ->where('dr.doc_type_id', $docTypeId);

        if ($subTypeId) {
            $query->where('dr.sub_type_id', $subTypeId);
        } else {
            $query->whereNull('dr.sub_type_id');
        }

        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            $query->whereIn('ml.revision_status', ['latest', 'obsolete']);
        }
        if ($excludeRequestId > 0) {
            $query->where('ml.request_id', '!=', $excludeRequestId);
        }

        return $query
            ->orderBy('ml.revise_no')
            ->orderByDesc('ml.id')
            ->get(['ml.revise_no', 'ml.doc_no', 'ml.scanned_masterlist'])
            ->unique(fn ($row) => (int) $row->revise_no)
            ->values()
            ->map(fn ($row) => [
                'revise_no' => (int) $row->revise_no,
                'doc_no' => (string) $row->doc_no,
                'scanned_copy_url' => $row->scanned_masterlist
                    ? self::scanUrl($row->scanned_masterlist)
                    : null,
            ])
            ->all();
    }

    private static function latestMasterlistForDocNo(
        string $docNo,
        int $docTypeId,
        ?int $subTypeId,
        array $visibleIds
    ): ?object {
        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $visibleIds)
            ->where('ml.doc_no', $docNo)
            ->where('dr.doc_type_id', $docTypeId);

        if ($subTypeId) {
            $query->where('dr.sub_type_id', $subTypeId);
        } else {
            $query->whereNull('dr.sub_type_id');
        }

        return $query
            ->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->select('ml.*')
            ->first();
    }

    /** @return list<array<string, mixed>> */
    private static function masterlistRevisionsForDocNo(
        string $docNo,
        int $docTypeId,
        ?int $subTypeId,
        array $visibleIds
    ): array {
        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $visibleIds)
            ->where('ml.doc_no', $docNo)
            ->where('dr.doc_type_id', $docTypeId);

        if ($subTypeId) {
            $query->where('dr.sub_type_id', $subTypeId);
        } else {
            $query->whereNull('dr.sub_type_id');
        }

        $cols = [
            'ml.id as masterlist_id',
            'ml.request_id',
            'ml.doc_no',
            'ml.doc_title',
            'ml.revise_no',
            'ml.effectivity_date',
            'ml.scanned_masterlist',
        ];
        if (self::supportsRevisionStatus()) {
            $cols[] = 'ml.revision_status';
        }

        return $query
            ->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->get($cols)
            ->map(function ($row) {
                // Rev 0 = original registration: no DCN, so no Brief Purpose.
                // Later revs: Brief Purpose = that registration's DCN justification only
                // (never masterlist keywords / brief_purpose).
                $purpose = null;
                $reviseNo = (int) ($row->revise_no ?? 0);
                if ($reviseNo > 0 && Schema::hasColumn('dcs_document_change_notice', 'brief_purpose')) {
                    $dcnPurpose = DB::table('dcs_document_change_notice')
                        ->where('request_id', $row->request_id)
                        ->value('brief_purpose');
                    if ($dcnPurpose !== null && trim((string) $dcnPurpose) !== '') {
                        $purpose = trim((string) $dcnPurpose);
                    }
                }
                $row->brief_purpose = $purpose;

                return self::mapDocumentRevisionRow($row);
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private static function mapDocumentRevisionRow(object $row): array
    {
        return [
            'masterlist_id' => $row->masterlist_id,
            'request_id' => $row->request_id,
            'doc_no' => $row->doc_no,
            'doc_title' => $row->doc_title,
            'revise_no' => (int) $row->revise_no,
            'revision_status' => ($row->revision_status ?? null) ?: 'latest',
            'effectivity_date' => $row->effectivity_date
                ? Carbon::parse($row->effectivity_date)->format('Y-m-d')
                : null,
            'brief_purpose' => $row->brief_purpose ?? null,
            'scanned_copy_url' => $row->scanned_masterlist
                ? self::scanUrl($row->scanned_masterlist)
                : null,
            'scanned_copy_path' => $row->scanned_masterlist,
            'label' => ($row->doc_no ?: 'No number') . ' — Rev ' . (int) $row->revise_no
                . (($row->revision_status ?? '') === 'obsolete' ? ' (Obsolete)' : ''),
        ];
    }

    public static function documentChecklistPreview(int $requestId, string $type): array
    {
        self::assertFullDcsUser();

        $allowed = ['drf', 'dcn', 'masterlist', 'approval', 'distribution', 'retrieval'];
        if (!in_array($type, $allowed, true)) {
            abort(404);
        }

        $visibleIds = self::visibleRequestIds();
        if (!in_array((int) $requestId, $visibleIds, true)) {
            abort(404);
        }

        $preview = match ($type) {
            'drf' => self::previewDrf($requestId),
            'dcn' => self::previewDcn($requestId),
            'masterlist' => self::previewMasterlist($requestId),
            'approval' => self::previewApproval($requestId),
            'distribution' => self::previewDistribution($requestId),
            'retrieval' => self::previewRetrieval($requestId),
        };

        $preview['fields'] = array_merge(self::previewTypeFields($requestId), $preview['fields'] ?? []);

        return $preview;
    }

    private static function previewTypeFields(int $requestId): array
    {
        $row = DB::table('dcs_document_requests as dr')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
            ->where('dr.id', $requestId)
            ->first(['dt.doc_type_name as type_name', 'st.doc_type_name as sub_type_name']);

        $fields = [];
        if (!empty($row?->type_name)) {
            $fields[] = self::previewField('Document Type', $row->type_name);
        }
        if (!empty($row?->sub_type_name)) {
            $fields[] = self::previewField('Sub Type', $row->sub_type_name);
        }

        return $fields;
    }

    private static function previewField(string $label, mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['label' => $label, 'value' => '—'];
        }

        return ['label' => $label, 'value' => (string) $value];
    }

    /**
     * @param  iterable<object|array>  $rows  each with office_name and optional copies
     * @return list<array{office:string,copies:?int}>
     */
    private static function previewOfficeRows(iterable $rows, bool $withCopies = false): array
    {
        $out = [];
        foreach ($rows as $row) {
            $name = is_array($row)
                ? (string) ($row['office_name'] ?? $row['name'] ?? '')
                : (string) ($row->office_name ?? '');
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $copies = null;
            if ($withCopies) {
                $raw = is_array($row) ? ($row['copies'] ?? 1) : ($row->copies ?? 1);
                $copies = max(1, (int) $raw);
            }
            $out[] = ['office' => $name, 'copies' => $copies];
        }

        return $out;
    }

    /**
     * @param  list<array{office:string,copies:?int}>  $offices
     * @return array{heading:string,offices:list<array{office:string,copies:?int}>,with_copies:bool}|null
     */
    private static function previewOfficesSection(string $heading, array $offices, bool $withCopies = false): ?array
    {
        if ($offices === []) {
            return null;
        }

        return [
            'heading' => $heading,
            'offices' => $offices,
            'with_copies' => $withCopies,
        ];
    }

    private static function previewDrf(int $requestId): array
    {
        $drf = DB::table('dcs_document_request_form')->where('request_id', $requestId)->first();
        if (!$drf) {
            abort(404);
        }

        $offices = self::previewOfficeRows(
            DB::table('dcs_drf_offices as d')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
                ->where('d.document_request_form_id', $drf->id)
                ->get(['o.office_name'])
        );

        return [
            'title' => 'Document Request Form',
            'type' => 'drf',
            'doc_no' => null,
            'doc_title' => $drf->doc_title,
            'fields' => [
                self::previewField('DRF No.', $drf->drf_no),
                self::previewField('DRF Date', self::formatDate($drf->drf_date)),
                self::previewField('Receipt Date', self::formatDate($drf->drf_receipt_date)),
                self::previewField('Receipt Time', self::formatTime($drf->drf_receipt_time)),
                self::previewField('Document Title', $drf->doc_title),
            ],
            'sections' => array_values(array_filter([
                self::previewOfficesSection('Source Offices', $offices),
            ])),
        ];
    }

    private static function previewDcn(int $requestId): array
    {
        $dcn = DB::table('dcs_document_change_notice')->where('request_id', $requestId)->first();
        if (!$dcn) {
            abort(404);
        }

        $offices = self::previewOfficeRows(
            DB::table('dcs_dcn_offices as d')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
                ->where('d.dcn_id', $dcn->id)
                ->get(['o.office_name'])
        );

        $revisions = DB::table('dcs_doc_revision')
            ->where('dcn_id', $dcn->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($rev) => [
                'title' => $rev->title ?: '—',
                'document_no' => $rev->document_no ?: '—',
                'revision_no' => $rev->revision_no !== null ? (string) $rev->revision_no : '—',
                'effectivity_date' => self::formatDate($rev->effectivity_date) ?: '—',
                'brief_purpose' => $rev->brief_purpose ?: '—',
            ])
            ->all();

        return [
            'title' => 'Document Change Notice',
            'type' => 'dcn',
            'doc_no' => $dcn->dcn_no,
            'doc_title' => null,
            'fields' => [
                self::previewField('DCN No.', $dcn->dcn_no),
                self::previewField('DCN Date', self::formatDate($dcn->dcn_date)),
                self::previewField('Receipt Date', self::formatDate($dcn->dcn_receipt_date)),
                self::previewField('Receipt Time', self::formatTime($dcn->dcn_receipt_time)),
            ],
            'sections' => array_values(array_filter([
                self::previewOfficesSection('Offices', $offices),
                $revisions !== [] ? ['heading' => 'Revisions', 'revisions' => $revisions] : null,
            ])),
        ];
    }

    private static function previewMasterlist(int $requestId): array
    {
        $ml = DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first();
        if (!$ml) {
            abort(404);
        }

        $offices = self::previewOfficeRows(
            DB::table('dcs_masterlist_source_offices as s')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 's.office_id')
                ->where('s.masterlist_id', $ml->id)
                ->get(['o.office_name'])
        );

        return [
            'title' => 'Masterlist Registration',
            'type' => 'masterlist',
            'doc_no' => $ml->doc_no,
            'doc_title' => $ml->doc_title,
            'fields' => [
                self::previewField('Document No.', $ml->doc_no),
                self::previewField('Document Title', $ml->doc_title),
                self::previewField('Revision No.', $ml->revise_no),
                self::previewField('Receipt Date', self::formatDate($ml->doc_receipt_date)),
                self::previewField('Receipt Time', self::formatTime($ml->doc_receipt_time)),
                self::previewField('Registered Date', self::formatDate($ml->doc_registered_date)),
                self::previewField('Registered Time', self::formatTime($ml->doc_registered_time)),
                self::previewField('Effectivity Date', self::formatDate($ml->effectivity_date)),
                self::previewField('No. of Pages', $ml->no_pages),
                self::previewField('Originator', $ml->originator_name),
                self::previewField('Deadline', self::formatDate($ml->deadline)),
                self::previewField('Time Spent (mins)', $ml->time_spent),
            ],
            'sections' => array_values(array_filter([
                self::previewOfficesSection('Source Offices', $offices),
            ])),
        ];
    }

    private static function previewApproval(int $requestId): array
    {
        $doc = DB::table('dcs_document_requests')->where('id', $requestId)->first();
        if (!$doc || ($doc->approval_status ?? '') !== 'applicable') {
            abort(404);
        }

        $approval = DB::table('dcs_approval_records as ar')
            ->leftJoin('dcs_approval_body as ab', 'ab.id', '=', 'ar.approval_body_id')
            ->where('ar.request_id', $requestId)
            ->first(['ab.approval_name', 'ar.approval_date', 'ar.approval_no']);

        return [
            'title' => 'Approving Body',
            'type' => 'approval',
            'doc_no' => null,
            'doc_title' => null,
            'fields' => [
                self::previewField('Approving Body', $approval?->approval_name),
                self::previewField('Approval Date', self::formatDate($approval?->approval_date)),
                self::previewField('Approval No.', $approval?->approval_no),
            ],
            'sections' => [],
        ];
    }

    private static function previewDistribution(int $requestId): array
    {
        $dist = DB::table('dcs_document_distribution')->where('request_id', $requestId)->first();
        if (!$dist) {
            abort(404);
        }

        $offices = self::previewOfficeRows(
            DB::table('dcs_distribution_offices as d')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
                ->where('d.distribution_id', $dist->id)
                ->orderBy('o.office_name')
                ->get(['o.office_name', 'd.copies']),
            true
        );

        return [
            'title' => 'Document Distribution',
            'type' => 'distribution',
            'doc_no' => null,
            'doc_title' => null,
            'fields' => [
                self::previewField('Distribution Date (Actual)', self::formatDate($dist->doc_distribution_date_actual)),
                self::previewField('Distribution Time (Actual)', self::formatTime($dist->doc_distribution_time_actual)),
                self::previewField('Distribution Date (File)', self::formatDate($dist->doc_distribution_date_file)),
                self::previewField('Distribution Time (File)', self::formatTime($dist->doc_distribution_time_file)),
                self::previewField('Time Spent (mins)', $dist->time_spent),
                self::previewField('Remarks', $dist->remarks),
            ],
            'sections' => array_values(array_filter([
                self::previewOfficesSection('Distribution Offices', $offices, true),
            ])),
        ];
    }

    private static function previewRetrieval(int $requestId): array
    {
        $ret = DB::table('dcs_document_retrieval')->where('request_id', $requestId)->first();
        if (!$ret) {
            abort(404);
        }

        $offices = self::previewOfficeRows(
            DB::table('dcs_retrieval_offices as r')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'r.office_id')
                ->where('r.retrieval_id', $ret->id)
                ->orderBy('o.office_name')
                ->get(['o.office_name', 'r.copies']),
            true
        );

        return [
            'title' => 'Document Retrieval',
            'type' => 'retrieval',
            'doc_no' => null,
            'doc_title' => null,
            'fields' => [
                self::previewField('Retrieval Date (Actual)', self::formatDate($ret->doc_retrieval_date_actual)),
                self::previewField('Retrieval Time (Actual)', self::formatTime($ret->doc_retrieval_time_actual)),
                self::previewField('Retrieval Date (File)', self::formatDate($ret->doc_retrieval_date_file)),
                self::previewField('Retrieval Time (File)', self::formatTime($ret->doc_retrieval_time_file)),
                self::previewField('Time Spent (mins)', $ret->time_spent),
                self::previewField('Remarks', $ret->remarks),
            ],
            'sections' => array_values(array_filter([
                self::previewOfficesSection('Retrieval Offices', $offices, true),
            ])),
        ];
    }

    public static function checkDocNo(Request $request)
    {
        $docNo = $request->input('doc_no');
        $docTypeId = (int) $request->input('doc_type_id');
        $subTypeId = $request->input('sub_type_id');
        $excludeRequestId = (int) $request->input('exclude_request_id', 0);
        $relatedFrom = trim((string) $request->input('related_from', ''));

        if (!$docNo) {
            return ['exists' => false, 'message' => 'No document number provided.'];
        }

        $result = RegisterPersistHelper::findMatchingRegistrationRows(
            $docNo,
            $docTypeId,
            $subTypeId ? (int) $subTypeId : null
        );

        if ($result['found']) {
            $matches = $result['matches'];
            if ($excludeRequestId > 0) {
                $matches = $matches->filter(fn ($row) => (int) $row->id !== $excludeRequestId)->values();
            }
            if ($matches->isEmpty()) {
                return [
                    'exists' => false,
                    'is_self' => true,
                    'message' => 'This is the current document number.',
                    'next_rev' => null,
                ];
            }
            $result['matches'] = $matches;
            $latest = DB::table('dcs_masterlist_registration')
                ->whereIn('request_id', $matches->pluck('id'))
                ->where('doc_no', $docNo)
                ->orderByDesc('revise_no')
                ->first();
            $result['latest'] = $latest;
            if ($latest) {
                $visibleIds = self::visibleRequestIds();
                $familyRevs = self::familyReviseNumbers(
                    $docNo,
                    $docTypeId,
                    $subTypeId ? (int) $subTypeId : null,
                    $visibleIds,
                    $excludeRequestId
                );
                $revisionScans = self::familyRevisionScans(
                    $docNo,
                    $docTypeId,
                    $subTypeId ? (int) $subTypeId : null,
                    $visibleIds,
                    $excludeRequestId
                );
                // Tip of the whole renumber family (e.g. 2024-323 → … → 2024-323-223 Rev 6).
                $latestRev = $familyRevs !== []
                    ? max($familyRevs)
                    : (int) $latest->revise_no;
                $registrations = DB::table('dcs_masterlist_registration')
                    ->whereIn('request_id', $result['matches']->pluck('id'))
                    ->where('doc_no', $docNo)
                    ->orderByDesc('revise_no')
                    ->get();

                $latestDistribution = DB::table('dcs_document_distribution')
                    ->where('request_id', $latest->request_id)
                    ->first();
                $latestDistributionOffices = [];
                if ($latestDistribution) {
                    $latestDistributionOffices = DB::table('dcs_distribution_offices as d')
                        ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
                        ->where('d.distribution_id', $latestDistribution->id)
                        ->orderBy('d.sort_order')
                        ->orderBy('d.id')
                        ->get([
                            'd.office_id',
                            'o.office_name',
                            'd.copies',
                        ])
                        ->map(fn ($o) => [
                            'office_id' => $o->office_id,
                            'office_name' => $o->office_name ?? 'Unknown Office',
                            'copies' => $o->copies ?? 1,
                        ])
                        ->values();
                }

                $sourceOffices = DB::table('dcs_masterlist_source_offices as s')
                    ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 's.office_id')
                    ->where('s.masterlist_id', $latest->id)
                    ->get(['o.office_name']);
                $latestSourceUnit = $sourceOffices->pluck('office_name')->filter()->implode(', ');

                $relatedIds = DB::table('dcs_masterlist_related_docs')
                    ->where('masterlist_id', $latest->id)
                    ->pluck('related_doc_id');
                $latestRelatedDocs = DB::table('dcs_masterlist_registration')
                    ->whereIn('id', $relatedIds)
                    ->get(['id', 'doc_no', 'doc_title'])
                    ->map(fn ($d) => [
                        'masterlist_id' => $d->id,
                        'doc_no' => $d->doc_no,
                        'doc_title' => $d->doc_title,
                    ])
                    ->values();

                $prevRequest = DB::table('dcs_document_requests')
                    ->where('id', $latest->request_id)
                    ->first(['approval_status']);
                $prevApproval = DB::table('dcs_approval_records')
                    ->where('request_id', $latest->request_id)
                    ->orderByDesc('id')
                    ->first(['approval_body_id', 'approval_no']);

                // Prefer explicit status; if missing but an approval record exists, treat as applicable
                $prevApprovalStatus = $prevRequest?->approval_status ?? null;
                if (!$prevApprovalStatus && $prevApproval) {
                    $prevApprovalStatus = 'applicable';
                }

                $alreadyRetrieved = self::priorRetrievedOfficesForDocNo(
                    $docNo,
                    $docTypeId,
                    $subTypeId ? (int) $subTypeId : null,
                    null,
                    null
                );

                $sameFamily = $relatedFrom === ''
                    ? true
                    : self::docNosShareLineage(
                        $docNo,
                        $relatedFrom,
                        $docTypeId,
                        $subTypeId ? (int) $subTypeId : null
                    );

                return [
                    'exists' => true,
                    'same_family' => $sameFamily,
                    'message' => 'Document found.',
                    'next_rev' => $latestRev + 1,
                    'latest_rev' => $latestRev,
                    'taken_revs' => $familyRevs,
                    'revision_scans' => $revisionScans,
                    'latest_scanned_copy_url' => $latest->scanned_masterlist
                        ? self::scanUrl($latest->scanned_masterlist)
                        : null,
                    'latest_title' => $latest->doc_title,
                    'latest_originator' => $latest->originator_name,
                    'revision_count' => $registrations->count(),
                    'latest_distribution_offices' => $latestDistributionOffices,
                    'already_retrieved_offices' => $alreadyRetrieved,
                    'latest_source_unit' => $latestSourceUnit,
                    'latest_effectivity_date' => $latest->effectivity_date ? Carbon::parse($latest->effectivity_date)->format('Y-m-d') : null,
                    'latest_no_pages' => $latest->no_pages,
                    'latest_deadline' => $latest->deadline ? Carbon::parse($latest->deadline)->format('Y-m-d') : null,
                    'latest_brief_purpose' => null,
                    'latest_keywords' => $latest->keywords ?? null,
                    'latest_related_documents' => $latestRelatedDocs,
                    // Carry previous approval into revised: show Approval Details only if applicable
                    'latest_approval_status' => $prevApprovalStatus,
                    'latest_approval_body_id' => $prevApproval?->approval_body_id ?? null,
                    'latest_approval_no' => $prevApproval?->approval_no ?? null,
                ];
            }
        }

        if (($result['reason'] ?? '') === 'not_registered') {
            $hasSubType = $subTypeId && (int) $subTypeId > 0;
            $message = 'This document number is not registered';
            if ($hasSubType) {
                $subType = RegisterPersistHelper::dcsDocType($subTypeId);
                $message .= ' under "' . ($subType ? $subType->doc_type_name : 'this sub-type') . '"';
            } elseif ($docTypeId) {
                $type = RegisterPersistHelper::dcsDocType($docTypeId);
                $message .= ' under "' . ($type ? $type->doc_type_name : 'this document type') . '"';
            }
            $message .= '. Please register it as a New Document first.';

            return ['exists' => false, 'message' => $message, 'next_rev' => null];
        }

        $existingDr = $result['existing_dr'] ?? null;
        if (($result['reason'] ?? '') === 'wrong_subtype') {
            $existingSubType = RegisterPersistHelper::dcsDocType($existingDr->sub_type_id ?? null);

            return [
                'exists' => false,
                'wrong_subtype' => true,
                'existing_subtype_name' => $existingSubType ? $existingSubType->doc_type_name : 'Unknown',
                'message' => RegisterPersistHelper::mismatchErrorMessageFromRow($docNo, $result),
                'next_rev' => null,
            ];
        }

        $existingType = RegisterPersistHelper::dcsDocType($existingDr->doc_type_id ?? null);

        return [
            'exists' => false,
            'wrong_type' => true,
            'existing_type_name' => $existingType ? $existingType->doc_type_name : 'Unknown',
            'message' => RegisterPersistHelper::mismatchErrorMessageFromRow($docNo, $result),
            'next_rev' => null,
        ];
    }

    /**
     * Live check: is this revise_no already taken for doc_no + type (active rows only)?
     */
    public static function checkRevNo(Request $request): array
    {
        $docNo = trim((string) $request->input('doc_no', ''));
        $rawRev = $request->input('revise_no');
        $docTypeId = (int) $request->input('doc_type_id');
        $subTypeId = $request->input('sub_type_id');
        $excludeRequestId = (int) $request->input('exclude_request_id', 0);

        if ($docNo === '') {
            return [
                'taken' => false,
                'needs_doc_no' => true,
                'message' => 'Enter a document number first.',
            ];
        }

        if ($docTypeId < 1) {
            return [
                'taken' => false,
                'needs_doc_type' => true,
                'message' => 'Select a document type first.',
            ];
        }

        $reviseNo = ($rawRev === null || $rawRev === '') ? 0 : (int) $rawRev;
        if ($reviseNo < 0) {
            return [
                'taken' => true,
                'revise_no' => $reviseNo,
                'message' => 'Revision number cannot be negative.',
            ];
        }

        $result = RegisterPersistHelper::findMatchingRegistrationRows(
            $docNo,
            $docTypeId,
            $subTypeId ? (int) $subTypeId : null
        );

        if (!$result['found']) {
            return [
                'taken' => false,
                'revise_no' => $reviseNo,
                'taken_revs' => [],
                'next_rev' => $reviseNo,
                'message' => 'Revision ' . $reviseNo . ' is available for this document number.',
            ];
        }

        $visibleIds = self::visibleRequestIds();
        $takenRevs = self::familyReviseNumbers(
            $docNo,
            $docTypeId,
            $subTypeId ? (int) $subTypeId : null,
            $visibleIds,
            $excludeRequestId
        );

        // Fallback: exact doc no only if family walk found nothing.
        if ($takenRevs === []) {
            $matchIds = $result['matches']->pluck('id');
            $revQuery = DB::table('dcs_masterlist_registration')
                ->whereIn('request_id', $matchIds)
                ->where('doc_no', $docNo);

            if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
                $revQuery->whereIn('revision_status', ['latest', 'obsolete']);
            }
            if ($excludeRequestId > 0) {
                $revQuery->where('request_id', '!=', $excludeRequestId);
            }

            $takenRevs = $revQuery
                ->orderBy('revise_no')
                ->pluck('revise_no')
                ->map(fn ($n) => (int) $n)
                ->unique()
                ->values()
                ->all();
        }

        $taken = in_array($reviseNo, $takenRevs, true);
        $familyLatest = $takenRevs !== [] ? max($takenRevs) : null;
        $suggestedNext = $familyLatest === null ? $reviseNo : ($familyLatest + 1);

        return [
            'taken' => $taken,
            'revise_no' => $reviseNo,
            'taken_revs' => $takenRevs,
            'latest_rev' => $familyLatest,
            'next_rev' => $suggestedNext,
            'message' => $taken
                ? 'Revision ' . $reviseNo . ' is taken. Use Rev ' . $suggestedNext . '.'
                : (
                    $familyLatest !== null && $reviseNo <= $familyLatest
                        ? 'Revision ' . $reviseNo . ' is available (gap). Latest is Rev ' . $familyLatest . '.'
                        : 'Revision ' . $reviseNo . ' is available.'
                ),
        ];
    }

    public static function editPayload(int $id): array
    {
        $docRequest = self::findDocumentRequest($id);
        abort_unless($docRequest, 404);
        self::assertCanAccessRequest($id);

        $ml = DB::table('dcs_masterlist_registration')->where('request_id', $id)->first();
        // Obsolete revisions are editable; Update list groups them under the latest tip.

        $drf = DB::table('dcs_document_request_form')->where('request_id', $id)->first();
        $drfOffices = $drf
            ? DB::table('dcs_drf_offices as d')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
                ->where('d.document_request_form_id', $drf->id)
                ->get(['d.office_id', 'o.office_name'])
            : collect();

        $dcn = DB::table('dcs_document_change_notice')->where('request_id', $id)->first();
        $dcnOffices = $dcn
            ? DB::table('dcs_dcn_offices as d')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
                ->where('d.dcn_id', $dcn->id)
                ->get(['d.office_id', 'o.office_name'])
            : collect();
        $revisions = $dcn
            ? DB::table('dcs_doc_revision')->where('dcn_id', $dcn->id)->get()
            : collect();

        $retrieval = DB::table('dcs_document_retrieval')->where('request_id', $id)->first();
        $retrievalOfficeColumns = ['r.office_id', 'r.copies', 'o.office_name'];
        if (Schema::hasColumn('dcs_retrieval_offices', 'retrieval_status')) {
            $retrievalOfficeColumns[] = 'r.retrieval_status';
        }
        if (Schema::hasColumn('dcs_retrieval_offices', 'retrieval_date')) {
            $retrievalOfficeColumns[] = 'r.retrieval_date';
        }
        if (Schema::hasColumn('dcs_retrieval_offices', 'retrieval_time')) {
            $retrievalOfficeColumns[] = 'r.retrieval_time';
        }
        $retrievalOffices = $retrieval
            ? DB::table('dcs_retrieval_offices as r')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'r.office_id')
                ->where('r.retrieval_id', $retrieval->id)
                ->get($retrievalOfficeColumns)
            : collect();

        // Retrieved offices stay visible in Retrieval (status = Retrieved) and are
        // also merged into Distribution on this form.
        $ownRetrieved = $retrievalOffices
            ->filter(fn ($o) => strtolower((string) ($o->retrieval_status ?? 'pending')) === 'retrieved')
            ->values();

        $priorRetrieved = ($ml && !empty($ml->doc_no))
            ? self::priorRetrievedOfficesForDocNo(
                (string) $ml->doc_no,
                (int) $docRequest->doc_type_id,
                $docRequest->sub_type_id ? (int) $docRequest->sub_type_id : null,
                isset($ml->revise_no) ? (int) $ml->revise_no : null,
                (int) $id
            )
            : [];

        $distribution = DB::table('dcs_document_distribution')->where('request_id', $id)->first();
        $distributionOfficeColumns = ['d.office_id', 'd.copies', 'o.office_name'];
        if (Schema::hasColumn('dcs_distribution_offices', 'distribution_date')) {
            $distributionOfficeColumns[] = 'd.distribution_date';
        }
        $distributionOffices = $distribution
            ? DB::table('dcs_distribution_offices as d')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
                ->where('d.distribution_id', $distribution->id)
                ->orderBy('d.sort_order')
                ->orderBy('d.id')
                ->get($distributionOfficeColumns)
            : collect();

        // Next revision: offices from THIS distribution (and prior-retrieved fallback)
        // start as Pending in Retrieval — new copies to pull back.
        $seedForPendingRetrieval = [];
        foreach ($distributionOffices as $row) {
            $seedForPendingRetrieval[(int) $row->office_id] = [
                'office_id' => (int) $row->office_id,
                'office_name' => $row->office_name ?? 'Unknown Office',
                'copies' => max(1, (int) ($row->copies ?? 1)),
            ];
        }
        foreach ($priorRetrieved as $row) {
            $oid = (int) ($row['office_id'] ?? 0);
            if ($oid <= 0 || isset($seedForPendingRetrieval[$oid])) {
                continue;
            }
            $seedForPendingRetrieval[$oid] = [
                'office_id' => $oid,
                'office_name' => $row['office_name'],
                'copies' => $row['copies'],
            ];
        }

        $ownRetrievedIds = $ownRetrieved->pluck('office_id')->map(fn ($id) => (int) $id)->all();
        $havePending = $retrievalOffices->pluck('office_id')->map(fn ($id) => (int) $id)->all();
        foreach ($seedForPendingRetrieval as $oid => $row) {
            if ($oid <= 0 || in_array($oid, $havePending, true) || in_array($oid, $ownRetrievedIds, true)) {
                continue;
            }
            $retrievalOffices->push((object) [
                'office_id' => $oid,
                'office_name' => $row['office_name'],
                'copies' => $row['copies'],
                'retrieval_status' => 'pending',
            ]);
            $havePending[] = $oid;
        }
        $retrievalOffices = $retrievalOffices->values();

        // Only THIS form's retrieved offices belong on Distribution automatically.
        $distributionOffices = self::mergeRetrievedIntoDistribution(
            $distributionOffices,
            $ownRetrieved,
            []
        );

        $approval = DB::table('dcs_approval_records')->where('request_id', $id)->first();

        $sourceOffices = collect();
        if ($ml) {
            $sourceOffices = DB::table('dcs_masterlist_source_offices as s')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 's.office_id')
                ->where('s.masterlist_id', $ml->id)
                ->get(['s.office_id', 'o.office_name']);
        }

        $syllabiSelect = [
            's.*',
            'pc.course_name',
        ];
        if (Schema::hasColumn('dcs_program_courses', 'course_code')) {
            $syllabiSelect[] = 'pc.course_code';
        }

        $syllabi = DB::table('dcs_syllabi as s')
            ->leftJoin('dcs_program_courses as pc', 'pc.id', '=', 's.course_id')
            ->where('s.request_id', $id)
            ->orderBy('s.id')
            ->get($syllabiSelect);

        $syllabiGroupsSeed = $syllabi->map(function ($syl) {
            $drfs = DB::table('dcs_syllabi_drf')->where('syllabi_id', $syl->id)->orderBy('id')->get();
            $copies = max(1, (int) $syl->no_copies);
            $rows = [];
            if ($copies === 1) {
                $firstDrf = $drfs->first();
                $rows[] = [
                    'faculty' => $drfs->pluck('faculty_name')->filter()->implode(', '),
                    'date_received' => self::formatDate($syl->date_received),
                    'time_received' => self::formatTime($syl->time_received),
                    'drf_available' => self::pgBool($firstDrf->is_drf_available ?? false),
                    'drf_no' => $firstDrf->drf_no ?? null,
                    'drf_date' => self::formatDate($firstDrf->drf_date ?? null),
                    'drf_received_date' => self::formatDate($firstDrf->drf_received_date ?? null),
                    'scanned_drf' => $firstDrf->scanned_drf ?? null,
                    'scanned_drf_name' => !empty($firstDrf->scanned_drf ?? null) ? basename($firstDrf->scanned_drf) : null,
                ];
            } else {
                foreach ($drfs->take($copies) as $drf) {
                    $rows[] = [
                        'faculty' => $drf->faculty_name,
                        'date_received' => self::formatDate($syl->date_received),
                        'time_received' => self::formatTime($syl->time_received),
                        'drf_available' => self::pgBool($drf->is_drf_available),
                        'drf_no' => $drf->drf_no,
                        'drf_date' => self::formatDate($drf->drf_date),
                        'drf_received_date' => self::formatDate($drf->drf_received_date),
                        'scanned_drf' => $drf->scanned_drf,
                        'scanned_drf_name' => $drf->scanned_drf ? basename($drf->scanned_drf) : null,
                    ];
                }
            }

            return [
                'course_name' => $syl->course_name ?? '',
                'course_code' => $syl->course_code ?? '',
                'availability' => self::pgBool($syl->is_available),
                'no_pages' => $syl->no_pages,
                'copies' => $copies,
                'college_id' => $syl->college_id,
                'program_id' => $syl->program_id,
                'semester_id' => $syl->semester_id,
                'school_year_id' => $syl->school_year_id,
                'rows' => $rows,
            ];
        })->values();

        $dcnOfficesSeed = $dcnOffices->map(fn ($o) => [
            'id' => $o->office_id,
            'label' => $o->office_name ?? 'Unknown',
        ])->values();

        $relatedDocsData = collect();
        if ($ml) {
            $relatedIds = DB::table('dcs_masterlist_related_docs')
                ->where('masterlist_id', $ml->id)
                ->pluck('related_doc_id');
            $relatedDocsData = DB::table('dcs_masterlist_registration')
                ->whereIn('id', $relatedIds)
                ->get(['id', 'doc_no', 'doc_title'])
                ->map(fn ($m) => [
                    'masterlist_id' => $m->id,
                    'doc_no' => $m->doc_no,
                    'doc_title' => $m->doc_title,
                    'label' => $m->doc_title . ($m->doc_no ? ' (' . $m->doc_no . ')' : ''),
                ]);
        }

        return [
            'blocked' => false,
            'docRequest' => $docRequest,
            'drf' => $drf,
            'dcn' => $dcn,
            'revisions' => $revisions,
            'masterlist' => $ml,
            'retrieval' => $retrieval,
            'retrievalOffices' => $retrievalOffices,
            'retrievedOfficesHidden' => collect(),
            'distribution' => $distribution,
            'distributionOffices' => $distributionOffices,
            'approval' => $approval,
            'drfOfficesSeed' => $drfOffices->map(fn ($o) => [
                'id' => $o->office_id,
                'label' => $o->office_name ?? 'Unknown',
            ])->values(),
            'dcnOfficesSeed' => $dcnOfficesSeed,
            'masterlistSourceSeed' => $sourceOffices->map(fn ($o) => [
                'type' => 'office',
                'id' => $o->office_id,
                'label' => $o->office_name ?? 'Unknown',
            ])->filter(fn ($o) => $o['id'])->values(),
            'masterlistOriginatorSeed' => ($ml && $ml->originator_name)
                ? [[
                    'type' => !empty($ml->originator_id ?? null) ? 'office' : 'name',
                    'id' => ($ml->originator_id ?? null) ?: ('n' . $ml->id),
                    'label' => $ml->originator_name,
                ]]
                : [],
            'syllabiGroupsSeed' => $syllabiGroupsSeed,
            'syllabiContextSeed' => $syllabi->first(),
            'isSyllabiLikeEdit' => RegisterPersistHelper::isSyllabiLikeSubTypeRow(
                RegisterPersistHelper::dcsDocType($docRequest->sub_type_id)
            ),
            'relatedDocsData' => $relatedDocsData,
        ];
    }

    /**
     * Offices marked retrieved on lower revise_no rows of the same doc family.
     * Used when editing a higher obsolete revision or starting a new revised registration.
     *
     * @return list<array{office_id:int,office_name:string,copies:int,retrieval_status:string}>
     */
    public static function priorRetrievedOfficesForDocNo(
        string $docNo,
        int $docTypeId,
        ?int $subTypeId,
        ?int $beforeReviseNo,
        ?int $excludeRequestId
    ): array {
        if ($docNo === '' || $docTypeId < 1 || !Schema::hasTable('dcs_retrieval_offices')) {
            return [];
        }
        if (!Schema::hasColumn('dcs_retrieval_offices', 'retrieval_status')) {
            return [];
        }

        $mlQuery = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->where('ml.doc_no', $docNo)
            ->where('dr.doc_type_id', $docTypeId);

        if ($subTypeId) {
            $mlQuery->where('dr.sub_type_id', $subTypeId);
        } else {
            $mlQuery->whereNull('dr.sub_type_id');
        }

        if (Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            $mlQuery->whereNull('dr.deleted_at');
        }
        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            $mlQuery->whereIn('ml.revision_status', ['latest', 'obsolete']);
        }
        if ($beforeReviseNo !== null) {
            $mlQuery->where('ml.revise_no', '<', $beforeReviseNo);
        }
        if ($excludeRequestId) {
            $mlQuery->where('ml.request_id', '!=', $excludeRequestId);
        }

        $priorRequestIds = $mlQuery->pluck('ml.request_id')->unique()->filter()->values()->all();
        if ($priorRequestIds === []) {
            return [];
        }

        $rows = DB::table('dcs_retrieval_offices as r')
            ->join('dcs_document_retrieval as ret', 'ret.id', '=', 'r.retrieval_id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'r.office_id')
            ->whereIn('ret.request_id', $priorRequestIds)
            ->where('r.retrieval_status', 'retrieved')
            ->whereNotNull('r.office_id')
            ->orderByDesc('ret.id')
            ->orderByDesc('r.id')
            ->get(['r.office_id', 'o.office_name', 'r.copies']);

        $byOffice = [];
        foreach ($rows as $row) {
            $oid = (int) $row->office_id;
            if ($oid <= 0 || isset($byOffice[$oid])) {
                continue;
            }
            $byOffice[$oid] = [
                'office_id' => $oid,
                'office_name' => $row->office_name ?? 'Unknown Office',
                'copies' => max(1, (int) ($row->copies ?? 1)),
                'retrieval_status' => 'retrieved',
            ];
        }

        return array_values($byOffice);
    }

    /**
     * Put retrieved offices onto Distribution (not Retrieval).
     * Own request retrieved + prior-revision retrieved.
     */
    private static function mergeRetrievedIntoDistribution(
        Collection $distributionOffices,
        Collection $ownRetrieved,
        array $priorRetrieved
    ): Collection {
        $have = $distributionOffices->pluck('office_id')->map(fn ($id) => (int) $id)->filter()->all();

        $toAdd = [];
        foreach ($ownRetrieved as $row) {
            $toAdd[] = [
                'office_id' => (int) ($row->office_id ?? 0),
                'office_name' => $row->office_name ?? 'Unknown Office',
                'copies' => max(1, (int) ($row->copies ?? 1)),
            ];
        }
        foreach ($priorRetrieved as $row) {
            $toAdd[] = [
                'office_id' => (int) ($row['office_id'] ?? 0),
                'office_name' => $row['office_name'] ?? 'Unknown Office',
                'copies' => max(1, (int) ($row['copies'] ?? 1)),
            ];
        }

        foreach ($toAdd as $row) {
            $oid = (int) $row['office_id'];
            if ($oid <= 0 || in_array($oid, $have, true)) {
                continue;
            }
            $distributionOffices->push((object) [
                'office_id' => $oid,
                'office_name' => $row['office_name'],
                'copies' => $row['copies'],
            ]);
            $have[] = $oid;
        }

        return $distributionOffices->values();
    }

    public static function hydrateRequests(Collection $docs): Collection
    {
        if ($docs->isEmpty()) {
            return $docs;
        }

        $ids = $docs->pluck('id')->all();
        $typeIds = $docs->pluck('doc_type_id')->merge($docs->pluck('sub_type_id'))->filter()->unique()->all();
        $types = $typeIds
            ? DB::table('dcs_doc_types')->whereIn('id', $typeIds)->get()->keyBy('id')
            : collect();

        $mls = DB::table('dcs_masterlist_registration')->whereIn('request_id', $ids)->get()->keyBy('request_id');
        $mlIds = $mls->pluck('id')->all();

        $sourceByMl = collect();
        $relatedByMl = collect();
        if ($mlIds) {
            $sourceByMl = DB::table('dcs_masterlist_source_offices as so')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'so.office_id')
                ->whereIn('so.masterlist_id', $mlIds)
                ->get(['so.masterlist_id', 'so.office_id', 'o.office_name'])
                ->groupBy('masterlist_id')
                ->map(fn ($rows) => $rows->map(function ($row) {
                    $row->office = (object) ['office_name' => $row->office_name];

                    return $row;
                }));

            $relatedA = DB::table('dcs_masterlist_related_docs as rd')
                ->join('dcs_masterlist_registration as rel', 'rel.id', '=', 'rd.related_doc_id')
                ->whereIn('rd.masterlist_id', $mlIds)
                ->get(['rd.masterlist_id', 'rel.doc_no', 'rel.doc_title']);
            $relatedB = DB::table('dcs_masterlist_related_docs as rd')
                ->join('dcs_masterlist_registration as rel', 'rel.id', '=', 'rd.masterlist_id')
                ->whereIn('rd.related_doc_id', $mlIds)
                ->get(['rd.related_doc_id as masterlist_id', 'rel.doc_no', 'rel.doc_title']);
            $relatedByMl = $relatedA->concat($relatedB)->groupBy('masterlist_id');
        }

        $drfs = DB::table('dcs_document_request_form')->whereIn('request_id', $ids)->get()->keyBy('request_id');
        $dcns = DB::table('dcs_document_change_notice')->whereIn('request_id', $ids)->get()->keyBy('request_id');
        $dcnIds = $dcns->pluck('id')->all();
        $revsByDcn = $dcnIds
            ? DB::table('dcs_doc_revision')->whereIn('dcn_id', $dcnIds)->orderBy('id')->get()->groupBy('dcn_id')
            : collect();

        $dists = DB::table('dcs_document_distribution')->whereIn('request_id', $ids)->get()->keyBy('request_id');
        $distIds = $dists->pluck('id')->all();
        $distOffices = $distIds
            ? DB::table('dcs_distribution_offices as dof')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'dof.office_id')
                ->whereIn('dof.distribution_id', $distIds)
                ->orderBy('dof.sort_order')
                ->orderBy('dof.id')
                ->get(['dof.distribution_id', 'dof.office_id', 'dof.copies', 'o.office_name'])
                ->groupBy('distribution_id')
                ->map(fn ($rows) => $rows->map(function ($row) {
                    $row->office = (object) ['office_name' => $row->office_name];

                    return $row;
                }))
            : collect();

        $rets = DB::table('dcs_document_retrieval')->whereIn('request_id', $ids)->get()->keyBy('request_id');
        $retIds = $rets->pluck('id')->all();
        $retOffices = $retIds
            ? DB::table('dcs_retrieval_offices as rof')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'rof.office_id')
                ->whereIn('rof.retrieval_id', $retIds)
                ->get(['rof.retrieval_id', 'rof.office_id', 'rof.copies', 'o.office_name'])
                ->groupBy('retrieval_id')
                ->map(fn ($rows) => $rows->map(function ($row) {
                    $row->office = (object) ['office_name' => $row->office_name];

                    return $row;
                }))
            : collect();

        $approvals = DB::table('dcs_approval_records')->whereIn('request_id', $ids)->get()->groupBy('request_id');
        $stamps = DB::table('dcs_document_stamps')->whereIn('document_request_id', $ids)->get()->groupBy('document_request_id');

        $historySyllabiSelect = ['s.*', 'c.course_name'];
        if (Schema::hasColumn('dcs_program_courses', 'course_code')) {
            $historySyllabiSelect[] = 'c.course_code';
        }

        $syllabi = DB::table('dcs_syllabi as s')
            ->leftJoin('dcs_program_courses as c', 'c.id', '=', 's.course_id')
            ->whereIn('s.request_id', $ids)
            ->get($historySyllabiSelect);
        $sylIds = $syllabi->pluck('id')->all();
        $drfsBySyl = $sylIds
            ? DB::table('dcs_syllabi_drf')->whereIn('syllabi_id', $sylIds)->get()->groupBy('syllabi_id')
            : collect();
        $syllabiByReq = $syllabi->groupBy('request_id')->map(fn ($rows) => $rows->map(function ($row) use ($drfsBySyl) {
            $row->course = (object) [
                'course_name' => $row->course_name,
                'course_code' => $row->course_code ?? null,
            ];
            $row->drfs = $drfsBySyl->get($row->id, collect());

            return $row;
        }));

        foreach ($docs as $doc) {
            $doc->docType = $types->get($doc->doc_type_id);
            $doc->subType = $types->get($doc->sub_type_id);
            $ml = $mls->get($doc->id);
            if ($ml) {
                $ml->sourceOffices = $sourceByMl->get($ml->id, collect());
                $ml->relatedList = $relatedByMl->get($ml->id, collect())->unique(fn ($r) => $r->doc_no . '|' . $r->doc_title)->values();
            }
            $doc->masterlistRegistration = $ml;
            $doc->documentRequestForm = $drfs->get($doc->id);
            $dcn = $dcns->get($doc->id);
            if ($dcn) {
                $dcn->revisions = $revsByDcn->get($dcn->id, collect());
            }
            $doc->documentChangeNotice = $dcn;
            $dist = $dists->get($doc->id);
            if ($dist) {
                $dist->offices = $distOffices->get($dist->id, collect());
            }
            $doc->documentDistribution = $dist;
            $ret = $rets->get($doc->id);
            if ($ret) {
                $ret->offices = $retOffices->get($ret->id, collect());
            }
            $doc->documentRetrieval = $ret;
            $doc->approvalRecords = $approvals->get($doc->id, collect());
            $doc->stamps = $stamps->get($doc->id, collect());
            $doc->syllabi = $syllabiByReq->get($doc->id, collect());
        }

        return $docs;
    }

    public static function hydrateMasterlists(Collection $records): Collection
    {
        if ($records->isEmpty()) {
            return $records;
        }

        $requestIds = $records->pluck('request_id')->filter()->unique()->all();
        $requests = $requestIds
            ? self::hydrateRequests(DB::table('dcs_document_requests')->whereIn('id', $requestIds)->get())
                ->keyBy(fn ($row) => (int) $row->id)
            : collect();
        $typeIds = $records->pluck('doc_type_id')->filter()->unique()->all();
        $types = $typeIds
            ? DB::table('dcs_doc_types')->whereIn('id', $typeIds)->get()->keyBy('id')
            : collect();
        $mlIds = $records->pluck('id')->all();
        $sourceByMl = DB::table('dcs_masterlist_source_offices as so')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'so.office_id')
            ->whereIn('so.masterlist_id', $mlIds)
            ->get(['so.masterlist_id', 'so.office_id', 'o.office_name'])
            ->groupBy('masterlist_id')
            ->map(fn ($rows) => $rows->map(function ($row) {
                $row->office = (object) ['office_name' => $row->office_name];

                return $row;
            }));

        foreach ($records as $ml) {
            $ml->request = $requests->get((int) $ml->request_id);
            $ml->docType = $types->get($ml->doc_type_id);
            $ml->sourceOffices = $sourceByMl->get($ml->id, collect());
        }

        return $records;
    }

    /** JSON-shaped catalog matching the Register Alpine/UI keys. */
    public static function jsCatalog(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $docTypes = DB::table('dcs_doc_types')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'doc_type_name'])
            ->map(fn ($d) => [
                'doc_type_id' => $d->id,
                'parent_id' => $d->parent_id,
                'doc_type_name' => $d->doc_type_name,
                'is_syllabi_like' => self::isSyllabiLikeName($d->doc_type_name),
            ])
            ->values()
            ->all();

        $checklistsByVersion = [];
        $rows = DB::table('dcs_checklist_version as cv')
            ->join('dcs_checklist_types as ct', 'ct.id', '=', 'cv.checklist_id')
            ->orderBy('ct.id')
            ->get([
                'cv.version_id',
                'ct.id as checklist_id',
                'ct.checklist_name',
            ]);

        foreach ($rows as $row) {
            $checklistsByVersion[(string) $row->version_id][] = [
                'checklist_id' => $row->checklist_id,
                'checklist_name' => $row->checklist_name,
            ];
        }

        $programsByCollege = [];
        foreach (DB::table('dcs_programs')->orderBy('program_name')->get(['id', 'college_id', 'program_name', 'program_code']) as $p) {
            $programsByCollege[(string) $p->college_id][] = [
                'program_id' => $p->id,
                'program_name' => $p->program_name,
                'program_code' => $p->program_code,
            ];
        }

        $facultiesByCourse = collect();
        if (Schema::hasTable('dcs_program_course_faculties')) {
            $facultiesByCourse = DB::table('dcs_program_course_faculties as pcf')
                ->join('dcs_faculties as f', 'f.id', '=', 'pcf.faculty_id')
                ->orderBy('f.faculty_name')
                ->get(['pcf.program_course_id', 'f.id', 'f.faculty_name'])
                ->groupBy('program_course_id');
        }

        $courseColumns = ['id', 'program_id', 'semester_id', 'course_name'];
        if (Schema::hasColumn('dcs_program_courses', 'course_code')) {
            $courseColumns[] = 'course_code';
        }

        $coursesByProgramSemester = [];
        foreach (DB::table('dcs_program_courses')->orderBy('course_name')->get($courseColumns) as $c) {
            $coursesByProgramSemester[$c->program_id . ':' . $c->semester_id][] = [
                'id' => $c->id,
                'course_name' => $c->course_name,
                'course_code' => $c->course_code ?? '',
                'faculties' => collect($facultiesByCourse->get($c->id) ?? $facultiesByCourse->get((string) $c->id) ?? [])->map(fn ($f) => [
                    'id' => $f->id,
                    'faculty_name' => $f->faculty_name,
                ])->values()->all(),
            ];
        }

        return $cache = [
            'offices' => DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')
                ->where('is_active', true)
                ->orderBy('office_name')
                ->get(['id', 'office_name', 'office_code', 'cluster'])
                ->map(fn ($o) => [
                    'office_id' => $o->id,
                    'office_name' => $o->office_name,
                    'office_code' => $o->office_code,
                    'cluster' => $o->cluster,
                ])
                ->values()
                ->all(),
            'clusters' => DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_cluster') ? 'sys_cluster' : 'cluster')
                ->where('is_active', true)
                ->orderBy('cluster_name')
                ->get(['id', 'cluster_name', 'cluster_code'])
                ->map(fn ($c) => [
                    'cluster_id' => $c->id,
                    'cluster_name' => $c->cluster_name,
                    'cluster_code' => $c->cluster_code,
                ])
                ->values()
                ->all(),
            'docTypes' => $docTypes,
            'versionTypes' => DB::table('dcs_version_type')
                ->orderBy('version_name')
                ->get(['id', 'version_name'])
                ->map(fn ($v) => [
                    'version_id' => $v->id,
                    'version_name' => $v->version_name,
                ])
                ->values()
                ->all(),
            'approvalBodies' => DB::table('dcs_approval_body')
                ->orderBy('approval_name')
                ->get(['id', 'approval_name'])
                ->map(fn ($a) => [
                    'approval_body_id' => $a->id,
                    'approval_name' => $a->approval_name,
                ])
                ->values()
                ->all(),
            'originators' => Schema::hasTable('dcs_originators')
                ? DB::table('dcs_originators')
                    ->orderBy('originator_name')
                    ->get(['id', 'originator_name'])
                    ->map(fn ($o) => [
                        'originator_id' => $o->id,
                        'originator_name' => $o->originator_name,
                    ])
                    ->values()
                    ->all()
                : [],
            'checklistsByVersion' => $checklistsByVersion,
            'colleges' => DB::table('dcs_colleges')
                ->orderBy('college_name')
                ->get(['id', 'college_name'])
                ->map(fn ($c) => [
                    'college_id' => $c->id,
                    'college_name' => $c->college_name,
                ])
                ->values()
                ->all(),
            'semesters' => DB::table('dcs_semesters')
                ->orderBy('id')
                ->get(['id', 'semester_name'])
                ->map(fn ($s) => [
                    'semester_id' => $s->id,
                    'semester_name' => $s->semester_name,
                ])
                ->values()
                ->all(),
            'schoolYears' => DB::table('dcs_school_years')
                ->orderBy('school_year')
                ->get(['id', 'school_year'])
                ->map(fn ($y) => [
                    'school_year_id' => $y->id,
                    'school_year' => $y->school_year,
                ])
                ->values()
                ->all(),
            'programsByCollege' => $programsByCollege,
            'coursesByProgramSemester' => $coursesByProgramSemester,
            'faculties' => DB::table('dcs_faculties')
                ->orderBy('faculty_name')
                ->get(['id', 'faculty_name', 'college_id'])
                ->map(fn ($f) => [
                    'id' => $f->id,
                    'faculty_name' => $f->faculty_name,
                    'college_id' => $f->college_id,
                ])
                ->values()
                ->all(),
        ];
    }
}
