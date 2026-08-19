<?php

namespace App\Helpers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Register writes via DB::table(). Real PK is always `id`.
 */
class RegisterPersistHelper
{
    public const SCAN_MAX_KB = 204800;

    public static function scanFileRules(): array
    {
        $rule = 'nullable|file|mimes:pdf|max:' . self::SCAN_MAX_KB;

        return [
            'drfFile' => $rule,
            'dcnFile' => $rule,
            'uploadScannedCopy' => $rule,
            'scannedRet' => $rule,
            'scanneddist' => $rule,
            'scannedCopy.*' => $rule,
            'syllabiScannedDrf.*' => $rule,
        ];
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

        $inactive = DB::table('office')
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
        $clean = [];
        foreach ($request->all() as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                $clean[$key] = null;
            } elseif (is_array($value)) {
                $clean[$key] = array_map(
                    fn ($v) => is_string($v) && trim($v) === '' ? null : $v,
                    $value
                );
            }
        }
        if ($clean !== []) {
            $request->merge($clean);
        }
    }

    public static function syncedDocTitle(Request $request): ?string
    {
        $drf = trim((string) $request->input('drfTitle', ''));
        if ($drf !== '') {
            return $drf;
        }
        $ml = trim((string) $request->input('masterlistDocTitle', ''));

        return $ml !== '' ? $ml : null;
    }

    public static function validateCheckedSections(Request $request): ?RedirectResponse
    {
        $checked = array_map('intval', $request->input('checklists', []));
        if ($checked === []) {
            return back()->withInput()->with('error', 'Select at least one checklist section.');
        }

        return null;
    }

    public static function isKnownPublicScanPath(string $path, array $extraAllowed = []): bool
    {
        $path = ltrim(str_replace(['../', '..\\'], '', $path), '/');
        if ($path === '' || str_contains($path, '..') || !str_starts_with($path, 'scans/')) {
            return false;
        }
        if ($extraAllowed === [] || !in_array($path, $extraAllowed, true)) {
            return false;
        }

        return Storage::disk('public')->exists($path);
    }

    public static function saveDistributionOffices(int $distributionId, Request $request): void
    {
        foreach ($request->input('distOffice', []) as $i => $officeId) {
            $id = (int) $officeId;
            if ($id <= 0) {
                continue;
            }
            DB::table('dcs_distribution_offices')->insert([
                'distribution_id' => $distributionId,
                'office_id' => $id,
                'copies' => $request->input('distCopies')[$i] ?? 1,
                'sort_order' => $i,
            ]);
        }
    }

    public static function persist(Request $request): RedirectResponse
    {
        self::blankStringsToNull($request);
        $mode = $request->input('registration_mode', 'new');

        if ($mode === 'revised') {
            $subType = self::dcsDocType($request->input('sub_type_id'));
            $isSyllabi = self::isSyllabiLikeSubTypeRow($subType);
            $docNo = trim((string) ($isSyllabi
                ? $request->input('syllabiDocNo')
                : $request->input('masterlistDocNo')));
            $docTypeId = $request->input('doc_type_id');
            $subTypeId = $request->input('sub_type_id');

            if ($docNo === '') {
                return back()->withInput()
                    ->with('error', 'You must enter a registered document number before revising it. Register it as a New Document first if it is not yet registered.');
            }

            $result = self::findMatchingRegistrationRows($docNo, (int) $docTypeId, $subTypeId ? (int) $subTypeId : null);

            if (!$result['found']) {
                if (($result['reason'] ?? '') === 'not_registered') {
                    return back()->withInput()
                        ->with('error', 'Document "' . $docNo . '" is not registered. You must register it as a New Document first before revising it.');
                }

                return back()->withInput()
                    ->with('error', self::mismatchErrorMessageFromRow($docNo, $result));
            }

            $requestedRev = (int) $request->input('masterlistRevisionNo');
            $matchingIds = $result['matches']->pluck('id');
            $duplicateExists = DB::table('dcs_masterlist_registration')
                ->whereIn('request_id', $matchingIds)
                ->where('doc_no', $docNo)
                ->where('revise_no', $requestedRev)
                ->exists();

            if ($duplicateExists) {
                return back()->withInput()
                    ->with('error', 'Revision ' . $requestedRev . ' for document "' . $docNo . '" already exists. Please use a different revision number.');
            }
        }

        if ($redirect = self::rejectInactiveOfficeIds($request)) {
            return $redirect;
        }

        if ($mode === 'new') {
            $request->validate([
                'doc_type_id' => 'required|integer|exists:dcs_doc_types,id',
                'version_id' => 'required|integer|exists:dcs_version_type,id',
                'approval_status' => 'required|in:applicable,not_applicable',
                'masterlistRevisionNo' => 'nullable|integer|min:0',
            ]);
        }

        $request->validate(array_merge([
            'doc_type_id' => 'required|integer|exists:dcs_doc_types,id',
            'version_id' => 'required|integer|exists:dcs_version_type,id',
            'approval_status' => 'required|in:applicable,not_applicable',
        ], self::scanFileRules()));

        $subType = self::dcsDocType($request->sub_type_id);
        $isSyllabi = self::isSyllabiLikeSubTypeRow($subType);

        if ($redirect = self::validateSyllabiLikeRequestRows($request)) {
            return $redirect;
        }
        if ($redirect = self::validateCheckedSections($request)) {
            return $redirect;
        }

        if ($mode === 'new') {
            $docNo = $isSyllabi ? $request->input('syllabiDocNo') : $request->input('masterlistDocNo');
            $docTypeId = (int) $request->input('doc_type_id');
            $subTypeId = $request->input('sub_type_id');

            if ($docNo) {
                $result = self::findMatchingRegistrationRows($docNo, $docTypeId, $subTypeId ? (int) $subTypeId : null);

                if ($result['found']) {
                    $existing = $result['latest'];
                    return back()->withInput()
                        ->with('error', 'Document "' . $docNo . '" is already registered (Rev ' . $existing->revise_no . '). Please use Revised Registration to create a new revision.');
                }
            }
        }

        DB::beginTransaction();

        $uploadedFiles = [];
        $filesToDelete = [];

        try {
            $now = now();
            $userId = auth()->id();

            $requestId = DB::table('dcs_document_requests')->insertGetId([
                'version_id' => $request->version_id,
                'doc_type_id' => $request->doc_type_id,
                'sub_type_id' => $request->sub_type_id ?: null,
                'approval_status' => $request->approval_status,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $docTypeId = $request->doc_type_id;
            $versionId = $request->version_id;
            $checkedChecklists = array_map('intval', $request->input('checklists', []));

            if (in_array(1, $checkedChecklists, true)) {
                $drfFile = null;
                if ($request->hasFile('drfFile')) {
                    $drfFile = $request->file('drfFile')->store('scans/drf', 'public');
                    $uploadedFiles[] = $drfFile;
                }

                $drfOfficeIds = array_values(array_filter($request->input('drfSourceUnit', [])));

                $drfId = DB::table('dcs_document_request_form')->insertGetId([
                    'checklist_id' => 1,
                    'version_id' => $versionId,
                    'request_id' => $requestId,
                    'doc_type_id' => $docTypeId,
                    'drf_no' => $request->drfNo,
                    'drf_date' => $request->drfDate,
                    'drf_receipt_date' => $request->drfReceiptDate,
                    'drf_receipt_time' => $request->drfTime,
                    'doc_title' => self::syncedDocTitle($request) ?: $request->drfTitle,
                    'scanned_drf' => $drfFile,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($drfOfficeIds as $officeId) {
                    $id = (int) $officeId;
                    if ($id <= 0) {
                        continue;
                    }
                    DB::table('dcs_drf_offices')->insert([
                        'document_request_form_id' => $drfId,
                        'office_id' => $id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            if (in_array(2, $checkedChecklists, true)) {
                $dcnFile = null;
                if ($request->hasFile('dcnFile')) {
                    $dcnFile = $request->file('dcnFile')->store('scans/dcn', 'public');
                    $uploadedFiles[] = $dcnFile;
                }

                $dcnOfficeIds = array_values(array_filter($request->input('dcnSourceUnit', [])));
                $firstDcnOffice = $dcnOfficeIds[0] ?? null;

                $dcnId = DB::table('dcs_document_change_notice')->insertGetId([
                    'checklist_id' => 2,
                    'version_id' => $versionId,
                    'request_id' => $requestId,
                    'doc_type_id' => $docTypeId,
                    'dcn_no' => $request->dcnNumber,
                    'dcn_date' => $request->noticeDate,
                    'dcn_receipt_date' => $request->receiptDate,
                    'dcn_receipt_time' => $request->receiptTime,
                    'office_id' => $firstDcnOffice ?: null,
                    'scanned_dcn' => $dcnFile,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                self::saveDcnOfficesById($dcnId, $dcnOfficeIds);

                if ($request->has('documentTitle') || $request->has('documentNo')) {
                    $titles = $request->documentTitle ?? [];
                    $numbers = $request->documentNo ?? [];
                    $totalRows = max(count($titles), count($numbers));

                    for ($i = 0; $i < $totalRows; $i++) {
                        $title = $titles[$i] ?? null;
                        $docNo = $numbers[$i] ?? null;
                        if (empty($title) && empty($docNo)) {
                            continue;
                        }

                        DB::table('dcs_doc_revision')->insert([
                            'dcn_id' => $dcnId,
                            'title' => $title,
                            'document_no' => $docNo,
                            'effectivity_date' => $request->effectiveDate[$i] ?? null,
                            'revision_no' => $request->revisionNo[$i] ?? null,
                            'scanned_copy' => self::resolveRevisionScannedCopyPath($request, $i, $uploadedFiles),
                            'brief_purpose' => $request->revisionPurpose[$i] ?? null,
                            'created_at' => $now,
                        ]);
                    }
                }
            }

            if (in_array(3, $checkedChecklists, true) && !$isSyllabi) {
                $masterlistFile = null;
                if ($request->hasFile('uploadScannedCopy')) {
                    $masterlistFile = $request->file('uploadScannedCopy')->store('scans/masterlist', 'public');
                    $uploadedFiles[] = $masterlistFile;
                }

                $masterlistTimeSpent = null;
                if ($request->filled('masterlistTimeSpent') && is_numeric($request->masterlistTimeSpent) && $request->masterlistTimeSpent >= 0) {
                    $masterlistTimeSpent = intval($request->masterlistTimeSpent);
                }

                $masterlistId = DB::table('dcs_masterlist_registration')->insertGetId([
                    'checklist_id' => 3,
                    'version_id' => $versionId,
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
                    'revise_no' => $request->masterlistRevisionNo,
                    'revision_status' => 'latest',
                    'no_pages' => $request->masterlistNoOfPages,
                    'originator_name' => $request->masterlistOriginator,
                    'deadline' => $request->deadlineOfSubmission,
                    'brief_purpose' => $request->briefPurpose,
                    'scanned_masterlist' => $masterlistFile,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

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
                    $masterlistFile = $request->file('uploadScannedCopy')->store('scans/masterlist', 'public');
                    $uploadedFiles[] = $masterlistFile;
                }

                $masterlistTimeSpent = null;
                if ($request->filled('masterlistTimeSpent') && is_numeric($request->masterlistTimeSpent) && $request->masterlistTimeSpent >= 0) {
                    $masterlistTimeSpent = intval($request->masterlistTimeSpent);
                }

                $masterlistData = [
                    'checklist_id' => 3,
                    'version_id' => $versionId,
                    'doc_type_id' => $docTypeId,
                    'doc_no' => $request->syllabiDocNo,
                    'doc_title' => $request->syllabiDocTitle,
                    'doc_receipt_date' => $request->masterlistReceiptDate,
                    'doc_receipt_time' => $request->masterlistReceiptTime,
                    'doc_registered_date' => $request->masterlistRegisteredDate,
                    'doc_registered_time' => $request->masterlistRegisteredTime,
                    'time_spent' => $masterlistTimeSpent,
                    'effectivity_date' => $request->syllabiEffectivityDate,
                    'deadline' => $request->syllabiDeadline,
                    'revise_no' => $request->masterlistRevisionNo ?? 0,
                    'revision_status' => 'latest',
                    'no_pages' => $totalPages,
                    'originator_name' => $request->masterlistOriginator,
                    'brief_purpose' => $request->briefPurpose,
                    'scanned_masterlist' => $masterlistFile,
                    'updated_at' => $now,
                ];

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

                self::saveSyllabiRowsFromRequest($requestId, $versionId, $docTypeId, $request, $uploadedFiles);
            }

            if (in_array(4, $checkedChecklists, true)) {
                $retrievalFile = null;
                if ($request->hasFile('scannedRet')) {
                    $retrievalFile = $request->file('scannedRet')->store('scans/retrieval', 'public');
                    $uploadedFiles[] = $retrievalFile;
                }

                $retrievalTimeSpent = null;
                if ($request->filled('retrievalTimeSpent') && is_numeric($request->retrievalTimeSpent) && $request->retrievalTimeSpent >= 0) {
                    $retrievalTimeSpent = intval($request->retrievalTimeSpent);
                }

                $retrievalId = DB::table('dcs_document_retrieval')->insertGetId([
                    'checklist_id' => 4,
                    'version_id' => $versionId,
                    'request_id' => $requestId,
                    'doc_type_id' => $docTypeId,
                    'doc_retrieval_date_actual' => $request->retrievalDate,
                    'doc_retrieval_time_actual' => $request->retrievalTime,
                    'doc_retrieval_date_file' => $request->retrievalFormDate,
                    'doc_retrieval_time_file' => $request->retrievalFormTime,
                    'time_spent' => $retrievalTimeSpent,
                    'remarks' => $request->retrievalRemarks,
                    'scanned_retrieval' => $retrievalFile,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($request->has('retrievalOffice')) {
                    foreach ($request->retrievalOffice as $i => $officeId) {
                        $id = (int) $officeId;
                        if ($id <= 0) {
                            continue;
                        }
                        DB::table('dcs_retrieval_offices')->insert([
                            'retrieval_id' => $retrievalId,
                            'office_id' => $id,
                            'copies' => $request->retrievalCopies[$i] ?? 1,
                        ]);
                    }
                }
            }

            if (in_array(5, $checkedChecklists, true)) {
                $distFile = null;
                if ($request->hasFile('scanneddist')) {
                    $distFile = $request->file('scanneddist')->store('scans/distribution', 'public');
                    $uploadedFiles[] = $distFile;
                }

                $distTimeSpent = null;
                if ($request->filled('distributionTimeSpent') && is_numeric($request->distributionTimeSpent) && $request->distributionTimeSpent >= 0) {
                    $distTimeSpent = intval($request->distributionTimeSpent);
                }

                $distributionId = DB::table('dcs_document_distribution')->insertGetId([
                    'checklist_id' => 5,
                    'version_id' => $versionId,
                    'request_id' => $requestId,
                    'doc_type_id' => $docTypeId,
                    'doc_distribution_date_actual' => $request->distributionDate,
                    'doc_distribution_time_actual' => $request->distributionTime,
                    'doc_distribution_date_file' => $request->distributionFormDate,
                    'doc_distribution_time_file' => $request->distributionFormTime,
                    'time_spent' => $distTimeSpent,
                    'remarks' => $request->distributionRemarks,
                    'scanned_distribution' => $distFile,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($request->has('distOffice')) {
                    self::saveDistributionOffices($distributionId, $request);
                }
            }

            if ($request->approval_status === 'applicable' && $request->filled('approvalBody')) {
                DB::table('dcs_approval_records')->insert([
                    'checklist_id' => null,
                    'version_id' => $versionId,
                    'request_id' => $requestId,
                    'doc_type_id' => $docTypeId,
                    'approval_body_id' => $request->approvalBody,
                    'approval_date' => $request->approvalDate,
                    'approval_no' => $request->approvalNo,
                ]);
            }

            $savedMl = DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first();
            if ($savedMl) {
                self::syncRevisionStatusForMasterlist((int) $savedMl->id);
            }

            DB::commit();

            foreach ($filesToDelete as $file) {
                if ($file) {
                    Storage::disk('public')->delete($file);
                }
            }

            return redirect()->route('dcs.register.edit', $requestId)
                ->with('success', 'Document registered successfully!');
        } catch (\Throwable $e) {
            DB::rollBack();

            foreach ($uploadedFiles as $file) {
                Storage::disk('public')->delete($file);
            }

            $refId = uniqid('err_');
            Log::error("Document registration failed [{$refId}]: " . $e->getMessage());

            return back()->withInput()
                ->with('error', 'Failed to save document. Please try again. (ref: ' . $refId . ')');
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

    public static function findMatchingRegistrationRows(string $docNo, int $docTypeId, ?int $subTypeId): array
    {
        $allMl = DB::table('dcs_masterlist_registration')->where('doc_no', $docNo)->get();

        if ($allMl->isEmpty()) {
            return ['found' => false, 'reason' => 'not_registered'];
        }

        $requestIds = $allMl->pluck('request_id')->unique()->filter();
        $relatedDocRequests = DB::table('dcs_document_requests')->whereIn('id', $requestIds)->get();
        $hasSubType = $subTypeId && (int) $subTypeId > 0;

        $matching = $relatedDocRequests->filter(function ($dr) use ($docTypeId, $subTypeId, $hasSubType) {
            if ((int) $dr->doc_type_id !== (int) $docTypeId) {
                return false;
            }
            if ($hasSubType) {
                return $dr->sub_type_id && (int) $dr->sub_type_id === (int) $subTypeId;
            }

            return $dr->sub_type_id === null || $dr->sub_type_id === '';
        });

        if ($matching->isNotEmpty()) {
            $matchingIds = $matching->pluck('id');
            $latest = DB::table('dcs_masterlist_registration')
                ->whereIn('request_id', $matchingIds)
                ->where('doc_no', $docNo)
                ->where('revision_status', 'latest')
                ->orderByDesc('id')
                ->first();
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

    public static function validateSyllabiLikeRequestRows(Request $request): ?RedirectResponse
    {
        $subType = self::dcsDocType($request->sub_type_id);
        $isSyllabi = self::isSyllabiLikeSubTypeRow($subType);

        if (!$isSyllabi || !$request->has('syllabiCourseName')) {
            return null;
        }

        $courseNames = $request->syllabiCourseName;
        $copiesArr = $request->syllabiCopies ?? [];
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

            for ($c = 0; $c < $copies; $c++) {
                $rowIdx = $i + $c;
                if ($rowIdx >= $total) {
                    break;
                }
                $copyNum = $c + 1;
                $rowLabel = "Syllabi \"{$courseLabel}\" (Copy {$copyNum})";

                if ($copies > 1) {
                    $facultyCount = count(array_filter(array_map('trim', explode(',', $request->syllabiFaculty[$rowIdx] ?? ''))));
                    if ($facultyCount > 1) {
                        return back()->withInput()->with('error',
                            "{$rowLabel}: Only one faculty per row is allowed when copies are split across rows.");
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
            'college_id' => 'nullable|integer|exists:dcs_colleges,id',
            'program_id' => 'nullable|integer|exists:dcs_programs,id',
            'semester_id' => 'nullable|integer|exists:dcs_semesters,id',
            'school_year_id' => 'nullable|integer|exists:dcs_school_years,id',
            'syllabiDocNo' => 'nullable|string',
            'syllabiDocTitle' => 'nullable|string',
            'syllabiEffectivityDate' => 'nullable|date',
            'syllabiDeadline' => 'nullable|date',
        ]);

        return null;
    }

    public static function resolveRevisionScannedCopyPath(Request $request, int $i, array &$uploadedFiles, array $allowedPaths = []): ?string
    {
        if ($request->hasFile('scannedCopy') && isset($request->file('scannedCopy')[$i])) {
            $path = $request->file('scannedCopy')[$i]->store('scans/revisions', 'public');
            $uploadedFiles[] = $path;

            return $path;
        }

        $source = $request->input('revisionScannedPath')[$i] ?? null;
        if (!is_string($source) || trim($source) === '') {
            return null;
        }

        $source = ltrim(str_replace(['../', '..\\'], '', $source), '/');
        if (!self::isKnownPublicScanPath($source, $allowedPaths)) {
            return null;
        }

        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $dest = 'scans/revisions/' . uniqid('rev_', true) . ($ext !== '' ? '.' . $ext : '');
        Storage::disk('public')->copy($source, $dest);
        $uploadedFiles[] = $dest;

        return $dest;
    }

    public static function saveDcnOfficesById(int $dcnId, array $officeIds): void
    {
        DB::table('dcs_dcn_offices')->where('dcn_id', $dcnId)->delete();
        $firstId = null;
        $now = now();
        foreach ($officeIds as $officeId) {
            $id = (int) trim((string) $officeId);
            if ($id <= 0) {
                continue;
            }
            DB::table('dcs_dcn_offices')->insert([
                'dcn_id' => $dcnId,
                'office_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $firstId ??= $id;
        }
        DB::table('dcs_document_change_notice')->where('id', $dcnId)->update(['office_id' => $firstId]);
    }

    public static function saveOriginsFromOfficeIds(int $masterlistId, array $officeIds): void
    {
        $now = now();
        foreach ($officeIds as $officeId) {
            $id = (int) trim((string) $officeId);
            if ($id <= 0) {
                continue;
            }
            DB::table('dcs_masterlist_source_offices')->insert([
                'masterlist_id' => $masterlistId,
                'office_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
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

        foreach ($relatedIds as $id) {
            if ((int) $id === (int) $masterlistId) {
                continue;
            }
            DB::table('dcs_masterlist_related_docs')->insert([
                'masterlist_id' => $masterlistId,
                'related_doc_id' => (int) $id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public static function saveSyllabiRowsFromRequest(
        int $requestId,
        int $versionId,
        int $docTypeId,
        Request $request,
        array &$uploadedFiles,
        array $allowedExistingPaths = []
    ): void {
        if (!$request->has('syllabiCourseName')) {
            return;
        }

        $courseNames = $request->syllabiCourseName;
        $courseCodes = $request->syllabiCourseCode ?? [];
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
        $hasCourseCode = Schema::hasColumn('dcs_program_courses', 'course_code');

        while ($i < $total) {
            $courseName = $courseNames[$i];
            $copies = max(1, (int) ($copiesArr[$i] ?? 1));

            if (empty($courseName)) {
                $i += $copies;
                continue;
            }

            if (empty($request->program_id) || empty($request->semester_id)) {
                $i += $copies;
                continue;
            }

            $course = DB::table('dcs_program_courses')
                ->where('program_id', $request->program_id)
                ->where('semester_id', $request->semester_id)
                ->where('course_name', $courseName)
                ->first();

            $courseCode = trim((string) ($courseCodes[$i] ?? ''));
            $courseCode = $courseCode !== '' ? $courseCode : null;
            $codeInUse = $hasCourseCode && $courseCode
                ? self::programCourseCodeTaken(
                    (int) $request->program_id,
                    (int) $request->semester_id,
                    $courseCode,
                    $course?->id
                )
                : false;

            if (!$course) {
                $insert = [
                    'program_id' => $request->program_id,
                    'semester_id' => $request->semester_id,
                    'course_name' => $courseName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if ($hasCourseCode) {
                    $insert['course_code'] = $codeInUse ? null : $courseCode;
                }
                $courseId = DB::table('dcs_program_courses')->insertGetId($insert);
            } else {
                $courseId = $course->id;
                if ($hasCourseCode && $courseCode && !$codeInUse && ($course->course_code ?? null) !== $courseCode) {
                    DB::table('dcs_program_courses')->where('id', $courseId)->update([
                        'course_code' => $courseCode,
                        'updated_at' => $now,
                    ]);
                }
            }

            $syllabiId = DB::table('dcs_syllabi')->insertGetId([
                'request_id' => $requestId,
                'doc_type_id' => $docTypeId,
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

                $facultyNames = array_filter(array_map('trim', explode(',', $facultyArr[$rowIdx] ?? '')));
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

                    DB::table('dcs_syllabi_drf')->insert([
                        'syllabi_id' => $syllabiId,
                        'faculty_id' => $facultyId,
                        'faculty_name' => $facultyName,
                        'is_drf_available' => ($drfAvailArr[$rowIdx] ?? 'not available') === 'available',
                        'drf_no' => $drfNoArr[$rowIdx] ?? null,
                        'drf_date' => $drfDateArr[$rowIdx] ?? null,
                        'drf_received_date' => $drfRecvArr[$rowIdx] ?? null,
                        'scanned_drf' => $scannedDrf,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $i += $copies;
        }
    }

    private static function programCourseCodeTaken(int $programId, int $semesterId, string $courseCode, ?int $exceptId = null): bool
    {
        $query = DB::table('dcs_program_courses')
            ->where('program_id', $programId)
            ->where('semester_id', $semesterId)
            ->where('course_code', $courseCode);
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
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

        return $file->store($directory, 'public');
    }

    public static function syncRevisionStatusForMasterlist(int $masterlistId): void
    {
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

        self::markLatestMasterlist(
            $ml->doc_no,
            (int) $dr->doc_type_id,
            $dr->sub_type_id ? (int) $dr->sub_type_id : null,
            $masterlistId
        );
    }

    public static function markLatestMasterlist(string $docNo, int $docTypeId, ?int $subTypeId, int $latestMasterlistId): void
    {
        $requestIds = RegisterQueryHelper::requestIdsWithSameDocType((object) [
            'doc_type_id' => $docTypeId,
            'sub_type_id' => $subTypeId,
        ]);

        $family = DB::table('dcs_masterlist_registration')
            ->where('doc_no', $docNo)
            ->whereIn('request_id', $requestIds);

        $family->where('id', '!=', $latestMasterlistId)
            ->update(['revision_status' => 'obsolete', 'updated_at' => now()]);

        DB::table('dcs_masterlist_registration')
            ->where('id', $latestMasterlistId)
            ->update(['revision_status' => 'latest', 'updated_at' => now()]);
    }

    public static function promoteLatestForDoc(string $docNo, int $docTypeId, ?int $subTypeId): void
    {
        $requestIds = RegisterQueryHelper::requestIdsWithSameDocType((object) [
            'doc_type_id' => $docTypeId,
            'sub_type_id' => $subTypeId,
        ]);

        $next = DB::table('dcs_masterlist_registration')
            ->where('doc_no', $docNo)
            ->whereIn('request_id', $requestIds)
            ->orderByDesc('revise_no')
            ->orderByDesc('id')
            ->first();

        if ($next) {
            self::markLatestMasterlist($docNo, $docTypeId, $subTypeId, (int) $next->id);
        }
    }
}
