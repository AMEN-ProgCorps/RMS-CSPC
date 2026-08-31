<?php

namespace App\Helpers;

use App\Services\DocumentStorageService;
use App\Services\DcsNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Non-RFIO office DRF/DCN intake: create once, then view/print only.
 * Fields mirror Document Registration sections 1 (DRF) and 2 (DCN).
 */
class OfficeIntakeHelper
{
    public const IMMUTABLE_MESSAGE = 'This document cannot be edited.';

    public static function canAccessIntake(): bool
    {
        return RegisterQueryHelper::isFullDcsUser() || RegisterQueryHelper::isLimitedDcsUser();
    }

    public static function assertCanAccessIntake(): void
    {
        abort_unless(self::canAccessIntake(), 403, 'You do not have access to office DRF/DCN intake.');
    }

    public static function assertOwnsDrf(object $drf): void
    {
        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            return;
        }
        abort_unless(
            (int) ($drf->created_by ?? 0) === (int) auth()->id(),
            403,
            'You can only view Document Request Forms you created.'
        );
    }

    public static function assertOwnsDcn(object $dcn): void
    {
        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            return;
        }
        abort_unless(
            (int) ($dcn->created_by ?? 0) === (int) auth()->id(),
            403,
            'You can only view Document Change Notices you created.'
        );
    }

    public static function rejectMutation(): RedirectResponse
    {
        return redirect()->back()->with('error', self::IMMUTABLE_MESSAGE);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function listMyDrf()
    {
        if (! Schema::hasColumn('dcs_document_request_form', 'is_office_intake')) {
            return collect();
        }

        $q = DB::table('dcs_document_request_form as drf')
            ->where('drf.is_office_intake', true)
            ->orderByDesc('drf.id');

        if (! RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            $q->where('drf.created_by', auth()->id());
        }

        return $q->get([
            'drf.id',
            'drf.drf_no',
            'drf.drf_date',
            'drf.doc_title',
            'drf.drf_receipt_date',
            'drf.created_at',
            'drf.created_by',
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function listMyDcn()
    {
        if (! Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
            return collect();
        }

        $q = DB::table('dcs_document_change_notice as dcn')
            ->where('dcn.is_office_intake', true)
            ->orderByDesc('dcn.id');

        if (! RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            $q->where('dcn.created_by', auth()->id());
        }

        return $q->get(array_values(array_filter([
            'dcn.id',
            'dcn.dcn_no',
            'dcn.dcn_date',
            Schema::hasColumn('dcs_document_change_notice', 'brief_purpose') ? 'dcn.brief_purpose' : null,
            'dcn.created_at',
            'dcn.created_by',
        ])));
    }

    public static function findOfficeDrf(int $id): ?object
    {
        if (! Schema::hasColumn('dcs_document_request_form', 'is_office_intake')) {
            return null;
        }

        return DB::table('dcs_document_request_form')
            ->where('id', $id)
            ->where('is_office_intake', true)
            ->first();
    }

    public static function findOfficeDcn(int $id): ?object
    {
        if (! Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
            return null;
        }

        return DB::table('dcs_document_change_notice')
            ->where('id', $id)
            ->where('is_office_intake', true)
            ->first();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function drfSourceOffices(int $drfId)
    {
        return DB::table('dcs_drf_offices as d')
            ->leftJoin('office as o', 'o.id', '=', 'd.office_id')
            ->where('d.document_request_form_id', $drfId)
            ->orderBy('o.office_name')
            ->get(['d.office_id', 'o.office_name', 'o.office_code']);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function dcnSourceOffices(int $dcnId)
    {
        return DB::table('dcs_dcn_offices as d')
            ->leftJoin('office as o', 'o.id', '=', 'd.office_id')
            ->where('d.dcn_id', $dcnId)
            ->orderBy('o.office_name')
            ->get(['d.office_id', 'o.office_name', 'o.office_code']);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function dcnRevisions(int $dcnId)
    {
        return DB::table('dcs_doc_revision')
            ->where('dcn_id', $dcnId)
            ->orderBy('id')
            ->get();
    }

    public static function originatorMatchesUser(?string $originatorName, ?int $originatorAccountId = null): bool
    {
        return RegisterQueryHelper::originatorMatchesCurrentUser($originatorName, $originatorAccountId);
    }

    public static function storeDrf(Request $request): RedirectResponse
    {
        self::assertCanAccessIntake();

        $data = $request->validate([
            'drfNo' => 'required|string|max:100',
            'drfDate' => 'required|date',
            'drfTitle' => 'required|string|max:255',
            'originatorName' => 'nullable|string|max:255',
            'docTypeKind' => 'nullable|in:internal,external',
            'descriptionReason' => 'nullable|string|max:5000',
            'distributeToOffice' => 'nullable|array',
            'distributeToOffice.*' => 'nullable|integer',
        ]);

        $officeIds = [];
        $current = RegisterQueryHelper::currentOfficeId();
        if ($current) {
            $officeIds = [(int) $current];
        }

        $userId = (int) auth()->id();
        $now = now();

        $drfFile = null;

        try {
            $id = DB::transaction(function () use ($data, $officeIds, $userId, $now, $drfFile) {
                $row = array_merge([
                    'request_id' => null,
                    'drf_no' => $data['drfNo'],
                    'drf_date' => $data['drfDate'],
                    'drf_receipt_date' => null,
                    'drf_receipt_time' => null,
                    'doc_title' => $data['drfTitle'],
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], RegisterPersistHelper::dcsScanFields('dcs_document_request_form', 'scanned_drf', $drfFile));

                if (Schema::hasColumn('dcs_document_request_form', 'is_office_intake')) {
                    $row['is_office_intake'] = true;
                    $row['prepared_by_name'] = RegisterQueryHelper::currentUserDisplayName();
                    $row['originator_name'] = trim((string) ($data['originatorName'] ?? ''))
                        ?: RegisterQueryHelper::currentUserDisplayName();
                    if (Schema::hasColumn('dcs_document_request_form', 'doc_type_kind')) {
                        $row['doc_type_kind'] = $data['docTypeKind'] ?? null;
                    }
                    if (Schema::hasColumn('dcs_document_request_form', 'description_reason')) {
                        $row['description_reason'] = trim((string) ($data['descriptionReason'] ?? '')) ?: null;
                    }
                    if (Schema::hasColumn('dcs_document_request_form', 'distribute_to')) {
                        $row['distribute_to'] = self::encodeDistributeTo(
                            self::officeCodesForIds($data['distributeToOffice'] ?? [])
                        );
                    }
                }

                $drfId = DB::table('dcs_document_request_form')->insertGetId($row);

                foreach ($officeIds as $officeId) {
                    if ($officeId <= 0) {
                        continue;
                    }
                    DB::table('dcs_drf_offices')->insert([
                        'document_request_form_id' => $drfId,
                        'office_id' => $officeId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return $drfId;
            });
        } catch (\Throwable $e) {
            if ($drfFile) {
                DocumentStorageService::deleteDcsScan($drfFile);
            }
            throw $e;
        }

        RegisterPersistHelper::logAdminChange(
            'Created office DRF #' . $id . ' — ' . $data['drfNo'] . ': ' . $data['drfTitle']
        );

        if (! RegisterQueryHelper::isRfioOffice()) {
            DcsNotificationService::notifyOfficeDrfSubmitted(
                DcsNotificationService::RFIO_OFFICE_CODE,
                RegisterQueryHelper::currentUserDisplayName(),
                $data['drfNo'],
                $data['drfTitle'],
                $id
            );
        }

        return redirect()
            ->route('dcs.office.drf.show', $id)
            ->with('success', 'Document Request Form saved. This document cannot be edited.')
            ->with('locked', true);
    }

    public static function storeDcn(Request $request): RedirectResponse
    {
        self::assertCanAccessIntake();

        $data = $request->validate([
            'dcnNumber' => 'required|string|max:100',
            'documentNo' => 'required|string|max:150',
            'documentTitle' => 'nullable|string|max:255',
            'changeFrom' => 'nullable|string|max:5000',
            'changeTo' => 'nullable|string|max:5000',
            'dcnJustification' => 'required|string|max:5000',
            'originatorName' => 'nullable|string|max:255',
            'departmentDate' => 'nullable|string|max:255',
            'reviewedByDate' => 'nullable|string|max:255',
            'revisionMasterlistId' => 'nullable|integer',
            'revisionLinked' => 'nullable|in:1,0,true,false',
        ]);

        $docNo = trim((string) $data['documentNo']);
        $docTitle = trim((string) ($data['documentTitle'] ?? ''));
        $linked = filter_var($data['revisionLinked'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (int) ($data['revisionMasterlistId'] ?? 0) > 0;

        if (! $linked) {
            throw ValidationException::withMessages([
                'documentNo' => 'Search and select the document being revised. Free-typed rows are not allowed.',
            ]);
        }

        $mlId = (int) ($data['revisionMasterlistId'] ?? 0);
        self::assertRevisionOriginatorAllowed($docNo, $mlId);

        $userId = (int) auth()->id();
        $now = now();

        try {
            $id = DB::transaction(function () use ($data, $docNo, $docTitle, $userId, $now) {
                $row = [
                    'request_id' => null,
                    'dcn_no' => $data['dcnNumber'],
                    'dcn_date' => now()->toDateString(),
                    'dcn_receipt_date' => null,
                    'dcn_receipt_time' => null,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (Schema::hasColumn('dcs_document_change_notice', 'brief_purpose')) {
                    $row['brief_purpose'] = $data['dcnJustification'];
                }

                if (Schema::hasColumn('dcs_document_change_notice', 'brief_purpose')) {
                    $row['brief_purpose'] = $data['dcnJustification'];
                }

                if (Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
                    $row['is_office_intake'] = true;
                    $row['document_no'] = $docNo !== '' ? $docNo : null;
                    $row['document_title'] = $docTitle !== '' ? $docTitle : null;
                    $row['change_from'] = trim((string) ($data['changeFrom'] ?? '')) ?: null;
                    $row['change_to'] = trim((string) ($data['changeTo'] ?? '')) ?: null;
                    $row['originator_name'] = trim((string) ($data['originatorName'] ?? ''))
                        ?: RegisterQueryHelper::currentUserDisplayName();
                    $row['department_date'] = trim((string) ($data['departmentDate'] ?? '')) ?: null;
                    $row['reviewed_by_date'] = trim((string) ($data['reviewedByDate'] ?? '')) ?: null;
                }

                $dcnId = DB::table('dcs_document_change_notice')->insertGetId($row);

                $currentOfficeId = RegisterQueryHelper::currentOfficeId();
                if ($currentOfficeId) {
                    RegisterPersistHelper::saveDcnOfficesById($dcnId, [$currentOfficeId]);
                }

                $revRow = [
                    'dcn_id' => $dcnId,
                    'title' => $docTitle !== '' ? $docTitle : null,
                    'document_no' => $docNo !== '' ? $docNo : null,
                    'created_at' => $now,
                ];
                if (Schema::hasColumn('dcs_doc_revision', 'brief_purpose')) {
                    $revRow['brief_purpose'] = $data['dcnJustification'];
                }
                DB::table('dcs_doc_revision')->insert($revRow);

                return $dcnId;
            });
        } catch (\Throwable $e) {
            throw $e;
        }

        RegisterPersistHelper::logAdminChange(
            'Created office DCN #' . $id
            . ' — ' . $data['dcnNumber']
            . ($docNo !== '' ? ' for ' . $docNo : '')
            . ($docTitle !== '' ? ': ' . $docTitle : '')
        );

        if (! RegisterQueryHelper::isRfioOffice()) {
            DcsNotificationService::notifyOfficeDcnSubmitted(
                DcsNotificationService::RFIO_OFFICE_CODE,
                RegisterQueryHelper::currentUserDisplayName(),
                $data['dcnNumber'],
                $docNo,
                $id
            );
        }

        return redirect()
            ->route('dcs.office.dcn.show', $id)
            ->with('success', 'Document Change Notice saved. This document cannot be edited.')
            ->with('locked', true);
    }

    private static function assertRevisionOriginatorAllowed(string $docNo, int $masterlistId): void
    {
        $q = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id');

        if ($masterlistId > 0) {
            $q->where('ml.id', $masterlistId);
        } else {
            $q->where('ml.doc_no', $docNo);
            RegisterQueryHelper::applyLatestRevisionStatus($q, 'ml');
            $q->orderByDesc('ml.revise_no');
        }

        $ml = $q->first(['ml.id', 'ml.doc_no', 'ml.originator_name', 'ml.originator_account_id', 'ml.request_id']);
        if (! $ml) {
            throw ValidationException::withMessages([
                'documentNo' => 'Selected document was not found in the masterlist.',
            ]);
        }

        RegisterQueryHelper::assertCanAccessRequest((int) $ml->request_id);

        if (! self::originatorMatchesUser($ml->originator_name ?? null, isset($ml->originator_account_id) ? (int) $ml->originator_account_id : null)) {
            throw ValidationException::withMessages([
                'documentNo' => 'You can only revise documents where you are the originator (originator name must match your account name).',
            ]);
        }
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

    public static function decodeDistributeTo(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @param  array<int|string|null>  $officeIds */
    public static function officeCodesForIds(array $officeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $officeIds))));
        if ($ids === []) {
            return [];
        }

        $byId = DB::table('office')
            ->whereIn('id', $ids)
            ->get(['id', 'office_code', 'office_name'])
            ->keyBy('id');

        $codes = [];
        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;
            if (!$row) {
                continue;
            }
            $code = trim((string) ($row->office_code ?? ''));
            $label = $code !== '' ? $code : trim((string) ($row->office_name ?? ''));
            if ($label !== '') {
                $codes[] = $label;
            }
        }

        return $codes;
    }

    /** @param  array<int, string>  $labels */
    public static function encodeDistributeTo(array $labels): ?string
    {
        $labels = collect($labels)->map(fn ($v) => trim((string) $v))->filter()->values();

        return $labels->isEmpty() ? null : json_encode($labels->all());
    }
}
