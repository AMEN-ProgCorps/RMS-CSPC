<?php

namespace App\Helpers;

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
 * Non-RFIO office DRF/DCN intake: create once, then view/print only.
 * Fields mirror Document Registration sections 1 (DRF) and 2 (DCN).
 * DCS file: OfficeIntakeHelper in app/ so Composer autoloads it.
 * Office DRF and DCN intake. The office creates the form once. Print stays locked until RFIO sets rfio_print_notified_at. A registration notifies that office only when the saved document matches the intake.
 * Do not move this class back under resources/views.
 * Public methods:
 * intakeTable, canAccessIntake, assertCanAccessIntake, assertOfficeIntakePrintAllowed,
 * officeMayPrintIntake, officeIntakePrintReadyIds, assertOwnsDrf, assertOwnsDcn,
 * rejectMutation, listMyDrf, listMyDcn, listPendingOfficeRequests, requestReviewPayload,
 * registrationTarget, registerContinueUrl, isIntakeClaimedPending, rfioOpenIntakeUrl,
 * findOfficeDrf, findOfficeDcn, drfSourceOffices, dcnSourceOffices, dcnRevisions,
 * findLatestRevisableRegistration, assertRegisteredRevisableDocNo,
 * searchRevisableDocuments, findRevisableRegistrationMatch, resolveOfficeDcnDocument,
 * resolveOfficeDrfRecurDocument, pendingOfficeRequestCounts, drfPrefillFromDcn, latestRegistrationForDocNo,
 * distributionOfficeIdsForRequest, linkedDrfIdsForOfficeDcns, findLinkedDrfIdForOfficeDcn,
 * findSourceDcnIdForOfficeDrf, officeDcnHasLinkedDrf, officeDcnAllowsLaterDrf,
 * assertOfficeDcnCanCreateDrf, findOpenDuplicateDcn, duplicateDcnMessage,
 * assertNoOpenDuplicateDcn, assertNoOpenDuplicateDrf, currentUserOfficeForDcn,
 * originatorMatchesUser, storeDrf, previewDrf, previewDcn, storeDcn, updateDrf, updateDcn,
 * reviewersPayloadFromRequest, loadDcnReviewers, syncDcnReviewers, loadDcnApprovals,
 * syncDcnApprovals, decodeDcnApprovals, approvalsPayloadFromRequest,
 * departmentDateForPrint, parseDepartmentDate, parseIntakeNotificationUrl,
 * isIntakeRegistered, syncRegisteredIntakeLink, canOfficeEditIntake, intakeEditState,
 * beginRegister, pendingRegisterIntake, explicitRegisterIntake,
 * reconcileClaimedIntakesWithRegistrations, registrationMatchesIntake,
 * closePendingIntakeForRegistration, clearPendingRegisterIntake, markIntakeRegistered,
 * intakeSubmitterOfficeCodes, unlockForEdit, notifyReadyForPrintSign,
 * intakePrintNotifiedState, intakeSubmissionMeta, officeLabelForUser, displayNameForUser,
 * modalPayload, distributeOfficeIds, syncDistributeOffices, drfDistributeOffices,
 * mergeDistributeOfficeBackup, intakeReceivedState, markReceived, clearReceived, registerPrefillForDrf,
 * registerPrefillForDcn, documentGroupDefs, documentGroupScope, documentGroupKeysForScope,
 * documentGroupLabel, docTypeIdsForGroup, dcnDocumentGroupKeys, officeDocumentTotal,
 * officeDocumentGroups, listOfficeDocuments, markOfficeDocumentReceived,
 * listDocumentsForCheck, officeDocumentGroupsForCheck, listDistributedDocumentsForCheck.
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

    public static function intakeTable(string $type): string
    {
        return $type === 'dcn' ? self::DCN_TABLE : self::DRF_TABLE;
    }

    public static function canAccessIntake(): bool
    {
        return RegisterQueryHelper::canAccessOfficeIntake();
    }

    public static function assertCanAccessIntake(): void
    {
        abort_unless(self::canAccessIntake(), 403, 'You do not have access to office DRF/DCN intake.');
    }

    /** Print/view form: submitting office always; RFIO reviewers may open for review. */
    public static function assertOfficeIntakePrintAllowed(): void
    {
        // Allowed for office creators and RFIO review_intake operators.
    }

    /** Office users print only after RFIO sends the print-and-sign notice. */
    public static function officeMayPrintIntake(?object $record): bool
    {
        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            return true;
        }

        return $record !== null && ! empty($record->rfio_print_notified_at);
    }

    /**
     * Ids the current office user may print, for a live unlock on an open form.
     *
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
     * Limit My DRF/DCN to forms linked to the current office, or forms the user created
     * that have no source-office links yet. RFIO browse-all skips this.
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

    public static function assertOwnsDrf(object $drf): void
    {
        abort_unless(
            self::userCanAccessOfficeIntakeRecord($drf, 'drf'),
            403,
            'You can only view Document Request Forms from your office.'
        );
    }

    public static function assertOwnsDcn(object $dcn): void
    {
        abort_unless(
            self::userCanAccessOfficeIntakeRecord($dcn, 'dcn'),
            403,
            'You can only view Document Change Notices from your office.'
        );
    }

    public static function rejectMutation(): RedirectResponse
    {
        return redirect()->back()->with('error', self::IMMUTABLE_MESSAGE);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function listMyDrf()
    {
        if (! Schema::hasTable('dcs_office_intake_drf')) {
            return collect();
        }

        $cols = [
            'drf.id',
            'drf.drf_no',
            'drf.drf_date',
            'drf.doc_title',
            'drf.created_at',
            'drf.created_by',
        ];
        if (Schema::hasColumn('dcs_office_intake_drf', 'originator_name')) {
            $cols[] = 'drf.originator_name';
        }
        if (Schema::hasColumn('dcs_office_intake_drf', 'rfio_received_at')) {
            $cols[] = 'drf.rfio_received_at';
        }
        if (Schema::hasColumn('dcs_office_intake_drf', 'rfio_registered_at')) {
            $cols[] = 'drf.rfio_registered_at';
        }
        if (Schema::hasColumn('dcs_office_intake_drf', 'rfio_claimed_at')) {
            $cols[] = 'drf.rfio_claimed_at';
        }
        if (Schema::hasColumn('dcs_office_intake_drf', 'registered_request_id')) {
            $cols[] = 'drf.registered_request_id';
        }
        if (Schema::hasColumn('dcs_office_intake_drf', 'rfio_print_notified_at')) {
            $cols[] = 'drf.rfio_print_notified_at';
        }

        self::reconcileClaimedIntakesWithRegistrations();

        $query = DB::table('dcs_office_intake_drf as drf');
        self::applyOwnOfficeIntakeScope($query, 'drf', 'drf');

        $rows = $query->orderByDesc('drf.id')->get($cols);

        return $rows->map(function ($row) {
            $meta = self::intakeRegistrationMeta('drf', $row);
            $row->is_registered = $meta['is_registered'];
            $row->registered_doc_no = $meta['doc_no'];
            $row->registered_doc_title = $meta['doc_title'];
            $submission = self::intakeSubmissionMeta($row, 'drf', (int) $row->id);
            $row->submitting_office = $submission['office'];
            $row->submitter_name = $submission['submitter'];

            return $row;
        });
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function listMyDcn()
    {
        if (! Schema::hasTable('dcs_office_intake_dcn')) {
            return collect();
        }

        $cols = array_values(array_filter([
            'dcn.id',
            'dcn.dcn_no',
            'dcn.dcn_date',
            Schema::hasColumn('dcs_office_intake_dcn', 'originator_name') ? 'dcn.originator_name' : null,
            Schema::hasColumn('dcs_office_intake_dcn', 'document_title') ? 'dcn.document_title' : null,
            Schema::hasColumn('dcs_office_intake_dcn', 'document_no') ? 'dcn.document_no' : null,
            'dcn.created_at',
            'dcn.created_by',
            Schema::hasColumn('dcs_office_intake_dcn', 'rfio_received_at') ? 'dcn.rfio_received_at' : null,
            Schema::hasColumn('dcs_office_intake_dcn', 'rfio_registered_at') ? 'dcn.rfio_registered_at' : null,
            Schema::hasColumn('dcs_office_intake_dcn', 'rfio_claimed_at') ? 'dcn.rfio_claimed_at' : null,
            Schema::hasColumn('dcs_office_intake_dcn', 'registered_request_id') ? 'dcn.registered_request_id' : null,
            Schema::hasColumn('dcs_office_intake_dcn', 'expects_drf') ? 'dcn.expects_drf' : null,
            Schema::hasColumn('dcs_office_intake_dcn', 'rfio_print_notified_at') ? 'dcn.rfio_print_notified_at' : null,
        ]));

        self::reconcileClaimedIntakesWithRegistrations();

        $query = DB::table('dcs_office_intake_dcn as dcn');
        self::applyOwnOfficeIntakeScope($query, 'dcn', 'dcn');

        $rows = $query->orderByDesc('dcn.id')->get($cols);
        $linkedDrfIds = self::linkedDrfIdsForOfficeDcns(
            $rows->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        return $rows->map(function ($row) use ($linkedDrfIds) {
            $meta = self::intakeRegistrationMeta('dcn', $row);
            $row->is_registered = $meta['is_registered'];
            $row->registered_doc_no = $meta['doc_no'];
            $row->registered_doc_title = $meta['doc_title'];
            $submission = self::intakeSubmissionMeta($row, 'dcn', (int) $row->id);
            $row->submitting_office = $submission['office'];
            $row->submitter_name = $submission['submitter'];
            $row->linked_drf_id = $linkedDrfIds[(int) $row->id] ?? null;

            return $row;
        });
    }

    /**
     * Pending office-intake DRF + DCN for RFIO Request module (excludes registered).
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
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
     *
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

    /** Review payload for Request module show page. */
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
     *
     * @return array{registerType: string, intakeType: string, intakeId: int, registerUrl: string}
     */
    public static function registrationTarget(string $type, int $id): array
    {
        $type = strtolower($type);
        $intakeType = $type;
        $intakeId = $id;
        $registerType = 'new';

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
                }
            }
        }

        return [
            'registerType' => $registerType,
            'intakeType' => $intakeType,
            'intakeId' => $intakeId,
            'registerUrl' => route('dcs.register.create', [
                'type' => $registerType,
                'intake' => $intakeType,
                'intake_id' => $intakeId,
            ], absolute: false),
        ];
    }

    /** Register URL for office intake Proceed / resume after claim. */
    public static function registerContinueUrl(string $type, int $id): string
    {
        return self::registrationTarget($type, $id)['registerUrl'];
    }

    /** True when RFIO started registration (Proceed) but has not finished registering. */
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
     * Where RFIO should land when opening an intake from a notification / deep link.
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

    /** @return array{is_registered: bool, doc_no: string, doc_title: string} */
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

    public static function findOfficeDrf(int $id): ?object
    {
        if (! Schema::hasTable('dcs_office_intake_drf')) {
            return null;
        }

        return DB::table('dcs_office_intake_drf')
            ->where('id', $id)
            ->first();
    }

    public static function findOfficeDcn(int $id): ?object
    {
        if (! Schema::hasTable('dcs_office_intake_dcn')) {
            return null;
        }

        return DB::table('dcs_office_intake_dcn')
            ->where('id', $id)
            ->first();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function drfSourceOffices(int $drfId)
    {
        return DB::table('dcs_office_drf_offices as d')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
            ->where('d.office_intake_drf_id', $drfId)
            ->orderBy('o.office_name')
            ->get(['d.office_id', 'o.office_name', 'o.office_code']);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function dcnSourceOffices(int $dcnId)
    {
        return DB::table('dcs_office_dcn_offices as d')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
            ->where('d.office_intake_dcn_id', $dcnId)
            ->orderBy('o.office_name')
            ->get(['d.office_id', 'o.office_name', 'o.office_code']);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
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
     * @return object Latest revisable registration
     *
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
     * Finder results for office DCN / recurring DRF.
     * Always returns a selectable list (exact match first, then related on the same number).
     * The client must not auto-select — the user picks from the list.
     *
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
        $matches = [];
        $matched = self::findRevisableRegistrationMatch($docNo, $title, $originator);
        if ($matched) {
            $matches[] = self::revisableMatchPayload($matched, false, $exceptId);
        }
        foreach (self::nearRevisableMatches($docNo, $title, $originator, $exceptId) as $near) {
            $matches[] = $near;
        }

        return [
            'found' => $matches !== [],
            'matches' => $matches,
            'suggestions' => $matches,
        ];
    }

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
        if (Schema::hasTable('dcs_originators')) {
            $query->leftJoin('dcs_originators as og', 'og.id', '=', 'ml.originator_id');
        }
        $originSql = Schema::hasTable('dcs_originators')
            ? "LOWER(TRIM(COALESCE(og.originator_name, '')))"
            : "''";
        $originDisplay = Schema::hasTable('dcs_originators')
            ? "TRIM(COALESCE(og.originator_name, ''))"
            : "''";
        if (Schema::hasColumn('dcs_masterlist_registration', 'originator_name')) {
            $originSql = Schema::hasTable('dcs_originators')
                ? "LOWER(TRIM(COALESCE(NULLIF(og.originator_name, ''), ml.originator_name, '')))"
                : "LOWER(TRIM(COALESCE(ml.originator_name, '')))";
            $originDisplay = Schema::hasTable('dcs_originators')
                ? "TRIM(COALESCE(NULLIF(og.originator_name, ''), ml.originator_name, ''))"
                : "TRIM(COALESCE(ml.originator_name, ''))";
        }
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

    /** Office DCN revises the latest row only. */
    private static function excludeObsoleteRevisions($query): void
    {
        if (! RegisterQueryHelper::supportsRevisionStatus()) {
            return;
        }

        $query->whereRaw("LOWER(COALESCE(NULLIF(TRIM(ml.revision_status::text), ''), 'latest')) <> 'obsolete'");
    }

    /**
     * Same document number, with a title or originator close to what was typed.
     *
     * @return list<array<string, mixed>>
     */
    private static function nearRevisableMatches(string $docNo, string $title, string $originator, ?int $exceptId = null): array
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
        if (Schema::hasTable('dcs_originators')) {
            $query->leftJoin('dcs_originators as og', 'og.id', '=', 'ml.originator_id');
        }
        $originDisplay = Schema::hasTable('dcs_originators')
            ? "TRIM(COALESCE(og.originator_name, ''))"
            : "''";
        if (Schema::hasColumn('dcs_masterlist_registration', 'originator_name')) {
            $originDisplay = Schema::hasTable('dcs_originators')
                ? "TRIM(COALESCE(NULLIF(og.originator_name, ''), ml.originator_name, ''))"
                : "TRIM(COALESCE(ml.originator_name, ''))";
        }
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
            $suggestions[] = self::revisableMatchPayload($picked['row'], false, $exceptId);
            if (count($suggestions) >= 8) {
                break;
            }
        }

        return $suggestions;
    }

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
     *
     * @return list<string>
     */
    private static function completedNameWords(string $typed): array
    {
        $parts = preg_split('/[\s.]+/u', mb_strtolower(trim($typed))) ?: [];

        return array_values(array_filter($parts, fn ($word) => mb_strlen($word) >= 2));
    }

    /** @param  list<string>  $words */
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

    private static function rowAllowsDcnRevision(object $row): bool
    {
        return RegisterQueryHelper::supportsAllowsRevisionColumn()
            && property_exists($row, 'allows_revision')
            && $row->allows_revision !== null
            ? (bool) $row->allows_revision
            : RegisterQueryHelper::effectiveTypeAllowsRevision($row->doc_type_id ?? null, $row->sub_type_id ?? null);
    }

    /** @return array<string, mixed> */
    private static function revisableMatchPayload(object $row, bool $found, ?int $exceptId = null): array
    {
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
        ];
        $open = self::findOpenDuplicateDcn(
            (string) ($row->doc_no ?? ''),
            (string) ($row->doc_title ?? ''),
            '',
            [trim((string) ($row->originator_label ?? ''))],
            $exceptId
        );
        if ($open) {
            $payload['open_dcn_id'] = (int) $open->id;
            $payload['open_request_message'] = self::duplicateDcnMessage($open);
        }
        if ($found) {
            $payload = ['found' => true] + $payload;
        }

        return $payload;
    }

    /** @return list<array{position: string, name: string}> */
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
     *
     * @return array{
     *   previous_document_no: string,
     *   matched_title: string,
     *   matched_originator: string,
     *   masterlist_id: int,
     *   request_id: int,
     *   doc_type_kind: string,
     *   distribute_office_ids: list<int>
     * }
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

        return [
            'previous_document_no' => $previous,
            'matched_title' => $matchedTitle,
            'matched_originator' => trim((string) ($matched->originator_label ?? '')),
            'masterlist_id' => (int) ($matched->ml_id ?? 0),
            'request_id' => $requestId,
            'doc_type_kind' => $kind,
            'distribute_office_ids' => self::distributionOfficeIdsForRequest($requestId),
        ];
    }

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
        if (Schema::hasTable('dcs_originators')) {
            $query->leftJoin('dcs_originators as og', 'og.id', '=', 'ml.originator_id');
        }
        $originDisplay = Schema::hasTable('dcs_originators')
            ? "TRIM(COALESCE(og.originator_name, ''))"
            : "''";
        if (Schema::hasColumn('dcs_masterlist_registration', 'originator_name')) {
            $originDisplay = Schema::hasTable('dcs_originators')
                ? "TRIM(COALESCE(NULLIF(og.originator_name, ''), ml.originator_name, ''))"
                : "TRIM(COALESCE(ml.originator_name, ''))";
        }
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
     * Office DRF and DCN still waiting in the Request queue.
     *
     * @return array{drf: int, dcn: int}
     */
    public static function pendingOfficeRequestCounts(): array
    {
        return [
            'drf' => self::countOpenIntake('dcs_office_intake_drf'),
            'dcn' => self::countOpenIntake('dcs_office_intake_dcn'),
        ];
    }

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
     *
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
        if ($registered) {
            $typeName = trim((string) ($registered->doc_type_name ?? ''));
            $subName = trim((string) ($registered->sub_type_name ?? ''));
            $label = $subName !== '' ? $subName : $typeName;
            if (str_contains(mb_strtolower($typeName.' '.$subName), 'external')) {
                $kind = 'external';
            }
            $officeIds = self::distributionOfficeIdsForRequest((int) ($registered->request_id ?? 0));
        }

        return [
            'drfTitle' => $title,
            'originatorName' => trim((string) ($dcn->originator_name ?? '')),
            'descriptionReason' => '',
            'from_dcn' => $dcnId,
            'docTypeKind' => $kind,
            'docTypeLabel' => $label,
            'lockDocType' => $registered !== null,
            'distributeOfficeIds' => $officeIds,
        ];
    }

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

    /** @return list<int> */
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

    /** Linked office DRF id for this office DCN, if any. */
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

    /** Source office DCN id for this office DRF, if linked. */
    public static function findSourceDcnIdForOfficeDrf(int $drfId): ?int
    {
        if ($drfId < 1 || ! Schema::hasColumn('dcs_office_intake_drf', 'source_office_dcn_id')) {
            return null;
        }

        $query = DB::table('dcs_office_intake_drf')->where('id', $drfId);

        $dcnId = (int) ($query->value('source_office_dcn_id') ?: 0);

        return $dcnId > 0 ? $dcnId : null;
    }

    public static function officeDcnHasLinkedDrf(int $dcnId): bool
    {
        return self::findLinkedDrfIdForOfficeDcn($dcnId) !== null;
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function officeDcnAllowsLaterDrf(int $dcnId): bool
    {
        if ($dcnId < 1 || ! Schema::hasColumn('dcs_office_intake_dcn', 'expects_drf')) {
            return false;
        }

        return (bool) DB::table('dcs_office_intake_dcn')->where('id', $dcnId)->value('expects_drf');
    }

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
     * Another office DCN for this registered document that RFIO has not registered yet.
     *
     * @param  list<string>  $originators
     */
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

        foreach ($query->orderBy('id')->get(['id', 'document_no', 'document_title', 'originator_name']) as $row) {
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

    public static function duplicateDcnMessage(object $row): string
    {
        $no = trim((string) ($row->document_no ?? ''));
        $title = trim((string) ($row->document_title ?? ''));
        $label = trim($no.($title !== '' ? ' — '.$title : ''));

        return 'A Document Change Notice for '.($label !== '' ? $label : 'this document')
            .' is already submitted and still waiting to be registered. Open DCN #'.(int) $row->id.'.';
    }

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
            ->whereRaw('LOWER(TRIM(COALESCE(doc_title, \'\'))) = ?', [$title])
            ->whereRaw('LOWER(TRIM(COALESCE(originator_name, \'\'))) = ?', [$originator]);
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

    /** @return array{id: int|null, code: string, name: string, label: string} */
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

    public static function originatorMatchesUser(?string $originatorName, ?int $originatorAccountId = null): bool
    {
        return RegisterQueryHelper::originatorMatchesCurrentUser($originatorName, $originatorAccountId);
    }

    private static function mergeJoinedOriginator(Request $request): void
    {
        $request->merge([
            'originatorName' => RegisterPersistHelper::joinedOriginatorNames($request->input('originatorName')),
        ]);
    }

    public static function bumpOfficeInventoryCache(): void
    {
        try {
            $version = (int) Cache::get('dcs.ofi.inventory.version', 1);
            Cache::forever('dcs.ofi.inventory.version', $version + 1);
        } catch (\Throwable) {
        }
    }

    private static function officeInventoryCacheVersion(): int
    {
        try {
            return (int) Cache::get('dcs.ofi.inventory.version', 1);
        } catch (\Throwable) {
            return 1;
        }
    }

    private static function rememberOfficeInventory(string $suffix, callable $loader, int $ttl = 20): mixed
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
            'docTypeKind' => 'required|in:internal,external',
            'descriptionReason' => 'required|string|max:5000',
            'distributeToOffice' => 'required|array|min:1',
            'distributeToOffice.*' => 'required|integer',
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
            // Prefer registered Internal/External when the finder resolved a match.
            if (! empty($resolved['doc_type_kind'])) {
                $data['docTypeKind'] = $resolved['doc_type_kind'];
            }
            // Keep the DRF title aligned with the selected document family unless the office typed another title.
            if ($previousTitle !== '' && trim((string) ($data['drfTitle'] ?? '')) === '') {
                $data['drfTitle'] = $previousTitle;
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
            $previousRequestId
        ) {
                $row = [
                    'drf_no' => null,
                    'drf_date' => $data['drfDate'] ?? null,
                    'doc_title' => $data['drfTitle'],
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'prepared_by_name' => trim((string) ($data['preparedByName'] ?? '')) ?: null,
                    'originator_name' => trim((string) ($data['originatorName'] ?? '')) ?: null,
                    'doc_type_kind' => $data['docTypeKind'] ?? null,
                    'description_reason' => trim((string) ($data['descriptionReason'] ?? '')) ?: null,
                ];

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

    public static function previewDrf(Request $request)
    {
        self::assertCanAccessIntake();
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('distributeToOffice', []))));
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
            'preview_distribute_offices' => self::officesForPreview($ids),
        ];
        $logoSrc = self::printLogoSrc();
        $sourceOffices = collect();
        $viewerMode = false;
        $allowPrint = false;

        return response()->view('pages.dcs.office.drf-print', compact('drf', 'logoSrc', 'sourceOffices', 'viewerMode', 'allowPrint'));
    }

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

        return response()->view('pages.dcs.office.dcn-print', compact('dcn', 'logoSrc', 'revisions', 'sourceOffices', 'viewerMode', 'allowPrint'));
    }

    /** @param  list<int>  $ids
     *  @return list<array{office_id: int, code: string, name: string}>
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

    private static function printLogoSrc(): string
    {
        $logoPath = public_path('images/logo.png');

        return file_exists($logoPath) ? ('data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath))) : '';
    }

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
                    'originator_name' => trim((string) ($data['originatorName'] ?? '')) ?: null,
                    'department_on' => $data['departmentDate'] ?? null,
                ];
                if (Schema::hasColumn('dcs_office_intake_dcn', 'previous_document_no')) {
                    $row['previous_document_no'] = $previousDocNo !== '' ? $previousDocNo : null;
                }
                if (Schema::hasColumn('dcs_office_intake_dcn', 'expects_drf')) {
                    $row['expects_drf'] = $expectsDrf;
                }

                $dcnId = DB::table('dcs_office_intake_dcn')->insertGetId($row);

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
            'docTypeKind' => 'required|in:internal,external',
            'descriptionReason' => 'required|string|max:5000',
            'distributeToOffice' => 'required|array|min:1',
            'distributeToOffice.*' => 'required|integer',
            'preparedByName' => 'required|string|max:255',
            'preparedByDesignation' => 'required|string|max:255',
            'reviewedByName' => 'required|string|max:255',
            'reviewedByDesignation' => 'required|string|max:255',
            'approvedByName' => 'required|string|max:255',
            'approvedByDesignation' => 'required|string|max:255',
            'confirmDataCorrect' => 'accepted',
        ]);

        self::assertNoOpenDuplicateDrf(
            (string) ($data['drfTitle'] ?? ''),
            (string) ($data['originatorName'] ?? ''),
            $id
        );

        $now = now();
        DB::transaction(function () use ($data, $id, $now) {
            $row = [
                'drf_date' => $data['drfDate'] ?? null,
                'doc_title' => $data['drfTitle'],
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('dcs_office_intake_drf', 'originator_name')) {
                $row['originator_name'] = trim((string) ($data['originatorName'] ?? '')) ?: null;
            }
            if (Schema::hasColumn('dcs_office_intake_drf', 'doc_type_kind')) {
                $row['doc_type_kind'] = $data['docTypeKind'] ?? null;
            }
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

            $affected = DB::table('dcs_office_intake_drf')
                ->where('id', $id)
                ->update($row);
            abort_if($affected < 1, 404, 'Document Request Form not found.');
        });

        DcsNotificationService::dismissOfficeIntakeNotifications('drf', $id);

        // Always notify Document Controllers / RFIO review queue.
        // Limited RFOIU staff also use office intake — do not skip when submitter office is RFIO/RFOIU.
        DcsNotificationService::notifyOfficeIntakeResubmitted(
            RegisterQueryHelper::rfioNotificationOfficeCode(),
            RegisterQueryHelper::currentUserDisplayName(),
            'drf',
            $id,
            (string) ($data['drfTitle'] ?? '')
        );

        return redirect()
            ->route('dcs.office.drf.show', $id)
            ->with('success', 'Document Request Form updated and resubmitted to RFIO. This document is locked again.')
            ->with('locked', true);
    }

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
                $row['originator_name'] = trim((string) ($data['originatorName'] ?? '')) ?: null;
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

            $affected = DB::table('dcs_office_intake_dcn')
                ->where('id', $id)
                ->update($row);
            abort_if($affected < 1, 404, 'Document Change Notice not found.');

            self::syncDcnReviewers($id, $reviewers);
            self::syncDcnApprovals($id, $approvals);
        });

        DcsNotificationService::dismissOfficeIntakeNotifications('dcn', $id);

        // Always notify Document Controllers / RFIO review queue.
        // Limited RFOIU staff also use office intake — do not skip when submitter office is RFIO/RFOIU.
        DcsNotificationService::notifyOfficeIntakeResubmitted(
            RegisterQueryHelper::rfioNotificationOfficeCode(),
            RegisterQueryHelper::currentUserDisplayName(),
            'dcn',
            $id,
            $docTitle !== '' ? $docTitle : $docNo
        );

        return redirect()
            ->route('dcs.office.dcn.show', $id)
            ->with('success', 'Document Change Notice updated and resubmitted to RFIO. This document is locked again.')
            ->with('locked', true);
    }


    /** Format Reviewed by display: name only (dates are left blank for wet-ink). */
    private static function formatReviewedByLabel(?string $name, ?string $date = null): ?string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{name: string, date: string|null}>
     */
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
     * Last DRF signatories for the current office / creator (for create-form suggestions).
     *
     * @return array{
     *     prepared_by_name: string,
     *     prepared_by_designation: string,
     *     reviewed_by_name: string,
     *     reviewed_by_designation: string,
     *     approved_by_name: string,
     *     approved_by_designation: string
     * }|null
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
     *
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
     *
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

    /** Office code plus the department date, joined for the printed DCN line. */
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
     *
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

    /** @return array{type: string, id: int}|null */
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
            // Success notices use ?registered=1 — not an open RFIO review item.
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

    public static function isIntakeRegistered(string $type, int $id): bool
    {
        $type = strtolower($type);
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        if (! $record) {
            return true;
        }

        if (! empty($record->rfio_registered_at)) {
            if ((int) ($record->request_id ?? 0) > 0) {
                self::detachIntakeFromControlledRequest($type, $id);
            }

            return true;
        }

        return (int) ($record->registered_request_id ?? 0) > 0;
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
     * (created at/after the intake), used to close orphaned RFIO review items.
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
     * Remember which office intake is being registered (survives form remorph / missing hiddens).
     * Claims the Request-queue item so it leaves the RFIO Request list immediately.
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

        return [
            'ok' => true,
            'type' => $target['intakeType'],
            'id' => $target['intakeId'],
            'registerType' => $target['registerType'],
            'registerUrl' => $target['registerUrl'],
        ];
    }

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

    /** @return array{type: string, id: int}|null */
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
     * The office intake this save is actually registering.
     * A DRF/DCN source unit on a document the admin created is not enough —
     * the form must be the one posted from Proceed to Registration, which is
     * the row in My DRF / My DCN.
     *
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
     * exists from the moment RFIO started registration.
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

    /** @return list<int> */
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

    /** @param  list<string>  $docNos */
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

    /** @return array{0: string, 1: ?string} */
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
     *
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
     * The saved registration is this office's DCN/DRF only when its document
     * number (or the document it revises) or its title is the one they submitted.
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
     *
     * @return list<string> submitting offices that were notified
     */
    public static function closePendingIntakeForRegistration(
        string $type,
        int $id,
        int $requestId,
        string $docNo,
        ?string $docTitle = null,
        ?string $revisedFromDocNo = null,
        bool $deferRegisteredNotify = false
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

        if (! self::registrationMatchesIntake($type, $record, $docNo, $docTitle, $revisedFromDocNo)) {
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

        return collect($codes)
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public static function clearPendingRegisterIntake(): void
    {
        session()->forget('dcs_office_intake_pending');
        cookie()->queue(cookie()->forget('dcs_office_intake_pending'));
    }

    /** Return a claimed intake to the RFIO queue when this registration is not its document. */
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
     * After RFIO registers a document from office intake: link the intake row,
     * remove RFIO submit notifications, and notify only the submitting office
     * ("Your DCN/DRF…"). Distribution offices are notified separately.
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

        if (! $notifyOffice || $deferRegisteredNotify) {
            return;
        }

        self::sendIntakeRegisteredNotification($type, $intakeId, $record, $docNo, $docTitle);
    }

    /**
     * After RDP appraisal finishes for a DCS registration that came from office intake,
     * notify the office that created the DRF/DCN (deferred from register time).
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
     * Office code(s) for the account that created the office intake form.
     *
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

        // Include RFIO/RFOIU when the submitter belongs there (limited intake staff).
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
     * RFIO returns an incorrect office intake so the submitting office can edit it.
     *
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

        $formLabel = $type === 'dcn' ? 'Document Change Notice' : 'Document Request Form';
        $title = trim((string) ($type === 'dcn' ? ($record->document_title ?? '') : ($record->doc_title ?? '')));
        $titlePart = $title !== '' ? " \"{$title}\"" : '';
        $message = "RFIO returned your {$formLabel}{$titlePart} for correction: {$reason}. You can edit and resubmit it now.";
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
     * RFIO confirms the electronic submission is correct — notify the office to print, sign, and bring the hard copy.
     *
     * @return array<string, mixed>
     */
    public static function notifyReadyForPrintSign(string $type, int $id): array
    {
        abort_unless(RegisterQueryHelper::canBrowseAllOfficeIntake(), 403);

        $type = strtolower($type);
        abort_unless(in_array($type, ['drf', 'dcn'], true), 404);
        $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : 'dcs_office_intake_drf';
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        abort_unless($record, 404);
        abort_if(self::isIntakeRegistered($type, $id), 422, 'This submission is already registered.');

        $title = trim((string) ($type === 'dcn' ? ($record->document_title ?? '') : ($record->doc_title ?? '')));
        $officeCodes = self::intakeOfficeCodes($type, $id, $record);
        abort_if($officeCodes === [], 422, 'No submitting office found to notify.');

        $now = now();
        $userId = (int) auth()->id();
        if (Schema::hasColumn($table, 'rfio_print_notified_at')) {
            DB::table($table)->where('id', $id)->update([
                'rfio_print_notified_at' => $now,
                'rfio_print_notified_by' => $userId > 0 ? $userId : null,
                'updated_at' => $now,
            ]);
        }

        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        $peer = self::linkedIntakePeer($type, $id);
        $peerNotified = $peer !== null && ! empty($peer['record']->rfio_print_notified_at ?? null);
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
            'pairComplete' => $peer !== null && $peerNotified,
            'pairLabel' => $peer ? strtoupper((string) $peer['type']) : '',
        ], self::intakePrintNotifiedState($record));
    }

    /** @return array{type: string, id: int, record: object}|null */
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

    /** @return list<string> */
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

        // Prefer non-RFIO client offices. If the submitter is in RFIO/RFOIU (same office
        // as Document Controllers), still notify that office — limited-DCS staff see it;
        // full Document Controllers have client notices filtered from their bell.
        $withoutRfio = $codes
            ->reject(fn ($code) => in_array(strtoupper(trim((string) $code)), $rfioCodes, true))
            ->values();

        return $withoutRfio->isNotEmpty() ? $withoutRfio->all() : $codes->all();
    }

    /** @return array{office: string, submitter: string, submittedAt: string} */
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

    /** @return array<string, mixed>|null */
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

    /** @return list<int> */
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

    /** @param  array<int|string|null>  $officeIds */
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

    /** @return array<int, array{office_id: int, code: string, name: string}> */
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

    /** @return array<string, mixed>|null */
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
                ? 'Already registered / controlled — this RFIO review item is closed.'
                : 'Review only — submitted by another office for RFIO processing.',
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

    /** @return array<string, mixed>|null */
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
                ? 'Already registered / controlled — this RFIO review item is closed.'
                : 'Review only — submitted by another office for RFIO processing.',
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

        return [
            'canConfirmReceived' => $canManage && ! $registered,
            'canRegister' => $canRegister && ! $registered,
            'canUnlockEdit' => $canManage && ! $registered,
            'canNotifyPrintReady' => $canManage && ! $registered,
            'received' => $received['received'],
            'receivedAt' => $received['receivedAt'],
            'receivedBy' => $received['receivedBy'],
            'registerUrl' => $registerUrl,
            'registerType' => $registerType,
            'registered' => $registered,
            'editUnlocked' => (bool) ($editState['editUnlocked'] ?? false),
            'editUnlockReason' => $editState['editUnlockReason'] ?? null,
            'editUnlockedAt' => $editState['editUnlockedAt'] ?? null,
            'printNotified' => (bool) ($printState['printNotified'] ?? false),
            'printNotifiedAt' => $printState['printNotifiedAt'] ?? null,
            'printNotifiedBy' => $printState['printNotifiedBy'] ?? null,
        ];
    }

    /**
     * RFIO admin marks physical document as received.
     *
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

        if (! Schema::hasColumn($table, 'rfio_received_at')) {
            abort(422, 'Received tracking is not available yet. Please run migrations.');
        }

        $now = now();
        $userId = (int) auth()->id();

        if (empty($record->rfio_received_at)) {
            DB::table($table)->where('id', $id)->update([
                'rfio_received_at' => $now,
                'rfio_received_by' => $userId > 0 ? $userId : null,
                'updated_at' => $now,
            ]);
            $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
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
     * Undo "I have received the signed printed form…" so RFIO can return it for correction instead.
     *
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

    /** @return array<string, mixed> */
    public static function registerPrefillForDrf(object $drf, int $id): array
    {
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
        $docTypeId = match ($kind) {
            'internal' => $parentMap['internal_docs'] ?? null,
            'external' => $parentMap['external_docs'] ?? null,
            default => null,
        };

        $drfDate = ! empty($drf->drf_date)
            ? \Carbon\Carbon::parse($drf->drf_date)->format('Y-m-d')
            : null;

        $previousNo = trim((string) ($drf->previous_document_no ?? ''));
        $isRecurring = $previousNo !== '' && self::findSourceDcnIdForOfficeDrf($id) === null;
        $versionType = $isRecurring ? 'recurring' : 'new';

        $prefill = [
            'intake' => 'drf',
            'intakeId' => $id,
            'versionType' => $versionType,
            'checklistIds' => [1, 3, 5],
            'docTypeId' => $docTypeId,
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

    /** @return array<string, mixed> */
    public static function registerPrefillForDcn(object $dcn, int $id, string $docNo, string $docTitle): array
    {
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

    /** Parent doc-type groups shown in office document inventory. */
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
     * How an office sees documents in each parent type.
     * All groups (including Forms / Logbooks) use Document Distribution recipients.
     */
    public static function documentGroupScope(string $groupKey): string
    {
        return 'distribution';
    }

    /** @return list<string> */
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

    public static function documentGroupLabel(string $groupKey): string
    {
        return self::documentGroupDefs()[$groupKey] ?? 'Documents';
    }

    /** @return list<int> */
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

    /** Restrict masterlist rows to offices listed on Document Distribution. */
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

    /** Restrict masterlist rows to offices listed as Source Unit. */
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
     *
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

    /** Office DCN Document No. lookup is Internal and External only. */
    public static function dcnDocumentGroupKeys(): array
    {
        return ['internal_docs', 'external_docs'];
    }

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

    /** @return int */
    public static function officeDocumentTotal(?int $officeId = null, string $receiptFilter = 'all'): int
    {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();

        return (int) self::rememberOfficeInventory(
            'total.' . $receiptFilter . '.' . (int) ($officeId ?? 0),
            function () use ($officeId, $receiptFilter) {
                $query = self::officeMasterlistQuery($officeId, null);
                self::applyOfficeReceiptFilter($query, (int) ($officeId ?? 0), $receiptFilter);

                return (int) $query->count();
            }
        );
    }

    public static function officeIncomingAttentionTotal(?int $officeId = null): int
    {
        $pending = self::officeDocumentTotal($officeId, 'pending');
        $perms = auth()->user()?->permissions;
        if (! $perms || (! $perms->is_sadm && ! $perms->can_access_rdp)) {
            return $pending;
        }

        $held = self::officeDocumentTotal($officeId, 'received_for_appraisal');
        $onMasterlist = self::officeDocumentTotal($officeId, 'received');

        return $pending + max(0, $held - $onMasterlist);
    }

    public static function isIncomingNoticeUrl(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? $url);

        return str_contains($path, '/dcs/office/incoming');
    }

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
     * Groups for office document inventory.
     * When $onlyWithDocuments is false, every parent type is returned (count may be 0).
     *
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
            'groups.' . $receiptFilter . '.' . $onlyFlag . '.' . (int) ($officeId ?? 0),
            function () use ($officeId, $onlyWithDocuments, $receiptFilter) {
                return self::buildOfficeDocumentGroups($officeId, $onlyWithDocuments, $receiptFilter);
            }
        );
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    private static function buildOfficeDocumentGroups(
        ?int $officeId,
        bool $onlyWithDocuments,
        string $receiptFilter
    ): array {
        $groups = [];
        foreach (self::documentGroupDefs() as $key => $label) {
            $scope = self::documentGroupScope($key);
            $query = self::applyMasterlistGroupFilter(
                self::officeMasterlistQuery($officeId, $scope),
                $key
            );
            self::applyOfficeReceiptFilter($query, (int) ($officeId ?? 0), $receiptFilter);
            $count = (int) $query->count();
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
            if (! $keepAwaitingAppraisal) {
                self::excludePendingAppraisal($query, $officeId);
            }
        }

        // A copy this office has already received stays on the masterlist only.
        // Save and Appraise stays off Incoming until the admin forwards the appraisal.
        if ($receiptFilter === 'pending') {
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
     * Keep a received copy off the office masterlist while its DCS appraisal is still pending.
     */
    protected static function excludePendingAppraisal($query, int $officeId): void
    {
        $perms = auth()->user()?->permissions;
        if (! $perms || (! $perms->is_sadm && ! $perms->can_access_rdp)) {
            return;
        }
        if ($officeId < 1 || ! Schema::hasTable('rdp_received_documents')) {
            return;
        }

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $officeCode = DB::table($officeTbl)->where('id', $officeId)->value('office_code');
        if (! is_string($officeCode) || trim($officeCode) === '') {
            return;
        }

        $query->whereNotExists(function ($q) use ($officeCode) {
            $q->select(DB::raw(1))
                ->from('rdp_received_documents as rdp_hold')
                ->where('rdp_hold.source_subsystem', 'DCS')
                ->where('rdp_hold.status', 'pending')
                ->whereRaw('LOWER(rdp_hold.target_office) = ?', [strtolower(trim($officeCode))])
                ->where(function ($match) {
                    $match->whereColumn('rdp_hold.document_code', 'ml.doc_no')
                        ->orWhereRaw("COALESCE(rdp_hold.metadata->>'dcs_request_id', '') = ml.request_id::text")
                        ->orWhereRaw(
                            "rdp_hold.document_code = CONCAT('DCS-REQ-', ml.request_id::text)"
                        );
                });
        });
    }

    /**
     * One present copy per document this office holds.
     * Same number with a different title stays separate. Obsolete and superseded copies do not replace the current one.
     */
    protected static function keepLatestDistributedDocuments($records)
    {
        $best = [];
        foreach ($records as $ml) {
            $docNo = strtolower(trim((string) ($ml->doc_no ?? '')));
            $title = strtolower(trim((string) ($ml->doc_title ?? '')));
            $key = $docNo !== '' ? $docNo.'||'.$title : 'id:'.(int) ($ml->id ?? 0);
            $status = strtolower(trim((string) ($ml->revision_status ?? 'latest')));
            if ($status === '') {
                $status = 'latest';
            }
            $candidate = [
                'row' => $ml,
                'current' => $status === 'latest' ? 1 : 0,
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

    /** @param  array{current: int, rev: int, id: int}  $candidate */
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
     * @param  'all'|'received'|'pending'  $receiptFilter
     * @return list<array{
     *     item_no: int,
     *     request_id: int,
     *     doc_no: string,
     *     rev_no: int,
     *     doc_title: string,
     *     pages: string,
     *     effectivity_date: string|null,
     *     can_receive: bool,
     *     received_at: string|null,
     *     received_by_name: string
     * }>
     */
    public static function listOfficeDocuments(
        string $groupKey,
        ?int $officeId = null,
        string $receiptFilter = 'all'
    ): array {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();
        if (! $officeId) {
            return [];
        }

        return self::rememberOfficeInventory(
            'rows.' . $receiptFilter . '.' . ($groupKey === '' ? 'all' : $groupKey) . '.' . (int) $officeId,
            function () use ($groupKey, $officeId, $receiptFilter) {
                return self::buildOfficeDocumentRows($groupKey, (int) $officeId, $receiptFilter);
            }
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function buildOfficeDocumentRows(
        string $groupKey,
        int $officeId,
        string $receiptFilter
    ): array {
        if (! $officeId) {
            return [];
        }

        if ($groupKey === '' || $groupKey === 'all') {
            $query = self::officeMasterlistQuery($officeId, null);
        } else {
            if (! isset(self::documentGroupDefs()[$groupKey])) {
                return [];
            }
            $query = self::applyMasterlistGroupFilter(
                self::officeMasterlistQuery($officeId, self::documentGroupScope($groupKey)),
                $groupKey
            );
        }

        self::applyOfficeReceiptFilter($query, (int) $officeId, $receiptFilter);

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

        $records = $query
            ->orderByRaw("CASE WHEN COALESCE(TRIM(ml.doc_no), '') = '' THEN 1 ELSE 0 END")
            ->orderBy('ml.doc_no')
            ->orderByDesc('ml.revise_no')
            ->orderBy('ml.id')
            ->get($select);

        $records = self::keepLatestDistributedDocuments($records);

        $receipts = self::officeDistributionReceipts(
            $officeId,
            $records->pluck('request_id')->filter()->map(fn ($id) => (int) $id)->unique()->all()
        );

        $rows = [];
        $itemNo = 0;
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
        if ($officeCode) {
            $perms = auth()->user()?->permissions;
            $canAppraise = $perms && ($perms->is_sadm || $perms->can_access_rdp);
            $appraisalWaiting = false;
            if ($canAppraise && Schema::hasTable('rdp_received_documents')) {
                $intakeCode = \App\Services\RdpIntakeService::documentCodeForRequest($requestId, $docNo);
                $appraisalWaiting = DB::table('rdp_received_documents')
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
            if ($appraisalWaiting) {
                DcsNotificationService::notifyAppraisalStillWaiting(
                    $officeCode,
                    $docNo !== '' ? $docNo : \App\Services\RdpIntakeService::documentCodeForRequest($requestId, $docNo),
                    $title !== '' ? $title : null
                );
            }

            DcsNotificationService::notifyOfficeDocumentReceived(
                $officeCode,
                $receiverName,
                $title !== '' ? $title : $docNo,
                $docNo !== '' ? $docNo : null,
                $userId > 0 ? $userId : null
            );
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
                ? 'Document marked as received. It stays on Incoming until you finish Appraisal.'
                : 'Document marked as received.',
        ];
    }

    /**
     * Documents for Random Check (by office + optional parent type group).
     *
     * @param  bool  $distributionOnly  When true, always scope by Document Distribution
     *                                  (used by Random Check office audits).
     * @return list<array{
     *     masterlist_id: int,
     *     doc_no: string,
     *     rev_no: int,
     *     doc_title: string,
     *     effectivity_date: string|null,
     *     effectivity_date_raw: string|null,
     *     doc_type_key: string,
     *     doc_type_label: string
     * }>
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
        if ($groupKey === '' || $groupKey === 'all') {
            $rows = [];
            $seen = [];
            foreach (array_keys(self::documentGroupDefs()) as $key) {
                foreach (self::listDocumentsForCheckGroup((int) $officeId, $key, $distributionOnly) as $row) {
                    $id = (int) $row['masterlist_id'];
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        if (! isset(self::documentGroupDefs()[$groupKey])) {
            return [];
        }

        return self::listDocumentsForCheckGroup((int) $officeId, $groupKey, $distributionOnly);
    }

    /**
     * Type counts for an office (distribution recipients when $distributionOnly).
     *
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
     * @return list<array{
     *     masterlist_id: int,
     *     doc_no: string,
     *     rev_no: int,
     *     doc_title: string,
     *     effectivity_date: string|null,
     *     effectivity_date_raw: string|null,
     *     doc_type_key: string,
     *     doc_type_label: string
     * }>
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

        $records = $query
            ->orderByRaw("CASE WHEN COALESCE(TRIM(ml.doc_no), '') = '' THEN 1 ELSE 0 END")
            ->orderBy('ml.doc_no')
            ->orderBy('ml.id')
            ->get([
                'ml.id',
                'ml.doc_no',
                'ml.revise_no',
                'ml.doc_title',
                'ml.effectivity_date',
            ]);

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

    /** @deprecated Use listDocumentsForCheck() */
    public static function listDistributedDocumentsForCheck(?int $officeId = null): array
    {
        return self::listDocumentsForCheck($officeId, 'all', true);
    }
}