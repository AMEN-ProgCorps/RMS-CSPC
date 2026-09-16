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

    /** Print is for the submitting office only — not RFIO reviewers. */
    public static function assertOfficeIntakePrintAllowed(): void
    {
        abort_if(
            RegisterQueryHelper::canBrowseAllOfficeIntake(),
            403,
            'Print is available only to the office that created this form.'
        );
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

        $cols = [
            'drf.id',
            'drf.drf_no',
            'drf.drf_date',
            'drf.doc_title',
            'drf.drf_receipt_date',
            'drf.created_at',
            'drf.created_by',
        ];
        if (Schema::hasColumn('dcs_document_request_form', 'rfio_registered_at')) {
            $cols[] = 'drf.rfio_registered_at';
        }
        if (Schema::hasColumn('dcs_document_request_form', 'registered_request_id')) {
            $cols[] = 'drf.registered_request_id';
        }

        $rows = DB::table('dcs_document_request_form as drf')
            ->where('drf.is_office_intake', true)
            ->where('drf.created_by', auth()->id())
            ->orderByDesc('drf.id')
            ->get($cols);

        return $rows->map(function ($row) {
            $meta = self::intakeRegistrationMeta('drf', $row);
            $row->is_registered = $meta['is_registered'];
            $row->registered_doc_no = $meta['doc_no'];
            $row->registered_doc_title = $meta['doc_title'];

            return $row;
        });
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function listMyDcn()
    {
        if (! Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
            return collect();
        }

        $cols = array_values(array_filter([
            'dcn.id',
            'dcn.dcn_no',
            'dcn.dcn_date',
            Schema::hasColumn('dcs_document_change_notice', 'originator_name') ? 'dcn.originator_name' : null,
            Schema::hasColumn('dcs_document_change_notice', 'document_title') ? 'dcn.document_title' : null,
            Schema::hasColumn('dcs_document_change_notice', 'document_no') ? 'dcn.document_no' : null,
            'dcn.created_at',
            Schema::hasColumn('dcs_document_change_notice', 'rfio_registered_at') ? 'dcn.rfio_registered_at' : null,
            Schema::hasColumn('dcs_document_change_notice', 'registered_request_id') ? 'dcn.registered_request_id' : null,
        ]));

        $rows = DB::table('dcs_document_change_notice as dcn')
            ->where('dcn.is_office_intake', true)
            ->where('dcn.created_by', auth()->id())
            ->orderByDesc('dcn.id')
            ->get($cols);

        return $rows->map(function ($row) {
            $meta = self::intakeRegistrationMeta('dcn', $row);
            $row->is_registered = $meta['is_registered'];
            $row->registered_doc_no = $meta['doc_no'];
            $row->registered_doc_title = $meta['doc_title'];

            return $row;
        });
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
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
            ->where('d.document_request_form_id', $drfId)
            ->orderBy('o.office_name')
            ->get(['d.office_id', 'o.office_name', 'o.office_code']);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function dcnSourceOffices(int $dcnId)
    {
        return DB::table('dcs_dcn_offices as d')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'd.office_id')
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

        $rateCheck = \App\Services\RateLimiterService::check('dcs_create');
        if (!$rateCheck['allowed']) {
            return back()->withInput()->with('error', $rateCheck['message']);
        }

        $data = $request->validate([
            'drfDate' => 'required|date',
            'drfTitle' => 'required|string|max:255',
            'originatorName' => 'required|string|max:255',
            'docTypeKind' => 'required|in:internal,external',
            'descriptionReason' => 'required|string|max:5000',
            'distributeToOffice' => 'required|array|min:1',
            'distributeToOffice.*' => 'required|integer',
            'confirmDataCorrect' => 'accepted',
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
                    'drf_no' => null,
                    'drf_date' => $data['drfDate'] ?? null,
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
                    $row['originator_name'] = trim((string) ($data['originatorName'] ?? '')) ?: null;
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
            'Created office DRF #' . $id . ': ' . $data['drfTitle']
        );

        \App\Services\DcsAuditService::log(
            'office.drf.create',
            'review_intake',
            null,
            null,
            ['drf_id' => $id, 'title' => $data['drfTitle']]
        );

        if (! RegisterQueryHelper::isRfioOffice()) {
            DcsNotificationService::notifyOfficeDrfSubmitted(
                RegisterQueryHelper::rfioNotificationOfficeCode(),
                RegisterQueryHelper::currentUserDisplayName(),
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

    public static function storeDcn(Request $request): RedirectResponse
    {
        self::assertCanAccessIntake();

        $rateCheck = \App\Services\RateLimiterService::check('dcs_create');
        if (!$rateCheck['allowed']) {
            return back()->withInput()->with('error', $rateCheck['message']);
        }

        $data = $request->validate([
            'documentNo' => 'required|string|max:150',
            'documentTitle' => 'required|string|max:255',
            'changeFrom' => 'required|string|max:5000',
            'changeTo' => 'required|string|max:5000',
            'dcnJustification' => 'required|string|max:5000',
            'originatorName' => 'required|string|max:255',
            'departmentOfficeId' => 'required|integer',
            'departmentDate' => 'required|date',
            'reviewedByDate' => 'required|string|max:255',
            'confirmDataCorrect' => 'accepted',
        ]);

        $docNo = trim((string) ($data['documentNo'] ?? ''));
        $docTitle = trim((string) $data['documentTitle']);
        $departmentDateLabel = self::formatDepartmentDateLabel(
            isset($data['departmentOfficeId']) ? (int) $data['departmentOfficeId'] : null,
            $data['departmentDate'] ?? null
        );

        $userId = (int) auth()->id();
        $now = now();

        try {
            $id = DB::transaction(function () use ($data, $docNo, $docTitle, $departmentDateLabel, $userId, $now) {
                $row = [
                    'request_id' => null,
                    'dcn_no' => null,
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

                if (Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
                    $row['is_office_intake'] = true;
                    $row['document_no'] = $docNo !== '' ? $docNo : null;
                    $row['document_title'] = $docTitle !== '' ? $docTitle : null;
                    $row['change_from'] = trim((string) ($data['changeFrom'] ?? '')) ?: null;
                    $row['change_to'] = trim((string) ($data['changeTo'] ?? '')) ?: null;
                    $row['originator_name'] = trim((string) ($data['originatorName'] ?? '')) ?: null;
                    $row['department_date'] = $departmentDateLabel;
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

        if (! RegisterQueryHelper::isRfioOffice()) {
            DcsNotificationService::notifyOfficeDcnSubmitted(
                RegisterQueryHelper::rfioNotificationOfficeCode(),
                RegisterQueryHelper::currentUserDisplayName(),
                '',
                $docNo,
                $id
            );
        }

        return redirect()
            ->route('dcs.office.dcn.show', $id)
            ->with('success', 'Document Change Notice saved. This document cannot be edited.')
            ->with('locked', true);
    }

    public static function updateDrf(Request $request, int $id): RedirectResponse
    {
        self::assertCanAccessIntake();
        abort_unless(self::canOfficeEditIntake('drf', $id), 403, self::IMMUTABLE_MESSAGE);

        $rateCheck = \App\Services\RateLimiterService::check('dcs_create');
        if (! $rateCheck['allowed']) {
            return back()->withInput()->with('error', $rateCheck['message']);
        }

        $data = $request->validate([
            'drfDate' => 'required|date',
            'drfTitle' => 'required|string|max:255',
            'originatorName' => 'required|string|max:255',
            'docTypeKind' => 'required|in:internal,external',
            'descriptionReason' => 'required|string|max:5000',
            'distributeToOffice' => 'required|array|min:1',
            'distributeToOffice.*' => 'required|integer',
            'confirmDataCorrect' => 'accepted',
        ]);

        $now = now();
        DB::transaction(function () use ($data, $id, $now) {
            $row = [
                'drf_date' => $data['drfDate'] ?? null,
                'doc_title' => $data['drfTitle'],
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('dcs_document_request_form', 'originator_name')) {
                $row['originator_name'] = trim((string) ($data['originatorName'] ?? '')) ?: null;
            }
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
            if (Schema::hasColumn('dcs_document_request_form', 'edit_unlocked_at')) {
                $row['edit_unlocked_at'] = null;
                $row['edit_unlocked_by'] = null;
                $row['edit_unlock_reason'] = null;
            }
            if (Schema::hasColumn('dcs_document_request_form', 'rfio_received_at')) {
                $row['rfio_received_at'] = null;
                $row['rfio_received_by'] = null;
            }

            $affected = DB::table('dcs_document_request_form')
                ->where('id', $id)
                ->where('is_office_intake', true)
                ->update($row);
            abort_if($affected < 1, 404, 'Document Request Form not found.');
        });

        DcsNotificationService::dismissOfficeIntakeNotifications('drf', $id);

        if (! RegisterQueryHelper::isRfioOffice()) {
            DcsNotificationService::notifyOfficeIntakeResubmitted(
                RegisterQueryHelper::rfioNotificationOfficeCode(),
                RegisterQueryHelper::currentUserDisplayName(),
                'drf',
                $id,
                (string) ($data['drfTitle'] ?? '')
            );
        }

        return redirect()
            ->route('dcs.office.drf.show', $id)
            ->with('success', 'Document Request Form updated and resubmitted to RFIO. This document is locked again.')
            ->with('locked', true);
    }

    public static function updateDcn(Request $request, int $id): RedirectResponse
    {
        self::assertCanAccessIntake();
        abort_unless(self::canOfficeEditIntake('dcn', $id), 403, self::IMMUTABLE_MESSAGE);

        $rateCheck = \App\Services\RateLimiterService::check('dcs_create');
        if (! $rateCheck['allowed']) {
            return back()->withInput()->with('error', $rateCheck['message']);
        }

        $data = $request->validate([
            'documentNo' => 'required|string|max:150',
            'documentTitle' => 'required|string|max:255',
            'changeFrom' => 'required|string|max:5000',
            'changeTo' => 'required|string|max:5000',
            'dcnJustification' => 'required|string|max:5000',
            'originatorName' => 'required|string|max:255',
            'departmentOfficeId' => 'required|integer',
            'departmentDate' => 'required|date',
            'reviewedByDate' => 'required|string|max:255',
            'confirmDataCorrect' => 'accepted',
        ]);

        $docNo = trim((string) ($data['documentNo'] ?? ''));
        $docTitle = trim((string) $data['documentTitle']);
        $departmentDateLabel = self::formatDepartmentDateLabel(
            isset($data['departmentOfficeId']) ? (int) $data['departmentOfficeId'] : null,
            $data['departmentDate'] ?? null
        );
        $now = now();

        DB::transaction(function () use ($data, $id, $docNo, $docTitle, $departmentDateLabel, $now) {
            $row = [
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('dcs_document_change_notice', 'brief_purpose')) {
                $row['brief_purpose'] = $data['dcnJustification'];
            }
            if (Schema::hasColumn('dcs_document_change_notice', 'document_no')) {
                $row['document_no'] = $docNo !== '' ? $docNo : null;
                $row['document_title'] = $docTitle !== '' ? $docTitle : null;
                $row['change_from'] = trim((string) ($data['changeFrom'] ?? '')) ?: null;
                $row['change_to'] = trim((string) ($data['changeTo'] ?? '')) ?: null;
                $row['originator_name'] = trim((string) ($data['originatorName'] ?? '')) ?: null;
                $row['department_date'] = $departmentDateLabel;
                $row['reviewed_by_date'] = trim((string) ($data['reviewedByDate'] ?? '')) ?: null;
            }
            if (Schema::hasColumn('dcs_document_change_notice', 'edit_unlocked_at')) {
                $row['edit_unlocked_at'] = null;
                $row['edit_unlocked_by'] = null;
                $row['edit_unlock_reason'] = null;
            }
            if (Schema::hasColumn('dcs_document_change_notice', 'rfio_received_at')) {
                $row['rfio_received_at'] = null;
                $row['rfio_received_by'] = null;
            }

            $affected = DB::table('dcs_document_change_notice')
                ->where('id', $id)
                ->where('is_office_intake', true)
                ->update($row);
            abort_if($affected < 1, 404, 'Document Change Notice not found.');

            $firstRev = DB::table('dcs_doc_revision')->where('dcn_id', $id)->orderBy('id')->first();
            if ($firstRev) {
                $revUpdate = [
                    'title' => $docTitle !== '' ? $docTitle : null,
                    'document_no' => $docNo !== '' ? $docNo : null,
                ];
                if (Schema::hasColumn('dcs_doc_revision', 'brief_purpose')) {
                    $revUpdate['brief_purpose'] = $data['dcnJustification'];
                }
                DB::table('dcs_doc_revision')->where('id', $firstRev->id)->update($revUpdate);
            }
        });

        DcsNotificationService::dismissOfficeIntakeNotifications('dcn', $id);

        if (! RegisterQueryHelper::isRfioOffice()) {
            DcsNotificationService::notifyOfficeIntakeResubmitted(
                RegisterQueryHelper::rfioNotificationOfficeCode(),
                RegisterQueryHelper::currentUserDisplayName(),
                'dcn',
                $id,
                $docTitle !== '' ? $docTitle : $docNo
            );
        }

        return redirect()
            ->route('dcs.office.dcn.show', $id)
            ->with('success', 'Document Change Notice updated and resubmitted to RFIO. This document is locked again.')
            ->with('locked', true);
    }

    private static function formatDepartmentDateLabel(?int $officeId, ?string $date): ?string
    {
        $department = '';
        if ($officeId && $officeId > 0) {
            $row = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')
                ->where('id', $officeId)
                ->whereNotIn('office_code', RegisterQueryHelper::SYSTEM_OFFICE_CODES)
                ->first(['office_name', 'office_code']);
            if ($row) {
                $department = trim((string) ($row->office_code ?? ''));
                if ($department === '') {
                    $department = trim((string) ($row->office_name ?? ''));
                }
            }
        }

        $dateLabel = '';
        $date = trim((string) $date);
        if ($date !== '') {
            try {
                $dateLabel = \Carbon\Carbon::parse($date)->format('M d, Y');
            } catch (\Throwable) {
                $dateLabel = '';
            }
        }

        if ($department !== '' && $dateLabel !== '') {
            return $department . ' / ' . $dateLabel;
        }
        if ($department !== '') {
            return $department;
        }
        if ($dateLabel !== '') {
            return $dateLabel;
        }

        return null;
    }

    /** Prefer office code on print when a stored department label matches an office name/code. */
    public static function departmentDateForPrint(?string $stored): string
    {
        $parts = self::parseDepartmentDate($stored);
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
     * Split stored "Department / Date" for show/print.
     *
     * @return array{department: string, department_code: string, department_label: string, date_label: string, date_iso: string}
     */
    public static function parseDepartmentDate(?string $stored): array
    {
        $stored = trim((string) $stored);
        $department = $stored;
        $dateLabel = '';
        if ($stored !== '' && str_contains($stored, ' / ')) {
            [$department, $dateLabel] = array_map('trim', explode(' / ', $stored, 2));
        }

        $departmentCode = '';
        $departmentLabel = $department;
        if ($department !== '') {
            $row = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')
                ->whereNotIn('office_code', RegisterQueryHelper::SYSTEM_OFFICE_CODES)
                ->where(function ($q) use ($department) {
                    $q->where('office_code', $department)
                        ->orWhere('office_name', $department);
                })
                ->first(['office_code', 'office_name']);

            if ($row) {
                $code = trim((string) ($row->office_code ?? ''));
                $name = trim((string) ($row->office_name ?? ''));
                $departmentCode = $code;
                $departmentLabel = $code !== '' && $name !== ''
                    ? $code . ' — ' . $name
                    : ($name !== '' ? $name : $code);
                $department = $code !== '' ? $code : $name;
            }
        }

        $dateIso = '';
        if ($dateLabel !== '') {
            try {
                $dateIso = \Carbon\Carbon::parse($dateLabel)->format('Y-m-d');
                $dateLabel = \Carbon\Carbon::parse($dateLabel)->format('M d, Y');
            } catch (\Throwable) {
                $dateIso = '';
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

        if (preg_match('#/dcs/office/(drf|dcn)/(\d+)(?:/|$)#', (string) $path, $matches)) {
            // Success notices use ?registered=1 — not an open RFIO review item.
            if (! empty($params['registered'])) {
                return null;
            }

            return [
                'type' => $matches[1],
                'id' => (int) $matches[2],
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
            // Repair older rows that still share request_id with the controlled registration.
            if ((int) ($record->request_id ?? 0) > 0) {
                self::detachIntakeFromControlledRequest($type, $id);
            }

            return true;
        }

        if ((int) ($record->request_id ?? 0) > 0) {
            // Legacy link without rfio_registered_at — detach and treat as registered.
            self::detachIntakeFromControlledRequest($type, $id, true);

            return true;
        }

        // Treat title-matched controlled documents as registered (closes orphaned RFIO review items).
        return self::findMatchingControlledRequestId($type, $record) > 0;
    }

    /**
     * Office intake must stay detached from dcs_document_requests so Update/Database
     * do not exclude the controlled registration via applyExcludeOfficeIntakeRequests.
     */
    private static function detachIntakeFromControlledRequest(string $type, int $id, bool $markRegisteredAt = false): void
    {
        $type = strtolower($type);
        $table = $type === 'dcn' ? 'dcs_document_change_notice' : 'dcs_document_request_form';
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
            ->where('is_office_intake', true)
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
     */
    public static function beginRegister(string $type, int $id): array
    {
        abort_unless(RegisterQueryHelper::canBrowseAllOfficeIntake(), 403);
        $type = strtolower($type);
        abort_unless(in_array($type, ['drf', 'dcn'], true), 404);
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        abort_unless($record, 404);
        abort_if(self::isIntakeRegistered($type, $id), 422, 'This office intake is already registered.');

        session([
            'dcs_office_intake_pending' => [
                'type' => $type,
                'id' => $id,
            ],
        ]);

        $registerType = $type === 'dcn' ? 'revised' : 'new';

        return [
            'ok' => true,
            'type' => $type,
            'id' => $id,
            'registerUrl' => route('dcs.register.create', [
                'type' => $registerType,
                'intake' => $type,
                'intake_id' => $id,
            ]),
        ];
    }

    /** @return array{type: string, id: int}|null */
    public static function pendingRegisterIntake(?\Illuminate\Http\Request $request = null): ?array
    {
        $type = strtolower(trim((string) ($request?->input('office_intake_type') ?? '')));
        $id = (int) ($request?->input('office_intake_id') ?? 0);
        if (in_array($type, ['drf', 'dcn'], true) && $id > 0) {
            return ['type' => $type, 'id' => $id];
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
     * After RFIO registers a document from office intake: link the intake row,
     * remove RFIO submit notifications, and notify the submitting office.
     */
    public static function markIntakeRegistered(
        string $type,
        int $intakeId,
        int $requestId,
        string $docNo,
        ?string $docTitle = null,
        bool $notifyOffice = true
    ): void {
        $type = strtolower($type);
        if (! in_array($type, ['drf', 'dcn'], true) || $intakeId < 1 || $requestId < 1) {
            return;
        }

        $table = $type === 'dcn' ? 'dcs_document_change_notice' : 'dcs_document_request_form';
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
        if (Schema::hasColumn($table, 'registered_request_id')) {
            $update['registered_request_id'] = $requestId;
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

        if (! $notifyOffice) {
            return;
        }

        $title = trim((string) ($docTitle
            ?? ($type === 'dcn' ? ($record->document_title ?? '') : ($record->doc_title ?? ''))
        ));
        $docNo = trim($docNo);
        $formLabel = $type === 'dcn' ? 'Document Change Notice' : 'Document Request Form';
        $titlePart = $title !== '' ? " \"{$title}\"" : '';
        $docPart = $docNo !== '' ? " as {$docNo}" : '';
        $message = "Your {$formLabel}{$titlePart} has been registered / controlled{$docPart} by RFIO.";
        $url = '/dcs/office/' . $type . '/' . $intakeId . '?registered=1';

        foreach (self::intakeOfficeCodes($type, $intakeId, $record) as $officeCode) {
            DcsNotificationService::createNotification($officeCode, $message, $url);
        }
    }

    /**
     * Guarantee the submitting office appears as a masterlist Source Unit so the
     * controlled document shows under Office Documents after RFIO registration.
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
        $table = $type === 'dcn' ? 'dcs_document_change_notice' : 'dcs_document_request_form';
        $record = $type === 'dcn' ? self::findOfficeDcn($id) : self::findOfficeDrf($id);
        abort_unless($record, 404);
        abort_if(self::isIntakeRegistered($type, $id), 422, 'This submission is already registered and cannot be returned for edit.');

        if (! Schema::hasColumn($table, 'edit_unlocked_at')) {
            abort(422, 'Edit unlock is not available yet. Please run migrations.');
        }

        $reason = trim($reason);
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

        return collect($officeCodes)
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->reject(fn ($code) => $rfio !== '' && strcasecmp($code, $rfio) === 0)
            ->values()
            ->all();
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

    /** @return array<int, array{code: string, name: string}> */
    public static function drfDistributeOffices(object $drf): array
    {
        $catalog = collect(RegisterQueryHelper::jsCatalog()['offices'] ?? []);

        return collect(self::decodeDistributeTo($drf->distribute_to ?? null))
            ->map(function ($stored) use ($catalog) {
                $stored = trim((string) $stored);
                $match = $catalog->first(function ($office) use ($stored) {
                    $code = trim((string) ($office['office_code'] ?? ''));
                    $name = trim((string) ($office['office_name'] ?? ''));

                    return ($code !== '' && strcasecmp($code, $stored) === 0)
                        || ($name !== '' && strcasecmp($name, $stored) === 0);
                });

                return [
                    'code' => $match ? trim((string) ($match['office_code'] ?? '')) : $stored,
                    'name' => $match ? trim((string) ($match['office_name'] ?? '')) : '',
                ];
            })
            ->filter(fn (array $office) => $office['code'] !== '' || $office['name'] !== '')
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private static function dcnModalPayload(int $id): ?array
    {
        $dcn = self::findOfficeDcn($id);
        if (! $dcn) {
            return null;
        }

        self::assertOwnsDcn($dcn);
        self::syncRegisteredIntakeLink('dcn', $id);
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
        ], self::intakeHandoffMeta('dcn', $id, $received, $canManage, $canRegister, $registered, $editState));
    }

    /** @return array<string, mixed>|null */
    private static function drfModalPayload(int $id): ?array
    {
        $drf = self::findOfficeDrf($id);
        if (! $drf) {
            return null;
        }

        self::assertOwnsDrf($drf);
        self::syncRegisteredIntakeLink('drf', $id);
        $drf = self::findOfficeDrf($id) ?: $drf;

        $received = self::intakeReceivedState($drf);
        $canManage = RegisterQueryHelper::canBrowseAllOfficeIntake();
        $canRegister = $canManage && RegisterQueryHelper::canAccessDcsModule('register');
        $registered = self::isIntakeRegistered('drf', $id);
        $editState = self::intakeEditState($drf);
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
        ], self::intakeHandoffMeta('drf', $id, $received, $canManage, $canRegister, $registered, $editState));
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
     * @return array<string, mixed>
     */
    private static function intakeHandoffMeta(
        string $type,
        int $id,
        array $received,
        bool $canManage,
        bool $canRegister,
        bool $registered = false,
        array $editState = []
    ): array {
        $registerType = $type === 'dcn' ? 'revised' : 'new';
        $registerUrl = ($canRegister && ! $registered)
            ? route('dcs.register.create', [
                'type' => $registerType,
                'intake' => $type,
                'intake_id' => $id,
            ])
            : null;

        return [
            'canConfirmReceived' => $canManage && ! $registered,
            'canRegister' => $canRegister && ! $registered,
            'canUnlockEdit' => $canManage && ! $registered,
            'received' => $received['received'],
            'receivedAt' => $received['receivedAt'],
            'receivedBy' => $received['receivedBy'],
            'registerUrl' => $registerUrl,
            'registerType' => $registerType,
            'registered' => $registered,
            'editUnlocked' => (bool) ($editState['editUnlocked'] ?? false),
            'editUnlockReason' => $editState['editUnlockReason'] ?? null,
            'editUnlockedAt' => $editState['editUnlockedAt'] ?? null,
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

        $table = $type === 'dcn' ? 'dcs_document_change_notice' : 'dcs_document_request_form';
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
            'registerUrl' => $canRegister
                ? route('dcs.register.create', [
                    'type' => $type === 'dcn' ? 'revised' : 'new',
                    'intake' => $type,
                    'intake_id' => $id,
                ])
                : null,
        ]);
    }

    /**
     * Undo "I already received the document" so RFIO can return it for correction instead.
     *
     * @return array<string, mixed>
     */
    public static function clearReceived(string $type, int $id): array
    {
        abort_unless(RegisterQueryHelper::canBrowseAllOfficeIntake(), 403);

        $type = strtolower($type);
        abort_unless(in_array($type, ['drf', 'dcn'], true), 404);

        $table = $type === 'dcn' ? 'dcs_document_change_notice' : 'dcs_document_request_form';
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
            'registerUrl' => $canRegister
                ? route('dcs.register.create', [
                    'type' => $type === 'dcn' ? 'revised' : 'new',
                    'intake' => $type,
                    'intake_id' => $id,
                ])
                : null,
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
        $distributeIds = collect(self::decodeDistributeTo($drf->distribute_to ?? null))
            ->map(function ($stored) {
                $stored = trim((string) $stored);
                if ($stored === '') {
                    return null;
                }
                $row = DB::table(Schema::hasTable('sys_office') ? 'sys_office' : 'office')
                    ->where(function ($q) use ($stored) {
                        $q->where('office_code', $stored)->orWhere('office_name', $stored);
                    })
                    ->first(['id', 'office_name', 'office_code']);
                if (! $row) {
                    return null;
                }

                return [
                    'office_id' => (int) $row->id,
                    'office_name' => trim((string) ($row->office_name ?? '')),
                    'office_code' => trim((string) ($row->office_code ?? '')),
                ];
            })
            ->filter()
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

        return [
            'intake' => 'drf',
            'intakeId' => $id,
            'versionType' => 'new',
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
        ];
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

        $dept = self::parseDepartmentDate($dcn->department_date ?? null);
        if (($dept['department'] ?? '') !== '' || ($dept['department_code'] ?? '') !== '') {
            $needle = trim((string) (($dept['department_code'] ?? '') !== '' ? $dept['department_code'] : $dept['department']));
            $match = DB::table(Schema::hasTable('sys_office') ? 'sys_office' : 'office')
                ->where(function ($q) use ($needle) {
                    $q->where('office_code', $needle)->orWhere('office_name', $needle);
                })
                ->first(['id', 'office_name', 'office_code']);
            if ($match && empty($sourceOffices)) {
                $sourceOffices[] = [
                    'office_id' => (int) $match->id,
                    'office_name' => trim((string) ($match->office_name ?? '')),
                    'office_code' => trim((string) ($match->office_code ?? '')),
                ];
            }
        }

        $justification = trim((string) ($dcn->brief_purpose ?? ''));
        $changeFrom = trim((string) ($dcn->change_from ?? ''));
        $changeTo = trim((string) ($dcn->change_to ?? ''));
        if ($changeFrom !== '' || $changeTo !== '') {
            $extra = trim(
                ($changeFrom !== '' ? "Change from:\n{$changeFrom}" : '')
                . (($changeFrom !== '' && $changeTo !== '') ? "\n\n" : '')
                . ($changeTo !== '' ? "Change to:\n{$changeTo}" : '')
            );
            $justification = $justification !== ''
                ? $justification . "\n\n" . $extra
                : $extra;
        }

        $noticeDate = ! empty($dcn->dcn_date)
            ? \Carbon\Carbon::parse($dcn->dcn_date)->format('Y-m-d')
            : null;

        return [
            'intake' => 'dcn',
            'intakeId' => $id,
            'versionType' => 'revised',
            'checklistIds' => [2, 3, 4, 5],
            'documentNo' => $docNo,
            'documentTitle' => $docTitle,
            'dcnJustification' => $justification,
            'noticeDate' => $noticeDate,
            'originatorName' => trim((string) ($dcn->originator_name ?? '')),
            'masterlistDocTitle' => $docTitle,
            'masterlistDocNo' => $docNo,
            'changeFrom' => $changeFrom,
            'changeTo' => $changeTo,
            'sourceOffices' => $sourceOffices,
        ];
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

        $byId = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')
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

    /**
     * Base masterlist query for the current office's registered documents.
     * Visibility matches Inventory Source Unit: dcs_masterlist_source_offices
     * for the signed-in office (by office_id, with office_name fallback).
     */
    protected static function officeMasterlistQuery(?int $officeId = null)
    {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();
        $officeName = trim((string) (
            auth()->user()?->details?->office?->office_name
            ?? RegisterQueryHelper::currentOfficeName()
            ?? ''
        ));
        if ($officeName === '—') {
            $officeName = '';
        }

        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $query = DB::table('dcs_masterlist_registration as ml');

        if ((! $officeId && $officeName === '') || ! Schema::hasTable('dcs_masterlist_source_offices')) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        // Same Source Unit linkage Inventory displays under SOURCE UNIT,
        // plus documents registered from this office's own DRF/DCN intake.
        $query->where(function ($outer) use ($officeId, $officeName, $officeTable) {
            $outer->whereExists(function ($q) use ($officeId, $officeName, $officeTable) {
                $q->select(DB::raw(1))
                    ->from('dcs_masterlist_source_offices as so')
                    ->leftJoin($officeTable . ' as o_su', 'o_su.id', '=', 'so.office_id')
                    ->whereColumn('so.masterlist_id', 'ml.id')
                    ->where(function ($match) use ($officeId, $officeName) {
                        if ($officeId) {
                            $match->where('so.office_id', (int) $officeId);
                        }
                        if ($officeName !== '') {
                            $match->orWhereRaw('LOWER(TRIM(o_su.office_name)) = ?', [mb_strtolower($officeName)]);
                        }
                    });
            });

            if ($officeId && Schema::hasColumn('dcs_document_request_form', 'registered_request_id')) {
                $outer->orWhereExists(function ($q) use ($officeId) {
                    $q->select(DB::raw(1))
                        ->from('dcs_document_request_form as oi_drf')
                        ->join(
                            (Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as oi_ad',
                            'oi_ad.account_id',
                            '=',
                            'oi_drf.created_by'
                        )
                        ->whereColumn('oi_drf.registered_request_id', 'ml.request_id')
                        ->where('oi_drf.is_office_intake', true)
                        ->where('oi_ad.office_id', (int) $officeId)
                        ->whereNotNull('oi_drf.registered_request_id');
                });
            }

            if ($officeId && Schema::hasColumn('dcs_document_change_notice', 'registered_request_id')) {
                $outer->orWhereExists(function ($q) use ($officeId) {
                    $q->select(DB::raw(1))
                        ->from('dcs_document_change_notice as oi_dcn')
                        ->join(
                            (Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as oi_ad',
                            'oi_ad.account_id',
                            '=',
                            'oi_dcn.created_by'
                        )
                        ->whereColumn('oi_dcn.registered_request_id', 'ml.request_id')
                        ->where('oi_dcn.is_office_intake', true)
                        ->where('oi_ad.office_id', (int) $officeId)
                        ->whereNotNull('oi_dcn.registered_request_id');
                });
            }
        });

        $query->whereExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('dcs_document_requests as dr')
                ->whereColumn('dr.id', 'ml.request_id');
            RegisterQueryHelper::applyNotDeleted($q, 'dr');
            RegisterQueryHelper::applyExcludeOfficeIntakeRequests($q, 'dr');
            RegisterQueryHelper::applyExcludeDrafts($q, 'dr');
        });

        // Same visibility as Inventory for non-draft docs: latest + obsolete only.
        if (RegisterQueryHelper::supportsRevisionStatus()) {
            $query->where(function ($q) {
                $q->whereNull('ml.revision_status')
                    ->orWhere('ml.revision_status', '')
                    ->orWhereIn('ml.revision_status', ['latest', 'obsolete']);
            });
        }

        return $query;
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
    public static function officeDocumentTotal(?int $officeId = null): int
    {
        return (int) self::officeMasterlistQuery($officeId)->count();
    }

    /**
     * Groups for office document inventory.
     * When $onlyWithDocuments is false, every parent type is returned (count may be 0).
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public static function officeDocumentGroups(?int $officeId = null, bool $onlyWithDocuments = true): array
    {
        $groups = [];
        foreach (self::documentGroupDefs() as $key => $label) {
            $count = (int) self::applyMasterlistGroupFilter(self::officeMasterlistQuery($officeId), $key)->count();
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
     * @return list<array{item_no: int, doc_no: string, rev_no: int, doc_title: string, originator: string, effectivity_date: string|null}>
     */
    public static function listOfficeDocuments(string $groupKey, ?int $officeId = null): array
    {
        $query = self::officeMasterlistQuery($officeId);
        if ($groupKey !== '' && $groupKey !== 'all') {
            if (! isset(self::documentGroupDefs()[$groupKey])) {
                return [];
            }
            $query = self::applyMasterlistGroupFilter($query, $groupKey);
        }

        $select = [
            'ml.id',
            'ml.doc_no',
            'ml.revise_no',
            'ml.doc_title',
            'ml.effectivity_date',
        ];
        if (Schema::hasColumn('dcs_masterlist_registration', 'originator_name')) {
            $select[] = 'ml.originator_name';
        }

        $records = $query
            ->orderByRaw("CASE WHEN COALESCE(TRIM(ml.doc_no), '') = '' THEN 1 ELSE 0 END")
            ->orderBy('ml.doc_no')
            ->orderBy('ml.id')
            ->get($select);

        $rows = [];
        $itemNo = 0;
        foreach ($records as $ml) {
            $itemNo++;
            $rows[] = [
                'item_no' => $itemNo,
                'doc_no' => (string) ($ml->doc_no ?? ''),
                'rev_no' => (int) ($ml->revise_no ?? 0),
                'doc_title' => (string) ($ml->doc_title ?? ''),
                'originator' => trim((string) ($ml->originator_name ?? '')),
                'effectivity_date' => $ml->effectivity_date
                    ? \Carbon\Carbon::parse($ml->effectivity_date)->format('M d, Y')
                    : null,
            ];
        }

        return $rows;
    }
}