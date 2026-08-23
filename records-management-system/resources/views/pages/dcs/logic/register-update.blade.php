<?php

namespace App\Helpers;

use App\Services\StampBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class RegisterUpdateHelper
{
    /** Build a real RedirectResponse (avoid Livewire's redirect() Redirector). */
    private static function flashRedirect(string $route, string $type, string $message, array $params = []): RedirectResponse
    {
        session()->flash($type, $message);

        return new RedirectResponse(route($route, $params, false));
    }

    public static function update(Request $request, int $id): RedirectResponse
    {
        RegisterPersistHelper::blankStringsToNull($request);

        if ($redirect = RegisterPersistHelper::rejectInactiveOfficeIds($request)) {
            return $redirect;
        }

        $request->validate(array_merge([
            'approval_status' => 'nullable|in:applicable,not_applicable',
        ], RegisterPersistHelper::scanFileRules()));

        if ($redirect = RegisterPersistHelper::validateSyllabiLikeRequestRows($request)) {
            return $redirect;
        }

        $docRequest = RegisterQueryHelper::findDocumentRequest($id);
        abort_unless($docRequest, 404);

        $ml = DB::table('dcs_masterlist_registration')->where('request_id', $id)->first();
        $editingObsolete = $ml && $ml->doc_no
            && strtolower(trim((string) ($ml->revision_status ?? ''))) === 'obsolete';

        $previousDocNo = $ml?->doc_no ?? null;

        $docNo = RegisterPersistHelper::isSyllabiLikeSubTypeRow(RegisterPersistHelper::dcsDocType($docRequest->sub_type_id))
            ? $request->input('syllabiDocNo')
            : $request->input('masterlistDocNo');
        if ($docNo) {
            $result = RegisterPersistHelper::findMatchingRegistrationRows(
                $docNo,
                (int) $docRequest->doc_type_id,
                $docRequest->sub_type_id ? (int) $docRequest->sub_type_id : null
            );
            if ($result['found']) {
                $reviseNo = $ml?->revise_no ?? $request->input('masterlistRevisionNo');
                $collision = DB::table('dcs_masterlist_registration')
                    ->whereIn('request_id', $result['matches']->pluck('id'))
                    ->where('request_id', '!=', $id)
                    ->where('doc_no', $docNo)
                    ->where('revise_no', $reviseNo)
                    ->exists();
                if ($collision) {
                    return back()->withInput()
                        ->with('error', 'Revision ' . $reviseNo . ' for document "' . $docNo . '" already exists.');
                }
            }
        }

        $requestId = $id;
        $fromDb = [];
        if (DB::table('dcs_document_request_form')->where('request_id', $requestId)->exists()) {
            $fromDb[] = 1;
        }
        if (DB::table('dcs_document_change_notice')->where('request_id', $requestId)->exists()) {
            $fromDb[] = 2;
        }
        if (DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->exists()
            || DB::table('dcs_syllabi')->where('request_id', $requestId)->exists()) {
            $fromDb[] = 3;
        }
        if (DB::table('dcs_document_retrieval')->where('request_id', $requestId)->exists()) {
            $fromDb[] = 4;
        }
        if (DB::table('dcs_document_distribution')->where('request_id', $requestId)->exists()) {
            $fromDb[] = 5;
        }
        $fromPost = array_map('intval', $request->input('checklists', []));
        $checkedChecklists = array_values(array_unique(array_merge($fromDb, $fromPost)));
        $request->merge([
            'sub_type_id' => $docRequest->sub_type_id,
            'checklists' => $checkedChecklists,
        ]);
        if ($redirect = RegisterPersistHelper::validateCheckedSections($request)) {
            return $redirect;
        }

        DB::beginTransaction();
        $uploadedFiles = [];
        $filesToDelete = [];

        try {
            $now = now();
            $approvalStatus = $request->input('approval_status', $docRequest->approval_status);
            DB::table('dcs_document_requests')->where('id', $id)->update([
                'version_id' => $docRequest->version_id,
                'doc_type_id' => $docRequest->doc_type_id,
                'sub_type_id' => $docRequest->sub_type_id ?: null,
                'approval_status' => $approvalStatus,
                'updated_by' => auth()->id(),
                'updated_at' => $now,
            ]);

            $docTypeId = $docRequest->doc_type_id;
            $versionId = $docRequest->version_id;
            $userId = auth()->id();

            if ($approvalStatus !== 'applicable') {
                DB::table('dcs_approval_records')->where('request_id', $requestId)->delete();
            }

            if (in_array(1, $checkedChecklists, true)) {
                $drf = DB::table('dcs_document_request_form')->where('request_id', $requestId)->first();
                $drfFile = $drf ? $drf->scanned_drf : null;
                if ($request->hasFile('drfFile')) {
                    StampBackupService::invalidate($requestId, 'drf');
                    if ($drfFile) {
                        $filesToDelete[] = $drfFile;
                    }
                    $drfFile = $request->file('drfFile')->store('scans/drf', 'public');
                    $uploadedFiles[] = $drfFile;
                }
                $drfOfficeIds = array_values(array_filter($request->input('drfSourceUnit', [])));
                $drfData = [
                    'checklist_id' => 1,
                    'version_id' => $versionId,
                    'doc_type_id' => $docTypeId,
                    'drf_no' => $request->drfNo,
                    'drf_date' => $request->drfDate,
                    'drf_receipt_date' => $request->drfReceiptDate,
                    'drf_receipt_time' => $request->drfTime,
                    'doc_title' => RegisterPersistHelper::syncedDocTitle($request) ?: $request->drfTitle,
                    'scanned_drf' => $drfFile,
                    'updated_at' => $now,
                ];
                if ($drf) {
                    DB::table('dcs_document_request_form')->where('id', $drf->id)->update($drfData);
                    $drfId = $drf->id;
                } else {
                    $drfId = DB::table('dcs_document_request_form')->insertGetId(array_merge($drfData, [
                        'request_id' => $requestId,
                        'created_by' => $userId,
                        'created_at' => $now,
                    ]));
                }
                DB::table('dcs_drf_offices')->where('document_request_form_id', $drfId)->delete();
                foreach ($drfOfficeIds as $officeId) {
                    $oid = (int) $officeId;
                    if ($oid <= 0) {
                        continue;
                    }
                    DB::table('dcs_drf_offices')->insert([
                        'document_request_form_id' => $drfId,
                        'office_id' => $oid,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            if (in_array(2, $checkedChecklists, true)) {
                $dcn = DB::table('dcs_document_change_notice')->where('request_id', $requestId)->first();
                $dcnFile = $dcn ? $dcn->scanned_dcn : null;
                if ($request->hasFile('dcnFile')) {
                    StampBackupService::invalidate($requestId, 'dcn');
                    if ($dcnFile) {
                        $filesToDelete[] = $dcnFile;
                    }
                    $dcnFile = $request->file('dcnFile')->store('scans/dcn', 'public');
                    $uploadedFiles[] = $dcnFile;
                }
                $dcnOfficeIds = array_values(array_filter($request->input('dcnSourceUnit', [])));
                $firstDcnOffice = $dcnOfficeIds[0] ?? null;
                $dcnData = [
                    'checklist_id' => 2,
                    'version_id' => $versionId,
                    'doc_type_id' => $docTypeId,
                    'dcn_no' => $request->dcnNumber,
                    'dcn_date' => $request->noticeDate,
                    'dcn_receipt_date' => $request->receiptDate,
                    'dcn_receipt_time' => $request->receiptTime,
                    'office_id' => $firstDcnOffice ?: null,
                    'scanned_dcn' => $dcnFile,
                    'brief_purpose' => $request->dcnJustification,
                    'updated_at' => $now,
                ];
                if ($dcn) {
                    DB::table('dcs_document_change_notice')->where('id', $dcn->id)->update($dcnData);
                    $dcnId = $dcn->id;
                } else {
                    $dcnId = DB::table('dcs_document_change_notice')->insertGetId(array_merge($dcnData, [
                        'request_id' => $requestId,
                        'created_by' => $userId,
                        'created_at' => $now,
                    ]));
                }
                RegisterPersistHelper::saveDcnOfficesById($dcnId, $dcnOfficeIds);

                $oldRevisions = DB::table('dcs_doc_revision')->where('dcn_id', $dcnId)->get();
                $allowedRevPaths = $oldRevisions->pluck('scanned_copy')->filter()->values()->all();
                foreach ($oldRevisions as $rev) {
                    if ($rev->scanned_copy) {
                        $filesToDelete[] = $rev->scanned_copy;
                    }
                }
                DB::table('dcs_doc_revision')->where('dcn_id', $dcnId)->delete();

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
                            'scanned_copy' => RegisterPersistHelper::resolveRevisionScannedCopyPath($request, $i, $uploadedFiles, $allowedRevPaths),
                            'brief_purpose' => $request->revisionPurpose[$i] ?? null,
                            'created_at' => $now,
                        ]);
                    }
                }
            }

            $subType = RegisterPersistHelper::dcsDocType($docRequest->sub_type_id);
            $isSyllabi = RegisterPersistHelper::isSyllabiLikeSubTypeRow($subType);

            if (in_array(3, $checkedChecklists, true) && !$isSyllabi) {
                $masterlist = DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first();
                $masterlistFile = $masterlist ? $masterlist->scanned_masterlist : null;
                if ($request->hasFile('uploadScannedCopy')) {
                    StampBackupService::invalidate($requestId, 'masterlist');
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
                    'doc_no' => $request->masterlistDocNo,
                    'doc_receipt_date' => $request->masterlistReceiptDate,
                    'doc_receipt_time' => $request->masterlistReceiptTime,
                    'doc_registered_date' => $request->masterlistRegisteredDate,
                    'doc_registered_time' => $request->masterlistRegisteredTime,
                    'time_spent' => $masterlistTimeSpent,
                    'doc_title' => RegisterPersistHelper::syncedDocTitle($request) ?: $request->masterlistDocTitle,
                    'effectivity_date' => $request->masterlistEffectivityDate,
                    'revise_no' => $masterlist?->revise_no ?? $request->masterlistRevisionNo,
                    'no_pages' => $request->masterlistNoOfPages,
                    'originator_name' => $request->masterlistOriginator,
                    'deadline' => $request->deadlineOfSubmission,
                    'scanned_masterlist' => $masterlistFile,
                    'updated_at' => $now,
                ];
                $keywordVal = $request->keywords ?? $request->briefPurpose;
                if (Schema::hasColumn('dcs_masterlist_registration', 'keywords')) {
                    $masterlistData['keywords'] = $keywordVal;
                } else {
                    $masterlistData['brief_purpose'] = $keywordVal;
                }
                if ($previousDocNo
                    && $request->masterlistDocNo
                    && strcasecmp($previousDocNo, trim((string) $request->masterlistDocNo)) !== 0
                    && Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
                    $masterlistData['revised_from_doc_no'] = $previousDocNo;
                }
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
                RegisterPersistHelper::saveOriginsFromOfficeIds($masterlistId, $request->input('masterlistOfficeIds', []));
                $relatedIds = array_filter(array_map('intval', $request->input('relatedDocumentIds', [])));
                RegisterPersistHelper::saveRelatedDocumentIds($masterlistId, $relatedIds);
            }

            if (!$isSyllabi) {
                self::invalidateSyllabiStamps($requestId);
                self::queueSyllabiFiles($requestId, $filesToDelete);
                DB::table('dcs_syllabi')->where('request_id', $requestId)->delete();
            }

            if ($isSyllabi && in_array(3, $checkedChecklists, true)) {
                $totalPages = 0;
                if ($request->has('syllabiNoPages')) {
                    $totalPages = array_sum(array_filter($request->syllabiNoPages, fn ($p) => is_numeric($p) && $p > 0));
                }
                $masterlist = DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first();
                $masterlistFile = $masterlist ? $masterlist->scanned_masterlist : null;
                if ($request->hasFile('uploadScannedCopy')) {
                    StampBackupService::invalidate($requestId, 'masterlist');
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
                    'revise_no' => $masterlist?->revise_no ?? $request->masterlistRevisionNo ?? 0,
                    'no_pages' => $totalPages,
                    'originator_name' => $request->masterlistOriginator,
                    'scanned_masterlist' => $masterlistFile,
                    'updated_at' => $now,
                ];
                $syllabiKeywordVal = $request->keywords ?? $request->briefPurpose;
                if (Schema::hasColumn('dcs_masterlist_registration', 'keywords')) {
                    $masterlistData['keywords'] = $syllabiKeywordVal;
                } else {
                    $masterlistData['brief_purpose'] = $syllabiKeywordVal;
                }
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
                RegisterPersistHelper::saveOriginsFromOfficeIds($masterlistId, $request->input('masterlistOfficeIds', []));
                $relatedIds = array_filter(array_map('intval', $request->input('relatedDocumentIds', [])));
                RegisterPersistHelper::saveRelatedDocumentIds($masterlistId, $relatedIds);

                $keepPaths = DB::table('dcs_syllabi as s')
                    ->join('dcs_syllabi_drf as sd', 'sd.syllabi_id', '=', 's.id')
                    ->where('s.request_id', $requestId)
                    ->whereNotNull('sd.scanned_drf')
                    ->pluck('sd.scanned_drf')
                    ->all();
                self::invalidateSyllabiStamps($requestId);
                self::queueSyllabiFiles($requestId, $filesToDelete, $keepPaths);
                DB::table('dcs_syllabi')->where('request_id', $requestId)->delete();
                RegisterPersistHelper::saveSyllabiRowsFromRequest($requestId, $versionId, $docTypeId, $request, $uploadedFiles, $keepPaths);
            }

            if (in_array(4, $checkedChecklists, true)) {
                $retrieval = DB::table('dcs_document_retrieval')->where('request_id', $requestId)->first();
                $retrievalFile = $retrieval ? $retrieval->scanned_retrieval : null;
                if ($request->hasFile('scannedRet')) {
                    StampBackupService::invalidate($requestId, 'retrieval');
                    if ($retrievalFile) {
                        $filesToDelete[] = $retrievalFile;
                    }
                    $retrievalFile = $request->file('scannedRet')->store('scans/retrieval', 'public');
                    $uploadedFiles[] = $retrievalFile;
                }
                $retrievalTimeSpent = null;
                if ($request->filled('retrievalTimeSpent') && is_numeric($request->retrievalTimeSpent) && $request->retrievalTimeSpent >= 0) {
                    $retrievalTimeSpent = intval($request->retrievalTimeSpent);
                }
                $retrievalData = [
                    'checklist_id' => 4,
                    'version_id' => $versionId,
                    'doc_type_id' => $docTypeId,
                    'doc_retrieval_date_actual' => $request->retrievalDate,
                    'doc_retrieval_time_actual' => $request->retrievalTime,
                    'doc_retrieval_date_file' => $request->retrievalFormDate,
                    'doc_retrieval_time_file' => $request->retrievalFormTime,
                    'time_spent' => $retrievalTimeSpent,
                    'remarks' => $request->retrievalRemarks,
                    'scanned_retrieval' => $retrievalFile,
                    'updated_at' => $now,
                ];
                if ($retrieval) {
                    DB::table('dcs_document_retrieval')->where('id', $retrieval->id)->update($retrievalData);
                    $retrievalId = $retrieval->id;
                } else {
                    $retrievalId = DB::table('dcs_document_retrieval')->insertGetId(array_merge($retrievalData, [
                        'request_id' => $requestId,
                        'created_by' => $userId,
                        'created_at' => $now,
                    ]));
                }
                DB::table('dcs_retrieval_offices')->where('retrieval_id', $retrievalId)->delete();
                if ($request->has('retrievalOffice')) {
                    foreach ($request->retrievalOffice as $i => $officeId) {
                        $oid = (int) $officeId;
                        if ($oid <= 0) {
                            continue;
                        }
                        $retrievalOfficeRow = [
                            'retrieval_id' => $retrievalId,
                            'office_id' => $oid,
                            'copies' => $request->retrievalCopies[$i] ?? 1,
                            'retrieval_status' => Schema::hasColumn('dcs_retrieval_offices', 'retrieval_status')
                                ? ($request->input('retrievalStatus')[$i] ?? 'pending')
                                : null,
                        ];
                        if (Schema::hasColumn('dcs_retrieval_offices', 'retrieval_date')) {
                            $retrievalOfficeRow['retrieval_date'] = $request->input('retrievalOfficeDate')[$i] ?? null;
                        }
                        DB::table('dcs_retrieval_offices')->insert($retrievalOfficeRow);
                    }
                }
            }

            if (in_array(5, $checkedChecklists, true)) {
                $distribution = DB::table('dcs_document_distribution')->where('request_id', $requestId)->first();
                $distFile = $distribution ? $distribution->scanned_distribution : null;
                if ($request->hasFile('scanneddist')) {
                    StampBackupService::invalidate($requestId, 'distribution');
                    if ($distFile) {
                        $filesToDelete[] = $distFile;
                    }
                    $distFile = $request->file('scanneddist')->store('scans/distribution', 'public');
                    $uploadedFiles[] = $distFile;
                }
                $distTimeSpent = null;
                if ($request->filled('distributionTimeSpent') && is_numeric($request->distributionTimeSpent) && $request->distributionTimeSpent >= 0) {
                    $distTimeSpent = intval($request->distributionTimeSpent);
                }
                $distData = [
                    'checklist_id' => 5,
                    'version_id' => $versionId,
                    'doc_type_id' => $docTypeId,
                    'doc_distribution_date_actual' => $request->distributionDate,
                    'doc_distribution_time_actual' => $request->distributionTime,
                    'doc_distribution_date_file' => $request->distributionFormDate,
                    'doc_distribution_time_file' => $request->distributionFormTime,
                    'time_spent' => $distTimeSpent,
                    'remarks' => $request->distributionRemarks,
                    'scanned_distribution' => $distFile,
                    'updated_at' => $now,
                ];
                if ($distribution) {
                    DB::table('dcs_document_distribution')->where('id', $distribution->id)->update($distData);
                    $distributionId = $distribution->id;
                } else {
                    $distributionId = DB::table('dcs_document_distribution')->insertGetId(array_merge($distData, [
                        'request_id' => $requestId,
                        'created_by' => $userId,
                        'created_at' => $now,
                    ]));
                }
                DB::table('dcs_distribution_offices')->where('distribution_id', $distributionId)->delete();
                RegisterPersistHelper::saveDistributionOffices($distributionId, $request);
            }

            if ($approvalStatus === 'applicable' && $request->filled('approvalBody')) {
                $approval = DB::table('dcs_approval_records')->where('request_id', $requestId)->first();
                $approvalData = [
                    'checklist_id' => null,
                    'version_id' => $versionId,
                    'doc_type_id' => $docTypeId,
                    'approval_body_id' => $request->approvalBody,
                    'approval_date' => $request->approvalDate,
                    'approval_no' => $request->approvalNo,
                ];
                if ($approval) {
                    DB::table('dcs_approval_records')->where('id', $approval->id)->update($approvalData);
                } else {
                    DB::table('dcs_approval_records')->insert(array_merge($approvalData, [
                        'request_id' => $requestId,
                    ]));
                }
            }

            $savedMl = DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first();
            if ($savedMl) {
                if ($editingObsolete) {
                    // Keep the current tip as latest; do not promote this obsolete row.
                    RegisterPersistHelper::promoteLatestForDoc(
                        (string) $savedMl->doc_no,
                        (int) $docRequest->doc_type_id,
                        $docRequest->sub_type_id ? (int) $docRequest->sub_type_id : null
                    );
                } else {
                    RegisterPersistHelper::syncRevisionStatusForMasterlist((int) $savedMl->id);
                }
            }
            if ($previousDocNo && $savedMl && $previousDocNo !== $savedMl->doc_no) {
                RegisterPersistHelper::promoteLatestForDoc(
                    $previousDocNo,
                    (int) $docRequest->doc_type_id,
                    $docRequest->sub_type_id ? (int) $docRequest->sub_type_id : null
                );
            }

            DB::commit();
            foreach ($filesToDelete as $file) {
                Storage::disk('public')->delete($file);
            }

            return redirect()->route('dcs.register.edit', $id)
                ->with('success', 'Document updated successfully!');
        } catch (\Throwable $e) {
            DB::rollBack();
            foreach ($uploadedFiles as $file) {
                Storage::disk('public')->delete($file);
            }
            $refId = uniqid('err_');
            Log::error("Document update failed [{$refId}]: " . $e->getMessage());

            return back()->withInput()
                ->with('error', 'Failed to update document. Please try again. (ref: ' . $refId . ')');
        }
    }

    public static function destroy(int $id): RedirectResponse
    {
        if (!RegisterQueryHelper::supportsSoftDelete()) {
            return self::flashRedirect(
                'dcs.register.update',
                'error',
                'Recycle bin requires a pending database migration. Run php artisan migrate.'
            );
        }

        $docRequest = RegisterQueryHelper::findDocumentRequest($id);
        abort_unless($docRequest, 404);

        $ml = DB::table('dcs_masterlist_registration')->where('request_id', $id)->first();
        if ($ml && $ml->doc_no && ($ml->revision_status ?? 'latest') !== 'latest') {
            return self::flashRedirect(
                'dcs.register.update',
                'error',
                'Only the latest revision can be deleted.'
            );
        }

        $promoteDocNo = $ml?->doc_no ?? null;
        $promoteTypeId = (int) $docRequest->doc_type_id;
        $promoteSubTypeId = $docRequest->sub_type_id ? (int) $docRequest->sub_type_id : null;

        DB::beginTransaction();
        try {
            $now = now();
            $update = ['deleted_at' => $now, 'updated_at' => $now];
            if (Schema::hasColumn('dcs_document_requests', 'deleted_by')) {
                $update['deleted_by'] = auth()->id();
            }
            DB::table('dcs_document_requests')->where('id', $id)->update($update);

            if ($promoteDocNo) {
                RegisterPersistHelper::promoteLatestForDoc($promoteDocNo, $promoteTypeId, $promoteSubTypeId);
            }

            DB::commit();

            return self::flashRedirect(
                'dcs.register.update',
                'success',
                'Document moved to the recycle bin.'
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            $refId = uniqid('err_');
            Log::error("Document soft-delete failed [{$refId}]: " . $e->getMessage());

            return self::flashRedirect(
                'dcs.register.update',
                'error',
                'Failed to delete document. Please try again. (ref: ' . $refId . ')'
            );
        }
    }

    public static function restore(int $id): RedirectResponse
    {
        $docRequest = RegisterQueryHelper::findTrashedDocumentRequest($id);
        abort_unless($docRequest, 404);

        $ml = DB::table('dcs_masterlist_registration')->where('request_id', $id)->first();
        $now = now();
        $update = ['deleted_at' => null, 'updated_at' => $now];
        if (Schema::hasColumn('dcs_document_requests', 'deleted_by')) {
            $update['deleted_by'] = null;
        }
        DB::table('dcs_document_requests')->where('id', $id)->update($update);

        if ($ml) {
            RegisterPersistHelper::syncRevisionStatusForMasterlist((int) $ml->id);
        }

        return self::flashRedirect(
            'dcs.recycle-bin',
            'success',
            'Document restored successfully.'
        );
    }

    public static function permanentDestroy(int $id): RedirectResponse
    {
        $docRequest = RegisterQueryHelper::findTrashedDocumentRequest($id);
        abort_unless($docRequest, 404);

        DB::beginTransaction();
        $filesToDelete = [];
        try {
            $drf = DB::table('dcs_document_request_form')->where('request_id', $id)->first();
            if ($drf) {
                if ($drf->scanned_drf) {
                    $filesToDelete[] = $drf->scanned_drf;
                }
                DB::table('dcs_drf_offices')->where('document_request_form_id', $drf->id)->delete();
                DB::table('dcs_document_request_form')->where('id', $drf->id)->delete();
            }

            $dcn = DB::table('dcs_document_change_notice')->where('request_id', $id)->first();
            if ($dcn) {
                if ($dcn->scanned_dcn) {
                    $filesToDelete[] = $dcn->scanned_dcn;
                }
                foreach (DB::table('dcs_doc_revision')->where('dcn_id', $dcn->id)->get() as $rev) {
                    if ($rev->scanned_copy) {
                        $filesToDelete[] = $rev->scanned_copy;
                    }
                }
                DB::table('dcs_doc_revision')->where('dcn_id', $dcn->id)->delete();
                DB::table('dcs_document_change_notice')->where('id', $dcn->id)->delete();
            }

            $masterlist = DB::table('dcs_masterlist_registration')->where('request_id', $id)->first();
            if ($masterlist) {
                if ($masterlist->scanned_masterlist) {
                    $filesToDelete[] = $masterlist->scanned_masterlist;
                }
                DB::table('dcs_masterlist_source_offices')->where('masterlist_id', $masterlist->id)->delete();
                DB::table('dcs_masterlist_related_docs')
                    ->where(function ($q) use ($masterlist) {
                        $q->where('masterlist_id', $masterlist->id)
                            ->orWhere('related_doc_id', $masterlist->id);
                    })
                    ->delete();
                DB::table('dcs_masterlist_registration')->where('id', $masterlist->id)->delete();
            }

            self::queueSyllabiFiles($id, $filesToDelete);
            DB::table('dcs_syllabi')->where('request_id', $id)->delete();

            $retrieval = DB::table('dcs_document_retrieval')->where('request_id', $id)->first();
            if ($retrieval) {
                if ($retrieval->scanned_retrieval) {
                    $filesToDelete[] = $retrieval->scanned_retrieval;
                }
                DB::table('dcs_retrieval_offices')->where('retrieval_id', $retrieval->id)->delete();
                DB::table('dcs_document_retrieval')->where('id', $retrieval->id)->delete();
            }

            $distribution = DB::table('dcs_document_distribution')->where('request_id', $id)->first();
            if ($distribution) {
                if ($distribution->scanned_distribution) {
                    $filesToDelete[] = $distribution->scanned_distribution;
                }
                DB::table('dcs_distribution_offices')->where('distribution_id', $distribution->id)->delete();
                DB::table('dcs_document_distribution')->where('id', $distribution->id)->delete();
            }

            DB::table('dcs_approval_records')->where('request_id', $id)->delete();
            DB::table('dcs_opcr_ratings')->where('request_id', $id)->delete();
            DB::table('dcs_document_requests')->where('id', $id)->delete();

            DB::commit();
            StampBackupService::pruneOrphans();

            foreach ($filesToDelete as $file) {
                Storage::disk('public')->delete($file);
            }

            return self::flashRedirect(
                'dcs.recycle-bin',
                'success',
                'Document permanently deleted.'
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            $refId = uniqid('err_');
            Log::error("Document permanent deletion failed [{$refId}]: " . $e->getMessage());

            return self::flashRedirect(
                'dcs.recycle-bin',
                'error',
                'Failed to permanently delete document. Please try again. (ref: ' . $refId . ')'
            );
        }
    }

    private static function queueSyllabiFiles(int $requestId, array &$filesToDelete, array $keepPaths = []): void
    {
        $old = DB::table('dcs_syllabi')->where('request_id', $requestId)->get();
        foreach ($old as $syl) {
            foreach (DB::table('dcs_syllabi_drf')->where('syllabi_id', $syl->id)->get() as $sd) {
                if ($sd->scanned_drf && !in_array($sd->scanned_drf, $keepPaths, true)) {
                    $filesToDelete[] = $sd->scanned_drf;
                }
            }
        }
    }

    private static function invalidateSyllabiStamps(int $requestId): void
    {
        $ids = DB::table('dcs_syllabi_drf as sd')
            ->join('dcs_syllabi as s', 's.id', '=', 'sd.syllabi_id')
            ->where('s.request_id', $requestId)
            ->pluck('sd.id');

        foreach ($ids as $id) {
            StampBackupService::invalidate($requestId, 'syllabi_drf_' . $id);
        }
    }
}
