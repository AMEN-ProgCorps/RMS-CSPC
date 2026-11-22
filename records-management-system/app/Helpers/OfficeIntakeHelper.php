<?php

namespace App\Helpers;

use App\Helpers\DcsPrintTemplateHelper;
use App\Services\DocumentStorageService;
use App\Services\DcsNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Office Intake (non-RFIU DRF / DCN) — create once, then view / print / register handoff.
 *
 * LOCATION: app/Helpers/OfficeIntakeHelper.php (Composer-autoloaded). Do not move under resources/views.
 * UI callers: resources/views/pages/dcs/office-intake/* and Admin Request review pages.
 * Tables: dcs_office_intake_drf / dcs_office_intake_dcn (+ offices, originators, reviewers, approvals).
 * Permission gate: RegisterQueryHelper::canAccessOfficeIntake() / canBrowseAllOfficeIntake().
 *
 * FLOW (happy path):
 *   storeDrf|storeDcn → RFIU listPendingOfficeRequests → beginRegister → RegisterPersistHelper::persist
 *   → markIntakeRegistered / notifyReadyForPrintSign → officeMayPrintIntake.
 *
 * DEBUG MAP (symptom → start here):
 *   My DCN/DRF crash / blank originator     → hydrateIntakeOriginators, findOfficeDcn/Drf, listMy*
 *   403 on office form                      → userCanAccessOfficeIntakeRecord, applyOwnOfficeIntakeScope
 *   Print locked for office                 → officeMayPrintIntake, rfio_print_notified_at, notifyReadyForPrintSign
 *   Proceed to Register disabled / wrong URL→ beginRegister, isIntakeClaimedPending, registerContinueUrl
 *   Duplicate DCN/DRF blocked               → findOpenDuplicate*, assertNoOpenDuplicate*
 *   Wrong register prefill                  → registerPrefillForDrf/Dcn, resolveOfficeDcnDocument
 *   Inventory / incoming counts wrong       → officeDocument*, applyOffice*Scope, bumpOfficeInventoryCache
 *
 * Related helpers: RegisterPersistHelper (writes), RegisterQueryHelper (access + masterlist reads).
*/
class OfficeIntakeHelper
{
    public const IMMUTABLE_MESSAGE = 'This document cannot be edited.';

    public const DRF_TABLE = 'dcs_office_intake_drf';

    public const DCN_TABLE = 'dcs_office_intake_dcn';

    public const DRF_OFFICES_TABLE = 'dcs_office_drf_offices';

    public const DCN_OFFICES_TABLE = 'dcs_office_dcn_offices';

    public const REVIEWERS_TABLE = 'dcs_office_dcn_reviewers';

    public const APPROVALS_TABLE = 'dcs_office_dcn_approvals';

    // =====================================================================
    // SECTION: ACCESS & OWNERSHIP — who may open / print office intake forms
    // =====================================================================
    
    /**
     * Returns the office-intake table name for a form type (`drf` or `dcn`).
     *
     * DEBUG: wrong table/type mixups on My DRF vs My DCN → check the type argument here.
     */
    public static function intakeTable(string $type): string
    {
        return $type === 'dcn' ? self::DCN_TABLE : self::DRF_TABLE;
    }

    /**
     * Tells whether the signed-in user is allowed to use Office Intake (My DRF / My DCN).
     *
     * DEBUG: Office Intake missing from nav / 403 on office pages → canAccessOfficeIntake().
     */
    public static function canAccessIntake(): bool
    {
        return RegisterQueryHelper::canAccessOfficeIntake();
    }

    /**
     * Stops the request with 403 if the user cannot use Office Intake.
     *
     * DEBUG: unexpected 403 on create/show → this guard or canAccessIntake().
     */
    public static function assertCanAccessIntake(): void
    {
        abort_unless(self::canAccessIntake(), 403, 'You do not have access to office DRF/DCN intake.');
    }

    /**
     * Allows print/view for office creators and RFIU reviewers of an intake form.
     *
     * DEBUG: print/view 403 → officeMayPrintIntake() and ownership asserts.
     */
    /**
     * Stops with 403 when print is still locked (CSS/JS alone is not enough).
     * RFIU/reviewers (canBrowseAllOfficeIntake) always pass via officeMayPrintIntake.
     */
    public static function assertOfficeIntakePrintAllowed(?object $record = null, bool $viewerMode = false): void
    {
        self::assertCanAccessIntake();
        if ($record === null) {
            return;
        }

        abort_unless(
            self::officeMayPrintIntake($record),
            403,
            'Print unlocks after RFIU confirms this form and notifies you to print and sign.'
        );
    }

    /**
     * Tells whether an office user may print this form (only after RFIU unlocks print).
     *
     * DEBUG: Print button locked after RFIU notice → rfio_print_notified_at + this method.
     */
    public static function officeMayPrintIntake(?object $record): bool
    {
        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            return true;
        }

        return $record !== null && ! empty($record->rfio_print_notified_at);
    }

    /**
     * Returns which intake form ids are unlocked for printing (used by live print-unlock polling).
     *
     * DEBUG: print-unlock polling never unlocks → print-ready API + this method.
     * @param  list<int>  $ids
     * @return array{ready: list<int>}
     */
    public static function officeIntakePrintReadyIds(string $type, array $ids): array
    {
        $type = strtolower($type);
        abort_unless(in_array($type, ['drf', 'dcn'], true), 404);
        self::assertCanAccessIntake();

        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 50);
        if ($ids === []) {
            return ['ready' => []];
        }

        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'rfio_print_notified_at')) {
            return ['ready' => []];
        }

        $query = DB::table($table)->whereIn('id', $ids)->whereNotNull('rfio_print_notified_at');
        if (! RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            $userId = (int) auth()->id();
            $officeId = (int) (RegisterQueryHelper::currentOfficeId() ?? 0);
            $query->where(function ($q) use ($userId, $officeId, $table) {
                if ($userId > 0) {
                    $q->where('created_by', $userId);
                }
                if ($officeId > 0) {
                    $detailsTable = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
                    $q->orWhereExists(function ($officeQ) use ($officeId, $detailsTable, $table) {
                        $officeQ->select(DB::raw(1))
                            ->from($detailsTable . ' as ofi_ad')
                            ->whereColumn('ofi_ad.account_id', $table . '.created_by')
                            ->where('ofi_ad.office_id', $officeId);
                    });
                }
            });
        }

        return [
            'ready' => $query->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    /**
     * Limits My DRF/DCN queries to the current office (or the user’s own unlinked drafts).
     *
     * DEBUG: wrong office sees another office forms (or empty list) → this scope.
     */
    public static function applyOwnOfficeIntakeScope($query, string $alias, string $type): void
    {
        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            return;
        }

        $type = strtolower($type);
        $userId = (int) auth()->id();
        $officeId = (int) (RegisterQueryHelper::currentOfficeId() ?? 0);
        $linkTable = $type === 'dcn' ? self::DCN_OFFICES_TABLE : self::DRF_OFFICES_TABLE;
        $linkFk = $type === 'dcn' ? 'office_intake_dcn_id' : 'office_intake_drf_id';

        $query->where(function ($q) use ($alias, $userId, $officeId, $linkTable, $linkFk) {
            if ($officeId > 0 && Schema::hasTable($linkTable)) {
                $q->whereExists(function ($officeQ) use ($alias, $officeId, $linkTable, $linkFk) {
                    $officeQ->select(DB::raw(1))
                        ->from($linkTable.' as ofi_lnk')
                        ->whereColumn('ofi_lnk.'.$linkFk, $alias.'.id')
                        ->where('ofi_lnk.office_id', $officeId);
                });
            }

            // Own drafts/forms with no office links yet (still creating).
            $q->orWhere(function ($own) use ($alias, $userId, $linkTable, $linkFk) {
                if ($userId > 0) {
                    $own->where($alias.'.created_by', $userId);
                } else {
                    $own->whereRaw('1 = 0');
                }

                if (Schema::hasTable($linkTable)) {
                    $own->whereNotExists(function ($anyLink) use ($alias, $linkTable, $linkFk) {
                        $anyLink->select(DB::raw(1))
                            ->from($linkTable.' as ofi_any')
                            ->whereColumn('ofi_any.'.$linkFk, $alias.'.id');
                    });
                }
            });
        });
    }

    /**
     * Tells whether the user may open a specific office DRF/DCN record.
     *
     * DEBUG: 403 on show/edit for own office form → linked offices / created_by here.
     */
    public static function userCanAccessOfficeIntakeRecord(object $record, string $type): bool
    {
        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            return true;
        }

        $userId = (int) auth()->id();
        if ($userId > 0 && (int) ($record->created_by ?? 0) === $userId) {
            $officeId = (int) (RegisterQueryHelper::currentOfficeId() ?? 0);
            $type = strtolower($type);
            $linkTable = $type === 'dcn' ? self::DCN_OFFICES_TABLE : self::DRF_OFFICES_TABLE;
            $linkFk = $type === 'dcn' ? 'office_intake_dcn_id' : 'office_intake_drf_id';
            $recordId = (int) ($record->id ?? 0);

            if ($officeId > 0 && $recordId > 0 && Schema::hasTable($linkTable)) {
                $linkedIds = DB::table($linkTable)->where($linkFk, $recordId)->pluck('office_id')->map(fn ($id) => (int) $id)->all();
                if ($linkedIds !== [] && ! in_array($officeId, $linkedIds, true)) {
                    return false;
                }
            }

            return true;
        }

        $officeId = (int) (RegisterQueryHelper::currentOfficeId() ?? 0);
        if ($officeId < 1) {
            return false;
        }

        $type = strtolower($type);
        $linkTable = $type === 'dcn' ? self::DCN_OFFICES_TABLE : self::DRF_OFFICES_TABLE;
        $linkFk = $type === 'dcn' ? 'office_intake_dcn_id' : 'office_intake_drf_id';
        $recordId = (int) ($record->id ?? 0);
        if ($recordId < 1 || ! Schema::hasTable($linkTable)) {
            return false;
        }

        return DB::table($linkTable)->where($linkFk, $recordId)->where('office_id', $officeId)->exists();
    }

    /**
     * Stops with 403 if the user may not view this office DRF.
     *
     * DEBUG: DRF show 403 → userCanAccessOfficeIntakeRecord(drf).
     */
    public static function assertOwnsDrf(object $drf): void
    {
        abort_unless(
            self::userCanAccessOfficeIntakeRecord($drf, 'drf'),
            403,
            'You can only view Document Request Forms from your office.'
        );
    }

    /**
     * Stops with 403 if the user may not view this office DCN.
     *
     * DEBUG: DCN show 403 → userCanAccessOfficeIntakeRecord(dcn).
     */
    public static function assertOwnsDcn(object $dcn): void
    {
        abort_unless(
            self::userCanAccessOfficeIntakeRecord($dcn, 'dcn'),
            403,
            'You can only view Document Change Notices from your office.'
        );
    }

    /**
     * Redirects back when the intake form is locked and must not be edited.
     *
     * DEBUG: unexpected edit blocked → canOfficeEditIntake / isIntakeRegistered.
     */
    public static function rejectMutation(): RedirectResponse
    {
        return redirect()->back()->with('error', self::IMMUTABLE_MESSAGE);
    }

    /**
     * Loads the current office’s Document Request Forms for My DRF.
     *
     * DEBUG: My DRF empty or wrong rows → loadMyIntakeRows + applyOwnOfficeIntakeScope.
     * @param  array{limit?: int|null, with_submission?: bool, action_only?: bool}  $options
     * @return \Illuminate\Support\Collection<int, object>
     */
    // =====================================================================
    // SECTION: MY DRF / MY DCN LISTS — office dashboard tables & pipeline chips
    // =====================================================================
    
    public static function listMyDrf(array $options = [])
    {
        return self::loadMyIntakeRows('drf', $options);
    }

    /**
     * Loads the current office’s Document Change Notices for My DCN.
     *
     * DEBUG: My DCN crash on originator → hydrateIntakeOriginators; empty list → scope.
     * @param  array{limit?: int|null, with_submission?: bool, action_only?: bool}  $options
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function listMyDcn(array $options = [])
    {
        return self::loadMyIntakeRows('dcn', $options);
    }

    /**
     * Paginates My DRF for the office index page.
     *
     * DEBUG: pagination/filter issues on My DRF → paginateMyIntakeRows.
     */
    public static function paginateMyDrf(int $perPage = 25, bool $withSubmissionMeta = false)
    {
        return self::paginateMyIntakeRows('drf', $perPage, $withSubmissionMeta);
    }

    /**
     * Paginates My DCN for the office index page.
     *
     * DEBUG: My DCN index issues → paginateMyIntakeRows + hydrateIntakeOriginators.
     */
    public static function paginateMyDcn(int $perPage = 25, bool $withSubmissionMeta = false)
    {
        return self::paginateMyIntakeRows('dcn', $perPage, $withSubmissionMeta);
    }

    /**
     * Dashboard pipeline chips without loading full list UIs.
     * @return array{total: int, registered: int, correction: int, print_ready: int, printed: int, submitted: int}
     */
    public static function officeIntakePipelineCounts(string $type): array
    {
        $type = strtolower($type) === 'dcn' ? 'dcn' : 'drf';
        $userId = (int) auth()->id();

        return self::rememberOfficeInventory(
            'pipeline.'.$type.'.'.$userId,
            function () use ($type) {
                $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
                $alias = $type;
                $empty = [
                    'total' => 0,
                    'registered' => 0,
                    'correction' => 0,
                    'print_ready' => 0,
                    'printed' => 0,
                    'submitted' => 0,
                ];
                if (! Schema::hasTable($table)) {
                    return $empty;
                }

                self::ensureOfficePrintedColumns($table);
                self::reconcileClaimedIntakesWithRegistrations();

                $query = DB::table($table.' as '.$alias);
                self::applyOwnOfficeIntakeScope($query, $alias, $type);

                $hasReqId = Schema::hasColumn($table, 'registered_request_id');
                $hasRegisteredAt = Schema::hasColumn($table, 'rfio_registered_at');
                $hasUnlock = Schema::hasColumn($table, 'edit_unlocked_at');
                $hasPrint = Schema::hasColumn($table, 'rfio_print_notified_at');
                $hasPrinted = Schema::hasColumn($table, 'office_printed_at');
                $hasDraftCol = $hasReqId
                    && Schema::hasTable('dcs_document_requests')
                    && Schema::hasColumn('dcs_document_requests', 'is_draft');

                if ($hasDraftCol) {
                    $query->leftJoin('dcs_document_requests as ofi_pipe_req', 'ofi_pipe_req.id', '=', $alias.'.registered_request_id');
                }

                $isDraft = $hasDraftCol
                    ? '(COALESCE('.$alias.'.registered_request_id, 0) > 0 AND COALESCE(ofi_pipe_req.is_draft, false) = true)'
                    : 'false';
                $looksRegistered = 'false';
                if ($hasRegisteredAt && $hasReqId) {
                    $looksRegistered = '('.$alias.'.rfio_registered_at IS NOT NULL OR COALESCE('.$alias.'.registered_request_id, 0) > 0)';
                } elseif ($hasRegisteredAt) {
                    $looksRegistered = '('.$alias.'.rfio_registered_at IS NOT NULL)';
                } elseif ($hasReqId) {
                    $looksRegistered = '(COALESCE('.$alias.'.registered_request_id, 0) > 0)';
                }
                $isRegistered = '('.$looksRegistered.' AND NOT '.$isDraft.')';
                $isCorrection = $hasUnlock
                    ? '(NOT '.$isRegistered.' AND '.$alias.'.edit_unlocked_at IS NOT NULL)'
                    : 'false';
                $isPrintNotified = $hasPrint
                    ? '(NOT '.$isRegistered.' AND NOT '.$isCorrection.' AND '.$alias.'.rfio_print_notified_at IS NOT NULL)'
                    : 'false';
                $isPrintReady = $hasPrinted
                    ? '('.$isPrintNotified.' AND '.$alias.'.office_printed_at IS NULL)'
                    : $isPrintNotified;
                $isPrinted = $hasPrinted
                    ? '('.$isPrintNotified.' AND '.$alias.'.office_printed_at IS NOT NULL)'
                    : 'false';

                $row = $query->selectRaw('COUNT(*)::int as total')
                    ->selectRaw('COUNT(*) FILTER (WHERE '.$isRegistered.')::int as registered')
                    ->selectRaw('COUNT(*) FILTER (WHERE '.$isCorrection.')::int as correction')
                    ->selectRaw('COUNT(*) FILTER (WHERE '.$isPrintReady.')::int as print_ready')
                    ->selectRaw('COUNT(*) FILTER (WHERE '.$isPrinted.')::int as printed')
                    ->first();

                $total = (int) ($row->total ?? 0);
                $registered = (int) ($row->registered ?? 0);
                $correction = (int) ($row->correction ?? 0);
                $printReady = (int) ($row->print_ready ?? 0);
                $printed = (int) ($row->printed ?? 0);

                return [
                    'total' => $total,
                    'registered' => $registered,
                    'correction' => $correction,
                    'print_ready' => $printReady,
                    'printed' => $printed,
                    'submitted' => max(0, $total - $registered - $correction - $printReady - $printed),
                ];
            },
            20
        );
    }

    /**
     * Loads or finds data for load my intake rows.
     * @param  array{limit?: int|null, with_submission?: bool, action_only?: bool}  $options
     * @return \Illuminate\Support\Collection<int, object>
     */
    private static function loadMyIntakeRows(string $type, array $options = [])
    {
        $type = strtolower($type) === 'dcn' ? 'dcn' : 'drf';
        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        $alias = $type;
        if (! Schema::hasTable($table)) {
            return collect();
        }

        self::ensureOfficePrintedColumns($table);
        self::reconcileClaimedIntakesWithRegistrations();

        $query = DB::table($table.' as '.$alias);
        self::applyOwnOfficeIntakeScope($query, $alias, $type);
        self::applyIntakeActionOnlyFilter($query, $alias, $table, ! empty($options['action_only']));

        $limit = isset($options['limit']) ? (int) $options['limit'] : null;
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->orderByDesc($alias.'.id')->get(self::intakeListColumns($type));

        return self::enrichIntakeListRows(
            $type,
            $rows,
            array_key_exists('with_submission', $options) ? (bool) $options['with_submission'] : true
        );
    }

    /**
     * Lists or paginates data for paginate my intake rows.
     */
    private static function paginateMyIntakeRows(string $type, int $perPage = 25, bool $withSubmissionMeta = false)
    {
        $type = strtolower($type) === 'dcn' ? 'dcn' : 'drf';
        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        $alias = $type;
        $perPage = max(10, min(100, $perPage));

        if (! Schema::hasTable($table)) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        self::ensureOfficePrintedColumns($table);
        self::reconcileClaimedIntakesWithRegistrations();

        $query = DB::table($table.' as '.$alias);
        self::applyOwnOfficeIntakeScope($query, $alias, $type);
        $paginator = $query->orderByDesc($alias.'.id')->paginate($perPage, self::intakeListColumns($type));
        $enriched = self::enrichIntakeListRows($type, collect($paginator->items()), $withSubmissionMeta);
        $paginator->setCollection($enriched);

        return $paginator;
    }

    /**
     * Helper used for intake list columns.
     */
    private static function intakeListColumns(string $type): array
    {
        $type = strtolower($type) === 'dcn' ? 'dcn' : 'drf';
        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        $alias = $type;

        if ($type === 'drf') {
        $cols = [
            'drf.id',
            'drf.drf_no',
            'drf.drf_date',
            'drf.doc_title',
            'drf.created_at',
            'drf.created_by',
        ];
            if (Schema::hasColumn($table, 'originator_name')) {
            $cols[] = 'drf.originator_name';
        }
        } else {
            $cols = array_values(array_filter([
                'dcn.id',
                'dcn.dcn_no',
                'dcn.dcn_date',
                Schema::hasColumn($table, 'originator_name') ? 'dcn.originator_name' : null,
                Schema::hasColumn($table, 'document_title') ? 'dcn.document_title' : null,
                Schema::hasColumn($table, 'document_no') ? 'dcn.document_no' : null,
                'dcn.created_at',
                'dcn.created_by',
                Schema::hasColumn($table, 'expects_drf') ? 'dcn.expects_drf' : null,
            ]));
        }

        foreach ([
            'rfio_received_at',
            'rfio_registered_at',
            'rfio_claimed_at',
            'registered_request_id',
            'rfio_print_notified_at',
            'office_printed_at',
            'edit_unlocked_at',
            'edit_unlock_reason',
        ] as $column) {
            if (Schema::hasColumn($table, $column)) {
                $cols[] = $alias.'.'.$column;
            }
        }

        return $cols;
    }

    /**
     * Applies a query filter/scope: apply intake action only filter.
     *
     * DEBUG: wrong rows visible → check this scope/filter.
     */
    private static function applyIntakeActionOnlyFilter($query, string $alias, string $table, bool $actionOnly): void
    {
        if (! $actionOnly) {
            return;
        }

        $query->where(function ($q) use ($alias, $table) {
            $hasUnlock = Schema::hasColumn($table, 'edit_unlocked_at');
            $hasPrint = Schema::hasColumn($table, 'rfio_print_notified_at');
            $hasPrinted = Schema::hasColumn($table, 'office_printed_at');

            if ($hasUnlock) {
                $q->whereNotNull($alias.'.edit_unlocked_at');
            }
            if ($hasPrint) {
                $q->orWhere(function ($printQ) use ($alias, $hasPrinted) {
                    $printQ->whereNotNull($alias.'.rfio_print_notified_at');
                    if ($hasPrinted) {
                        $printQ->whereNull($alias.'.office_printed_at');
                    }
                });
            }
            if (! $hasUnlock && ! $hasPrint) {
                $q->whereRaw('1 = 0');
            }
        });

        // Skip finished registrations so attention stays small.
        if (Schema::hasColumn($table, 'rfio_registered_at')) {
            $query->whereNull($alias.'.rfio_registered_at');
        }
    }

    /**
     * Batch-enrich intake list rows (registration + optional submission meta).
     *
     * DEBUG: blank/missing fields on list/show → check this hydrator.
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return \Illuminate\Support\Collection<int, object>
     */
    private static function enrichIntakeListRows(string $type, $rows, bool $withSubmissionMeta = true)
    {
        $type = strtolower($type) === 'dcn' ? 'dcn' : 'drf';
        if ($rows->isEmpty()) {
            return collect();
        }

        self::hydrateIntakeOriginators($type, $rows);

        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $requestIds = $rows
            ->pluck('registered_request_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $draftByRequest = [];
        if ($requestIds !== [] && Schema::hasTable('dcs_document_requests') && Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            $draftByRequest = DB::table('dcs_document_requests')
                ->whereIn('id', $requestIds)
                ->pluck('is_draft', 'id')
                ->map(fn ($value) => (bool) $value)
                ->all();
        }

        $mlByRequest = [];
        if ($requestIds !== [] && Schema::hasTable('dcs_masterlist_registration')) {
            $mlRows = DB::table('dcs_masterlist_registration')
                ->whereIn('request_id', $requestIds)
                ->orderByDesc('id')
                ->get(['request_id', 'doc_no', 'doc_title']);
            foreach ($mlRows as $ml) {
                $rid = (int) $ml->request_id;
                if (! isset($mlByRequest[$rid])) {
                    $mlByRequest[$rid] = $ml;
                }
            }
        }

        $officesByParent = [];
        $people = collect();
        if ($withSubmissionMeta) {
            $userIds = $rows->pluck('created_by')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
            $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
            $accDetails = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
            $linkTable = $type === 'dcn' ? self::DCN_OFFICES_TABLE : self::DRF_OFFICES_TABLE;
            $linkFk = $type === 'dcn' ? 'office_intake_dcn_id' : 'office_intake_drf_id';

            if (Schema::hasTable($linkTable) && $ids !== []) {
                $officeRows = DB::table($linkTable.' as d')
                    ->leftJoin($officeTable.' as o', 'o.id', '=', 'd.office_id')
                    ->whereIn('d.'.$linkFk, $ids)
                    ->orderBy('o.office_name')
                    ->get(['d.'.$linkFk.' as parent_id', 'o.office_name', 'o.office_code']);
                foreach ($officeRows as $office) {
                    $label = trim((string) ($office->office_name ?? '')) ?: trim((string) ($office->office_code ?? ''));
                    if ($label === '') {
                        continue;
                    }
                    $officesByParent[(int) $office->parent_id][$label] = $label;
                }
            }

            if ($userIds !== []) {
                $people = DB::table($accDetails.' as ad')
                    ->leftJoin($officeTable.' as o', 'o.id', '=', 'ad.office_id')
                    ->whereIn('ad.account_id', $userIds)
                    ->get(['ad.account_id', 'ad.first_name', 'ad.last_name', 'o.office_name', 'o.office_code'])
                    ->keyBy(fn ($row) => (int) $row->account_id);
            }
        }

        $linkedDrfIds = $type === 'dcn' ? self::linkedDrfIdsForOfficeDcns($ids) : [];

        return $rows->map(function ($row) use ($type, $draftByRequest, $mlByRequest, $withSubmissionMeta, $officesByParent, $people, $linkedDrfIds) {
            $registeredAt = $row->rfio_registered_at ?? null;
            $registeredRequestId = (int) ($row->registered_request_id ?? 0);
            $isDraft = $registeredRequestId > 0 && ! empty($draftByRequest[$registeredRequestId]);
            $isRegistered = (! empty($registeredAt) || $registeredRequestId > 0) && ! $isDraft;

            $ml = $isRegistered && $registeredRequestId > 0 ? ($mlByRequest[$registeredRequestId] ?? null) : null;
            $row->is_registered = $isRegistered;
            $row->registered_doc_no = $ml ? trim((string) ($ml->doc_no ?? '')) : '';
            $row->registered_doc_title = $ml ? trim((string) ($ml->doc_title ?? '')) : '';

            if ($withSubmissionMeta) {
                $id = (int) $row->id;
                $person = $people->get((int) ($row->created_by ?? 0));
                $office = implode(', ', array_values($officesByParent[$id] ?? []));
                if ($office === '' && $person) {
                    $office = trim((string) ($person->office_name ?? '')) ?: trim((string) ($person->office_code ?? ''));
                }
                $submitter = $person
                    ? trim(trim((string) ($person->first_name ?? '')).' '.trim((string) ($person->last_name ?? '')))
                    : '';
                $row->submitting_office = $office !== '' ? $office : 'Unknown office';
                $row->submitter_name = $submitter !== '' ? $submitter : 'Unknown submitter';
            } else {
                $row->submitting_office = $row->submitting_office ?? '';
                $row->submitter_name = $row->submitter_name ?? '';
            }

            if ($type === 'dcn') {
                $row->linked_drf_id = $linkedDrfIds[(int) $row->id] ?? null;
            }

            $row->pipeline_status = self::intakePipelineStatus($row);

            return $row;
        })->values();
    }

    /**
     * Helper used for intake pipeline status.
     */
    public static function intakePipelineStatus(?object $row): string
    {
        if ($row === null) {
            return 'submitted';
        }
        if (! empty($row->is_registered)) {
            return 'registered';
        }
        if (! empty($row->edit_unlocked_at)) {
            return 'correction';
        }
        if (! empty($row->rfio_print_notified_at)) {
            return ! empty($row->office_printed_at) ? 'printed' : 'print_ready';
        }

        return 'submitted';
    }

    /**
     * Mark that the submitting office has printed this unlocked form.
     * Clears the dashboard "Ready to print" action item.
     *
     * DEBUG: print locked/unlocked wrongly → rfio_print_notified_at logic here.
     * @return array{ok: bool, type: string, id: int, printed_at: string|null}
     */
    public static function markOfficeIntakePrinted(string $type, int $id): array
    {
        self::assertCanAccessIntake();
        $type = strtolower($type);
        abort_unless(in_array($type, ['drf', 'dcn'], true), 404);

        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        self::ensureOfficePrintedColumns($table);
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        abort_unless($record, 404);
        if ($type === 'dcn') {
            self::assertOwnsDcn($record);
        } else {
            self::assertOwnsDrf($record);
        }

        abort_if(self::isIntakeRegistered($type, $id), 422, 'This submission is already registered.');
        abort_unless(self::officeMayPrintIntake($record), 422, 'Print is still locked for this form.');

        $now = now();
        $userId = (int) auth()->id();
        $printedAt = $record->office_printed_at ?? null;

        // RFIU review print must not clear the office dashboard action item.
        if (RegisterQueryHelper::canBrowseAllOfficeIntake() && ! RegisterQueryHelper::isLimitedDcsUser()) {
            return [
                'ok' => true,
                'type' => $type,
                'id' => $id,
                'printed_at' => $printedAt
                    ? \Carbon\Carbon::parse($printedAt)->timezone('Asia/Manila')->format('M d, Y g:i A')
                    : null,
                'skipped' => true,
            ];
        }

        if (Schema::hasColumn($table, 'office_printed_at') && empty($printedAt)) {
            $update = ['updated_at' => $now];
            $update['office_printed_at'] = $now;
            if (Schema::hasColumn($table, 'office_printed_by')) {
                $update['office_printed_by'] = $userId > 0 ? $userId : null;
            }
            DB::table($table)->where('id', $id)->update($update);
            $printedAt = $now;
            RegisterPersistHelper::logAdminChange(
                'Office printed '.strtoupper($type).' #'.$id
            );
            self::bumpOfficeInventoryCache();
        }

        return [
            'ok' => true,
            'type' => $type,
            'id' => $id,
            'printed_at' => $printedAt
                ? \Carbon\Carbon::parse($printedAt)->timezone('Asia/Manila')->format('M d, Y g:i A')
                : null,
        ];
    }

    /**
     * Helper used for ensure office printed columns.
     */
    private static function ensureOfficePrintedColumns(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        if (Schema::hasColumn($table, 'office_printed_at') && Schema::hasColumn($table, 'office_printed_by')) {
            return;
        }

        try {
            Schema::table($table, function ($blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'office_printed_at')) {
                    $blueprint->timestamp('office_printed_at')->nullable();
                }
                if (! Schema::hasColumn($table, 'office_printed_by')) {
                    $blueprint->unsignedInteger('office_printed_by')->nullable();
                }
            });
        } catch (\Throwable) {
            // Another request may have added the columns first.
        }
    }

    /**
     * Office dashboard "Needs your action" payload (shared by page + polling API).
     * total: int,
     * incoming_total: int,
     * items: list<array{
     * kind: string,
     * type: string,
     * title: string,
     * meta: string,
     * meta_label: ?string,
     * href: string
     * }>
     * }
     * @param  \Illuminate\Support\Collection<int, object>|null  $drfRows
     * @param  \Illuminate\Support\Collection<int, object>|null  $dcnRows
     * @return array{
     */
    public static function officeDashboardAttentionPayload($drfRows = null, $dcnRows = null): array
    {
        self::assertCanAccessIntake();
        $userId = (int) auth()->id();

        // When callers already have rows, build immediately (no second cache key miss).
        if ($drfRows !== null || $dcnRows !== null) {
            return self::buildOfficeDashboardAttentionPayload(
                $drfRows ?? collect(),
                $dcnRows ?? collect()
            );
        }

        return self::rememberOfficeInventory(
            'attention.'.$userId,
            function () {
                $drfRows = self::loadMyIntakeRows('drf', [
                    'action_only' => true,
                    'with_submission' => false,
                    'limit' => 40,
                ]);
                $dcnRows = self::loadMyIntakeRows('dcn', [
                    'action_only' => true,
                    'with_submission' => false,
                    'limit' => 40,
                ]);

                return self::buildOfficeDashboardAttentionPayload($drfRows, $dcnRows);
            },
            8
        );
    }

    /**
     * Builds or formats data for build office dashboard attention payload.
     * @param  \Illuminate\Support\Collection<int, object>  $drfRows
     * @param  \Illuminate\Support\Collection<int, object>  $dcnRows
     * @return array{total: int, incoming_total: int, items: list<array<string, mixed>>}
     */
    private static function buildOfficeDashboardAttentionPayload($drfRows, $dcnRows): array
    {
        $incomingBreakdown = self::officeIncomingAttentionBreakdown();
        $incomingPending = (int) ($incomingBreakdown['pending'] ?? 0);
        $incomingAppraisable = (int) ($incomingBreakdown['appraisable'] ?? 0);
        $incomingTotal = (int) ($incomingBreakdown['total'] ?? 0);

        $items = collect();
        foreach ($drfRows as $row) {
            if (($row->pipeline_status ?? '') === 'correction') {
                $reason = trim((string) ($row->edit_unlock_reason ?? ''));
                $items->push([
                    'kind' => 'correction',
                    'type' => 'drf',
                    'title' => $row->doc_title ?: ($row->drf_no ?: 'Untitled DRF'),
                    'meta' => $reason !== '' ? $reason : 'RFIU asked for corrections. Open the form, fix the noted items, then resubmit.',
                    'meta_label' => $reason !== '' ? 'RFIU correction message' : 'What to do',
                    'href' => route('dcs.office.drf.edit', $row->id, absolute: false),
                    'sort' => strtotime((string) ($row->edit_unlocked_at ?? $row->created_at ?? 'now')) ?: 0,
                ]);
            } elseif (($row->pipeline_status ?? '') === 'print_ready') {
                $items->push([
                    'kind' => 'print_ready',
                    'type' => 'drf',
                    'title' => $row->doc_title ?: ($row->drf_no ?: 'Untitled DRF'),
                    'meta' => 'Print is unlocked — print, sign, then submit to RFIU',
                    'meta_label' => null,
                    'href' => route('dcs.office.drf.show', $row->id, absolute: false),
                    'sort' => strtotime((string) ($row->rfio_print_notified_at ?? $row->created_at ?? 'now')) ?: 0,
                ]);
            }
        }
        foreach ($dcnRows as $row) {
            if (($row->pipeline_status ?? '') === 'correction') {
                $reason = trim((string) ($row->edit_unlock_reason ?? ''));
                $items->push([
                    'kind' => 'correction',
                    'type' => 'dcn',
                    'title' => $row->document_title
                        ?? $row->originator_name
                        ?? ($row->dcn_no ?: 'Untitled DCN'),
                    'meta' => $reason !== '' ? $reason : 'RFIU asked for corrections. Open the form, fix the noted items, then resubmit.',
                    'meta_label' => $reason !== '' ? 'RFIU correction message' : 'What to do',
                    'href' => route('dcs.office.dcn.edit', $row->id, absolute: false),
                    'sort' => strtotime((string) ($row->edit_unlocked_at ?? $row->created_at ?? 'now')) ?: 0,
                ]);
            } elseif (($row->pipeline_status ?? '') === 'print_ready') {
                $items->push([
                    'kind' => 'print_ready',
                    'type' => 'dcn',
                    'title' => $row->document_title
                        ?? $row->originator_name
                        ?? ($row->dcn_no ?: 'Untitled DCN'),
                    'meta' => 'Print is unlocked — print, sign, then submit to RFIU',
                    'meta_label' => null,
                    'href' => route('dcs.office.dcn.show', $row->id, absolute: false),
                    'sort' => strtotime((string) ($row->rfio_print_notified_at ?? $row->created_at ?? 'now')) ?: 0,
                ]);
            }
        }
        if ($incomingPending > 0) {
            $items->push([
                'kind' => 'incoming_receive',
                'type' => 'incoming',
                'title' => $incomingPending === 1
                    ? '1 document needs to be received'
                    : $incomingPending.' documents need to be received',
                'meta' => 'Open Incoming and confirm receipt so these move to your office masterlist.',
                'meta_label' => 'Action needed',
                'href' => route('dcs.office.incoming', absolute: false),
                'sort' => PHP_INT_MAX,
            ]);
        }
        if ($incomingAppraisable > 0) {
            $items->push([
                'kind' => 'incoming_appraise',
                'type' => 'incoming',
                'title' => $incomingAppraisable === 1
                    ? '1 document needs to be appraised'
                    : $incomingAppraisable.' documents need to be appraised',
                'meta' => 'Already received — finish Appraisal so Save and Appraise copies leave Incoming.',
                'meta_label' => 'Action needed',
                'href' => route('dcs.office.incoming', absolute: false),
                'sort' => PHP_INT_MAX - 1,
            ]);
        }

        $sorted = $items->sortByDesc('sort')->values();
        $shown = $sorted->take(8)->map(function (array $item) {
            unset($item['sort']);

            return $item;
        })->values()->all();

        return [
            'total' => $sorted->count(),
            'incoming_total' => $incomingTotal,
            'items' => $shown,
        ];
    }

    /**
     * Lists office intakes waiting in the Admin Request queue for RFIU.
     * @return \Illuminate\Support\Collection<int, object>
     */
    // =====================================================================
    // SECTION: RFIU REQUEST QUEUE — pending office forms awaiting registration
    // =====================================================================
    
    public static function listPendingOfficeRequests(?string $typeFilter = null)
    {
        abort_unless(RegisterQueryHelper::canBrowseAllOfficeIntake(), 403);

        $typeFilter = strtolower(trim((string) $typeFilter));
        $items = collect();

        if ($typeFilter === '' || $typeFilter === 'all' || $typeFilter === 'drf') {
            $items = $items->concat(self::pendingIntakeQueue('drf'));
        }

        if ($typeFilter === '' || $typeFilter === 'all' || $typeFilter === 'dcn') {
            $items = $items->concat(self::pendingIntakeQueue('dcn'));
        }

        return $items->sortByDesc(function ($row) {
            return strtotime((string) ($row->created_at ?? '')) ?: 0;
        })->values();
    }

    /**
     * Pending office DRF or DCN rows still waiting in the Request queue.
     * One query for the rows, one for offices, one for submitters.
     * @return \Illuminate\Support\Collection<int, object>
     */
    private static function pendingIntakeQueue(string $type)
    {
        $isDrf = $type === 'drf';
        $table = $isDrf ? 'dcs_office_intake_drf' : 'dcs_office_intake_dcn';
        if (! Schema::hasTable($table)) {
            return collect();
        }

        $alias = $isDrf ? 'drf' : 'dcn';
        $query = DB::table($table.' as '.$alias);
        if (Schema::hasColumn($table, 'rfio_claimed_at')) {
            $query->whereNull($alias.'.rfio_claimed_at');
        }
        if (Schema::hasColumn($table, 'rfio_registered_at')) {
            $query->whereNull($alias.'.rfio_registered_at');
        }
        if (Schema::hasColumn($table, 'registered_request_id')) {
            $query->where(function ($q) use ($alias) {
                $q->whereNull($alias.'.registered_request_id')
                    ->orWhere($alias.'.registered_request_id', 0);
            });
        }

        $titleColumn = $isDrf
            ? 'doc_title'
            : (Schema::hasColumn($table, 'document_title') ? 'document_title' : 'doc_title');
        $dateColumn = $isDrf ? 'drf_date' : 'dcn_date';
        $select = [
            $alias.'.id',
            $alias.'.'.$titleColumn.' as title',
            $alias.'.'.$dateColumn.' as form_date',
            $alias.'.created_at',
            $alias.'.created_by',
        ];
        if (Schema::hasColumn($table, 'rfio_received_at')) {
            $select[] = $alias.'.rfio_received_at';
        }

        $rows = $query->orderByDesc($alias.'.id')->get($select);
        if ($rows->isEmpty()) {
            return collect();
        }

        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $userIds = $rows->pluck('created_by')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $accDetails = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $linkTable = $isDrf ? 'dcs_office_drf_offices' : 'dcs_office_dcn_offices';
        $linkFk = $isDrf ? 'office_intake_drf_id' : 'office_intake_dcn_id';

        $officesByParent = [];
        if (Schema::hasTable($linkTable)) {
            $officeRows = DB::table($linkTable.' as d')
                ->leftJoin($officeTable.' as o', 'o.id', '=', 'd.office_id')
                ->whereIn('d.'.$linkFk, $ids)
                ->orderBy('o.office_name')
                ->get(['d.'.$linkFk.' as parent_id', 'o.office_name', 'o.office_code']);
            foreach ($officeRows as $office) {
                $label = trim((string) ($office->office_name ?? '')) ?: trim((string) ($office->office_code ?? ''));
                if ($label === '') {
                    continue;
                }
                $officesByParent[(int) $office->parent_id][$label] = $label;
            }
        }

        $people = collect();
        if ($userIds !== []) {
            $people = DB::table($accDetails.' as ad')
                ->leftJoin($officeTable.' as o', 'o.id', '=', 'ad.office_id')
                ->whereIn('ad.account_id', $userIds)
                ->get(['ad.account_id', 'ad.first_name', 'ad.last_name', 'o.office_name', 'o.office_code'])
                ->keyBy(fn ($row) => (int) $row->account_id);
        }

        $fallbackTitle = $isDrf ? 'Untitled DRF' : 'Untitled DCN';

        return $rows->map(function ($row) use ($type, $officesByParent, $people, $fallbackTitle) {
            $id = (int) $row->id;
            $person = $people->get((int) ($row->created_by ?? 0));
            $office = implode(', ', array_values($officesByParent[$id] ?? []));
            if ($office === '' && $person) {
                $office = trim((string) ($person->office_name ?? '')) ?: trim((string) ($person->office_code ?? ''));
            }
            $submitter = $person
                ? trim(trim((string) ($person->first_name ?? '')).' '.trim((string) ($person->last_name ?? '')))
                : '';

            return (object) [
                'type' => $type,
                'id' => $id,
                'title' => trim((string) ($row->title ?? '')) ?: $fallbackTitle,
                'form_date' => $row->form_date ?? null,
                'created_at' => $row->created_at ?? null,
                'submitting_office' => $office !== '' ? $office : 'Unknown office',
                'submitter_name' => $submitter !== '' ? $submitter : 'Unknown submitter',
                'received' => ! empty($row->rfio_received_at ?? null),
            ];
        })->values();
    }

    /**
     * Helper used for request review payload.
     */
    public static function requestReviewPayload(string $type, int $id): ?array
    {
        abort_unless(RegisterQueryHelper::canBrowseAllOfficeIntake(), 403);
        $payload = self::modalPayload($type, $id);
        if (! $payload || ! empty($payload['registered'])) {
            return null;
        }

        // Already moved into Document Registration — leave the Request queue.
        // Callers should use registerContinueUrl() / rfioOpenIntakeUrl() to resume.
        $record = strtolower($type) === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        if ($record && ! empty($record->rfio_claimed_at)) {
            return null;
        }

        return $payload;
    }

    /**
     * A DCN, including one that also has a DRF, registers as a revision of the
     * document the office selected. A DRF with no DCN registers as a new document.
     * Same syllabi context already registered → edit URL (merge faculty onto that pack).
     * @return array{registerType: string, intakeType: string, intakeId: int, registerUrl: string, mergeRequestId?: int|null}
     */
    public static function registrationTarget(string $type, int $id): array
    {
        $type = strtolower($type);
        $intakeType = $type;
        $intakeId = $id;
        $registerType = 'new';
        $mergeRequestId = null;

        if ($type === 'dcn') {
            $registerType = 'revised';
        } elseif ($type === 'drf') {
            $linkedDcnId = self::findSourceDcnIdForOfficeDrf($id);
            if ($linkedDcnId) {
                $registerType = 'revised';
                $intakeType = 'dcn';
                $intakeId = $linkedDcnId;
            } else {
                $drf = self::findOfficeDrf($id);
                $previousNo = trim((string) ($drf->previous_document_no ?? ''));
                if ($previousNo !== '') {
                    $registerType = 'recurring';
                } else {
                    $mergeRequestId = self::existingSyllabiRequestIdForDrf($drf);
                }
            }
        }

        if ($mergeRequestId) {
            return [
                'registerType' => 'edit',
                'intakeType' => $intakeType,
                'intakeId' => $intakeId,
                'mergeRequestId' => $mergeRequestId,
                'registerUrl' => route('dcs.register.edit', [
                    'id' => $mergeRequestId,
                    'intake' => $intakeType,
                    'intake_id' => $intakeId,
                ], absolute: false),
            ];
        }

        return [
            'registerType' => $registerType,
            'intakeType' => $intakeType,
            'intakeId' => $intakeId,
            'mergeRequestId' => null,
            'registerUrl' => route('dcs.register.create', [
                'type' => $registerType,
                'intake' => $intakeType,
                'intake_id' => $intakeId,
            ], absolute: false),
        ];
    }

    /**
     * Existing controlled syllabi request for this office DRF's college/course-type/semester/S/Y.
     * When present, Proceed should open Edit (merge faculty) instead of a new Register.
     */
    public static function existingSyllabiRequestIdForDrf(?object $drf): ?int
    {
        if (! $drf) {
            return null;
        }
        $ctx = self::officeDrfSyllabiContextFromRow($drf);
        if (empty($ctx['is_syllabi'])) {
            return null;
        }
        $collegeId = (int) ($ctx['college_id'] ?? 0);
        $programId = (int) ($ctx['program_id'] ?? 0);
        $semesterId = (int) ($ctx['semester_id'] ?? 0);
        $schoolYearId = (int) ($ctx['school_year_id'] ?? 0);
        $courseType = trim((string) ($ctx['course_type'] ?? ''));
        $subTypeId = (int) ($drf->sub_type_id ?? 0);
        if ($collegeId < 1 || $semesterId < 1 || $schoolYearId < 1 || $courseType === '') {
            return null;
        }

        $duplicate = RegisterPersistHelper::findSyllabiContextDuplicate(
            $collegeId,
            CourseTypeRules::isSharedType($courseType) ? 0 : $programId,
            $semesterId,
            $schoolYearId,
            $courseType,
            $subTypeId > 0 ? $subTypeId : null,
            null
        );

        $requestId = (int) ($duplicate->request_id ?? 0);

        return $requestId > 0 ? $requestId : null;
    }

    /**
     * Builds the Register URL used after Proceed / resume claim.
     */
    public static function registerContinueUrl(string $type, int $id): string
    {
        return self::registrationTarget($type, $id)['registerUrl'];
    }

    /**
     * Tells whether RFIU started registration but has not finished yet.
     *
     * DEBUG: unexpected allow/deny → check this predicate.
     */
    public static function isIntakeClaimedPending(string $type, int $id): bool
    {
        $type = strtolower($type);
        if (! in_array($type, ['drf', 'dcn'], true) || $id < 1) {
            return false;
        }
        if (self::isIntakeRegistered($type, $id)) {
            return false;
        }
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        if (! $record) {
            return false;
        }

        return ! empty($record->rfio_claimed_at);
    }

    /**
     * Where RFIU should land when opening an intake from a notification / deep link.
     * Claimed-but-not-registered → continue Register (Proceed already ran).
     * Otherwise → Request review page.
     */
    public static function rfioOpenIntakeUrl(string $type, int $id): string
    {
        $type = strtolower($type);
        abort_unless(in_array($type, ['drf', 'dcn'], true), 404);

        if (self::isIntakeRegistered($type, $id)) {
            return route('dcs.requests.index', absolute: false);
        }

        if (self::isIntakeClaimedPending($type, $id)) {
            return self::registerContinueUrl($type, $id);
        }

        return route('dcs.requests.show', ['type' => $type, 'id' => $id], absolute: false);
    }

    /**
     * Helper used for intake registration meta.
     */
    private static function intakeRegistrationMeta(string $type, object $row): array
    {
        $empty = ['is_registered' => false, 'doc_no' => '', 'doc_title' => ''];
        $registeredAt = $row->rfio_registered_at ?? null;
        $registeredRequestId = (int) ($row->registered_request_id ?? 0);
        if (empty($registeredAt) && $registeredRequestId < 1) {
            return $empty;
        }

        // A draft is not a finished registration. Keep the office form as Submitted
        // until the document is actually saved and stored.
        if ($registeredRequestId > 0 && Schema::hasTable('dcs_document_requests') && Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            $linkedDraft = DB::table('dcs_document_requests')
                ->where('id', $registeredRequestId)
                ->value('is_draft');
            if ($linkedDraft) {
                return $empty;
            }
        }

        // Prefer explicit RFIU registration stamp. Draft-linked request ids alone
        // never reach here; non-draft linked rows stay registered for legacy data.
        if (empty($registeredAt) && $registeredRequestId < 1) {
            return $empty;
        }

        $docNo = '';
        $docTitle = '';
        if ($registeredRequestId > 0 && Schema::hasTable('dcs_masterlist_registration')) {
            $ml = DB::table('dcs_masterlist_registration')
                ->where('request_id', $registeredRequestId)
                ->orderByDesc('id')
                ->first(['doc_no', 'doc_title']);
            if ($ml) {
                $docNo = trim((string) ($ml->doc_no ?? ''));
                $docTitle = trim((string) ($ml->doc_title ?? ''));
            }
        }

        return [
            'is_registered' => true,
            'doc_no' => $docNo,
            'doc_title' => $docTitle,
        ];
    }

    // =====================================================================
    // SECTION: LOAD / HYDRATE RECORDS — single-form fetch + 3NF originator fill
    // =====================================================================
    
    /**
     * Loads one office DRF and fills originator / previous-document fields for the show page.
     *
     * DEBUG: blank originator or missing previous title on DRF show → hydrate* here.
     */
    public static function findOfficeDrf(int $id): ?object
    {
        if (! Schema::hasTable('dcs_office_intake_drf')) {
            return null;
        }

        $row = DB::table('dcs_office_intake_drf')
            ->where('id', $id)
            ->first();
        if ($row) {
            self::hydrateIntakeOriginators('drf', [$row]);
            self::hydrateDrfPreviousCopies($row);
        }

        return $row;
    }

    /**
     * Loads one office DCN and fills originator fields for the show page.
     *
     * DEBUG: Undefined property originator_name / blank originator → hydrateIntakeOriginators.
     */
    public static function findOfficeDcn(int $id): ?object
    {
        if (! Schema::hasTable('dcs_office_intake_dcn')) {
            return null;
        }

        $row = DB::table('dcs_office_intake_dcn')
            ->where('id', $id)
            ->first();
        if ($row) {
            self::hydrateIntakeOriginators('dcn', [$row]);
        }

        return $row;
    }

    /**
     * Saves data for sync intake originators.
     *
     * DEBUG: blank/missing originator or Undefined property → check here.
     */
    private static function syncIntakeOriginators(string $junction, string $ownerColumn, int $ownerId, mixed $raw): void
    {
        if ($ownerId < 1 || ! Schema::hasTable($junction)) {
            return;
        }
        $resolved = RegisterPersistHelper::resolveOriginatorIds($raw);
        DB::table($junction)->where($ownerColumn, $ownerId)->delete();
        foreach ($resolved['ids'] as $index => $originatorId) {
            DB::table($junction)->insert([
                $ownerColumn => $ownerId,
                'originator_id' => $originatorId,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Fills `originator_name` on intake rows from junction tables so list/show never crash on a missing property.
     *
     * DEBUG: blank/missing originator or Undefined property on My DCN/DRF → this method + junction tables.
     * @param  iterable<mixed>  $rows
     */
    private static function hydrateIntakeOriginators(string $type, iterable $rows): void
    {
        $type = $type === 'dcn' ? 'dcn' : 'drf';
        $junction = $type === 'dcn' ? 'dcs_office_dcn_originators' : 'dcs_office_drf_originators';
        $ownerColumn = $type === 'dcn' ? 'office_intake_dcn_id' : 'office_intake_drf_id';

        // After 3NF, parent originator_name may be dropped. Always expose the property
        // so list/show blades never hit Undefined property on stdClass.
        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }
            if (! property_exists($row, 'originator_name')) {
                $row->originator_name = '';
            } else {
                $row->originator_name = trim((string) ($row->originator_name ?? ''));
            }
        }

        if (! Schema::hasTable($junction) || ! Schema::hasTable('dcs_originators')) {
            return;
        }

        $need = [];
        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }
            if (trim((string) ($row->originator_name ?? '')) !== '') {
                continue;
            }
            $id = (int) ($row->id ?? 0);
            if ($id > 0) {
                $need[] = $id;
            }
        }
        if ($need === []) {
            return;
        }

        $grouped = [];
        $links = DB::table($junction.' as j')
            ->join('dcs_originators as og', 'og.id', '=', 'j.originator_id')
            ->whereIn('j.'.$ownerColumn, $need)
            ->orderBy('j.sort_order')
            ->orderBy('j.id')
            ->get(['j.'.$ownerColumn.' as owner_id', 'og.originator_name']);
        foreach ($links as $link) {
            $grouped[(int) $link->owner_id][] = (string) $link->originator_name;
        }
        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }
            $id = (int) ($row->id ?? 0);
            if ($id > 0 && isset($grouped[$id]) && trim((string) ($row->originator_name ?? '')) === '') {
                $row->originator_name = implode(', ', $grouped[$id]);
            }
        }
    }

    /**
     * Fills extra fields onto rows for the UI (hydrate drf previous copies).
     *
     * DEBUG: blank/missing originator or Undefined property → check here.
     */
    private static function hydrateDrfPreviousCopies(object $drf): void
    {
        $masterlistId = (int) ($drf->previous_masterlist_id ?? 0);
        if ($masterlistId < 1 || ! Schema::hasTable('dcs_masterlist_registration')) {
            return;
        }
        $needsTitle = trim((string) ($drf->previous_document_title ?? '')) === '';
        $needsOriginator = trim((string) ($drf->previous_originator ?? '')) === '';
        if (! $needsTitle && ! $needsOriginator) {
            return;
        }
        $ml = DB::table('dcs_masterlist_registration')->where('id', $masterlistId)->first(['id', 'doc_title']);
        if (! $ml) {
            return;
        }
        if ($needsTitle) {
            $drf->previous_document_title = $ml->doc_title;
        }
        if ($needsOriginator) {
            RegisterQueryHelper::attachOriginatorNames([$ml]);
            $drf->previous_originator = $ml->originator_name ?? null;
        }
    }

    /**
     * Helper used for drf source offices.
     */
    public static function drfSourceOffices(int $drfId)
    {
        return DB::table('dcs_office_drf_offices as d')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
            ->where('d.office_intake_drf_id', $drfId)
            ->orderBy('o.office_name')
            ->get(['d.office_id', 'o.office_name', 'o.office_code']);
    }

    /**
     * Helper used for dcn source offices.
     */
    public static function dcnSourceOffices(int $dcnId)
    {
        return DB::table('dcs_office_dcn_offices as d')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
            ->where('d.office_intake_dcn_id', $dcnId)
            ->orderBy('o.office_name')
            ->get(['d.office_id', 'o.office_name', 'o.office_code']);
    }

    /**
     * Helper used for dcn revisions.
     */
    public static function dcnRevisions(int $dcnId)
    {
        $dcn = self::findOfficeDcn($dcnId);
        if (! $dcn) {
            return collect();
        }

        return collect([(object) [
            'id' => $dcnId,
            'dcn_id' => $dcnId,
            'title' => $dcn->document_title ?? null,
            'document_no' => $dcn->document_no ?? null,
            'brief_purpose' => $dcn->brief_purpose ?? null,
            'created_at' => $dcn->created_at ?? null,
        ]]);
    }

    /**
     * Latest registered masterlist row for a document no. that allows revision.
     */
    // =====================================================================
    // SECTION: REVISION LOOKUP — match registered docs for DCN revise / DRF recur
    // =====================================================================
    
    public static function findLatestRevisableRegistration(string $docNo): ?object
    {
        $docNo = trim($docNo);
        if ($docNo === '') {
            return null;
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
            ->whereRaw('LOWER(TRIM(ml.doc_no)) = ?', [mb_strtolower($docNo)]);
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyExcludeDrafts($query, 'dr');
        RegisterQueryHelper::applyExcludeOfficeIntakeRequests($query, 'dr');
        RegisterQueryHelper::applyLatestRevisionStatus($query, 'ml');

        return $query->orderByDesc('ml.revise_no')->orderByDesc('ml.id')->first([
            'ml.id as ml_id',
            'ml.request_id',
            'ml.doc_no',
            'ml.doc_title',
            'ml.revise_no',
            'dr.doc_type_id',
            'dr.sub_type_id',
            'dt.doc_type_name',
            'st.doc_type_name as sub_type_name',
        ]);
    }

    /**
     * Stops the request if a required condition fails (assert registered revisable doc no).
     *
     * DEBUG: unexpected 403 / abort → check this guard.
     * @return object Latest revisable registration
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function assertRegisteredRevisableDocNo(string $docNo): object
    {
        $matched = self::findLatestRevisableRegistration($docNo);
        if ($matched) {
            return $matched;
        }

        throw \Illuminate\Validation\ValidationException::withMessages([
            'documentNo' => 'Document No. is not a registered document.',
        ]);
    }

    /**
     * Helper used for search revisable documents.
     * @return array{found: bool, matches: list<array<string, mixed>>, suggestions: list<array<string, mixed>>}
     */
    public static function searchRevisableDocuments(\Illuminate\Http\Request $request): array
    {
        self::assertCanAccessIntake();

        $docNo = trim((string) $request->input('documentNo', ''));
        $title = trim((string) $request->input('currentTitle', ''));
        $originator = trim((string) $request->input('originator', ''));
        if ($docNo === '' || $title === '' || $originator === '') {
            return ['found' => false, 'matches' => [], 'suggestions' => []];
        }

        $exceptId = (int) $request->input('exceptId', 0);
        $exceptId = $exceptId > 0 ? $exceptId : null;
        $purpose = self::revisableSearchPurpose($request);
        $matches = [];
        $matched = self::findRevisableRegistrationMatch($docNo, $title, $originator);
        if ($matched) {
            $matches[] = self::revisableMatchPayload($matched, false, $exceptId, $purpose);
        }
        foreach (self::nearRevisableMatches($docNo, $title, $originator, $exceptId, $purpose) as $near) {
            $matches[] = $near;
        }

        return [
            'found' => $matches !== [],
            'matches' => $matches,
            'suggestions' => $matches,
        ];
    }

    /**
     * Helper used for revisable search purpose.
     */
    private static function revisableSearchPurpose(\Illuminate\Http\Request $request): string
    {
        $raw = strtolower(trim((string) $request->input(
            'purpose',
            (string) $request->input('for', 'dcn')
        )));
        if (in_array($raw, ['recur', 'recurring', 'drf', 'drf-recur', 'recurring-drf'], true)) {
            return 'recur';
        }

        return 'dcn';
    }

    /**
     * Loads or finds data for find revisable registration match.
     */
    public static function findRevisableRegistrationMatch(string $docNo, string $title, string $originator): ?object
    {
        $docNo = trim($docNo);
        $title = trim($title);
        $originator = trim($originator);
        if ($docNo === '' || $title === '' || $originator === '') {
            return null;
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
            ->whereRaw('LOWER(TRIM(ml.doc_no)) = ?', [mb_strtolower($docNo)])
            ->whereRaw('LOWER(TRIM(ml.doc_title)) = ?', [mb_strtolower($title)]);
        $originDisplay = RegisterQueryHelper::masterlistOriginatorNameSql('ml');
        $originSql = 'LOWER(TRIM(COALESCE('.$originDisplay.", '')))";
        $query->whereRaw($originSql . ' = ?', [mb_strtolower($originator)]);
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyExcludeDrafts($query, 'dr');
        RegisterQueryHelper::applyExcludeOfficeIntakeRequests($query, 'dr');
        RegisterQueryHelper::applyLatestRevisionStatus($query, 'ml');
        self::excludeObsoleteRevisions($query);

        $select = [
            'ml.id as ml_id',
            'ml.request_id',
            'ml.doc_no',
            'ml.doc_title',
            'ml.revise_no',
            'dr.doc_type_id',
            'dr.sub_type_id',
            'dt.doc_type_name',
            'st.doc_type_name as sub_type_name',
            DB::raw($originDisplay . ' as originator_label'),
        ];
        if (RegisterQueryHelper::supportsAllowsRevisionColumn()) {
            $select[] = 'ml.allows_revision';
        }

        return $query->orderByDesc('ml.revise_no')->orderByDesc('ml.id')->first($select);
    }

    /**
     * Helper used for exclude obsolete revisions.
     */
    private static function excludeObsoleteRevisions($query): void
    {
        if (! RegisterQueryHelper::supportsRevisionStatus()) {
            return;
        }

        $query->whereRaw("LOWER(COALESCE(NULLIF(TRIM(ml.revision_status::text), ''), 'latest')) <> 'obsolete'");
    }

    /**
     * Same document number, with a title or originator close to what was typed.
     * @return list<array<string, mixed>>
     */
    private static function nearRevisableMatches(
        string $docNo,
        string $title,
        string $originator,
        ?int $exceptId = null,
        string $purpose = 'dcn'
    ): array
    {
        $docNo = trim($docNo);
        if ($docNo === '') {
            return [];
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
            ->whereRaw('LOWER(TRIM(ml.doc_no)) = ?', [mb_strtolower($docNo)]);
        $originDisplay = RegisterQueryHelper::masterlistOriginatorNameSql('ml');
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyExcludeDrafts($query, 'dr');
        RegisterQueryHelper::applyExcludeOfficeIntakeRequests($query, 'dr');
        RegisterQueryHelper::applyLatestRevisionStatus($query, 'ml');
        self::excludeObsoleteRevisions($query);

        $select = [
            'ml.id as ml_id',
            'ml.request_id',
            'ml.doc_no',
            'ml.doc_title',
            'ml.revise_no',
            'dr.doc_type_id',
            'dr.sub_type_id',
            'dt.doc_type_name',
            'st.doc_type_name as sub_type_name',
            DB::raw($originDisplay . ' as originator_label'),
        ];
        if (RegisterQueryHelper::supportsAllowsRevisionColumn()) {
            $select[] = 'ml.allows_revision';
        }

        $latestByDocument = [];
        foreach ($query->orderByDesc('ml.revise_no')->orderByDesc('ml.id')->limit(40)->get($select) as $row) {
            $storedTitle = trim((string) ($row->doc_title ?? ''));
            $storedOrigin = trim((string) ($row->originator_label ?? ''));
            $titleExact = mb_strtolower($storedTitle) === mb_strtolower(trim($title));
            $originExact = mb_strtolower($storedOrigin) === mb_strtolower(trim($originator));
            if ($titleExact && $originExact) {
                continue;
            }
            // Same document number can cover unrelated titles (e.g. Buhi vs ERP).
            // Only suggest rows whose title is near what was typed, and originator words match.
            if (! self::textNear(trim($title), $storedTitle) && mb_strtolower($storedTitle) !== mb_strtolower(trim($title))) {
                continue;
            }
            $originWords = self::completedNameWords($originator);
            if ($originWords === [] || ! self::nameWordsMatch($originWords, $storedOrigin)) {
                continue;
            }
            $key = mb_strtolower(trim((string) ($row->doc_no ?? ''))).'|'.mb_strtolower($storedTitle).'|'.mb_strtolower($storedOrigin);
            $reviseNo = (int) ($row->revise_no ?? 0);
            $rowId = (int) ($row->ml_id ?? 0);
            $current = $latestByDocument[$key] ?? null;
            if ($current === null || $reviseNo > $current['revise_no'] || ($reviseNo === $current['revise_no'] && $rowId > $current['id'])) {
                $latestByDocument[$key] = ['revise_no' => $reviseNo, 'id' => $rowId, 'row' => $row];
            }
        }

        $suggestions = [];
        foreach ($latestByDocument as $picked) {
            $suggestions[] = self::revisableMatchPayload($picked['row'], false, $exceptId, $purpose);
            if (count($suggestions) >= 8) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Helper used for text near.
     */
    private static function textNear(string $typed, string $stored): bool
    {
        $typed = mb_strtolower(trim($typed));
        $stored = mb_strtolower(trim($stored));
        if ($typed === '' || $stored === '' || mb_strlen($typed) < 3) {
            return false;
        }
        if ($typed === $stored || str_contains($stored, $typed) || str_contains($typed, $stored)) {
            return true;
        }
        similar_text($typed, $stored, $percent);

        return $percent >= 72;
    }

    /**
     * Whole name words only. "Carlo" counts. "e" and "Carl" do not.
     * @return list<string>
     */
    private static function completedNameWords(string $typed): array
    {
        $parts = preg_split('/[\s.]+/u', mb_strtolower(trim($typed))) ?: [];

        return array_values(array_filter($parts, fn ($word) => mb_strlen($word) >= 2));
    }

    /**
     * Helper used for name words match.
     */
    private static function nameWordsMatch(array $words, string $stored): bool
    {
        if ($words === []) {
            return false;
        }
        $storedWords = self::completedNameWords($stored);
        foreach ($words as $word) {
            if (! in_array($word, $storedWords, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Helper used for row allows dcn revision.
     */
    private static function rowAllowsDcnRevision(object $row): bool
    {
        return RegisterQueryHelper::supportsAllowsRevisionColumn()
            && property_exists($row, 'allows_revision')
            && $row->allows_revision !== null
            ? (bool) $row->allows_revision
            : RegisterQueryHelper::effectiveTypeAllowsRevision($row->doc_type_id ?? null, $row->sub_type_id ?? null);
    }

    /**
     * Helper used for revisable match payload.
     */
    private static function revisableMatchPayload(
        object $row,
        bool $found,
        ?int $exceptId = null,
        string $purpose = 'dcn'
    ): array {
        $typeLabel = trim((string) ($row->sub_type_name ?? '')) !== ''
            ? trim((string) $row->sub_type_name)
            : trim((string) ($row->doc_type_name ?? 'Document'));
        $typeName = trim((string) ($row->doc_type_name ?? ''));
        $subName = trim((string) ($row->sub_type_name ?? ''));
        $kind = str_contains(mb_strtolower($typeName.' '.$subName), 'external') ? 'external' : 'internal';
        $requestId = (int) ($row->request_id ?? 0);
        $payload = [
            'document_no' => trim((string) ($row->doc_no ?? '')),
            'current_title' => trim((string) ($row->doc_title ?? '')),
            'originator' => trim((string) ($row->originator_label ?? '')),
            'revise_no' => (int) ($row->revise_no ?? 0),
            'doc_type' => $typeLabel !== '' ? $typeLabel : 'Document',
            'doc_type_kind' => $kind,
            'request_id' => $requestId,
            'masterlist_id' => (int) ($row->ml_id ?? 0),
            'doc_type_id' => (int) ($row->doc_type_id ?? 0),
            'sub_type_id' => (int) ($row->sub_type_id ?? 0),
            'distribute_office_ids' => self::distributionOfficeIdsForRequest($requestId),
            'approvals' => self::approvalsForRegisteredRequest($requestId),
            // Prefer signatories saved on the registered DRF; else Settings assignment for this type.
            'signatories' => self::signatoriesForRegisteredRequest($requestId)
                ?? self::drfSignatoryPrefill(
                    $kind,
                    (int) ($row->doc_type_id ?? 0) ?: null,
                    (int) ($row->sub_type_id ?? 0) ?: null
                ),
        ];
        $docNo = (string) ($row->doc_no ?? '');
        $docTitle = (string) ($row->doc_title ?? '');
        $originLabel = trim((string) ($row->originator_label ?? ''));
        if ($purpose === 'recur') {
            // Recurring DRF cannot start while another Recurring DRF OR an open DCN
            // for the same registered document is still waiting to be registered.
            $openDrf = self::findOpenDuplicateRecurringDrf($docNo, $docTitle, $exceptId);
            if ($openDrf) {
                $payload['open_request_type'] = 'recurring_drf';
                $payload['open_request_message'] = self::duplicateRecurringDrfMessage($openDrf);
                // Only expose open-form id/url when this user may open that record (no cross-office IDOR).
                if (self::userCanAccessOfficeIntakeRecord($openDrf, 'drf')) {
                    $payload['open_request_id'] = (int) $openDrf->id;
                    $payload['open_drf_id'] = (int) $openDrf->id;
                    $payload['open_request_url'] = route('dcs.office.drf.show', ['id' => (int) $openDrf->id], false);
                } else {
                    $payload['open_request_message'] = 'A Recurring DRF for this document is already submitted by another office and still waiting to be registered.';
                }
            } else {
                $openDcn = self::findOpenDuplicateDcn($docNo, $docTitle, '', [$originLabel], null);
                if ($openDcn) {
                    $payload['open_request_type'] = 'dcn';
                    $payload['open_request_message'] = self::duplicateDcnBlocksRecurringMessage($openDcn);
                    if (self::userCanAccessOfficeIntakeRecord($openDcn, 'dcn')) {
                        $payload['open_request_id'] = (int) $openDcn->id;
                        $payload['open_dcn_id'] = (int) $openDcn->id;
                        $payload['open_request_url'] = route('dcs.office.dcn.show', ['id' => (int) $openDcn->id], false);
                    } else {
                        $payload['open_request_message'] = 'A Document Change Notice for this document is already submitted by another office and still waiting to be registered.';
                    }
                }
            }
        } else {
            $open = self::findOpenDuplicateDcn($docNo, $docTitle, '', [$originLabel], $exceptId);
        if ($open) {
                $payload['open_request_type'] = 'dcn';
            $payload['open_request_message'] = self::duplicateDcnMessage($open);
                if (self::userCanAccessOfficeIntakeRecord($open, 'dcn')) {
                    $payload['open_request_id'] = (int) $open->id;
                    $payload['open_dcn_id'] = (int) $open->id;
                    $payload['open_request_url'] = route('dcs.office.dcn.show', ['id' => (int) $open->id], false);
                } else {
                    $payload['open_request_message'] = 'A Document Change Notice for this document is already submitted by another office and still waiting to be registered.';
                }
            }
        }
        if ($found) {
            $payload = ['found' => true] + $payload;
        }

        return $payload;
    }

    /**
     * Signatories saved on the registered DRF for this document request (recurring DRF prefill).
     * prepared_by_name: string,
     * prepared_by_designation: string,
     * reviewed_by_name: string,
     * reviewed_by_designation: string,
     * approved_by_name: string,
     * approved_by_designation: string
     * }|null
     * @return array{
     */
    private static function signatoriesForRegisteredRequest(int $requestId): ?array
    {
        if ($requestId < 1 || ! Schema::hasTable('dcs_document_request_form')) {
            return null;
        }

        $columns = [
            'prepared_by_name',
            'prepared_by_designation',
            'reviewed_by_name',
            'reviewed_by_designation',
            'approved_by_name',
            'approved_by_designation',
        ];
        $select = [];
        foreach ($columns as $column) {
            if (Schema::hasColumn('dcs_document_request_form', $column)) {
                $select[] = $column;
            }
        }
        if ($select === []) {
            return null;
        }

        $row = DB::table('dcs_document_request_form')
            ->where('request_id', $requestId)
            ->orderByDesc('id')
            ->first($select);
        if (! $row) {
            return null;
        }

        $payload = [];
        $hasAny = false;
        foreach ($columns as $column) {
            $value = in_array($column, $select, true) ? trim((string) ($row->{$column} ?? '')) : '';
            $payload[$column] = $value;
            if ($value !== '') {
                $hasAny = true;
            }
        }

        return $hasAny ? $payload : null;
    }

    /**
     * Helper used for approvals for registered request.
     */
    private static function approvalsForRegisteredRequest(int $requestId): array
    {
        if ($requestId < 1 || ! Schema::hasTable('dcs_document_change_notice') || ! Schema::hasTable('dcs_dcn_approvals')) {
            return [];
        }
        $dcnId = (int) DB::table('dcs_document_change_notice')->where('request_id', $requestId)->orderByDesc('id')->value('id');
        if ($dcnId < 1) {
            return [];
        }

        return DB::table('dcs_dcn_approvals')
            ->where('dcn_id', $dcnId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['position', 'name'])
            ->map(fn ($row) => [
                'position' => trim((string) ($row->position ?? '')),
                'name' => trim((string) ($row->name ?? '')),
            ])
            ->filter(fn (array $row) => $row['position'] !== '' || $row['name'] !== '')
            ->values()
            ->all();
    }

    /**
     * Parses or normalizes values used by resolve office dcn document.
     * @return array{document_no: string, document_title: string, previous_document_no: string}
     */
    public static function resolveOfficeDcnDocument(Request $request): array
    {
        $finderNo = trim((string) $request->input('finderDocumentNo', ''));
        $currentTitle = trim((string) $request->input('finderCurrentTitle', ''));
        $finderOriginator = trim((string) $request->input('finderOriginator', ''));
        $newTitle = trim((string) $request->input('documentTitle', ''));

        $matched = self::findRevisableRegistrationMatch($finderNo, $currentTitle, $finderOriginator);
        if (! $matched) {
            throw ValidationException::withMessages([
                'finderDocumentNo' => 'No registered document matched that number, title, and originator.',
            ]);
        }

        if ($newTitle === '') {
            throw ValidationException::withMessages([
                'documentTitle' => 'New document title is required.',
            ]);
        }

        $previous = trim((string) ($matched->doc_no ?? ''));
        if ($previous === '') {
            throw ValidationException::withMessages([
                'finderDocumentNo' => 'No registered document matched that number, title, and originator.',
            ]);
        }

        return [
            'document_no' => $previous,
            'document_title' => $newTitle,
            'previous_document_no' => $previous,
        ];
    }

    /**
     * Resolve the registered document a recurring office DRF is based on.
     * Match is number + title + originator. Prefer an explicit masterlist id when
     * the finder list selection sent one (same doc_no can belong to other titles).
     * previous_document_no: string,
     * matched_title: string,
     * matched_originator: string,
     * masterlist_id: int,
     * request_id: int,
     * doc_type_kind: string,
     * distribute_office_ids: list<int>
     * }
     * @return array{
     */
    public static function resolveOfficeDrfRecurDocument(Request $request): array
    {
        $finderNo = trim((string) $request->input('finderDocumentNo', ''));
        $currentTitle = trim((string) $request->input('finderCurrentTitle', ''));
        $finderOriginator = trim((string) $request->input('finderOriginator', ''));
        $confirmed = trim((string) $request->input('documentNoConfirmed', '')) === '1';
        $storedNo = trim((string) $request->input('documentNo', ''));
        $pickedMlId = (int) $request->input('finderMasterlistId', 0);
        $pickedRequestId = (int) $request->input('finderRequestId', 0);

        if (! $confirmed || ($storedNo === '' && $finderNo === '')) {
            throw ValidationException::withMessages([
                'finderDocumentNo' => 'Find the registered document before saving.',
            ]);
        }

        $matched = null;
        if ($pickedMlId > 0) {
            $matched = self::findRevisableRegistrationByMasterlistId($pickedMlId);
        }
        if (! $matched && $pickedRequestId > 0 && $currentTitle !== '') {
            $matched = self::findRevisableRegistrationMatch($finderNo !== '' ? $finderNo : $storedNo, $currentTitle, $finderOriginator);
            if ($matched && (int) ($matched->request_id ?? 0) !== $pickedRequestId) {
                $matched = null;
            }
        }
        if (! $matched) {
            $matched = self::findRevisableRegistrationMatch($finderNo, $currentTitle, $finderOriginator);
        }
        if (! $matched) {
            throw ValidationException::withMessages([
                'finderDocumentNo' => 'No registered document matched that number, title, and originator.',
            ]);
        }

        $previous = trim((string) ($matched->doc_no ?? ''));
        if ($previous === '') {
            throw ValidationException::withMessages([
                'finderDocumentNo' => 'No registered document matched that number, title, and originator.',
            ]);
        }

        // Guard: selected row title must match finder title (prevents Buhi pick → ERP family).
        $matchedTitle = trim((string) ($matched->doc_title ?? ''));
        if ($currentTitle !== '' && mb_strtolower($matchedTitle) !== mb_strtolower($currentTitle)) {
            throw ValidationException::withMessages([
                'finderCurrentTitle' => 'The selected document title does not match the finder title. Select the document again.',
            ]);
        }

        $typeName = trim((string) ($matched->doc_type_name ?? ''));
        $subName = trim((string) ($matched->sub_type_name ?? ''));
        $kind = str_contains(mb_strtolower($typeName.' '.$subName), 'external') ? 'external' : 'internal';
        $requestId = (int) ($matched->request_id ?? 0);

        $catalog = self::resolveIntakeCatalogSelection(
            (int) ($matched->doc_type_id ?? 0) ?: null,
            (int) ($matched->sub_type_id ?? 0) ?: null
        );

        return [
            'previous_document_no' => $previous,
            'matched_title' => $matchedTitle,
            'matched_originator' => trim((string) ($matched->originator_label ?? '')),
            'masterlist_id' => (int) ($matched->ml_id ?? 0),
            'request_id' => $requestId,
            'doc_type_kind' => $catalog['doc_type_kind'] ?: $kind,
            'doc_type_id' => $catalog['doc_type_id'],
            'sub_type_id' => $catalog['sub_type_id'],
            'distribute_office_ids' => self::distributionOfficeIdsForRequest($requestId),
        ];
    }

    /**
     * Loads or finds data for find revisable registration by masterlist id.
     */
    public static function findRevisableRegistrationByMasterlistId(int $masterlistId): ?object
    {
        if ($masterlistId < 1 || ! Schema::hasTable('dcs_masterlist_registration')) {
            return null;
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
            ->where('ml.id', $masterlistId);
        $originDisplay = RegisterQueryHelper::masterlistOriginatorNameSql('ml');
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyExcludeDrafts($query, 'dr');
        RegisterQueryHelper::applyExcludeOfficeIntakeRequests($query, 'dr');

        $select = [
            'ml.id as ml_id',
            'ml.request_id',
            'ml.doc_no',
            'ml.doc_title',
            'ml.revise_no',
            'dr.doc_type_id',
            'dr.sub_type_id',
            'dt.doc_type_name',
            'st.doc_type_name as sub_type_name',
            DB::raw($originDisplay.' as originator_label'),
        ];
        if (RegisterQueryHelper::supportsAllowsRevisionColumn()) {
            $select[] = 'ml.allows_revision';
        }

        return $query->first($select);
    }

    /**
     * Helper used for pending office request counts.
     * @return array{drf: int, dcn: int}
     */
    // =====================================================================
    // SECTION: DASHBOARD COUNTS & DCN→DRF PREFILL
    // =====================================================================
    
    public static function pendingOfficeRequestCounts(): array
    {
        return [
            'drf' => self::countOpenIntake('dcs_office_intake_drf'),
            'dcn' => self::countOpenIntake('dcs_office_intake_dcn'),
        ];
    }

    /**
     * Helper used for pending office request total.
     */
    public static function pendingOfficeRequestTotal(): int
    {
        $counts = self::pendingOfficeRequestCounts();

        return (int) ($counts['drf'] ?? 0) + (int) ($counts['dcn'] ?? 0);
    }

    /**
     * Compact Request-queue items for the admin DCS dashboard.
     * @return list<array{kind: string, type: string, title: string, meta: string, href: string}>
     */
    public static function dashboardRequestAttentionItems(int $limit = 6): array
    {
        if (! RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            return [];
        }

        $limit = max(1, min(12, $limit));

        return self::listPendingOfficeRequests()
            ->take($limit)
            ->map(function ($row) {
                $type = strtolower((string) ($row->type ?? 'drf'));
                $id = (int) ($row->id ?? 0);
                $office = trim((string) ($row->submitting_office ?? ''));
                $submitter = trim((string) ($row->submitter_name ?? ''));
                $metaParts = array_values(array_filter([$office, $submitter]));

                return [
                    'kind' => 'request',
                    'type' => $type,
                    'title' => trim((string) ($row->title ?? '')) ?: ('Untitled '.strtoupper($type)),
                    'meta' => $metaParts !== [] ? implode(' · ', $metaParts) : 'Waiting in Request',
                    'href' => route('dcs.requests.show', ['type' => $type, 'id' => $id], absolute: false),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Helper used for count open intake.
     */
    private static function countOpenIntake(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        if (Schema::hasColumn($table, 'rfio_registered_at')) {
            $query->whereNull('rfio_registered_at');
        }
        if (Schema::hasColumn($table, 'registered_request_id')) {
            $query->where(function ($sub) {
                $sub->whereNull('registered_request_id')->orWhere('registered_request_id', 0);
            });
        }
        if (Schema::hasColumn($table, 'rfio_claimed_at')) {
            $query->whereNull('rfio_claimed_at');
        }

        return (int) $query->count();
    }

    /**
     * Prefill values for DRF create from an owned office DCN.
     * Description/reason is left blank — office fills it manually.
     * @return array{drfTitle: string, originatorName: string, descriptionReason: string, from_dcn: int}
     */
    public static function drfPrefillFromDcn(int $dcnId): array
    {
        self::assertCanAccessIntake();
        $dcn = self::findOfficeDcn($dcnId);
        abort_unless($dcn, 404);
        self::assertOwnsDcn($dcn);
        self::assertOfficeDcnCanCreateDrf($dcnId);

        $revisions = self::dcnRevisions($dcnId);
        $firstRev = $revisions->first();
        $title = trim((string) ($dcn->document_title ?? ''))
            ?: trim((string) ($firstRev->title ?? ''));
        $docNo = trim((string) ($dcn->previous_document_no ?? ''));
        if ($docNo === '') {
            $docNo = trim((string) ($dcn->document_no ?? ''));
        }
        $registered = self::latestRegistrationForDocNo($docNo);
        $kind = 'internal';
        $label = '';
        $officeIds = [];
        $docTypeId = null;
        $subTypeId = null;
        if ($registered) {
            $typeName = trim((string) ($registered->doc_type_name ?? ''));
            $subName = trim((string) ($registered->sub_type_name ?? ''));
            $label = $subName !== '' ? $subName : $typeName;
            if (str_contains(mb_strtolower($typeName.' '.$subName), 'external')) {
                $kind = 'external';
            }
            $docTypeId = (int) ($registered->doc_type_id ?? 0) ?: null;
            $subTypeId = (int) ($registered->sub_type_id ?? 0) ?: null;
            $officeIds = self::distributionOfficeIdsForRequest((int) ($registered->request_id ?? 0));
        }

        return [
            'drfTitle' => $title,
            'originatorName' => trim((string) ($dcn->originator_name ?? '')),
            'descriptionReason' => '',
            'from_dcn' => $dcnId,
            'docTypeKind' => $kind,
            'docTypeId' => $docTypeId,
            'subTypeId' => $subTypeId,
            'docTypeLabel' => $label,
            'lockDocType' => $registered !== null,
            'distributeOfficeIds' => $officeIds,
        ];
    }

    /**
     * Helper used for latest registration for doc no.
     */
    public static function latestRegistrationForDocNo(string $docNo, ?string $title = null): ?object
    {
        $docNo = trim($docNo);
        if ($docNo === '' || ! Schema::hasTable('dcs_masterlist_registration')) {
            return null;
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
            ->whereRaw('LOWER(TRIM(ml.doc_no)) = ?', [mb_strtolower($docNo)]);
        $title = trim((string) $title);
        if ($title !== '') {
            $query->whereRaw('LOWER(TRIM(ml.doc_title)) = ?', [mb_strtolower($title)]);
        }
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyExcludeDrafts($query, 'dr');
        RegisterQueryHelper::applyExcludeOfficeIntakeRequests($query, 'dr');
        RegisterQueryHelper::applyLatestRevisionStatus($query, 'ml');

        return $query->orderByDesc('ml.revise_no')->orderByDesc('ml.id')->first([
            'ml.id as masterlist_id',
            'ml.request_id',
            'ml.doc_no',
            'ml.doc_title',
            'ml.revise_no',
            'dr.doc_type_id',
            'dr.sub_type_id',
            'dt.doc_type_name',
            'st.doc_type_name as sub_type_name',
        ]);
    }

    /**
     * Helper used for distribution office ids for request.
     */
    public static function distributionOfficeIdsForRequest(int $requestId): array
    {
        if ($requestId < 1 || ! Schema::hasTable('dcs_document_distribution') || ! Schema::hasTable('dcs_distribution_offices')) {
            return [];
        }
        $distId = (int) (DB::table('dcs_document_distribution')->where('request_id', $requestId)->orderByDesc('id')->value('id') ?: 0);
        if ($distId < 1) {
            return [];
        }

        return DB::table('dcs_distribution_offices')
            ->where('distribution_id', $distId)
            ->whereNotNull('office_id')
            ->orderBy('id')
            ->pluck('office_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
    }

    /**
     * Helper used for linked drf ids for office dcns.
     * @param  list<int>  $dcnIds
     * @return array<int, int>
     */
    public static function linkedDrfIdsForOfficeDcns(array $dcnIds): array
    {
        $dcnIds = array_values(array_unique(array_filter(array_map('intval', $dcnIds), static fn (int $id) => $id > 0)));
        if ($dcnIds === [] || ! Schema::hasColumn('dcs_office_intake_drf', 'source_office_dcn_id')) {
            return [];
        }

        $query = DB::table('dcs_office_intake_drf')
            ->whereIn('source_office_dcn_id', $dcnIds)
            ->orderBy('id');

        $out = [];
        foreach ($query->get(['id', 'source_office_dcn_id']) as $row) {
            $dcnId = (int) $row->source_office_dcn_id;
            if ($dcnId > 0 && ! isset($out[$dcnId])) {
                $out[$dcnId] = (int) $row->id;
            }
        }

        return $out;
    }

    /**
     * Loads or finds data for find linked drf id for office dcn.
     */
    public static function findLinkedDrfIdForOfficeDcn(int $dcnId): ?int
    {
        if ($dcnId < 1 || ! Schema::hasColumn('dcs_office_intake_drf', 'source_office_dcn_id')) {
            return null;
        }

        $query = DB::table('dcs_office_intake_drf')
            ->where('source_office_dcn_id', $dcnId);

        $id = $query->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * Loads or finds data for find source dcn id for office drf.
     */
    public static function findSourceDcnIdForOfficeDrf(int $drfId): ?int
    {
        if ($drfId < 1 || ! Schema::hasColumn('dcs_office_intake_drf', 'source_office_dcn_id')) {
            return null;
        }

        $query = DB::table('dcs_office_intake_drf')->where('id', $drfId);

        $dcnId = (int) ($query->value('source_office_dcn_id') ?: 0);

        return $dcnId > 0 ? $dcnId : null;
    }

    /**
     * Helper used for office dcn has linked drf.
     */
    public static function officeDcnHasLinkedDrf(int $dcnId): bool
    {
        return self::findLinkedDrfIdForOfficeDcn($dcnId) !== null;
    }

    /**
     * Helper used for office dcn allows later drf.
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function officeDcnAllowsLaterDrf(int $dcnId): bool
    {
        if ($dcnId < 1 || ! Schema::hasColumn('dcs_office_intake_dcn', 'expects_drf')) {
            return false;
        }

        return (bool) DB::table('dcs_office_intake_dcn')->where('id', $dcnId)->value('expects_drf');
    }

    /**
     * Stops the request if a required condition fails (assert office dcn can create drf).
     *
     * DEBUG: unexpected 403 / abort → check this guard.
     */
    public static function assertOfficeDcnCanCreateDrf(int $dcnId): void
    {
        if (! self::officeDcnAllowsLaterDrf($dcnId)) {
            throw ValidationException::withMessages([
                'from_dcn' => 'This Document Change Notice was created without a Document Request Form. A DRF cannot be added to it.',
            ]);
        }

        $existingId = self::findLinkedDrfIdForOfficeDcn($dcnId);
        if ($existingId === null) {
            return;
        }

        throw ValidationException::withMessages([
            'from_dcn' => 'This Document Change Notice already has a Document Request Form (DRF #'
                . $existingId
                . '). Open that DRF.',
        ]);
    }

    /**
     * Another office DCN for this registered document that RFIU has not registered yet.
     *
     * DEBUG: duplicate blocked or slipped through → check here.
     * @param  list<string>  $originators
     */
    // =====================================================================
    // SECTION: DUPLICATE GUARDS — block second open form for same title/originator
    // =====================================================================
    
    public static function findOpenDuplicateDcn(
        string $docNo,
        string $currentTitle,
        string $newTitle,
        array $originators,
        ?int $exceptId = null
    ): ?object {
        if (! Schema::hasTable('dcs_office_intake_dcn')) {
            return null;
        }

        self::reconcileClaimedIntakesWithRegistrations();

        $docNo = mb_strtolower(trim($docNo));
        if ($docNo === '') {
            return null;
        }

        $titles = array_values(array_unique(array_filter(array_map(
            static fn ($value) => mb_strtolower(trim((string) $value)),
            [$currentTitle, $newTitle]
        ))));
        $origins = array_values(array_unique(array_filter(array_map(
            static fn ($value) => mb_strtolower(trim((string) $value)),
            $originators
        ))));
        if ($titles === [] && $origins === []) {
            return null;
        }

        $query = self::openIntakeScope('dcs_office_intake_dcn');
        if ($exceptId) {
            $query->where('id', '<>', $exceptId);
        }
        $query->where(function ($inner) use ($docNo) {
            $inner->whereRaw("LOWER(TRIM(COALESCE(previous_document_no, ''))) = ?", [$docNo])
                ->orWhereRaw("LOWER(TRIM(COALESCE(document_no, ''))) = ?", [$docNo]);
        });

        $cols = ['id', 'document_no', 'document_title'];
        if (Schema::hasColumn('dcs_office_intake_dcn', 'originator_name')) {
            $cols[] = 'originator_name';
        }
        $candidates = $query->orderBy('id')->get($cols);
        self::hydrateIntakeOriginators('dcn', $candidates);
        foreach ($candidates as $row) {
            $title = mb_strtolower(trim((string) ($row->document_title ?? '')));
            $origin = mb_strtolower(trim((string) ($row->originator_name ?? '')));
            $sameTitle = $title !== '' && in_array($title, $titles, true);
            $sameOrigin = $origin !== '' && in_array($origin, $origins, true);
            if ($sameTitle || $sameOrigin) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Helper used for duplicate dcn message.
     */
    public static function duplicateDcnMessage(object $row): string
    {
        $no = trim((string) ($row->document_no ?? ''));
        $title = trim((string) ($row->document_title ?? ''));
        $label = trim($no.($title !== '' ? ' — '.$title : ''));

        return 'A Document Change Notice for '.($label !== '' ? $label : 'this document')
            .' is already submitted and still waiting to be registered. Open DCN #'.(int) $row->id.'.';
    }

    /**
     * Loads or finds data for find open duplicate recurring drf.
     *
     * DEBUG: duplicate blocked or slipped through → check here.
     */
    public static function findOpenDuplicateRecurringDrf(
        string $docNo,
        string $title = '',
        ?int $exceptId = null
    ): ?object {
        if (! Schema::hasTable('dcs_office_intake_drf')
            || ! Schema::hasColumn('dcs_office_intake_drf', 'previous_document_no')) {
            return null;
        }

        self::reconcileClaimedIntakesWithRegistrations();

        $docNo = mb_strtolower(trim($docNo));
        if ($docNo === '') {
            return null;
        }

        $query = self::openIntakeScope('dcs_office_intake_drf')
            ->whereRaw("LOWER(TRIM(COALESCE(previous_document_no, ''))) = ?", [$docNo]);

        // Recurring DRFs only — exclude DRFs that were created from a DCN.
        if (Schema::hasColumn('dcs_office_intake_drf', 'source_office_dcn_id')) {
            $query->where(function ($inner) {
                $inner->whereNull('source_office_dcn_id')->orWhere('source_office_dcn_id', 0);
            });
        }

        if ($exceptId) {
            $query->where('id', '<>', $exceptId);
        }

        $cols = ['id', 'doc_title', 'previous_document_no'];
        if (Schema::hasColumn('dcs_office_intake_drf', 'previous_document_title')) {
            $cols[] = 'previous_document_title';
        }
        $candidates = $query->orderBy('id')->get($cols);
        if ($candidates->isEmpty()) {
            return null;
        }

        $titleKey = mb_strtolower(trim($title));
        if ($titleKey !== '') {
            foreach ($candidates as $row) {
                $rowTitle = mb_strtolower(trim((string) (
                    $row->previous_document_title
                    ?? $row->doc_title
                    ?? ''
                )));
                if ($rowTitle !== '' && $rowTitle === $titleKey) {
                    return $row;
                }
            }
        }

        return $candidates->first();
    }

    /**
     * Helper used for duplicate recurring drf message.
     */
    public static function duplicateRecurringDrfMessage(object $row): string
    {
        $no = trim((string) ($row->previous_document_no ?? ''));
        $title = trim((string) (
            $row->previous_document_title
            ?? $row->doc_title
            ?? ''
        ));
        $label = trim($no.($title !== '' ? ' — '.$title : ''));

        return 'A Recurring DRF for '.($label !== '' ? $label : 'this document')
            .' is already submitted and still waiting to be registered.';
    }

    /**
     * Helper used for duplicate dcn blocks recurring message.
     */
    public static function duplicateDcnBlocksRecurringMessage(object $row): string
    {
        $no = trim((string) ($row->document_no ?? ''));
        $title = trim((string) ($row->document_title ?? ''));
        $label = trim($no.($title !== '' ? ' — '.$title : ''));

        return 'A Document Change Notice for '.($label !== '' ? $label : 'this document')
            .' is already submitted and still waiting to be registered. Finish or cancel that DCN before starting a Recurring DRF.';
    }

    /**
     * Stops the request if a required condition fails (assert no open duplicate dcn).
     *
     * DEBUG: duplicate blocked or slipped through → check here.
     */
    public static function assertNoOpenDuplicateDcn(
        string $docNo,
        string $currentTitle,
        string $newTitle,
        array $originators,
        ?int $exceptId = null
    ): void {
        $existing = self::findOpenDuplicateDcn($docNo, $currentTitle, $newTitle, $originators, $exceptId);
        if (! $existing) {
            return;
        }

        throw ValidationException::withMessages([
            'finderDocumentNo' => self::duplicateDcnMessage($existing),
        ]);
    }

    /**
     * Stops the request if a required condition fails (assert no open duplicate recurring drf).
     *
     * DEBUG: duplicate blocked or slipped through → check here.
     */
    public static function assertNoOpenDuplicateRecurringDrf(
        string $docNo,
        string $title = '',
        ?int $exceptId = null
    ): void {
        $existing = self::findOpenDuplicateRecurringDrf($docNo, $title, $exceptId);
        if (! $existing) {
            return;
        }

        throw ValidationException::withMessages([
            'finderDocumentNo' => self::duplicateRecurringDrfMessage($existing),
        ]);
    }

    /**
     * Stops the request if a required condition fails (assert no open duplicate drf).
     *
     * DEBUG: duplicate blocked or slipped through → check here.
     */
    public static function assertNoOpenDuplicateDrf(string $title, string $originator, ?int $exceptId = null): void
    {
        if (! Schema::hasTable('dcs_office_intake_drf')) {
            return;
        }

        self::reconcileClaimedIntakesWithRegistrations();

        $title = mb_strtolower(trim($title));
        $originator = mb_strtolower(trim($originator));
        if ($title === '' || $originator === '') {
            return;
        }

        $query = self::openIntakeScope('dcs_office_intake_drf')
            ->whereRaw('LOWER(TRIM(COALESCE(doc_title, \'\'))) = ?', [$title]);
        if (Schema::hasColumn('dcs_office_intake_drf', 'originator_name')) {
            $query->whereRaw('LOWER(TRIM(COALESCE(originator_name, \'\'))) = ?', [$originator]);
        } elseif (Schema::hasTable('dcs_office_drf_originators')) {
            $query->whereRaw(
                "LOWER(TRIM(COALESCE((SELECT string_agg(og.originator_name, ', ' ORDER BY j.sort_order, j.id) FROM dcs_office_drf_originators j INNER JOIN dcs_originators og ON og.id = j.originator_id WHERE j.office_intake_drf_id = dcs_office_intake_drf.id), ''))) = ?",
                [$originator]
            );
        }
        if ($exceptId) {
            $query->where('id', '<>', $exceptId);
        }

        $existing = $query->orderBy('id')->first(['id', 'doc_title']);
        if (! $existing) {
            return;
        }

        throw ValidationException::withMessages([
            'drfTitle' => 'A Document Request Form for "'.trim((string) ($existing->doc_title ?? '')).'"'
                .' by this originator is already submitted and still waiting to be registered. Open DRF #'.(int) $existing->id.'.',
        ]);
    }

    /**
     * Helper used for open intake scope.
     */
    private static function openIntakeScope(string $table)
    {
        $query = DB::table($table);
        if (Schema::hasColumn($table, 'rfio_registered_at')) {
            $query->whereNull('rfio_registered_at');
        }
        if (Schema::hasColumn($table, 'registered_request_id')) {
            $query->where(function ($inner) {
                $inner->whereNull('registered_request_id')->orWhere('registered_request_id', 0);
            });
        }

        return $query;
    }

    /**
     * Helper used for current user office for dcn.
     */
    public static function currentUserOfficeForDcn(): array
    {
        $id = RegisterQueryHelper::currentOfficeId();
        $code = trim((string) (RegisterQueryHelper::currentOfficeCode() ?? ''));
        $name = RegisterQueryHelper::currentOfficeName();
        $name = $name !== '—' ? trim($name) : '';
        $label = '';
        if ($code !== '' && $name !== '') {
            $label = $code . ' — ' . $name;
        } elseif ($name !== '') {
            $label = $name;
        } elseif ($code !== '') {
            $label = $code;
        }

        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'label' => $label,
        ];
    }

    /**
     * Helper used for locked department office id.
     */
    private static function lockedDepartmentOfficeId(): int
    {
        $id = RegisterQueryHelper::currentOfficeId();
        if (! $id) {
            throw ValidationException::withMessages([
                'departmentOfficeId' => 'Your account has no office assigned.',
            ]);
        }

        return $id;
    }

    /**
     * Helper used for originator matches user.
     */
    public static function originatorMatchesUser(?string $originatorName, ?int $originatorAccountId = null): bool
    {
        return RegisterQueryHelper::originatorMatchesCurrentUser($originatorName, $originatorAccountId);
    }

    /**
     * Helper used for merge joined originator.
     */
    private static function mergeJoinedOriginator(Request $request): void
    {
        $request->merge([
            'originatorName' => RegisterPersistHelper::joinedOriginatorNames($request->input('originatorName')),
        ]);
    }

    /**
     * Helper used for bump office inventory cache.
     */
    public static function bumpOfficeInventoryCache(): void
    {
        try {
            $version = (int) Cache::get('dcs.ofi.inventory.version', 1);
            Cache::forever('dcs.ofi.inventory.version', $version + 1);
        } catch (\Throwable) {
        }
    }

    /**
     * Helper used for office inventory cache version.
     */
    private static function officeInventoryCacheVersion(): int
    {
        try {
            return (int) Cache::get('dcs.ofi.inventory.version', 1);
        } catch (\Throwable) {
            return 1;
        }
    }

    /**
     * Workday-friendly default TTL: repeated Incoming/Masterlist polls reuse
     * totals/groups/pages instead of re-scanning distribution rows every click.
     */
    private static function rememberOfficeInventory(string $suffix, callable $loader, int $ttl = 90): mixed
    {
        $officeId = (int) (RegisterQueryHelper::currentOfficeId() ?? 0);
        $rdp = auth()->user()?->permissions?->can_access_rdp ? '1' : '0';
        $key = 'dcs.ofi.inv.' . self::officeInventoryCacheVersion() . '.' . $officeId . '.' . $rdp . '.' . $suffix;
        try {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }
        } catch (\Throwable) {
            $cached = null;
        }

        $value = $loader();
        try {
            Cache::put($key, $value, $ttl);
        } catch (\Throwable) {
        }

        return $value;
    }

    // =====================================================================
    // SECTION: CREATE / UPDATE / PREVIEW — office submits DRF or DCN
    // =====================================================================
    
    /**
     * Creates a new office Document Request Form from the Create DRF screen.
     *
     * DEBUG: DRF create fails / duplicate blocked → validation + assertNoOpenDuplicate*.
     */
    public static function storeDrf(Request $request): RedirectResponse
    {
        self::assertCanAccessIntake();
        RegisterPersistHelper::blankStringsToNull($request);
        self::mergeJoinedOriginator($request);

        $rateCheck = \App\Services\RateLimiterService::check('dcs_create');
        if (!$rateCheck['allowed']) {
            return back()->withInput()->with('error', $rateCheck['message']);
        }

        self::mergeDistributeOfficeBackup($request);

        $data = $request->validate([
            'drfDate' => 'required|date',
            'drfTitle' => 'required|string',
            'originatorName' => 'required|string',
            'docTypeKind' => 'required|in:internal,external,forms,logbooks',
            'docTypeId' => 'nullable|integer',
            'subTypeId' => 'nullable|integer',
            'collegeId' => 'nullable|integer',
            'programId' => 'nullable|integer',
            'courseId' => 'nullable|integer',
            'semesterId' => 'nullable|integer',
            'schoolYearId' => 'nullable|integer',
            'descriptionReason' => 'required|string|max:5000',
            // Empty = office-only controlled document (Source Unit). Other offices only when they need a copy.
            'distributeToOffice' => 'nullable|array',
            'distributeToOffice.*' => 'integer',
            'preparedByName' => 'required|string|max:255',
            'preparedByDesignation' => 'required|string|max:255',
            'reviewedByName' => 'required|string|max:255',
            'reviewedByDesignation' => 'required|string|max:255',
            'approvedByName' => 'required|string|max:255',
            'approvedByDesignation' => 'required|string|max:255',
            'from_dcn' => 'nullable|integer',
            'drfMode' => 'nullable|in:recurring',
            'confirmDataCorrect' => 'accepted',
        ]);

        $postedKind = strtolower(trim((string) ($data['docTypeKind'] ?? '')));
        $data = self::resolveOfficeDrfTypeSelection($data);

        // External is kind-only. Forms/Logbooks resolve to their catalog parent.
        // Internal requires catalog document type (+ sub-type when present).
        if ($postedKind === 'external') {
            $data['docTypeId'] = null;
            $data['subTypeId'] = null;
        } elseif (! in_array($postedKind, ['forms', 'logbooks'], true)) {
            if (empty($data['docTypeId'])) {
                throw ValidationException::withMessages([
                    'docTypeId' => 'Select a document type.',
                ]);
            }
            if (Schema::hasTable('dcs_doc_types')) {
                $hasSubs = DB::table('dcs_doc_types')->where('parent_id', (int) $data['docTypeId'])->exists();
                if ($hasSubs && empty($data['subTypeId'])) {
                    throw ValidationException::withMessages([
                        'subTypeId' => 'Select a sub-type for this internal document type.',
                    ]);
                }
            }
        }

        $catalog = self::resolveIntakeCatalogSelection(
            isset($data['docTypeId']) ? (int) $data['docTypeId'] : null,
            isset($data['subTypeId']) ? (int) $data['subTypeId'] : null
        );
        $syllabiCtx = self::resolveOfficeDrfSyllabiContext(
            $data,
            ! empty($catalog['sub_type_id']) ? (int) $catalog['sub_type_id'] : null
        );
        if ($postedKind === 'internal' && ! $catalog['doc_type_id'] && ! $catalog['sub_type_id']) {
            throw ValidationException::withMessages([
                'docTypeId' => 'Select a document type.',
            ]);
        }
        if (in_array($postedKind, ['forms', 'logbooks'], true)) {
            $data['docTypeKind'] = $postedKind;
        } elseif ($catalog['doc_type_id'] || $catalog['sub_type_id']) {
            $data['docTypeKind'] = $catalog['doc_type_kind'];
        } else {
            $data['docTypeKind'] = in_array($postedKind, ['internal', 'external'], true)
                ? $postedKind
                : 'internal';
        }
        $data['resolvedDocTypeId'] = $catalog['doc_type_id'];
        $data['resolvedSubTypeId'] = $catalog['sub_type_id'];

        $sourceDcnId = (int) ($data['from_dcn'] ?? 0);
        if ($sourceDcnId > 0) {
            $sourceDcn = self::findOfficeDcn($sourceDcnId);
            abort_unless($sourceDcn, 404);
            self::assertOwnsDcn($sourceDcn);
            self::assertOfficeDcnCanCreateDrf($sourceDcnId);
        } else {
            $sourceDcnId = 0;
        }

        $isRecurring = $sourceDcnId < 1 && ($data['drfMode'] ?? '') === 'recurring';
        $previousDocNo = '';
        $previousTitle = '';
        $previousOriginator = '';
        $previousMasterlistId = 0;
        $previousRequestId = 0;
        if ($isRecurring) {
            if (! Schema::hasColumn('dcs_office_intake_drf', 'previous_document_no')) {
                throw ValidationException::withMessages([
                    'finderDocumentNo' => 'Recurring DRF is not available until the database migration for previous_document_no is run.',
                ]);
            }
            $resolved = self::resolveOfficeDrfRecurDocument($request);
            $previousDocNo = $resolved['previous_document_no'];
            $previousTitle = trim((string) ($resolved['matched_title'] ?? ''))
                ?: trim((string) $request->input('finderCurrentTitle', ''));
            $previousOriginator = trim((string) ($resolved['matched_originator'] ?? ''))
                ?: trim((string) $request->input('finderOriginator', ''));
            $previousMasterlistId = (int) ($resolved['masterlist_id'] ?? 0);
            $previousRequestId = (int) ($resolved['request_id'] ?? 0);
            // Prefer registered catalog type / kind when the finder resolved a match.
            if (! empty($resolved['doc_type_kind'])) {
                $data['docTypeKind'] = $resolved['doc_type_kind'];
            }
            if (! empty($resolved['doc_type_id'])) {
                $data['resolvedDocTypeId'] = (int) $resolved['doc_type_id'];
            }
            if (array_key_exists('sub_type_id', $resolved)) {
                $data['resolvedSubTypeId'] = ! empty($resolved['sub_type_id'])
                    ? (int) $resolved['sub_type_id']
                    : null;
            }
            // Keep the DRF title aligned with the selected document family unless the office typed another title.
            if ($previousTitle !== '' && trim((string) ($data['drfTitle'] ?? '')) === '') {
                $data['drfTitle'] = $previousTitle;
            }
            self::assertNoOpenDuplicateRecurringDrf($previousDocNo, $previousTitle);
            $openDcn = self::findOpenDuplicateDcn(
                $previousDocNo,
                $previousTitle,
                '',
                [
                    $previousOriginator,
                    trim((string) ($data['originatorName'] ?? '')),
                ]
            );
            if ($openDcn) {
                throw ValidationException::withMessages([
                    'finderDocumentNo' => self::duplicateDcnBlocksRecurringMessage($openDcn),
                ]);
            }
        }

        self::assertNoOpenDuplicateDrf(
            (string) ($data['drfTitle'] ?? ''),
            (string) ($data['originatorName'] ?? '')
        );

        // Source unit / department is always the signed-in office (same as DCN) — not client-editable.
        $officeIds = [self::lockedDepartmentOfficeId()];

        $userId = (int) auth()->id();
        $now = now();

        $id = DB::transaction(function () use (
            $data,
            $officeIds,
            $userId,
            $now,
            $sourceDcnId,
            $previousDocNo,
            $previousTitle,
            $previousOriginator,
            $previousMasterlistId,
            $previousRequestId,
            $syllabiCtx
        ) {
                $row = [
                    'drf_no' => null,
                    'drf_date' => $data['drfDate'] ?? null,
                    'doc_title' => $data['drfTitle'],
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'prepared_by_name' => trim((string) ($data['preparedByName'] ?? '')) ?: null,
                    'doc_type_kind' => $data['docTypeKind'] ?? null,
                    'description_reason' => trim((string) ($data['descriptionReason'] ?? '')) ?: null,
                ];
                if (Schema::hasColumn('dcs_office_intake_drf', 'doc_type_id')) {
                    $row['doc_type_id'] = ! empty($data['resolvedDocTypeId']) ? (int) $data['resolvedDocTypeId'] : null;
                }
                if (Schema::hasColumn('dcs_office_intake_drf', 'sub_type_id')) {
                    $row['sub_type_id'] = ! empty($data['resolvedSubTypeId']) ? (int) $data['resolvedSubTypeId'] : null;
                }
                self::applyOfficeDrfSyllabiColumns($row, $syllabiCtx);
                if (Schema::hasColumn('dcs_office_intake_drf', 'originator_name')) {
                    $row['originator_name'] = RegisterPersistHelper::joinedOriginatorNames($data['originatorName'] ?? null) ?: null;
                }

                if ($sourceDcnId > 0) {
                    $row['source_office_dcn_id'] = $sourceDcnId;
                }
                if ($previousDocNo !== '' && Schema::hasColumn('dcs_office_intake_drf', 'previous_document_no')) {
                    $row['previous_document_no'] = $previousDocNo;
                }
                if ($previousTitle !== '' && Schema::hasColumn('dcs_office_intake_drf', 'previous_document_title')) {
                    $row['previous_document_title'] = $previousTitle;
                }
                if ($previousOriginator !== '' && Schema::hasColumn('dcs_office_intake_drf', 'previous_originator')) {
                    $row['previous_originator'] = $previousOriginator;
                }
                if ($previousMasterlistId > 0 && Schema::hasColumn('dcs_office_intake_drf', 'previous_masterlist_id')) {
                    $row['previous_masterlist_id'] = $previousMasterlistId;
                }
                if ($previousRequestId > 0 && Schema::hasColumn('dcs_office_intake_drf', 'previous_request_id')) {
                    $row['previous_request_id'] = $previousRequestId;
                }
                foreach ([
                    'prepared_by_designation' => 'preparedByDesignation',
                    'reviewed_by_name' => 'reviewedByName',
                    'reviewed_by_designation' => 'reviewedByDesignation',
                    'approved_by_name' => 'approvedByName',
                    'approved_by_designation' => 'approvedByDesignation',
                ] as $column => $inputKey) {
                    $row[$column] = trim((string) ($data[$inputKey] ?? '')) ?: null;
                }

                $drfId = DB::table('dcs_office_intake_drf')->insertGetId($row);
                self::syncIntakeOriginators('dcs_office_drf_originators', 'office_intake_drf_id', $drfId, $data['originatorName'] ?? null);
                self::syncDistributeOffices($drfId, $data['distributeToOffice'] ?? []);

                foreach ($officeIds as $officeId) {
                    if ($officeId <= 0) {
                        continue;
                    }
                    DB::table('dcs_office_drf_offices')->insert([
                        'office_intake_drf_id' => $drfId,
                        'office_id' => $officeId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return $drfId;
            });

        RegisterPersistHelper::logAdminChange(
            'Created office DRF #' . $id . ': ' . $data['drfTitle']
        );
        self::bumpOfficeInventoryCache();

        \App\Services\DcsAuditService::log(
            'office.drf.create',
            'review_intake',
            null,
            null,
            ['drf_id' => $id, 'title' => $data['drfTitle']]
        );

        // A DRF that belongs to a DCN is one combined notice. A DRF on its own stays a DRF notice.
        $notifyOffice = RegisterQueryHelper::rfioNotificationOfficeCode();
        $submitter = RegisterQueryHelper::currentUserDisplayName();
        if ($sourceDcnId > 0) {
            $sourceDcn = self::findOfficeDcn($sourceDcnId);
            DcsNotificationService::notifyOfficeDcnWithDrfSubmitted(
                $notifyOffice,
                $submitter,
                (string) ($sourceDcn->document_no ?? ''),
                $data['drfTitle'],
                $sourceDcnId
            );
        } else {
            DcsNotificationService::notifyOfficeDrfSubmitted(
                $notifyOffice,
                $submitter,
                '',
                $data['drfTitle'],
                $id
            );
        }

        return redirect()
            ->route('dcs.office.drf.show', $id)
            ->with('success', 'Document Request Form saved. This document cannot be edited.')
            ->with('locked', true);
    }

    /**
     * Builds the print/preview data for an office DRF without saving.
     */
    public static function previewDrf(Request $request)
    {
        self::assertCanAccessIntake();
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('distributeToOffice', []))));
        $distributeOffices = self::officesForPreview($ids);
        $drf = (object) [
            'id' => 0,
            'doc_title' => trim((string) $request->input('drfTitle', '')),
            'drf_date' => $request->input('drfDate') ?: null,
            'originator_name' => RegisterPersistHelper::joinedOriginatorNames($request->input('originatorName')),
            'doc_type_kind' => $request->input('docTypeKind'),
            'description_reason' => trim((string) $request->input('descriptionReason', '')),
            'prepared_by_name' => trim((string) $request->input('preparedByName', '')),
            'prepared_by_designation' => trim((string) $request->input('preparedByDesignation', '')),
            'reviewed_by_name' => trim((string) $request->input('reviewedByName', '')),
            'reviewed_by_designation' => trim((string) $request->input('reviewedByDesignation', '')),
            'approved_by_name' => trim((string) $request->input('approvedByName', '')),
            'approved_by_designation' => trim((string) $request->input('approvedByDesignation', '')),
            'preview_distribute_offices' => $distributeOffices,
        ];
        $logoSrc = self::printLogoSrc();
        $sourceOffices = collect();
        $viewerMode = false;
        $allowPrint = false;

        // Editable Form Templates layout drives office DRF print/preview.
        $tpl = DcsPrintTemplateHelper::get('office_drf');
        $identity = DcsPrintTemplateHelper::identityVars($tpl);
        if (($identity['logoSrc'] ?? '') !== '') {
            $logoSrc = $identity['logoSrc'];
        }
        $blocks = DcsPrintTemplateHelper::layoutBlocks($tpl);
        if ($blocks === []) {
            return response()->view('pages.dcs.office.drf-print', [
                'drf' => $drf,
                'logoSrc' => $logoSrc,
                'sourceOffices' => $sourceOffices,
                'viewerMode' => $viewerMode,
                'allowPrint' => $allowPrint,
                'printFormCode' => $identity['letterNumber'] !== '' ? $identity['letterNumber'] : 'CSPC-F-DCC-06',
                'printFormTitle' => $identity['formTitle'] !== '' ? $identity['formTitle'] : 'Document Request Form',
                'printRepublic' => $identity['republic'],
                'printInstitution' => $identity['institutionName'],
                'printAddress' => $identity['institutionAddress'],
                'printFooterLeft' => $identity['footerLeft'] !== '' ? $identity['footerLeft'] : 'Effectivity Date:',
                'printFooterCenter' => $identity['footerCenter'] !== '' ? $identity['footerCenter'] : 'Rev.',
                'printFooterEffectivity' => $identity['footerEffectivity'] !== '' ? $identity['footerEffectivity'] : 'January 2018',
                'printFooterRev' => $identity['footerRev'] !== '' ? $identity['footerRev'] : '0',
            ]);
        }

        return response()->view('pages.dcs.reports.print-layout-renderer', [
            'tpl' => $tpl,
            'values' => DcsPrintTemplateHelper::drfLayoutValues($drf, $distributeOffices, $sourceOffices),
            'distributeOffices' => $distributeOffices,
            'logoSrcOverride' => $logoSrc,
            'preview' => true,
            'hideToolbar' => false,
            'embed' => false,
            'viewerMode' => $viewerMode,
            'allowPrint' => false,
            'printLockMessage' => 'Preview only — this is not a printable copy yet.',
        ]);
    }

    /**
     * Builds the print/preview data for an office DCN without saving.
     */
    public static function previewDcn(Request $request)
    {
        self::assertCanAccessIntake();
        $names = (array) $request->input('reviewedByName', []);
        $dates = (array) $request->input('reviewedByOn', []);
        $reviewers = [];
        $count = max(count($names), count($dates));
        for ($i = 0; $i < $count; $i++) {
            $name = trim((string) ($names[$i] ?? ''));
            $date = trim((string) ($dates[$i] ?? ''));
            if ($name === '' && $date === '') {
                continue;
            }
            $reviewers[] = [
                'name' => $name,
                'date' => $date !== '' ? $date : null,
                'label' => self::formatReviewedByLabel($name, $date) ?? '',
            ];
        }
        $officeId = (int) $request->input('departmentOfficeId', 0);
        $office = $officeId > 0 ? (self::officesForPreview([$officeId])[0] ?? null) : null;
        $storedNo = trim((string) $request->input('documentNo', ''));
        if ($storedNo === '') {
            $storedNo = trim((string) $request->input('finderDocumentNo', ''));
        }
        $dcn = (object) [
            'id' => 0,
            'document_no' => $storedNo,
            'document_title' => trim((string) $request->input('documentTitle', '')),
            'change_from' => trim((string) $request->input('changeFrom', '')),
            'change_to' => trim((string) $request->input('changeTo', '')),
            'brief_purpose' => trim((string) $request->input('dcnJustification', '')),
            'originator_name' => RegisterPersistHelper::joinedOriginatorNames($request->input('originatorName')),
            'department_on' => $request->input('departmentDate') ?: null,
            'dcn_no' => '',
            'preview_reviewers' => $reviewers,
            'preview_department_code' => $office['code'] ?? '',
            'preview_department' => $office['name'] ?? '',
        ];
        $logoSrc = self::printLogoSrc();
        $revisions = collect();
        $sourceOffices = collect();
        $viewerMode = false;
        $allowPrint = false;

        // Official CSPC-F-DCC-01 blade + live canvas overrides (same as Form Templates preview).
        $tpl = DcsPrintTemplateHelper::get('office_dcn');
        $identity = DcsPrintTemplateHelper::identityVars($tpl);
        if (($identity['logoSrc'] ?? '') !== '') {
            $logoSrc = $identity['logoSrc'];
        }
        $layoutOverrides = DcsPrintTemplateHelper::dcnLiveLayoutOverrides();

        return response()->view('pages.dcs.office.dcn-print', [
            'dcn' => $dcn,
            'logoSrc' => $logoSrc,
            'revisions' => $revisions,
            'sourceOffices' => $sourceOffices,
            'viewerMode' => $viewerMode,
            'allowPrint' => $allowPrint,
            'printLockMessage' => 'Preview only — this is not a printable copy yet.',
            'printFormCode' => $identity['letterNumber'] !== '' ? $identity['letterNumber'] : 'CSPC-F-DCC-01',
            'printFormTitle' => $identity['formTitle'] !== '' ? $identity['formTitle'] : 'Document Change Notice',
            'printRepublic' => $identity['republic'],
            'printInstitution' => $identity['institutionName'],
            'printAddress' => $identity['institutionAddress'],
            'printFooterLeft' => $identity['footerLeft'] !== '' ? $identity['footerLeft'] : 'Effectivity Date:',
            'printFooterCenter' => $identity['footerCenter'] !== '' ? $identity['footerCenter'] : 'Rev.',
            'printFooterEffectivity' => $identity['footerEffectivity'] !== '' ? $identity['footerEffectivity'] : 'January 2018',
            'printFooterRev' => $identity['footerRev'] !== '' ? $identity['footerRev'] : '1',
            'layoutOverrides' => $layoutOverrides,
        ]);
    }

    /**
     * Helper used for offices for preview.
     * @return list<array{office_id: int, code: string, name: string}>
     */
    private static function officesForPreview(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $byId = DB::table($officeTable)->whereIn('id', $ids)->get(['id', 'office_code', 'office_name'])->keyBy('id');
        $offices = [];
        foreach ($ids as $officeId) {
            $row = $byId[$officeId] ?? null;
            if (! $row) {
                continue;
            }
            $offices[] = [
                'office_id' => $officeId,
                'code' => trim((string) ($row->office_code ?? '')),
                'name' => trim((string) ($row->office_name ?? '')),
            ];
        }

        return $offices;
    }

    /**
     * Helper used for print logo src.
     */
    private static function printLogoSrc(): string
    {
        $logoPath = public_path('images/logo.png');

        return file_exists($logoPath) ? ('data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath))) : '';
    }

    /**
     * Creates a new office Document Change Notice from the Create DCN screen.
     *
     * DEBUG: DCN create fails / duplicate blocked → validation + findOpenDuplicateDcn.
     */
    public static function storeDcn(Request $request): RedirectResponse
    {
        self::assertCanAccessIntake();
        RegisterPersistHelper::blankStringsToNull($request);
        self::mergeJoinedOriginator($request);

        $rateCheck = \App\Services\RateLimiterService::check('dcs_create');
        if (!$rateCheck['allowed']) {
            return back()->withInput()->with('error', $rateCheck['message']);
        }

        $data = $request->validate([
            'finderDocumentNo' => 'required|string|max:150',
            'finderCurrentTitle' => 'required|string',
            'finderOriginator' => 'required|string',
            'documentNoMode' => 'nullable|in:keep,new',
            'newDocumentNo' => 'nullable|string|max:150',
            'documentNo' => 'nullable|string|max:150',
            'documentTitle' => 'required|string',
            'changeFrom' => 'required|string|max:5000',
            'changeTo' => 'required|string|max:5000',
            'dcnJustification' => 'required|string|max:5000',
            'originatorName' => 'required|string',
            'departmentDate' => 'required|date',
            'reviewedByName' => 'required|array|min:1|max:9',
            'reviewedByName.*' => 'required|string|max:255',
            'approvalPosition' => 'nullable|array|max:9',
            'approvalPosition.*' => 'nullable|string|max:255',
            'approvalName' => 'nullable|array|max:9',
            'approvalName.*' => 'nullable|string|max:255',
            'alsoCreateDrf' => 'nullable|boolean',
            'confirmDataCorrect' => 'accepted',
        ], [
            'reviewedByName.*.required' => 'Each reviewer needs a name. Remove a reviewer you are not using.',
        ]);

        self::assertApprovalRowsComplete($request);

        $resolved = self::resolveOfficeDcnDocument($request);
        $docNo = $resolved['document_no'];
        $docTitle = $resolved['document_title'];
        $previousDocNo = $resolved['previous_document_no'];
        self::assertNoOpenDuplicateDcn(
            $previousDocNo,
            trim((string) $request->input('finderCurrentTitle', '')),
            $docTitle,
            [
                trim((string) $request->input('finderOriginator', '')),
                trim((string) ($data['originatorName'] ?? '')),
            ]
        );
        $reviewers = self::reviewersPayloadFromRequest($data);
        $approvals = self::approvalsPayloadFromRequest($data);

        $userId = (int) auth()->id();
        $now = now();

        try {
            $expectsDrf = $request->boolean('alsoCreateDrf');
            $id = DB::transaction(function () use ($data, $docNo, $docTitle, $previousDocNo, $reviewers, $approvals, $userId, $now, $expectsDrf) {
                $row = [
                    'dcn_no' => null,
                    'dcn_date' => now()->toDateString(),
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'brief_purpose' => $data['dcnJustification'],
                    'document_no' => $docNo !== '' ? $docNo : null,
                    'document_title' => $docTitle !== '' ? $docTitle : null,
                    'change_from' => trim((string) ($data['changeFrom'] ?? '')) ?: null,
                    'change_to' => trim((string) ($data['changeTo'] ?? '')) ?: null,
                    'department_on' => $data['departmentDate'] ?? null,
                ];
                if (Schema::hasColumn('dcs_office_intake_dcn', 'previous_document_no')) {
                    $row['previous_document_no'] = $previousDocNo !== '' ? $previousDocNo : null;
                }
                if (Schema::hasColumn('dcs_office_intake_dcn', 'expects_drf')) {
                    $row['expects_drf'] = $expectsDrf;
                }
                if (Schema::hasColumn('dcs_office_intake_dcn', 'originator_name')) {
                    $row['originator_name'] = RegisterPersistHelper::joinedOriginatorNames($data['originatorName'] ?? null) ?: null;
                }

                $dcnId = DB::table('dcs_office_intake_dcn')->insertGetId($row);
                self::syncIntakeOriginators('dcs_office_dcn_originators', 'office_intake_dcn_id', $dcnId, $data['originatorName'] ?? null);

                self::syncDcnReviewers($dcnId, $reviewers);
                self::syncDcnApprovals($dcnId, $approvals);

                $currentOfficeId = RegisterQueryHelper::currentOfficeId();
                if ($currentOfficeId) {
                    DB::table('dcs_office_dcn_offices')->insert([
                        'office_intake_dcn_id' => $dcnId,
                        'office_id' => $currentOfficeId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return $dcnId;
            });
        } catch (\Throwable $e) {
            throw $e;
        }

        RegisterPersistHelper::logAdminChange(
            'Created office DCN #' . $id
            . ($docNo !== '' ? ' for ' . $docNo : '')
            . ($docTitle !== '' ? ': ' . $docTitle : '')
        );
        self::bumpOfficeInventoryCache();

        \App\Services\DcsAuditService::log(
            'office.dcn.create',
            'review_intake',
            null,
            null,
            ['dcn_id' => $id, 'doc_no' => $docNo, 'title' => $docTitle]
        );

        // A DCN that will include a DRF waits until that DRF is saved, then sends one combined notice.
        if (! $request->boolean('alsoCreateDrf')) {
            DcsNotificationService::notifyOfficeDcnSubmitted(
                RegisterQueryHelper::rfioNotificationOfficeCode(),
                RegisterQueryHelper::currentUserDisplayName(),
                '',
                $docNo,
                $id
            );
        }

        if ($request->boolean('alsoCreateDrf')) {
            return redirect()
                ->route('dcs.office.drf.create', ['from_dcn' => $id])
                ->with('success', 'Document Change Notice saved. Continue with a Document Request Form for this change.')
                ->with('locked', true);
        }

        return redirect()
            ->route('dcs.office.dcn.show', $id)
            ->with('success', 'Document Change Notice saved. This document cannot be edited.')
            ->with('locked', true);
    }

    /**
     * Saves changes to an office DRF while editing is still allowed.
     *
     * DEBUG: cannot save edits → canOfficeEditIntake / isIntakeRegistered.
     */
    public static function updateDrf(Request $request, int $id): RedirectResponse
    {
        self::assertCanAccessIntake();
        RegisterPersistHelper::blankStringsToNull($request);
        self::mergeJoinedOriginator($request);
        abort_unless(self::canOfficeEditIntake('drf', $id), 403, self::IMMUTABLE_MESSAGE);

        $rateCheck = \App\Services\RateLimiterService::check('dcs_create');
        if (! $rateCheck['allowed']) {
            return back()->withInput()->with('error', $rateCheck['message']);
        }

        self::mergeDistributeOfficeBackup($request);

        $data = $request->validate([
            'drfDate' => 'required|date',
            'drfTitle' => 'required|string',
            'originatorName' => 'required|string',
            'docTypeKind' => 'nullable|in:internal,external,forms,logbooks',
            'docTypeId' => 'nullable|integer',
            'subTypeId' => 'nullable|integer',
            'collegeId' => 'nullable|integer',
            'programId' => 'nullable|integer',
            'courseId' => 'nullable|integer',
            'semesterId' => 'nullable|integer',
            'schoolYearId' => 'nullable|integer',
            'descriptionReason' => 'required|string|max:5000',
            // Empty = office-only controlled document (Source Unit). Other offices only when they need a copy.
            'distributeToOffice' => 'nullable|array',
            'distributeToOffice.*' => 'integer',
            'preparedByName' => 'required|string|max:255',
            'preparedByDesignation' => 'required|string|max:255',
            'reviewedByName' => 'required|string|max:255',
            'reviewedByDesignation' => 'required|string|max:255',
            'approvedByName' => 'required|string|max:255',
            'approvedByDesignation' => 'required|string|max:255',
            'confirmDataCorrect' => 'accepted',
        ]);

        $postedKind = strtolower(trim((string) ($data['docTypeKind'] ?? '')));
        $data = self::resolveOfficeDrfTypeSelection($data);

        $catalog = self::resolveIntakeCatalogSelection(
            isset($data['docTypeId']) ? (int) $data['docTypeId'] : null,
            isset($data['subTypeId']) ? (int) $data['subTypeId'] : null
        );
        if (in_array($postedKind, ['forms', 'logbooks'], true)) {
            $data['docTypeKind'] = $postedKind;
        } elseif ($catalog['doc_type_id'] || $catalog['sub_type_id']) {
            $data['docTypeKind'] = $catalog['doc_type_kind'];
        } else {
            $data['docTypeKind'] = in_array($postedKind, ['internal', 'external'], true)
                ? $postedKind
                : 'internal';
        }
        $syllabiCtx = self::resolveOfficeDrfSyllabiContext(
            $data,
            ! empty($catalog['sub_type_id']) ? (int) $catalog['sub_type_id'] : null,
            $id
        );

        self::assertNoOpenDuplicateDrf(
            (string) ($data['drfTitle'] ?? ''),
            (string) ($data['originatorName'] ?? ''),
            $id
        );
        $existingDrf = self::findOfficeDrf($id);
        $existingPreviousNo = trim((string) ($existingDrf->previous_document_no ?? ''));
        if ($existingPreviousNo !== '' && self::findSourceDcnIdForOfficeDrf($id) === null) {
            self::assertNoOpenDuplicateRecurringDrf(
                $existingPreviousNo,
                trim((string) ($existingDrf->previous_document_title ?? $existingDrf->doc_title ?? '')),
                $id
            );
        }

        $now = now();
        DB::transaction(function () use ($data, $catalog, $id, $now, $syllabiCtx) {
            $row = [
                'drf_date' => $data['drfDate'] ?? null,
                'doc_title' => $data['drfTitle'],
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('dcs_office_intake_drf', 'originator_name')) {
                $row['originator_name'] = RegisterPersistHelper::joinedOriginatorNames($data['originatorName'] ?? null) ?: null;
            }
            if (Schema::hasColumn('dcs_office_intake_drf', 'doc_type_kind')) {
                $row['doc_type_kind'] = $data['docTypeKind'] ?? null;
            }
            if (Schema::hasColumn('dcs_office_intake_drf', 'doc_type_id')) {
                $row['doc_type_id'] = $catalog['doc_type_id'];
            }
            if (Schema::hasColumn('dcs_office_intake_drf', 'sub_type_id')) {
                $row['sub_type_id'] = $catalog['sub_type_id'];
            }
            self::applyOfficeDrfSyllabiColumns($row, $syllabiCtx);
            if (Schema::hasColumn('dcs_office_intake_drf', 'description_reason')) {
                $row['description_reason'] = trim((string) ($data['descriptionReason'] ?? '')) ?: null;
            }
            self::syncDistributeOffices($id, $data['distributeToOffice'] ?? []);
            if (Schema::hasColumn('dcs_office_intake_drf', 'prepared_by_name')) {
                $row['prepared_by_name'] = trim((string) ($data['preparedByName'] ?? '')) ?: null;
            }
            foreach ([
                'prepared_by_designation' => 'preparedByDesignation',
                'reviewed_by_name' => 'reviewedByName',
                'reviewed_by_designation' => 'reviewedByDesignation',
                'approved_by_name' => 'approvedByName',
                'approved_by_designation' => 'approvedByDesignation',
            ] as $column => $inputKey) {
                if (Schema::hasColumn('dcs_office_intake_drf', $column)) {
                    $row[$column] = trim((string) ($data[$inputKey] ?? '')) ?: null;
                }
            }
            if (Schema::hasColumn('dcs_office_intake_drf', 'edit_unlocked_at')) {
                $row['edit_unlocked_at'] = null;
                $row['edit_unlocked_by'] = null;
                $row['edit_unlock_reason'] = null;
            }
            if (Schema::hasColumn('dcs_office_intake_drf', 'rfio_received_at')) {
                $row['rfio_received_at'] = null;
                $row['rfio_received_by'] = null;
            }
            // Resubmit after correction must restart print/sign handoff on the new data.
            if (Schema::hasColumn('dcs_office_intake_drf', 'rfio_print_notified_at')) {
                $row['rfio_print_notified_at'] = null;
                $row['rfio_print_notified_by'] = null;
            }
            if (Schema::hasColumn('dcs_office_intake_drf', 'office_printed_at')) {
                $row['office_printed_at'] = null;
            }
            if (Schema::hasColumn('dcs_office_intake_drf', 'office_printed_by')) {
                $row['office_printed_by'] = null;
            }

            $affected = DB::table('dcs_office_intake_drf')
                ->where('id', $id)
                ->update($row);
            abort_if($affected < 1, 404, 'Document Request Form not found.');
            self::syncIntakeOriginators('dcs_office_drf_originators', 'office_intake_drf_id', $id, $data['originatorName'] ?? null);
        });

        DcsNotificationService::dismissOfficeIntakeNotifications('drf', $id);

        RegisterPersistHelper::logAdminChange(
            'Updated office DRF #' . $id . ': ' . (string) ($data['drfTitle'] ?? '')
        );
        self::bumpOfficeInventoryCache();
        \App\Services\DcsAuditService::log(
            'office.drf.update',
            'review_intake',
            null,
            null,
            ['drf_id' => $id, 'title' => (string) ($data['drfTitle'] ?? '')]
        );

        // Always notify Document Controllers / RFIU review queue.
        // Limited RFOIU staff also use office intake — do not skip when submitter office is RFIU/RFOIU.
        DcsNotificationService::notifyOfficeIntakeResubmitted(
            RegisterQueryHelper::rfioNotificationOfficeCode(),
            RegisterQueryHelper::currentUserDisplayName(),
            'drf',
            $id,
            (string) ($data['drfTitle'] ?? '')
        );

        return redirect()
            ->route('dcs.office.drf.show', $id)
            ->with('success', 'Document Request Form updated and resubmitted to RFIU. This document is locked again.')
            ->with('locked', true);
    }

    /**
     * Saves changes to an office DCN while editing is still allowed.
     *
     * DEBUG: cannot save DCN edits → canOfficeEditIntake / lock state.
     */
    public static function updateDcn(Request $request, int $id): RedirectResponse
    {
        self::assertCanAccessIntake();
        RegisterPersistHelper::blankStringsToNull($request);
        self::mergeJoinedOriginator($request);
        abort_unless(self::canOfficeEditIntake('dcn', $id), 403, self::IMMUTABLE_MESSAGE);

        $rateCheck = \App\Services\RateLimiterService::check('dcs_create');
        if (! $rateCheck['allowed']) {
            return back()->withInput()->with('error', $rateCheck['message']);
        }

        $data = $request->validate([
            'finderDocumentNo' => 'required|string|max:150',
            'finderCurrentTitle' => 'required|string',
            'finderOriginator' => 'required|string',
            'documentNoMode' => 'nullable|in:keep,new',
            'newDocumentNo' => 'nullable|string|max:150',
            'documentNo' => 'nullable|string|max:150',
            'documentTitle' => 'required|string',
            'changeFrom' => 'required|string|max:5000',
            'changeTo' => 'required|string|max:5000',
            'dcnJustification' => 'required|string|max:5000',
            'originatorName' => 'required|string',
            'departmentDate' => 'required|date',
            'reviewedByName' => 'required|array|min:1|max:9',
            'reviewedByName.*' => 'required|string|max:255',
            'approvalPosition' => 'nullable|array|max:9',
            'approvalPosition.*' => 'nullable|string|max:255',
            'approvalName' => 'nullable|array|max:9',
            'approvalName.*' => 'nullable|string|max:255',
            'confirmDataCorrect' => 'accepted',
        ], [
            'reviewedByName.*.required' => 'Each reviewer needs a name. Remove a reviewer you are not using.',
        ]);

        self::assertApprovalRowsComplete($request);

        $resolved = self::resolveOfficeDcnDocument($request);
        $docNo = $resolved['document_no'];
        $docTitle = $resolved['document_title'];
        $previousDocNo = $resolved['previous_document_no'];
        self::assertNoOpenDuplicateDcn(
            $previousDocNo,
            trim((string) $request->input('finderCurrentTitle', '')),
            $docTitle,
            [
                trim((string) $request->input('finderOriginator', '')),
                trim((string) ($data['originatorName'] ?? '')),
            ],
            $id
        );
        $reviewers = self::reviewersPayloadFromRequest($data);
        $approvals = self::approvalsPayloadFromRequest($data);
        $now = now();

        DB::transaction(function () use ($data, $id, $docNo, $docTitle, $previousDocNo, $reviewers, $approvals, $now) {
            $row = [
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('dcs_office_intake_dcn', 'brief_purpose')) {
                $row['brief_purpose'] = $data['dcnJustification'];
            }
            if (Schema::hasColumn('dcs_office_intake_dcn', 'document_no')) {
                $row['document_no'] = $docNo !== '' ? $docNo : null;
                $row['document_title'] = $docTitle !== '' ? $docTitle : null;
                $row['change_from'] = trim((string) ($data['changeFrom'] ?? '')) ?: null;
                $row['change_to'] = trim((string) ($data['changeTo'] ?? '')) ?: null;
                if (Schema::hasColumn('dcs_office_intake_dcn', 'originator_name')) {
                    $row['originator_name'] = RegisterPersistHelper::joinedOriginatorNames($data['originatorName'] ?? null) ?: null;
                }
                $row['department_on'] = $data['departmentDate'] ?? null;
            }
            if (Schema::hasColumn('dcs_office_intake_dcn', 'previous_document_no')) {
                $row['previous_document_no'] = $previousDocNo !== '' ? $previousDocNo : null;
            }
            if (Schema::hasColumn('dcs_office_intake_dcn', 'edit_unlocked_at')) {
                $row['edit_unlocked_at'] = null;
                $row['edit_unlocked_by'] = null;
                $row['edit_unlock_reason'] = null;
            }
            if (Schema::hasColumn('dcs_office_intake_dcn', 'rfio_received_at')) {
                $row['rfio_received_at'] = null;
                $row['rfio_received_by'] = null;
            }
            // Resubmit after correction must restart print/sign handoff on the new data.
            if (Schema::hasColumn('dcs_office_intake_dcn', 'rfio_print_notified_at')) {
                $row['rfio_print_notified_at'] = null;
                $row['rfio_print_notified_by'] = null;
            }
            if (Schema::hasColumn('dcs_office_intake_dcn', 'office_printed_at')) {
                $row['office_printed_at'] = null;
            }
            if (Schema::hasColumn('dcs_office_intake_dcn', 'office_printed_by')) {
                $row['office_printed_by'] = null;
            }

            $affected = DB::table('dcs_office_intake_dcn')
                ->where('id', $id)
                ->update($row);
            abort_if($affected < 1, 404, 'Document Change Notice not found.');
            self::syncIntakeOriginators('dcs_office_dcn_originators', 'office_intake_dcn_id', $id, $data['originatorName'] ?? null);

            self::syncDcnReviewers($id, $reviewers);
            self::syncDcnApprovals($id, $approvals);
        });

        DcsNotificationService::dismissOfficeIntakeNotifications('dcn', $id);

        // Always notify Document Controllers / RFIU review queue.
        // Limited RFOIU staff also use office intake — do not skip when submitter office is RFIU/RFOIU.
        RegisterPersistHelper::logAdminChange(
            'Updated office DCN #' . $id
            . ($docNo !== '' ? ' for ' . $docNo : '')
            . ($docTitle !== '' ? ': ' . $docTitle : '')
        );
        self::bumpOfficeInventoryCache();
        \App\Services\DcsAuditService::log(
            'office.dcn.update',
            'review_intake',
            null,
            null,
            ['dcn_id' => $id, 'doc_no' => $docNo, 'title' => $docTitle]
        );

        DcsNotificationService::notifyOfficeIntakeResubmitted(
            RegisterQueryHelper::rfioNotificationOfficeCode(),
            RegisterQueryHelper::currentUserDisplayName(),
            'dcn',
            $id,
            $docTitle !== '' ? $docTitle : $docNo
        );

        return redirect()
            ->route('dcs.office.dcn.show', $id)
            ->with('success', 'Document Change Notice updated and resubmitted to RFIU. This document is locked again.')
            ->with('locked', true);
    }


    /**
     * Builds or formats data for format reviewed by label.
     */
    private static function formatReviewedByLabel(?string $name, ?string $date = null): ?string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : null;
    }

    /**
     * Helper used for reviewers payload from request.
     * @param  array<string, mixed>  $data
     * @return list<array{name: string, date: string|null}>
     */
    // =====================================================================
    // SECTION: SIGNATORIES & APPROVALS — DCN reviewers / approval rows
    // =====================================================================
    
    public static function reviewersPayloadFromRequest(array $data): array
    {
        $names = $data['reviewedByName'] ?? [];
        if (! is_array($names)) {
            $names = [];
        }

        // Legacy single-field form names (reviewedByName / reviewedByName2).
        if ($names === [] && isset($data['reviewedByName']) && ! is_array($data['reviewedByName'] ?? null)) {
            $names = [
                (string) ($data['reviewedByName'] ?? ''),
                (string) ($data['reviewedByName2'] ?? ''),
            ];
        }

        $rows = [];
        foreach ($names as $rawName) {
            $name = trim((string) $rawName);
            if ($name === '') {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'date' => null,
            ];
            if (count($rows) >= 9) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Saves data for sync signatory template fields.
     *
     * DEBUG: save failures / wrong DB rows → step through this write path.
     * @param  array<string, mixed>  $payload
     */
    private static function syncSignatoryTemplateFields(int $templateId, array $payload): void
    {
        if ($templateId < 1 || ! Schema::hasTable('dcs_signatory_template_fields')) {
            return;
        }
        DB::table('dcs_signatory_template_fields')->where('template_id', $templateId)->delete();
        foreach (self::signatoryFieldRows($payload) as $field) {
            DB::table('dcs_signatory_template_fields')->insert([
                'template_id' => $templateId,
                'role_key' => $field['role_key'],
                'name' => $field['name'],
                'designation' => $field['designation'],
            ]);
        }
    }

    /**
     * Fills extra fields onto rows for the UI (attach signatory payloads).
     *
     * DEBUG: blank/missing fields on list/show → check this hydrator.
     * @param  iterable<int, object>  $rows
     */
    private static function attachSignatoryPayloads(iterable $rows): void
    {
        $list = collect($rows);
        if ($list->isEmpty()) {
            return;
        }
        if (! Schema::hasTable('dcs_signatory_template_fields')) {
            foreach ($list as $row) {
                if (! is_object($row)) {
                    continue;
                }
                $row->payload = self::decodeSignatoryPayload($row->payload ?? null);
            }

            return;
        }
        $ids = $list->map(fn ($row) => is_object($row) ? (int) ($row->id ?? 0) : 0)->filter()->values()->all();
        $grouped = [];
        if ($ids !== []) {
            foreach (DB::table('dcs_signatory_template_fields')->whereIn('template_id', $ids)->orderBy('id')->get() as $field) {
                $grouped[(int) $field->template_id][] = $field;
            }
        }
        foreach ($list as $row) {
            if (! is_object($row)) {
                continue;
            }
            $fields = $grouped[(int) ($row->id ?? 0)] ?? [];
            if ($fields !== []) {
                $row->payload = self::payloadFromFieldRows($fields);

                continue;
            }
            $decoded = self::decodeSignatoryPayload($row->payload ?? null);
            $row->payload = is_array($decoded) ? $decoded : [];
        }
    }

    /**
     * Helper used for signatory payload for row.
     */
    private static function signatoryPayloadForRow(object $row): ?array
    {
        $templateId = (int) ($row->id ?? 0);
        if ($templateId > 0 && Schema::hasTable('dcs_signatory_template_fields')) {
            $fields = DB::table('dcs_signatory_template_fields')->where('template_id', $templateId)->orderBy('id')->get();
            if ($fields->isNotEmpty()) {
                return self::payloadFromFieldRows($fields->all());
            }
        }

        return self::decodeSignatoryPayload($row->payload ?? null);
    }

    /**
     * Helper used for decode signatory payload.
     */
    private static function decodeSignatoryPayload(mixed $payload): ?array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (! is_string($payload) || trim($payload) === '') {
            return null;
        }
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Helper used for payload from field rows.
     * @param  list<object>  $fields
     * @return array<string, mixed>
     */
    private static function payloadFromFieldRows(array $fields): array
    {
        $byRole = [];
        foreach ($fields as $field) {
            $byRole[(string) ($field->role_key ?? '')] = $field;
        }
        $payload = [];
        foreach (['prepared_by', 'reviewed_by', 'approved_by'] as $role) {
            $field = $byRole[$role] ?? null;
            $payload[$role.'_name'] = trim((string) ($field->name ?? ''));
            $payload[$role.'_designation'] = trim((string) ($field->designation ?? ''));
        }
        $reviewers = [];
        $approvals = [];
        foreach ($byRole as $key => $field) {
            if (preg_match('/^reviewer_(\d+)$/', $key, $match)) {
                $reviewers[(int) $match[1]] = ['name' => trim((string) ($field->name ?? ''))];
            }
            if (preg_match('/^approval_(\d+)$/', $key, $match)) {
                $approvals[(int) $match[1]] = [
                    'position' => trim((string) ($field->designation ?? '')),
                    'name' => trim((string) ($field->name ?? '')),
                ];
            }
        }
        if ($reviewers !== []) {
            ksort($reviewers);
            $payload['reviewers'] = array_values($reviewers);
        }
        if ($approvals !== []) {
            ksort($approvals);
            $payload['approvals'] = array_values($approvals);
        }

        return $payload;
    }

    /**
     * Helper used for signatory field rows.
     * @param  array<string, mixed>  $payload
     * @return list<array{role_key: string, name: ?string, designation: ?string}>
     */
    private static function signatoryFieldRows(array $payload): array
    {
        $rows = [];
        $roles = [
            'prepared_by' => ['prepared_by_name', 'prepared_by_designation'],
            'reviewed_by' => ['reviewed_by_name', 'reviewed_by_designation'],
            'approved_by' => ['approved_by_name', 'approved_by_designation'],
        ];
        foreach ($roles as $role => [$nameKey, $designationKey]) {
            if (! array_key_exists($nameKey, $payload) && ! array_key_exists($designationKey, $payload)) {
                continue;
            }
            $name = trim((string) ($payload[$nameKey] ?? ''));
            $designation = trim((string) ($payload[$designationKey] ?? ''));
            $rows[] = [
                'role_key' => $role,
                'name' => $name !== '' ? $name : null,
                'designation' => $designation !== '' ? $designation : null,
            ];
        }
        $index = 1;
        foreach ((array) ($payload['reviewers'] ?? []) as $reviewer) {
            $name = trim((string) (is_array($reviewer) ? ($reviewer['name'] ?? '') : $reviewer));
            if ($name === '') {
                continue;
            }
            $rows[] = ['role_key' => 'reviewer_'.$index, 'name' => $name, 'designation' => null];
            $index++;
        }
        $index = 1;
        foreach ((array) ($payload['approvals'] ?? []) as $approval) {
            if (! is_array($approval)) {
                continue;
            }
            $name = trim((string) ($approval['name'] ?? ''));
            $position = trim((string) ($approval['position'] ?? ''));
            if ($name === '' && $position === '') {
                continue;
            }
            $rows[] = [
                'role_key' => 'approval_'.$index,
                'name' => $name !== '' ? $name : null,
                'designation' => $position !== '' ? $position : null,
            ];
            $index++;
        }

        return $rows;
    }

    /**
     * Active campus signatory assignment for a form + catalog type/subtype
     * (sub-type → parent whole type). For Syllabi/TOS, optional college_id scopes
     * the row (college-specific first, then college_id NULL default).
     * @return array<string, mixed>|null
     */
    public static function signatoryTemplate(
        string $formKind,
        string $docTypeKind = 'internal',
        ?int $docTypeId = null,
        ?string $onDate = null,
        bool $allowKindFallback = false,
        bool $allowParentFallback = true,
        ?int $collegeId = null
    ): ?array {
        $formKind = strtolower(trim($formKind));
        $docTypeKind = strtolower(trim($docTypeKind));
        if (! in_array($formKind, ['drf', 'dcn'], true)) {
            return null;
        }
        if (! Schema::hasTable('dcs_signatory_templates')) {
            return null;
        }

        // Catalog type is required — kind-only (null doc_type_id) is no longer used.
        if (! $docTypeId || $docTypeId < 1) {
            return null;
        }
        if (! Schema::hasColumn('dcs_signatory_templates', 'doc_type_id')) {
            return null;
        }

        $day = $onDate ? \Carbon\Carbon::parse($onDate)->toDateString() : now()->toDateString();
        $hasCollegeCol = Schema::hasColumn('dcs_signatory_templates', 'college_id');
        $collegeId = $collegeId && $collegeId > 0 ? $collegeId : null;

        $findRow = function (?int $scopedCollegeId) use ($formKind, $docTypeId, $day, $hasCollegeCol) {
            $query = DB::table('dcs_signatory_templates')
                ->where('form_kind', $formKind)
                ->where('doc_type_id', $docTypeId);

            if ($hasCollegeCol) {
                if ($scopedCollegeId !== null) {
                    $query->where('college_id', $scopedCollegeId);
        } else {
                    $query->whereNull('college_id');
                }
        }

        if (Schema::hasColumn('dcs_signatory_templates', 'effective_from')) {
            $query->where(function ($q) use ($day) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day);
            });
        }
        if (Schema::hasColumn('dcs_signatory_templates', 'effective_until')) {
            $query->where(function ($q) use ($day) {
                $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day);
            });
        }

            return $query->orderByDesc('id')->first();
        };

        $row = null;
        $matchedCollegeScope = null;
        if ($hasCollegeCol && $collegeId !== null) {
            $row = $findRow($collegeId);
            if ($row) {
                $matchedCollegeScope = $collegeId;
            }
        }
        if (! $row) {
            $row = $findRow(null);
            $matchedCollegeScope = null;
        }

        // Legacy: if college column exists but older rows mixed scopes without filtering null,
        // already handled by whereNull. If no college column, single find without college.
        if (! $hasCollegeCol && ! $row) {
            $row = $findRow(null);
        }

        if (! $row) {
            if ($allowParentFallback && Schema::hasTable('dcs_doc_types')) {
                $parentId = (int) DB::table('dcs_doc_types')->where('id', $docTypeId)->value('parent_id');
                if ($parentId > 0) {
                    $parentHit = self::signatoryTemplate(
                        $formKind,
                        $docTypeKind,
                        $parentId,
                        $onDate,
                        false,
                        false,
                        $collegeId
                    );
                    if ($parentHit) {
                        $parentHit['_meta'] = is_array($parentHit['_meta'] ?? null) ? $parentHit['_meta'] : [];
                        $parentHit['_meta']['inherited_from'] = 'parent';
                        $parentHit['_meta']['inherited_doc_type_id'] = $parentId;

                        return $parentHit;
                    }
                }
            }

            // $allowKindFallback kept for call-site compatibility; intentionally unused.
            unset($allowKindFallback);

            return null;
        }

        $payload = self::signatoryPayloadForRow($row);
        if (! is_array($payload)) {
            return null;
        }

        $payload['_meta'] = [
            'id' => (int) data_get($row, 'id', 0),
            'doc_type_id' => ($id = data_get($row, 'doc_type_id')) ? (int) $id : null,
            'college_id' => $hasCollegeCol
                ? (($cid = data_get($row, 'college_id')) ? (int) $cid : null)
                : $matchedCollegeScope,
            'doc_type_kind' => data_get($row, 'doc_type_kind', $docTypeKind),
            'effective_from' => data_get($row, 'effective_from'),
            'effective_until' => data_get($row, 'effective_until'),
        ];

        return $payload;
    }

    /**
     * Create a dated assignment window. Closes any open overlapping assignment for the same scope
     * (form_kind + doc_type_id + college_id).
     *
     * DEBUG: save failures / wrong DB rows → step through this write path.
     * @param  array<string, mixed>  $payload
     */
    public static function saveSignatoryTemplate(
        string $formKind,
        string $docTypeKind,
        array $payload,
        ?int $updatedBy = null,
        ?int $docTypeId = null,
        ?string $effectiveFrom = null,
        ?string $effectiveUntil = null,
        ?int $collegeId = null
    ): void {
        $formKind = strtolower(trim($formKind));
        $docTypeKind = strtolower(trim($docTypeKind ?: 'internal'));
        if (! in_array($formKind, ['drf', 'dcn'], true) || ! in_array($docTypeKind, ['internal', 'external'], true)) {
            throw new \InvalidArgumentException('Invalid signatory template key.');
        }
        if (! $docTypeId || $docTypeId < 1) {
            throw new \InvalidArgumentException('Select a Document type from the catalog.');
        }
        if (! Schema::hasTable('dcs_signatory_templates')) {
            throw new \RuntimeException('Signatory templates table is not ready. Run migrations.');
        }
        if (! Schema::hasColumn('dcs_signatory_templates', 'doc_type_id')) {
            throw new \RuntimeException('Signatory templates need a doc_type_id column. Run migrations.');
        }

        $collegeId = $collegeId && $collegeId > 0 ? $collegeId : null;
        if ($collegeId !== null && Schema::hasTable('dcs_colleges')
            && ! DB::table('dcs_colleges')->where('id', $collegeId)->exists()
        ) {
            throw new \InvalidArgumentException('Select a valid college.');
        }

        $now = now();
        $from = $effectiveFrom ? \Carbon\Carbon::parse($effectiveFrom)->toDateString() : $now->toDateString();
        $until = $effectiveUntil ? \Carbon\Carbon::parse($effectiveUntil)->toDateString() : null;
        if ($until && $until < $from) {
            throw new \InvalidArgumentException('Effective until must be on or after effective from.');
        }

        $data = [
            'form_kind' => $formKind,
            'doc_type_kind' => $docTypeKind,
            'doc_type_id' => $docTypeId,
            'updated_by' => $updatedBy,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('dcs_signatory_templates', 'payload')) {
            $data['payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        if (Schema::hasColumn('dcs_signatory_templates', 'college_id')) {
            $data['college_id'] = $collegeId;
        }
        if (Schema::hasColumn('dcs_signatory_templates', 'effective_from')) {
            $data['effective_from'] = $from;
        }
        if (Schema::hasColumn('dcs_signatory_templates', 'effective_until')) {
            $data['effective_until'] = $until;
        }

        if (Schema::hasColumn('dcs_signatory_templates', 'effective_until')) {
            $close = DB::table('dcs_signatory_templates')
                ->where('form_kind', $formKind)
                ->where('doc_type_id', $docTypeId)
                ->where(function ($q) use ($from) {
                    $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $from);
                });
            if (Schema::hasColumn('dcs_signatory_templates', 'college_id')) {
                if ($collegeId !== null) {
                    $close->where('college_id', $collegeId);
            } else {
                    $close->whereNull('college_id');
                }
            }
            $close->update([
                'effective_until' => \Carbon\Carbon::parse($from)->subDay()->toDateString(),
                'updated_at' => $now,
                'updated_by' => $updatedBy,
            ]);
        }

        $data['created_at'] = $now;
        $templateId = (int) DB::table('dcs_signatory_templates')->insertGetId($data);
        self::syncSignatoryTemplateFields($templateId, $payload);
    }

    /**
     * Lists or paginates data for list signatory assignments.
     * @return \Illuminate\Support\Collection<int, object>
     */
    /** @var array<string, \Illuminate\Support\Collection<int, object>> */
    private static array $signatoryAssignmentsMemo = [];

    public static function listSignatoryAssignments(string $formKind = 'drf'): \Illuminate\Support\Collection
    {
        $memoKey = strtolower(trim($formKind));
        if ($memoKey === '') {
            $memoKey = 'drf';
        }
        if (isset(self::$signatoryAssignmentsMemo[$memoKey])) {
            return self::$signatoryAssignmentsMemo[$memoKey];
        }

        if (! Schema::hasTable('dcs_signatory_templates')) {
            return self::$signatoryAssignmentsMemo[$memoKey] = collect();
        }

        $query = DB::table('dcs_signatory_templates as t')
            ->where('t.form_kind', $memoKey)
            ->orderByDesc('t.id');

        if (Schema::hasColumn('dcs_signatory_templates', 'doc_type_id') && Schema::hasTable('dcs_doc_types')) {
            $query->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 't.doc_type_id');
            $select = [
                    't.*',
                    'dt.doc_type_name',
                    'dt.parent_id as doc_type_parent_id',
            ];
            if (Schema::hasColumn('dcs_signatory_templates', 'college_id') && Schema::hasTable('dcs_colleges')) {
                $query->leftJoin('dcs_colleges as col', 'col.id', '=', 't.college_id');
                $select[] = 'col.college_name';
                $select[] = 'col.college_code';
            }
            $query->select($select);
        } else {
            $query->select('t.*');
        }

        $rows = $query->limit(200)->get();
        self::attachSignatoryPayloads($rows);

        return self::$signatoryAssignmentsMemo[$memoKey] = $rows;
    }

    /**
     * Classify a signatory window relative to $today (Y-m-d).
     */
    public static function signatoryAssignmentStatus(object $row, ?string $today = null): string
    {
        $today = $today ?: now()->toDateString();
        $fromRaw = data_get($row, 'effective_from');
        $untilRaw = data_get($row, 'effective_until');
        $from = $fromRaw ? \Carbon\Carbon::parse($fromRaw)->toDateString() : null;
        $until = $untilRaw ? \Carbon\Carbon::parse($untilRaw)->toDateString() : null;

        if ($from && $from > $today) {
            return 'scheduled';
        }
        if (($from === null || $from <= $today) && ($until === null || $until >= $today)) {
            return 'active';
        }

        return 'ended';
    }

    /**
     * Readable assignment window, e.g. "Oct 27, 2026 – present" or "Jan 5, 2025 – Feb 23, 2025".
     */
    public static function formatSignatoryDateRange(mixed $fromRaw, mixed $untilRaw): string
    {
        $from = self::formatSignatoryDate($fromRaw);
        if ($untilRaw === null || $untilRaw === '') {
            return ($from !== '' ? $from : '—').' – present';
        }

        $until = self::formatSignatoryDate($untilRaw);

        return ($from !== '' ? $from : '—').' – '.($until !== '' ? $until : '—');
    }

    /**
     * Builds or formats data for format signatory date.
     */
    public static function formatSignatoryDate(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        try {
            return \Carbon\Carbon::parse($raw)->format('M j, Y');
        } catch (\Throwable) {
            return trim((string) $raw);
        }
    }

    /**
     * Helper used for signatory assignment status counts.
     * @return array{active: int, scheduled: int, ended: int, total: int}
     */
    public static function signatoryAssignmentStatusCounts(string $formKind = 'drf'): array
    {
        $today = now()->toDateString();
        $counts = ['active' => 0, 'scheduled' => 0, 'ended' => 0, 'total' => 0];
        foreach (self::listSignatoryAssignments($formKind) as $row) {
            $status = self::signatoryAssignmentStatus($row, $today);
            $counts[$status]++;
            $counts['total']++;
        }

        return $counts;
    }

    /**
     * Nest assignments by catalog parent → subtype (or Kind fallback) for Settings history.
     * @param  string|null  $parentKeyFilter  e.g. parent:12, kind, or null/all for every type
     * @return list<array{key: string, label: string, children: list<array{key: string, label: string, items: \Illuminate\Support\Collection<int, object>}>}>
     */
    public static function groupedSignatoryAssignments(string $formKind = 'drf', ?string $parentKeyFilter = null): array
    {
        $rows = self::listSignatoryAssignments($formKind);
        if ($rows->isEmpty()) {
            return [];
        }

        $parentNameById = collect();
        if (Schema::hasTable('dcs_doc_types')) {
            $parentIds = $rows->pluck('doc_type_parent_id')->filter(fn ($id) => (int) $id > 0)->unique()->values();
            $orphanParentIds = $rows
                ->filter(fn ($row) => (int) data_get($row, 'doc_type_id', 0) > 0 && ! (int) data_get($row, 'doc_type_parent_id', 0))
                ->pluck('doc_type_id')
                ->unique();
            $ids = $parentIds->merge($orphanParentIds)->unique()->filter()->values()->all();
            if ($ids !== []) {
                $parentNameById = DB::table('dcs_doc_types')
                    ->whereIn('id', $ids)
                    ->get(['id', 'doc_type_name'])
                    ->keyBy('id');
            }
        }

        $parents = [];
        foreach ($rows as $row) {
            $docTypeId = (int) data_get($row, 'doc_type_id', 0);
            $parentId = (int) data_get($row, 'doc_type_parent_id', 0);
            $typeName = trim((string) data_get($row, 'doc_type_name', ''));
            $kind = strtolower(trim((string) data_get($row, 'doc_type_kind', '')));

            if ($docTypeId < 1) {
                // Old rows saved without a catalog type — read-only history.
                $parentKey = 'kind';
                $parentLabel = 'Legacy (no catalog type)';
                $childKey = 'kind:'.($kind !== '' ? $kind : 'unknown');
                $childLabel = $kind !== '' ? ucfirst($kind).' (legacy)' : 'Unspecified (legacy)';
            } elseif ($parentId > 0) {
                $parent = $parentNameById->get($parentId);
                $parentKey = 'parent:'.$parentId;
                $parentLabel = $parent ? trim((string) $parent->doc_type_name) : ('Type #'.$parentId);
                $collegeId = (int) data_get($row, 'college_id', 0);
                $collegeName = trim((string) data_get($row, 'college_name', ''));
                $isSyllabiLike = RegisterQueryHelper::isSyllabiLikeName($typeName);
                if ($isSyllabiLike || $collegeId > 0) {
                    $childKey = 'sub:'.$docTypeId.':c:'.($collegeId > 0 ? $collegeId : 'all');
                    $base = $typeName !== '' ? $typeName : ('Subtype #'.$docTypeId);
                    $childLabel = $collegeId > 0
                        ? ($base.' · '.($collegeName !== '' ? $collegeName : ('College #'.$collegeId)))
                        : ($base.' · All campuses');
                } else {
                $childKey = 'sub:'.$docTypeId;
                $childLabel = $typeName !== '' ? $typeName : ('Subtype #'.$docTypeId);
                }
            } else {
                $parentKey = 'parent:'.$docTypeId;
                $parentLabel = $typeName !== '' ? $typeName : ('Type #'.$docTypeId);
                $childKey = 'whole';
                $childLabel = 'Whole catalog type';
            }

            if (! isset($parents[$parentKey])) {
                $parents[$parentKey] = [
                    'key' => $parentKey,
                    'label' => $parentLabel,
                    'sort' => mb_strtolower($parentLabel),
                    'children' => [],
                ];
            }
            if (! isset($parents[$parentKey]['children'][$childKey])) {
                $parents[$parentKey]['children'][$childKey] = [
                    'key' => $childKey,
                    'label' => $childLabel,
                    'sort' => mb_strtolower($childLabel),
                    'items' => collect(),
                ];
            }
            $parents[$parentKey]['children'][$childKey]['items']->push($row);
        }

        $grouped = collect($parents)
            ->sortBy('sort')
            ->map(function (array $parent) {
                $children = collect($parent['children'])
                    ->sortBy('sort')
                    ->map(fn (array $child) => [
                        'key' => $child['key'],
                        'label' => $child['label'],
                        'items' => $child['items'],
                    ])
                    ->values()
                    ->all();

                return [
                    'key' => $parent['key'],
                    'label' => $parent['label'],
                    'children' => $children,
                ];
            })
            ->values();

        if ($parentKeyFilter && $parentKeyFilter !== 'all') {
            $grouped = $grouped->filter(fn (array $parent) => $parent['key'] === $parentKeyFilter)->values();
        }

        return $grouped->all();
    }

    /**
     * Document-type tabs for Settings recent signatories (catalog parents + kind fallback if used).
     * @param  \Illuminate\Support\Collection<int, object>|iterable<int, object>  $docTypeParents
     * @return list<array{key: string, label: string, count: int}>
     */
    public static function signatoryHistoryDocTypeTabs(string $formKind, $docTypeParents): array
    {
        $groups = self::groupedSignatoryAssignments($formKind);
        $countByKey = [];
        foreach ($groups as $group) {
            $count = 0;
            foreach ($group['children'] as $child) {
                $count += $child['items']->count();
            }
            $countByKey[$group['key']] = $count;
        }

        $tabs = [
            [
                'key' => 'all',
                'label' => 'All',
                'count' => array_sum($countByKey),
            ],
        ];

        foreach ($docTypeParents as $type) {
            $id = (int) ($type->id ?? 0);
            if ($id < 1) {
                continue;
            }
            $name = trim((string) ($type->doc_type_name ?? ''));
            $key = 'parent:'.$id;
            $count = (int) ($countByKey[$key] ?? 0);
            // Forms / Logbooks stay off the tabs until admin enables them for office DRF.
            if (self::isDefaultExcludedDrfParent($name) && ! self::isOfficeDrfParentEnabled($name) && $count < 1) {
                continue;
            }
            $tabs[] = [
                'key' => $key,
                'label' => $name !== '' ? $name : ('Type #'.$id),
                'count' => $count,
            ];
            unset($countByKey[$key]);
        }

        if (isset($countByKey['kind']) && (int) $countByKey['kind'] > 0) {
            $tabs[] = [
                'key' => 'kind',
                'label' => 'Legacy',
                'count' => (int) $countByKey['kind'],
            ];
        }

        return $tabs;
    }

    /**
     * Whether an open (active) assignment already exists for this exact form + catalog + college scope
     * (no parent / kind inheritance — used by Settings editor banners).
     *
     * DEBUG: unexpected allow/deny → check this predicate.
     */
    public static function hasActiveSignatoryAssignment(
        string $formKind,
        string $docTypeKind = 'internal',
        ?int $docTypeId = null,
        ?int $collegeId = null
    ): bool {
        $hit = self::signatoryTemplate($formKind, $docTypeKind, $docTypeId, null, false, false, $collegeId);

        // Exact scope only: if college was requested, require the hit's college_id to match
        // (signatoryTemplate falls back to NULL college — treat that as not "exact" for the banner).
        if (! is_array($hit)) {
            return false;
        }
        if ($collegeId && $collegeId > 0) {
            $hitCollege = (int) data_get($hit, '_meta.college_id', 0);

            return $hitCollege === $collegeId;
        }

        // Non-college scope: active only when the row itself has no college.
        $hitCollege = (int) data_get($hit, '_meta.college_id', 0);

        return $hitCollege < 1;
    }

    /**
     * Human label for what office intake will use when this exact scope has no assignment.
     */
    public static function signatoryInheritanceHint(
        string $formKind,
        string $docTypeKind = 'internal',
        ?int $docTypeId = null,
        ?int $collegeId = null
    ): ?string {
        if (! $docTypeId || $docTypeId < 1) {
            return null;
        }
        if (self::hasActiveSignatoryAssignment($formKind, $docTypeKind, $docTypeId, $collegeId)) {
            return null;
        }

        $hit = self::signatoryTemplate($formKind, $docTypeKind, $docTypeId, null, false, true, $collegeId);
        if (! is_array($hit)) {
            return 'No assignment for this type/sub-type or its parent — office intake will leave signatories blank.';
        }

        $from = (string) data_get($hit, '_meta.inherited_from', '');
        if ($from === 'parent') {
            $parentId = (int) data_get($hit, '_meta.inherited_doc_type_id', 0);
            $parentName = $parentId > 0 && Schema::hasTable('dcs_doc_types')
                ? trim((string) DB::table('dcs_doc_types')->where('id', $parentId)->value('doc_type_name'))
                : '';
            $label = $parentName !== '' ? $parentName : 'the parent document type';

            return 'No assignment for this sub-type. Office intake will use the whole '.$label.' assignment.';
        }

        $hitCollege = (int) data_get($hit, '_meta.college_id', 0);
        if ($collegeId && $collegeId > 0 && $hitCollege < 1) {
            return 'No college-specific assignment. Office intake will use the All campuses default for this type.';
        }

        return null;
    }

    /**
     * Helper used for catalog parent id for kind.
     */
    public static function catalogParentIdForKind(string $kind): ?int
    {
        $kind = strtolower(trim($kind));
        if (! in_array($kind, ['internal', 'external'], true) || ! Schema::hasTable('dcs_doc_types')) {
            return null;
        }
        $q = DB::table('dcs_doc_types')->whereNull('parent_id');
        SettingsRecycleHelper::applyNotDeleted($q, 'dcs_doc_types');
        $id = (int) ($q->whereRaw('LOWER(TRIM(doc_type_name)) = ?', [$kind])->value('id') ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Default-excluded DRF parents (Forms / Logbooks) until admin enables them
     * under Settings → Signatories (Enable for office DRF).
     *
     * DEBUG: unexpected allow/deny → check this predicate.
     */
    public static function isDefaultExcludedDrfParent(string $name): bool
    {
        $n = mb_strtolower(trim($name));

        return in_array($n, ['forms', 'logbooks', 'logbook'], true);
    }

    /**
     * Helper used for office drf parent slug.
     */
    public static function officeDrfParentSlug(string $name): ?string
    {
        $n = mb_strtolower(trim($name));
        if ($n === 'forms') {
            return 'forms';
        }
        if (in_array($n, ['logbooks', 'logbook'], true)) {
            return 'logbooks';
        }

        return null;
    }

    /**
     * Helper used for office drf enable setting key.
     */
    public static function officeDrfEnableSettingKey(string $name): ?string
    {
        $slug = self::officeDrfParentSlug($name);

        return $slug !== null ? 'dcs_office_drf_'.$slug.'_enabled' : null;
    }

    /**
     * Helper used for system settings table.
     */
    private static function systemSettingsTable(): ?string
    {
        if (Schema::hasTable('sys_system_settings')) {
            return 'sys_system_settings';
        }
        if (Schema::hasTable('system_settings')) {
            return 'system_settings';
        }

        return null;
    }

    /**
     * Tells whether is office drf parent enabled — used for access and UI decisions.
     *
     * DEBUG: unexpected allow/deny → check this predicate.
     */
    public static function isOfficeDrfParentEnabled(string $name): bool
    {
        $key = self::officeDrfEnableSettingKey($name);
        $table = self::systemSettingsTable();
        if ($key === null || $table === null) {
            return false;
        }

        return DB::table($table)->where('key', $key)->value('value') === 'true';
    }

    /**
     * Helper used for set office drf parent enabled.
     */
    public static function setOfficeDrfParentEnabled(string $name, bool $enabled): bool
    {
        $key = self::officeDrfEnableSettingKey($name);
        $table = self::systemSettingsTable();
        if ($key === null || $table === null) {
            return false;
        }

        $payload = [
            'key' => $key,
            'value' => $enabled ? 'true' : 'false',
            'updated_at' => now(),
        ];
        if (Schema::hasColumn($table, 'created_at')) {
            $exists = DB::table($table)->where('key', $key)->exists();
            if (! $exists) {
                $payload['created_at'] = now();
            }
        }
        DB::table($table)->updateOrInsert(['key' => $key], $payload);

        return true;
    }

    /**
     * Shown for DRF intake / Settings DRF catalog: normal parents, plus Forms/Logbooks
     * only after admin enables them in Admin Console → System Settings.
     *
     * DEBUG: unexpected allow/deny → check this predicate.
     */
    public static function isDrfEligibleDocTypeParent(string $name, ?int $parentId = null): bool
    {
        if (! self::isDefaultExcludedDrfParent($name)) {
            return true;
        }

        return self::isOfficeDrfParentEnabled($name);
    }

    /**
     * Catalog parent id for Forms / Logbooks (exact name match, case-insensitive).
     */
    public static function findOptInDrfParentId(string $slugOrName): ?int
    {
        if (! Schema::hasTable('dcs_doc_types')) {
            return null;
        }
        $slug = self::officeDrfParentSlug($slugOrName);
        if ($slug === null) {
            return null;
        }
        $names = $slug === 'forms' ? ['Forms'] : ['Logbooks', 'Logbook'];
        $q = DB::table('dcs_doc_types')->whereNull('parent_id')->where(function ($query) use ($names) {
            foreach ($names as $i => $name) {
                $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
                $query->{$method}('LOWER(TRIM(doc_type_name)) = ?', [mb_strtolower($name)]);
            }
        });
        SettingsRecycleHelper::applyNotDeleted($q, 'dcs_doc_types');
        $id = (int) ($q->value('id') ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Enabled Forms / Logbooks options for office DRF “Type of document” radios.
     * @return list<array{slug: string, label: string, id: int}>
     */
    public static function enabledOfficeDrfTypeRadios(): array
    {
        $out = [];
        foreach (['Forms', 'Logbooks'] as $label) {
            if (! self::isOfficeDrfParentEnabled($label)) {
                continue;
            }
            $id = self::findOptInDrfParentId($label);
            if (! $id) {
                continue;
            }
            $slug = self::officeDrfParentSlug($label);
            if ($slug === null) {
                continue;
            }
            $out[] = [
                'slug' => $slug,
                'label' => $label === 'Logbooks' ? 'Logbooks' : 'Forms',
                'id' => $id,
            ];
        }

        return $out;
    }

    /**
     * Normalize posted Type of document: forms/logbooks → parent id + keep kind slug.
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function resolveOfficeDrfTypeSelection(array $data): array
    {
        $kind = strtolower(trim((string) ($data['docTypeKind'] ?? '')));
        if (! in_array($kind, ['forms', 'logbooks'], true)) {
            return $data;
        }
        $label = $kind === 'forms' ? 'Forms' : 'Logbooks';
        if (! self::isOfficeDrfParentEnabled($label)) {
            throw ValidationException::withMessages([
                'docTypeKind' => $label.' is not enabled for office DRF.',
            ]);
        }
        $parentId = self::findOptInDrfParentId($kind);
        if (! $parentId) {
            throw ValidationException::withMessages([
                'docTypeKind' => $label.' is not configured in Document Types.',
            ]);
        }
        $data['docTypeKind'] = $kind;
        $data['docTypeId'] = $parentId;
        $data['subTypeId'] = null;

        return $data;
    }

    /**
     * Active Document Types tree for office intake / Settings DRF catalog pickers.
     * @param  'all'|'internal'|'external'|null  $kindFilter  When set, only parents matching that kind.
     * @return array{parents: list<object>, childrenByParent: array<string, list<object>>, parentKind: array<string, string>}
     */
    public static function intakeDocTypeCatalog(?string $kindFilter = null, bool $drfEligibleOnly = true): array
    {
        if (! Schema::hasTable('dcs_doc_types')) {
            return ['parents' => [], 'childrenByParent' => [], 'parentKind' => []];
        }

        $parentsQ = DB::table('dcs_doc_types')->whereNull('parent_id')->orderBy('doc_type_name');
        SettingsRecycleHelper::applyNotDeleted($parentsQ, 'dcs_doc_types');
        $parents = $parentsQ->get(['id', 'doc_type_name']);

        $subsQ = DB::table('dcs_doc_types')->whereNotNull('parent_id')->orderBy('doc_type_name');
        SettingsRecycleHelper::applyNotDeleted($subsQ, 'dcs_doc_types');
        $subs = $subsQ->get(['id', 'doc_type_name', 'parent_id']);

        $parentKind = [];
        $filteredParents = [];
        foreach ($parents as $parent) {
            $name = trim((string) ($parent->doc_type_name ?? ''));
            $pid = (int) ($parent->id ?? 0);
            if ($drfEligibleOnly && ! self::isDrfEligibleDocTypeParent($name, $pid > 0 ? $pid : null)) {
                continue;
            }
            $kind = str_contains(mb_strtolower($name), 'external') ? 'external' : 'internal';
            $parentKind[(string) (int) $parent->id] = $kind;
            if ($kindFilter === 'internal' || $kindFilter === 'external') {
                if ($kind !== $kindFilter) {
                    continue;
                }
            }
            $filteredParents[] = $parent;
        }

        $allowedParentIds = collect($filteredParents)->map(fn ($p) => (int) $p->id)->all();
        $childrenByParent = [];
        foreach ($subs as $sub) {
            $pid = (int) ($sub->parent_id ?? 0);
            if ($pid < 1 || ! in_array($pid, $allowedParentIds, true)) {
                continue;
            }
            $key = (string) $pid;
            $childrenByParent[$key] ??= [];
            $childrenByParent[$key][] = $sub;
            $parentName = '';
            foreach ($filteredParents as $p) {
                if ((int) $p->id === $pid) {
                    $parentName = trim((string) ($p->doc_type_name ?? ''));
                    break;
                }
            }
            $subName = trim((string) ($sub->doc_type_name ?? ''));
            $parentKind[(string) (int) $sub->id] = str_contains(mb_strtolower($parentName.' '.$subName), 'external')
                ? 'external'
                : 'internal';
        }

        return [
            'parents' => $filteredParents,
            'childrenByParent' => $childrenByParent,
            'parentKind' => $parentKind,
        ];
    }

    /**
     * Resolve kind + normalized parent/sub ids from catalog selection.
     * @return array{doc_type_id: ?int, sub_type_id: ?int, doc_type_kind: string}
     */
    public static function resolveIntakeCatalogSelection(?int $docTypeId, ?int $subTypeId): array
    {
        $parentId = $docTypeId && $docTypeId > 0 ? $docTypeId : null;
        $childId = $subTypeId && $subTypeId > 0 ? $subTypeId : null;
        $kind = 'internal';

        if (! Schema::hasTable('dcs_doc_types')) {
            return ['doc_type_id' => $parentId, 'sub_type_id' => $childId, 'doc_type_kind' => $kind];
        }

        $label = '';
        if ($childId) {
            $child = DB::table('dcs_doc_types')->where('id', $childId)->first(['id', 'doc_type_name', 'parent_id']);
            if ($child) {
                $parentId = (int) ($child->parent_id ?? 0) ?: $parentId;
                $label = trim((string) ($child->doc_type_name ?? ''));
            } else {
                $childId = null;
            }
        }
        if ($parentId) {
            $parent = DB::table('dcs_doc_types')->where('id', $parentId)->whereNull('parent_id')->first(['id', 'doc_type_name']);
            if ($parent) {
                $label = trim((string) ($parent->doc_type_name ?? '').' '.$label);
            } else {
                $parentId = null;
                $childId = null;
            }
        }

        if (str_contains(mb_strtolower($label), 'external')) {
            $kind = 'external';
        }

        return [
            'doc_type_id' => $parentId,
            'sub_type_id' => $childId,
            'doc_type_kind' => $kind,
        ];
    }

    /**
     * Tells whether is office drf syllabi like — used for access and UI decisions.
     *
     * DEBUG: unexpected allow/deny → check this predicate.
     */
    public static function isOfficeDrfSyllabiLike(?int $subTypeId): bool
    {
        if (! $subTypeId || $subTypeId < 1 || ! Schema::hasTable('dcs_doc_types')) {
            return false;
        }
        $name = DB::table('dcs_doc_types')->where('id', $subTypeId)->value('doc_type_name');

        return RegisterQueryHelper::isSyllabiLikeName($name !== null ? (string) $name : null);
    }

    /**
     * Compact catalog for office DRF syllabi context pickers.
     *
     * @return array{
     *     colleges: list<array{college_id:int,college_name:string,college_code:string}>,
     *     programsByCollege: array<string, list<array{program_id:int,program_name:string,program_code:string}>>,
     *     coursesByProgramSemester: array<string, list<array{id:int,course_name:string,course_code:string,year_level:string,course_type:string}>>,
     *     semesters: list<array{semester_id:int,semester_name:string}>,
     *     schoolYears: list<array{school_year_id:int,school_year:string}>
     * }
     */
    /**
     * School years reserved by another *open* office DRF for the same course + sub-type.
     *
     * Registered syllabi packs are NOT treated as taken — a second faculty can still
     * submit a DRF for that course/S/Y; RFIU Proceed opens the existing registration
     * (edit + intake stamp) instead of creating a new pack.
     *
     * Key: "{college_id}:{program_id}:{semester_id}:{course_id}:{sub_type_id}" → list of school_year_id.
     * program_id 0 = institutional GE/PE/NSTP.
     *
     * @return array<string, list<int>>
     */
    public static function officeDrfSyllabiTakenSchoolYearsByCourse(?int $exceptDrfId = null): array
    {
        $taken = [];

        $push = static function (array &$map, $collegeId, $programId, $semesterId, $courseId, $schoolYearId, $subTypeId): void {
            $collegeId = (int) $collegeId;
            $programId = (int) ($programId ?? 0);
            $semesterId = (int) $semesterId;
            $courseId = (int) $courseId;
            $schoolYearId = (int) $schoolYearId;
            $subTypeId = (int) $subTypeId;
            // program_id 0 = institutional GE/PE/NSTP (no program).
            if ($collegeId < 1 || $programId < 0 || $semesterId < 1 || $courseId < 1 || $schoolYearId < 1 || $subTypeId < 1) {
                return;
            }
            $key = $collegeId . ':' . $programId . ':' . $semesterId . ':' . $courseId . ':' . $subTypeId;
            if (! isset($map[$key])) {
                $map[$key] = [];
            }
            if (! in_array($schoolYearId, $map[$key], true)) {
                $map[$key][] = $schoolYearId;
            }
        };

        // Only open (unregistered) office DRFs reserve a course + school year + sub-type.
        // Already-controlled packs stay available so another faculty can request a merge stamp.
        if (
            Schema::hasTable('dcs_office_intake_drf')
            && Schema::hasColumn('dcs_office_intake_drf', 'course_id')
            && Schema::hasColumn('dcs_office_intake_drf', 'school_year_id')
        ) {
            $intake = self::openIntakeScope('dcs_office_intake_drf')
                ->whereNotNull('course_id')
                ->whereNotNull('school_year_id');
            if (Schema::hasColumn('dcs_office_intake_drf', 'sub_type_id')) {
                $intake->whereNotNull('sub_type_id');
            }
            if ($exceptDrfId && $exceptDrfId > 0) {
                $intake->where('id', '!=', $exceptDrfId);
            }
            $cols = ['college_id', 'program_id', 'semester_id', 'course_id', 'school_year_id'];
            if (Schema::hasColumn('dcs_office_intake_drf', 'sub_type_id')) {
                $cols[] = 'sub_type_id';
            }
            foreach ($intake->get($cols) as $row) {
                $push(
                    $taken,
                    $row->college_id ?? 0,
                    $row->program_id ?? 0,
                    $row->semester_id ?? 0,
                    $row->course_id ?? 0,
                    $row->school_year_id ?? 0,
                    $row->sub_type_id ?? 0
                );
            }
        }

        return $taken;
    }

    /**
     * Helper used for office drf syllabi catalog.
     */
    public static function officeDrfSyllabiCatalog(?int $exceptDrfId = null): array
    {
        $full = RegisterQueryHelper::jsCatalog();
        $courses = [];
        foreach (($full['coursesByProgramSemester'] ?? []) as $key => $rows) {
            $filtered = [];
            foreach ((array) $rows as $row) {
                $year = trim((string) ($row['year_level'] ?? ''));
                $type = trim((string) ($row['course_type'] ?? ''));
                if ($year === '' || $type === '') {
                    continue;
                }
                $filtered[] = $row;
            }
            if ($filtered !== []) {
                $courses[$key] = $filtered;
            }
        }
        $shared = [];
        foreach (($full['coursesBySharedSemester'] ?? []) as $key => $rows) {
            $filtered = [];
            foreach ((array) $rows as $row) {
                $year = trim((string) ($row['year_level'] ?? ''));
                $type = trim((string) ($row['course_type'] ?? ''));
                if ($year === '' || $type === '') {
                    continue;
                }
                $filtered[] = $row;
            }
            if ($filtered !== []) {
                $shared[$key] = $filtered;
            }
        }

        return [
            'colleges' => $full['colleges'] ?? [],
            'programsByCollege' => $full['programsByCollege'] ?? [],
            'coursesByProgramSemester' => $courses,
            'coursesBySharedSemester' => $shared,
            'semesters' => $full['semesters'] ?? [],
            // Requestors only see the single active school year (plus this DRF's year when editing).
            'schoolYears' => self::officeDrfActiveSchoolYears($exceptDrfId),
            'takenSchoolYearsByCourse' => self::officeDrfSyllabiTakenSchoolYearsByCourse($exceptDrfId),
        ];
    }

    /**
     * School years offered on office Syllabi/TOS DRF: the active S/Y only.
     * When editing, also include that DRF's saved year so the form can redisplay it.
     *
     * @return list<array{school_year_id:int,school_year:string,is_active?:bool}>
     */
    public static function officeDrfActiveSchoolYears(?int $exceptDrfId = null): array
    {
        if (! Schema::hasTable('dcs_school_years')) {
            return [];
        }

        $q = DB::table('dcs_school_years')->orderBy('school_year');
        SettingsRecycleHelper::applyNotDeleted($q, 'dcs_school_years');

        $hasActiveCol = Schema::hasColumn('dcs_school_years', 'is_active');
        $rows = collect();

        if ($hasActiveCol) {
            $active = (clone $q)->where('is_active', true)->get(['id', 'school_year', 'is_active']);
            $rows = $rows->concat($active);
        } else {
            // Pre-migration fallback: keep previous "all years" behavior.
            $rows = (clone $q)->get(['id', 'school_year']);
        }

        $keepId = 0;
        if ($exceptDrfId && $exceptDrfId > 0
            && Schema::hasTable('dcs_office_intake_drf')
            && Schema::hasColumn('dcs_office_intake_drf', 'school_year_id')
        ) {
            $keepId = (int) (DB::table('dcs_office_intake_drf')->where('id', $exceptDrfId)->value('school_year_id') ?? 0);
        }
        if ($keepId > 0 && ! $rows->contains(fn ($r) => (int) $r->id === $keepId)) {
            $extraQ = DB::table('dcs_school_years')->where('id', $keepId);
            SettingsRecycleHelper::applyNotDeleted($extraQ, 'dcs_school_years');
            $extraCols = $hasActiveCol ? ['id', 'school_year', 'is_active'] : ['id', 'school_year'];
            $extra = $extraQ->first($extraCols);
            if ($extra) {
                $rows = $rows->push($extra);
            }
        }

        return $rows
            ->unique('id')
            ->sortBy('school_year')
            ->values()
            ->map(fn ($y) => array_filter([
                'school_year_id' => (int) $y->id,
                'school_year' => (string) $y->school_year,
                'is_active' => $hasActiveCol ? ! empty($y->is_active) : null,
            ], fn ($v) => $v !== null))
            ->all();
    }

    /**
     * Whether this course + school year is reserved by another open office DRF
     * for the same sub-type (registered packs do not block a new request).
     */
    public static function officeDrfSyllabiCourseSchoolYearTaken(
        int $collegeId,
        int $programId,
        int $semesterId,
        int $courseId,
        int $schoolYearId,
        ?int $exceptDrfId = null,
        ?int $subTypeId = null
    ): bool {
        // program_id 0 = institutional GE/PE/NSTP.
        if ($collegeId < 1 || $programId < 0 || $semesterId < 1 || $courseId < 1 || $schoolYearId < 1) {
            return false;
        }
        $subTypeId = (int) ($subTypeId ?? 0);
        if ($subTypeId < 1) {
            return false;
        }
        $key = $collegeId . ':' . $programId . ':' . $semesterId . ':' . $courseId . ':' . $subTypeId;
        $taken = self::officeDrfSyllabiTakenSchoolYearsByCourse($exceptDrfId);

        return in_array($schoolYearId, $taken[$key] ?? [], true);
    }

    /**
     * Helper used for office drf syllabi connector.
     */
    public static function officeDrfSyllabiConnector(?int $subTypeId): string
    {
        if (! $subTypeId || $subTypeId < 1 || ! Schema::hasTable('dcs_doc_types')) {
            return 'Syllabus,';
        }
        $name = mb_strtolower(trim((string) DB::table('dcs_doc_types')->where('id', $subTypeId)->value('doc_type_name')));
        if (str_contains($name, 'tos') || str_contains($name, 'rubric')) {
            return 'TOS/Rubrics,';
        }

        return 'Syllabus,';
    }

    /**
     * Builds or formats data for build office drf syllabi title.
     */
    public static function buildOfficeDrfSyllabiTitle(
        string $college,
        string $program,
        string $course,
        string $semester,
        string $schoolYear,
        string $connector = 'Syllabus,',
        ?string $courseType = null
    ): string {
        $college = trim($college);
        $program = trim($program);
        $course = trim($course);
        $semester = trim($semester);
        $schoolYear = trim($schoolYear);
        $connector = trim($connector) !== '' ? trim($connector) : 'Syllabus,';
        $shared = CourseTypeRules::isSharedType($courseType);
        if ($college === '' || $course === '' || $semester === '' || $schoolYear === '') {
            return '';
        }
        if (! $shared && $program === '') {
            return '';
        }
        $sy = preg_replace('/^\s*s\/?y\s*/i', '', $schoolYear) ?: $schoolYear;
        $for = $shared ? $college : $program;

        return $college.' '.$connector.' '.$semester.' S/Y '.$sy.' for '.$for.' in '.$course;
    }

    /**
     * college_id:?int,program_id:?int,course_id:?int,semester_id:?int,school_year_id:?int,is_syllabi:bool
     * }
     * @param  array<string, mixed>  $data
     * @return array{
     */
    public static function resolveOfficeDrfSyllabiContext(array $data, ?int $subTypeId, ?int $exceptDrfId = null): array
    {
        $empty = [
            'college_id' => null,
            'program_id' => null,
            'course_id' => null,
            'semester_id' => null,
            'school_year_id' => null,
            'is_syllabi' => false,
        ];
        if (! self::isOfficeDrfSyllabiLike($subTypeId)) {
            return $empty;
        }

        $collegeId = (int) ($data['collegeId'] ?? $data['college_id'] ?? 0);
        $programId = (int) ($data['programId'] ?? $data['program_id'] ?? 0);
        $courseId = (int) ($data['courseId'] ?? $data['course_id'] ?? 0);
        $semesterId = (int) ($data['semesterId'] ?? $data['semester_id'] ?? 0);
        $schoolYearId = (int) ($data['schoolYearId'] ?? $data['school_year_id'] ?? 0);
        $courseTypeHint = trim((string) ($data['courseType'] ?? $data['course_type'] ?? ''));

        $courseCols = ['id', 'program_id', 'semester_id'];
        if (Schema::hasColumn('dcs_program_courses', 'course_type')) {
            $courseCols[] = 'course_type';
        }
        $course = $courseId > 0
            ? DB::table('dcs_program_courses')->where('id', $courseId)->first($courseCols)
            : null;
        $courseType = trim((string) ($course->course_type ?? $courseTypeHint));
        $shared = CourseTypeRules::isSharedType($courseType);

        $errors = [];
        if ($collegeId < 1) {
            $errors['collegeId'] = 'Select a college.';
        }
        if (! $shared && $programId < 1) {
            $errors['programId'] = 'Select a program.';
        }
        if ($semesterId < 1) {
            $errors['semesterId'] = 'Select a semester.';
        }
        if ($courseId < 1) {
            $errors['courseId'] = 'Select a course.';
        }
        if ($schoolYearId < 1) {
            $errors['schoolYearId'] = 'Select a school year.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($shared) {
            $programId = 0;
            if (! $course
                || $course->program_id !== null
                || (int) ($course->semester_id ?? 0) !== $semesterId
            ) {
                throw ValidationException::withMessages(['courseId' => 'Selected course does not match the institutional catalog and semester.']);
            }
            if (! DB::table('dcs_colleges')->where('id', $collegeId)->exists()) {
                throw ValidationException::withMessages(['collegeId' => 'Select a valid college.']);
            }
        } else {
            $program = DB::table('dcs_programs')->where('id', $programId)->first(['id', 'college_id']);
            if (! $program || (int) ($program->college_id ?? 0) !== $collegeId) {
                throw ValidationException::withMessages(['programId' => 'Selected program does not belong to that college.']);
            }

            if (! $course
                || (int) ($course->program_id ?? 0) !== $programId
                || (int) ($course->semester_id ?? 0) !== $semesterId
            ) {
                throw ValidationException::withMessages(['courseId' => 'Selected course does not match the program and semester.']);
            }
        }

        if (! DB::table('dcs_school_years')->where('id', $schoolYearId)->exists()) {
            throw ValidationException::withMessages(['schoolYearId' => 'Select a valid school year.']);
        }

        // New requests must use the active school year only (editing may keep a legacy year).
        if (
            Schema::hasColumn('dcs_school_years', 'is_active')
            && ! (bool) DB::table('dcs_school_years')->where('id', $schoolYearId)->value('is_active')
        ) {
            $editingSameYear = $exceptDrfId
                && $exceptDrfId > 0
                && Schema::hasTable('dcs_office_intake_drf')
                && Schema::hasColumn('dcs_office_intake_drf', 'school_year_id')
                && (int) (DB::table('dcs_office_intake_drf')->where('id', $exceptDrfId)->value('school_year_id') ?? 0) === $schoolYearId;
            if (! $editingSameYear) {
                $activeLabel = (string) (DB::table('dcs_school_years')->where('is_active', true)->value('school_year') ?? '');
                throw ValidationException::withMessages([
                    'schoolYearId' => $activeLabel !== ''
                        ? 'Only the active school year (' . $activeLabel . ') can be requested. Change the active year in Settings if needed.'
                        : 'No active school year is set. Ask Records to set one under Settings → School Years.',
                ]);
            }
        }

        if (self::officeDrfSyllabiCourseSchoolYearTaken(
            $collegeId,
            $shared ? 0 : $programId,
            $semesterId,
            $courseId,
            $schoolYearId,
            $exceptDrfId,
            $subTypeId
        )) {
            $sy = (string) (DB::table('dcs_school_years')->where('id', $schoolYearId)->value('school_year') ?? '');
            $courseName = (string) (DB::table('dcs_program_courses')->where('id', $courseId)->value('course_name') ?? 'This course');
            $subLabel = trim((string) (DB::table('dcs_doc_types')->where('id', $subTypeId)->value('doc_type_name') ?? 'this document type'));
            throw ValidationException::withMessages([
                'schoolYearId' => trim($courseName) . ' already has an open office ' . $subLabel
                    . ' request for S/Y ' . ($sy !== '' ? $sy : 'that school year')
                    . '. Finish or cancel that request first, or choose another school year.',
            ]);
        }

        return [
            'college_id' => $collegeId,
            'program_id' => $shared ? null : $programId,
            'course_id' => $courseId,
            'semester_id' => $semesterId,
            'school_year_id' => $schoolYearId,
            'is_syllabi' => true,
        ];
    }

    /**
     * Applies a query filter/scope: apply office drf syllabi columns.
     *
     * DEBUG: wrong rows visible → check this scope/filter.
     * @param  array<string, mixed>  $row
     * @param  array{college_id:?int,program_id:?int,course_id:?int,semester_id:?int,school_year_id:?int}  $ctx
     */
    public static function applyOfficeDrfSyllabiColumns(array &$row, array $ctx): void
    {
        foreach (['college_id', 'program_id', 'course_id', 'semester_id', 'school_year_id'] as $col) {
            if (Schema::hasColumn('dcs_office_intake_drf', $col)) {
                $row[$col] = ! empty($ctx[$col]) ? (int) $ctx[$col] : null;
            }
        }
    }

    /**
     * Helper used for office drf syllabi context from row.
     * @return array{
     */
    public static function officeDrfSyllabiContextFromRow(?object $drf): array
    {
        $subTypeId = (int) ($drf->sub_type_id ?? 0);
        $isSyllabi = self::isOfficeDrfSyllabiLike($subTypeId > 0 ? $subTypeId : null);
        $collegeId = (int) ($drf->college_id ?? 0);
        $programId = (int) ($drf->program_id ?? 0);
        $courseId = (int) ($drf->course_id ?? 0);
        $semesterId = (int) ($drf->semester_id ?? 0);
        $schoolYearId = (int) ($drf->school_year_id ?? 0);

        $college = $collegeId > 0 ? (string) (DB::table('dcs_colleges')->where('id', $collegeId)->value('college_name') ?? '') : '';
        $program = $programId > 0 ? (string) (DB::table('dcs_programs')->where('id', $programId)->value('program_name') ?? '') : '';
        $semester = $semesterId > 0 ? (string) (DB::table('dcs_semesters')->where('id', $semesterId)->value('semester_name') ?? '') : '';
        $schoolYear = $schoolYearId > 0 ? (string) (DB::table('dcs_school_years')->where('id', $schoolYearId)->value('school_year') ?? '') : '';
        $courseName = '';
        $courseType = '';
        $yearLevel = '';
        if ($courseId > 0 && Schema::hasTable('dcs_program_courses')) {
            $cols = ['course_name'];
            if (Schema::hasColumn('dcs_program_courses', 'course_type')) {
                $cols[] = 'course_type';
            }
            if (Schema::hasColumn('dcs_program_courses', 'year_level')) {
                $cols[] = 'year_level';
            }
            $course = DB::table('dcs_program_courses')->where('id', $courseId)->first($cols);
            if ($course) {
                $courseName = trim((string) ($course->course_name ?? ''));
                $courseType = trim((string) ($course->course_type ?? ''));
                $yearLevel = trim((string) ($course->year_level ?? ''));
            }
        }

        return [
            'is_syllabi' => $isSyllabi,
            'college' => $college !== '' ? $college : null,
            'program' => $program !== '' ? $program : null,
            'course' => $courseName !== '' ? $courseName : null,
            'semester' => $semester !== '' ? $semester : null,
            'school_year' => $schoolYear !== '' ? $schoolYear : null,
            'college_id' => $collegeId > 0 ? $collegeId : null,
            'program_id' => $programId > 0 ? $programId : null,
            'course_id' => $courseId > 0 ? $courseId : null,
            'semester_id' => $semesterId > 0 ? $semesterId : null,
            'school_year_id' => $schoolYearId > 0 ? $schoolYearId : null,
            'course_type' => $courseType !== '' ? $courseType : null,
            'year_level' => $yearLevel !== '' ? $yearLevel : null,
            'connector' => self::officeDrfSyllabiConnector($subTypeId > 0 ? $subTypeId : null),
        ];
    }

    /**
     * Other open (not-yet-registered) syllabi-like DRFs for the same course context.
     * Used to merge peer originators onto one shared syllabi registration.
     *
     * @return list<object>
     */
    public static function peerOpenSyllabiDrfs(object $drf, int $exceptId = 0): array
    {
        if (! Schema::hasTable(self::DRF_TABLE)) {
            return [];
        }

        $collegeId = (int) ($drf->college_id ?? 0);
        $courseId = (int) ($drf->course_id ?? 0);
        $semesterId = (int) ($drf->semester_id ?? 0);
        $schoolYearId = (int) ($drf->school_year_id ?? 0);
        $programId = (int) ($drf->program_id ?? 0);
        $subTypeId = (int) ($drf->sub_type_id ?? 0);
        if ($collegeId < 1 || $courseId < 1 || $semesterId < 1 || $schoolYearId < 1) {
            return [];
        }

        $courseType = '';
        if (Schema::hasTable('dcs_program_courses') && Schema::hasColumn('dcs_program_courses', 'course_type')) {
            $courseType = trim((string) (DB::table('dcs_program_courses')->where('id', $courseId)->value('course_type') ?? ''));
        }
        $shared = CourseTypeRules::isSharedType($courseType);

        $query = DB::table(self::DRF_TABLE.' as d')
            ->where('d.college_id', $collegeId)
            ->where('d.course_id', $courseId)
            ->where('d.semester_id', $semesterId)
            ->where('d.school_year_id', $schoolYearId)
            ->where('d.id', '!=', max(0, $exceptId));

        if ($shared) {
            $query->where(function ($q) {
                $q->whereNull('d.program_id')->orWhere('d.program_id', 0);
            });
        } elseif ($programId > 0) {
            $query->where('d.program_id', $programId);
        }

        if ($subTypeId > 0) {
            $query->where('d.sub_type_id', $subTypeId);
        } else {
            // Only syllabi-like intakes when the seed row has no sub type.
            $query->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('dcs_doc_types as dt')
                    ->whereColumn('dt.id', 'd.sub_type_id')
                    ->where(function ($nameQ) {
                        foreach (['syllabi', 'syllabus', 'tos', 'rubric'] as $needle) {
                            $nameQ->orWhereRaw('LOWER(dt.doc_type_name) LIKE ?', ['%'.$needle.'%']);
                        }
                    });
            });
        }

        if (Schema::hasColumn(self::DRF_TABLE, 'rfio_registered_at')) {
            $query->whereNull('d.rfio_registered_at');
        }
        if (Schema::hasColumn(self::DRF_TABLE, 'registered_request_id')) {
            $query->where(function ($q) {
                $q->whereNull('d.registered_request_id')->orWhere('d.registered_request_id', 0);
            });
        }
        if (Schema::hasColumn(self::DRF_TABLE, 'deleted_at')) {
            $query->whereNull('d.deleted_at');
        }

        $rows = $query->orderBy('d.id')->get(['d.*']);
        if ($rows->isEmpty()) {
            return [];
        }

        $list = $rows->all();
        self::hydrateIntakeOriginators('drf', $list);

        return $list;
    }

    /**
     * Peer originator names + intake ids for shared syllabi merge prefill.
     *
     * @return array{peer_originators: list<string>, peer_intake_ids: list<int>}
     */
    public static function peerSyllabiMergePayload(object $drf, int $exceptId = 0): array
    {
        $peers = self::peerOpenSyllabiDrfs($drf, $exceptId);
        $names = [];
        $ids = [];
        $seenName = [];
        foreach ($peers as $peer) {
            $id = (int) ($peer->id ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
            $name = trim((string) ($peer->originator_name ?? ''));
            if ($name === '') {
                continue;
            }
            // Junction may join multiple originators with ", ".
            foreach (preg_split('/\s*,\s*/', $name) ?: [] as $part) {
                $part = trim((string) $part);
                if ($part === '') {
                    continue;
                }
                $key = mb_strtolower($part);
                if (isset($seenName[$key])) {
                    continue;
                }
                $seenName[$key] = true;
                $names[] = $part;
            }
        }

        return [
            'peer_originators' => $names,
            'peer_intake_ids' => array_values(array_unique($ids)),
        ];
    }

    /**
     * DRF signatory payload from Settings for a catalog selection only (no personal fallback).
     * @return array<string, string>|null
     */
    public static function drfSignatoryPrefill(
        string $docTypeKind = 'internal',
        ?int $docTypeId = null,
        ?int $subTypeId = null,
        ?int $collegeId = null
    ): ?array {
        $kind = in_array($docTypeKind, ['internal', 'external'], true) ? $docTypeKind : 'internal';

        // External has no catalog pickers — use the External catalog parent assignment.
        if ($kind === 'external' && (! $docTypeId || $docTypeId < 1) && (! $subTypeId || $subTypeId < 1)) {
            $externalId = self::catalogParentIdForKind('external');
            if (! $externalId) {
                return null;
            }
            $template = self::signatoryTemplate('drf', 'external', $externalId, null, false, false);

            return is_array($template) ? self::normalizeDrfSignatoryPayload($template) : null;
        }

        $resolved = self::resolveIntakeCatalogSelection($docTypeId, $subTypeId);
        $lookupId = $resolved['sub_type_id'] ?: $resolved['doc_type_id'];
        if (! $lookupId) {
            return null;
        }

        $kind = in_array($docTypeKind, ['internal', 'external'], true)
            ? $docTypeKind
            : $resolved['doc_type_kind'];

        $collegeScope = null;
        if (self::isOfficeDrfSyllabiLike($resolved['sub_type_id'])) {
            $collegeScope = $collegeId && $collegeId > 0 ? $collegeId : null;
        }

        // Sub-type (+ college for syllabi) → parent whole type.
        $template = self::signatoryTemplate('drf', $kind, $lookupId, null, false, true, $collegeScope);
        if (! is_array($template)) {
            return null;
        }

        return self::normalizeDrfSignatoryPayload($template);
    }

    /**
     * Compact map for office DRF create JS autofill (no extra round-trips).
     * byDocTypeId: array<string, array<string, string>>,
     * byDocTypeCollege: array<string, array<string, array<string, string>>>,
     * syllabiLikeDocTypeIds: list<string>,
     * parentKind: array<string, string>,
     * externalParentId: ?int
     * }
     * @return array{
     */
    public static function drfSignatoryTemplateMap(): array
    {
        $byDocTypeId = [];
        $byDocTypeCollege = [];
        $syllabiLikeDocTypeIds = [];
        $parentKind = [];
        $externalParentId = self::catalogParentIdForKind('external');

        $catalog = self::intakeDocTypeCatalog();
        foreach ($catalog['parents'] as $parent) {
            $pid = (int) ($parent->id ?? 0);
            if ($pid < 1) {
                continue;
            }
            $name = trim((string) ($parent->doc_type_name ?? ''));
            $kind = str_contains(mb_strtolower($name), 'external') ? 'external' : 'internal';
            $parentKind[(string) $pid] = $kind;
            if (RegisterQueryHelper::isSyllabiLikeName($name)) {
                $syllabiLikeDocTypeIds[] = (string) $pid;
            }
            foreach ($catalog['childrenByParent'][(string) $pid] ?? [] as $sub) {
                $sid = (int) ($sub->id ?? 0);
                if ($sid < 1) {
                    continue;
                }
                $subName = trim((string) ($sub->doc_type_name ?? ''));
                $subKind = str_contains(mb_strtolower($name.' '.$subName), 'external') ? 'external' : 'internal';
                $parentKind[(string) $sid] = $subKind;
                if (RegisterQueryHelper::isSyllabiLikeName($subName)) {
                    $syllabiLikeDocTypeIds[] = (string) $sid;
            }
        }
        }
        $syllabiLikeDocTypeIds = array_values(array_unique($syllabiLikeDocTypeIds));

        if (! Schema::hasTable('dcs_signatory_templates')) {
            return compact('byDocTypeId', 'byDocTypeCollege', 'syllabiLikeDocTypeIds', 'parentKind', 'externalParentId');
        }

        $day = now()->toDateString();
        $query = DB::table('dcs_signatory_templates')
            ->where('form_kind', 'drf')
            ->whereNotNull('doc_type_id')
            ->orderByDesc('id');
        if (Schema::hasColumn('dcs_signatory_templates', 'effective_from')) {
            $query->where(function ($q) use ($day) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day);
            });
        }
        if (Schema::hasColumn('dcs_signatory_templates', 'effective_until')) {
            $query->where(function ($q) use ($day) {
                $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day);
            });
        }
        $hasCollegeCol = Schema::hasColumn('dcs_signatory_templates', 'college_id');
        foreach ($query->get() as $row) {
            $payload = self::signatoryPayloadForRow($row);
            $normalized = is_array($payload) ? self::normalizeDrfSignatoryPayload($payload) : null;
            if ($normalized === null) {
                continue;
            }
            $docTypeId = (int) data_get($row, 'doc_type_id', 0);
            if ($docTypeId < 1) {
                continue;
            }
                $key = (string) $docTypeId;
            $rowCollegeId = $hasCollegeCol ? (int) data_get($row, 'college_id', 0) : 0;
            if ($rowCollegeId > 0) {
                if (! isset($byDocTypeCollege[$key])) {
                    $byDocTypeCollege[$key] = [];
                }
                $cKey = (string) $rowCollegeId;
                if (! isset($byDocTypeCollege[$key][$cKey])) {
                    $byDocTypeCollege[$key][$cKey] = $normalized;
                }
            } elseif (! isset($byDocTypeId[$key])) {
                $byDocTypeId[$key] = $normalized;
            }
        }

        return compact('byDocTypeId', 'byDocTypeCollege', 'syllabiLikeDocTypeIds', 'parentKind', 'externalParentId');
    }

    /**
     * Parses or normalizes values used by normalize drf signatory payload.
     * @param  array<string, mixed>  $payload
     * @return array<string, string>|null
     */
    private static function normalizeDrfSignatoryPayload(array $payload): ?array
    {
        $keys = [
            'prepared_by_name',
            'prepared_by_designation',
            'reviewed_by_name',
            'reviewed_by_designation',
            'approved_by_name',
            'approved_by_designation',
        ];
        $out = [];
        $any = false;
        foreach ($keys as $key) {
            $val = trim((string) ($payload[$key] ?? ''));
            $out[$key] = $val;
            if ($val !== '') {
                $any = true;
            }
        }

        return $any ? $out : null;
    }

    /**
     * Last DRF signatories for the current office / creator (for create-form suggestions).
     * prepared_by_name: string,
     * prepared_by_designation: string,
     * reviewed_by_name: string,
     * reviewed_by_designation: string,
     * approved_by_name: string,
     * approved_by_designation: string
     * }|null
     * @return array{
     */
    public static function previousDrfSignatories(): ?array
    {
        if (! Schema::hasTable(self::DRF_TABLE)) {
            return null;
        }

        $query = DB::table(self::DRF_TABLE.' as drf')->select([
            'drf.prepared_by_name',
            'drf.prepared_by_designation',
            'drf.reviewed_by_name',
            'drf.reviewed_by_designation',
            'drf.approved_by_name',
            'drf.approved_by_designation',
        ]);
        self::applyOwnOfficeIntakeScope($query, 'drf', 'drf');
        $row = $query->orderByDesc('drf.id')->first();
        if (! $row) {
            return null;
        }

        $payload = [
            'prepared_by_name' => trim((string) ($row->prepared_by_name ?? '')),
            'prepared_by_designation' => trim((string) ($row->prepared_by_designation ?? '')),
            'reviewed_by_name' => trim((string) ($row->reviewed_by_name ?? '')),
            'reviewed_by_designation' => trim((string) ($row->reviewed_by_designation ?? '')),
            'approved_by_name' => trim((string) ($row->approved_by_name ?? '')),
            'approved_by_designation' => trim((string) ($row->approved_by_designation ?? '')),
        ];

        $hasAny = false;
        foreach ($payload as $value) {
            if ($value !== '') {
                $hasAny = true;
                break;
            }
        }

        return $hasAny ? $payload : null;
    }

    /**
     * Last DCN reviewers + approvals for the current office / creator.
     * @return array{reviewers: list<array{name: string, date: string|null}>, approvals: list<array{position: string, name: string, date: string|null}>}
     */
    public static function previousDcnSignatories(): array
    {
        $empty = ['reviewers' => [], 'approvals' => []];
        if (! Schema::hasTable(self::DCN_TABLE)) {
            return $empty;
        }

        $query = DB::table(self::DCN_TABLE.' as dcn')->select('dcn.id');
        self::applyOwnOfficeIntakeScope($query, 'dcn', 'dcn');
        $dcnId = (int) ($query->orderByDesc('dcn.id')->value('id') ?? 0);
        if ($dcnId < 1) {
            return $empty;
        }

        $reviewers = array_values(array_filter(
            array_map(function (array $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    return null;
                }

                return ['name' => $name, 'date' => $row['date'] ?? null];
            }, self::loadDcnReviewers($dcnId))
        ));

        $approvals = array_values(array_filter(
            array_map(function (array $row) {
                $position = trim((string) ($row['position'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));
                if ($position === '' && $name === '') {
                    return null;
                }

                return [
                    'position' => $position,
                    'name' => $name,
                    'date' => $row['date'] ?? null,
                ];
            }, self::loadDcnApprovals($dcnId))
        ));

        return [
            'reviewers' => $reviewers,
            'approvals' => $approvals,
        ];
    }

    /**
     * Loads or finds data for load dcn reviewers.
     * @return list<array{name: string, date: string|null, label: string}>
     */
    public static function loadDcnReviewers(int $dcnId, ?object $dcn = null): array
    {
        if ($dcn && isset($dcn->preview_reviewers) && is_array($dcn->preview_reviewers)) {
            return $dcn->preview_reviewers;
        }

        if (! Schema::hasTable('dcs_office_dcn_reviewers')) {
            return [];
        }

        return DB::table('dcs_office_dcn_reviewers')
            ->where('office_intake_dcn_id', $dcnId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function ($row) {
                $name = trim((string) ($row->name ?? ''));
                $date = ! empty($row->reviewed_on)
                    ? \Carbon\Carbon::parse($row->reviewed_on)->format('Y-m-d')
                    : null;

                return [
                    'name' => $name,
                    'date' => $date,
                    'label' => self::formatReviewedByLabel($name, (string) ($date ?? '')) ?? '',
                ];
            })->all();
    }

    /**
     * Saves data for sync dcn reviewers.
     *
     * DEBUG: save failures / wrong DB rows → step through this write path.
     * @param  list<array{name: string, date: string|null}>  $rows
     */
    public static function syncDcnReviewers(int $dcnId, array $rows): void
    {
        if (! Schema::hasTable('dcs_office_dcn_reviewers')) {
            return;
        }

        DB::table('dcs_office_dcn_reviewers')->where('office_intake_dcn_id', $dcnId)->delete();
        $now = now();
        $inserts = [];
        foreach (array_values($rows) as $i => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $date = $row['date'] ?? null;
            if ($name === '' && empty($date)) {
                continue;
            }
            $inserts[] = [
                'office_intake_dcn_id' => $dcnId,
                'sort_order' => $i,
                'name' => $name !== '' ? $name : '—',
                'reviewed_on' => $date ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($inserts !== []) {
            DB::table('dcs_office_dcn_reviewers')->insert($inserts);
        }
    }

    /**
     * Office DCN Approvals: Position / Name / Date rows in dcs_office_dcn_approvals.
     * @return list<array{position: string, name: string, date: string|null}>
     */
    public static function loadDcnApprovals(int $dcnId, mixed $legacyJson = null): array
    {
        if (Schema::hasTable('dcs_office_dcn_approvals')) {
            $rows = DB::table('dcs_office_dcn_approvals')
                ->where('office_intake_dcn_id', $dcnId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            if ($rows->isNotEmpty()) {
                return $rows->map(function ($row) {
                    return [
                        'position' => trim((string) ($row->position ?? '')),
                        'name' => trim((string) ($row->name ?? '')),
                        'date' => ! empty($row->approved_on)
                            ? \Carbon\Carbon::parse($row->approved_on)->format('Y-m-d')
                            : null,
                    ];
                })->all();
            }
        }

        return self::decodeDcnApprovals($legacyJson);
    }

    /**
     * Saves data for sync dcn approvals.
     *
     * DEBUG: save failures / wrong DB rows → step through this write path.
     * @param  list<array{position: string, name: string, date: string|null}>  $rows
     */
    public static function syncDcnApprovals(int $dcnId, array $rows): void
    {
        if (! Schema::hasTable('dcs_office_dcn_approvals')) {
            return;
        }

        DB::table('dcs_office_dcn_approvals')->where('office_intake_dcn_id', $dcnId)->delete();
        $now = now();
        $inserts = [];
        foreach (array_values($rows) as $i => $row) {
            $position = trim((string) ($row['position'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $date = $row['date'] ?? null;
            if ($position === '' && $name === '' && empty($date)) {
                continue;
            }
            $inserts[] = [
                'office_intake_dcn_id' => $dcnId,
                'sort_order' => $i,
                'position' => $position !== '' ? $position : null,
                'name' => $name !== '' ? $name : null,
                'approved_on' => $date ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($inserts) >= 9) {
                break;
            }
        }
        if ($inserts !== []) {
            DB::table('dcs_office_dcn_approvals')->insert($inserts);
        }
    }

    /**
     * Helper used for decode dcn approvals.
     * @return list<array{position: string, name: string, date: string|null}>
     */
    public static function decodeDcnApprovals(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $position = trim((string) ($row['position'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $date = trim((string) ($row['date'] ?? ''));
            if ($position === '' && $name === '' && $date === '') {
                continue;
            }
            $dateOut = null;
            if ($date !== '') {
                try {
                    $dateOut = \Carbon\Carbon::parse($date)->format('Y-m-d');
                } catch (\Throwable) {
                    $dateOut = null;
                }
            }
            $rows[] = [
                'position' => $position,
                'name' => $name,
                'date' => $dateOut,
            ];
            if (count($rows) >= 9) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Helper used for approvals payload from request.
     * @param  array<string, mixed>  $data
     * @return list<array{position: string, name: string, date: string|null}>
     */
    public static function approvalsPayloadFromRequest(array $data): array
    {
        $positions = $data['approvalPosition'] ?? [];
        $names = $data['approvalName'] ?? [];
        if (! is_array($positions)) {
            $positions = [];
        }
        if (! is_array($names)) {
            $names = [];
        }

        $count = max(count($positions), count($names));
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $position = trim((string) ($positions[$i] ?? ''));
            $name = trim((string) ($names[$i] ?? ''));
            if ($position === '' && $name === '') {
                continue;
            }
            $rows[] = [
                'position' => $position,
                'name' => $name,
                'date' => null,
            ];
            if (count($rows) >= 9) {
                break;
            }
        }

        return $rows;
    }

    /**
     * An added approval card must have both a position and a name.
     * The first card may stay blank when no approval is used.
     *
     * DEBUG: unexpected 403 / abort → check this guard.
     */
    private static function assertApprovalRowsComplete(Request $request): void
    {
        $positions = $request->input('approvalPosition', []);
        $names = $request->input('approvalName', []);
        if (! is_array($positions)) {
            $positions = [];
        }
        if (! is_array($names)) {
            $names = [];
        }

        $count = max(count($positions), count($names));
        $errors = [];
        for ($i = 0; $i < $count; $i++) {
            $position = trim((string) ($positions[$i] ?? ''));
            $name = trim((string) ($names[$i] ?? ''));
            if ($i === 0 && $position === '' && $name === '') {
                continue;
            }
            if ($position === '') {
                $errors['approvalPosition.'.$i] = 'Approval '.($i + 1).' needs a position. Remove an approval you are not using.';
            }
            if ($name === '') {
                $errors['approvalName.'.$i] = 'Approval '.($i + 1).' needs a name. Remove an approval you are not using.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Helper used for department date for print.
     */
    public static function departmentDateForPrint(object $dcn): string
    {
        $parts = self::parseDepartmentDate($dcn);
        $department = $parts['department_code'] !== ''
            ? $parts['department_code']
            : $parts['department'];
        $dateLabel = $parts['date_label'];

        if ($department !== '' && $dateLabel !== '') {
            return $department . ' / ' . $dateLabel;
        }

        return $department !== '' ? $department : $dateLabel;
    }

    /**
     * Department from the submitting office and the date stored on the DCN.
     * @return array{department: string, department_code: string, department_label: string, date_label: string, date_iso: string}
     */
    public static function parseDepartmentDate(object $dcn): array
    {
        if ($dcn && (! empty($dcn->preview_department_code) || ! empty($dcn->preview_department))) {
            $code = trim((string) ($dcn->preview_department_code ?? ''));
            $name = trim((string) ($dcn->preview_department ?? ''));
            $dateIso = trim((string) ($dcn->department_on ?? ''));
            $dateLabel = $dateIso !== '' ? \Carbon\Carbon::parse($dateIso)->format('F d, Y') : '';

            return [
                'department' => $name !== '' ? $name : $code,
                'department_code' => $code,
                'department_label' => $name !== '' ? $name : $code,
                'date_label' => $dateLabel,
                'date_iso' => $dateIso,
            ];
        }

        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $office = null;
        if (Schema::hasTable('dcs_office_dcn_offices')) {
            $officeId = (int) DB::table('dcs_office_dcn_offices')
                ->where('office_intake_dcn_id', (int) ($dcn->id ?? 0))
                ->orderBy('id')
                ->value('office_id');
            if ($officeId > 0) {
                $office = DB::table($officeTable)
                    ->where('id', $officeId)
                    ->whereNotIn('office_code', RegisterQueryHelper::SYSTEM_OFFICE_CODES)
                    ->first(['office_code', 'office_name']);
            }
        }

        $departmentCode = trim((string) ($office->office_code ?? ''));
        $name = trim((string) ($office->office_name ?? ''));
        $department = $departmentCode !== '' ? $departmentCode : $name;
        $departmentLabel = $departmentCode !== '' && $name !== ''
            ? $departmentCode . ' — ' . $name
            : ($name !== '' ? $name : $departmentCode);

        $dateIso = '';
        $dateLabel = '';
        if (! empty($dcn->department_on)) {
            try {
                $parsed = \Carbon\Carbon::parse($dcn->department_on);
                $dateIso = $parsed->format('Y-m-d');
                $dateLabel = \App\Helpers\RegisterQueryHelper::formatSmartDate($parsed);
            } catch (\Throwable) {
                $dateIso = '';
                $dateLabel = '';
            }
        }

        return [
            'department' => $department,
            'department_code' => $departmentCode,
            'department_label' => $departmentLabel,
            'date_label' => $dateLabel,
            'date_iso' => $dateIso,
        ];
    }

    /**
     * Parses or normalizes values used by normalize time.
     */
    private static function normalizeTime(?string $time): ?string
    {
        $time = trim((string) $time);
        if ($time === '') {
            return null;
        }
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            return substr($time, 0, 5);
        }

        return null;
    }

    /**
     * Parses or normalizes values used by parse intake notification url.
     */
    public static function parseIntakeNotificationUrl(?string $url): ?array
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? $url;
        $query = parse_url($url, PHP_URL_QUERY);
        $params = [];
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
        }

        if (preg_match('#/dcs/(?:requests|register/requests|office)/(drf|dcn)/(\d+)(/edit)?(?:/|$|\?)#', (string) $path, $matches)) {
            // Success notices use ?registered=1 — not an open RFIU review item.
            if (! empty($params['registered'])) {
                return null;
            }

            return [
                'type' => $matches[1],
                'id' => (int) $matches[2],
                'edit' => ! empty($matches[3]),
            ];
        }

        $intake = strtolower(trim((string) ($params['intake'] ?? '')));
        $id = (int) ($params['id'] ?? $params['intake_id'] ?? 0);
        if (in_array($intake, ['drf', 'dcn'], true) && $id > 0) {
            return [
                'type' => $intake,
                'id' => $id,
            ];
        }

        return null;
    }

    // =====================================================================
    // SECTION: REGISTER HANDOFF — claim, match, mark registered, unlock print
    // =====================================================================
    
    /**
     * Tells whether this intake is already linked to a finished registration.
     *
     * DEBUG: unexpected allow/deny → check this predicate.
     */
    public static function isIntakeRegistered(string $type, int $id): bool
    {
        $type = strtolower($type);
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        if (! $record) {
            return true;
        }

        // Same rules as intakeRegistrationMeta: draft-linked intakes are NOT registered yet.
        $meta = self::intakeRegistrationMeta($type, $record);
        if (! empty($meta['is_registered']) && ! empty($record->rfio_registered_at) && (int) ($record->request_id ?? 0) > 0) {
                self::detachIntakeFromControlledRequest($type, $id);
            }

        return ! empty($meta['is_registered']);
    }

    /**
     * Office intake must stay detached from dcs_document_requests so Update/Database
     * do not exclude the controlled registration via applyExcludeOfficeIntakeRequests.
     */
    private static function detachIntakeFromControlledRequest(string $type, int $id, bool $markRegisteredAt = false): void
    {
        $type = strtolower($type);
        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'request_id')) {
            return;
        }

        $update = [
            'request_id' => null,
            'updated_at' => now(),
        ];
        if ($markRegisteredAt && Schema::hasColumn($table, 'rfio_registered_at')) {
            $update['rfio_registered_at'] = now();
        }

        DB::table($table)
            ->where('id', $id)
            ->whereNotNull('request_id')
            ->update($update);
    }

    /**
     * If this intake was registered without writing request_id / rfio_registered_at,
     * link it quietly so Proceed to Registration stays hidden.
     *
     * DEBUG: save failures / wrong DB rows → step through this write path.
     */
    public static function syncRegisteredIntakeLink(string $type, int $id): void
    {
        $type = strtolower($type);
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        if (! $record) {
            return;
        }
        if (! empty($record->rfio_registered_at) || (int) ($record->request_id ?? 0) > 0) {
            return;
        }

        $matchedRequestId = self::findMatchingControlledRequestId($type, $record);
        if ($matchedRequestId < 1) {
            return;
        }

        $title = $type === 'dcn'
            ? trim((string) ($record->document_title ?? ''))
            : trim((string) ($record->doc_title ?? ''));
        $docNo = '';
        if (Schema::hasTable('dcs_masterlist_registration')) {
            $docNo = trim((string) (DB::table('dcs_masterlist_registration')
                ->where('request_id', $matchedRequestId)
                ->value('doc_no') ?? ''));
        }

        self::markIntakeRegistered(
            $type,
            $id,
            $matchedRequestId,
            $docNo,
            $title !== '' ? $title : null,
            false
        );
    }

    /**
     * Find a controlled masterlist request that matches this office intake title
     * (created at/after the intake), used to close orphaned RFIU review items.
     */
    private static function findMatchingControlledRequestId(string $type, object $record): int
    {
        if (! Schema::hasTable('dcs_masterlist_registration')) {
            return 0;
        }

        $title = $type === 'dcn'
            ? trim((string) ($record->document_title ?? ''))
            : trim((string) ($record->doc_title ?? ''));
        if ($title === '') {
            return 0;
        }

        $query = DB::table('dcs_masterlist_registration')
            ->where('doc_title', $title)
            ->whereNotNull('request_id')
            ->where('request_id', '>', 0)
            ->orderByDesc('id');

        if (Schema::hasColumn('dcs_masterlist_registration', 'is_draft')) {
            $query->where(function ($q) {
                $q->whereNull('is_draft')->orWhere('is_draft', false);
            });
        }

        $createdAt = $record->created_at ?? null;
        if ($createdAt) {
            $query->where('created_at', '>=', $createdAt);
        }

        return (int) ($query->value('request_id') ?? 0);
    }

    /**
     * Tells whether the office may still edit this intake form.
     *
     * DEBUG: unexpected allow/deny → check this predicate.
     */
    public static function canOfficeEditIntake(string $type, int $id): bool
    {
        $type = strtolower($type);
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        if (! $record) {
            return false;
        }
        if (self::isIntakeRegistered($type, $id)) {
            return false;
        }
        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            return false;
        }
        if ($type === 'dcn') {
            self::assertOwnsDcn($record);
        } else {
            self::assertOwnsDrf($record);
        }

        return ! empty($record->edit_unlocked_at);
    }

    /**
     * Returns edit/lock flags used by the intake form UI.
     */
    public static function intakeEditState(object $record): array
    {
        $unlocked = ! empty($record->edit_unlocked_at);

        return [
            'editUnlocked' => $unlocked,
            'editUnlockReason' => $unlocked ? trim((string) ($record->edit_unlock_reason ?? '')) : null,
            'editUnlockedAt' => $unlocked
                ? \Carbon\Carbon::parse($record->edit_unlocked_at)->timezone('Asia/Manila')->format('M d, Y g:i A')
                : null,
        ];
    }

    /**
     * Starts RFIU “Proceed to Register”: claims the intake and returns the Register URL.
     *
     * DEBUG: Proceed disabled / wrong register URL → isIntakeClaimedPending + registerContinueUrl.
     */
    public static function beginRegister(string $type, int $id): array
    {
        abort_unless(RegisterQueryHelper::canBrowseAllOfficeIntake(), 403);
        $type = strtolower($type);
        abort_unless(in_array($type, ['drf', 'dcn'], true), 404);
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        abort_unless($record, 404);
        abort_if(self::isIntakeRegistered($type, $id), 422, 'This office intake is already registered.');

        // Fresh column read — avoid stale model attributes blocking Proceed.
        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        $receivedAt = Schema::hasColumn($table, 'rfio_received_at')
            ? DB::table($table)->where('id', $id)->value('rfio_received_at')
            : ($record->rfio_received_at ?? null);
        $printNotifiedAt = Schema::hasColumn($table, 'rfio_print_notified_at')
            ? DB::table($table)->where('id', $id)->value('rfio_print_notified_at')
            : ($record->rfio_print_notified_at ?? null);

        $editUnlockedAt = Schema::hasColumn($table, 'edit_unlocked_at')
            ? DB::table($table)->where('id', $id)->value('edit_unlocked_at')
            : ($record->edit_unlocked_at ?? null);
        abort_if(
            ! empty($editUnlockedAt),
            422,
            'Wait for the submitting office to edit and resubmit this form before registration.'
        );
        abort_if(empty($receivedAt), 422, 'Confirm receipt of the signed '.strtoupper($type).' first to unlock registration.');
        abort_if(empty($printNotifiedAt), 422, 'Notify the submitting office that this form is correct before registration.');
        if ($type === 'dcn') {
            $linkedDrfId = self::findLinkedDrfIdForOfficeDcn($id);
            if ($linkedDrfId) {
                $linkedDrf = self::findOfficeDrf($linkedDrfId);
                abort_if(! $linkedDrf || empty($linkedDrf->rfio_received_at), 422, 'Receive the linked Document Request Form before registration.');
                abort_if(empty($linkedDrf->rfio_print_notified_at), 422, 'Notify the office for both the DCN and DRF before registration.');
            }
        } else {
            $linkedDcnId = self::findSourceDcnIdForOfficeDrf($id);
            if ($linkedDcnId) {
                $linkedDcn = self::findOfficeDcn($linkedDcnId);
                abort_if(! $linkedDcn || empty($linkedDcn->rfio_received_at), 422, 'Receive the linked Document Change Notice before registration.');
                abort_if(empty($linkedDcn->rfio_print_notified_at), 422, 'Notify the office for both the DCN and DRF before registration.');
            }
        }

        $target = self::registrationTarget($type, $id);
        self::claimOfficeIntake($type, $id);
        if ($target['intakeType'] !== $type || (int) $target['intakeId'] !== $id) {
            self::claimOfficeIntake($target['intakeType'], (int) $target['intakeId']);
        }
        if ($type === 'dcn') {
            $linkedDrfId = self::findLinkedDrfIdForOfficeDcn($id);
            if ($linkedDrfId) {
                self::claimOfficeIntake('drf', $linkedDrfId);
            }
        }

        session([
            'dcs_office_intake_pending' => [
                'type' => $target['intakeType'],
                'id' => $target['intakeId'],
            ],
        ]);
        cookie()->queue(cookie(
            'dcs_office_intake_pending',
            $target['intakeType'].':'.$target['intakeId'],
            120
        ));

        RegisterPersistHelper::logAdminChange(
            'Began registration from office ' . strtoupper($type) . ' #' . $id
        );
        \App\Services\DcsAuditService::log(
            'office.intake.begin_register',
            'review_intake',
            null,
            null,
            [
                'intake_type' => $type,
                'intake_id' => $id,
                'register_type' => $target['registerType'] ?? null,
            ]
        );

        return [
            'ok' => true,
            'type' => $target['intakeType'],
            'id' => $target['intakeId'],
            'registerType' => $target['registerType'],
            'registerUrl' => $target['registerUrl'],
        ];
    }

    /**
     * Helper used for claim office intake.
     */
    private static function claimOfficeIntake(string $type, int $id): void
    {
        $type = strtolower($type);
        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        if ($id < 1 || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'rfio_claimed_at')) {
            return;
        }

        DB::table($table)->where('id', $id)->whereNull('rfio_claimed_at')->update([
            'rfio_claimed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Peer office DRF ids merged onto a shared syllabi registration (comma/JSON list).
     *
     * @return list<int>
     */
    public static function peerIntakeIdsFromRequest(?\Illuminate\Http\Request $request = null): array
    {
        $request = $request ?? request();
        $raw = $request?->input('office_intake_peer_ids', $request?->input('peer_intake_ids', ''));
        if (is_array($raw)) {
            return array_values(array_unique(array_filter(array_map('intval', $raw))));
        }
        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }
        if ($text[0] === '[') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                return array_values(array_unique(array_filter(array_map('intval', $decoded))));
            }
        }

        return array_values(array_unique(array_filter(array_map(
            'intval',
            preg_split('/[\s,;|]+/', $text) ?: []
        ))));
    }

    /**
     * Helper used for pending register intake.
     */
    public static function pendingRegisterIntake(?\Illuminate\Http\Request $request = null): ?array
    {
        $type = strtolower(trim((string) ($request?->input('office_intake_type') ?? '')));
        $id = (int) ($request?->input('office_intake_id') ?? 0);
        if (in_array($type, ['drf', 'dcn'], true) && $id > 0) {
            return ['type' => $type, 'id' => $id];
        }

        $cookie = trim((string) ($request?->cookie('dcs_office_intake_pending') ?? ''));
        if (preg_match('/^(drf|dcn):(\d+)$/', $cookie, $cookieMatch) === 1) {
            return ['type' => $cookieMatch[1], 'id' => (int) $cookieMatch[2]];
        }

        $pending = session('dcs_office_intake_pending');
        if (is_array($pending)) {
            $type = strtolower(trim((string) ($pending['type'] ?? '')));
            $id = (int) ($pending['id'] ?? 0);
            if (in_array($type, ['drf', 'dcn'], true) && $id > 0) {
                return ['type' => $type, 'id' => $id];
            }
        }

        return null;
    }

    /**
     * Helper used for explicit register intake.
     * @return array{type: string, id: int}|null
     */
    public static function explicitRegisterIntake(?\Illuminate\Http\Request $request = null): ?array
    {
        $type = strtolower(trim((string) ($request?->input('office_intake_type') ?? '')));
        $id = (int) ($request?->input('office_intake_id') ?? 0);
        if (! in_array($type, ['drf', 'dcn'], true) || $id < 1) {
            return null;
        }

        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        if (! $record || (int) ($record->created_by ?? 0) < 1) {
            return null;
        }

        return ['type' => $type, 'id' => $id];
    }

    /**
     * A claimed office form stays "Submitted" when the registration save did not
     * carry the intake id. Link it once a controlled copy of that document
     * exists from the moment RFIU started registration.
     */
    public static function reconcileClaimedIntakesWithRegistrations(): void
    {
        static $done = false;
        if ($done || ! Schema::hasTable('dcs_masterlist_registration')) {
            return;
        }
        $done = true;

        if (Schema::hasTable('dcs_office_intake_dcn')) {
            foreach (self::claimedUnregisteredRows('dcs_office_intake_dcn') as $row) {
                $requestId = self::registrationRequestIdAfterClaim($row, [
                    (string) ($row->document_no ?? ''),
                    (string) ($row->previous_document_no ?? ''),
                ], null);
                if ($requestId < 1) {
                    continue;
                }
                [$docNo, $docTitle] = self::masterlistIdentity($requestId);
                self::markIntakeRegistered('dcn', (int) $row->id, $requestId, $docNo, $docTitle, true);
                $linkedDrfId = self::findLinkedDrfIdForOfficeDcn((int) $row->id);
                if ($linkedDrfId) {
                    self::markIntakeRegistered('drf', $linkedDrfId, $requestId, $docNo, $docTitle, true);
                }
            }
        }

        if (Schema::hasTable('dcs_office_intake_drf')) {
            foreach (self::claimedUnregisteredRows('dcs_office_intake_drf') as $row) {
                $linkedDcnId = (int) ($row->source_office_dcn_id ?? 0);
                $numbers = [];
                if ($linkedDcnId > 0) {
                    $dcn = self::findOfficeDcn($linkedDcnId);
                    if ($dcn) {
                        $numbers = [
                            (string) ($dcn->document_no ?? ''),
                            (string) ($dcn->previous_document_no ?? ''),
                        ];
                    }
                }
                $requestId = self::registrationRequestIdAfterClaim(
                    $row,
                    $numbers,
                    (string) ($row->doc_title ?? '')
                );
                if ($requestId < 1) {
                    continue;
                }
                [$docNo, $docTitle] = self::masterlistIdentity($requestId);
                self::markIntakeRegistered('drf', (int) $row->id, $requestId, $docNo, $docTitle, true);
                if ($linkedDcnId > 0) {
                    self::markIntakeRegistered('dcn', $linkedDcnId, $requestId, $docNo, $docTitle, true);
                }
            }
        }
    }

    /**
     * Helper used for intake registration request ids.
     */
    private static function intakeRegistrationRequestIds(): array
    {
        $ids = [];
        foreach (['dcs_office_intake_dcn', 'dcs_office_intake_drf'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'registered_request_id')) {
                continue;
            }
            $found = DB::table($table)
                ->whereNotNull('registered_request_id')
                ->where('registered_request_id', '>', 0)
                ->pluck('registered_request_id');
            foreach ($found as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Helper used for claimed unregistered rows.
     */
    private static function claimedUnregisteredRows(string $table)
    {
        $query = DB::table($table)->whereNotNull('rfio_claimed_at');
        if (Schema::hasColumn($table, 'rfio_registered_at')) {
            $query->whereNull('rfio_registered_at');
        }
        if (Schema::hasColumn($table, 'registered_request_id')) {
            $query->where(function ($inner) {
                $inner->whereNull('registered_request_id')->orWhere('registered_request_id', 0);
            });
        }

        return $query->get();
    }

    /**
     * Helper used for registration request id after claim.
     */
    private static function registrationRequestIdAfterClaim(object $row, array $docNos, ?string $title): int
    {
        $claimedAt = $row->rfio_claimed_at ?? null;
        if (! $claimedAt) {
            return 0;
        }

        $docNos = array_values(array_unique(array_filter(array_map(
            static fn ($value) => mb_strtolower(trim((string) $value)),
            $docNos
        ))));
        $title = mb_strtolower(trim((string) $title));

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->where('ml.created_at', '>=', $claimedAt);
        RegisterQueryHelper::applyExcludeDrafts($query, 'dr');
        RegisterQueryHelper::applyNotDeleted($query, 'dr');

        if ($docNos !== []) {
            $hasRevisedFrom = Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no');
            $query->where(function ($inner) use ($docNos, $hasRevisedFrom) {
                foreach ($docNos as $no) {
                    $inner->orWhereRaw('LOWER(TRIM(ml.doc_no)) = ?', [$no]);
                    if ($hasRevisedFrom) {
                        $inner->orWhereRaw("LOWER(TRIM(COALESCE(ml.revised_from_doc_no, ''))) = ?", [$no]);
                    }
                }
            });
            $takenIds = self::intakeRegistrationRequestIds();
            if ($takenIds !== []) {
                $query->whereNotIn('ml.request_id', $takenIds);
            }
        } elseif ($title !== '') {
            $query->whereRaw('LOWER(TRIM(ml.doc_title)) = ?', [$title]);
        } else {
            return 0;
        }

        return (int) ($query->orderBy('ml.id')->value('ml.request_id') ?? 0);
    }

    /**
     * Helper used for masterlist identity.
     */
    private static function masterlistIdentity(int $requestId): array
    {
        $ml = DB::table('dcs_masterlist_registration')
            ->where('request_id', $requestId)
            ->orderByDesc('id')
            ->first(['doc_no', 'doc_title']);
        $title = trim((string) ($ml->doc_title ?? ''));

        return [
            trim((string) ($ml->doc_no ?? '')),
            $title !== '' ? $title : null,
        ];
    }

    /**
     * Document numbers and titles the office put on this DCN/DRF.
     * A DRF created from a DCN uses that DCN's document as well.
     * @return array{numbers: list<string>, titles: list<string>}
     */
    private static function intakeIdentity(string $type, object $record): array
    {
        $type = strtolower($type);
        $numbers = [];
        $titles = [];
        $push = static function (array &$bucket, mixed $value): void {
            $value = mb_strtolower(trim((string) $value));
            if ($value !== '') {
                $bucket[] = $value;
            }
        };

        if ($type === 'dcn') {
            $push($numbers, $record->document_no ?? '');
            $push($numbers, $record->previous_document_no ?? '');
            $push($titles, $record->document_title ?? '');
        } else {
            $push($titles, $record->doc_title ?? '');
            $dcnId = (int) ($record->source_office_dcn_id ?? 0);
            if ($dcnId > 0) {
                $dcn = self::findOfficeDcn($dcnId);
                if ($dcn) {
                    $linked = self::intakeIdentity('dcn', $dcn);
                    $numbers = array_merge($numbers, $linked['numbers']);
                    $titles = array_merge($titles, $linked['titles']);
                }
            }
        }

        return [
            'numbers' => array_values(array_unique($numbers)),
            'titles' => array_values(array_unique($titles)),
        ];
    }

    /**
     * Checks whether a finished registration matches the office intake so it can be closed/linked.
     *
     * DEBUG: office not notified after register → match failed here / closePendingIntake*.
     */
    public static function registrationMatchesIntake(
        string $type,
        object $record,
        string $docNo,
        ?string $docTitle = null,
        ?string $revisedFromDocNo = null
    ): bool {
        $identity = self::intakeIdentity($type, $record);
        $needles = [];
        foreach ([$docNo, $revisedFromDocNo] as $value) {
            $value = mb_strtolower(trim((string) $value));
            if ($value !== '') {
                $needles[] = $value;
            }
        }
        foreach ($needles as $needle) {
            if (in_array($needle, $identity['numbers'], true)) {
                return true;
            }
        }

        $savedTitle = mb_strtolower(trim((string) $docTitle));

        return $savedTitle !== '' && in_array($savedTitle, $identity['titles'], true);
    }

    /**
     * Close the pending office DCN/DRF after registration only when this document
     * is the one that office created. A different document must not notify them.
     * @return list<string> submitting offices that were notified
     */
    public static function closePendingIntakeForRegistration(
        string $type,
        int $id,
        int $requestId,
        string $docNo,
        ?string $docTitle = null,
        ?string $revisedFromDocNo = null,
        bool $deferRegisteredNotify = false,
        bool $requireIdentityMatch = true
    ): array {
        $type = strtolower($type);
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : ($type === 'drf' ? self::findOfficeDrf($id) : null);
        if (! $record || $id < 1 || $requestId < 1) {
            self::clearPendingRegisterIntake();

            return [];
        }

        $pairType = null;
        $pairId = null;
        if ($type === 'dcn') {
            $pairType = 'drf';
            $pairId = self::findLinkedDrfIdForOfficeDcn($id);
        } else {
            $pairType = 'dcn';
            $pairId = self::findSourceDcnIdForOfficeDrf($id);
        }

        // Explicit Proceed-to-Registration (office_intake_id posted) must close the
        // intake even when admin syllabi pack title differs from the office request title.
        if ($requireIdentityMatch
            && ! self::registrationMatchesIntake($type, $record, $docNo, $docTitle, $revisedFromDocNo)
        ) {
            self::releaseUnregisteredClaim($type, $id);
            if ($pairType && $pairId) {
                self::releaseUnregisteredClaim($pairType, (int) $pairId);
            }
            self::clearPendingRegisterIntake();

            return [];
        }

        $codes = self::intakeSubmitterOfficeCodes($record);
        self::markIntakeRegistered($type, $id, $requestId, $docNo, $docTitle, ! $deferRegisteredNotify, $deferRegisteredNotify);
        if ($pairType && $pairId) {
            $pair = $pairType === 'dcn' ? self::findOfficeDcn((int) $pairId) : self::findOfficeDrf((int) $pairId);
            if ($pair) {
                foreach (self::intakeSubmitterOfficeCodes($pair) as $code) {
                    $codes[] = $code;
                }
            }
            self::markIntakeRegistered($pairType, (int) $pairId, $requestId, $docNo, $docTitle, ! $deferRegisteredNotify, $deferRegisteredNotify);
        }

        // Shared syllabi: close peer pending DRFs merged onto this same registration.
        if ($type === 'drf') {
            foreach (self::peerIntakeIdsFromRequest(request()) as $peerId) {
                if ($peerId === $id || ($pairType === 'drf' && $peerId === (int) $pairId)) {
                    continue;
                }
                if (self::isIntakeRegistered('drf', $peerId)) {
                    continue;
                }
                $peer = self::findOfficeDrf($peerId);
                if (! $peer) {
                    continue;
                }
                foreach (self::intakeSubmitterOfficeCodes($peer) as $code) {
                    $codes[] = $code;
                }
                self::markIntakeRegistered('drf', $peerId, $requestId, $docNo, $docTitle, ! $deferRegisteredNotify, $deferRegisteredNotify);
            }
        }

        return collect($codes)
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Removes or clears data for clear pending register intake.
     */
    public static function clearPendingRegisterIntake(): void
    {
        session()->forget('dcs_office_intake_pending');
        cookie()->queue(cookie()->forget('dcs_office_intake_pending'));
    }

    /**
     * Helper used for release unregistered claim.
     */
    private static function releaseUnregisteredClaim(string $type, int $id): void
    {
        $type = strtolower($type);
        if (! in_array($type, ['drf', 'dcn'], true) || $id < 1 || self::isIntakeRegistered($type, $id)) {
            return;
        }

        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'rfio_claimed_at')) {
            return;
        }

        DB::table($table)->where('id', $id)->whereNull('rfio_registered_at')->update([
            'rfio_claimed_at' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Link office intake → draft/registration request without notifying the office.
     * Used when RFIU saves a draft from Proceed to Registration.
     */
    public static function linkIntakeToRegistrationRequest(string $type, int $intakeId, int $requestId): void
    {
        $type = strtolower($type);
        if (! in_array($type, ['drf', 'dcn'], true) || $intakeId < 1 || $requestId < 1) {
            return;
        }

        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'registered_request_id')) {
            return;
        }

        $update = ['updated_at' => now(), 'registered_request_id' => $requestId];
        if (Schema::hasColumn($table, 'rfio_claimed_at')) {
            $update['rfio_claimed_at'] = now();
        }
        DB::table($table)->where('id', $intakeId)->update($update);

        $pairId = $type === 'dcn'
            ? self::findLinkedDrfIdForOfficeDcn($intakeId)
            : self::findSourceDcnIdForOfficeDrf($intakeId);
        if ($pairId) {
            $pairType = $type === 'dcn' ? 'drf' : 'dcn';
            $pairTable = $pairType === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
            if (Schema::hasTable($pairTable) && Schema::hasColumn($pairTable, 'registered_request_id')) {
                $pairUpdate = ['updated_at' => now(), 'registered_request_id' => $requestId];
                if (Schema::hasColumn($pairTable, 'rfio_claimed_at')) {
                    $pairUpdate['rfio_claimed_at'] = now();
                }
                DB::table($pairTable)->where('id', (int) $pairId)->update($pairUpdate);
            }
        }
    }

    /**
     * Marks an office intake as registered and links it to the controlled document request.
     *
     * DEBUG: still pending after register → linkIntakeToRegistrationRequest + this method.
     */
    public static function markIntakeRegistered(
        string $type,
        int $intakeId,
        int $requestId,
        string $docNo,
        ?string $docTitle = null,
        bool $notifyOffice = true,
        bool $deferRegisteredNotify = false
    ): void {
        $type = strtolower($type);
        if (! in_array($type, ['drf', 'dcn'], true) || $intakeId < 1 || $requestId < 1) {
            return;
        }

        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        $record = $type === 'dcn' ? self::findOfficeDcn($intakeId) : self::findOfficeDrf($intakeId);
        if (! $record) {
            return;
        }

        $now = now();
        $update = ['updated_at' => $now];
        // Keep office-intake rows detached from dcs_document_requests. Attaching
        // request_id here made Update/Database hide the controlled registration
        // via applyExcludeOfficeIntakeRequests().
        if (Schema::hasColumn($table, 'request_id')) {
            $update['request_id'] = null;
        }
        if (Schema::hasColumn($table, 'rfio_registered_at')) {
            $update['rfio_registered_at'] = $now;
        }
        if (Schema::hasColumn($table, 'rfio_claimed_at')) {
            $update['rfio_claimed_at'] = $now;
        }
        if (Schema::hasColumn($table, 'registered_request_id')) {
            $update['registered_request_id'] = $requestId;
        }
        if (Schema::hasColumn($table, 'registered_notify_pending')) {
            $update['registered_notify_pending'] = $deferRegisteredNotify && ! $notifyOffice;
        }
        if (Schema::hasColumn($table, 'edit_unlocked_at')) {
            $update['edit_unlocked_at'] = null;
            $update['edit_unlocked_by'] = null;
            $update['edit_unlock_reason'] = null;
        }
        DB::table($table)->where('id', $intakeId)->update($update);

        self::ensureIntakeOfficeOnMasterlistSources($type, $intakeId, $requestId, $record);

        DcsNotificationService::dismissOfficeIntakeNotifications($type, $intakeId);
        session()->forget('dcs_office_intake_pending');
        cookie()->queue(cookie()->forget('dcs_office_intake_pending'));

        RegisterPersistHelper::logAdminChange(
            'Linked office ' . strtoupper($type) . ' #' . $intakeId
            . ' to registration #' . $requestId
            . ($docNo !== '' ? ' — ' . $docNo : '')
            . ($docTitle ? ': ' . $docTitle : '')
        );
        \App\Services\DcsAuditService::log(
            'office.intake.registered',
            'review_intake',
            $requestId,
            null,
            [
                'intake_type' => $type,
                'intake_id' => $intakeId,
                'doc_no' => $docNo,
                'title' => $docTitle,
            ]
        );

        if (! $notifyOffice || $deferRegisteredNotify) {
            return;
        }

        self::sendIntakeRegisteredNotification($type, $intakeId, $record, $docNo, $docTitle);
    }

    /**
     * After RDP appraisal finishes for a DCS registration that came from office intake,
     * notify the office that created the DRF/DCN (deferred from register time).
     *
     * DEBUG: missing/wrong bell notice → check here.
     */
    public static function notifyPendingIntakeRegistrationAfterAppraisal(int $requestId): void
    {
        if ($requestId < 1) {
            return;
        }

        foreach (['drf' => 'dcs_office_intake_drf', 'dcn' => 'dcs_office_intake_dcn'] as $type => $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'registered_request_id')) {
                continue;
            }
            $query = DB::table($table)->where('registered_request_id', $requestId);
            if (Schema::hasColumn($table, 'registered_notify_pending')) {
                $query->where('registered_notify_pending', true);
            }
            foreach ($query->orderBy('id')->get() as $row) {
                $intakeId = (int) ($row->id ?? 0);
                if ($intakeId < 1) {
                    continue;
                }
                $docNo = trim((string) ($row->previous_document_no ?? $row->document_no ?? ''));
                $docTitle = trim((string) ($type === 'dcn' ? ($row->document_title ?? '') : ($row->doc_title ?? '')));
                self::sendIntakeRegisteredNotification($type, $intakeId, $row, $docNo, $docTitle !== '' ? $docTitle : null);
                if (Schema::hasColumn($table, 'registered_notify_pending')) {
                    DB::table($table)->where('id', $intakeId)->update([
                        'registered_notify_pending' => false,
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Sends a notification for send intake registered notification.
     *
     * DEBUG: missing/wrong bell notice → check here.
     */
    private static function sendIntakeRegisteredNotification(
        string $type,
        int $intakeId,
        object $record,
        string $docNo,
        ?string $docTitle = null
    ): void {
        $submitterCodes = self::intakeSubmitterOfficeCodes($record);
        if ($submitterCodes === []) {
            return;
        }

        $title = trim((string) ($docTitle
            ?? ($type === 'dcn' ? ($record->document_title ?? '') : ($record->doc_title ?? ''))
        ));
        $docNo = trim($docNo);
        $formLabel = $type === 'dcn' ? 'Document Change Notice' : 'Document Request Form';
        $titlePart = $title !== '' ? " \"{$title}\"" : '';
        $docPart = $docNo !== '' ? " as {$docNo}" : '';
        $message = "Your {$formLabel}{$titlePart} was registered{$docPart}.";
        $url = '/dcs/office/'.$type.'/'.$intakeId.'?registered=1';

        foreach ($submitterCodes as $officeCode) {
            DcsNotificationService::createNotification($officeCode, $message, $url);
        }
    }

    /**
     * Submitter office codes for a registration that came from office DRF/DCN intake.
     * Used so Incoming-distribution notices never go to the office that filed the request.
     * @return list<string>
     */
    public static function submitterOfficeCodesForRequest(int $requestId, ?Request $request = null): array
    {
        $codes = [];
        if ($requestId > 0) {
            foreach (['drf' => 'dcs_office_intake_drf', 'dcn' => 'dcs_office_intake_dcn'] as $type => $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'registered_request_id')) {
                    continue;
                }
                $rows = DB::table($table)
                    ->where('registered_request_id', $requestId)
                    ->orderBy('id')
                    ->get();
                foreach ($rows as $row) {
                    foreach (self::intakeSubmitterOfficeCodes($row) as $code) {
                        $codes[] = $code;
                    }
                }
            }
        }

        // Pending claim (not linked yet) — still skip that office during first publish.
        $pending = self::pendingRegisterIntake($request);
        if (is_array($pending)
            && in_array(($pending['type'] ?? ''), ['drf', 'dcn'], true)
            && (int) ($pending['id'] ?? 0) > 0
        ) {
            $type = (string) $pending['type'];
            $id = (int) $pending['id'];
            $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
            if ($record) {
                foreach (self::intakeSubmitterOfficeCodes($record) as $code) {
                    $codes[] = $code;
                }
            }
        }

        return collect($codes)
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Office code(s) for the account that created the office intake form.
     * @return list<string>
     */
    public static function intakeSubmitterOfficeCodes(object $record): array
    {
        $createdBy = (int) ($record->created_by ?? 0);
        if ($createdBy < 1) {
            return [];
        }

        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $submitter = DB::table($accDetailsTbl . ' as ad')
            ->join($officeTbl . ' as o', 'o.id', '=', 'ad.office_id')
            ->where('ad.account_id', $createdBy)
            ->select('o.id', 'o.office_code')
            ->first();
        if (! $submitter) {
            return [];
        }

        $codes = [];
        $code = strtoupper(trim((string) ($submitter->office_code ?? '')));
        if ($code !== '') {
            $codes[] = $code;
        }

        $oid = (int) ($submitter->id ?? 0);
        if ($oid > 0) {
            foreach (DcsNotificationService::officeCodesFromIds([$oid]) as $fromId) {
                $codes[] = $fromId;
            }
        }

        // Include RFIU/RFOIU when the submitter belongs there (limited intake staff).
        // Other offices are unaffected — this list is only the creator's office.
        return collect($codes)
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Guarantee the submitting office appears as a masterlist Source Unit
     * (keeps Source Unit in sync with the intake office that submitted the form).
     */
    private static function ensureIntakeOfficeOnMasterlistSources(
        string $type,
        int $intakeId,
        int $requestId,
        object $record
    ): void {
        if (! Schema::hasTable('dcs_masterlist_registration')
            || ! Schema::hasTable('dcs_masterlist_source_offices')
            || $requestId < 1) {
            return;
        }

        $masterlistId = (int) (DB::table('dcs_masterlist_registration')
            ->where('request_id', $requestId)
            ->orderByDesc('id')
            ->value('id') ?? 0);
        if ($masterlistId < 1) {
            return;
        }

        $officeIds = [];
        $sourceOffices = $type === 'dcn' ? self::dcnSourceOffices($intakeId) : self::drfSourceOffices($intakeId);
        foreach ($sourceOffices as $row) {
            $oid = (int) ($row->office_id ?? 0);
            if ($oid > 0) {
                $officeIds[] = $oid;
            }
        }

        $createdBy = (int) ($record->created_by ?? 0);
        if ($createdBy > 0) {
            $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
            $submitterOfficeId = (int) (DB::table($accDetailsTbl)
                ->where('account_id', $createdBy)
                ->value('office_id') ?? 0);
            if ($submitterOfficeId > 0) {
                $officeIds[] = $submitterOfficeId;
            }
        }

        $officeIds = array_values(array_unique(array_filter($officeIds)));
        if ($officeIds === []) {
            return;
        }

        $now = now();
        foreach ($officeIds as $officeId) {
            $exists = DB::table('dcs_masterlist_source_offices')
                ->where('masterlist_id', $masterlistId)
                ->where('office_id', $officeId)
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('dcs_masterlist_source_offices')->insert([
                'masterlist_id' => $masterlistId,
                'office_id' => $officeId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * RFIU returns an incorrect office intake so the submitting office can edit it.
     * @return array<string, mixed>
     */
    public static function unlockForEdit(string $type, int $id, string $reason): array
    {
        abort_unless(RegisterQueryHelper::canBrowseAllOfficeIntake(), 403);

        $type = strtolower($type);
        abort_unless(in_array($type, ['drf', 'dcn'], true), 404);
        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        abort_unless($record, 404);
        abort_if(self::isIntakeRegistered($type, $id), 422, 'This submission is already registered and cannot be returned for edit.');
        abort_if(
            ! empty($record->rfio_print_notified_at),
            422,
            'This submission was already marked correct and the office was notified to print and sign. Enable edit is no longer available.'
        );

        if (! Schema::hasColumn($table, 'edit_unlocked_at')) {
            abort(422, 'Edit unlock is not available yet. Please run migrations.');
        }

        $reason = strip_tags(html_entity_decode((string) $reason, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $reason = trim(preg_replace('/\s+/u', ' ', str_replace("\0", '', $reason)) ?? '');
        $reason = mb_substr($reason, 0, 1000);
        abort_if($reason === '' || mb_strlen($reason) < 5, 422, 'Please provide a short reason (at least 5 characters).');

        $now = now();
        $userId = (int) auth()->id();
        DB::table($table)->where('id', $id)->update([
            'edit_unlocked_at' => $now,
            'edit_unlocked_by' => $userId > 0 ? $userId : null,
            'edit_unlock_reason' => mb_substr($reason, 0, 1000),
            'updated_at' => $now,
        ]);
        self::bumpOfficeInventoryCache();

        $formLabel = $type === 'dcn' ? 'Document Change Notice' : 'Document Request Form';
        $title = trim((string) ($type === 'dcn' ? ($record->document_title ?? '') : ($record->doc_title ?? '')));
        $titlePart = $title !== '' ? " \"{$title}\"" : '';
        $message = "RFIU returned your {$formLabel}{$titlePart} for correction: {$reason}. You can edit and resubmit it now.";
        $url = '/dcs/office/' . $type . '/' . $id . '/edit';

        foreach (self::intakeOfficeCodes($type, $id, $record) as $officeCode) {
            DcsNotificationService::createNotification($officeCode, $message, $url);
        }

        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);

        return array_merge([
            'ok' => true,
            'type' => $type,
            'id' => $id,
        ], self::intakeEditState($record));
    }

    /**
     * Unlocks print for the office and sends the print-and-sign notification.
     *
     * DEBUG: office never got print unlock → DcsNotificationService + this method.
     * @return array<string, mixed>
     */
    public static function notifyReadyForPrintSign(string $type, int $id): array
    {
        abort_unless(RegisterQueryHelper::canBrowseAllOfficeIntake(), 403);

        $type = strtolower($type);
        abort_unless(in_array($type, ['drf', 'dcn'], true), 404);
        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        self::ensureOfficePrintedColumns($table);
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        abort_unless($record, 404);
        abort_if(self::isIntakeRegistered($type, $id), 422, 'This submission is already registered.');
        abort_if(
            ! empty($record->edit_unlocked_at),
            422,
            'Wait for the submitting office to edit and resubmit this form before notifying them to print and sign.'
        );

        $title = trim((string) ($type === 'dcn' ? ($record->document_title ?? '') : ($record->doc_title ?? '')));
        $officeCodes = self::intakeOfficeCodes($type, $id, $record);
        abort_if($officeCodes === [], 422, 'No submitting office found to notify.');

        $now = now();
        $userId = (int) auth()->id();
        if (Schema::hasColumn($table, 'rfio_print_notified_at')) {
            $update = [
                'rfio_print_notified_at' => $now,
                'rfio_print_notified_by' => $userId > 0 ? $userId : null,
                'updated_at' => $now,
            ];
            // A fresh print notice means the office still needs to print again.
            if (Schema::hasColumn($table, 'office_printed_at')) {
                $update['office_printed_at'] = null;
            }
            if (Schema::hasColumn($table, 'office_printed_by')) {
                $update['office_printed_by'] = null;
            }
            DB::table($table)->where('id', $id)->update($update);
            self::bumpOfficeInventoryCache();
        }

        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        $peer = self::linkedIntakePeer($type, $id);
        $peerAlsoNotified = false;

        // Linked DCN+DRF: one RFIU confirm marks both forms ready and sends a single pair notice.
        // Hold only when the peer is still unlocked for correction.
        if ($peer !== null && empty($peer['record']->rfio_print_notified_at ?? null)) {
            if (! empty($peer['record']->edit_unlocked_at)) {
                return array_merge([
                    'ok' => true,
                    'type' => $type,
                    'id' => $id,
                    'notifiedOffices' => $officeCodes,
                    'noticeHeld' => true,
                    'pairComplete' => false,
                    'peerAlsoNotified' => false,
                    'pairLabel' => strtoupper((string) $peer['type']),
                ], self::intakePrintNotifiedState($record));
            }

            $peerTable = $peer['type'] === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
            self::ensureOfficePrintedColumns($peerTable);
            if (Schema::hasColumn($peerTable, 'rfio_print_notified_at')) {
                $peerUpdate = [
                    'rfio_print_notified_at' => $now,
                    'rfio_print_notified_by' => $userId > 0 ? $userId : null,
                    'updated_at' => $now,
                ];
                if (Schema::hasColumn($peerTable, 'office_printed_at')) {
                    $peerUpdate['office_printed_at'] = null;
                }
                if (Schema::hasColumn($peerTable, 'office_printed_by')) {
                    $peerUpdate['office_printed_by'] = null;
                }
                DB::table($peerTable)->where('id', (int) $peer['id'])->update($peerUpdate);
                self::bumpOfficeInventoryCache();
                $peerAlsoNotified = true;
                $peer['record'] = $peer['type'] === 'dcn'
                    ? self::findOfficeDcn((int) $peer['id'])
                    : self::findOfficeDrf((int) $peer['id']);
            }
        }

        $peerNotified = $peer === null || ! empty($peer['record']->rfio_print_notified_at ?? null);
        $noticeHeld = $peer !== null && ! $peerNotified;
        $sent = 0;

        if (! $noticeHeld) {
            foreach ($officeCodes as $officeCode) {
                $ok = $peer
                    ? DcsNotificationService::notifyOfficeIntakePairReadyForPrintSign(
                        $officeCode,
                        $type === 'dcn' ? $id : (int) $peer['id'],
                        $type === 'drf' ? $id : (int) $peer['id'],
                        $title
                    )
                    : DcsNotificationService::notifyOfficeIntakeReadyForPrintSign($officeCode, $type, $id, $title);
                if ($ok) {
                    $sent++;
                }
            }
            abort_if($sent < 1, 422, 'Could not send notification to the submitting office.');
        }

        return array_merge([
            'ok' => true,
            'type' => $type,
            'id' => $id,
            'notifiedOffices' => $officeCodes,
            'noticeHeld' => $noticeHeld,
            'pairComplete' => $peer === null || $peerNotified,
            'peerAlsoNotified' => $peerAlsoNotified || ($peer !== null && $peerNotified),
            'pairLabel' => $peer ? strtoupper((string) $peer['type']) : '',
        ], self::intakePrintNotifiedState($record));
    }

    /**
     * Helper used for linked intake peer.
     */
    private static function linkedIntakePeer(string $type, int $id): ?array
    {
        $type = strtolower($type);
        if ($type === 'dcn') {
            $peerId = self::findLinkedDrfIdForOfficeDcn($id);
            if (! $peerId) {
                return null;
            }
            $peer = self::findOfficeDrf($peerId);

            return $peer ? ['type' => 'drf', 'id' => $peerId, 'record' => $peer] : null;
        }

        $peerId = self::findSourceDcnIdForOfficeDrf($id);
        if (! $peerId) {
            return null;
        }
        $peer = self::findOfficeDcn($peerId);

        return $peer ? ['type' => 'dcn', 'id' => $peerId, 'record' => $peer] : null;
    }

    /**
     * Helper used for intake print notified state.
     * @return array{printNotified: bool, printNotifiedAt: string|null, printNotifiedBy: string|null}
     */
    public static function intakePrintNotifiedState(object $record): array
    {
        $at = $record->rfio_print_notified_at ?? null;
        $byId = (int) ($record->rfio_print_notified_by ?? 0);
        $notified = ! empty($at);

        return [
            'printNotified' => $notified,
            'printNotifiedAt' => $notified
                ? \Carbon\Carbon::parse($at)->timezone('Asia/Manila')->format('M d, Y g:i A')
                : null,
            'printNotifiedBy' => $notified && $byId > 0 ? self::displayNameForUser($byId) : null,
        ];
    }

    /**
     * Helper used for intake office codes.
     */
    private static function intakeOfficeCodes(string $type, int $id, object $record): array
    {
        $officeCodes = [];
        $officeIds = [];

        $sourceOffices = $type === 'dcn' ? self::dcnSourceOffices($id) : self::drfSourceOffices($id);
        foreach ($sourceOffices as $row) {
            $code = strtoupper(trim((string) ($row->office_code ?? '')));
            if ($code !== '') {
                $officeCodes[] = $code;
            }
            $oid = (int) ($row->office_id ?? 0);
            if ($oid > 0) {
                $officeIds[] = $oid;
            }
        }

        // Always include the submitter's office (source rows may lack office_code).
        $createdBy = (int) ($record->created_by ?? 0);
        if ($createdBy > 0) {
            $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
            $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
            $submitter = DB::table($accDetailsTbl . ' as ad')
                ->join($officeTbl . ' as o', 'o.id', '=', 'ad.office_id')
                ->where('ad.account_id', $createdBy)
                ->select('o.id', 'o.office_code')
                ->first();
            if ($submitter) {
                $code = strtoupper(trim((string) ($submitter->office_code ?? '')));
                if ($code !== '') {
                    $officeCodes[] = $code;
                }
                $oid = (int) ($submitter->id ?? 0);
                if ($oid > 0) {
                    $officeIds[] = $oid;
                }
            }
        }

        foreach (DcsNotificationService::officeCodesFromIds($officeIds) as $code) {
            $officeCodes[] = $code;
        }

        $rfio = strtoupper(trim((string) RegisterQueryHelper::rfioNotificationOfficeCode()));

        $codes = collect($officeCodes)
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values();

        $rfioCodes = collect(RegisterQueryHelper::rfioOfficeCodes())
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->all();
        if ($rfio !== '') {
            $rfioCodes[] = $rfio;
        }
        $rfioCodes = array_values(array_unique($rfioCodes));

        // Prefer non-RFIU client offices. If the submitter is in RFIU/RFOIU (same office
        // as Document Controllers), still notify that office — limited-DCS staff see it;
        // full Document Controllers have client notices filtered from their bell.
        $withoutRfio = $codes
            ->reject(fn ($code) => in_array(strtoupper(trim((string) $code)), $rfioCodes, true))
            ->values();

        return $withoutRfio->isNotEmpty() ? $withoutRfio->all() : $codes->all();
    }

    /**
     * Helper used for intake submission meta.
     */
    public static function intakeSubmissionMeta(object $record, string $type, int $id): array
    {
        $offices = $type === 'dcn' ? self::dcnSourceOffices($id) : self::drfSourceOffices($id);
        $office = $offices
            ->map(fn ($row) => trim((string) ($row->office_name ?? '')) ?: trim((string) ($row->office_code ?? '')))
            ->filter()
            ->unique()
            ->implode(', ');

        if ($office === '') {
            $office = self::officeLabelForUser((int) ($record->created_by ?? 0));
        }

        $submitter = self::displayNameForUser((int) ($record->created_by ?? 0));
        $submittedAt = ! empty($record->created_at)
            ? \Carbon\Carbon::parse($record->created_at)->timezone('Asia/Manila')->format('M d, Y g:i A')
            : '—';

        return [
            'office' => $office !== '' ? $office : 'Unknown office',
            'submitter' => $submitter !== '' ? $submitter : 'Unknown submitter',
            'submittedAt' => $submittedAt,
        ];
    }

    /**
     * Helper used for office label for user.
     */
    public static function officeLabelForUser(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        $row = DB::table($accDetailsTbl . ' as ad')
            ->join($officeTbl . ' as o', 'o.id', '=', 'ad.office_id')
            ->where('ad.account_id', $userId)
            ->select('o.office_name', 'o.office_code')
            ->first();

        if (! $row) {
            return '';
        }

        $name = trim((string) ($row->office_name ?? ''));

        return $name !== '' ? $name : trim((string) ($row->office_code ?? ''));
    }

    /**
     * Helper used for display name for user.
     */
    public static function displayNameForUser(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $row = DB::table($accDetailsTbl)
            ->where('account_id', $userId)
            ->select('first_name', 'last_name')
            ->first();

        if (! $row) {
            return '';
        }

        return trim(trim((string) ($row->first_name ?? '')) . ' ' . trim((string) ($row->last_name ?? '')));
    }

    /**
     * Helper used for modal payload.
     */
    // =====================================================================
    // SECTION: MODALS, DISTRIBUTE OFFICES, RECEIVED STATE
    // =====================================================================
    
    public static function modalPayload(string $type, int $id): ?array
    {
        return match (strtolower($type)) {
            'dcn' => self::dcnModalPayload($id),
            'drf' => self::drfModalPayload($id),
            default => null,
        };
    }

    /**
     * When the browser posts distributeToOffice[] empty but still sends the CSV
     * backup from the office widget, rebuild the array before validation.
     */
    public static function mergeDistributeOfficeBackup(Request $request): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('distributeToOffice', []))));
        if ($ids !== []) {
            $request->merge(['distributeToOffice' => $ids]);

            return;
        }

        $backup = trim((string) $request->input('distributeToOfficeBackup', ''));
        if ($backup === '') {
            return;
        }

        $fromBackup = array_values(array_unique(array_filter(array_map(
            static fn ($part) => (int) trim((string) $part),
            explode(',', $backup)
        ), static fn (int $id) => $id > 0)));

        if ($fromBackup !== []) {
            $request->merge(['distributeToOffice' => $fromBackup]);
        }
    }

    /**
     * Helper used for distribute office ids.
     */
    public static function distributeOfficeIds(int $drfId): array
    {
        if ($drfId <= 0 || ! Schema::hasTable('dcs_office_drf_distribute_offices')) {
            return [];
        }

        return DB::table('dcs_office_drf_distribute_offices')
            ->where('office_intake_drf_id', $drfId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('office_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
    }

    /**
     * Saves data for sync distribute offices.
     *
     * DEBUG: save failures / wrong DB rows → step through this write path.
     */
    public static function syncDistributeOffices(int $drfId, array $officeIds): void
    {
        if ($drfId <= 0 || ! Schema::hasTable('dcs_office_drf_distribute_offices')) {
            return;
        }

        DB::table('dcs_office_drf_distribute_offices')
            ->where('office_intake_drf_id', $drfId)
            ->delete();

        $ids = array_values(array_unique(array_filter(array_map('intval', $officeIds))));
        if ($ids === []) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($ids as $index => $officeId) {
            if ($officeId <= 0) {
                continue;
            }
            $rows[] = [
                'office_intake_drf_id' => $drfId,
                'office_id' => $officeId,
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            DB::table('dcs_office_drf_distribute_offices')->insert($rows);
        }
    }

    /**
     * Helper used for drf distribute offices.
     */
    public static function drfDistributeOffices(object $drf): array
    {
        if (isset($drf->preview_distribute_offices) && is_array($drf->preview_distribute_offices)) {
            return $drf->preview_distribute_offices;
        }

        $ids = self::distributeOfficeIds((int) ($drf->id ?? 0));
        if ($ids === []) {
            return [];
        }

        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $byId = DB::table($officeTable)
            ->whereIn('id', $ids)
            ->get(['id', 'office_code', 'office_name'])
            ->keyBy('id');

        $offices = [];
        foreach ($ids as $officeId) {
            $row = $byId[$officeId] ?? null;
            if (! $row) {
                continue;
            }
            $code = trim((string) ($row->office_code ?? ''));
            $name = trim((string) ($row->office_name ?? ''));
            if ($code === '' && $name === '') {
                continue;
            }
            $offices[] = [
                'office_id' => $officeId,
                'code' => $code,
                'name' => $name,
            ];
        }

        return $offices;
    }

    /**
     * Helper used for dcn modal payload.
     */
    private static function dcnModalPayload(int $id): ?array
    {
        $dcn = self::findOfficeDcn($id);
        if (! $dcn) {
            return null;
        }

        self::assertOwnsDcn($dcn);
        $dcn = self::findOfficeDcn($id) ?: $dcn;

        $revisions = self::dcnRevisions($id);
        $firstRev = $revisions->first();
        $docNo = trim((string) ($dcn->document_no ?? '')) ?: trim((string) ($firstRev->document_no ?? ''));
        $docTitle = trim((string) ($dcn->document_title ?? '')) ?: trim((string) ($firstRev->title ?? ''));
        $received = self::intakeReceivedState($dcn);
        $canManage = RegisterQueryHelper::canBrowseAllOfficeIntake();
        $canRegister = $canManage && RegisterQueryHelper::canAccessDcsModule('register');
        $registered = self::isIntakeRegistered('dcn', $id);
        $editState = self::intakeEditState($dcn);
        $printState = self::intakePrintNotifiedState($dcn);
        if ($registered) {
            DcsNotificationService::dismissOfficeIntakeNotifications('dcn', $id);
        }

        return array_merge([
            'type' => 'dcn',
            'id' => $id,
            'title' => 'Office DCN Submission',
            'subtitle' => $registered
                ? 'Already registered / controlled — this RFIU review item is closed.'
                : 'Review only — submitted by another office for RFIU processing.',
            'html' => view('pages.dcs.office.partials.review-submission-dcn', [
                'dcn' => $dcn,
                'docNo' => $docNo,
                'docTitle' => $docTitle,
                'meta' => self::intakeSubmissionMeta($dcn, 'dcn', $id),
            ])->render(),
            'registerPrefill' => self::registerPrefillForDcn($dcn, $id, $docNo, $docTitle),
            'registered' => $registered,
        ], self::intakeHandoffMeta('dcn', $id, $received, $canManage, $canRegister, $registered, $editState, $printState));
    }

    /**
     * Helper used for drf modal payload.
     */
    private static function drfModalPayload(int $id): ?array
    {
        $drf = self::findOfficeDrf($id);
        if (! $drf) {
            return null;
        }

        self::assertOwnsDrf($drf);
        $drf = self::findOfficeDrf($id) ?: $drf;

        $received = self::intakeReceivedState($drf);
        $canManage = RegisterQueryHelper::canBrowseAllOfficeIntake();
        $canRegister = $canManage && RegisterQueryHelper::canAccessDcsModule('register');
        $registered = self::isIntakeRegistered('drf', $id);
        $editState = self::intakeEditState($drf);
        $printState = self::intakePrintNotifiedState($drf);
        if ($registered) {
            DcsNotificationService::dismissOfficeIntakeNotifications('drf', $id);
        }

        return array_merge([
            'type' => 'drf',
            'id' => $id,
            'title' => 'Office DRF Submission',
            'subtitle' => $registered
                ? 'Already registered / controlled — this RFIU review item is closed.'
                : 'Review only — submitted by another office for RFIU processing.',
            'html' => view('pages.dcs.office.partials.review-submission-drf', [
                'drf' => $drf,
                'distributeOffices' => self::drfDistributeOffices($drf),
                'meta' => self::intakeSubmissionMeta($drf, 'drf', $id),
            ])->render(),
            'registerPrefill' => self::registerPrefillForDrf($drf, $id),
            'registered' => $registered,
        ], self::intakeHandoffMeta('drf', $id, $received, $canManage, $canRegister, $registered, $editState, $printState));
    }

    /**
     * Helper used for intake received state.
     * @return array{received: bool, receivedAt: string|null, receivedBy: string|null}
     */
    public static function intakeReceivedState(object $record): array
    {
        $at = $record->rfio_received_at ?? null;
        $byId = (int) ($record->rfio_received_by ?? 0);
        $received = ! empty($at);

        return [
            'received' => $received,
            'receivedAt' => $received
                ? \Carbon\Carbon::parse($at)->timezone('Asia/Manila')->format('M d, Y g:i A')
                : null,
            'receivedBy' => $received && $byId > 0 ? self::displayNameForUser($byId) : null,
        ];
    }

    /**
     * Helper used for intake handoff meta.
     * @param  array{received: bool, receivedAt: string|null, receivedBy: string|null}  $received
     * @param  array{editUnlocked: bool, editUnlockReason: string|null, editUnlockedAt: string|null}  $editState
     * @param  array{printNotified: bool, printNotifiedAt: string|null, printNotifiedBy: string|null}  $printState
     * @return array<string, mixed>
     */
    private static function intakeHandoffMeta(
        string $type,
        int $id,
        array $received,
        bool $canManage,
        bool $canRegister,
        bool $registered = false,
        array $editState = [],
        array $printState = []
    ): array {
        $target = self::registrationTarget($type, $id);
        $registerType = $target['registerType'];
        $registerUrl = ($canRegister && ! $registered) ? $target['registerUrl'] : null;

        $editUnlocked = (bool) ($editState['editUnlocked'] ?? false);

        return [
            'canConfirmReceived' => $canManage && ! $registered && ! $editUnlocked,
            'canRegister' => $canRegister && ! $registered && ! $editUnlocked,
            'canUnlockEdit' => $canManage && ! $registered,
            'canNotifyPrintReady' => $canManage && ! $registered && ! $editUnlocked,
            'received' => $received['received'],
            'receivedAt' => $received['receivedAt'],
            'receivedBy' => $received['receivedBy'],
            'registerUrl' => $registerUrl,
            'registerType' => $registerType,
            'registered' => $registered,
            'editUnlocked' => $editUnlocked,
            'editUnlockReason' => $editState['editUnlockReason'] ?? null,
            'editUnlockedAt' => $editState['editUnlockedAt'] ?? null,
            'printNotified' => (bool) ($printState['printNotified'] ?? false),
            'printNotifiedAt' => $printState['printNotifiedAt'] ?? null,
            'printNotifiedBy' => $printState['printNotifiedBy'] ?? null,
        ];
    }

    /**
     * Helper used for mark received.
     * @return array<string, mixed>
     */
    public static function markReceived(string $type, int $id): array
    {
        abort_unless(RegisterQueryHelper::canBrowseAllOfficeIntake(), 403);

        $type = strtolower($type);
        abort_unless(in_array($type, ['drf', 'dcn'], true), 404);

        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        abort_unless($record, 404);
        abort_if(
            ! empty($record->edit_unlocked_at),
            422,
            'Wait for the submitting office to edit and resubmit this form before confirming receipt.'
        );

        if (! Schema::hasColumn($table, 'rfio_received_at')) {
            abort(422, 'Received tracking is not available yet. Please run migrations.');
        }

        $now = now();
        $userId = (int) auth()->id();

        $wasNew = empty($record->rfio_received_at);
        if ($wasNew) {
            DB::table($table)->where('id', $id)->update([
                'rfio_received_at' => $now,
                'rfio_received_by' => $userId > 0 ? $userId : null,
                'updated_at' => $now,
            ]);
            $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);

            RegisterPersistHelper::logAdminChange(
                'Received signed office ' . strtoupper($type) . ' #' . $id
            );
            \App\Services\DcsAuditService::log(
                'office.intake.received',
                'review_intake',
                null,
                null,
                ['intake_type' => $type, 'intake_id' => $id]
            );
        }

        $state = self::intakeReceivedState($record);
        $canRegister = RegisterQueryHelper::canAccessDcsModule('register');

        return array_merge($state, [
            'ok' => true,
            'type' => $type,
            'id' => $id,
            'canRegister' => $canRegister,
            'registerUrl' => $canRegister ? self::registrationTarget($type, $id)['registerUrl'] : null,
        ]);
    }

    /**
     * Undo "I have received the signed printed form…" so RFIU can return it for correction instead.
     * @return array<string, mixed>
     */
    public static function clearReceived(string $type, int $id): array
    {
        abort_unless(RegisterQueryHelper::canBrowseAllOfficeIntake(), 403);

        $type = strtolower($type);
        abort_unless(in_array($type, ['drf', 'dcn'], true), 404);

        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        abort_unless($record, 404);
        abort_if(self::isIntakeRegistered($type, $id), 422, 'This submission is already registered.');

        if (Schema::hasColumn($table, 'rfio_received_at')) {
            DB::table($table)->where('id', $id)->update([
                'rfio_received_at' => null,
                'rfio_received_by' => null,
                'updated_at' => now(),
            ]);
            $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);

            RegisterPersistHelper::logAdminChange(
                'Cleared receipt on office ' . strtoupper($type) . ' #' . $id
            );
            \App\Services\DcsAuditService::log(
                'office.intake.clear_received',
                'review_intake',
                null,
                null,
                ['intake_type' => $type, 'intake_id' => $id]
            );
        }

        $state = self::intakeReceivedState($record);
        $canRegister = RegisterQueryHelper::canAccessDcsModule('register');

        return array_merge($state, [
            'ok' => true,
            'type' => $type,
            'id' => $id,
            'canRegister' => $canRegister,
            'canUnlockEdit' => true,
            'registerUrl' => $canRegister ? self::registrationTarget($type, $id)['registerUrl'] : null,
        ]);
    }

    /**
     * Maps an office DRF into the Document Registration form fields (prefill).
     *
     * DEBUG: wrong fields after Proceed from DRF → this prefill mapper.
     */
    // =====================================================================
    // SECTION: REGISTER PREFILL — map office intake → Document Registration form
    // =====================================================================
    
    public static function registerPrefillForDrf(object $drf, int $id): array
    {
        self::hydrateIntakeOriginators('drf', [$drf]);
        self::hydrateDrfPreviousCopies($drf);
        $sourceOffices = self::drfSourceOffices($id)->map(fn ($row) => [
            'office_id' => (int) ($row->office_id ?? 0),
            'office_name' => trim((string) ($row->office_name ?? '')),
            'office_code' => trim((string) ($row->office_code ?? '')),
        ])->filter(fn ($o) => ($o['office_id'] ?? 0) > 0 || ($o['office_name'] ?? '') !== '' || ($o['office_code'] ?? '') !== '')
            ->values()
            ->all();

        $distribute = self::drfDistributeOffices($drf);
        $distributeIds = collect($distribute)
            ->map(fn (array $office) => [
                'office_id' => (int) ($office['office_id'] ?? 0),
                'office_name' => trim((string) ($office['name'] ?? '')),
                'office_code' => trim((string) ($office['code'] ?? '')),
            ])
            ->filter(fn (array $office) => $office['office_id'] > 0)
            ->values()
            ->all();

        $kind = strtolower(trim((string) ($drf->doc_type_kind ?? '')));
        $parentMap = RegisterQueryHelper::parentTypeIdMap();
        $storedParentId = (int) ($drf->doc_type_id ?? 0);
        $storedSubId = (int) ($drf->sub_type_id ?? 0);
        if ($storedParentId > 0 || $storedSubId > 0) {
            $resolved = self::resolveIntakeCatalogSelection(
                $storedParentId > 0 ? $storedParentId : null,
                $storedSubId > 0 ? $storedSubId : null
            );
            $docTypeId = $resolved['doc_type_id'];
            $subTypeId = $resolved['sub_type_id'];
            // Keep Forms/Logbooks as posted — catalog resolve would map them to "internal".
            if (! in_array($kind, ['forms', 'logbooks'], true) && ! empty($resolved['doc_type_kind'])) {
                $kind = $resolved['doc_type_kind'];
            }
        } else {
            $docTypeId = match ($kind) {
                'internal' => $parentMap['internal_docs'] ?? null,
                'external' => $parentMap['external_docs'] ?? null,
                'forms' => self::findOptInDrfParentId('forms'),
                'logbooks' => self::findOptInDrfParentId('logbooks'),
                default => null,
            };
            $subTypeId = null;
        }

        $drfDate = ! empty($drf->drf_date)
            ? \Carbon\Carbon::parse($drf->drf_date)->format('Y-m-d')
            : null;

        $previousNo = trim((string) ($drf->previous_document_no ?? ''));
        $isRecurring = $previousNo !== '' && self::findSourceDcnIdForOfficeDrf($id) === null;
        $versionType = $isRecurring ? 'recurring' : 'new';

        $syllabi = self::officeDrfSyllabiContextFromRow($drf);
        $peerMerge = ! empty($syllabi['is_syllabi'])
            ? self::peerSyllabiMergePayload($drf, $id)
            : ['peer_originators' => [], 'peer_intake_ids' => []];
        // Checklist 1 stays on so registration still includes a DRF; syllabi mode
        // hides #section-1 and uses the syllabi wizard DRF (#section-syllabi) instead.

        $prefill = [
            'intake' => 'drf',
            'intakeId' => $id,
            'versionType' => $versionType,
            'checklistIds' => [1, 3, 5],
            'docTypeId' => $docTypeId,
            'subTypeId' => $subTypeId,
            'docTypeKind' => $kind !== '' ? $kind : null,
            'drfTitle' => trim((string) ($drf->doc_title ?? '')),
            'drfDate' => $drfDate,
            'descriptionReason' => trim((string) ($drf->description_reason ?? '')),
            'originatorName' => trim((string) ($drf->originator_name ?? '')),
            'masterlistDocTitle' => trim((string) ($drf->doc_title ?? '')),
            'sourceOffices' => $sourceOffices,
            'distributeOffices' => $distributeIds !== [] ? $distributeIds : collect($distribute)->map(fn ($o) => [
                'office_code' => $o['code'] ?? '',
                'office_name' => $o['name'] ?? '',
            ])->all(),
            // When the submitting office already chose distribution offices on the DRF,
            // DCS register must not add further offices beyond that list.
            'lockDistributeOffices' => ($distributeIds !== [] || $distribute !== []),
            'isSyllabiLike' => ! empty($syllabi['is_syllabi']),
            'peerIntakeIds' => $peerMerge['peer_intake_ids'],
            'syllabi' => ! empty($syllabi['is_syllabi']) ? [
                'college_id' => $syllabi['college_id'],
                'program_id' => $syllabi['program_id'],
                'course_id' => $syllabi['course_id'],
                'course_name' => $syllabi['course'],
                'semester_id' => $syllabi['semester_id'],
                'school_year_id' => $syllabi['school_year_id'],
                'course_type' => $syllabi['course_type'],
                'year_level' => $syllabi['year_level'],
                'doc_title' => trim((string) ($drf->doc_title ?? '')),
                'drf_date' => $drfDate,
                'peer_originators' => $peerMerge['peer_originators'],
                'peer_intake_ids' => $peerMerge['peer_intake_ids'],
            ] : null,
        ];

        if ($isRecurring) {
            $prefill['reviseFromDocNo'] = $previousNo;
            $prefill['masterlistDocNo'] = $previousNo;
            $identityTitle = trim((string) ($drf->previous_document_title ?? ''));
            if ($identityTitle === '') {
                $identityTitle = trim((string) ($drf->doc_title ?? ''));
            }
            $identityOriginator = trim((string) ($drf->previous_originator ?? ''));
            $pinnedMlId = (int) ($drf->previous_masterlist_id ?? 0);
            $pinnedRequestId = (int) ($drf->previous_request_id ?? 0);

            $registered = null;
            if ($pinnedMlId > 0) {
                $byId = self::findRevisableRegistrationByMasterlistId($pinnedMlId);
                if ($byId) {
                    $registered = (object) [
                        'masterlist_id' => (int) ($byId->ml_id ?? 0),
                        'request_id' => (int) ($byId->request_id ?? 0),
                        'doc_no' => trim((string) ($byId->doc_no ?? $previousNo)),
                        'doc_title' => trim((string) ($byId->doc_title ?? '')),
                        'revise_no' => (int) ($byId->revise_no ?? 0),
                        'doc_type_id' => (int) ($byId->doc_type_id ?? 0),
                        'sub_type_id' => (int) ($byId->sub_type_id ?? 0),
                        'originator_name' => trim((string) ($byId->originator_label ?? '')),
                    ];
                }
            }
            if (! $registered) {
                $registered = self::latestRegistrationForDocNo($previousNo, $identityTitle !== '' ? $identityTitle : null);
            }
            if ($registered && $pinnedRequestId > 0 && (int) ($registered->request_id ?? 0) !== $pinnedRequestId) {
                // Prefer the exact request the office selected when doc_no collisions exist.
                $byReq = self::findRevisableRegistrationMatch(
                    $previousNo,
                    $identityTitle,
                    $identityOriginator !== '' ? $identityOriginator : trim((string) ($drf->originator_name ?? ''))
                );
                if ($byReq && (int) ($byReq->request_id ?? 0) === $pinnedRequestId) {
                    $registered = (object) [
                        'masterlist_id' => (int) ($byReq->ml_id ?? 0),
                        'request_id' => (int) ($byReq->request_id ?? 0),
                        'doc_no' => trim((string) ($byReq->doc_no ?? $previousNo)),
                        'doc_title' => trim((string) ($byReq->doc_title ?? '')),
                        'revise_no' => (int) ($byReq->revise_no ?? 0),
                        'doc_type_id' => (int) ($byReq->doc_type_id ?? 0),
                        'sub_type_id' => (int) ($byReq->sub_type_id ?? 0),
                        'originator_name' => trim((string) ($byReq->originator_label ?? '')),
                    ];
                }
            }
            if ($registered) {
                $typeId = (int) ($registered->doc_type_id ?? 0);
                $subId = (int) ($registered->sub_type_id ?? 0);
                if ($typeId > 0) {
                    $prefill['docTypeId'] = $typeId;
                }
                $prefill['subTypeId'] = $subId > 0 ? $subId : null;
                $prefill['revisionRequestId'] = (int) ($registered->request_id ?? 0);
                $prefill['revisionReviseNo'] = (int) ($registered->revise_no ?? 0);
                $prefill['revisionDocTitle'] = trim((string) ($registered->doc_title ?? $identityTitle));
                $prefill['revisionDocNo'] = trim((string) ($registered->doc_no ?? $previousNo));
                $prefill['supersedeMasterlistId'] = (int) ($registered->masterlist_id ?? $pinnedMlId);
                if (! empty($registered->originator_name)) {
                    $prefill['originatorName'] = trim((string) $registered->originator_name);
                } elseif ($identityOriginator !== '') {
                    $prefill['originatorName'] = $identityOriginator;
                }
            }
        }

        return $prefill;
    }

    /**
     * Maps an office DCN into the Document Registration form fields (prefill).
     *
     * DEBUG: wrong fields after Proceed from DCN → this prefill mapper.
     */
    public static function registerPrefillForDcn(object $dcn, int $id, string $docNo, string $docTitle): array
    {
        self::hydrateIntakeOriginators('dcn', [$dcn]);
        $sourceOffices = self::dcnSourceOffices($id)->map(fn ($row) => [
            'office_id' => (int) ($row->office_id ?? 0),
            'office_name' => trim((string) ($row->office_name ?? '')),
            'office_code' => trim((string) ($row->office_code ?? '')),
        ])->filter(fn ($o) => ($o['office_id'] ?? 0) > 0 || ($o['office_name'] ?? '') !== '' || ($o['office_code'] ?? '') !== '')
            ->values()
            ->all();

        $justification = trim((string) ($dcn->brief_purpose ?? ''));
        $changeFrom = trim((string) ($dcn->change_from ?? ''));
        $changeTo = trim((string) ($dcn->change_to ?? ''));

        $noticeDate = ! empty($dcn->dcn_date)
            ? \Carbon\Carbon::parse($dcn->dcn_date)->format('Y-m-d')
            : null;

        $previousNo = trim((string) ($dcn->previous_document_no ?? ''));
        if ($previousNo === '') {
            $previousNo = $docNo;
        }

        $prefill = [
            'intake' => 'dcn',
            'intakeId' => $id,
            'versionType' => 'revised',
            'checklistIds' => [2, 3, 4, 5],
            'documentNo' => $docNo,
            'documentTitle' => $docTitle,
            // Justification = office intake "Justification of Change" only (brief_purpose).
            // Do not seed from Detailed Description (change_from / change_to).
            'dcnJustification' => $justification,
            'noticeDate' => $noticeDate,
            'originatorName' => trim((string) ($dcn->originator_name ?? '')),
            'masterlistDocTitle' => $docTitle,
            'masterlistDocNo' => $docNo,
            'reviseFromDocNo' => $previousNo,
            'changeFrom' => $changeFrom,
            'changeTo' => $changeTo,
            'sourceOffices' => $sourceOffices,
        ];

        $linkedDrfId = self::findLinkedDrfIdForOfficeDcn($id);
        if ($linkedDrfId) {
            $linkedDrf = self::findOfficeDrf($linkedDrfId);
            if ($linkedDrf) {
                $drfPrefill = self::registerPrefillForDrf($linkedDrf, $linkedDrfId);
                $prefill['checklistIds'] = array_values(array_unique(array_merge(
                    [1],
                    $prefill['checklistIds']
                )));
                $prefill['linkedDrfId'] = $linkedDrfId;
                $prefill['drfTitle'] = $drfPrefill['drfTitle'] ?? '';
                $prefill['drfDate'] = $drfPrefill['drfDate'] ?? null;
                $prefill['descriptionReason'] = $drfPrefill['descriptionReason'] ?? '';
                $prefill['distributeOffices'] = $drfPrefill['distributeOffices'] ?? [];
                $prefill['lockDistributeOffices'] = $drfPrefill['lockDistributeOffices'] ?? false;
                if (! empty($drfPrefill['originatorName'])) {
                    $prefill['originatorName'] = $drfPrefill['originatorName'];
                }
                if (! empty($drfPrefill['docTypeKind'])) {
                    $prefill['docTypeKind'] = $drfPrefill['docTypeKind'];
                }
                if (! empty($drfPrefill['docTypeId'])) {
                    $prefill['docTypeId'] = $drfPrefill['docTypeId'];
                }
            }
        }

        $registered = self::latestRegistrationForDocNo($previousNo);
        if ($registered) {
            $typeId = (int) ($registered->doc_type_id ?? 0);
            $subId = (int) ($registered->sub_type_id ?? 0);
            if ($typeId > 0) {
                $prefill['docTypeId'] = $typeId;
            }
            $prefill['subTypeId'] = $subId > 0 ? $subId : null;
            $prefill['revisionRequestId'] = (int) ($registered->request_id ?? 0);
            $prefill['revisionReviseNo'] = (int) ($registered->revise_no ?? 0);
            $prefill['revisionDocTitle'] = trim((string) ($registered->doc_title ?? ''));
            $prefill['revisionDocNo'] = trim((string) ($registered->doc_no ?? $previousNo));
        }

        return $prefill;
    }

    /**
     * Helper used for document group defs.
     */
    // =====================================================================
    // SECTION: OFFICE INVENTORY — grouped documents, incoming, random-check feeds
    // =====================================================================
    
    public static function documentGroupDefs(): array
    {
        return [
            'internal_docs' => 'Internal',
            'internal_forms' => 'Internal Forms',
            'external_docs' => 'External',
            'forms' => 'Forms',
            'logbooks' => 'Logbooks',
        ];
    }

    /**
     * Helper used for document group scope.
     */
    public static function documentGroupScope(string $groupKey): string
    {
        return 'distribution';
    }

    /**
     * Helper used for document group keys for scope.
     */
    public static function documentGroupKeysForScope(string $scope): array
    {
        $keys = [];
        foreach (array_keys(self::documentGroupDefs()) as $key) {
            if (self::documentGroupScope($key) === $scope) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Helper used for document group label.
     */
    public static function documentGroupLabel(string $groupKey): string
    {
        return self::documentGroupDefs()[$groupKey] ?? 'Documents';
    }

    /**
     * Helper used for doc type ids for group.
     */
    public static function docTypeIdsForGroup(string $groupKey): array
    {
        $parentId = RegisterQueryHelper::parentTypeIdMap()[$groupKey] ?? null;
        if (! $parentId) {
            return [];
        }

        return DB::table('dcs_doc_types')
            ->where(function ($q) use ($parentId) {
                $q->where('id', $parentId)->orWhere('parent_id', $parentId);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Applies a query filter/scope: apply office distribution scope.
     *
     * DEBUG: wrong rows visible → check this scope/filter.
     */
    protected static function applyOfficeDistributionScope($query, int $officeId)
    {
        return $query->whereExists(function ($q) use ($officeId) {
            $q->select(DB::raw(1))
                ->from('dcs_document_distribution as dist')
                ->join('dcs_distribution_offices as doff', 'doff.distribution_id', '=', 'dist.id')
                ->whereColumn('dist.request_id', 'ml.request_id')
                ->where('doff.office_id', $officeId)
                ->whereNotNull('doff.office_id');
        });
    }

    /**
     * Applies a query filter/scope: apply office source scope.
     *
     * DEBUG: wrong rows visible → check this scope/filter.
     */
    protected static function applyOfficeSourceScope($query, int $officeId)
    {
        return $query->whereExists(function ($q) use ($officeId) {
            $q->select(DB::raw(1))
                ->from('dcs_masterlist_source_offices as so')
                ->whereColumn('so.masterlist_id', 'ml.id')
                ->where('so.office_id', $officeId)
                ->whereNotNull('so.office_id');
        });
    }

    /**
     * Hide documents this office itself submitted via office intake (DRF/DCN).
     * Applied for all distribution-scoped inventory groups (including Forms / Logbooks).
     *
     * DEBUG: wrong rows visible → check this scope/filter.
     */
    protected static function applyExcludeOwnOfficeIntake($query, int $officeId)
    {
        $detailsTable = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

        if (Schema::hasColumn('dcs_office_intake_drf', 'registered_request_id')) {
            $query->whereNotExists(function ($q) use ($officeId, $detailsTable) {
                $q->select(DB::raw(1))
                    ->from('dcs_office_intake_drf as oi_drf')
                    ->join($detailsTable . ' as oi_ad', 'oi_ad.account_id', '=', 'oi_drf.created_by')
                    ->whereColumn('oi_drf.registered_request_id', 'ml.request_id')
                    ->where('oi_ad.office_id', $officeId)
                    ->whereNotNull('oi_drf.registered_request_id');
            });
        }

        if (Schema::hasColumn('dcs_office_intake_dcn', 'registered_request_id')) {
            $query->whereNotExists(function ($q) use ($officeId, $detailsTable) {
                $q->select(DB::raw(1))
                    ->from('dcs_office_intake_dcn as oi_dcn')
                    ->join($detailsTable . ' as oi_ad', 'oi_ad.account_id', '=', 'oi_dcn.created_by')
                    ->whereColumn('oi_dcn.registered_request_id', 'ml.request_id')
                    ->where('oi_ad.office_id', $officeId)
                    ->whereNotNull('oi_dcn.registered_request_id');
            });
        }

        return $query;
    }

    /**
     * Applies a query filter/scope: apply office inventory common filters.
     *
     * DEBUG: wrong rows visible → check this scope/filter.
     */
    protected static function applyOfficeInventoryCommonFilters($query)
    {
        $query->whereExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('dcs_document_requests as dr')
                ->whereColumn('dr.id', 'ml.request_id');
            RegisterQueryHelper::applyNotDeleted($q, 'dr');
            RegisterQueryHelper::applyExcludeOfficeIntakeRequests($q, 'dr');
            RegisterQueryHelper::applyExcludeDrafts($q, 'dr');
        });

        return $query;
    }

    /**
     * Base masterlist query for documents distributed to the current office.
     * @param  'distribution'|'source'|null  $scope  null = combined (All / total)
     */
    protected static function officeMasterlistQuery(?int $officeId = null, ?string $scope = 'distribution')
    {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();
        $query = DB::table('dcs_masterlist_registration as ml');

        $hasDist = Schema::hasTable('dcs_document_distribution')
            && Schema::hasTable('dcs_distribution_offices');
        $hasSource = Schema::hasTable('dcs_masterlist_source_offices');

        if (! $officeId) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        $officeId = (int) $officeId;

        if ($scope === 'distribution') {
            if (! $hasDist) {
                $query->whereRaw('1 = 0');

                return $query;
            }
            self::applyOfficeDistributionScope($query, $officeId);
            self::applyExcludeOwnOfficeIntake($query, $officeId);
        } elseif ($scope === 'source') {
            if (! $hasSource) {
                $query->whereRaw('1 = 0');

                return $query;
            }
            self::applyOfficeSourceScope($query, $officeId);
        } else {
            // Combined All view: any distribution-scoped document type for this office.
            $query->where(function ($outer) use ($officeId, $hasDist) {
                if (! $hasDist) {
                    $outer->whereRaw('1 = 0');

                    return;
                }
                $outer->where(function ($q) use ($officeId) {
                    self::applyOfficeDistributionScope($q, $officeId);
                    self::applyExcludeOwnOfficeIntake($q, $officeId);
                    $q->where(function ($types) {
                        foreach (self::documentGroupKeysForScope('distribution') as $key) {
                            $types->orWhere(function ($t) use ($key) {
                                self::applyMasterlistGroupFilter($t, $key);
                            });
                        }
                    });
                });
            });
        }

        return self::applyOfficeInventoryCommonFilters($query);
    }

    /**
     * Helper used for dcn document group keys.
     */
    public static function dcnDocumentGroupKeys(): array
    {
        return ['internal_docs', 'external_docs'];
    }

    /**
     * Applies a query filter/scope: apply dcn document type filter.
     *
     * DEBUG: wrong rows visible → check this scope/filter.
     */
    protected static function applyDcnDocumentTypeFilter($query)
    {
        return $query->where(function ($outer) {
            foreach (self::dcnDocumentGroupKeys() as $key) {
                $outer->orWhere(function ($q) use ($key) {
                    self::applyMasterlistGroupFilter($q, $key);
                });
            }
        });
    }

    /**
     * Filter by parent document type the same way Inventory type tabs do
     * (dcs_document_requests.doc_type_id = parent type id).
     *
     * DEBUG: wrong rows visible → check this scope/filter.
     */
    protected static function applyMasterlistGroupFilter($query, string $groupKey)
    {
        $parentId = RegisterQueryHelper::parentTypeIdMap()[$groupKey] ?? null;
        if (! $parentId) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        return $query->where(function ($q) use ($parentId) {
            $q->where('ml.doc_type_id', (int) $parentId)
                ->orWhereExists(function ($r) use ($parentId) {
                    $r->select(DB::raw(1))
                        ->from('dcs_document_requests as dr')
                        ->whereColumn('dr.id', 'ml.request_id')
                        ->where('dr.doc_type_id', (int) $parentId);
                })
                ->orWhereExists(function ($r) use ($parentId) {
                    $r->select(DB::raw(1))
                        ->from('dcs_document_requests as dr')
                        ->join('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
                        ->whereColumn('dr.id', 'ml.request_id')
                        ->where('st.parent_id', (int) $parentId);
                })
                ->orWhereExists(function ($r) use ($parentId) {
                    $r->select(DB::raw(1))
                        ->from('dcs_doc_types as mt')
                        ->whereColumn('mt.id', 'ml.doc_type_id')
                        ->where('mt.parent_id', (int) $parentId);
                });
        });
    }

    /**
     * Helper used for office document total.
     */
    public static function officeDocumentTotal(?int $officeId = null, string $receiptFilter = 'all'): int
    {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();

        return (int) self::rememberOfficeInventory(
            'total.v2.' . $receiptFilter . '.' . (int) ($officeId ?? 0),
            function () use ($officeId, $receiptFilter) {
                if (! $officeId) {
                    return 0;
                }

                return (int) self::officeDocumentsDistinctQuery('all', (int) $officeId, $receiptFilter)->count();
            }
        );
    }

    /**
     * Helper used for office incoming attention breakdown.
     * @return array{pending: int, appraisable: int, total: int}
     */
    public static function officeIncomingAttentionBreakdown(?int $officeId = null, bool $forceFresh = false): array
    {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();

        if ($forceFresh) {
            self::bumpOfficeInventoryCache();
        }

        return self::rememberOfficeInventory(
            'incoming.attention.v2.'.(int) ($officeId ?? 0),
            function () use ($officeId) {
        $pending = self::officeDocumentTotal($officeId, 'pending');
                $appraisable = 0;
        $perms = auth()->user()?->permissions;
                if ($perms && ($perms->is_sadm || $perms->can_access_rdp) && $officeId) {
                    // SQL distinct count only — no row hydration for the nav badge.
                    $appraisable = self::officeDocumentTotal($officeId, 'received_for_appraisal');
                }

                return [
                    'pending' => $pending,
                    'appraisable' => $appraisable,
                    'total' => $pending + $appraisable,
                ];
            },
            45
        );
    }

    /**
     * Helper used for office incoming attention total.
     */
    public static function officeIncomingAttentionTotal(?int $officeId = null, bool $forceFresh = false): int
    {
        return (int) (self::officeIncomingAttentionBreakdown($officeId, $forceFresh)['total'] ?? 0);
    }

    /**
     * Helper used for request is linked to office intake.
     */
    public static function requestIsLinkedToOfficeIntake(int $requestId): bool
    {
        if ($requestId < 1) {
            return false;
        }

        return isset(self::requestIdsLinkedToOfficeIntake([$requestId])[$requestId]);
    }

    /**
     * Link claimed (not yet finalized) intakes to draft requests by exact title only.
     * Never match on document number — shared form nos (e.g. CSPC-F-COL-13) wrongly
     * lock Delete on unrelated drafts.
     *
     * @param  list<array{request_id:int,title:string,doc_no:string}>  $drafts
     */
    public static function backfillDraftIntakeLinks(array $drafts): void
    {
        if ($drafts === []) {
            return;
        }

        // Drop false links first (cookie/doc-no accidents), then optional title repair.
        self::unlinkMismatchedDraftIntakeLinks($drafts);

        $byTitle = [];
        foreach ($drafts as $draft) {
            $rid = (int) ($draft['request_id'] ?? 0);
            if ($rid < 1 || self::requestIsLinkedToOfficeIntake($rid)) {
                continue;
            }
            $title = mb_strtolower(trim((string) ($draft['title'] ?? '')));
            if ($title !== '' && $title !== 'untitled draft') {
                $byTitle[$title] = $rid;
            }
        }
        if ($byTitle === []) {
            return;
        }

        foreach (['drf' => 'dcs_office_intake_drf', 'dcn' => 'dcs_office_intake_dcn'] as $type => $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'registered_request_id')) {
                continue;
            }
            if (! Schema::hasColumn($table, 'rfio_claimed_at')) {
                continue;
            }
            $query = DB::table($table)
                ->whereNotNull('rfio_claimed_at')
                ->where(function ($q) {
                    $q->whereNull('registered_request_id')->orWhere('registered_request_id', 0);
                });
            if (Schema::hasColumn($table, 'rfio_registered_at')) {
                $query->whereNull('rfio_registered_at');
            }
            foreach ($query->orderByDesc('id')->limit(100)->get() as $row) {
                $title = mb_strtolower(trim((string) ($type === 'dcn'
                    ? ($row->document_title ?? '')
                    : ($row->doc_title ?? ''))));
                $requestId = $byTitle[$title] ?? 0;
                if ($requestId > 0) {
                    self::linkIntakeToRegistrationRequest($type, (int) $row->id, $requestId);
                }
            }
        }
    }

    /**
     * Clear draft↔intake links that do not share the intake title (and are not finalized).
     * Restores Delete on drafts that were wrongly locked by cookie / doc-no backfill.
     *
     * @param  list<array{request_id:int,title:string,doc_no?:string}>  $drafts
     */
    public static function unlinkMismatchedDraftIntakeLinks(array $drafts): void
    {
        if ($drafts === []) {
            return;
        }

        $titlesByRequest = [];
        foreach ($drafts as $draft) {
            $rid = (int) ($draft['request_id'] ?? 0);
            if ($rid < 1) {
                continue;
            }
            $titlesByRequest[$rid] = mb_strtolower(trim((string) ($draft['title'] ?? '')));
        }
        if ($titlesByRequest === []) {
            return;
        }

        foreach (['drf' => 'dcs_office_intake_drf', 'dcn' => 'dcs_office_intake_dcn'] as $type => $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'registered_request_id')) {
                continue;
            }
            $query = DB::table($table)
                ->whereIn('registered_request_id', array_keys($titlesByRequest));
            if (Schema::hasColumn($table, 'rfio_registered_at')) {
                $query->whereNull('rfio_registered_at');
            }
            foreach ($query->get() as $row) {
                $requestId = (int) ($row->registered_request_id ?? 0);
                $draftTitle = $titlesByRequest[$requestId] ?? '';
                $intakeTitle = mb_strtolower(trim((string) ($type === 'dcn'
                    ? ($row->document_title ?? '')
                    : ($row->doc_title ?? ''))));
                // Syllabi office DRFs use a pack title on Register that differs from the
                // request title — keep those links.
                if ($type === 'drf' && (int) ($row->course_id ?? 0) > 0) {
                    continue;
                }
                if ($draftTitle !== '' && $intakeTitle !== '' && $draftTitle === $intakeTitle) {
                    continue;
                }

                DB::table($table)->where('id', (int) $row->id)->update([
                    'registered_request_id' => null,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Helper used for request ids linked to office intake.
     * @param  list<int>  $requestIds
     * @return array<int, true>
     */
    public static function requestIdsLinkedToOfficeIntake(array $requestIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
        if ($ids === []) {
            return [];
        }

        $linked = [];
        foreach (['dcs_office_intake_drf', 'dcs_office_intake_dcn'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'registered_request_id')) {
                continue;
            }
            foreach (DB::table($table)->whereIn('registered_request_id', $ids)->pluck('registered_request_id') as $id) {
                $linked[(int) $id] = true;
            }
        }

        return $linked;
    }

    /**
     * Tells whether is incoming notice url — used for access and UI decisions.
     *
     * DEBUG: unexpected allow/deny → check this predicate.
     */
    public static function isIncomingNoticeUrl(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? $url);

        return str_contains($path, '/dcs/office/incoming');
    }

    /**
     * Helper used for incoming document code from url.
     */
    public static function incomingDocumentCodeFromUrl(?string $url): ?string
    {
        if (! self::isIncomingNoticeUrl($url)) {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        $params = [];
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
        }

        $doc = trim((string) ($params['doc'] ?? ''));

        return $doc !== '' ? $doc : null;
    }

    /**
     * Helper used for incoming notice still open.
     */
    public static function incomingNoticeStillOpen(?string $url): bool
    {
        if (! self::isIncomingNoticeUrl($url)) {
            return false;
        }

        $doc = self::incomingDocumentCodeFromUrl($url);
        if ($doc === null) {
            return self::officeIncomingAttentionTotal() > 0;
        }

        $officeId = (int) (RegisterQueryHelper::currentOfficeId() ?? 0);
        if ($officeId < 1) {
            return false;
        }

        $unreceived = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_distribution as dist', 'dist.request_id', '=', 'ml.request_id')
            ->join('dcs_distribution_offices as doff', 'doff.distribution_id', '=', 'dist.id')
            ->where('doff.office_id', $officeId)
            ->whereNull('doff.office_received_at')
            ->whereRaw('LOWER(ml.doc_no) = ?', [strtolower($doc)])
            ->exists();
        if ($unreceived) {
            return true;
        }

        $perms = auth()->user()?->permissions;
        if (! $perms || (! $perms->is_sadm && ! $perms->can_access_rdp)) {
            return false;
        }
        if (! Schema::hasTable('rdp_received_documents')) {
            return false;
        }

        $officeCode = RegisterQueryHelper::currentOfficeCode();
        if (! $officeCode) {
            return false;
        }

        return DB::table('rdp_received_documents')
            ->where('source_subsystem', 'DCS')
            ->where('status', 'pending')
            ->whereRaw('LOWER(document_code) = ?', [strtolower($doc)])
            ->whereRaw('LOWER(target_office) = ?', [strtolower($officeCode)])
            ->exists();
    }

    /**
     * Helper used for office document groups.
     * @param  'all'|'received'|'pending'  $receiptFilter
     * @return list<array{key: string, label: string, count: int}>
     */
    public static function officeDocumentGroups(
        ?int $officeId = null,
        bool $onlyWithDocuments = true,
        string $receiptFilter = 'all'
    ): array {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();
        $onlyFlag = $onlyWithDocuments ? '1' : '0';

        return self::rememberOfficeInventory(
            'groups.v2.' . $receiptFilter . '.' . $onlyFlag . '.' . (int) ($officeId ?? 0),
            function () use ($officeId, $onlyWithDocuments, $receiptFilter) {
                return self::buildOfficeDocumentGroups($officeId, $onlyWithDocuments, $receiptFilter);
            }
        );
    }

    /**
     * Builds or formats data for build office document groups.
     * @return list<array{key: string, label: string, count: int}>
     */
    private static function buildOfficeDocumentGroups(
        ?int $officeId,
        bool $onlyWithDocuments,
        string $receiptFilter
    ): array {
        $groups = [];
        foreach (self::documentGroupDefs() as $key => $label) {
            $count = 0;
            if ($officeId) {
                $count = (int) self::officeDocumentsDistinctQuery($key, (int) $officeId, $receiptFilter)->count();
            }
            if ($onlyWithDocuments && $count < 1) {
                continue;
            }
            $groups[] = [
                'key' => $key,
                'label' => $label,
                'count' => $count,
            ];
        }

        return $groups;
    }

    /**
     * Filter distributed docs by physical receipt status for the office.
     *
     * DEBUG: wrong rows visible → check this scope/filter.
     * @param  'all'|'received'|'pending'  $receiptFilter
     */
    protected static function applyOfficeReceiptFilter($query, int $officeId, string $receiptFilter): void
    {
        $keepAwaitingAppraisal = $receiptFilter === 'received_for_appraisal';
        if ($keepAwaitingAppraisal) {
            $receiptFilter = 'received';
        }

        if ($receiptFilter === 'all' || $officeId < 1) {
            return;
        }
        if (! Schema::hasColumn('dcs_distribution_offices', 'office_received_at')) {
            if ($receiptFilter === 'received') {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $query->whereExists(function ($q) use ($officeId, $receiptFilter) {
            $q->select(DB::raw(1))
                ->from('dcs_document_distribution as dist_rcpt')
                ->join('dcs_distribution_offices as doff_rcpt', 'doff_rcpt.distribution_id', '=', 'dist_rcpt.id')
                ->whereColumn('dist_rcpt.request_id', 'ml.request_id')
                ->where('doff_rcpt.office_id', $officeId);
            if ($receiptFilter === 'received') {
                $q->whereNotNull('doff_rcpt.office_received_at');
            } elseif ($receiptFilter === 'pending') {
                $q->whereNull('doff_rcpt.office_received_at');
            }
        });

        // Received masterlist is the current copy this office holds, not earlier revisions.
        // A copy with a pending DCS appraisal stays on Incoming until that office creates the record.
        if ($receiptFilter === 'received') {
            RegisterQueryHelper::applyLatestRevisionStatus($query, 'ml');
            if ($keepAwaitingAppraisal) {
                self::onlyPendingAppraisal($query, $officeId);
            } else {
                self::excludePendingAppraisal($query, $officeId);
            }
        }

        // A copy this office has already received stays on the masterlist only.
        // Save and Appraise stays off Incoming until the admin forwards the appraisal.
        // Pending badge/list both use latest-only — obsolete unreceived revisions must not inflate the count.
        if ($receiptFilter === 'pending') {
            RegisterQueryHelper::applyLatestRevisionStatus($query, 'ml');
            self::excludeUnforwardedAppraisal($query);
            $query->whereNotExists(function ($q) use ($officeId) {
                $q->select(DB::raw(1))
                    ->from('dcs_document_distribution as dist_got')
                    ->join('dcs_distribution_offices as doff_got', 'doff_got.distribution_id', '=', 'dist_got.id')
                    ->whereColumn('dist_got.request_id', 'ml.request_id')
                    ->where('doff_got.office_id', $officeId)
                    ->whereNotNull('doff_got.office_received_at');
            });
        }
    }

    /**
     * A Save and Appraise registration stays out of Incoming until the admin forwards it.
     */
    protected static function excludeUnforwardedAppraisal($query): void
    {
        if (! Schema::hasColumn('dcs_document_requests', 'appraisal_opt_in')) {
            return;
        }

        $query->whereNotExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('dcs_document_requests as dr_appr')
                ->whereColumn('dr_appr.id', 'ml.request_id')
                ->where('dr_appr.appraisal_opt_in', true);
            if (Schema::hasTable('rdp_received_documents')) {
                $q->whereNotExists(function ($inner) {
                    $inner->select(DB::raw(1))
                        ->from('rdp_received_documents as rdp_appr')
                        ->where('rdp_appr.source_subsystem', 'DCS')
                        ->whereRaw("COALESCE(rdp_appr.metadata->>'dcs_request_id', '') = dr_appr.id::text");
                });
            }
        });
    }

    /**
     * True when this office still has a pending DCS appraisal intake for the document.
     */
    public static function officeHasPendingAppraisalIntake(?string $officeCode, int $requestId, string $docNo = ''): bool
    {
        $officeCode = strtoupper(trim((string) $officeCode));
        if ($officeCode === '' || $requestId < 1 || ! Schema::hasTable('rdp_received_documents')) {
            return false;
        }

        $intakeCode = \App\Services\RdpIntakeService::documentCodeForRequest($requestId, $docNo);
        $docNo = trim($docNo);

        return DB::table('rdp_received_documents')
            ->where('source_subsystem', 'DCS')
            ->where('status', 'pending')
            ->whereRaw('LOWER(target_office) = ?', [strtolower($officeCode)])
            ->where(function ($q) use ($docNo, $intakeCode, $requestId) {
                if ($docNo !== '') {
                    $q->orWhereRaw('LOWER(document_code) = ?', [strtolower($docNo)]);
                }
                $q->orWhereRaw('LOWER(document_code) = ?', [strtolower($intakeCode)])
                    ->orWhereRaw("COALESCE(metadata->>'dcs_request_id', '') = ?", [(string) $requestId]);
            })
            ->exists();
    }

    /**
     * Keep a received copy off the office masterlist while its DCS appraisal is still pending.
     * Applies for every office — Incoming → receive → Appraisal → Masterlist.
     */
    protected static function excludePendingAppraisal($query, int $officeId): void
    {
        $officeCode = self::officeCodeForAppraisalFilter($officeId);
        if ($officeCode === null) {
            return;
        }

        $query->whereNotExists(function ($q) use ($officeCode) {
            self::applyPendingAppraisalExists($q, $officeCode);
        });
    }

    /**
     * Incoming "awaiting appraisal" — only received copies still held by a pending RDP intake.
     */
    protected static function onlyPendingAppraisal($query, int $officeId): void
    {
        $officeCode = self::officeCodeForAppraisalFilter($officeId);
        if ($officeCode === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereExists(function ($q) use ($officeCode) {
            self::applyPendingAppraisalExists($q, $officeCode);
        });
    }

    /**
     * Helper used for office code for appraisal filter.
     */
    protected static function officeCodeForAppraisalFilter(int $officeId): ?string
    {
        if ($officeId < 1 || ! Schema::hasTable('rdp_received_documents')) {
            return null;
        }

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $officeCode = DB::table($officeTbl)->where('id', $officeId)->value('office_code');
        if (! is_string($officeCode) || trim($officeCode) === '') {
            return null;
        }

        return trim($officeCode);
    }

    /**
     * Applies a query filter/scope: apply pending appraisal exists.
     *
     * DEBUG: wrong rows visible → check this scope/filter.
     */
    protected static function applyPendingAppraisalExists($q, string $officeCode): void
    {
            $q->select(DB::raw(1))
                ->from('rdp_received_documents as rdp_hold')
                ->where('rdp_hold.source_subsystem', 'DCS')
                ->where('rdp_hold.status', 'pending')
            ->whereRaw('LOWER(rdp_hold.target_office) = ?', [strtolower($officeCode)])
                ->where(function ($match) {
                    $match->whereColumn('rdp_hold.document_code', 'ml.doc_no')
                        ->orWhereRaw("COALESCE(rdp_hold.metadata->>'dcs_request_id', '') = ml.request_id::text")
                        ->orWhereRaw(
                            "rdp_hold.document_code = CONCAT('DCS-REQ-', ml.request_id::text)"
                        );
        });
    }

    /**
     * Helper used for keep latest distributed documents.
     */
    protected static function keepLatestDistributedDocuments($records)
    {
        $best = [];
        foreach ($records as $ml) {
            $status = strtolower(trim((string) ($ml->revision_status ?? 'latest')));
            if ($status === '') {
                $status = 'latest';
            }
            // Random Check / office lists must only show current (latest) distributed docs.
            if ($status !== 'latest') {
                continue;
            }
            $docNo = strtolower(trim((string) ($ml->doc_no ?? '')));
            $title = strtolower(trim((string) ($ml->doc_title ?? '')));
            $key = $docNo !== '' ? $docNo.'||'.$title : 'id:'.(int) ($ml->id ?? 0);
            $candidate = [
                'row' => $ml,
                'current' => 1,
                'rev' => (int) ($ml->revise_no ?? 0),
                'id' => (int) ($ml->id ?? 0),
            ];
            if (! isset($best[$key]) || self::distributedCopyIsCurrent($candidate, $best[$key])) {
                $best[$key] = $candidate;
            }
        }

        return collect(array_values(array_map(fn (array $item) => $item['row'], $best)))->sortBy(function ($ml) {
            $docNo = trim((string) ($ml->doc_no ?? ''));

            return sprintf('%d-%s-%010d', $docNo === '' ? 1 : 0, $docNo, (int) ($ml->id ?? 0));
        }, SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * Helper used for distributed copy is current.
     */
    protected static function distributedCopyIsCurrent(array $candidate, array $current): bool
    {
        if ($candidate['current'] !== $current['current']) {
            return $candidate['current'] > $current['current'];
        }
        if ($candidate['rev'] !== $current['rev']) {
            return $candidate['rev'] > $current['rev'];
        }

        return $candidate['id'] > $current['id'];
    }

    /**
     * Lists or paginates data for list office documents.
     * @param  'all'|'received'|'pending'|'received_for_appraisal'  $receiptFilter
     * @return list<array{
     */
    public static function listOfficeDocuments(
        string $groupKey,
        ?int $officeId = null,
        string $receiptFilter = 'all',
        ?int $limit = null
    ): array {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();
        if (! $officeId) {
            return [];
        }

        // Hard cap so accidental full scans cannot blow memory on large offices.
        $limit = $limit === null ? null : max(1, min(500, $limit));
        $cacheKey = 'rows.v2.' . $receiptFilter . '.' . ($groupKey === '' ? 'all' : $groupKey)
            . '.' . (int) $officeId . '.' . ($limit ?? 'all');

        return self::rememberOfficeInventory(
            $cacheKey,
            function () use ($groupKey, $officeId, $receiptFilter, $limit) {
                return self::buildOfficeDocumentRows($groupKey, (int) $officeId, $receiptFilter, $limit, 1);
            }
        );
    }

    /**
     * DB-paged office document list (distinct current copy per doc no + title).
     * @param  'all'|'received'|'pending'|'received_for_appraisal'  $receiptFilter
     */
    public static function paginateOfficeDocuments(
        string $groupKey,
        ?int $officeId = null,
        string $receiptFilter = 'all',
        int $perPage = 25,
        ?int $page = null
    ): \Illuminate\Pagination\LengthAwarePaginator {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();
        $perPage = max(10, min(100, $perPage));
        $page = max(1, (int) ($page ?? request()->input('page', 1)));

        if (! $officeId) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, $page);
        }

        $cacheKey = 'page.v2.' . $receiptFilter . '.' . ($groupKey === '' ? 'all' : $groupKey)
            . '.' . (int) $officeId . '.' . $perPage . '.' . $page;

        /** @var array{total:int,page:int,rows:list<array<string,mixed>>} $payload */
        $payload = self::rememberOfficeInventory(
            $cacheKey,
            function () use ($groupKey, $officeId, $receiptFilter, $perPage, $page) {
                $distinct = self::officeDocumentsDistinctQuery($groupKey, (int) $officeId, $receiptFilter);
                $total = (int) (clone $distinct)->count();
                $lastPage = max(1, (int) ceil(max(0, $total) / $perPage));
                $page = min($page, $lastPage);
                $records = (clone $distinct)
                    ->orderByRaw("CASE WHEN COALESCE(TRIM(doc_no), '') = '' THEN 1 ELSE 0 END")
                    ->orderBy('doc_no')
                    ->orderByDesc('revise_no')
                    ->orderBy('id')
                    ->forPage($page, $perPage)
                    ->get();

                return [
                    'total' => $total,
                    'page' => $page,
                    'rows' => self::mapOfficeDocumentRows($records, (int) $officeId, (($page - 1) * $perPage) + 1),
                ];
            },
            60
        );

        $page = max(1, (int) ($payload['page'] ?? $page));

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $payload['rows'] ?? [],
            (int) ($payload['total'] ?? 0),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );
    }

    /**
     * Incoming queue: pending receipts first, then received-awaiting-appraisal — DB-paged.
     * rows: \Illuminate\Pagination\LengthAwarePaginator,
     * total: int,
     * pending_total: int,
     * appraisal_total: int,
     * active_count: int
     * }
     * @return array{
     */
    public static function paginateOfficeIncomingDocuments(
        string $groupKey,
        ?int $officeId = null,
        int $perPage = 25,
        ?int $page = null
    ): array {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();
        $perPage = max(10, min(100, $perPage));
        $page = max(1, (int) ($page ?? request()->input('page', 1)));

        if (! $officeId) {
            $empty = new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, 1);

            return [
                'rows' => $empty,
                'total' => 0,
                'pending_total' => 0,
                'appraisal_total' => 0,
                'active_count' => 0,
            ];
        }

        $cacheKey = 'incoming.page.v2.' . ($groupKey === '' ? 'all' : $groupKey)
            . '.' . (int) $officeId . '.' . $perPage . '.' . $page;

        /** @var array{total:int,page:int,pending_total:int,appraisal_total:int,rows:list<array<string,mixed>>} $payload */
        $payload = self::rememberOfficeInventory(
            $cacheKey,
            function () use ($groupKey, $officeId, $perPage, $page) {
                $pendingTotal = (int) self::officeDocumentsDistinctQuery($groupKey, (int) $officeId, 'pending')->count();
                $appraisalTotal = (int) self::officeDocumentsDistinctQuery($groupKey, (int) $officeId, 'received_for_appraisal')->count();
                $total = $pendingTotal + $appraisalTotal;
                $lastPage = max(1, (int) ceil(max(0, $total) / $perPage));
                $page = min($page, $lastPage);
                $offset = ($page - 1) * $perPage;

                $rows = [];
                $itemStart = $offset + 1;

                if ($offset < $pendingTotal) {
                    $take = min($perPage, $pendingTotal - $offset);
                    $pendingRecords = self::officeDocumentsDistinctQuery($groupKey, (int) $officeId, 'pending')
                        ->orderByRaw("CASE WHEN COALESCE(TRIM(doc_no), '') = '' THEN 1 ELSE 0 END")
                        ->orderBy('doc_no')
                        ->orderByDesc('revise_no')
                        ->orderBy('id')
                        ->offset($offset)
                        ->limit($take)
                        ->get();
                    $rows = array_merge($rows, self::mapOfficeDocumentRows($pendingRecords, (int) $officeId, $itemStart));
                }

                $filled = count($rows);
                if ($filled < $perPage && $appraisalTotal > 0) {
                    $appraisalOffset = max(0, $offset - $pendingTotal);
                    $appraisalTake = $perPage - $filled;
                    $appraisalRecords = self::officeDocumentsDistinctQuery($groupKey, (int) $officeId, 'received_for_appraisal')
                        ->orderByRaw("CASE WHEN COALESCE(TRIM(doc_no), '') = '' THEN 1 ELSE 0 END")
                        ->orderBy('doc_no')
                        ->orderByDesc('revise_no')
                        ->orderBy('id')
                        ->offset($appraisalOffset)
                        ->limit($appraisalTake)
                        ->get();
                    $appraisalRows = self::mapOfficeDocumentRows(
                        $appraisalRecords,
                        (int) $officeId,
                        $itemStart + $filled
                    );
                    $canAppraise = (bool) (auth()->user()?->permissions?->is_sadm
                        || auth()->user()?->permissions?->can_access_rdp);
                    $officeCode = RegisterQueryHelper::currentOfficeCode();
                    $appraisalUrls = \App\Services\RdpIntakeService::appraisalUrlsForReceivedOfficeDocs(
                        $officeCode,
                        $appraisalRows
                    );
                    foreach ($appraisalRows as &$row) {
                        $requestId = (int) ($row['request_id'] ?? 0);
                        $docNo = trim((string) ($row['doc_no'] ?? ''));
                        $url = ($docNo !== '' ? ($appraisalUrls[$docNo] ?? null) : null)
                            ?? ($appraisalUrls['request:'.$requestId] ?? null)
                            ?? ($appraisalUrls[\App\Services\RdpIntakeService::documentCodeForRequest($requestId, $docNo)] ?? null);
                        $row['appraisal_url'] = ($canAppraise && $url) ? $url : null;
                        $row['awaiting_appraisal'] = true;
                        $row['can_receive'] = false;
                    }
                    unset($row);
                    $rows = array_merge($rows, $appraisalRows);
                }

                return [
                    'total' => $total,
                    'page' => $page,
                    'pending_total' => $pendingTotal,
                    'appraisal_total' => $appraisalTotal,
                    'rows' => $rows,
                ];
            },
            45
        );

        $page = max(1, (int) ($payload['page'] ?? $page));
        $total = (int) ($payload['total'] ?? 0);
        $pendingTotal = (int) ($payload['pending_total'] ?? 0);
        $appraisalTotal = (int) ($payload['appraisal_total'] ?? 0);

        return [
            'rows' => new \Illuminate\Pagination\LengthAwarePaginator(
                $payload['rows'] ?? [],
                $total,
                $perPage,
                $page,
                [
                    'path' => request()->url(),
                    'pageName' => 'page',
                ]
            ),
            'total' => $groupKey === '' || $groupKey === 'all'
                ? $total
                : $pendingTotal,
            'pending_total' => $pendingTotal,
            'appraisal_total' => $appraisalTotal,
            'active_count' => $total,
        ];
    }

    /**
     * Filtered masterlist query for one office + receipt/type tab (before distinct).
     */
    private static function officeDocumentsFilteredQuery(
        string $groupKey,
        int $officeId,
        string $receiptFilter
    ) {
        if ($groupKey === '' || $groupKey === 'all') {
            $query = self::officeMasterlistQuery($officeId, null);
        } else {
            if (! isset(self::documentGroupDefs()[$groupKey])) {
                return DB::table('dcs_masterlist_registration as ml')->whereRaw('1 = 0');
            }
            $query = self::applyMasterlistGroupFilter(
                self::officeMasterlistQuery($officeId, self::documentGroupScope($groupKey)),
                $groupKey
            );
        }

        self::applyOfficeReceiptFilter($query, $officeId, $receiptFilter);

        return $query;
    }

    /**
     * One current distributed copy per document number + title (SQL window).
     */
    private static function officeDocumentsDistinctQuery(
        string $groupKey,
        int $officeId,
        string $receiptFilter
    ) {
        $query = self::officeDocumentsFilteredQuery($groupKey, $officeId, $receiptFilter);

        $select = [
                'ml.id',
                'ml.doc_no',
                'ml.revise_no',
                'ml.doc_title',
                'ml.effectivity_date',
        ];
        if (Schema::hasColumn('dcs_masterlist_registration', 'no_pages')) {
            $select[] = 'ml.no_pages';
        }
        if (Schema::hasColumn('dcs_masterlist_registration', 'request_id')) {
            $select[] = 'ml.request_id';
        }
        if (RegisterQueryHelper::supportsRevisionStatus()) {
            $select[] = 'ml.revision_status';
        }

        $driver = Schema::getConnection()->getDriverName();
        $orderSql = $driver === 'pgsql'
            ? 'ml.revise_no DESC NULLS LAST, ml.id DESC'
            : 'ml.revise_no DESC, ml.id DESC';

        $inner = (clone $query)->select(array_merge($select, [
            DB::raw(
                "ROW_NUMBER() OVER (
                    PARTITION BY LOWER(TRIM(COALESCE(ml.doc_no, ''))), LOWER(TRIM(COALESCE(ml.doc_title, '')))
                    ORDER BY {$orderSql}
                ) as ofi_rn"
            ),
        ]));

        return DB::query()->fromSub($inner, 'ofi_ml')->where('ofi_rn', 1);
    }

    /**
     * Builds or formats data for build office document rows.
     * @return list<array<string, mixed>>
     */
    private static function buildOfficeDocumentRows(
        string $groupKey,
        int $officeId,
        string $receiptFilter,
        ?int $limit = null,
        int $itemStart = 1
    ): array {
        if (! $officeId) {
            return [];
        }

        $distinct = self::officeDocumentsDistinctQuery($groupKey, $officeId, $receiptFilter)
            ->orderByRaw("CASE WHEN COALESCE(TRIM(doc_no), '') = '' THEN 1 ELSE 0 END")
            ->orderBy('doc_no')
            ->orderByDesc('revise_no')
            ->orderBy('id');

        if ($limit !== null) {
            $distinct->limit($limit);
        }

        return self::mapOfficeDocumentRows($distinct->get(), $officeId, $itemStart);
    }

    /**
     * Helper used for map office document rows.
     * @param  iterable<int, object>  $records
     * @return list<array<string, mixed>>
     */
    private static function mapOfficeDocumentRows(iterable $records, int $officeId, int $itemStart = 1): array
    {
        $records = collect($records);
        $receipts = self::officeDistributionReceipts(
            $officeId,
            $records->pluck('request_id')->filter()->map(fn ($id) => (int) $id)->unique()->all()
        );

        $rows = [];
        $itemNo = max(1, $itemStart) - 1;
        foreach ($records as $ml) {
            $itemNo++;
            $pagesRaw = $ml->no_pages ?? null;
            $pages = ($pagesRaw !== null && $pagesRaw !== '')
                ? (string) (int) $pagesRaw
                : '';
            $requestId = (int) ($ml->request_id ?? 0);
            $receipt = $receipts[$requestId] ?? null;
            $receivedAt = $receipt['received_at'] ?? null;
            $rows[] = [
                'item_no' => $itemNo,
                'request_id' => $requestId,
                'doc_no' => (string) ($ml->doc_no ?? ''),
                'rev_no' => (int) ($ml->revise_no ?? 0),
                'doc_title' => (string) ($ml->doc_title ?? ''),
                'pages' => $pages,
                'effectivity_date' => $ml->effectivity_date
                    ? RegisterQueryHelper::formatRdpPeriodDate($ml->effectivity_date)
                    : null,
                'can_receive' => $requestId > 0 && $receipt !== null && $receivedAt === null,
                'received_at' => $receivedAt,
                'received_by_name' => (string) ($receipt['received_by_name'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Helper used for office distribution receipts.
     * @param  list<int>  $requestIds
     * @return array<int, array{received_at: string|null, received_by_name: string}>
     */
    protected static function officeDistributionReceipts(int $officeId, array $requestIds): array
    {
        $requestIds = array_values(array_filter($requestIds, static fn (int $id) => $id > 0));
        if ($requestIds === [] || ! Schema::hasColumn('dcs_distribution_offices', 'office_received_at')) {
            return [];
        }

        $rows = DB::table('dcs_document_distribution as dist')
            ->join('dcs_distribution_offices as doff', 'doff.distribution_id', '=', 'dist.id')
            ->whereIn('dist.request_id', $requestIds)
            ->where('doff.office_id', $officeId)
            ->get([
                'dist.request_id',
                'doff.office_received_at',
                'doff.office_received_by',
            ]);

        $names = [];
        $receiverIds = $rows
            ->pluck('office_received_by')
            ->filter(fn ($id) => $id !== null && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($receiverIds !== []) {
            $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
            $names = DB::table($accDetailsTbl)
                ->whereIn('account_id', $receiverIds)
                ->get(['account_id', 'first_name', 'last_name'])
                ->mapWithKeys(fn ($d) => [
                    (int) $d->account_id => trim(trim((string) ($d->first_name ?? '')) . ' ' . trim((string) ($d->last_name ?? ''))),
                ])
                ->all();
        }

        $out = [];
        foreach ($rows as $row) {
            $requestId = (int) $row->request_id;
            $rid = (int) ($row->office_received_by ?? 0);
            $out[$requestId] = [
                'received_at' => $row->office_received_at
                    ? \Carbon\Carbon::parse($row->office_received_at)->format('M d, Y g:i A')
                    : null,
                'received_by_name' => $rid > 0 ? trim((string) ($names[$rid] ?? '')) : '',
            ];
        }

        return $out;
    }

    /**
     * Helper used for mark office document received.
     * @return array{ok: bool, already?: bool, message: string}
     */
    public static function markOfficeDocumentReceived(int $requestId): array
    {
        self::assertCanAccessIntake();

        $officeId = RegisterQueryHelper::currentOfficeId();
        if (! $officeId || $requestId <= 0) {
            return ['ok' => false, 'message' => 'Your office is not assigned to this document.'];
        }
        if (! Schema::hasColumn('dcs_distribution_offices', 'office_received_at')) {
            return ['ok' => false, 'message' => 'Physical receipt is not available yet.'];
        }

        $row = DB::table('dcs_document_distribution as dist')
            ->join('dcs_distribution_offices as doff', 'doff.distribution_id', '=', 'dist.id')
            ->where('dist.request_id', $requestId)
            ->where('doff.office_id', $officeId)
            ->select('doff.id', 'doff.office_received_at', 'doff.office_received_by')
            ->first();

        if (! $row) {
            return ['ok' => false, 'message' => 'This document is not listed for your office.'];
        }

        $ml = Schema::hasTable('dcs_masterlist_registration')
            ? DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first(['doc_title', 'doc_no', 'revise_no'])
            : null;
        $title = trim((string) ($ml->doc_title ?? ''));
        $docNo = trim((string) ($ml->doc_no ?? ''));
        $revNo = isset($ml->revise_no) ? (int) $ml->revise_no : null;

        if (! empty($row->office_received_at)) {
            $who = self::displayNameForUser((int) ($row->office_received_by ?? 0));

            return [
                'ok' => true,
                'already' => true,
                'message' => $who !== ''
                    ? "This document was already received by {$who}."
                    : 'This document was already received by your office.',
            ];
        }

        $userId = (int) (auth()->id() ?? 0);
        DB::table('dcs_distribution_offices')->where('id', $row->id)->update([
            'office_received_at' => now(),
            'office_received_by' => $userId > 0 ? $userId : null,
        ]);

        $receiverName = RegisterQueryHelper::currentUserDisplayName();
        $officeCode = RegisterQueryHelper::currentOfficeCode();
        $appraisalWaiting = false;
        if ($officeCode && Schema::hasTable('rdp_received_documents')) {
            // Every receiving office must appraise — create/keep a pending RDP intake row.
            $appraisalUrl = \App\Services\RdpIntakeService::ensurePendingForOfficeDocument(
                $officeCode,
                $requestId,
                $docNo,
                $title
            );
            $appraisalWaiting = is_string($appraisalUrl) && $appraisalUrl !== '';
            if ($appraisalWaiting) {
                DcsNotificationService::notifyAppraisalStillWaiting(
                    $officeCode,
                    $docNo !== '' ? $docNo : \App\Services\RdpIntakeService::documentCodeForRequest($requestId, $docNo),
                    $title !== '' ? $title : null
                );
            }

            // Do not office-broadcast "marked as received" to distribution / intake offices.
            // Creating offices must not see peer receive notices — RFIU / Document Controllers only.
        }

        DcsNotificationService::notifyAdminOfficeReceivedDocument(
            RegisterQueryHelper::currentOfficeName(),
            $receiverName,
            $title !== '' ? $title : $docNo,
            $docNo !== '' ? $docNo : null,
            $requestId,
            $revNo
        );
        self::bumpOfficeInventoryCache();

        return [
            'ok' => true,
            'already' => false,
            'message' => ! empty($appraisalWaiting)
                ? 'Document marked as received. It stays on Incoming until Appraisal is finished.'
                : 'Document marked as received.',
        ];
    }

    /**
     * Documents for Random Check (by office + optional parent type group).
     * (used by Random Check office audits).
     * masterlist_id: int,
     * doc_no: string,
     * rev_no: int,
     * doc_title: string,
     * effectivity_date: string|null,
     * effectivity_date_raw: string|null,
     * doc_type_key: string,
     * doc_type_label: string
     * }>
     * @param  bool  $distributionOnly  When true, always scope by Document Distribution
     * @return list<array{
     */
    public static function listDocumentsForCheck(
        ?int $officeId = null,
        string $groupKey = 'all',
        bool $distributionOnly = false
    ): array {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();
        if (! $officeId) {
            return [];
        }

        $groupKey = trim($groupKey);
        $cacheKey = 'check.'.($distributionOnly ? 'dist' : 'all').'.'.($groupKey === '' ? 'all' : $groupKey).'.'.(int) $officeId;

        return self::rememberOfficeInventory($cacheKey, function () use ($officeId, $groupKey, $distributionOnly) {
        if ($groupKey === '' || $groupKey === 'all') {
                // One distribution query for the whole office, then classify + keep latest.
                $scope = $distributionOnly ? 'distribution' : null;
                $query = self::officeMasterlistQuery((int) $officeId, $scope);
                $select = [
                    'ml.id',
                    'ml.doc_no',
                    'ml.revise_no',
                    'ml.doc_title',
                    'ml.effectivity_date',
                    'ml.doc_type_id',
                    'ml.request_id',
                ];
                if (RegisterQueryHelper::supportsRevisionStatus()) {
                    $select[] = 'ml.revision_status';
                }
                $records = $query
                    ->orderByRaw("CASE WHEN COALESCE(TRIM(ml.doc_no), '') = '' THEN 1 ELSE 0 END")
                    ->orderBy('ml.doc_no')
                    ->orderByDesc('ml.revise_no')
                    ->orderBy('ml.id')
                    ->get($select);
                $records = self::keepLatestDistributedDocuments($records);
                $parentMap = RegisterQueryHelper::parentTypeIdMap();
                $idToKey = [];
                foreach ($parentMap as $key => $pid) {
                    $idToKey[(int) $pid] = $key;
                }
                $requestIds = $records->pluck('request_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
                $requestParents = [];
                if ($requestIds !== [] && Schema::hasTable('dcs_document_requests')) {
                    $reqRows = DB::table('dcs_document_requests as dr')
                        ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
                        ->whereIn('dr.id', $requestIds)
                        ->get(['dr.id', 'dr.doc_type_id', 'st.parent_id as sub_parent_id']);
                    foreach ($reqRows as $rr) {
                        $parentId = (int) ($rr->sub_parent_id ?: $rr->doc_type_id ?: 0);
                        $requestParents[(int) $rr->id] = $idToKey[$parentId] ?? ($idToKey[(int) ($rr->doc_type_id ?? 0)] ?? 'internal_docs');
                    }
                }
                $rows = [];
                foreach ($records as $ml) {
                    $raw = $ml->effectivity_date ?? null;
                    $typeId = (int) ($ml->doc_type_id ?? 0);
                    $key = $idToKey[$typeId]
                        ?? $requestParents[(int) ($ml->request_id ?? 0)]
                        ?? 'internal_docs';
                    $rows[] = [
                        'masterlist_id' => (int) $ml->id,
                        'doc_no' => (string) ($ml->doc_no ?? ''),
                        'rev_no' => (int) ($ml->revise_no ?? 0),
                        'doc_title' => (string) ($ml->doc_title ?? ''),
                        'effectivity_date' => $raw
                            ? RegisterQueryHelper::formatSmartDate($raw)
                            : null,
                        'effectivity_date_raw' => $raw
                            ? \Carbon\Carbon::parse($raw)->format('Y-m-d')
                            : null,
                        'doc_type_key' => $key,
                        'doc_type_label' => self::documentGroupLabel($key),
                    ];
            }

            return $rows;
        }

        if (! isset(self::documentGroupDefs()[$groupKey])) {
            return [];
        }

        return self::listDocumentsForCheckGroup((int) $officeId, $groupKey, $distributionOnly);
        });
    }

    /**
     * Type counts for an office (distribution recipients when $distributionOnly).
     * @return list<array{key: string, label: string, count: int}>
     */
    public static function officeDocumentGroupsForCheck(?int $officeId = null, bool $distributionOnly = true): array
    {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();
        $groups = [];
        foreach (self::documentGroupDefs() as $key => $label) {
            $count = $officeId
                ? count(self::listDocumentsForCheckGroup((int) $officeId, $key, $distributionOnly))
                : 0;
            $groups[] = [
                'key' => $key,
                'label' => $label,
                'count' => $count,
            ];
        }

        return $groups;
    }

    /**
     * Lists or paginates data for list documents for check group.
     * @return list<array{
     */
    protected static function listDocumentsForCheckGroup(
        int $officeId,
        string $groupKey,
        bool $distributionOnly = false
    ): array {
        $label = self::documentGroupLabel($groupKey);
        $scope = $distributionOnly ? 'distribution' : self::documentGroupScope($groupKey);
        $query = self::applyMasterlistGroupFilter(
            self::officeMasterlistQuery($officeId, $scope),
            $groupKey
        );

        $select = [
                'ml.id',
                'ml.doc_no',
                'ml.revise_no',
                'ml.doc_title',
                'ml.effectivity_date',
        ];
        if (RegisterQueryHelper::supportsRevisionStatus()) {
            $select[] = 'ml.revision_status';
        }

        $records = $query
            ->orderByRaw("CASE WHEN COALESCE(TRIM(ml.doc_no), '') = '' THEN 1 ELSE 0 END")
            ->orderBy('ml.doc_no')
            ->orderByDesc('ml.revise_no')
            ->orderBy('ml.id')
            ->get($select);

        // Same latest-copy rule as office Masterlist — one current doc per number/title.
        $records = self::keepLatestDistributedDocuments($records);

        $rows = [];
        foreach ($records as $ml) {
            $raw = $ml->effectivity_date ?? null;
            $rows[] = [
                'masterlist_id' => (int) $ml->id,
                'doc_no' => (string) ($ml->doc_no ?? ''),
                'rev_no' => (int) ($ml->revise_no ?? 0),
                'doc_title' => (string) ($ml->doc_title ?? ''),
                'effectivity_date' => $raw
                    ? RegisterQueryHelper::formatSmartDate($raw)
                    : null,
                'effectivity_date_raw' => $raw
                    ? \Carbon\Carbon::parse($raw)->format('Y-m-d')
                    : null,
                'doc_type_key' => $groupKey,
                'doc_type_label' => $label,
            ];
        }

        return $rows;
    }

    /**
     * Lists or paginates data for list distributed documents for check.
     */
    public static function listDistributedDocumentsForCheck(?int $officeId = null): array
    {
        return self::listDocumentsForCheck($officeId, 'all', true);
    }
}