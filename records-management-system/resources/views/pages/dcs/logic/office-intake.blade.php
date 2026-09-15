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

        return DB::table('dcs_document_request_form as drf')
            ->where('drf.is_office_intake', true)
            ->where('drf.created_by', auth()->id())
            ->orderByDesc('drf.id')
            ->get([
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

        return DB::table('dcs_document_change_notice as dcn')
            ->where('dcn.is_office_intake', true)
            ->where('dcn.created_by', auth()->id())
            ->orderByDesc('dcn.id')
            ->get(array_values(array_filter([
            'dcn.id',
            'dcn.dcn_no',
            'dcn.dcn_date',
            Schema::hasColumn('dcs_document_change_notice', 'originator_name') ? 'dcn.originator_name' : null,
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
            'drfDate' => 'nullable|date',
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
            'documentNo' => 'nullable|string|max:150',
            'documentTitle' => 'required|string|max:255',
            'changeFrom' => 'nullable|string|max:5000',
            'changeTo' => 'nullable|string|max:5000',
            'dcnJustification' => 'required|string|max:5000',
            'originatorName' => 'nullable|string|max:255',
            'departmentOfficeId' => 'nullable|integer',
            'departmentDate' => 'nullable|date',
            'reviewedByDate' => 'nullable|string|max:255',
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
        if (! preg_match('#/dcs/office/(drf|dcn)/(\d+)(?:/|$)#', $path, $matches)) {
            return null;
        }

        return [
            'type' => $matches[1],
            'id' => (int) $matches[2],
        ];
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

        $revisions = self::dcnRevisions($id);
        $firstRev = $revisions->first();
        $docNo = trim((string) ($dcn->document_no ?? '')) ?: trim((string) ($firstRev->document_no ?? ''));
        $docTitle = trim((string) ($dcn->document_title ?? '')) ?: trim((string) ($firstRev->title ?? ''));

        return [
            'type' => 'dcn',
            'id' => $id,
            'title' => 'Office DCN Submission',
            'subtitle' => 'Review only — submitted by another office for RFIO processing.',
            'html' => view('pages.dcs.office.partials.review-submission-dcn', [
                'dcn' => $dcn,
                'docNo' => $docNo,
                'docTitle' => $docTitle,
                'meta' => self::intakeSubmissionMeta($dcn, 'dcn', $id),
            ])->render(),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function drfModalPayload(int $id): ?array
    {
        $drf = self::findOfficeDrf($id);
        if (! $drf) {
            return null;
        }

        self::assertOwnsDrf($drf);

        return [
            'type' => 'drf',
            'id' => $id,
            'title' => 'Office DRF Submission',
            'subtitle' => 'Review only — submitted by another office for RFIO processing.',
            'html' => view('pages.dcs.office.partials.review-submission-drf', [
                'drf' => $drf,
                'distributeOffices' => self::drfDistributeOffices($drf),
                'meta' => self::intakeSubmissionMeta($drf, 'drf', $id),
            ])->render(),
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

        // Same Source Unit linkage Inventory displays under SOURCE UNIT.
        $query->whereExists(function ($q) use ($officeId, $officeName, $officeTable) {
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

        $query->whereExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('dcs_document_requests as dr')
                ->whereColumn('dr.id', 'ml.request_id');
            RegisterQueryHelper::applyNotDeleted($q, 'dr');
            RegisterQueryHelper::applyExcludeOfficeIntakeRequests($q, 'dr');
            RegisterQueryHelper::applyExcludeDrafts($q, 'dr');
        });

        // Same visibility as Inventory for non-draft docs: exclude archived only.
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
     * @return list<array{item_no: int, doc_no: string, rev_no: int, doc_title: string, effectivity_date: string|null}>
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
        $itemNo = 0;
        foreach ($records as $ml) {
            $itemNo++;
            $rows[] = [
                'item_no' => $itemNo,
                'doc_no' => (string) ($ml->doc_no ?? ''),
                'rev_no' => (int) ($ml->revise_no ?? 0),
                'doc_title' => (string) ($ml->doc_title ?? ''),
                'effectivity_date' => $ml->effectivity_date
                    ? \Carbon\Carbon::parse($ml->effectivity_date)->format('M d, Y')
                    : null,
            ];
        }

        return $rows;
    }
}
