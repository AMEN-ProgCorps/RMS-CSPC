<?php

namespace App\Helpers;

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
        if (RegisterQueryHelper::isFullDcsUser()) {
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
        if (RegisterQueryHelper::isFullDcsUser()) {
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

        if (! RegisterQueryHelper::isFullDcsUser()) {
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

        if (! RegisterQueryHelper::isFullDcsUser()) {
            $q->where('dcn.created_by', auth()->id());
        }

        return $q->get([
            'dcn.id',
            'dcn.dcn_no',
            'dcn.dcn_date',
            'dcn.brief_purpose',
            'dcn.created_at',
            'dcn.created_by',
        ]);
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

    public static function originatorMatchesUser(?string $originatorName): bool
    {
        $a = mb_strtolower(trim((string) $originatorName));
        $b = mb_strtolower(trim(RegisterQueryHelper::currentUserDisplayName()));

        return $a !== '' && $b !== '' && $b !== '—' && $a === $b;
    }

    public static function storeDrf(Request $request): RedirectResponse
    {
        self::assertCanAccessIntake();

        $data = $request->validate([
            'drfNo' => 'required|string|max:100',
            'drfDate' => 'required|date',
            'drfReceiptDate' => 'nullable|date',
            'drfTime' => 'nullable|string|max:8',
            'drfTitle' => 'required|string|max:255',
            'drfSourceUnit' => 'nullable|array',
            'drfSourceUnit.*' => 'nullable|integer',
            'drfFile' => 'nullable|file|mimes:pdf|max:20480',
        ]);

        $officeIds = array_values(array_filter(array_map('intval', $data['drfSourceUnit'] ?? [])));
        if ($officeIds === []) {
            $current = RegisterQueryHelper::currentOfficeId();
            if ($current) {
                $officeIds = [$current];
            }
        }

        $userId = (int) auth()->id();
        $now = now();
        $typeIds = RegisterQueryHelper::parentTypeIdMap();
        $docTypeId = $typeIds['internal_docs'] ?? 1;
        $versionId = (int) (DB::table('dcs_version_type')->orderBy('id')->value('id') ?: 1);

        $drfFile = null;
        if ($request->hasFile('drfFile')) {
            $drfFile = $request->file('drfFile')->store('scans/drf', 'public');
        }

        $receiptTime = self::normalizeTime($data['drfTime'] ?? null);

        try {
            $id = DB::transaction(function () use ($data, $officeIds, $userId, $now, $docTypeId, $versionId, $drfFile, $receiptTime) {
                $requestId = DB::table('dcs_document_requests')->insertGetId([
                    'version_id' => $versionId,
                    'doc_type_id' => $docTypeId,
                    'sub_type_id' => null,
                    'approval_status' => 'not_applicable',
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $row = [
                    'request_id' => $requestId,
                    'drf_no' => $data['drfNo'],
                    'drf_date' => $data['drfDate'],
                    'drf_receipt_date' => $data['drfReceiptDate'] ?? null,
                    'drf_receipt_time' => $receiptTime,
                    'doc_title' => $data['drfTitle'],
                    'scanned_drf' => $drfFile,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (Schema::hasColumn('dcs_document_request_form', 'is_office_intake')) {
                    $row['is_office_intake'] = true;
                    $row['prepared_by_name'] = RegisterQueryHelper::currentUserDisplayName();
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
                Storage::disk('public')->delete($drfFile);
            }
            throw $e;
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
            'noticeDate' => 'nullable|date',
            'receiptDate' => 'nullable|date',
            'receiptTime' => 'nullable|string|max:8',
            'dcnJustification' => 'required|string|max:5000',
            'dcnSourceUnit' => 'nullable|array',
            'dcnSourceUnit.*' => 'nullable|integer',
            'dcnFile' => 'nullable|file|mimes:pdf|max:20480',
            'documentNo' => 'required|array|min:1',
            'documentNo.*' => 'nullable|string|max:150',
            'documentTitle' => 'nullable|array',
            'documentTitle.*' => 'nullable|string|max:255',
            'effectiveDate' => 'nullable|array',
            'effectiveDate.*' => 'nullable|date',
            'revisionNo' => 'nullable|array',
            'revisionNo.*' => 'nullable',
            'revisionPurpose' => 'nullable|array',
            'revisionPurpose.*' => 'nullable|string|max:5000',
            'revisionScannedPath' => 'nullable|array',
            'revisionScannedPath.*' => 'nullable|string|max:500',
            'revisionMasterlistId' => 'nullable|array',
            'revisionMasterlistId.*' => 'nullable|integer',
            'revisionLinked' => 'nullable|array',
            'revisionLinked.*' => 'nullable|in:1,0,true,false',
        ]);

        $titles = $data['documentTitle'] ?? [];
        $numbers = $data['documentNo'] ?? [];
        $linkedFlags = $data['revisionLinked'] ?? [];
        $masterlistIds = $data['revisionMasterlistId'] ?? [];
        $totalRows = max(count($titles), count($numbers));

        $rows = [];
        for ($i = 0; $i < $totalRows; $i++) {
            $title = trim((string) ($titles[$i] ?? ''));
            $docNo = trim((string) ($numbers[$i] ?? ''));
            if ($title === '' && $docNo === '') {
                continue;
            }
            $linked = filter_var($linkedFlags[$i] ?? false, FILTER_VALIDATE_BOOLEAN)
                || (int) ($masterlistIds[$i] ?? 0) > 0;
            if (! $linked) {
                throw ValidationException::withMessages([
                    'documentNo' => 'Search and select the document being revised. Free-typed rows are not allowed.',
                ]);
            }
            $mlId = (int) ($masterlistIds[$i] ?? 0);
            self::assertRevisionOriginatorAllowed($docNo, $mlId);
            $rows[] = $i;
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'documentNo' => 'Select at least one document for revision.',
            ]);
        }

        $officeIds = array_values(array_filter(array_map('intval', $data['dcnSourceUnit'] ?? [])));
        if ($officeIds === []) {
            $current = RegisterQueryHelper::currentOfficeId();
            if ($current) {
                $officeIds = [$current];
            }
        }

        $userId = (int) auth()->id();
        $now = now();
        $typeIds = RegisterQueryHelper::parentTypeIdMap();
        $docTypeId = $typeIds['internal_docs'] ?? 1;
        $versionId = (int) (DB::table('dcs_version_type')->where('version_name', 'ilike', 'revised')->value('id')
            ?: DB::table('dcs_version_type')->orderByDesc('id')->value('id')
            ?: 2);

        $dcnFile = null;
        if ($request->hasFile('dcnFile')) {
            $dcnFile = $request->file('dcnFile')->store('scans/dcn', 'public');
        }

        $uploadedFiles = [];
        if ($dcnFile) {
            $uploadedFiles[] = $dcnFile;
        }

        try {
            $id = DB::transaction(function () use ($request, $data, $rows, $officeIds, $userId, $now, $docTypeId, $versionId, $dcnFile, &$uploadedFiles) {
                $requestId = DB::table('dcs_document_requests')->insertGetId([
                    'version_id' => $versionId,
                    'doc_type_id' => $docTypeId,
                    'sub_type_id' => null,
                    'approval_status' => 'not_applicable',
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $row = [
                    'request_id' => $requestId,
                    'dcn_no' => $data['dcnNumber'],
                    'dcn_date' => $data['noticeDate'] ?? now()->toDateString(),
                    'dcn_receipt_date' => $data['receiptDate'] ?? null,
                    'dcn_receipt_time' => self::normalizeTime($data['receiptTime'] ?? null),
                    'scanned_dcn' => $dcnFile,
                    'brief_purpose' => $data['dcnJustification'],
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
                    $row['is_office_intake'] = true;
                    $firstIdx = $rows[0];
                    $row['document_no'] = trim((string) ($request->input('documentNo')[$firstIdx] ?? ''));
                    $row['document_title'] = trim((string) ($request->input('documentTitle')[$firstIdx] ?? ''));
                    $row['originator_name'] = RegisterQueryHelper::currentUserDisplayName();
                    $row['department_date'] = RegisterQueryHelper::currentOfficeName() . ' / ' . now()->format('M d, Y');
                }

                $dcnId = DB::table('dcs_document_change_notice')->insertGetId($row);
                RegisterPersistHelper::saveDcnOfficesById($dcnId, $officeIds);

                foreach ($rows as $i) {
                    $title = trim((string) ($request->input('documentTitle')[$i] ?? ''));
                    $docNo = trim((string) ($request->input('documentNo')[$i] ?? ''));
                    DB::table('dcs_doc_revision')->insert([
                        'dcn_id' => $dcnId,
                        'title' => $title !== '' ? $title : null,
                        'document_no' => $docNo !== '' ? $docNo : null,
                        'effectivity_date' => $request->input('effectiveDate')[$i] ?? null,
                        'revision_no' => $request->input('revisionNo')[$i] ?? null,
                        'scanned_copy' => RegisterPersistHelper::resolveRevisionScannedCopyPath($request, $i, $uploadedFiles),
                        'brief_purpose' => $request->input('revisionPurpose')[$i] ?? null,
                        'created_at' => $now,
                    ]);
                }

                return $dcnId;
            });
        } catch (\Throwable $e) {
            foreach ($uploadedFiles as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
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
            $q->where('ml.doc_no', $docNo)
                ->where(function ($qr) {
                    $qr->whereNull('ml.revision_status')
                        ->orWhere('ml.revision_status', '')
                        ->orWhere('ml.revision_status', 'latest');
                })
                ->orderByDesc('ml.revise_no');
        }

        $ml = $q->first(['ml.id', 'ml.doc_no', 'ml.originator_name', 'ml.request_id']);
        if (! $ml) {
            throw ValidationException::withMessages([
                'documentNo' => 'Selected document was not found in the masterlist.',
            ]);
        }

        RegisterQueryHelper::assertCanAccessRequest((int) $ml->request_id);

        if (! self::originatorMatchesUser($ml->originator_name ?? null)) {
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
}
